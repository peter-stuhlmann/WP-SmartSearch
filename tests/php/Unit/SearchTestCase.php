<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Functions;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Database\SearchLog;
use PrstSearch\Provider\HttpTransport;
use PrstSearch\Support\Counters;
use PrstSearch\Settings\Settings;
use PrstSearch\Support\Container;
use WP_Post;

/**
 * Base for tests of the search as a whole.
 *
 * The real services are wired together by the plugin's own container. Only
 * the three edges are replaced: the index, the search log and the AI provider.
 * WordPress itself is reduced to a handful of posts.
 */
abstract class SearchTestCase extends WordPressTestCase {

	protected FakeChunkStore $store;

	protected FakeSearchLog $log;

	protected ScriptedTransport $provider;

	protected Container $container;

	/**
	 * The posts of the website, keyed by ID.
	 *
	 * @var array<int, WP_Post>
	 */
	private array $posts = array();

	/**
	 * A passage needs this length to count fully in the semantic search.
	 */
	private const PADDING = ' Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod.';

	protected function setUp(): void {
		parent::setUp();

		$this->store    = new FakeChunkStore();
		$this->log      = new FakeSearchLog();
		$this->provider = new ScriptedTransport();
		$this->posts    = array();

		$this->container = new Container();
		$this->container->set( ChunkStore::class, fn (): ChunkStore => $this->store );
		$this->container->set( SearchLog::class, fn (): SearchLog => $this->log );
		$this->container->set( HttpTransport::class, fn (): HttpTransport => $this->provider );
		$this->container->set( Counters::class, static fn (): Counters => new MemoryCounters() );

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		Functions\when( 'get_post' )->alias( fn ( int $post_id ): ?WP_Post => $this->posts[ $post_id ] ?? null );
		Functions\when( 'get_post_type' )->alias( fn ( int $post_id ): string|false => isset( $this->posts[ $post_id ] ) ? $this->posts[ $post_id ]->post_type : false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn ( string $format ): string => gmdate( $format ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'untrailingslashit' )->alias( static fn ( string $value ): string => rtrim( $value, '/' ) );

		$this->configure( array() );
	}

	/**
	 * Changes settings. Without any, the search works by keyword only and logs every search.
	 *
	 * @param array<string, mixed> $settings Settings that differ.
	 */
	protected function configure( array $settings ): void {
		$this->options[ Settings::OPTION ] = array_merge(
			array(
				'mode'             => 'keyword',
				'post_types'       => array( 'post', 'page' ),
				'auto_post_types'  => false,
				'tracking_enabled' => true,
			),
			$this->options[ Settings::OPTION ] ?? array(),
			$settings
		);
	}

	/**
	 * Switches on semantic search and the second opinion of the language model, with a provider that needs no key.
	 */
	protected function use_ai(): void {
		$this->configure(
			array(
				'mode'            => 'semantic',
				'provider'        => 'custom',
				'base_url'        => 'https://ai.example/v1',
				'embedding_model' => 'embed',
				'chat_model'      => 'chat',
				'ai_judge'        => true,
			)
		);
	}

	/**
	 * Adds a published page with one indexed passage.
	 *
	 * @param int          $post_id   ID of the post. The passage gets the same ID.
	 * @param string       $title     Title.
	 * @param string       $content   Text.
	 * @param float[]|null $embedding Embedding of the passage, if it has one.
	 * @param array<string, string> $fields Post fields that differ from a published page.
	 */
	protected function add_page( int $post_id, string $title, string $content, ?array $embedding = null, array $fields = array() ): void {
		$post                = new WP_Post();
		$post->ID            = $post_id;
		$post->post_title    = $title;
		$post->post_type     = $fields['post_type'] ?? 'page';
		$post->post_status   = $fields['post_status'] ?? 'publish';
		$post->post_password = $fields['post_password'] ?? '';
		$post->post_date_gmt = $fields['post_date_gmt'] ?? $post->post_date_gmt;

		$this->posts[ $post_id ] = $post;

		$passage = array(
			'id'      => $post_id,
			'post_id' => $post_id,
			'title'   => $title,
			'content' => $content . self::PADDING,
		);

		if ( null !== $embedding ) {
			$passage['embedding'] = $embedding;
		}

		$this->store->passages[] = $passage;
	}

	/**
	 * Removes a post from the website while its passage stays in the index.
	 */
	protected function delete_page( int $post_id ): void {
		unset( $this->posts[ $post_id ] );
	}
}
