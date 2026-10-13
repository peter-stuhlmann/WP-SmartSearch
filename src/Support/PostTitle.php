<?php
/**
 * The title a post is shown with.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

use WP_Post;

/**
 * Gives every post a title to show.
 *
 * A post may have none. In a list of results its title is the text of the
 * link, and a link without text can neither be seen nor be named by a screen
 * reader.
 */
final class PostTitle {

	/**
	 * Returns the title of a post as markup, or a stand-in if it has none.
	 */
	public static function of( int|WP_Post $post ): string {
		$title = get_the_title( $post );

		return '' === trim( wp_strip_all_tags( $title ) ) ? esc_html__( '(no title)', 'smart-search' ) : $title;
	}
}
