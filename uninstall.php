<?php
/**
 * Remove plugin data on uninstall.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
delete_option( 'dgwc_settings' );
delete_option( 'dgwc_leads' );
delete_option( 'dgwc_clicks_db' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dgwc_clicks" ); // phpcs:ignore WordPress.DB
