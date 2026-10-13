<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Provider\ChatStream;
use PrstSearch\Provider\HttpTransport;
use PrstSearch\Provider\OpenAiCompatibleClient;
use PrstSearch\Search\AnswerService;
use PrstSearch\Search\SearchService;

/**
 * AI answers that are handed over while they are written.
 */
final class AnswerStreamTest extends SearchTestCase {

	private StreamingTransportFake $streaming;

	/**
	 * The pieces of text handed over so far.
	 *
	 * @var string[]
	 */
	private array $received = array();

	protected function setUp(): void {
		parent::setUp();

		$this->streaming = new StreamingTransportFake();
		$this->received  = array();

		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
	}

	public function test_a_stream_is_read_whatever_way_the_network_cuts_it(): void {
		$stream = new ChatStream();
		$events = 'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n"
			. 'data: {"choices":[{"delta":{"content":"Grüße "}}]}' . "\n\n"
			. ": comment\n"
			. "event: ping\n"
			. 'data: {"choices":[{"delta":{"content":"aus Köln"}}]}' . "\r\n\r\n"
			. 'data: not json' . "\n"
			. 'data: {"choices":[],"usage":{"prompt_tokens":12,"completion_tokens":4}}' . "\n\n"
			. "data: [DONE]\n\n";

		$pieces = array();

		// Byte by byte: every event, and every umlaut, arrives in parts.
		foreach ( str_split( $events ) as $byte ) {
			$pieces = array_merge( $pieces, $stream->feed( $byte ) );
		}

		self::assertSame( array( 'Grüße ', 'aus Köln' ), $pieces );
		self::assertSame( 'Grüße aus Köln', $stream->text() );
		self::assertSame( 12, $stream->usage()?->input );
		self::assertSame( 4, $stream->usage()?->output );
	}

	public function test_a_stream_without_token_counts_has_no_usage(): void {
		$stream = new ChatStream();

		$stream->feed( 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n" );

		self::assertNull( $stream->usage() );
	}

	public function test_the_client_hands_over_the_pieces_and_returns_the_whole_answer(): void {
		$result = $this->client( $this->streaming )->chat_stream( 'Be brief.', 'Do you ship abroad?', $this->collect() );

		self::assertSame( array( 'We ship ', 'worldwide [1].' ), $this->received );
		self::assertSame( 'We ship worldwide [1].', $result->text );
		self::assertSame( 120, $result->usage->input );
		self::assertSame( 9, $result->usage->output );
		self::assertTrue( $this->streaming->chat_requests()[0]['stream'] );
	}

	public function test_tokens_are_estimated_when_the_provider_does_not_report_them(): void {
		$this->streaming->reports_usage = false;

		$result = $this->client( $this->streaming )->chat_stream( str_repeat( 'a', 40 ), str_repeat( 'b', 40 ), $this->collect() );

		self::assertSame( 20, $result->usage->input );
		self::assertSame( 6, $result->usage->output, '22 characters are about six tokens.' );
	}

	public function test_a_provider_that_refuses_to_stream_is_asked_the_ordinary_way(): void {
		$this->streaming->stream_status = 400;

		$result = $this->client( $this->streaming )->chat_stream( 'Be brief.', 'Do you ship abroad?', $this->collect() );

		self::assertSame( 'An answer in one piece.', $result->text );
		self::assertSame( array( 'An answer in one piece.' ), $this->received );
		self::assertCount( 2, $this->streaming->chat_requests() );
		self::assertArrayNotHasKey( 'stream', $this->streaming->chat_requests()[1] );
	}

	public function test_a_transport_that_cannot_stream_delivers_the_answer_in_one_piece(): void {
		$this->provider->chat_answer = 'All at once.';

		$result = $this->client( $this->provider )->chat_stream( 'Be brief.', 'Do you ship abroad?', $this->collect() );

		self::assertSame( 'All at once.', $result->text );
		self::assertSame( array( 'All at once.' ), $this->received );
	}

	public function test_an_answer_is_streamed_logged_and_cached(): void {
		$this->answer_about_shipping();

		$service = $this->answers();
		$results = $this->container->get( SearchService::class )->search( 'shipping' );
		$answer  = $service->answer( 'shipping', $results->log_token, $this->collect() );

		self::assertSame( 'We ship worldwide [1].', $answer?->text );
		self::assertSame( array( 'We ship ', 'worldwide [1].' ), $this->received );
		self::assertSame( array( 10 ), $answer->source_ids );

		// The same question again: from the cache, in one piece, without another request.
		$this->received = array();
		$again          = $service->answer( 'shipping', '', $this->collect() );

		self::assertSame( 'We ship worldwide [1].', $again?->text );
		self::assertSame( array(), $this->received );
		self::assertCount( 1, $this->streaming->chat_requests() );
	}

	public function test_without_a_listener_the_answer_is_requested_in_one_piece(): void {
		$this->answer_about_shipping();

		$answer = $this->answers()->answer( 'shipping' );

		self::assertSame( 'An answer in one piece.', $answer?->text );
		self::assertArrayNotHasKey( 'stream', $this->streaming->chat_requests()[0] );
	}

	/**
	 * Sets up a website with one page and AI answers from the streaming provider.
	 */
	private function answer_about_shipping(): void {
		$this->use_ai();
		$this->configure( array( 'mode' => 'ai' ) );
		$this->add_page( 10, 'Shipping', 'We send parcels worldwide.' );

		$this->container->set( HttpTransport::class, fn (): HttpTransport => $this->streaming );
	}

	private function answers(): AnswerService {
		return $this->container->get( AnswerService::class );
	}

	private function client( HttpTransport $transport ): OpenAiCompatibleClient {
		return new OpenAiCompatibleClient( $transport, 'https://ai.example/v1', '', 'embed', 'chat' );
	}

	/**
	 * @return callable(string): void
	 */
	private function collect(): callable {
		return function ( string $piece ): void {
			$this->received[] = $piece;
		};
	}
}
