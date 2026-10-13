<?php
/**
 * HTTP abstraction for provider calls.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Sends JSON requests. Exists so provider clients can be tested without a network.
 */
interface HttpTransport {

	/**
	 * Sends a JSON POST request.
	 *
	 * @param string                $url     Request URL.
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>  $payload Body, encoded as JSON by the transport.
	 * @param int                   $timeout Timeout in seconds.
	 * @return array{status: int, body: string}
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	public function post_json( string $url, array $headers, array $payload, int $timeout ): array;

	/**
	 * Sends a GET request.
	 *
	 * @param string                $url     Request URL.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 * @return array{status: int, body: string}
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	public function get( string $url, array $headers, int $timeout ): array;
}
