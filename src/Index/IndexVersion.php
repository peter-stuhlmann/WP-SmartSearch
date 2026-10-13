<?php
/**
 * Tracks changes of the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

/**
 * A marker that changes whenever the searchable text changes.
 *
 * Everything derived from the index, such as cached answers or the spelling
 * dictionary, records the marker it was built for and is rebuilt once the
 * marker has moved on.
 */
final class IndexVersion {

	public const OPTION = 'prstsearch_index_version';

	/**
	 * Returns the current marker.
	 */
	public function current(): string {
		return (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Returns when the index last changed, as a Unix timestamp, or 0 if it never did.
	 */
	public function changed_at(): int {
		return (int) $this->current();
	}

	/**
	 * Records that the index changed.
	 */
	public function bump(): void {
		// Microseconds, so two changes within one second still differ.
		update_option( self::OPTION, sprintf( '%.6F', microtime( true ) ) );
	}
}
