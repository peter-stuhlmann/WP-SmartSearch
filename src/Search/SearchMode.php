<?php
/**
 * The three stages of the search.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * How a search was, or should be, answered.
 */
enum SearchMode: string {

	case Keyword  = 'keyword';
	case Semantic = 'semantic';
	case Ai       = 'ai';

	/**
	 * Returns the translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Keyword  => __( 'Keyword', 'smart-search' ),
			self::Semantic => __( 'Semantic', 'smart-search' ),
			self::Ai       => __( 'AI answer', 'smart-search' ),
		};
	}
}
