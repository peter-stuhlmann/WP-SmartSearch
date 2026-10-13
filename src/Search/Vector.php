<?php
/**
 * Vector helpers for semantic search.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Stateless math and (de)serialisation for embedding vectors.
 *
 * Vectors are stored normalised to unit length, so cosine similarity is a
 * plain dot product at query time.
 */
final class Vector {

	/**
	 * Scales a vector to unit length. A zero vector is returned unchanged.
	 *
	 * @param float[] $vector Raw vector.
	 * @return float[]
	 */
	public static function normalize( array $vector ): array {
		$sum = 0.0;

		foreach ( $vector as $value ) {
			$sum += $value * $value;
		}

		if ( 0.0 === $sum ) {
			return array_values( $vector );
		}

		$length = sqrt( $sum );

		return array_map( static fn ( $value ): float => $value / $length, array_values( $vector ) );
	}

	/**
	 * Returns the dot product of two vectors of equal length.
	 *
	 * @param float[] $a First vector, as a list.
	 * @param float[] $b Second vector, as a list.
	 */
	public static function dot( array $a, array $b ): float {
		$sum = 0.0;

		foreach ( $a as $index => $value ) {
			$sum += $value * ( $b[ $index ] ?? 0.0 );
		}

		return $sum;
	}

	/**
	 * Number of 64-bit words of a signature, and so of the columns it is stored in.
	 */
	public const SIGNATURE_WORDS = 8;

	/**
	 * Reduces a vector to one bit per dimension: whether the value is positive.
	 *
	 * Vectors that point in a similar direction agree in most of these bits.
	 * Counting the bits that differ is something a database can do for every
	 * row in an instant, which makes the signature a quick first selection.
	 * The exact comparison then only has to look at the vectors selected.
	 *
	 * @param float[] $vector A vector.
	 * @return int[] The bits of the first 512 dimensions, as 64-bit words.
	 */
	public static function signature( array $vector ): array {
		$words = array_fill( 0, self::SIGNATURE_WORDS, 0 );

		foreach ( array_slice( array_values( $vector ), 0, self::SIGNATURE_WORDS * 64 ) as $dimension => $value ) {
			if ( $value > 0 ) {
				$words[ $dimension >> 6 ] |= 1 << ( $dimension & 63 );
			}
		}

		return $words;
	}

	/**
	 * Serialises a vector as little-endian 32 bit floats.
	 *
	 * @param float[] $vector Vector to store.
	 */
	public static function pack( array $vector ): string {
		return pack( 'g*', ...$vector );
	}

	/**
	 * Restores a vector from its binary form.
	 *
	 * @return float[]
	 */
	public static function unpack( string $binary ): array {
		$values = unpack( 'g*', $binary );

		return false === $values ? array() : array_values( $values );
	}
}
