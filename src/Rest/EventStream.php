<?php
/**
 * Output of server-sent events.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Rest;

/**
 * Sends events to the browser one at a time instead of one response at the end.
 *
 * Whether they really arrive one at a time is up to the server: output
 * buffers, compression and proxies can hold them back. Everything that can be
 * switched off from here is switched off. Where that is not enough the events
 * arrive together, which is slower but still correct.
 */
final class EventStream {

	/**
	 * Some servers only start sending once this many bytes have piled up.
	 */
	private const PADDING_BYTES = 2048;

	/**
	 * Starts the response.
	 */
	public function open(): void {
		// The answer is finished, and so cached and counted, even if the visitor leaves the page.
		ignore_user_abort( true );

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Compression collects the output this response exists to send early.
		ini_set( 'zlib.output_compression', '0' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		// Tells nginx not to buffer this response.
		header( 'X-Accel-Buffering: no' );

		// A comment line, ignored by the browser.
		echo ':' . str_repeat( ' ', self::PADDING_BYTES ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Spaces.

		flush();
	}

	/**
	 * Sends one event.
	 *
	 * @param string $name Name of the event.
	 * @param mixed  $data Its data, sent as JSON.
	 */
	public function send( string $name, mixed $data ): void {
		// JSON has no line breaks, which would end the data line of the event.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An event stream, not HTML. The receiving script inserts text as text.
		echo 'event: ' . preg_replace( '/[^a-z]/', '', $name ) . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n";

		flush();
	}
}
