<?php
/**
 * Human-readable index information.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Provider\ProviderConfig;
use WP_Post;

/**
 * Describes the state of the index for the admin UI and the REST API.
 */
final class IndexReport {

	/**
	 * Creates the report.
	 */
	public function __construct(
		private readonly IndexStatusStore $status,
		private readonly Eligibility $eligibility,
		private readonly ChunkStore $chunks,
		private readonly Indexer $indexer,
		private readonly ProviderConfig $config
	) {}

	/**
	 * Describes the index status of one post.
	 *
	 * @return array{status: string, label: string, description: string, detail: string, indexed_at: string, excluded_manually: bool}
	 */
	public function for_post( WP_Post $post ): array {
		$status     = $this->status->status( $post );
		$indexed_at = $this->status->indexed_at( $post->ID );

		$detail = match ( $status ) {
			IndexStatus::Excluded => (string) $this->eligibility->exclusion_reason( $post ),
			IndexStatus::Error    => $this->status->error_message( $post->ID ),
			default               => '',
		};

		return array(
			'excluded_manually' => $this->eligibility->is_excluded_manually( $post->ID ),
			'status'            => $status->value,
			'label'             => $status->label(),
			'description'       => $status->description(),
			'detail'            => $detail,
			'indexed_at'        => $indexed_at > 0 && IndexStatus::Excluded !== $status
				/* translators: %s: human-readable time difference, e.g. "5 minutes". */
				? sprintf( __( 'Last updated %s ago', 'smart-search' ), human_time_diff( $indexed_at ) )
				: '',
		);
	}

	/**
	 * Returns the totals of the whole index.
	 *
	 * @return array{searchable: int, indexed: int, passages: int, pending_embeddings: int, errors: int, uses_embeddings: bool}
	 */
	public function overview(): array {
		$uses_embeddings = $this->config->embeddings_enabled();
		$counts          = $this->chunks->counts( $this->config->embedding_model() );

		return array(
			'searchable'         => $this->indexer->searchable_count(),
			'indexed'            => $counts['posts'],
			'passages'           => $counts['chunks'],
			'pending_embeddings' => $uses_embeddings ? $counts['missing'] : 0,
			'errors'             => $this->status->error_count(),
			'uses_embeddings'    => $uses_embeddings,
		);
	}
}
