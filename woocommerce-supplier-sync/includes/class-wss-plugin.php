<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WSS_Plugin {

    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );
            return;
        }

        WSS_Admin::init();
        WSS_Sync_Engine::init();
    }

    public static function activate() {
        if ( ! wp_next_scheduled( 'wss_daily_sync_event' ) ) {
            wp_schedule_single_event( WSS_Sync_Engine::next_scheduled_timestamp(), 'wss_daily_sync_event' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'wss_daily_sync_event' );
    }

    public static function woocommerce_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__( 'WooCommerce Supplier Sync requires WooCommerce to be active.', 'wss' );
        echo '</p></div>';
    }
}
