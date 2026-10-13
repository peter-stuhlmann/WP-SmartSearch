<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use Brain\Monkey\Filters;
use PrstSearch\Search\HitFilter;
use PrstSearch\Search\Relevance;
use PrstSearch\Search\SearchHit;

/**
 * The one gate every hit passes before it is shown.
 */
final class HitFilterTest extends SearchTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->add_page( 10, 'Public', 'Text.' );
		$this->add_page( 20, 'Draft', 'Text.', null, array( 'post_status' => 'draft' ) );
		$this->add_page( 30, 'Protected', 'Text.', null, array( 'post_password' => 'secret' ) );
		$this->add_page( 40, 'Another public page', 'Text.' );
		$this->add_page( 50, 'Deleted', 'Text.' );
		$this->delete_page( 50 );
	}

	public function test_only_published_unprotected_existing_posts_pass_in_their_order(): void {
		self::assertSame( array( 40, 10 ), $this->filter()->allowed_posts( array( 50, 40, 30, 20, 10 ) ) );
	}

	public function test_a_rule_of_the_website_can_exclude_a_post(): void {
		Filters\expectApplied( 'prstsearch_is_indexable' )->andReturnUsing(
			static fn ( bool $indexable, object $post ): bool => $indexable && 40 !== $post->ID
		);

		self::assertSame( array( 10 ), $this->filter()->allowed_posts( array( 10, 40 ) ) );
	}

	public function test_other_code_can_remove_and_reorder_hits(): void {
		Filters\expectApplied( 'prstsearch_hits' )->andReturnUsing( static fn ( array $hits ): array => array_reverse( $hits ) );

		self::assertSame( array( 40, 10 ), $this->filter()->allowed_posts( array( 10, 40 ) ) );
	}

	public function test_other_code_cannot_bring_back_what_was_sorted_out(): void {
		$draft = self::hit( 20 );

		Filters\expectApplied( 'prstsearch_hits' )->andReturnUsing( static fn ( array $hits ): array => $hits );

		$passed = $this->filter()->apply( array( self::hit( 10 ), $draft ) );

		self::assertNotContains( $draft, $passed );
		self::assertCount( 1, $passed );
	}

	public function test_a_filter_that_returns_nonsense_does_not_break_the_search(): void {
		Filters\expectApplied( 'prstsearch_hits' )->andReturn( 'not a list' );

		self::assertSame( array( 10, 40 ), $this->filter()->allowed_posts( array( 10, 40 ) ) );
	}

	public function test_entries_that_are_not_hits_are_dropped_from_a_filtered_list(): void {
		Filters\expectApplied( 'prstsearch_hits' )->andReturnUsing(
			static fn ( array $hits ): array => array(
				'first'  => $hits[0],
				'junk'   => 42,
				'second' => $hits[1],
			)
		);

		$passed = $this->filter()->apply( array( self::hit( 10 ), self::hit( 40 ) ) );

		self::assertSame( array( 0, 1 ), array_keys( $passed ), 'The list is numbered from zero again.' );
		self::assertContainsOnlyInstancesOf( SearchHit::class, $passed );
	}

	private function filter(): HitFilter {
		return $this->container->get( HitFilter::class );
	}

	private static function hit( int $post_id ): SearchHit {
		return new SearchHit( $post_id, $post_id, Relevance::High, 1.0, true, false, false );
	}
}
