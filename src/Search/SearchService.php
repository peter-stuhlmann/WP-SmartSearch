<?php
/**
 * The search itself.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Provider\ProviderException;
use PrstSearch\Provider\TokenUsage;
use PrstSearch\Provider\UsageLedger;
use PrstSearch\Settings\Settings;
use PrstSearch\Stats\SearchLogger;

/**
 * Answers queries: keyword search always, semantic search on top when it is configured and available.
 */
final class SearchService {

	/**
	 * Longest search phrase, in characters. Whatever is longer is not a search.
	 */
	public const MAX_QUERY_LENGTH = 200;

	/**
	 * Log tokens of the searches of this request, keyed by query.
	 *
	 * @var array<string, string>
	 */
	private array $log_tokens = array();

	/**
	 * Rankings of this request, keyed by query.
	 *
	 * @var array<string, Ranking>
	 */
	private array $rankings = array();

	/**
	 * Rankings of this request before the language model was asked, keyed by query.
	 *
	 * @var array<string, Ranking>
	 */
	private array $unaided = array();

	/**
	 * Creates the service.
	 */
	public function __construct(
		private readonly KeywordSearcher $keyword,
		private readonly SemanticSearcher $semantic,
		private readonly ProviderConfig $config,
		private readonly UsageLedger $ledger,
		private readonly Settings $settings,
		private readonly SearchLogger $logger,
		private readonly Vocabulary $vocabulary,
		private readonly SecondOpinion $second_opinion,
		private readonly HitFilter $filter,
		private readonly Demands $demands,
		private readonly Recommendations $recommendations
	) {}

	/**
	 * Returns one page of results.
	 *
	 * @param string   $query    The search phrase.
	 * @param int      $page     Page number, starting at 1.
	 * @param int|null $per_page   Hits per page, or null for the configured number.
	 * @param string[] $post_types Limit the hits to these post types. Empty for all indexed types.
	 * @param bool     $is_quick   Return what the search finds by itself, without waiting for the language
	 *                             model. The results then say whether asking it is still due, see refined().
	 */
	public function search( string $query, int $page = 1, ?int $per_page = null, array $post_types = array(), bool $is_quick = false ): SearchResults {
		$query    = self::normalize( $query );
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page ?? $this->settings->int( 'results_per_page' ) );

		$started  = microtime( true );
		$ranking  = $is_quick ? $this->rank_unaided( $query ) : $this->rank( $query );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		// One log entry per search, not one per page viewed. Everything that renders the same
		// search in this request gets the same token, so clicks and answer costs find the entry.
		if ( 1 === $page && '' !== $query && ! isset( $this->log_tokens[ $query ] ) ) {
			$this->log_tokens[ $query ] = $this->logger->log_search( $query, $ranking, $duration );
		}

