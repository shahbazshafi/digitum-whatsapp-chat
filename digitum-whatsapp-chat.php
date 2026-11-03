<?php
/**
 * Plugin Name:       Digitum GHL WhatsApp Popup
 * Description:       Floating WhatsApp-style chat button with a compact chat card that sends leads to GoHighLevel and fires the Google Ads conversion on a real form submission.
 * Version:           1.9.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digitum Marketing
 * License:           GPL-2.0-or-later
 * Text Domain:       digitum-whatsapp-chat
 * Update URI:        https://github.com/shahbazshafi/digitum-whatsapp-chat-releases
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DGWC_VERSION', '1.9.0' );
define( 'DGWC_FILE', __FILE__ );
define( 'DGWC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGWC_URL', plugin_dir_url( __FILE__ ) );

require_once DGWC_DIR . 'includes/class-dgwc-settings.php';
require_once DGWC_DIR . 'includes/class-dgwc-leads.php';
require_once DGWC_DIR . 'includes/class-dgwc-clicks.php';
require_once DGWC_DIR . 'includes/class-dgwc-submit.php';
require_once DGWC_DIR . 'includes/class-dgwc-frontend.php';
require_once DGWC_DIR . 'includes/class-dgwc-admin.php';
require_once DGWC_DIR . 'includes/class-dgwc-updater.php';

DGWC_Submit::init();
DGWC_Clicks::init();
DGWC_Frontend::init();
DGWC_Updater::init();
if ( is_admin() ) {
	DGWC_Admin::init();
}

register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( DGWC_Settings::OPTION, false ) ) {
			add_option( DGWC_Settings::OPTION, DGWC_Settings::defaults() );
		}
		if ( false === get_option( DGWC_Leads::OPTION, false ) ) {
			add_option( DGWC_Leads::OPTION, array(), '', 'no' );
		}
		DGWC_Clicks::install();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = DGWC_Admin::page_url();
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'digitum-whatsapp-chat' ) . '</a>' );
		return $links;
	}
);
