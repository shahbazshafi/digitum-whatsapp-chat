<?php
/**
 * Updates from GitHub.
 *
 * Every release is published on the public releases repo with a digitum-whatsapp-chat.zip asset.
 * WordPress asks this class about updates because of the "Update URI" header in the main file,
 * so the plugin shows up in Dashboard > Updates and can be auto-updated like any other plugin.
 * The source code repo stays private: client sites only read the public releases.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Updater {

	const REPO      = 'shahbazshafi/digitum-whatsapp-chat-releases';
	const SLUG      = 'digitum-whatsapp-chat';
	const ASSET     = 'digitum-whatsapp-chat.zip';
	const TRANSIENT = 'dgwc_update_release';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Latest release: version, package, notes, url, date. Null when GitHub can't be reached.
	 * Cached for 6 hours (1 hour after a failed lookup); "Check again" in Dashboard > Updates skips the cache.
	 */
	public static function release() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache bust.
		if ( is_admin() && ! empty( $_GET['force-check'] ) ) {
			self::flush();
		}
		$r = get_site_transient( self::TRANSIENT );
		if ( is_array( $r ) ) {
			return $r ? $r : null;
		}

		$r   = array();
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) && ! empty( $data['assets'] ) ) {
				foreach ( (array) $data['assets'] as $a ) {
					if ( isset( $a['name'], $a['browser_download_url'] ) && self::ASSET === $a['name'] ) {
						$r = array(
							'version' => ltrim( (string) $data['tag_name'], 'vV' ),
							'package' => (string) $a['browser_download_url'],
							'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
							'url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
							'date'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
						);
						break;
					}
				}
			}
		}

		set_site_transient( self::TRANSIENT, $r, $r ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $r ? $r : null;
	}

	/**
	 * Answers WordPress's update check for this plugin. WordPress compares the versions itself.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( DGWC_FILE ) !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( ! $r ) {
			return $update;
		}
		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => self::SLUG,
			'version'      => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'requires_php' => $plugin_data['RequiresPHP'],
		);
	}

	/**
	 * The "View details" popup in Plugins and Dashboard > Updates.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( ! $r ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Digitum GHL WhatsApp Popup',
			'slug'          => self::SLUG,
			'version'       => $r['version'],
			'author'        => 'Digitum Marketing',
			'homepage'      => $r['url'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => $r['date'],
			'download_link' => $r['package'],
			'sections'      => array(
				'changelog' => '' !== $r['notes'] ? wpautop( esc_html( $r['notes'] ) ) : esc_html__( 'See the release page on GitHub.', 'digitum-whatsapp-chat' ),
			),
		);
	}
}
