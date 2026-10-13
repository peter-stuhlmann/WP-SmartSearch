<?php
/**
 * What the unit tests need of WordPress itself: a few constants and classes.
 *
 * Functions are not defined here. Tests stub the ones they need with Brain
 * Monkey, so every test shows what it relies on.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user with roles.
	 */
	class WP_User {

		/**
		 * Role slugs.
		 *
		 * @var string[]
		 */
		public array $roles = array();
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * A post, with the fields the plugin reads.
	 */
	class WP_Post {

		public int $ID = 0;

		public string $post_title = '';

		public string $post_type = 'post';

		public string $post_status = 'publish';

		public string $post_password = '';

		public string $post_date_gmt = '2026-01-01 00:00:00';
	}
}
