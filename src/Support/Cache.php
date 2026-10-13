<?php
/**
 * The plugin's cache.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

use PrstSearch\Index\IndexVersion;

/**
 * Transient storage with the two patterns the plugin needs.
 *
 * Per-request-key entries (one per query) expire on their own. Entries that
 * describe the whole index live under one fixed key and remember the state
 * of the index they were built for, so a changed index overwrites them
 * instead of leaving an orphan behind.
 */
final class Cache {

	public const PREFIX = 'prstsearch_';

	/**
	 * How long a rebuild keeps others from starting the same one.
	 */
	private const REBUILD_LOCK = 5 * MINUTE_IN_SECONDS;

	/**
	 * How long a request waits for a value another one is building for the first time, in seconds.
	 * After that it builds the value itself, in case the other request died.
	 */
	private const FIRST_BUILD_WAIT = 10;

	/**
	 * How often it looks whether the value is there, in milliseconds.
	 */
	private const WAIT_STEP = 250;

	/**
	 * Names of the index values this request has already arranged to rebuild.
	 *
	 * @var array<string, true>
	 */
	private array $rebuilding = array();

	/**
	 * Creates the cache.
	 */
	public function __construct( private readonly IndexVersion $version ) {}

	/**
	 * Builds a key from a name and the values the entry depends on.
	 *
	 * @param string $name  Short name of what is cached.
	 * @param string ...$parts Values that make the entry unique.
	 */
	public static function key( string $name, string ...$parts ): string {
		// wp_hash() is keyed with the site's secret, so keys cannot be guessed from their inputs.
		return self::PREFIX . $name . '_' . substr( wp_hash( implode( '|', $parts ) ), 0, 32 );
	}

	/**
	 * Builds a key for an entry that must not outlive the current state of the index.
	 *
	 * @param string $name  Short name of what is cached.
	 * @param string ...$parts Values that make the entry unique.
	 */
	public function index_key( string $name, string ...$parts ): string {
		return self::key( $name, $this->version->current(), ...$parts );
	}

	/**
	 * Returns a cached value.
	 *
	 * @return mixed|null Null if nothing is cached.
	 */
	public function get( string $key ): mixed {
		$value = get_transient( $key );

		return false === $value ? null : $value;
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key   Cache key, see key().
	 * @param mixed  $value Anything but false and null. Use arrays and scalars, not objects.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public function set( string $key, mixed $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}

	/**
	 * Returns a value derived from the whole index.
	 *
	 * Such a value takes long to build on a large website: every passage has
	 * to be read. So a visitor never waits for it twice. After the index
	 * changed, the previous value keeps being used, and the new one is built
	 * once the response of this request has been sent. Only the very first
	 * request, and one after the variant changed, builds it on the spot. Others
	 * arriving in that moment wait for it instead of all doing the same work.
	 *
	 * @param string            $name    Name of the value. One entry exists per name.
	 * @param string            $variant What the value is built for, e.g. the embedding model. A value for
	 *                                   another variant is useless and never returned.
	 * @param callable(): mixed $build   Creates the value.
	 * @param string            $state   Whatever besides the index version makes a value outdated, though still usable.
	 */
	public function for_index( string $name, string $variant, callable $build, string $state = '' ): mixed {
		$key   = self::PREFIX . 'index_' . $name;
		$stamp = $this->version->current() . '|' . $state;
		$entry = self::usable( get_transient( $key ), $variant ) ?? $this->awaited( $key, $variant );

		if ( null === $entry ) {
			$lock = $key . '_rebuilding';

			set_transient( $lock, 1, self::REBUILD_LOCK );

			try {
				return $this->rebuild( $key, $variant, $stamp, $build );
			} finally {
				delete_transient( $lock );
			}
		}

		if ( ( $entry['stamp'] ?? null ) !== $stamp ) {
			$this->rebuild_later( $key, $variant, $build, $state );
		}

		return $entry['value'];
	}

	/**
	 * Returns a stored entry if it holds a value for the given variant.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function usable( mixed $entry, string $variant ): ?array {
		return is_array( $entry ) && ( $entry['variant'] ?? null ) === $variant && array_key_exists( 'value', $entry ) ? $entry : null;
	}

	/**
	 * Waits for a value another request is building, if one is.
	 *
	 * @return array<string, mixed>|null The entry once it is there. Null if nobody builds it, or not in time.
	 */
	private function awaited( string $key, string $variant ): ?array {
		$lock = $key . '_rebuilding';

		for ( $waited = 0; $waited < self::FIRST_BUILD_WAIT * 1000; $waited += self::WAIT_STEP ) {
			if ( false === get_transient( $lock ) ) {
				break;
			}

			usleep( self::WAIT_STEP * 1000 );

			$entry = self::usable( get_transient( $key ), $variant );

			if ( null !== $entry ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Builds and stores an index value.
	 *
	 * @param callable(): mixed $build Creates the value.
	 */
	private function rebuild( string $key, string $variant, string $stamp, callable $build ): mixed {
		$value = $build();

		set_transient(
			$key,
			array(
				'variant' => $variant,
				'stamp'   => $stamp,
				'value'   => $value,
			),
			WEEK_IN_SECONDS
		);

		return $value;
	}

	/**
	 * Arranges for an index value to be rebuilt when this request is over.
	 *
	 * @param callable(): mixed $build Creates the value.
	 */
	private function rebuild_later( string $key, string $variant, callable $build, string $state ): void {
		$lock = $key . '_rebuilding';

		// One request rebuilds; the others keep using the previous value in the meantime.
		if ( isset( $this->rebuilding[ $key ] ) || false !== get_transient( $lock ) ) {
			return;
		}

		$this->rebuilding[ $key ] = true;

		set_transient( $lock, 1, self::REBUILD_LOCK );

		add_action(
			'shutdown',
			function () use ( $key, $variant, $build, $state, $lock ): void {
				// Where the server allows it, the visitor gets the page before the work starts.
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}

				// The stamp is read now: the index may have changed again since the request began.
				$this->rebuild( $key, $variant, $this->version->current() . '|' . $state, $build );

				delete_transient( $lock );
			}
		);
	}
}
