<?php
/**
 * Vector search on the index.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

use Generator;
use PrstSearch\Database\ChunkStore;
use PrstSearch\Provider\ClientFactory;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Provider\ProviderException;
use PrstSearch\Provider\RequestThrottle;
use PrstSearch\Provider\TokenUsage;
use PrstSearch\Settings\Settings;
use PrstSearch\Support\Cache;

/**
 * Finds posts by meaning: the query is embedded and compared with the passages.
 *
 * The comparison runs in PHP and needs no special database. Up to a few
 * thousand passages every one of them is compared. Beyond that the database
 * first selects the most promising ones by their signature, see
 * Vector::signature(), and only those are compared exactly. Scores are
 * measured relative to the index, see CenteredSimilarity, and reduced for
 * very short passages.
 */
final class SemanticSearcher {

	/**
	 * Long enough for someone paging through results, short enough that one-off queries do not pile up.
	 */
	private const QUERY_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Number of passages from which the mean of the index is stable enough to centre on.
	 */
	private const MIN_PASSAGES_TO_CENTER = 10;

	/**
	 * Up to this many passages each one is compared with the query. Reading
	 * more vectors than that from the database would take too long per search.
	 */
	private const COMPARE_ALL_UP_TO = 2000;

	/**
	 * How many passages the database selects by signature in a larger index.
	 * Generous, because the signature is rough: what matters is that the best
	 * passages are among them, the exact comparison then puts them in order.
	 */
	private const SHORTLIST = 600;

	/**
	 * How many passages the mean of a large index is calculated from.
	 */
	private const MEAN_SAMPLE = 2000;

	/**
	 * How many of the best posts of a query are remembered, and for how long.
	 * Someone paging through results, or asking for the AI answer to them,
	 * repeats the query within moments.
	 */
	private const REMEMBERED_POSTS = 300;
	private const RESULT_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	private const COUNT_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Tokens used by the most recent search. Zero if the query embedding was cached.
	 */
	private TokenUsage $last_usage;

	/**
	 * Scores of this request, keyed by query.
	 *
	 * @var array<string, array{posts: array<int, array{chunk_id: int, similarity: float}>, scale: SimilarityScale}>
	 */
	private array $scored = array();

	/**
	 * Creates the searcher.
	 */
	public function __construct(
		private readonly ChunkStore $chunks,
		private readonly ClientFactory $clients,
		private readonly ProviderConfig $config,
		private readonly Settings $settings,
		private readonly Cache $cache,
		private readonly RequestThrottle $throttle
	) {
		$this->last_usage = new TokenUsage();
	}

	/**
	 * Searches the index for passages similar to the query.
	 *
	 * @return array<int, array{chunk_id: int, similarity: float, relevance: Relevance}> Best passage per post, keyed by post ID, best first.
	 *
	 * @throws ProviderException If the query cannot be embedded.
	 */
	public function search( string $query, int $limit ): array {
		$scored  = $this->scored( $query );
		$matches = array();

		foreach ( $scored['posts'] as $post_id => $post ) {
			// Sorted best first, so everything from here on is noise as well.
			if ( $scored['scale']->is_noise( $post['similarity'] ) || count( $matches ) >= $limit ) {
				break;
			}

			$matches[ $post_id ] = $post + array( 'relevance' => $scored['scale']->relevance( $post['similarity'] ) );
		}

		return $matches;
	}

	/**
	 * Returns the posts closest to the query, whether or not they are close enough to count as results.
	 *
	 * For a second opinion on queries the similarity alone cannot settle, see ResultJudge.
	 *
	 * @return array<int, array{chunk_id: int, similarity: float}> Best passage per post, keyed by post ID, best first.
	 *
	 * @throws ProviderException If the query cannot be embedded.
	 */
	public function candidates( string $query, int $limit ): array {
		return array_slice( $this->scored( $query )['posts'], 0, $limit, true );
	}

	/**
	 * Scores every post against the query. Kept for the request, because search and candidates share it.
	 *
	 * @return array{posts: array<int, array{chunk_id: int, similarity: float}>, scale: SimilarityScale} Posts keyed by ID, best first.
	 *
	 * @throws ProviderException If the query cannot be embedded.
	 */
	private function scored( string $query ): array {
		if ( isset( $this->scored[ $query ] ) ) {
			$this->last_usage = new TokenUsage();

			return $this->scored[ $query ];
		}

		$model     = $this->config->embedding_model();
		$cache_key = $this->cache->index_key( 'semantic', $model, mb_strtolower( $query ) );
		$use_cache = $this->settings->bool( 'cache_enabled' );
		$cached    = $use_cache ? $this->cache->get( $cache_key ) : null;

		if ( is_array( $cached ) && is_array( $cached['posts'] ?? null ) ) {
			$this->last_usage       = new TokenUsage();
			$this->scored[ $query ] = array(
				'posts' => $cached['posts'],
				'scale' => $this->scale( ! empty( $cached['is_centered'] ) ),
			);

			return $this->scored[ $query ];
		}

		$embedded     = $this->embedded_count( $model );
		$query_vector = $this->query_vector( $query, $model );
		$mean         = $this->index_mean( $model, $embedded );
		$centered     = array() === $mean ? null : new CenteredSimilarity( $query_vector, $mean );
		$posts        = array();

		foreach ( $this->passages_to_compare( $model, $embedded, $query_vector ) as $row ) {
			$vector     = Vector::unpack( $row['embedding'] );
			$similarity = null === $centered ? Vector::dot( $query_vector, $vector ) : $centered->score( $vector );
			$similarity = $similarity * CenteredSimilarity::length_weight( $row['length'] );

			if ( $similarity <= ( $posts[ $row['post_id'] ]['similarity'] ?? -INF ) ) {
				continue;
			}

			$posts[ $row['post_id'] ] = array(
				'chunk_id'   => $row['id'],
				'similarity' => $similarity,
			);
		}

		uasort( $posts, static fn ( array $a, array $b ): int => $b['similarity'] <=> $a['similarity'] );

		$posts = array_slice( $posts, 0, self::REMEMBERED_POSTS, true );

		$this->scored[ $query ] = array(
			'posts' => $posts,
			'scale' => $this->scale( null !== $centered ),
		);

		if ( $use_cache ) {
			$this->cache->set(
				$cache_key,
				array(
					'posts'       => $posts,
					'is_centered' => null !== $centered,
				),
				self::RESULT_CACHE_TTL
			);
		}

		return $this->scored[ $query ];
	}

