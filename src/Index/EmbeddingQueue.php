<?php
/**
 * Creates the embeddings missing in the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Provider\ClientFactory;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Provider\ProviderException;
use PrstSearch\Provider\UsageLedger;
use PrstSearch\Search\Vector;
use PrstSearch\Support\Lock;

/**
 * Works through chunks without an embedding, one provider call per batch.
 */
final class EmbeddingQueue {

	/**
	 * Keeps a chunk safely below the input limit of common embedding models.
	 */
	private const MAX_INPUT_LENGTH = 6000;

	private const LOCK = 'embeddings';

	/**
	 * A provider call that takes longer than this is treated as dead, and its lock is taken over.
	 */
	private const LOCK_LIFETIME = 90;

	/**
	 * Seconds until the queue tries again after the provider failed.
	 */
	private const RETRY_AFTER_ERROR = 15 * MINUTE_IN_SECONDS;

	/**
	 * Seconds until the queue checks again whether the monthly budget allows more work.
	 */
	private const RETRY_AFTER_BUDGET = 6 * HOUR_IN_SECONDS;

	/**
	 * Creates the queue.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly ClientFactory $clients,
		private readonly ProviderConfig $config,
		private readonly UsageLedger $ledger,
		private readonly IndexStatusStore $status,
		private readonly Lock $lock
	) {}

	/**
	 * Embeds one batch of chunks.
	 *
	 * Cron, the rebuild screen and the editor can all call this at the same
	 * time. A lock makes sure only one of them talks to the provider, so no
	 * chunk is paid for twice.
	 *
	 * @param int $limit   Number of chunks to embed.
	 * @param int $post_id Embed the chunks of this post only, or 0 to work through the queue in order.
	 * @return array{processed: int, remaining: int, error: string, is_busy: bool, retry_in: int} "retry_in" is the number of seconds after which a stopped queue should be tried again, 0 if it is not stopped.
	 */
	public function process( int $limit = 40, int $post_id = 0 ): array {
		if ( ! $this->config->embeddings_enabled() ) {
			return self::result( 0, 0 );
		}

		$model = $this->config->embedding_model();

		if ( $this->ledger->is_budget_exhausted() ) {
			return self::result( 0, $this->remaining( $model ), __( 'The monthly budget is used up.', 'smart-search' ), self::RETRY_AFTER_BUDGET );
		}

		if ( ! $this->lock->acquire( self::LOCK, self::LOCK_LIFETIME ) ) {
			return array( 'is_busy' => true ) + self::result( 0, $this->remaining( $model ) );
		}

		try {
			return $this->embed_batch( $model, $limit, $post_id );
		} finally {
			$this->lock->release( self::LOCK );
		}
	}

	/**
	 * Embeds the next chunks and stores the result.
	 *
	 * @return array{processed: int, remaining: int, error: string, is_busy: bool, retry_in: int}
	 */
	private function embed_batch( string $model, int $limit, int $post_id ): array {
		$rows = $this->chunks->missing_embeddings( $model, $limit, $post_id );

		if ( array() === $rows ) {
			return self::result( 0, $this->remaining( $model ) );
		}

		$post_ids = array_unique( array_column( $rows, 'post_id' ) );

		try {
			$embeddings = $this->clients->create()->embed( array_map( array( self::class, 'input' ), $rows ) );
		} catch ( ProviderException $error ) {
			foreach ( $post_ids as $failed_post_id ) {
				$this->status->mark_error( $failed_post_id, $error->getMessage() );
			}

			return self::result( 0, $this->remaining( $model ), $error->getMessage(), self::RETRY_AFTER_ERROR );
		}

		foreach ( $rows as $position => $row ) {
			$this->chunks->save_embedding( $row['id'], Vector::pack( Vector::normalize( $embeddings->vectors[ $position ] ) ), $model );
		}

		$this->ledger->record( UsageLedger::PURPOSE_INDEX, $embeddings->usage );

		foreach ( $post_ids as $embedded_post_id ) {
			if ( ! $this->chunks->has_missing_embeddings( $embedded_post_id, $model ) ) {
				$this->status->mark_indexed( $embedded_post_id );
			}
		}

		return self::result( count( $rows ), $this->remaining( $model ) );
	}

	/**
	 * Builds the text that is embedded for a chunk.
	 *
	 * The title gives every passage its context.
	 *
	 * @param array{title: string, content: string} $row Chunk row.
	 */
	private static function input( array $row ): string {
		return mb_substr( $row['title'] . "\n" . $row['content'], 0, self::MAX_INPUT_LENGTH );
	}

	/**
	 * Counts the chunks still waiting for an embedding.
	 */
	private function remaining( string $model ): int {
		return $this->chunks->counts( $model )['missing'];
	}

	/**
	 * Builds the result array.
	 *
	 * @return array{processed: int, remaining: int, error: string, is_busy: bool, retry_in: int}
	 */
	private static function result( int $processed, int $remaining, string $error = '', int $retry_in = 0 ): array {
		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'error'     => $error,
			'is_busy'   => false,
			'retry_in'  => $retry_in,
		);
	}
}
