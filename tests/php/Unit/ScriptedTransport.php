<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Provider\HttpTransport;

/**
 * Test double: an AI provider that answers the way the test tells it to.
 */
final class ScriptedTransport implements HttpTransport {

	/**
	 * The vector returned for each text that is embedded.
	 *
	 * @var array<string, float[]>
	 */
	public array $vectors = array();

	/**
	 * What the language model answers.
	 */
	public string $chat_answer = 'none';

	/**
	 * Whether the provider is down.
	 */
	public bool $is_down = false;

	/**
	 * The payloads of the chat requests that were made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $chat_requests = array();

	/**
	 * The texts that were embedded.
	 *
	 * @var string[]
	 */
	public array $embedded = array();

	public function get( string $url, array $headers, int $timeout ): array {
		return self::response( 200, array( 'data' => array() ) );
	}

	public function post_json( string $url, array $headers, array $payload, int $timeout ): array {
		if ( $this->is_down ) {
			return self::response( 503, array( 'error' => array( 'message' => 'Service unavailable' ) ) );
		}

		if ( str_ends_with( $url, '/embeddings' ) ) {
			$this->embedded = array_merge( $this->embedded, $payload['input'] );

			return self::response(
				200,
				array(
					'data'  => array_map( fn ( string $text ): array => array( 'embedding' => $this->vectors[ $text ] ?? array( 0.0, 0.0, 1.0 ) ), $payload['input'] ),
					'usage' => array( 'total_tokens' => 4 ),
				)
			);
		}

		$this->chat_requests[] = $payload;

		return self::response(
			200,
			array(
				'choices' => array( array( 'message' => array( 'content' => $this->chat_answer ) ) ),
				'usage'   => array(
					'prompt_tokens'     => 200,
					'completion_tokens' => 3,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{status: int, body: string}
	 */
	private static function response( int $status, array $body ): array {
		return array(
			'status' => $status,
			'body'   => (string) json_encode( $body ),
		);
	}
}
