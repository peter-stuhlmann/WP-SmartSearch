<?php
/**
 * Measures how long searches take on the installation it is pointed at.
 *
 * Runs each query through the keyword search, as a visitor's request would,
 * and prints time, number of results and memory. Nothing is logged or changed.
 *
 * Usage: WP_LOAD_PATH=/path/to/wp-load.php php tests/benchmark/measure.php ["query" ...]
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
use PrstSearch\Search\KeywordSearcher;
use PrstSearch\Search\QueryTerms;
use PrstSearch\Search\SearchService;
use PrstSearch\Search\Vocabulary;

global $wpdb;

$plugin  = Plugin::instance();
$queries = array_slice( $argv, 1 );
$queries = array() !== $queries ? $queries : array(
	'Reise',
	'Italien',
	'Rundreise Italien',
	'Wanderreise Dolomiten Hütte',
	'Tag',
	'Tür',
	'Essen',
	'Bus',
	'Ei',
	'Kosten',
	'"Halong-Bucht"',
	'Vietnam -Rundreise',
	'Itallien',
	'Safarie Tansanya',
	'afar',
	'qwertzuiop',
	'qwertz uiopü asdfg hjklö yxcvb',
	'Welche Reise nach Japan dauert zwei Wochen und kostet wenig?',
);

$chunks = $wpdb->prefix . 'prstsearch_chunks';

printf(
	"posts in the index: %d | passages: %d | table: %.1f MB | max results: %d\n\n",
	$wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM $chunks" ),
	$wpdb->get_var( "SELECT COUNT(*) FROM $chunks" ),
	$wpdb->get_var( $wpdb->prepare( 'SELECT (DATA_LENGTH + INDEX_LENGTH) / 1048576 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $chunks ) ),
	$plugin->get( SearchService::class )->max_hits()
);

$started = microtime( true );
$words   = count( $plugin->get( Vocabulary::class )->words() );

printf( "vocabulary: %d words, read in %d ms\n\n", $words, 1000 * ( microtime( true ) - $started ) );
printf( "%-62s %8s %8s %8s %8s\n", 'query', 'keyword', 'whole', 'results', 'queries' );

foreach ( $queries as $query ) {
	$before  = $wpdb->num_queries;
	$started = microtime( true );
	$matches = $plugin->get( KeywordSearcher::class )->search( QueryTerms::from_phrase( $query ), $plugin->get( SearchService::class )->max_hits() );
	$keyword = 1000 * ( microtime( true ) - $started );

	// The whole search: with the spelling correction and everything else a request does.
	$started = microtime( true );
	$results = $plugin->get( SearchService::class )->preview( $query );
	$whole   = 1000 * ( microtime( true ) - $started );

	printf( "%-62s %5d ms %5d ms %8d %8d   %s\n", mb_strimwidth( $query, 0, 60, '…' ), $keyword, $whole, $results->total, $wpdb->num_queries - $before, '' === $results->ranking->corrected_query ? '' : '→ ' . $results->ranking->corrected_query );
	unset( $matches, $results );
}

printf( "\npeak memory: %.0f MB\n", memory_get_peak_usage( true ) / 1048576 );
