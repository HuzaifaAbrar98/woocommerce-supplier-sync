<?php
/**
 * Plugin Name: WooCommerce Supplier Sync
 * Description: Server-side FTP supplier CSV download and WooCommerce price/stock synchronization.
 * Version: 0.9.3
 * Author: Custom Development
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WSS_VERSION', '0.9.3' );
define( 'WSS_PLUGIN_FILE', __FILE__ );
define( 'WSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WSS_PLUGIN_DIR . 'includes/class-wss-csv-processor.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-sync-engine.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-admin.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-plugin.php';

register_activation_hook( __FILE__, array( 'WSS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WSS_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WSS_Plugin', 'init' ) );
