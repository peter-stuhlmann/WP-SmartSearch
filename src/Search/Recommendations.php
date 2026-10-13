<?php
/**
 * Pages the website recommends for certain searches.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Database\ChunkStore;

/**
 * Rules of the kind "for a search for opening hours, show the contact page first".
 *
 * A search cannot know what the people who run a website know: which page
 * answers a question although it does not use the words of the question.
 * A rule says so. It is no synonym list: it names one page for a few
 * searches that the statistics showed to end without a good result.
 */
final class Recommendations {

	public const OPTION = 'prstsearch_recommendations';

	private const MAX_RULES          = 200;
	private const MAX_TERMS_PER_RULE = 20;
	private const MAX_TERM_LENGTH    = 100;

	/**
	 * Creates the service.
	 */
	public function __construct( private readonly ChunkStore $chunks ) {}

	/**
	 * Returns the rules.
	 *
	 * @return array<int, array{terms: string[], post_id: int}>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$rules  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $rule ) {
			$terms   = is_array( $rule['terms'] ?? null ) ? array_values( array_filter( $rule['terms'], 'is_string' ) ) : array();
			$post_id = (int) ( $rule['post_id'] ?? 0 );

			if ( array() !== $terms && $post_id > 0 ) {
				$rules[] = array(
					'terms'   => $terms,
					'post_id' => $post_id,
				);
			}
		}

		return $rules;
	}

	/**
	 * Stores the rules as they come from the settings form. Rows without search terms or without a page are dropped.
	 *
	 * @param mixed $rows Rows of the form: "terms" separated by commas, and "page" ending with the ID of the page.
	 */
	public function save_input( mixed $rows ): void {
		$rules = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$terms   = self::terms( is_array( $row ) && is_string( $row['terms'] ?? null ) ? $row['terms'] : '' );
			$post_id = self::page_id( is_array( $row ) && is_string( $row['page'] ?? null ) ? $row['page'] : '' );

			if ( array() !== $terms && $post_id > 0 ) {
				$rules[] = array(
					'terms'   => $terms,
					'post_id' => $post_id,
				);
			}
		}

