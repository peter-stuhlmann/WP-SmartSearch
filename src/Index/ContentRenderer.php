<?php
/**
 * Produces the searchable text of a post.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

use PrstSearch\Settings\Settings;
use WP_Post;

/**
 * Renders a post the way visitors see it and reduces it to plain text.
 *
 * Working on the rendered output instead of the stored markup is what makes
 * the index independent of the editor or page builder that created the page.
 */
final class ContentRenderer {

	/**
	 * Below this length the rendered content is considered empty enough to try the public page.
	 */
	private const MIN_USEFUL_LENGTH = 200;

	private const FETCH_TIMEOUT = 8;

	/**
	 * Upper bound for comments indexed per post, so one heavily discussed post cannot bloat the index.
	 */
	private const MAX_COMMENTS = 200;

	/**
	 * Creates the renderer.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly HtmlToText $html_to_text
	) {}

	/**
	 * Returns the plain text of a post.
	 */
	public function render( WP_Post $post ): string {
		$text = $this->html_to_text->convert( $this->rendered_content( $post ) );

		if ( mb_strlen( $text ) < self::MIN_USEFUL_LENGTH && $this->settings->bool( 'fetch_fallback' ) ) {
			$fetched = $this->html_to_text->convert( $this->fetched_content( $post ) );
			$text    = mb_strlen( $fetched ) > mb_strlen( $text ) ? $fetched : $text;
		}

		$parts = array_filter(
			array(
				$text,
				$this->settings->bool( 'index_excerpt' ) ? $post->post_excerpt : '',
				$this->settings->bool( 'index_terms' ) ? $this->term_names( $post ) : '',
				$this->custom_field_values( $post ),
				$this->settings->bool( 'index_comments' ) ? $this->comment_texts( $post ) : '',
			)
		);

		/**
		 * Filters the text that is indexed for a post.
		 *
		 * @param string  $text The plain text.
		 * @param WP_Post $post The post.
		 */
		return (string) apply_filters( 'prstsearch_post_text', implode( "\n", $parts ), $post );
	}

	/**
	 * Runs the content through the_content, with the post as the current post and nobody logged in.
	 */
	private function rendered_content( WP_Post $post ): string {
		$previous      = $GLOBALS['post'] ?? null;
		$previous_user = get_current_user_id();

		/*
		 * The index is public, so it must only ever hold what an anonymous
		 * visitor sees. Indexing is usually triggered by an administrator;
		 * rendering as that user would store text that membership, course or
		 * shop plugins show to logged-in users only.
		 */
		wp_set_current_user( 0 );

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below; builders read the global.
		setup_postdata( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$html = (string) apply_filters( 'the_content', $post->post_content );

		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the original.

		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}

		wp_set_current_user( $previous_user );

		return $html;
	}

	/**
	 * Fetches the public page and returns its main content.
	 */
	private function fetched_content( WP_Post $post ): string {
		$url = get_permalink( $post );

		if ( ! $url ) {
			return '';
		}

		// The page is fetched without cookies, i.e. as an anonymous visitor. Redirects are
		// not followed, so a redirecting page cannot make the index read another server.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::FETCH_TIMEOUT,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$html = wp_remote_retrieve_body( $response );

		foreach ( array( 'main', 'article', 'body' ) as $element ) {
			if ( preg_match( '#<' . $element . '\b[^>]*>(.*)</' . $element . '>#is', $html, $match ) ) {
				// Header and footer inside the container are navigation, not content.
				return (string) preg_replace( '#<(header|footer)\b[^>]*>.*?</\1>#is', ' ', $match[1] );
			}
		}

		return '';
	}

	/**
	 * Returns the text of the approved comments of a post.
	 */
	private function comment_texts( WP_Post $post ): string {
		$comment_ids = get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'type'    => 'comment',
				'number'  => self::MAX_COMMENTS,
				'fields'  => 'ids',
			)
		);

		$texts = array();

		foreach ( is_array( $comment_ids ) ? $comment_ids : array() as $comment_id ) {
			$texts[] = $this->html_to_text->convert( get_comment_text( (int) $comment_id ) );
		}

		return implode( "\n", array_filter( $texts ) );
	}

	/**
	 * Returns the values of the custom fields that were added to the search.
	 */
	private function custom_field_values( WP_Post $post ): string {
		$keys   = array_filter( array_map( 'trim', explode( ',', $this->settings->string( 'custom_fields' ) ) ) );
		$values = array();

		foreach ( $keys as $key ) {
			// Keys WordPress treats as internal (leading underscore) are not meant for the public.
			if ( is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			foreach ( get_post_meta( $post->ID, $key, false ) as $value ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$values[] = $this->html_to_text->convert( (string) $value );
				}
			}
		}

		return implode( "\n", $values );
	}

	/**
	 * Returns the names of the post's public terms, so categories and tags are searchable.
	 */
	private function term_names( WP_Post $post ): string {
		$names = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( is_array( $terms ) ) {
				array_push( $names, ...wp_list_pluck( $terms, 'name' ) );
			}
		}

		return implode( ', ', array_unique( $names ) );
	}
}
