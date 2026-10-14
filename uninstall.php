<?php
/**
 * Runs when the plugin is deleted.
 *
 * Secrets and caches are always removed. Index, statistics and settings are
 * only removed if the site owner asked for it in the settings.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

PrstSearch\Autoloader::register( 'PrstSearch\\', __DIR__ . '/src/' );

/**
 * Cleans up the site that is currently active.
 */
function prstsearch_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( PrstSearch\Settings\Settings::OPTION, array() );

	// An API key has no business outliving the plugin that used it.
	delete_option( PrstSearch\Security\SecretStore::OPTION );

	// Transients, locks and counters are disposable in any case.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of the plugin's own rows.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_' . PrstSearch\Support\Cache::PREFIX ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . PrstSearch\Support\Cache::PREFIX ) . '%',
			$wpdb->esc_like( 'prstsearch_lock_' ) . '%',
			$wpdb->esc_like( 'prstsearch_count_' ) . '%'
		)
	);

	( new PrstSearch\Index\Scheduler() )->clear();

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	( new PrstSearch\Database\Installer( $wpdb ) )->drop();

	$options = array(
		PrstSearch\Settings\Settings::OPTION,
		PrstSearch\Provider\UsageLedger::OPTION,
		PrstSearch\Index\IndexVersion::OPTION,
		PrstSearch\Index\BackgroundJobs::BACKFILL_CURSOR_OPTION,
		PrstSearch\Index\BackgroundJobs::PRUNE_CURSOR_OPTION,
		PrstSearch\Search\Recommendations::OPTION,
	);

	array_map( 'delete_option', $options );

	$meta_keys = array(
		PrstSearch\Index\IndexStatusStore::STATE_META,
		PrstSearch\Index\IndexStatusStore::INDEXED_AT_META,
		PrstSearch\Index\IndexStatusStore::ERROR_META,
		PrstSearch\Index\Eligibility::EXCLUDE_META,
	);

	array_map( 'delete_post_meta_by_key', $meta_keys );
}

if ( is_multisite() ) {
	// Every site of the network has its own tables, options and settings.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			// The default is the first hundred.
			'number' => 0,
		)
	) as $prstsearch_site_id ) {
		switch_to_blog( (int) $prstsearch_site_id );
		prstsearch_uninstall_site();
		restore_current_blog();
	}
} else {
	prstsearch_uninstall_site();
}
