<?php
/**
 * Cron handlers for index work.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\ChunkStore;

/**
 * Runs the index jobs queued by the Scheduler in small, time-boxed steps.
 */
final class BackgroundJobs implements Hookable {

	public const BACKFILL_CURSOR_OPTION = 'prstsearch_backfill_cursor';

	public const PRUNE_CURSOR_OPTION = 'prstsearch_prune_cursor';

	/**
	 * Seconds one cron run may work before it hands over to the next run.
	 */
	private const TIME_BUDGET = 20;

	private const BACKFILL_PAGE_SIZE = 20;

	private const PRUNE_PAGE_SIZE = 200;

	private const COMPLETE_PAGE_SIZE = 200;

	/**
	 * Seconds to wait when another request is embedding at the moment.
	 */
	private const BUSY_RETRY = MINUTE_IN_SECONDS;

	/**
	 * Creates the handlers.
	 */
	public function __construct(
		private readonly Indexer $indexer,
		private readonly EmbeddingQueue $embeddings,
		private readonly Scheduler $scheduler,
		private readonly ChunkStore $chunks
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( Scheduler::EMBEDDINGS_HOOK, array( $this, 'process_embeddings' ) );
		add_action( Scheduler::BACKFILL_HOOK, array( $this, 'backfill' ) );
		add_action( Scheduler::CLEANUP_HOOK, array( $this, 'prune' ) );
		add_action( Scheduler::COMPLETE_HOOK, array( $this, 'complete_passages' ) );
	}

	/**
	 * Creates missing embeddings until done or out of time, and arranges the next run.
	 *
	 * A queue stopped by a provider error or an exhausted budget is tried
	 * again later instead of being left waiting for someone to save a post.
	 */
	public function process_embeddings(): void {
		$deadline = time() + self::TIME_BUDGET;

		do {
			$result = $this->embeddings->process();
		} while ( $result['processed'] > 0 && $result['remaining'] > 0 && time() < $deadline );

		if ( 0 === $result['remaining'] ) {
			return;
		}

		$delay = $result['is_busy'] ? self::BUSY_RETRY : $result['retry_in'];

		$this->scheduler->embeddings( $delay );
	}

	/**
	 * Indexes posts that were never indexed, continuing where the last run stopped.
	 */
	public function backfill(): void {
		$deadline = time() + self::TIME_BUDGET;
		$after_id = (int) get_option( self::BACKFILL_CURSOR_OPTION, 0 );

		do {
			$after_id = $this->indexer->backfill_page( self::BACKFILL_PAGE_SIZE, $after_id );
		} while ( $after_id > 0 && time() < $deadline );

		if ( 0 === $after_id ) {
			delete_option( self::BACKFILL_CURSOR_OPTION );
			return;
		}

		update_option( self::BACKFILL_CURSOR_OPTION, $after_id, false );
		$this->scheduler->backfill();
	}

	/**
	 * Brings passages stored by an older version of the plugin up to date, and arranges the next run if any are left.
	 *
	 * Until a passage is done it is not found by its words. Nothing has to be
	 * rendered or sent to a provider for this, so even a large index is done
	 * within a few runs.
	 *
	 * @param int $seconds How long this run may work.
	 */
	public function complete_passages( int $seconds = self::TIME_BUDGET ): void {
		$deadline = time() + $seconds;

		do {
			$is_done = $this->chunks->complete_passages( self::COMPLETE_PAGE_SIZE ) < self::COMPLETE_PAGE_SIZE;
		} while ( ! $is_done && time() < $deadline );

		if ( ! $is_done ) {
			$this->scheduler->completion();
		}
	}

	/**
	 * Removes posts from the index that no longer belong there. Runs once a day.
	 *
	 * A large index does not fit into one run. The next run then continues where
	 * this one stopped, so every post is checked in turn.
	 */
	public function prune(): void {
		$deadline = time() + self::TIME_BUDGET;
		$after_id = (int) get_option( self::PRUNE_CURSOR_OPTION, 0 );

		do {
			$after_id = $this->indexer->prune( $after_id, self::PRUNE_PAGE_SIZE );
		} while ( $after_id > 0 && time() < $deadline );

		if ( 0 === $after_id ) {
			delete_option( self::PRUNE_CURSOR_OPTION );
			return;
		}

		update_option( self::PRUNE_CURSOR_OPTION, $after_id, false );
	}
}
