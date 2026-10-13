<?php
/**
 * Splits text into index chunks.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Index;

/**
 * Cuts plain text into passages of similar size along sentence boundaries.
 *
 * Passages overlap by one sentence so a statement that spans a boundary can
 * still be found.
 */
final class Chunker {

	/**
	 * Creates the chunker.
	 *
	 * @param int $max_length Upper bound for a chunk, in characters.
	 */
	public function __construct( private readonly int $max_length = 1200 ) {}

	/**
	 * Splits text into chunks.
	 *
	 * @return string[]
	 */
	public function split( string $text ): array {
		$chunks  = array();
		$current = array();
		$length  = 0;

		foreach ( $this->sentences( $text ) as $sentence ) {
			$sentence_length = mb_strlen( $sentence );

			if ( array() !== $current && $length + $sentence_length + 1 > $this->max_length ) {
				$chunks[] = implode( ' ', $current );
				$current  = $this->overlap( $current, $sentence_length );
				$length   = array() === $current ? 0 : mb_strlen( implode( ' ', $current ) ) + 1;
			}

			$current[] = $sentence;
			$length   += $sentence_length + 1;
		}

		if ( array() !== $current ) {
			$chunks[] = implode( ' ', $current );
		}

		return $chunks;
	}

	/**
	 * Returns the sentence that is repeated at the start of the next chunk.
	 *
	 * Long sentences are not repeated, they would eat most of the next chunk.
	 *
	 * @param string[] $sentences   Sentences of the chunk that was just closed.
	 * @param int      $next_length Length of the sentence that opens the next chunk.
	 * @return string[]
	 */
	private function overlap( array $sentences, int $next_length ): array {
		$last = end( $sentences );

		// The repeated sentence must never push the next chunk over the limit.
		if ( mb_strlen( $last ) + 1 + $next_length > $this->max_length ) {
			return array();
		}

		if ( count( $sentences ) < 2 || mb_strlen( $last ) > $this->max_length / 4 ) {
			return array();
		}

		return array( $last );
	}

	/**
	 * Splits text into sentences no longer than the maximum chunk length.
	 *
	 * @return string[]
	 */
	private function sentences( string $text ): array {
		$parts = preg_split( '/(?<=[.!?…])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		$sentences = array();

		foreach ( $parts ? $parts : array() as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			if ( mb_strlen( $part ) <= $this->max_length ) {
				$sentences[] = $part;
				continue;
			}

			array_push( $sentences, ...$this->wrap( $part ) );
		}

		return $sentences;
	}

	/**
	 * Breaks an overlong sentence at word boundaries.
	 *
	 * @return string[]
	 */
	private function wrap( string $sentence ): array {
		$lines = array();
		$line  = '';

		foreach ( preg_split( '/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			// A single "word" longer than a chunk, e.g. a data URI, is cut hard.
			while ( mb_strlen( $word ) > $this->max_length ) {
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}

				$lines[] = mb_substr( $word, 0, $this->max_length );
				$word    = mb_substr( $word, $this->max_length );
			}

			if ( '' !== $line && mb_strlen( $line ) + mb_strlen( $word ) + 1 > $this->max_length ) {
				$lines[] = $line;
				$line    = '';
			}

			$line = '' === $line ? $word : $line . ' ' . $word;
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}
}
