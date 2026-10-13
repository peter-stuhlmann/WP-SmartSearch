<?php
/**
 * Compares the keyword search of the plugin with the search of WordPress on the travel corpus.
 *
 * For every query it is known which trips a visitor would want, from what
 * the corpus generator recorded about each post and from the words of the
 * texts. Measured is how many of the first ten results are such trips
 * (precision), and how many of the wanted trips are among the first 200 (recall).
 *
 * Usage: WP_LOAD_PATH=/path/to/wp-load.php php tests/benchmark/quality.php
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require (string) getenv( 'WP_LOAD_PATH' );

use PrstSearch\Plugin;
use PrstSearch\Search\SearchHit;
use PrstSearch\Search\SearchService;

global $wpdb;

const PRSTSEARCH_QUALITY_DEPTH = 200;

// The trips, with what is known about each.
$trips = array();
$rows  = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_content, k.meta_value AS kind, c.meta_value AS countries
	FROM {$wpdb->posts} p
	JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_prstsearch_corpus_kind'
	JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_prstsearch_corpus_country'
	WHERE p.post_status = 'publish'"
);

foreach ( $rows as $row ) {
	$trips[ (int) $row->ID ] = array(
		'kind'      => $row->kind,
		'countries' => explode( '|', $row->countries ),
		'text'      => $row->post_title . ' ' . wp_strip_all_tags( $row->post_content ),
	);
}

if ( array() === $trips ) {
	echo "No corpus found. Create it with tests/benchmark/travel-corpus.php first.\n";
	exit( 1 );
}

$to       = static fn ( string $country ): callable => static fn ( array $trip ): bool => in_array( $country, $trip['countries'], true );
$of_kind  = static fn ( string $kind ): callable => static fn ( array $trip ): bool => $kind === $trip['kind'];
$both     = static fn ( callable $a, callable $b ): callable => static fn ( array $trip ): bool => $a( $trip ) && $b( $trip );
$with     = static fn ( string $pattern ): callable => static fn ( array $trip ): bool => 1 === preg_match( '/' . $pattern . '/iu', $trip['text'] );
$word     = static fn ( string $forms ): callable => $with( '(?<![\p{L}])(?:' . $forms . ')(?![\p{L}])' );
$compound = static fn ( string $forms ): callable => $with( '(?:' . $forms . ')(?![\p{L}])' );

/*
 * What a visitor means by each query, said without either search: by the
 * facts of the trip, or by the words a person would accept as the same word.
 */
$queries = array(
	'the word as it stands'              => array(
		'Italien'            => $to( 'Italien' ),
		'Norwegen'           => $to( 'Norwegen' ),
		'Sri Lanka'          => $to( 'Sri Lanka' ),
		'Safari'             => $word( 'Safaris?' ),
		'Halbpension'        => $word( 'Halbpension' ),
		'Machu Picchu'       => $word( 'Machu Picchu' ),
	),
	'several words'                      => array(
		'Rundreise Italien'      => $both( $of_kind( 'Rundreise' ), $to( 'Italien' ) ),
		'Wanderreise Österreich' => $both( $of_kind( 'Wanderreise' ), $to( 'Österreich' ) ),
		'Safari Tansania'        => $both( $word( 'Safaris?' ), $to( 'Tansania' ) ),
		'Familienreise Kuba'     => $both( $of_kind( 'Familienreise' ), $to( 'Kuba' ) ),
		'Radreise Frankreich'    => $both( $of_kind( 'Radreise' ), $to( 'Frankreich' ) ),
		'Japan Kirschblüte'      => $both( $to( 'Japan' ), $word( 'Kirschblüte' ) ),
	),
	'another form of the word'           => array(
		'Rundreisen'        => $of_kind( 'Rundreise' ),
		'Städtereisen'      => $of_kind( 'Städtereise' ),
		'Safaris'           => $word( 'Safaris?' ),
		'Hotel'             => $word( 'Hotels?' ),
		'Wanderungen'       => $word( 'Wanderung(?:en)?' ),
		'Weingüter'         => $word( 'Weing(?:ut|üter|ütern|utes|uts)' ),
		'Unterkunft'        => $word( 'Unterk(?:unft|ünfte|ünften)' ),
		'Märkte'            => $word( 'M(?:arkt|ärkte|ärkten|arktes)' ),
		'Familienreisen Kuba' => $both( $of_kind( 'Familienreise' ), $to( 'Kuba' ) ),
		'Strände'           => $word( 'Str(?:and|ände|änden|andes)' ),
	),
	'the last part of a compound'        => array(
		'Bus'      => $compound( 'bus(?:se|sen|ses)?' ),
		'Essen'    => $compound( 'essens?' ),
		'Fahrt'    => $compound( 'fahrt(?:en)?' ),
		'Führung'  => $compound( 'führung(?:en)?' ),
		'Probe'    => $compound( 'proben?' ),
		'Wagen'    => $compound( 'wagens?' ),
		'Zimmer'   => $compound( 'zimmer[ns]?' ),
	),
	'the first part of a compound'       => array(
		'Wein'  => $with( '(?<![\p{L}])Wein' ),
		'Bahn'  => $with( '(?<![\p{L}])Bahn' ),
		'Stadt' => $with( '(?<![\p{L}])St(?:adt|ädte)' ),
	),
	'a typing error'                     => array(
		'Itallien'      => $to( 'Italien' ),
		'Portugall'     => $to( 'Portugal' ),
		'Norwegn'       => $to( 'Norwegen' ),
		'Griechnland'   => $to( 'Griechenland' ),
		'Rundriese'     => $of_kind( 'Rundreise' ),
		'Halbpenson'    => $word( 'Halbpension' ),
	),
	'without the umlaut'                 => array(
		'Osterreich'  => $to( 'Österreich' ),
		'Turkei'      => $to( 'Türkei' ),
		'Stadtereise' => $of_kind( 'Städtereise' ),
	),
);

