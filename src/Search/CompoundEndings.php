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
 * such a word only counts where what stands before it is a word of its own
 * that the website uses: "Haus" is one, "Na" and "Kul" are not.
 */
final class CompoundEndings {

	/**
	 * The shortest first part of a compound: the length of the shortest word in the index.
	 */
	private const MIN_BEGINNING = IndexTerms::MIN_LENGTH;

	/**
	 * How many first parts are looked up in one request. A text can hold any number of candidates.
	 */
	private const MAX_LOOKUPS = 40;

	/**
	 * Whether a stem is a word of the website, for those already looked up.
	 *
	 * @var array<string, bool>
	 */
	private array $known = array();

	/**
	 * Creates the service.
	 */
	public function __construct( private readonly ChunkStore $chunks ) {}

	/**
	 * Finds the compounds in a text that end with one of the short search terms.
	 *
	 * @param string   $text  Text to look in.
	 * @param string[] $terms Lower-case search terms.
	 * @return array<string, string[]> Compounds in lower case, as they are written in the text, keyed by the
	 *                                 term they end with. Terms without one are left out.
	 */
	public function in( string $text, array $terms ): array {
		$candidates = array();

		foreach ( $terms as $term ) {
			$stem = Stemmer::stem( $term );

			if ( ! IndexTerms::is_short_ending( $stem ) ) {
				continue;
			}

			// The shortest beginning that leaves a form of the term is the one to judge: "abend" in "abendessen".
			$pattern = '/(?<![\p{L}\p{N}])([\p{L}\p{N}]{' . self::MIN_BEGINNING . ',}?)' . Stemmer::forms_pattern( $stem ) . '(?![\p{L}\p{N}])/u';

			if ( ! preg_match_all( $pattern, mb_strtolower( $text ), $found, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $found as $match ) {
				// A linking "s" as in "Arbeitstag" goes the way a genitive does.
				$candidates[ $term ][ $match[0] ] = Stemmer::stem( $match[1] );
			}
		}

		if ( array() === $candidates ) {
			return array();
		}

		$this->look_up( array_merge( ...array_values( array_map( 'array_values', $candidates ) ) ) );

		$compounds = array();

		foreach ( $candidates as $term => $words ) {
			$accepted = array_keys( array_filter( $words, fn ( string $beginning ): bool => $this->known[ $beginning ] ?? false ) );

			if ( array() !== $accepted ) {
				$compounds[ $term ] = array_map( 'strval', $accepted );
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
		$unknown = array_filter(
			array_unique( $stems ),
			fn ( string $stem ): bool => ! isset( $this->known[ $stem ] ) && mb_strlen( $stem ) >= self::MIN_BEGINNING
		);

		$unknown = array_slice( array_values( $unknown ), 0, max( 0, self::MAX_LOOKUPS - count( $this->known ) ) );

		if ( array() === $unknown ) {
			return;
		}

		$this->known += array_fill_keys( $unknown, false );

		foreach ( $this->chunks->known_terms( $unknown ) as $stem ) {
			$this->known[ $stem ] = true;
		}
	}
}
