<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Provider\StreamingTransport;

/**
 * Test double: an AI provider that streams its answers.
 *
 * The stream is cut into portions of a few bytes, which is harder on the
 * reader than any real network: events, and even characters, arrive in halves.
 */
final class StreamingTransportFake implements StreamingTransport {

	/**
	 * The pieces of text the model writes one after another.
	 *
	 * @var string[]
	 */
	public array $pieces = array( 'We ship ', 'worldwide [1].' );

	/**
	 * Whether the stream reports the tokens it used.
	 */
	public bool $reports_usage = true;

	/**
	 * Status of the response to a streaming request.
	 */
	public int $stream_status = 200;

	/**
	 * Payloads of the requests, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $requests = array();

	/**
	 * Returns the payloads of the requests that asked for an answer, leaving out those for embeddings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function chat_requests(): array {
		return array_values( array_filter( $this->requests, static fn ( array $payload ): bool => isset( $payload['messages'] ) ) );
	}

	public function get( string $url, array $headers, int $timeout ): array {
		return self::json( 200, array( 'data' => array() ) );
	}

	public function post_json( string $url, array $headers, array $payload, int $timeout ): array {
		$this->requests[] = $payload;

		return self::json(
			200,
			array(
				'choices' => array( array( 'message' => array( 'content' => 'An answer in one piece.' ) ) ),
				'usage'   => array(
					'prompt_tokens'     => 50,
					'completion_tokens' => 6,
				),
			)
		);
	}

	public function post_json_stream( string $url, array $headers, array $payload, int $timeout, callable $on_data ): array {
		$this->requests[] = $payload;

		if ( 200 !== $this->stream_status ) {
			return self::json( $this->stream_status, array( 'error' => array( 'message' => 'Unknown parameter: stream_options' ) ) );
		}

		$body = ": keep-alive\n\n";

		foreach ( $this->pieces as $piece ) {
			$body .= 'data: ' . json_encode( array( 'choices' => array( array( 'delta' => array( 'content' => $piece ) ) ) ) ) . "\n\n";
		}

		if ( $this->reports_usage ) {
			$body .= 'data: ' . json_encode(
				array(
					'choices' => array(),
					'usage'   => array(
						'prompt_tokens'     => 120,
						'completion_tokens' => 9,
					),
				)
			) . "\r\n\r\n";
		}

		$body .= "data: [DONE]\n\n";

		foreach ( str_split( $body, 7 ) as $portion ) {
			$on_data( $portion );
		}

		return array(
			'status' => 200,
			'body'   => $body,
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{status: int, body: string}
	 */
	private static function json( int $status, array $body ): array {
		return array(
			'status' => $status,
			'body'   => (string) json_encode( $body ),
		);
	}
}
