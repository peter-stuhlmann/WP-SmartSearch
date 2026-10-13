<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\RankFusion;
use PrstSearch\Search\Relevance;
use PrstSearch\Search\SimilarityScale;
use PrstSearch\Search\Vector;

/**
 * The pure calculations behind ranking: vectors, rank fusion, relevance levels.
 */
final class SearchMathTest extends TestCase {

	public function test_normalized_vector_has_unit_length(): void {
		$vector = Vector::normalize( array( 3.0, 4.0 ) );

		self::assertEqualsWithDelta( array( 0.6, 0.8 ), $vector, 1e-9 );
		self::assertEqualsWithDelta( 1.0, Vector::dot( $vector, $vector ), 1e-9 );
	}

	public function test_zero_vector_is_left_alone(): void {
		self::assertSame( array( 0.0, 0.0 ), Vector::normalize( array( 0.0, 0.0 ) ) );
	}

	public function test_dot_product_of_orthogonal_vectors_is_zero(): void {
		self::assertSame( 0.0, Vector::dot( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ) );
	}

	public function test_vector_survives_packing(): void {
		$vector = Vector::normalize( array( 0.25, -0.5, 0.125, 1.0 ) );

		self::assertEqualsWithDelta( $vector, Vector::unpack( Vector::pack( $vector ) ), 1e-6 );
		self::assertSame( 16, strlen( Vector::pack( $vector ) ) );
	}

	public function test_fusion_prefers_what_both_rankings_agree_on(): void {
		// 7 is second in both lists, 1 and 9 are first in one list only.
		$fused = RankFusion::fuse( array( array( 1, 7, 3 ), array( 9, 7, 4 ) ) );

		self::assertSame( 7, $fused[0] );
		self::assertEqualsCanonicalizing( array( 1, 3, 4, 7, 9 ), $fused );
	}

	public function test_fusion_keeps_the_order_of_a_single_ranking(): void {
		self::assertSame( array( 5, 2, 8 ), RankFusion::fuse( array( array( 5, 2, 8 ), array() ) ) );
	}

	public function test_fusion_breaks_ties_by_first_appearance(): void {
		self::assertSame( array( 1, 2 ), RankFusion::fuse( array( array( 1 ), array( 2 ) ) ) );
	}

	public function test_keyword_relevance_levels(): void {
		// All search words found.
		self::assertSame( Relevance::High, Relevance::from_keyword_coverage( 1.0 ) );
		// One search word that only matches the beginning of a longer word.
		self::assertSame( Relevance::Medium, Relevance::from_keyword_coverage( 0.6 ) );
		// One of three search words found.
		self::assertSame( Relevance::Low, Relevance::from_keyword_coverage( 0.34 ) );
	}

	public function test_the_better_of_two_levels_wins(): void {
		self::assertSame( Relevance::High, Relevance::Low->or_better( Relevance::High ) );
		self::assertSame( Relevance::Medium, Relevance::Medium->or_better( Relevance::Low ) );
	}

	public function test_similarity_scale_uses_its_own_thresholds(): void {
		$scale = new SimilarityScale( 0.8, 0.7 );

		self::assertSame( Relevance::High, $scale->relevance( 0.8 ) );
		self::assertSame( Relevance::Medium, $scale->relevance( 0.75 ) );
		self::assertSame( Relevance::Low, $scale->relevance( 0.65 ) );
	}

	public function test_similarity_below_the_cut_off_is_noise(): void {
		$scale = new SimilarityScale( 0.45, 0.30 );

		self::assertFalse( $scale->is_noise( 0.15 ) );
		self::assertTrue( $scale->is_noise( 0.14 ) );
	}
}
