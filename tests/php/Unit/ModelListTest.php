<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Provider\ModelClassifier;
use PrstSearch\Provider\OpenAiCompatibleClient;
use PrstSearch\Provider\ProviderException;

/**
 * Fetching a provider's model list and sorting it by purpose.
 */
final class ModelListTest extends TestCase {

	private const MODELS = array(
		'gpt-10-mini',
		'gpt-9-mini',
		'text-embedding-3-small',
		'whisper-1',
		'tts-1-hd',
		'dall-e-3',
		'gpt-image-2',
		'omni-moderation-latest',
		'gpt-realtime',
		'gpt-4o-transcribe',
		'gpt-3.5-turbo-instruct',
		'mistral-embed',
		'mistral-small-latest',
		'gemini-embedding-001',
		'gpt-9-mini',
	);

	public function test_embedding_models_are_recognised_by_name(): void {
		self::assertSame(
			array( 'gemini-embedding-001', 'mistral-embed', 'text-embedding-3-small' ),
			ModelClassifier::embedding( self::MODELS )
		);
	}

	public function test_only_text_models_are_offered_for_answers(): void {
		self::assertSame(
			array( 'gpt-9-mini', 'gpt-10-mini', 'mistral-small-latest' ),
			ModelClassifier::chat( self::MODELS )
		);
	}

	public function test_a_model_released_after_the_plugin_is_still_offered(): void {
		self::assertSame( array( 'some-future-model' ), ModelClassifier::chat( array( 'some-future-model' ) ) );
	}

	public function test_all_models_are_kept_when_names_say_nothing_about_their_purpose(): void {
		// What a self-hosted server might report: none of these names contains "embed".
		self::assertSame(
			array( 'bge-m3', 'llama3.3', 'nomic-v2', 'whisper-large' ),
			ModelClassifier::all( array( 'nomic-v2', 'llama3.3', 'bge-m3', 'whisper-large', 'llama3.3' ) )
		);
	}

	public function test_client_requests_the_model_list(): void {
		$transport = new RecordingTransport(
			200,
			(string) json_encode(
				array(
					'data' => array(
						array( 'id' => 'gpt-4o-mini' ),
						array( 'id' => 'models/gemini-2.5-flash' ),
						array( 'object' => 'no id' ),
					),
				)
			)
		);

		$models = ( new OpenAiCompatibleClient( $transport, 'https://api.example.com/v1', 'secret', 'e', 'c' ) )->models();

		self::assertSame( 'https://api.example.com/v1/models', $transport->url );
		self::assertSame( array( 'Authorization' => 'Bearer secret' ), $transport->headers );
		// Gemini's "models/" prefix is removed, entries without a name are skipped.
		self::assertSame( array( 'gpt-4o-mini', 'gemini-2.5-flash' ), $models );
	}

	public function test_client_reports_a_rejected_key(): void {
		$transport = new RecordingTransport( 401, '{"error":{"message":"Incorrect API key provided."}}' );

		$this->expectException( ProviderException::class );
		$this->expectExceptionMessage( 'HTTP 401: Incorrect API key provided.' );

		( new OpenAiCompatibleClient( $transport, 'https://api.example.com/v1', 'bad', 'e', 'c' ) )->models();
	}
}
