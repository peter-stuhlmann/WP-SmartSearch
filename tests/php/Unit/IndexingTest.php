<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PrstSearch\Index\BackgroundJobs;
use PrstSearch\Index\Indexer;
use PrstSearch\Index\IndexStatusStore;
use PrstSearch\Index\IndexVersion;
use PrstSearch\Index\Scheduler;
use RuntimeException;
use WP_Post;
use wpdb;

/**
 * Getting content into the index, keeping it current, and the jobs that do so in the background.
 */
final class IndexingTest extends SearchTestCase {

	private const TEXT = 'We send parcels to every country. Delivery takes between two and five working days, depending on the destination. '
		. 'Orders placed before noon leave our warehouse on the same day, and every parcel can be tracked until it arrives.';

	/**
	 * Post meta, by post ID and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * Hooks of jobs that were scheduled.
	 *
	 * @var string[]
	 */
	private array $scheduled = array();

	/**
	 * The user of the request, as WordPress sees it.
	 */
	private int $current_user = 7;

	private wpdb $database;

	protected function setUp(): void {
		parent::setUp();

		$this->meta         = array();
		$this->scheduled    = array();
		$this->current_user = 7;
		$this->database     = new wpdb();

		$this->container->set( wpdb::class, fn (): wpdb => $this->database );

		Functions\when( 'get_post_meta' )->alias( fn ( int $post_id, string $key = '' ): mixed => '' === $key ? ( $this->meta[ $post_id ] ?? array() ) : ( $this->meta[ $post_id ][ $key ] ?? '' ) );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->meta[ $post_id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				unset( $this->meta[ $post_id ][ $key ] );

				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->alias( fn (): int => $this->current_user );
		Functions\when( 'wp_set_current_user' )->alias(
			function ( int $user_id ): void {
				$this->current_user = $user_id;
			}
		);
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_the_title' )->alias( static fn ( WP_Post $post ): string => $post->post_title );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $time, string $hook ): bool {
				$this->scheduled[] = $hook;

				return true;
			}
		);
	}

	public function test_a_published_page_is_stored_in_the_index_as_a_visitor_sees_it(): void {
		$seen_as = null;

		Filters\expectApplied( 'the_content' )->andReturnUsing(
			function ( string $content ) use ( &$seen_as ): string {
				$seen_as = $this->current_user;

				return '<h2>Shipping</h2><p>' . $content . '</p><script>track()</script>';
			}
		);

		$this->publish( 10, 'Shipping', self::TEXT );
		$this->indexer()->index_post( 10 );

		self::assertTrue( $this->store->has_chunks( 10 ) );
		self::assertStringContainsString( 'We send parcels', $this->store->passages[0]['content'] );
		self::assertStringNotContainsString( 'track()', $this->store->passages[0]['content'] );
		self::assertSame( 0, $seen_as, 'Rendered as a logged-out visitor, so nothing meant for members gets into a public index.' );
		self::assertSame( 7, $this->current_user, 'And the request has its user back afterwards.' );
		self::assertSame( 'indexed', $this->meta[10][ IndexStatusStore::STATE_META ] );
	}

	public function test_saving_without_a_change_of_text_leaves_every_cache_valid(): void {
		$this->publish( 10, 'Shipping', self::TEXT );

		$indexer = $this->indexer();
		$indexer->index_post( 10 );

		$version = $this->version();

		$indexer->index_post( 10 );

		self::assertSame( $version, $this->version() );
	}

	public function test_changed_text_invalidates_the_caches(): void {
		$post = $this->publish( 10, 'Shipping', self::TEXT );

		$indexer = $this->indexer();
		$indexer->index_post( 10 );

		$version            = $this->version();
		$post->post_content = self::TEXT . ' Returns are free of charge.';

		$indexer->index_post( 10 );

		self::assertNotSame( $version, $this->version() );
		self::assertStringContainsString( 'Returns are free', implode( ' ', array_column( $this->store->passages, 'content' ) ) );
	}

	public function test_a_new_comment_is_indexed_without_making_cached_answers_worthless(): void {
		$post = $this->publish( 10, 'Shipping', self::TEXT );

		$indexer = $this->indexer();
		$indexer->index_post( 10 );

		$version            = $this->version();
		$post->post_content = self::TEXT . ' A visitor wrote this.';

		// What happens for a comment: the post is indexed again, but not as a change of its content.
		$indexer->index_post( 10, false );

		self::assertStringContainsString( 'A visitor wrote this.', implode( ' ', array_column( $this->store->passages, 'content' ) ) );
		self::assertSame( $version, $this->version(), 'Otherwise anybody could make the website pay for every AI answer again by commenting.' );
	}

	public function test_a_page_that_is_no_longer_public_leaves_the_index(): void {
		$post = $this->publish( 10, 'Shipping', self::TEXT );

		$indexer = $this->indexer();
		$indexer->index_post( 10 );

		$version           = $this->version();
		$post->post_status = 'draft';

		$indexer->index_post( 10 );

		self::assertFalse( $this->store->has_chunks( 10 ) );
		self::assertArrayNotHasKey( IndexStatusStore::STATE_META, $this->meta[10] );
		self::assertNotSame( $version, $this->version() );
	}

	public function test_a_page_without_text_is_still_found_by_its_title(): void {
		Functions\when( 'get_permalink' )->justReturn( 'https://example.org/empty/' );
		Functions\when( 'wp_safe_remote_get' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->publish( 10, 'Opening hours', '' );
		$this->indexer()->index_post( 10 );

		self::assertSame( array( 'Opening hours' ), array_column( $this->store->passages, 'content' ) );
	}

	public function test_a_broken_shortcode_neither_stops_the_save_nor_leaves_the_request_logged_out(): void {
		Filters\expectApplied( 'the_content' )->andReturnUsing(
			static function (): string {
				throw new RuntimeException( 'Shortcode [gallery] failed.' );
			}
		);

		$this->publish( 10, 'Shipping', self::TEXT );
		$this->indexer()->index_post( 10 );

		self::assertSame( 7, $this->current_user );
		self::assertSame( 'error', $this->meta[10][ IndexStatusStore::STATE_META ] );
		self::assertSame( 'Shortcode [gallery] failed.', $this->meta[10][ IndexStatusStore::ERROR_META ] );
	}

	public function test_with_semantic_search_a_new_page_waits_for_its_embedding(): void {
		$this->use_ai();
		$this->publish( 10, 'Shipping', self::TEXT );

		$this->indexer()->index_post( 10 );

		self::assertSame( 'pending', $this->meta[10][ IndexStatusStore::STATE_META ] );
		self::assertContains( Scheduler::EMBEDDINGS_HOOK, $this->scheduled );
	}

	public function test_the_background_job_creates_the_embeddings_and_marks_the_page_as_done(): void {
		$this->use_ai();
		$this->publish( 10, 'Shipping', self::TEXT );
		$this->indexer()->index_post( 10 );

		$this->scheduled = array();
		$this->jobs()->process_embeddings();

		self::assertArrayHasKey( 'embedding', $this->store->passages[0] );
		self::assertSame( 'indexed', $this->meta[10][ IndexStatusStore::STATE_META ] );
		self::assertSame( array(), $this->scheduled, 'Nothing is left to do.' );
	}

	public function test_a_provider_error_is_noted_at_the_page_and_tried_again_later(): void {
		$this->use_ai();
		$this->publish( 10, 'Shipping', self::TEXT );
		$this->indexer()->index_post( 10 );

		$this->provider->is_down = true;
		$this->scheduled         = array();

		$this->jobs()->process_embeddings();

		self::assertSame( 'error', $this->meta[10][ IndexStatusStore::STATE_META ] );
		self::assertStringContainsString( '503', $this->meta[10][ IndexStatusStore::ERROR_META ] );
		self::assertSame( array( Scheduler::EMBEDDINGS_HOOK ), $this->scheduled );
	}

	public function test_while_another_request_is_embedding_the_job_waits_instead_of_paying_twice(): void {
		$this->use_ai();
		$this->publish( 10, 'Shipping', self::TEXT );
		$this->indexer()->index_post( 10 );

		// The lock is taken: writing its row changes nothing.
		$this->database->affected = 0;
		$this->scheduled          = array();

		$this->jobs()->process_embeddings();

		self::assertSame( array(), $this->provider->embedded );
		self::assertSame( array( Scheduler::EMBEDDINGS_HOOK ), $this->scheduled );
	}

	public function test_posts_that_left_the_website_are_removed_from_the_index(): void {
		$this->publish( 10, 'Shipping', self::TEXT );
		$this->publish( 20, 'Returns', self::TEXT );

		$indexer = $this->indexer();
		$indexer->index_post( 10 );
		$indexer->index_post( 20 );

		// Deleted while the plugin was not looking.
		$this->delete_page( 20 );

		self::assertSame( 0, $indexer->prune( 0, 100 ), 'The end of the index was reached.' );
		self::assertTrue( $this->store->has_chunks( 10 ) );
		self::assertFalse( $this->store->has_chunks( 20 ) );
	}

	public function test_the_daily_clean_up_continues_where_it_stopped(): void {
		$this->publish( 10, 'Shipping', self::TEXT );
		$this->indexer()->index_post( 10 );

		$this->options[ BackgroundJobs::PRUNE_CURSOR_OPTION ] = 10;

		$this->jobs()->prune();

		self::assertArrayNotHasKey( BackgroundJobs::PRUNE_CURSOR_OPTION, $this->options, 'Past the last post: the next run starts over.' );
	}

	public function test_posts_never_indexed_are_caught_up_with_by_id_not_by_position(): void {
		$this->publish( 10, 'Shipping', self::TEXT );
		$this->publish( 20, 'Returns', self::TEXT );

		// What the database reports as never indexed, page by page.
		$this->database->columns = array( array( 10, 20 ), array() );

		$this->jobs()->backfill();

		self::assertTrue( $this->store->has_chunks( 10 ) );
		self::assertTrue( $this->store->has_chunks( 20 ) );
		self::assertArrayNotHasKey( BackgroundJobs::BACKFILL_CURSOR_OPTION, $this->options );
	}

	public function test_passages_of_an_older_version_are_completed_in_steps(): void {
		$this->store->incomplete = 450;

		// No time at all: one step, and the rest is handed to the next run.
		$this->jobs()->complete_passages( 0 );

		self::assertSame( 250, $this->store->incomplete );
		self::assertSame( array( Scheduler::COMPLETE_HOOK ), $this->scheduled );

		$this->scheduled = array();
		$this->jobs()->complete_passages( 5 );

		self::assertSame( 0, $this->store->incomplete );
		self::assertSame( array(), $this->scheduled );
	}

	private function publish( int $post_id, string $title, string $content ): WP_Post {
		$this->add_page( $post_id, $title, 'placeholder' );

		// add_page() also puts a passage into the index. Here the indexer is to do that.
		$this->store->passages = array_values( array_filter( $this->store->passages, static fn ( array $passage ): bool => $passage['post_id'] !== $post_id ) );

		$post               = get_post( $post_id );
		$post->post_content = $content;

		return $post;
	}

	private function indexer(): Indexer {
		return $this->container->get( Indexer::class );
	}

	private function jobs(): BackgroundJobs {
		return $this->container->get( BackgroundJobs::class );
	}

	private function version(): string {
		return ( new IndexVersion() )->current();
	}
}