/**
 * Returns the posts the search of WordPress finds, best first.
 *
 * @return int[]
 */
function prstsearch_quality_wordpress( string $query ): array {
	// Not the main query, so the plugin does not answer it.
	$found = new WP_Query(
		array(
			's'                   => $query,
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => PRSTSEARCH_QUALITY_DEPTH,
			'fields'              => 'ids',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);

	return array_map( 'intval', $found->posts );
}

/**
 * Returns the posts the plugin finds, best first.
 *
 * @return int[]
 */
function prstsearch_quality_plugin( string $query ): array {
	$hits = Plugin::instance()->get( SearchService::class )->preview( $query )->ranking->hits;

	return array_slice( array_map( static fn ( SearchHit $hit ): int => $hit->post_id, $hits ), 0, PRSTSEARCH_QUALITY_DEPTH );
}

/**
 * Judges a result list against the wanted posts.
 *
 * @param int[]            $found  Found posts, best first.
 * @param array<int, true> $wanted Wanted posts as keys.
 * @return array{precision: float, recall: float}
 */
function prstsearch_quality_judge( array $found, array $wanted ): array {
	$first   = array_slice( $found, 0, 10 );
	$hits    = count( array_filter( $found, static fn ( int $post_id ): bool => isset( $wanted[ $post_id ] ) ) );
	$on_top  = count( array_filter( $first, static fn ( int $post_id ): bool => isset( $wanted[ $post_id ] ) ) );
	$to_find = min( count( $wanted ), PRSTSEARCH_QUALITY_DEPTH );

	return array(
		// Ten results cannot all be wanted if fewer than ten trips are.
		'precision' => array() === $wanted ? 0.0 : $on_top / min( 10, count( $wanted ) ),
		'recall'    => 0 === $to_find ? 0.0 : min( 1.0, $hits / $to_find ),
	);
}

$engines = array(
	'plugin'    => 'prstsearch_quality_plugin',
	'WordPress' => 'prstsearch_quality_wordpress',
);
$totals  = array();
$mode    = Plugin::instance()->get( PrstSearch\Settings\Settings::class )->string( 'mode' );

printf( "%d trips, search mode of the plugin: %s\n\n", count( $trips ), $mode );
printf( "%-26s %7s | %-27s | %-27s\n", '', '', 'plugin', 'WordPress' );
printf( "%-26s %7s | %8s %9s %8s | %8s %9s %8s\n", 'query', 'wanted', 'found', 'first 10', 'recall', 'found', 'first 10', 'recall' );

foreach ( $queries as $group => $list ) {
	echo "\n", $group, "\n";

	foreach ( $list as $query => $is_wanted ) {
		$wanted = array_fill_keys( array_keys( array_filter( $trips, $is_wanted ) ), true );
		$line   = sprintf( '  %-24s %7d', $query, count( $wanted ) );

		foreach ( $engines as $name => $search ) {
			$found = $search( (string) $query );
			$score = prstsearch_quality_judge( $found, $wanted );

			$totals[ $group ][ $name ]['precision'][] = $score['precision'];
			$totals[ $group ][ $name ]['recall'][]    = $score['recall'];

			$line .= sprintf( ' | %8d %8.0f%% %7.0f%%', count( $found ), 100 * $score['precision'], 100 * $score['recall'] );
		}

		echo $line, "\n";
	}
}

echo "\naverage                            | plugin: first 10, recall    | WordPress: first 10, recall\n";

$all = array();

foreach ( $totals as $group => $by_engine ) {
	$line = sprintf( '  %-32s', $group );

	foreach ( $by_engine as $name => $scores ) {
		$line .= sprintf( ' | %14.0f%% %10.0f%%', 100 * array_sum( $scores['precision'] ) / count( $scores['precision'] ), 100 * array_sum( $scores['recall'] ) / count( $scores['recall'] ) );

		$all[ $name ]['precision'] = array_merge( $all[ $name ]['precision'] ?? array(), $scores['precision'] );
		$all[ $name ]['recall']    = array_merge( $all[ $name ]['recall'] ?? array(), $scores['recall'] );
	}

	echo $line, "\n";
}

$line = sprintf( '  %-32s', 'all queries' );

foreach ( $all as $scores ) {
	$line .= sprintf( ' | %14.0f%% %10.0f%%', 100 * array_sum( $scores['precision'] ) / count( $scores['precision'] ), 100 * array_sum( $scores['recall'] ) / count( $scores['recall'] ) );
}

echo $line, "\n";
