<?php
/**
 * The words that occur in the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Support\Cache;

/**
 * Collects the vocabulary of the website, as the dictionary for spelling correction.
 *
 * Building it means reading every passage once, so it is cached until the
 * index changes.
 */
final class Vocabulary {

	/**
	 * Shorter words are never corrected, see SpellingCorrector, so they need no dictionary entry.
	 */
	public const MIN_WORD_LENGTH = 4;

	/**
	 * Keeps the cached dictionary of very large sites at a reasonable size. Rare words go first.
	 */
	private const MAX_WORDS = 20000;

	/**
	 * Creates the vocabulary.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly Cache $cache
	) {}

	/**
	 * Returns the words of the index with how often they occur.
	 *
	 * @return array<string, int> Frequencies keyed by lower-case word.
	 */
	public function words(): array {
		return (array) $this->cache->for_index(
			'vocabulary',
			'',
			fn (): array => self::count_words( $this->chunks->texts() )
		);
	}

	/**
	 * Counts the words of a series of texts.
	 *
	 * @param iterable<string> $texts Texts.
	 * @return array<string, int> Frequencies keyed by lower-case word, most frequent first.
	 */
	public static function count_words( iterable $texts ): array {
		$counts = array();

		foreach ( $texts as $text ) {
			$found = preg_split( '/[^\p{L}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );

			foreach ( $found ? $found : array() as $word ) {
				if ( mb_strlen( $word ) >= self::MIN_WORD_LENGTH ) {
					$counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $counts );

		return array_slice( $counts, 0, self::MAX_WORDS, true );
	}
}
