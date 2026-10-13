<?php
/**
 * HTTP transport based on the WordPress HTTP API.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

use WP_Error;

/**
 * Sends provider requests with wp_remote_post() and wp_remote_get().
 */
final class WpHttpTransport implements StreamingTransport {

	/**
	 * {@inheritDoc}
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	public function post_json( string $url, array $headers, array $payload, int $timeout ): array {
		return self::result( self::post( $url, $headers, $payload, $timeout ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The HTTP API of WordPress returns a response only when it is complete.
	 * With cURL, which nearly every server uses, the body can be read earlier:
	 * WordPress announces the request before sending it, and at that moment
	 * this method takes over the function that receives the body. With another
	 * transport that never happens, and the body is handed over at the end.
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	public function post_json_stream( string $url, array $headers, array $payload, int $timeout, callable $on_data ): array {
		$received = '';

		$take_over = static function ( $handle ) use ( &$received, $on_data ): void {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- The HTTP API has no way to read a response while it arrives.
			curl_setopt(
				$handle,
				CURLOPT_WRITEFUNCTION,
				static function ( $curl, string $data ) use ( &$received, $on_data ): int {
					$received .= $data;

					// An error response is a message, not an answer. It is read from the result below.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- See above.
					if ( 200 === (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ) ) {
						$on_data( $data );
					}

					return strlen( $data );
				}
			);
		};

		add_action( 'requests-curl.before_send', $take_over );

		try {
			$result = self::result( self::post( $url, $headers, $payload, $timeout ) );
		} finally {
			remove_action( 'requests-curl.before_send', $take_over );
		}

		if ( '' !== $received ) {
			return array( 'body' => $received ) + $result;
		}

		// Not sent with cURL: the body arrived the ordinary way.
		if ( 200 === $result['status'] ) {
			$on_data( $result['body'] );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	public function get( string $url, array $headers, int $timeout ): array {
		return self::result(
			wp_remote_get(
				$url,
				array(
					'headers'     => $headers,
					'timeout'     => $timeout,
					'redirection' => 0,
				)
			)
		);
	}

	/**
	 * Sends a JSON POST request with the HTTP API.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>  $payload Body, encoded as JSON here.
	 * @return array<string, mixed>|WP_Error
	 *
	 * @throws ProviderException If the payload cannot be encoded.
	 */
	private static function post( string $url, array $headers, array $payload, int $timeout ) {
		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			throw new ProviderException( 'The request could not be encoded as JSON.' );
		}

		/*
		 * wp_remote_post() instead of wp_safe_remote_post(): a custom endpoint
		 * may legitimately be a model on the local network. Only users who may
		 * manage the plugin can set the URL. Redirects are not followed: the request
		 * carries the API key, which must not travel to a host nobody configured.
		 */
		return wp_remote_post(
			$url,
			array(
				'headers'     => $headers + array( 'Content-Type' => 'application/json' ),
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);
	}

	/**
	 * Reduces a WordPress HTTP response to status and body.
	 *
	 * @param array<string, mixed>|WP_Error $response Response of the HTTP API.
	 * @return array{status: int, body: string}
	 *
	 * @throws ProviderException If the request could not be sent.
	 */
	private static function result( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new ProviderException( esc_html( $response->get_error_message() ) );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
