<?php
/**
 * Bootstrap of the integration tests. They run inside a WordPress installation.
 *
 * Without WP_LOAD_PATH nothing is loaded and every test skips itself.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$prstsearch_wp_load = getenv( 'WP_LOAD_PATH' );

if ( is_string( $prstsearch_wp_load ) && is_file( $prstsearch_wp_load ) ) {
	// WordPress needs these to build its URLs when it is not started by a web server.
	$_SERVER['HTTP_HOST']   ??= 'localhost';
	$_SERVER['REQUEST_URI'] ??= '/';

	/*
	 * WordPress is only needed for its database connection. The plugins of the
	 * site stay switched off for this run: they are not under test, and one that
	 * ships its own copy of PHPUnit would get in the way of the one running here.
	 * Hooks set before WordPress loads are picked up by it; this is how its own
	 * test suite does it.
	 */
	$GLOBALS['wp_filter'] = array(
		'option_active_plugins' => array(
			10 => array(
				array(
					'function'      => static fn (): array => array(),
					'accepted_args' => 1,
				),
			),
		),
	);

	require_once $prstsearch_wp_load;
}
