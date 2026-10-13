<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Provider\HttpTransport;
use PrstSearch\Provider\OpenAiCompatibleClient;
use PrstSearch\Provider\Prices;
use PrstSearch\Provider\ProviderException;
use PrstSearch\Provider\TokenUsage;

final class OpenAiCompatibleClientTest extends TestCase {

	public function test_embed_sends_the_expected_request(): void {
		$transport = $this->transport( 200, array( 'data' => array( array( 'embedding' => array( 0.1 ) ) ) ) );

		$this->client( $transport )->embed( array( 'hello' ) );

		self::assertSame( 'https://api.example.com/v1/embeddings', $transport->url );
		self::assertSame( array( 'Authorization' => 'Bearer secret' ), $transport->headers );
		self::assertSame(
			array(
				'model' => 'embed-model',
				'input' => array( 'hello' ),
			),
			$transport->payload
		);
	}

	public function test_embed_returns_vectors_in_input_order_with_usage(): void {
		$transport = $this->transport(
			200,
			array(
				'data'  => array(
					array(
						'index'     => 1,
						'embedding' => array( 0.3, 0.4 ),
					),
					array(
						'index'     => 0,
						'embedding' => array( 0.1, 0.2 ),
					),
				),
				'usage' => array( 'total_tokens' => 7 ),
			)
		);

		$result = $this->client( $transport )->embed( array( 'first', 'second' ) );

		self::assertSame( array( array( 0.1, 0.2 ), array( 0.3, 0.4 ) ), $result->vectors );
		self::assertSame( 7, $result->usage->embedding );
	}

	public function test_embed_without_texts_does_not_call_the_provider(): void {
		$transport = $this->transport( 500, array() );

		self::assertSame( array(), $this->client( $transport )->embed( array() )->vectors );
		self::assertSame( '', $transport->url );
	}

	public function test_embed_rejects_a_response_with_missing_vectors(): void {
		$transport = $this->transport( 200, array( 'data' => array( array( 'embedding' => array( 0.1 ) ) ) ) );

		$this->expectException( ProviderException::class );

		$this->client( $transport )->embed( array( 'one', 'two' ) );
	}

	public function test_chat_returns_text_and_usage(): void {
		$transport = $this->transport(
			200,
			array(
				'choices' => array( array( 'message' => array( 'content' => " The answer [1].\n" ) ) ),
				'usage'   => array(
					'prompt_tokens'     => 120,
					'completion_tokens' => 30,
				),
			)
		);

		$result = $this->client( $transport )->chat( 'Be brief.', 'Question?' );

		self::assertSame( 'The answer [1].', $result->text );
		self::assertSame( 120, $result->usage->input );
		self::assertSame( 30, $result->usage->output );
		self::assertSame( 'chat-model', $transport->payload['model'] );
		self::assertSame( 'system', $transport->payload['messages'][0]['role'] );
		self::assertSame( 'Question?', $transport->payload['messages'][1]['content'] );
	}

	public function test_chat_rejects_an_empty_answer(): void {
		$transport = $this->transport( 200, array( 'choices' => array( array( 'message' => array( 'content' => '  ' ) ) ) ) );

		$this->expectException( ProviderException::class );

		$this->client( $transport )->chat( 'Be brief.', 'Question?' );
	}

	public function test_error_response_carries_status_and_provider_message(): void {
		$transport = $this->transport( 401, array( 'error' => array( 'message' => 'Incorrect API key provided.' ) ) );

		try {
			$this->client( $transport )->embed( array( 'hello' ) );
			self::fail( 'Expected a ProviderException.' );
		} catch ( ProviderException $error ) {
			self::assertSame( 401, $error->getCode() );
			self::assertSame( 'HTTP 401: Incorrect API key provided.', $error->getMessage() );
		}
	}

	public function test_error_wrapped_in_a_list_is_understood(): void {
		$transport = $this->transport( 400, array( array( 'error' => array( 'message' => 'API key not valid.' ) ) ) );

		$this->expectExceptionMessage( 'HTTP 400: API key not valid.' );

		$this->client( $transport )->embed( array( 'hello' ) );
	}

	public function test_no_authorization_header_without_a_key(): void {
		$transport = $this->transport( 200, array( 'data' => array( array( 'embedding' => array( 0.1 ) ) ) ) );
		$client    = new OpenAiCompatibleClient( $transport, 'http://localhost:11434/v1', '', 'embed-model', 'chat-model' );

		$client->embed( array( 'hello' ) );

		self::assertSame( array(), $transport->headers );
	}

	public function test_costs_are_calculated_per_million_tokens(): void {
		$prices = new Prices( 0.02, 0.15, 0.60 );

		self::assertEqualsWithDelta( 0.00002, $prices->cost_of( new TokenUsage( embedding: 1000 ) ), 1e-12 );
		self::assertEqualsWithDelta( 0.75, $prices->cost_of( new TokenUsage( input: 1_000_000, output: 1_000_000 ) ), 1e-12 );
		self::assertSame( 0.0, $prices->cost_of( new TokenUsage() ) );
	}

	public function test_usage_adds_up(): void {
		$sum = ( new TokenUsage( 1, 2, 3 ) )->plus( new TokenUsage( 10, 20, 30 ) );

		self::assertSame( array( 11, 22, 33 ), array( $sum->embedding, $sum->input, $sum->output ) );
		self::assertTrue( ( new TokenUsage() )->is_empty() );
		self::assertFalse( $sum->is_empty() );
	}

	private function client( HttpTransport $transport ): OpenAiCompatibleClient {
		return new OpenAiCompatibleClient( $transport, 'https://api.example.com/v1', 'secret', 'embed-model', 'chat-model' );
	}

	/**
	 * Creates a transport that records the request and answers with a fixed response.
	 *
	 * @param array<int|string, mixed> $body Response body, encoded as JSON.
	 */
	private function transport( int $status, array $body ): RecordingTransport {
		return new RecordingTransport( $status, (string) json_encode( $body ) );
	}
}
