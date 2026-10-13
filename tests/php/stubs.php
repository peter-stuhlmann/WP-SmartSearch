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

// The templates and views refuse to run outside WordPress.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'KB_IN_BYTES' ) || define( 'KB_IN_BYTES', 1024 );
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

		public string $post_content = '';
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * A database that answers what the test tells it to.
	 */
	class wpdb {

		public string $posts = 'wp_posts';

		public string $postmeta = 'wp_postmeta';

		public string $options = 'wp_options';

		/**
		 * What the next calls of get_col() return, in order.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		public array $columns = array();

		/**
		 * What query() returns: the number of rows it changed.
		 */
		public int $affected = 1;

		/**
		 * What the next calls of get_var() return, in order. Null once they are used up.
		 *
		 * @var array<int, mixed>
		 */
		public array $vars = array();

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}

		public function query( string $query ): int {
			return $this->affected;
		}

		public function get_col( string $query ): array {
			return array() === $this->columns ? array() : array_shift( $this->columns );
		}

		public function get_var( string $query ): mixed {
			return array_shift( $this->vars );
		}

		public function get_results( string $query, string $output = '' ): array {
			return array();
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * The method names of the REST API.
	 */
	class WP_REST_Server {

		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * A request with parameters.
	 */
	class WP_REST_Request {

		/**
		 * @param array<string, mixed> $params Parameters of the request.
		 */
		public function __construct( private array $params = array() ) {}

		public function get_param( string $name ): mixed {
			return $this->params[ $name ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * A response with data, a status and headers.
	 */
	class WP_REST_Response {

		/**
		 * @var array<string, string>
		 */
		private array $headers = array();

		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $name, string $value ): void {
			$this->headers[ $name ] = $value;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}