		return $this->page_of( $ranking, $query, $page, $per_page, $post_types, $this->log_tokens[ $query ] ?? '', $is_quick && $this->second_opinion->is_wanted( $ranking ) );
	}

	/**
	 * Returns one page of results of the basic search: what costs little and nothing else.
	 *
	 * For visitors over the search limit, see SearchGuard. Only the keyword
	 * search runs, without the provider, without the spelling correction,
	 * without reading the whole index for text inside words, and the search is
	 * not recorded in the statistics. Quotation marks and excluded words still
	 * apply, and so does everything that decides what a visitor may see.
	 *
	 * @param string   $query      The search phrase.
	 * @param int      $page       Page number, starting at 1.
	 * @param int|null $per_page   Hits per page, or null for the configured number.
	 * @param string[] $post_types Limit the hits to these post types. Empty for all indexed types.
	 */
	public function basic( string $query, int $page = 1, ?int $per_page = null, array $post_types = array() ): SearchResults {
		$query    = self::normalize( $query );
		$per_page = max( 1, $per_page ?? $this->settings->int( 'results_per_page' ) );
		$terms    = QueryTerms::from_phrase( $query );
		$hits     = $terms->is_empty() ? array() : $this->merge( $this->keyword->search( $terms, $this->max_hits(), false ), array() );

		// Marked as degraded: the full search would have done more.
		$ranking = new Ranking( $this->demands->met_by( $hits, $terms ), $terms, SearchMode::Keyword, true, new TokenUsage() );

		return $this->page_of( $ranking, $query, max( 1, $page ), $per_page, $post_types, '', false );
	}

	/**
	 * Returns the first page of results after the language model had its say.
	 *
	 * The second step of a quick search, see search(). The search is already
	 * logged; what the model cost is added to that entry.
	 *
	 * @param string   $query      The search phrase.
	 * @param string   $log_token  Token of the quick search this continues.
	 * @param int|null $per_page   Hits per page, or null for the configured number.
	 * @param string[] $post_types Limit the hits to these post types. Empty for all indexed types.
	 */
	public function refined( string $query, string $log_token, ?int $per_page = null, array $post_types = array() ): SearchResults {
		$query    = self::normalize( $query );
		$per_page = max( 1, $per_page ?? $this->settings->int( 'results_per_page' ) );
		$unaided  = $this->rank_unaided( $query );
		$ranking  = $this->rank( $query );

		$this->logger->log_answer_usage( $log_token, $ranking->usage->minus( $unaided->usage ) );

		return $this->page_of( $ranking, $query, 1, $per_page, $post_types, $log_token, false );
	}

	/**
	 * Cuts one page out of a ranking.
	 *
	 * @param string[] $post_types Limit the hits to these post types. Empty for all indexed types.
	 */
	private function page_of( Ranking $ranking, string $query, int $page, int $per_page, array $post_types, string $log_token, bool $is_provisional ): SearchResults {
		$hits = self::of_post_types( $ranking->hits, $post_types );

		return new SearchResults(
			$query,
			array_slice( $hits, ( $page - 1 ) * $per_page, $per_page ),
			count( $hits ),
			$page,
			$per_page,
			$ranking,
			$log_token,
			$post_types,
			$is_provisional
		);
	}

	/**
	 * Returns the first page of results without writing to the statistics. For the test field in the admin area.
	 */
	public function preview( string $query ): SearchResults {
		$query    = self::normalize( $query );
		$ranking  = $this->rank( $query );
		$per_page = max( 1, $this->settings->int( 'results_per_page' ) );

		return new SearchResults( $query, array_slice( $ranking->hits, 0, $per_page ), count( $ranking->hits ), 1, $per_page, $ranking );
	}

	/**
	 * Returns all hits of a query, best first.
	 */
	public function rank( string $query ): Ranking {
		$query = self::normalize( $query );

		return $this->rankings[ $query ] ??= $this->build_ranking( $query );
	}

	/**
	 * Returns what the search finds by itself: by keyword, by meaning and with corrected spelling.
	 */
	private function rank_unaided( string $query ): Ranking {
		return $this->unaided[ $query ] ??= $this->build_unaided_ranking( $query );
	}

	/**
	 * Brings a search phrase into the form it is searched and logged in.
	 */
	public static function normalize( string $query ): string {
		// Cut here, so the limit holds for every way a query can come in, not only for the REST API.
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', Unicode::composed( $query ) ) ), 0, self::MAX_QUERY_LENGTH );
	}

	/**
	 * Keeps the hits of the given post types.
	 *
	 * @param SearchHit[] $hits       Hits to filter.
	 * @param string[]    $post_types Allowed post types, empty for no restriction.
	 * @return SearchHit[]
	 */
	private static function of_post_types( array $hits, array $post_types ): array {
		if ( array() === $post_types ) {
			return $hits;
		}

		_prime_post_caches( array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $hits ), false, false );

		return array_values(
			array_filter(
				$hits,
				static fn ( SearchHit $hit ): bool => in_array( get_post_type( $hit->post_id ), $post_types, true )
			)
		);
	}

	/**
	 * Builds the ranking of a query.
	 *
	 * Two safety nets follow the plain search. A query that found nothing
	 * relevant is searched again with its spelling corrected; a word the
	 * website does contain is never second-guessed. And if still no hit is a
	 * clear match, the language model is asked which nearby pages fit.
	 */
	private function build_ranking( string $query ): Ranking {
		$ranking = $this->rank_unaided( $query );

		return $this->second_opinion->refine( $ranking, '' === $ranking->corrected_query ? $query : $ranking->corrected_query );
	}

	/**
	 * Builds the ranking of a query without asking the language model.
	 */
	private function build_unaided_ranking( string $query ): Ranking {
		$ranking = $this->run( $query );

		// Quotation marks and excluded words say precisely what is wanted. Nothing is added to that,
		// and a corrected phrase would lose them.
		if ( $ranking->terms->is_empty() || $ranking->terms->has_demands() ) {
			return $ranking;
		}

		if ( ! $ranking->has_hit_of( Relevance::Medium ) ) {
			$ranking = $this->with_corrected_spelling( $ranking );
		}

		return $this->with_recommended_pages( $ranking, $query );
	}

	/**
	 * Puts the pages the website recommends for the query first, see Recommendations.
	 */
	private function with_recommended_pages( Ranking $ranking, string $query ): Ranking {
		// A recommendation is a result like any other: only shown if the visitor may see the page.
		$recommended = $this->filter->apply( $this->recommendations->hits_for( $query, $ranking->hits ) );

		if ( array() === $recommended ) {
			return $ranking;
		}

		$post_ids = array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $recommended );
		$others   = array_filter( $ranking->hits, static fn ( SearchHit $hit ): bool => ! in_array( $hit->post_id, $post_ids, true ) );

		return $ranking->with( array_merge( $recommended, array_values( $others ) ), new TokenUsage() );
	}

	/**
	 * Searches again with misspelled words corrected, and returns that ranking if it found more.
	 */
	private function with_corrected_spelling( Ranking $ranking ): Ranking {
		$corrected_terms = ( new SpellingCorrector( $this->vocabulary->words() ) )->correct( $ranking->terms->all() );

		if ( null === $corrected_terms ) {
			return $ranking;
		}

		$corrected = $this->run( implode( ' ', $corrected_terms ), true );

		// Both attempts were paid for, whichever is used.
		return $corrected->has_hit_of( Relevance::Medium )
			? $corrected->with( $corrected->hits, $ranking->usage )
			: $ranking->with( $ranking->hits, $corrected->usage );
	}

	/**
	 * Runs the searchers and merges their results.
	 *
	 * @param string $query        The phrase to search for.
	 * @param bool   $is_corrected Whether the phrase is a spelling correction of what the visitor typed.
	 */
	private function run( string $query, bool $is_corrected = false ): Ranking {
		$terms = QueryTerms::from_phrase( $query );

		if ( $terms->is_empty() ) {
			return new Ranking( array(), $terms, SearchMode::Keyword, false, new TokenUsage() );
		}

		$keyword_matches  = $this->keyword->search( $terms, $this->max_hits() );
		$semantic_matches = array();
		$usage            = new TokenUsage();
		$mode             = SearchMode::Keyword;
		$wants_semantic   = $this->config->embeddings_enabled();

		if ( $wants_semantic && ! $this->ledger->is_budget_exhausted() ) {
			try {
				// The meaning of the phrase is what a person would say, without the notation.
				$semantic_matches = $this->semantic->search( $terms->has_demands() ? $terms->text() : $query, $this->max_hits() );
				$usage            = $this->semantic->last_usage();
				$mode             = SearchMode::tryFrom( $this->settings->string( 'mode' ) ) ?? SearchMode::Semantic;

				$this->ledger->record( UsageLedger::PURPOSE_SEARCH, $usage );
			} catch ( ProviderException ) {
				// Keyword results are better than an error page.
				$mode = SearchMode::Keyword;
			}
		}

		return new Ranking(
			$this->demands->met_by( $this->merge( $keyword_matches, $semantic_matches ), $terms ),
			$terms,
			$mode,
			$wants_semantic && SearchMode::Keyword === $mode,
			$usage,
			$is_corrected ? $query : ''
		);
	}

	/**
	 * Merges keyword and semantic matches into one ordered list of hits.
	 *
	 * @param array<int, array{chunk_id: int, coverage: float, in_title: bool}>         $keyword_matches Keyword matches by post ID.
	 * @param array<int, array{chunk_id: int, similarity: float, relevance: Relevance}> $semantic_matches Semantic matches by post ID.
	 * @return SearchHit[]
	 */
	private function merge( array $keyword_matches, array $semantic_matches ): array {
		$hits = array();

		foreach ( RankFusion::fuse( array( array_keys( $semantic_matches ), array_keys( $keyword_matches ) ) ) as $post_id ) {
			$keyword  = $keyword_matches[ $post_id ] ?? null;
			$semantic = $semantic_matches[ $post_id ] ?? null;

			$relevance = $semantic['relevance'] ?? Relevance::Low;

			if ( null !== $keyword ) {
				$relevance = $relevance->or_better( Relevance::from_keyword_coverage( $keyword['coverage'] ) );
			}

			$hits[] = new SearchHit(
				$post_id,
				// The passage shown with the hit. One that has the search words is what the visitor looks for.
				$keyword['chunk_id'] ?? $semantic['chunk_id'],
				$relevance,
				$semantic['similarity'] ?? $keyword['coverage'],
				null !== $keyword,
				null !== $semantic,
				$keyword['in_title'] ?? false
			);
		}

		if ( $this->settings->bool( 'hide_weak_results' ) ) {
			$hits = array_filter( $hits, static fn ( SearchHit $hit ): bool => Relevance::Low !== $hit->relevance );
		}

		// Keyword and semantic search each return up to the limit; together they must not exceed it either.
		return $this->filter->apply( array_slice( Ranking::by_relevance( $hits ), 0, $this->max_hits() ) );
	}

	/**
	 * Returns how many hits a query has at most.
	 *
	 * Every hit is read and judged, so the limit caps the work of a request.
	 */
	public function max_hits(): int {
		return $this->settings->int( 'max_results' );
	}
}
