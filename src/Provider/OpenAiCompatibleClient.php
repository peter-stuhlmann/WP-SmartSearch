<?php
/**
 * Client for OpenAI-compatible APIs.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Talks to any API that implements the OpenAI embeddings, chat completions and models endpoints.
 *
 * Answers can be streamed if the transport can read a response while it arrives.
 */
final class OpenAiCompatibleClient implements StreamingAiClient {

	private const EMBEDDING_TIMEOUT = 30;
	private const CHAT_TIMEOUT      = 45;
	// For a stream the timeout is the time the whole answer may take to arrive, not the wait for its beginning.
	private const STREAM_TIMEOUT = 120;
	private const MODELS_TIMEOUT = 15;

	/**
	 * Creates the client.
	 *
	 * @param HttpTransport $transport       HTTP transport.
	 * @param string        $base_url        API root without trailing slash.
	 * @param string        $api_key         API key, may be empty for local models.
	 * @param string        $embedding_model Embedding model name.
	 * @param string        $chat_model      Chat model name.
	 */
	public function __construct(
		private readonly HttpTransport $transport,
		private readonly string $base_url,
		private readonly string $api_key,
		private readonly string $embedding_model,
		private readonly string $chat_model
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function embed( array $texts ): EmbeddingResult {
		$texts = array_values( $texts );

		if ( array() === $texts ) {
			return new EmbeddingResult( array(), new TokenUsage() );
		}

		$data = $this->request(
			'/embeddings',
			array(
				'model' => $this->embedding_model,
				'input' => $texts,
			),
			self::EMBEDDING_TIMEOUT
		);

		$vectors = array();

		foreach ( is_array( $data['data'] ?? null ) ? $data['data'] : array() as $position => $item ) {
			if ( ! is_array( $item ) || ! is_array( $item['embedding'] ?? null ) ) {
				continue;
			}

			$vectors[ (int) ( $item['index'] ?? $position ) ] = array_map( 'floatval', $item['embedding'] );
		}

		ksort( $vectors );

		if ( count( $vectors ) !== count( $texts ) ) {
			throw new ProviderException( 'The provider returned an unexpected number of embeddings.' );
		}

		$tokens = (int) ( $data['usage']['total_tokens'] ?? $data['usage']['prompt_tokens'] ?? 0 );

		return new EmbeddingResult( array_values( $vectors ), new TokenUsage( embedding: $tokens ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function chat( string $instructions, string $message ): ChatResult {
		return $this->chat_result( $this->request( '/chat/completions', $this->chat_payload( $instructions, $message ), self::CHAT_TIMEOUT ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function chat_stream( string $instructions, string $message, callable $on_text ): ChatResult {
		if ( ! $this->transport instanceof StreamingTransport ) {
			return $this->chat_at_once( $instructions, $message, $on_text );
		}

		$stream   = new ChatStream();
		$response = $this->transport->post_json_stream(
			$this->base_url . '/chat/completions',
			$this->headers(),
			$this->chat_payload( $instructions, $message ) + array(
				'stream'         => true,
				// Without this the stream carries no token counts.
				'stream_options' => array( 'include_usage' => true ),
			),
			self::STREAM_TIMEOUT,
			static function ( string $data ) use ( $stream, $on_text ): void {
				foreach ( $stream->feed( $data ) as $piece ) {
					$on_text( $piece );
				}
			}
		);

		$text = trim( $stream->text() );

		// An API that does not know the streaming options says so with "400 Bad Request". Only then
		// is it asked again the ordinary way: repeating any other failure would just double the load.
		if ( 400 === $response['status'] && '' === $text ) {
			return $this->chat_at_once( $instructions, $message, $on_text );
		}

		$data = $this->decoded( $response );

		// Some APIs answer a streaming request with a plain response. That answer is there already.
		if ( '' === $text && is_array( $data ) ) {
			$result = $this->chat_result( $data );

			$on_text( $result->text );

			return $result;
		}

		// A stream can break off, or report a failure, after it began. What arrived until then is no answer.
		if ( '' === $text || $stream->has_failed() ) {
			throw new ProviderException( 'The provider did not finish the answer.' );
		}

		// A provider that reports no tokens for a stream is estimated, so the costs are not lost.
		$usage = $stream->usage() ?? new TokenUsage(
			input: self::estimated_tokens( $instructions . $message ),
			output: self::estimated_tokens( $text )
		);

		return new ChatResult( $text, $usage );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function models(): array {
		$data   = $this->request( '/models', null, self::MODELS_TIMEOUT );
		$models = array();

		foreach ( is_array( $data['data'] ?? null ) ? $data['data'] : array() as $item ) {
			if ( is_array( $item ) && is_string( $item['id'] ?? null ) ) {
				// Gemini prefixes its names with "models/".
				$models[] = (string) preg_replace( '#^models/#', '', $item['id'] );
			}
		}

		return $models;
	}

	/**
	 * Reads the answer from a complete chat response.
	 *
	 * @param array<string, mixed> $data Decoded response.
	 *
	 * @throws ProviderException If the response holds no text.
	 */
	private function chat_result( array $data ): ChatResult {
		$text = $data['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			throw new ProviderException( 'The provider returned an empty answer.' );
		}

		return new ChatResult(
			trim( $text ),
			new TokenUsage(
				input: (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				output: (int) ( $data['usage']['completion_tokens'] ?? 0 )
			)
		);
	}

	/**
	 * Generates the answer in one piece and hands it over as a single piece of text.
	 *
	 * @param callable(string): void $on_text Receives the answer.
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	private function chat_at_once( string $instructions, string $message, callable $on_text ): ChatResult {
		$result = $this->chat( $instructions, $message );

		$on_text( $result->text );

		return $result;
	}

	/**
	 * Builds the body of a chat request.
	 *
	 * @return array<string, mixed>
	 */
	private function chat_payload( string $instructions, string $message ): array {
		return array(
			'model'    => $this->chat_model,
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $instructions,
				),
				array(
					'role'    => 'user',
					'content' => $message,
				),
			),
		);
	}

	/**
	 * Estimates the tokens of a text by the usual rule of thumb of four characters per token.
	 */
	private static function estimated_tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	/**
	 * Returns the headers every request carries.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return '' === $this->api_key ? array() : array( 'Authorization' => 'Bearer ' . $this->api_key );
	}

	/**
	 * Sends a request and returns the decoded response.
	 *
	 * @param array<string, mixed>|null $payload Request body, or null for a GET request.
	 * @return array<string, mixed>
	 *
	 * @throws ProviderException If the provider answers with an error or invalid JSON.
	 */
	private function request( string $path, ?array $payload, int $timeout ): array {
		$headers = $this->headers();

		$response = null === $payload
			? $this->transport->get( $this->base_url . $path, $headers, $timeout )
			: $this->transport->post_json( $this->base_url . $path, $headers, $payload, $timeout );
		$data     = $this->decoded( $response );

		if ( ! is_array( $data ) ) {
			throw new ProviderException( 'The provider returned an invalid response.' );
		}

		return $data;
	}

	/**
	 * Decodes a response, and turns an error status into an exception.
	 *
	 * @param array{status: int, body: string} $response Response of the transport.
	 * @return array<string, mixed>|null Null if the body is not a JSON object, as that of a stream is not.
	 *
	 * @throws ProviderException If the provider answered with an error.
	 */
	private function decoded( array $response ): ?array {
		$data = json_decode( $response['body'], true );

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			throw new ProviderException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped where it is displayed.
				sprintf( 'HTTP %d: %s', $response['status'], $this->error_message( $data ) ),
				$response['status'] // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integer.
			);
		}

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Extracts a readable message from an error response.
	 */
	private function error_message( mixed $data ): string {
		// Gemini wraps errors in a list.
		if ( is_array( $data ) && array_is_list( $data ) && isset( $data[0] ) ) {
			$data = $data[0];
		}

		$message = is_array( $data ) ? ( $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? null ) : null;

		return is_string( $message ) && '' !== $message ? $message : 'Request failed.';
	}
}
