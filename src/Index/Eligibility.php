<?php
/**
 * Decides which posts belong in the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Settings\Settings;
use WP_Post;

/**
 * The single place that knows whether a post may be searched.
 */
final class Eligibility {

	public const EXCLUDE_META = '_prstsearch_exclude';

	/**
	 * Meta value of a manually excluded post.
	 */
	public const EXCLUDED = '1';

	/**
	 * Creates the service.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Tells whether a post belongs in the index.
	 */
	public function is_indexable( WP_Post $post ): bool {
		return null === $this->exclusion_reason( $post );
	}

	/**
	 * Explains why a post is not in the index, or returns null if it is indexable.
	 */
	public function exclusion_reason( WP_Post $post ): ?string {
		$reason = match ( true ) {
			! $this->is_enabled_post_type( $post->post_type ) => __( 'This content type is not included in the search.', 'smart-search' ),
			'publish' !== $post->post_status                  => __( 'Only published content is searchable.', 'smart-search' ),
			'' !== $post->post_password                       => __( 'Password-protected content is not searchable.', 'smart-search' ),
			$this->is_excluded_manually( $post->ID )          => __( 'Excluded from the search by hand.', 'smart-search' ),
			default                                           => null,
		};

		/**
		 * Filters whether a post is indexed.
		 *
		 * @param bool    $indexable Whether the post belongs in the index.
		 * @param WP_Post $post      The post.
		 */
		$indexable = (bool) apply_filters( 'prstsearch_is_indexable', null === $reason, $post );

		if ( $indexable ) {
			return null;
		}

		return $reason ?? __( 'Excluded by a custom rule.', 'smart-search' );
	}

	/**
	 * Tells whether a post type is included in the search.
	 */
	public function is_enabled_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->enabled_post_types(), true );
	}

	/**
	 * Returns the searchable post types that are currently registered.
	 *
	 * Besides the types chosen in the settings these are, if wanted, all types
	 * that did not exist yet when the settings were last saved. A content type
	 * added by a new plugin is searchable without anyone having to remember it.
	 *
	 * @return string[]
	 */
	public function enabled_post_types(): array {
		$enabled = $this->settings->list( 'post_types' );

		if ( $this->settings->bool( 'auto_post_types' ) ) {
			$new     = array_diff( array_keys( $this->available_post_types() ), $this->settings->list( 'known_post_types' ) );
			$enabled = array_merge( $enabled, $new );
		}

		return array_values( array_unique( array_filter( $enabled, 'post_type_exists' ) ) );
	}

	/**
	 * Returns the post types that can be offered for the search.
	 *
	 * @return array<string, string> Labels keyed by post type.
	 */
	public function available_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );

		unset( $types['attachment'] );

		return array_map( static fn ( $type ): string => $type->labels->name, $types );
	}

	/**
	 * Excludes a post from the search by hand, or includes it again.
	 */
	public function set_excluded_manually( int $post_id, bool $is_excluded ): void {
		if ( $is_excluded ) {
			update_post_meta( $post_id, self::EXCLUDE_META, self::EXCLUDED );
			return;
		}

		delete_post_meta( $post_id, self::EXCLUDE_META );
	}

	/**
	 * Tells whether an editor excluded the post.
	 */
	public function is_excluded_manually( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::EXCLUDE_META, true );
	}
}
