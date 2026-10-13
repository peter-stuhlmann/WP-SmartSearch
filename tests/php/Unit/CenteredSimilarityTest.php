<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrstSearch\Search\CenteredSimilarity;
use PrstSearch\Search\Relevance;
use PrstSearch\Search\SimilarityScale;
use PrstSearch\Search\Vector;

final class CenteredSimilarityTest extends TestCase {

	public function test_mean_is_calculated_per_dimension(): void {
		self::assertSame(
			array( 2.0, 3.0 ),
			CenteredSimilarity::mean( array( array( 1.0, 2.0 ), array( 3.0, 4.0 ) ) )
		);
	}

	public function test_mean_of_nothing_is_empty(): void {
		self::assertSame( array(), CenteredSimilarity::mean( array() ) );
	}

	public function test_score_equals_the_cosine_of_the_centred_vectors(): void {
		$query   = Vector::normalize( array( 0.9, 0.3, 0.2 ) );
		$passage = Vector::normalize( array( 0.7, 0.6, 0.1 ) );
		$mean    = array( 0.5, 0.4, 0.1 );

		// The straightforward calculation the class avoids for speed.
		$centre   = static fn ( array $vector ): array => Vector::normalize(
			array_map( static fn ( float $value, float $average ): float => $value - $average, $vector, $mean )
		);
		$expected = Vector::dot( $centre( $query ), $centre( $passage ) );

		self::assertEqualsWithDelta( $expected, ( new CenteredSimilarity( $query, $mean ) )->score( $passage ), 1e-9 );
	}

	public function test_centring_separates_what_raw_similarity_cannot(): void {
		// Three texts that all share a strong common direction, as embeddings of one model do.
		$shared  = array( 10.0, 0.0, 0.0 );
		$cooking = Vector::normalize( array( 10.0, 1.0, 0.0 ) );
		$privacy = Vector::normalize( array( 10.0, 0.0, 1.0 ) );
		$query   = Vector::normalize( array( 10.0, 0.9, 0.1 ) );
		$mean    = CenteredSimilarity::mean( array( $cooking, $privacy, Vector::normalize( $shared ) ) );

		// Raw cosine calls everything a near-perfect match.
		self::assertGreaterThan( 0.99, Vector::dot( $query, $cooking ) );
		self::assertGreaterThan( 0.99, Vector::dot( $query, $privacy ) );

		$similarity = new CenteredSimilarity( $query, $mean );

		self::assertGreaterThan( 0.8, $similarity->score( $cooking ) );
		self::assertLessThan( 0.0, $similarity->score( $privacy ) );
	}

	public function test_a_passage_equal_to_the_mean_scores_zero(): void {
		$mean = Vector::normalize( array( 1.0, 1.0 ) );

		self::assertSame( 0.0, ( new CenteredSimilarity( Vector::normalize( array( 1.0, 0.0 ) ), $mean ) )->score( $mean ) );
	}

	public function test_short_passages_count_less(): void {
		self::assertSame( 0.0, CenteredSimilarity::length_weight( 0 ) );
		self::assertEqualsWithDelta( 0.5, CenteredSimilarity::length_weight( 15 ), 1e-9 );
		self::assertSame( 1.0, CenteredSimilarity::length_weight( 60 ) );
		self::assertSame( 1.0, CenteredSimilarity::length_weight( 5000 ) );
	}

	public function test_scale_with_an_explicit_minimum(): void {
		$scale = new SimilarityScale( 0.35, 0.22, 0.20 );

		self::assertSame( Relevance::High, $scale->relevance( 0.36 ) );
		self::assertSame( Relevance::Medium, $scale->relevance( 0.25 ) );
		self::assertSame( Relevance::Low, $scale->relevance( 0.21 ) );
		self::assertFalse( $scale->is_noise( 0.21 ) );
		self::assertTrue( $scale->is_noise( 0.19 ) );
	}
}
