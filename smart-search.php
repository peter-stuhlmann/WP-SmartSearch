<?php
/**
 * Plugin Name:       Smart Search
 * Description:       Replaces the WordPress search with a fast keyword index, optional semantic search and AI answers.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Peter R. Stuhlmann
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-search
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'PRSTSEARCH_VERSION', '1.0.0' );
define( 'PRSTSEARCH_FILE', __FILE__ );
define( 'PRSTSEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRSTSEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once PRSTSEARCH_DIR . 'src/Autoloader.php';

PrstSearch\Autoloader::register( 'PrstSearch\\', PRSTSEARCH_DIR . 'src/' );

require_once PRSTSEARCH_DIR . 'src/template-tags.php';

register_activation_hook( __FILE__, array( PrstSearch\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( PrstSearch\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( PrstSearch\Plugin::class, 'boot' ) );