	/**
	 * Returns the scale that fits how the similarities were measured.
	 */
	private function scale( bool $is_centered ): SimilarityScale {
		return $is_centered ? $this->config->centered_similarity_scale() : $this->config->similarity_scale();
	}

	/**
	 * Returns the number of passages that have an embedding of the model.
	 */
	private function embedded_count( string $model ): int {
		// Counting reads the whole table. The number only decides how the search goes about its work,
		// so one that is a few minutes old serves as well.
		$key    = $this->cache->index_key( 'embedded', $model );
		$cached = $this->cache->get( $key );

		if ( is_numeric( $cached ) ) {
			return (int) $cached;
		}

		$counts   = $this->chunks->counts( $model );
		$embedded = $counts['chunks'] - $counts['missing'];

		$this->cache->set( $key, (string) $embedded, self::COUNT_CACHE_TTL );

		return $embedded;
	}

	/**
	 * Returns the passages the query is compared with exactly.
	 *
	 * @param float[] $query_vector Normalised embedding of the query.
	 * @return iterable<int, array{id: int, post_id: int, embedding: string, length: int}>
	 */
	private function passages_to_compare( string $model, int $embedded, array $query_vector ): iterable {
		if ( $embedded <= self::COMPARE_ALL_UP_TO ) {
			return $this->chunks->embeddings( $model );
		}

		return $this->chunks->embeddings_of(
			$this->chunks->nearest( $model, Vector::signature( $query_vector ), self::SHORTLIST )
		);
	}

	/**
	 * Returns the tokens used by the most recent search.
	 */
	public function last_usage(): TokenUsage {
		return $this->last_usage;
	}

	/**
	 * Returns the mean vector of all passages, or an empty array if the index is too small for one.
	 *
	 * Calculating it means reading many embeddings, so it is cached. On a
	 * large index an even sample is read instead of all of them: the mean of
	 * two thousand passages hardly differs from the mean of fifty thousand.
	 *
	 * @param int $embedded Number of passages that have an embedding.
	 * @return float[]
	 */
	private function index_mean( string $model, int $embedded ): array {
		if ( $embedded < self::MIN_PASSAGES_TO_CENTER ) {
			return array();
		}

		$step = max( 1, intdiv( $embedded, self::MEAN_SAMPLE ) );

		// New embeddings change the mean without changing the text. A few more hardly move it, so it
		// only counts as outdated when their number has grown by about a quarter: otherwise it would
		// be calculated again and again while a large index is being built.
		$packed = $this->cache->for_index(
			'mean',
			$model,
			fn (): string => self::encode( CenteredSimilarity::mean( $this->vectors( $model, $step ) ) ),
			(string) (int) floor( log( $embedded, 1.25 ) )
		);

		return self::decode( is_string( $packed ) ? $packed : '' );
	}

	/**
	 * Yields the vectors of every n-th passage, one at a time.
	 *
	 * @return Generator<int, float[]>
	 */
	private function vectors( string $model, int $step ): Generator {
		foreach ( $this->chunks->embeddings( $model, $step ) as $row ) {
			yield Vector::unpack( $row['embedding'] );
		}
	}

	/**
	 * Returns the normalised embedding of the query.
	 *
	 * Cached for a short time, because paging through results repeats the
	 * same query. A cache miss is a paid provider call triggered by a
	 * visitor, so it is rate limited.
	 *
	 * @return float[]
	 *
	 * @throws ProviderException If the provider call fails or the rate limit is reached.
	 */
	private function query_vector( string $query, string $model ): array {
		$this->last_usage = new TokenUsage();

		$cache_key = Cache::key( 'query', $model, mb_strtolower( $query ) );
		$use_cache = $this->settings->bool( 'cache_enabled' );
		$cached    = $use_cache ? $this->cache->get( $cache_key ) : null;
		$vector    = self::decode( is_string( $cached ) ? $cached : '' );

		if ( array() !== $vector ) {
			return $vector;
		}

		if ( ! $this->throttle->allow( RequestThrottle::EMBEDDING ) ) {
			throw new ProviderException( 'Too many search requests at the moment.' );
		}

		$result = $this->clients->create()->embed( array( $query ) );
		$vector = Vector::normalize( $result->vectors[0] );

		$this->last_usage = $result->usage;

		if ( $use_cache ) {
			$this->cache->set( $cache_key, self::encode( $vector ), self::QUERY_CACHE_TTL );
		}

		return $vector;
	}

	/**
	 * Turns a vector into text that survives any cache backend.
	 *
	 * @param float[] $vector Vector.
	 */
	private static function encode( array $vector ): string {
		return base64_encode( Vector::pack( $vector ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe cache value.
	}

	/**
	 * Restores a vector from its cached form.
	 *
	 * @return float[] Empty if the text is not a cached vector.
	 */
	private static function decode( string $encoded ): array {
		$binary = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe cache value.

		return false === $binary || '' === $binary ? array() : Vector::unpack( $binary );
	}
}
