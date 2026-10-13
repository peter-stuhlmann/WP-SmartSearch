<?php
/**
 * Index status of a post.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

/**
 * What editors see about a post's place in the search index.
 */
enum IndexStatus: string {

	case Indexed  = 'indexed';
	case Stale    = 'stale';
	case Error    = 'error';
	case Excluded = 'excluded';

	/**
	 * Returns the translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Indexed  => __( 'Indexed', 'smart-search' ),
			self::Stale    => __( 'Outdated', 'smart-search' ),
			self::Error    => __( 'Error', 'smart-search' ),
			self::Excluded => __( 'Excluded', 'smart-search' ),
		};
	}

	/**
	 * Returns a one-sentence explanation of the status.
	 */
	public function description(): string {
		return match ( $this ) {
			self::Indexed  => __( 'The search finds the current version of this content.', 'smart-search' ),
			self::Stale    => __( 'The index is being updated in the background. The content is already found by its words; the search by meaning follows.', 'smart-search' ),
			self::Error    => __( 'The content could not be added to the index.', 'smart-search' ),
			self::Excluded => __( 'This content does not appear in search results.', 'smart-search' ),
		};
	}
}
