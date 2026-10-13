<?php
/**
 * One page of search results.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * What a search returns to templates and the REST API.
 */
final class SearchResults {

	/**
	 * Creates the result page.
	 *
	 * @param string      $query     The search phrase.
	 * @param SearchHit[] $hits      Hits of the requested page.
	 * @param int         $total     Number of hits on all pages.
	 * @param int         $page      Current page, starting at 1.
	 * @param int         $per_page  Hits per page.
	 * @param Ranking     $ranking   The full ranking the page was cut from.
	 * @param string      $log_token Signed reference to the log entry, empty if the search was not logged.
	 * @param string[]    $post_types Content types the results were limited to, empty for no limit.
	 * @param bool        $is_provisional Whether the language model has yet to be asked for pages the search missed, see SecondOpinion.
	 */
	public function __construct(
		public readonly string $query,
		public readonly array $hits,
		public readonly int $total,
		public readonly int $page,
		public readonly int $per_page,
		public readonly Ranking $ranking,
		public readonly string $log_token = '',
		public readonly array $post_types = array(),
		public readonly bool $is_provisional = false
	) {}

	/**
	 * Returns the number of pages.
	 */
	public function page_count(): int {
		return (int) ceil( $this->total / max( 1, $this->per_page ) );
	}

	/**
	 * Returns the post IDs of the page in result order.
	 *
	 * @return int[]
	 */
	public function post_ids(): array {
		return array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $this->hits );
	}
}
