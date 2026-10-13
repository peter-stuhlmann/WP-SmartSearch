<?php
/**
 * Compounds that end with a short search word.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Database\ChunkStore;

/**
 * Tells "Haustür" from "Natur" for somebody searching for "Tür".
 *
 * A word of four letters or more is taken as the last part of any longer
 * word that ends with it. With three letters that goes wrong too often:
 * "tur" ends "Natur" and "Kultur", "ess" ends "Adresse" and "Prozess". So
 * such a word only counts fully where what stands before it is a word of its
 * own that the website uses: "Haus" is one, "Na" and "Kul" are not.
 *
 * A website can say "Mietwagen" a hundred times and never "Miete". So a
 * first part nobody can vouch for still makes a likely compound, which counts
 * a little, if it is long enough to be a word and the last part is exactly
 * what was typed: "Natur" is not written with the "ü" of "Tür", and "Prozess"
 * does not end in "Essen".
 */
final class CompoundEndings {

	/**
	 * The shortest first part of a compound: the length of the shortest word in the index.
	 */
	private const MIN_BEGINNING = IndexTerms::MIN_LENGTH;

	/**
	 * The shortest first part that is taken for a word without the website using it as one.
	 */
	private const MIN_UNKNOWN_BEGINNING = 4;

	/**
	 * Marks, in what in() returns, the compounds that are only likely ones.
	 */
	public const LIKELY = '~';

	/**
	 * How many first parts are looked up for one text, the most frequent ones first. A text can hold any number of candidates.
	 */
	private const MAX_LOOKUPS = 200;

	/**
	 * How many of them one query asks for.
	 */
	private const BATCH = 40;

	/**
	 * Whether a stem is a word of the website, for those already looked up.
	 *
	 * @var array<string, bool>
	 */
	private array $known = array();

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly Vocabulary $vocabulary
	) {}

	/**
	 * Finds the compounds of the whole website that end with one of the short search terms.
	 *
	 * They are what the index is asked for by name. Asking it for every word
	 * that ends with "tür" would return "Natur" and "Kultur" as well, and on a
	 * website that has many of those, the real matches would be crowded out.
	 *
	 * @param string[] $terms Lower-case search terms.
	 * @return string[] Compounds in lower case, the most frequent first.
	 */
	public function of_website( array $terms ): array {
		if ( array() === array_filter( $terms, static fn ( string $term ): bool => self::is_judged( Stemmer::stem( $term ) ) ) ) {
			return array();
		}

		// The vocabulary lists every word once, the most frequent first.
		$words = implode( ' ', array_map( 'strval', array_keys( $this->vocabulary->words() ) ) );

		return array_values( array_unique( array_merge( ...array_values( $this->in( $words, $terms ) ) ) ) );
	}

	/**
	 * Tells whether a stem is one of those this class decides about.
	 *
	 * A number is none: "123" does not end "456123" the way "Tür" ends "Haustür".
	 */
	private static function is_judged( string $stem ): bool {
		return IndexTerms::is_short_ending( $stem ) && 1 === preg_match( '/\p{L}/u', $stem );
	}

	/**
	 * Finds the compounds in a text that end with one of the short search terms.
	 *
	 * @param string   $given Text to look in.
	 * @param string[] $terms Lower-case search terms.
	 * @return array<string, string[]> Compounds in lower case, as they are written in the text, keyed by the
	 *                                 term they end with. Those that are only likely compounds are keyed by
	 *                                 the term with LIKELY in front. Terms without any are left out.
	 */
	public function in( string $given, array $terms ): array {
		$candidates = array();
		$likely     = array();
		$text       = null;

		foreach ( $terms as $term ) {
			$term = (string) $term;
			$stem = Stemmer::stem( $term );

			if ( ! self::is_judged( $stem ) ) {
				continue;
			}

			$text ??= mb_strtolower( $given );

			// The shortest beginning that leaves a form of the term is the one to judge: "abend" in "abendessen".
			$pattern = '/(?<![\p{L}\p{N}])([\p{L}\p{N}]{' . self::MIN_BEGINNING . ',}?)' . Stemmer::forms_pattern( $stem ) . '(?![\p{L}\p{N}])/u';

			if ( ! preg_match_all( $pattern, $text, $found, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $found as $match ) {
				// A linking "s" as in "Arbeitstag" goes the way a genitive does.
				$candidates[ $term ][ $match[0] ] = Stemmer::stem( $match[1] );

				if ( mb_strlen( $match[1] ) >= self::MIN_UNKNOWN_BEGINNING && mb_substr( $match[0], mb_strlen( $match[1] ) ) === $term ) {
					$likely[ $term ][ $match[0] ] = true;
				}
			}
		}

		if ( array() === $candidates ) {
			return array();
		}

		$this->look_up( array_merge( ...array_values( array_map( 'array_values', $candidates ) ) ) );

		$compounds = array();

		foreach ( $candidates as $term => $words ) {
			$accepted = array_filter( $words, fn ( string $beginning ): bool => $this->known[ $beginning ] ?? false );
			$guessed  = array_diff_key( $likely[ $term ] ?? array(), $accepted );

			if ( array() !== $accepted ) {
				$compounds[ $term ] = array_map( 'strval', array_keys( $accepted ) );
			}

			if ( array() !== $guessed ) {
				$compounds[ self::LIKELY . $term ] = array_map( 'strval', array_keys( $guessed ) );
			}
		}

		return $compounds;
	}

	/**
	 * Asks the index which of the stems are words of the website, unless that is known already.
	 *
	 * @param string[] $stems Stems of the first parts.
	 */
	private function look_up( array $stems ): void {
		$counts = array_count_values( $stems );

		// The first parts most compounds share decide about the most matches.
		arsort( $counts );

		$unknown = array_filter(
			array_map( 'strval', array_keys( $counts ) ),
			fn ( string $stem ): bool => ! isset( $this->known[ $stem ] ) && mb_strlen( $stem ) >= self::MIN_BEGINNING
		);

		foreach ( array_chunk( array_slice( $unknown, 0, self::MAX_LOOKUPS ), self::BATCH ) as $batch ) {
			$this->known += array_fill_keys( $batch, false );

			foreach ( $this->chunks->known_terms( $batch ) as $stem ) {
				$this->known[ $stem ] = true;
			}
		}
	}
}
