<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey;
use LogicException;
use PHPUnit\Framework\TestCase;
use PrstSearch\Index\Chunker;
use PrstSearch\Index\HtmlToText;
use PrstSearch\Provider\HttpTransport;
use PrstSearch\Provider\OpenAiCompatibleClient;
use PrstSearch\Support\Container;

final class ContainerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_services_are_created_once(): void {
		$container = new Container();

		self::assertSame( $container->get( HtmlToText::class ), $container->get( HtmlToText::class ) );
	}

	public function test_default_values_are_used_for_scalar_parameters(): void {
		self::assertInstanceOf( Chunker::class, ( new Container() )->get( Chunker::class ) );
	}

	public function test_factories_resolve_interfaces(): void {
		$container = new Container();
		$transport = new RecordingTransport( 200, '{}' );

		$container->set( HttpTransport::class, static fn (): HttpTransport => $transport );

		self::assertSame( $transport, $container->get( HttpTransport::class ) );
	}

	public function test_unresolvable_parameter_is_reported_clearly(): void {
		$container = new Container();
		$container->set( HttpTransport::class, static fn (): HttpTransport => new RecordingTransport( 200, '{}' ) );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( '$base_url' );

		// The client needs strings that only a factory can provide.
		$container->get( OpenAiCompatibleClient::class );
	}
}
