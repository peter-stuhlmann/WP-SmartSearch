<?php
/**
 * Replaces the engine behind the WordPress search.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Search\SearchResults;
use PrstSearch\Search\SearchService;
use PrstSearch\Security\SearchGuard;
use PrstSearch\Settings\Settings;
use WP_Post;
use WP_Query;

/**
 * Answers the main search query from the index.
 *
 * The theme keeps its search form, its search template and its markup. Only
 * the list of posts handed to the loop comes from this plugin.
 */
final class QueryIntegration implements Hookable {

	/**
	 * Results of the main search query of this request.
	 */
	private ?SearchResults $results = null;

	/**
	 * Creates the integration.
	 */
	public function __construct(
		private readonly SearchService $search,
		private readonly ChunkStore $chunks,
		private readonly ProviderConfig $config,
		private readonly Settings $settings,
		private readonly SearchGuard $guard
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'set_results_per_page' ) );
		add_filter( 'posts_pre_query', array( $this, 'replace_posts' ), 10, 2 );
	}

	/**
	 * Returns the results of the main search query, or null if this plugin did not answer it.
	 */
	public function results(): ?SearchResults {
		return $this->results;
	}

	/**
	 * Applies the configured number of results per page.
	 */
	public function set_results_per_page( WP_Query $query ): void {
		if ( $this->applies_to( $query ) ) {
			$query->set( 'posts_per_page', $this->settings->int( 'results_per_page' ) );
		}
	}

	/**
	 * Hands the posts found in the index to WordPress, which then skips its own search.
	 *
	 * @param WP_Post[]|int[]|null $posts Posts set by another plugin, or null.
	 * @param WP_Query             $query The query.
	 * @return WP_Post[]|int[]|null
	 */
	public function replace_posts( $posts, WP_Query $query ) {
		if ( null !== $posts || ! $this->applies_to( $query ) ) {
			return $posts;
		}

		$per_page  = (int) $query->get( 'posts_per_page' );
		$arguments = array(
			(string) $query->get( 's' ),
			max( 1, (int) $query->get( 'paged' ) ),
			$per_page > 0 ? $per_page : $this->search->max_hits(),
			self::requested_post_types( $query ),
		);

		// A visitor over the search limit gets the basic search, see SearchGuard.
		$results = $this->guard->is_basic() ? $this->search->basic( ...$arguments ) : $this->search->search( ...$arguments );

		// An index that was never built must not make the site's search go blank.
		if ( 0 === $results->total && $this->is_index_empty() ) {
			return null;
		}

		$this->results = $results;

		$query->found_posts   = $results->total;
		$query->max_num_pages = $results->page_count();

		if ( 'ids' === $query->get( 'fields' ) ) {
			return $results->post_ids();
		}

		return array_values( array_filter( array_map( 'get_post', $results->post_ids() ) ) );
	}

	/**
	 * Tells whether a query is the visitor-facing main search.
	 */
	private function applies_to( WP_Query $query ): bool {
		return $this->settings->bool( 'replace_wp_search' )
			&& ! is_admin()
			&& $query->is_main_query()
			&& $query->is_search()
			&& '' !== trim( (string) $query->get( 's' ) );
	}

	/**
	 * Returns the post types a query is restricted to, e.g. by a shop search.
	 *
	 * @return string[] Empty if the query is not restricted.
	 */
	private static function requested_post_types( WP_Query $query ): array {
		$post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) || 'any' === $post_types ) {
			return array();
		}

		return array_values( array_filter( (array) $post_types, 'is_string' ) );
	}

	/**
	 * Tells whether the index contains nothing at all.
	 */
	private function is_index_empty(): bool {
		return 0 === $this->chunks->counts( $this->config->embedding_model() )['chunks'];
	}
}
