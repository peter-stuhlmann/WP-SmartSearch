<?php
/**
 * The parts of a search phrase.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Splits a search phrase into what is searched for and what is demanded.
 *
 * Words are searched for; the more of them a page has, the better. Two
 * notations known from the large search engines are demands instead:
 * a phrase in quotation marks must appear exactly like that, and a word with
 * a minus in front must not appear at all.
 */
final class QueryTerms {

	private const MAX_TERMS = 12;

	/**
	 * Function words that say nothing about what is searched for.
	 *
	 * Without them "was könnte ich heute essen" is searched as "heute essen".
	 */
	private const STOP_WORDS = array(
		'der die das den dem des ein eine einer eines einem einen und oder aber doch',
		'ich du er sie es wir ihr mich mir dich dir uns euch man sich mein dein sein',
		'ist sind war waren bin bist sein hat habe haben hatte wird werden wurde kann',
		'könnte können konnte soll sollte muss müssen darf will wollen möchte',
		'was wer wie wo wann warum welche welcher welches wieso',
		'in im an am auf aus bei mit nach von vom zu zum zur für über unter um als auch',
		'nicht kein keine nur noch schon sehr so da hier dort denn mal bitte',
		'the a an and or but is are was were be been am do does did have has had',
		'i you he she it we they me my your our their this that these those',
		'what who how where when why which can could should would will may might',
		'in on at to of for with from by about as not no so if then there here',
	);

	/**
	 * Creates the terms.
	 *
	 * @param string[] $terms    Lower-case words to search for.
	 * @param string[] $phrases  Lower-case phrases that must appear.
	 * @param string[] $excluded Lower-case words that must not appear.
	 * @param string   $text     The phrase without its notation, as a person would say it.
	 */
	private function __construct(
		private readonly array $terms,
		private readonly array $phrases,
		private readonly array $excluded,
		private readonly string $text
	) {}

	/**
	 * Extracts the parts of a phrase. Punctuation and function words are dropped.
	 */
	public static function from_phrase( string $phrase ): self {
		// Whatever kind of quotation mark the keyboard produced.
		$phrase = (string) preg_replace( '/[„“”«»]/u', '"', mb_strtolower( $phrase ) );

		preg_match_all( '/"([^"]+)"/u', $phrase, $quoted );

		// A minus only excludes at the beginning of a word: "e-mail" is one word.
		preg_match_all( '/(?<![\p{L}\p{N}])-([\p{L}\p{N}]+)/u', $phrase, $minus );

		$searched = (string) preg_replace( '/(?<![\p{L}\p{N}])-[\p{L}\p{N}]+/u', ' ', $phrase );
		$words    = preg_split( '/[^\p{L}\p{N}]+/u', $searched, -1, PREG_SPLIT_NO_EMPTY );
		$words    = array_values( array_unique( $words ? $words : array() ) );
		$terms    = array_values( array_diff( $words, explode( ' ', implode( ' ', self::STOP_WORDS ) ) ) );

		return new self(
			// "wer sind wir" has no other words; then the function words are all there is to search for.
			array_slice( array() === $terms ? $words : $terms, 0, self::MAX_TERMS ),
			array_values( array_filter( array_map( array( self::class, 'spaced' ), $quoted[1] ) ) ),
			array_values( array_unique( $minus[1] ) ),
			self::spaced( str_replace( '"', ' ', $searched ) )
		);
	}

	/**
	 * Returns the words to search for.
	 *
	 * @return string[]
	 */
	public function all(): array {
		return $this->terms;
	}

	/**
	 * Returns the phrases a result must contain word for word.
	 *
	 * @return string[]
	 */
	public function phrases(): array {
		return $this->phrases;
	}

	/**
	 * Returns the words a result must not contain.
	 *
	 * @return string[]
	 */
	public function excluded(): array {
		return $this->excluded;
	}

	/**
	 * Tells whether the phrase makes demands a result has to meet.
	 */
	public function has_demands(): bool {
		return array() !== $this->phrases || array() !== $this->excluded;
	}

	/**
	 * Returns the phrase without quotation marks and excluded words, e.g. to compare its meaning.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Tells whether the phrase contained no usable word.
	 */
	public function is_empty(): bool {
		return array() === $this->terms;
	}

	/**
	 * Builds the FULLTEXT boolean query for the words, see IndexTerms.
	 *
	 * Returns an empty string if no word is long enough for the index.
	 */
	public function to_boolean_query(): string {
		return IndexTerms::query( $this->terms );
	}

	/**
	 * Collapses whitespace.
	 */
	private static function spaced( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
