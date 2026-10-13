<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Frontend\TemplateLoader;

/**
 * Base for tests that render markup through the real templates.
 *
 * What the templates print is what the scripts insert into the page, so it
 * is tested as printed: with the escaping functions doing their work.
 */
abstract class RenderingTestCase extends SearchTestCase {

	protected const HOME = 'https://example.org/';

	protected function setUp(): void {
		parent::setUp();

		$this->container->set( TemplateLoader::class, static fn (): TemplateLoader => new TemplateLoader( dirname( __DIR__, 3 ) . '/templates/' ) );

		Functions\stubEscapeFunctions();

		Functions\when( 'wp_kses' )->alias(
			// Like the real one for the one case the templates use: everything but the allowed elements is removed.
			static fn ( string $html, array $allowed ): string => strip_tags( $html, array_keys( $allowed ) )
		);
		Functions\when( 'locate_template' )->justReturn( '' );
		Functions\when( 'load_template' )->alias(
			// What WordPress does: run the file with its data in $args.
			static function ( string $file, bool $once, array $args ): void {
				include $file;
			}
		);
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unique_id' )->alias( static fn ( string $prefix = '' ): string => $prefix . '1' );
		Functions\when( 'get_the_title' )->alias( static fn ( object|int $post ): string => is_object( $post ) ? $post->post_title : (string) get_post( $post )?->post_title );
		Functions\when( 'get_permalink' )->alias( static fn ( object|int $post ): string => self::HOME . '?p=' . ( is_object( $post ) ? $post->ID : $post ) );
		Functions\when( 'home_url' )->justReturn( self::HOME );
		Functions\when( 'add_query_arg' )->alias(
			// The values arrive encoded, as the real function expects them.
			static fn ( array $args, string $url ): string => $url . '?' . implode( '&', array_map( static fn ( string $name, string|int $value ): string => $name . '=' . $value, array_keys( $args ), $args ) )
		);
		Functions\when( 'number_format_i18n' )->alias( static fn ( int|float $number ): string => (string) $number );
		Functions\when( 'get_post_type_object' )->alias(
			static fn ( string $type ): object => (object) array(
				'labels' => (object) array(
					'name'          => ucfirst( $type ) . 's',
					'singular_name' => ucfirst( $type ),
				),
			)
		);
	}
}
