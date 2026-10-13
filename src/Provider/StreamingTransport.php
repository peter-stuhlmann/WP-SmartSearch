<?php
/**
 * HTTP abstraction for provider calls whose response is read while it arrives.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * An optional ability of a transport.
 */
interface StreamingTransport extends HttpTransport {

	/**
	 * Sends a JSON POST request and hands over the response body as it arrives.
	 *
	 * The body of a successful response reaches the callback completely, in as
	 * many portions as the network delivers. A transport that cannot read
	 * while receiving hands over the whole body in one portion. The body of an
	 * error response is not handed over.
	 *
	 * @param string                 $url     Request URL.
	 * @param array<string, string>  $headers Request headers.
	 * @param array<string, mixed>   $payload Body, encoded as JSON by the transport.
	 * @param int                    $timeout Timeout in seconds.
	 * @param callable(string): void $on_data Receives the portions of the response body.
	 * @return array{status: int, body: string} The body is complete here as well.
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	public function post_json_stream( string $url, array $headers, array $payload, int $timeout, callable $on_data ): array;
}