		$this->store( $rules );
	}

	/**
	 * Returns the rules in a form that can be taken to another website.
	 *
	 * There the same page has another ID. So each rule also names the page
	 * by its address and its title, which is what import() goes by.
	 *
	 * @return array<int, array{terms: string[], post_id: int, path: string, title: string}>
	 */
	public function export(): array {
		return array_map(
			static fn ( array $rule ): array => $rule + array(
				'path'  => self::path_on_this_website( (string) get_permalink( $rule['post_id'] ) ),
				'title' => get_the_title( $rule['post_id'] ),
			),
			$this->all()
		);
	}

	/**
	 * Replaces the rules with exported ones. Rules whose page cannot be found on this website are left out.
	 *
	 * @param mixed $exported What export() returned, read from a file.
	 * @return int Number of rules left out.
	 */
	public function import( mixed $exported ): int {
		$rules   = array();
		$skipped = 0;

		foreach ( is_array( $exported ) ? $exported : array() as $rule ) {
			$terms   = self::terms( is_array( $rule ) && is_array( $rule['terms'] ?? null ) ? implode( ',', array_filter( $rule['terms'], 'is_string' ) ) : '' );
			$post_id = is_array( $rule ) ? self::page_on_this_website( $rule ) : 0;

			if ( array() === $terms || 0 === $post_id ) {
				++$skipped;
				continue;
			}

			$rules[] = array(
				'terms'   => $terms,
				'post_id' => $post_id,
			);
		}

		$this->store( $rules );

		return $skipped;
	}

	/**
	 * Stores rules that are complete and clean.
	 *
	 * @param array<int, array{terms: string[], post_id: int}> $rules Rules.
	 */
	private function store( array $rules ): void {
		// Not autoloaded: only searches need it.
		update_option( self::OPTION, array_slice( $rules, 0, self::MAX_RULES ), false );
	}

	/**
	 * Returns the address of a page relative to the start page.
	 *
	 * Not relative to the domain: a website in a subfolder would export the
	 * folder with it, and no page would be found again after a move.
	 */
	private static function path_on_this_website( string $url ): string {
		$home = untrailingslashit( home_url() );

		return str_starts_with( $url, $home ) ? '/' . ltrim( substr( $url, strlen( $home ) ), '/' ) : '';
	}

	/**
	 * Finds the page an exported rule means on this website.
	 *
	 * By its address first. The ID only counts if the page with that ID has
	 * the exported title: on another website the same number is another page.
	 *
	 * @param array<string, mixed> $rule An exported rule.
	 * @return int Post ID, 0 if the page is not there.
	 */
	private static function page_on_this_website( array $rule ): int {
		$path = is_string( $rule['path'] ?? null ) ? $rule['path'] : '';

		if ( '' !== $path ) {
			$by_address = (int) url_to_postid( home_url( $path ) );

			if ( $by_address > 0 ) {
				return $by_address;
			}
		}

		$post_id = (int) ( $rule['post_id'] ?? 0 );
		$title   = is_string( $rule['title'] ?? null ) ? $rule['title'] : '';

		return $post_id > 0 && '' !== $title && null !== get_post( $post_id ) && get_the_title( $post_id ) === $title ? $post_id : 0;
	}

	/**
	 * Returns the recommended pages for a query as hits, in the order of the rules.
	 *
	 * @param string      $query The search phrase.
	 * @param SearchHit[] $hits  What the search found. A recommended page among them keeps its passage.
	 * @return SearchHit[]
	 */
	public function hits_for( string $query, array $hits ): array {
		$post_ids = $this->posts_for( $query );

		if ( array() === $post_ids ) {
			return array();
		}

		$found = array();

		foreach ( $hits as $hit ) {
			$found[ $hit->post_id ] = $hit;
		}

		// A page the search did not find is shown with its beginning.
		$first_passages = $this->chunks->first_passages( array_values( array_diff( $post_ids, array_keys( $found ) ) ) );
		$recommended    = array();

		foreach ( $post_ids as $post_id ) {
			$hit = $found[ $post_id ] ?? null;

			if ( null !== $hit ) {
				$recommended[] = new SearchHit( $post_id, $hit->chunk_id, Relevance::High, 1.0, $hit->by_keyword, $hit->by_meaning, $hit->in_title, false, true );
				continue;
			}

			// A page that is not in the index is not searchable, recommended or not.
			if ( isset( $first_passages[ $post_id ] ) ) {
				$recommended[] = new SearchHit( $post_id, $first_passages[ $post_id ], Relevance::High, 1.0, false, false, false, false, true );
			}
		}

		return $recommended;
	}

	/**
	 * Returns the pages recommended for a query.
	 *
	 * A rule applies if the query contains all words of one of its search
	 * terms, in whatever form and order: "wann offen" applies to "Wann habt
	 * ihr offen?".
	 *
	 * @return int[] Post IDs in the order of the rules.
	 */
	public function posts_for( string $query ): array {
		$asked    = self::stems( $query );
		$post_ids = array();

		foreach ( $this->all() as $rule ) {
			foreach ( $rule['terms'] as $term ) {
				$wanted = self::stems( $term );

				if ( array() !== $wanted && array() === array_diff( $wanted, $asked ) ) {
					$post_ids[] = $rule['post_id'];
					break;
				}
			}
		}

		return array_values( array_unique( $post_ids ) );
	}

	/**
	 * Returns the stems of all words of a text. Function words count here: "wann" can be what a rule is about.
	 *
	 * @return string[]
	 */
	private static function stems( string $text ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_map( array( Stemmer::class, 'stem' ), $words ? $words : array() ) ) );
	}

	/**
	 * Splits the search terms of a form row.
	 *
	 * @return string[] Lower-case terms.
	 */
	private static function terms( string $text ): array {
		$terms = array();

		foreach ( explode( ',', $text ) as $term ) {
			$term = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $term ) ) ), 0, self::MAX_TERM_LENGTH );

			if ( '' !== $term ) {
				$terms[] = mb_strtolower( $term );
			}
		}

		return array_slice( array_values( array_unique( $terms ) ), 0, self::MAX_TERMS_PER_RULE );
	}

	/**
	 * Reads the page of a form row: "Contact #12", or just the ID.
	 */
	private static function page_id( string $text ): int {
		return 1 === preg_match( '/#?(\d+)\s*$/', trim( $text ), $found ) ? (int) $found[1] : 0;
	}
}
