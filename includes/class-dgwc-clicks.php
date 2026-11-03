<?php
/**
 * WhatsApp button click references.
 *
 * When a visitor lands on a page whose URL carries a gclid, the page asks the server for a
 * reference code (e.g. REF-KM3P). The server picks it at random and reserves it in the table,
 * so a code is never handed out twice. When the visitor clicks a WhatsApp button, the code is
 * added to the prefilled message and the reserved row becomes a click row:
 * Code | GCLID | Click time | Button | Page. A new code is reserved for the next click.
 *
 * Pages without a gclid in their URL get no code: the prefilled text is sent as it is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Clicks {

	const DB_VERSION   = '4';
	const DB_OPTION    = 'dgwc_clicks_db';
	const AJAX         = 'dgwc_wa_click';
	const AJAX_RESERVE = 'dgwc_wa_reserve';
	const CHARS        = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	const CODE_LEN     = 4;
	const RATE_LIMIT   = 60;  // requests…
	const RATE_SPAN    = 600; // …per 10 minutes per visitor IP.
	const RESERVE_TTL  = 172800; // Unused reserved codes are released after 2 days.

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		foreach ( array( self::AJAX => 'ajax_click', self::AJAX_RESERVE => 'ajax_reserve' ) as $action => $cb ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $cb ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $cb ) );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dgwc_clicks';
	}

	/**
	 * Create or upgrade the table. Runs on activation and after a plugin update
	 * (uploading a new zip does not fire the activation hook).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'clicked',
			sheet_status varchar(10) NOT NULL DEFAULT '',
			sheet_detail varchar(200) NOT NULL DEFAULT '',
			gclid varchar(300) NOT NULL DEFAULT '',
			gbraid varchar(300) NOT NULL DEFAULT '',
			wbraid varchar(300) NOT NULL DEFAULT '',
			fbclid varchar(300) NOT NULL DEFAULT '',
			utm_source varchar(191) NOT NULL DEFAULT '',
			utm_medium varchar(191) NOT NULL DEFAULT '',
			utm_campaign varchar(191) NOT NULL DEFAULT '',
			utm_term varchar(191) NOT NULL DEFAULT '',
			utm_content varchar(191) NOT NULL DEFAULT '',
			button varchar(191) NOT NULL DEFAULT '',
			wa_number varchar(32) NOT NULL DEFAULT '',
			page_url varchar(500) NOT NULL DEFAULT '',
			page_title varchar(200) NOT NULL DEFAULT '',
			referrer varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY gclid (gclid(64)),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	private static function json_payload() {
		if ( ! isset( $_POST['payload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- public, cache-safe; rate limited.
			return array();
		}
		$decoded = json_decode( wp_unslash( $_POST['payload'] ), true ); // phpcs:ignore
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function str( $p, $key, $max ) {
		if ( ! isset( $p[ $key ] ) || is_array( $p[ $key ] ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( (string) $p[ $key ] ), 0, $max );
	}

	private static function url( $p, $key ) {
		if ( ! isset( $p[ $key ] ) || is_array( $p[ $key ] ) ) {
			return '';
		}
		return mb_substr( esc_url_raw( (string) $p[ $key ], array( 'http', 'https' ) ), 0, 500 );
	}

	/**
	 * A reference code: PREFIX-XXXX, capital letters and digits only.
	 */
	public static function code_pattern() {
		return '/^[A-Z0-9]{1,10}-[A-Z0-9]{' . self::CODE_LEN . '}$/';
	}

	private static function random_code( $prefix ) {
		$out = '';
		$max = strlen( self::CHARS ) - 1;
		for ( $i = 0; $i < self::CODE_LEN; $i++ ) {
			$out .= self::CHARS[ random_int( 0, $max ) ];
		}
		return $prefix . '-' . $out;
	}

	/**
	 * Rate limit on the real connection IP only (headers like X-Real-IP can be faked).
	 */
	private static function rate_ok() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'dgwc_rc_' . md5( $ip . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_SPAN );
		return true;
	}

	private static function out( $status, $body ) {
		return array( 'status' => $status, 'body' => $body );
	}

	/* ------------------------------------------------------------------ */
	/* Reserve a code (page load with a gclid, and after each click)        */
	/* ------------------------------------------------------------------ */

	public static function ajax_reserve() {
		$res = self::reserve( self::json_payload() );
		wp_send_json( $res['body'], $res['status'] );
	}

	public static function reserve( array $p ) {
		global $wpdb;
		$s = DGWC_Settings::get();
		if ( empty( $s['ref_enabled'] ) ) {
			return self::out( 403, array( 'ok' => false, 'code' => 'disabled' ) );
		}
		$gclid = self::str( $p, 'gclid', 300 );
		if ( '' === $gclid ) {
			return self::out( 400, array( 'ok' => false, 'code' => 'no_gclid' ) );
		}
		if ( ! self::rate_ok() ) {
			return self::out( 429, array( 'ok' => false, 'code' => 'rate_limited' ) );
		}

		// Now and then, release codes that were reserved but never clicked.
		if ( 1 === random_int( 1, 25 ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status = 'reserved' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::RESERVE_TTL ) ) ); // phpcs:ignore WordPress.DB
		}

		for ( $try = 0; $try < 25; $try++ ) {
			$code = self::random_code( $s['ref_prefix'] );
			$ok   = $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::table() . " (code, status, gclid, created_at) VALUES (%s, 'reserved', %s, %s)", // phpcs:ignore WordPress.DB
					$code,
					$gclid,
					current_time( 'mysql', true )
				)
			);
			if ( 1 === (int) $ok ) {
				return self::out( 200, array( 'ok' => true, 'ref' => $code ) );
			}
		}
		return self::out( 503, array( 'ok' => false, 'code' => 'no_code' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Log a click                                                          */
	/* ------------------------------------------------------------------ */

	public static function ajax_click() {
		$res = self::handle( self::json_payload() );
		wp_send_json( $res['body'], $res['status'] );
	}

	public static function handle( array $p ) {
		global $wpdb;
		$s = DGWC_Settings::get();
		if ( empty( $s['ref_enabled'] ) ) {
			return self::out( 403, array( 'ok' => false, 'code' => 'disabled' ) );
		}
		$code = self::str( $p, 'code', 32 );
		if ( ! preg_match( self::code_pattern(), $code ) ) {
			return self::out( 400, array( 'ok' => false, 'code' => 'bad_code' ) );
		}
		$gclid = self::str( $p, 'gclid', 300 );
		if ( '' === $gclid ) {
			return self::out( 400, array( 'ok' => false, 'code' => 'no_gclid' ) );
		}
		if ( ! self::rate_ok() ) {
			return self::out( 429, array( 'ok' => false, 'code' => 'rate_limited' ) );
		}

		$row = array(
			'status'       => 'clicked',
			'gclid'        => $gclid,
			'gbraid'       => self::str( $p, 'gbraid', 300 ),
			'wbraid'       => self::str( $p, 'wbraid', 300 ),
			'fbclid'       => self::str( $p, 'fbclid', 300 ),
			'utm_source'   => self::str( $p, 'utm_source', 191 ),
			'utm_medium'   => self::str( $p, 'utm_medium', 191 ),
			'utm_campaign' => self::str( $p, 'utm_campaign', 191 ),
			'utm_term'     => self::str( $p, 'utm_term', 191 ),
			'utm_content'  => self::str( $p, 'utm_content', 191 ),
			'button'       => self::str( $p, 'button', 191 ),
			'wa_number'    => preg_replace( '/\D/', '', self::str( $p, 'wa_number', 32 ) ),
			'page_url'     => self::url( $p, 'page_url' ),
			'page_title'   => self::str( $p, 'page_title', 200 ),
			'referrer'     => self::url( $p, 'referrer' ),
			'created_at'   => current_time( 'mysql', true ),
		);

		/**
		 * Filter a WhatsApp click row before it is stored.
		 */
		$row = apply_filters( 'dgwc_wa_click_row', $row, $p );

		// Normal case: the code was reserved for this visitor → turn the reservation into the click row.
		$updated = $wpdb->update( self::table(), $row, array( 'code' => $code, 'status' => 'reserved' ) ); // phpcs:ignore WordPress.DB
		if ( ! $updated ) {
			// Code made in the browser (the reservation had not arrived yet). INSERT IGNORE: a code
			// already used by an earlier click is never overwritten.
			$row  = array_merge( array( 'code' => $code ), $row );
			$cols = array_keys( $row );
			$sql  = 'INSERT IGNORE INTO ' . self::table() . ' (`' . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $cols ), '%s' ) ) . ')';
			$is_new = 1 === (int) $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) ); // phpcs:ignore WordPress.DB
		} else {
			$is_new = true;
			$row    = array_merge( array( 'code' => $code ), $row );
		}

		// Same click → one row in the Google Sheet (GCLID, Ref Code, UTC, Pk Time).
		if ( $is_new ) {
			self::send_sheet( $row );
		}

		do_action( 'dgwc_wa_click', $row );

		return self::out( 200, array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Google Sheet                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * 12-hour clock times for a stored GMT datetime: e.g. "19 Oct 2026, 1:05 PM".
	 *
	 * @return array{utc:string,pk:string}
	 */
	public static function sheet_times( $gmt ) {
		$ts  = self::ts( $gmt );
		$fmt = 'j M Y, g:i A';
		$pk  = new DateTime( '@' . $ts );
		$pk->setTimezone( new DateTimeZone( 'Asia/Karachi' ) );
		return array(
			'utc' => gmdate( $fmt, $ts ),
			'pk'  => $pk->format( $fmt ),
		);
	}

	/**
	 * Send one click to the Google Sheet and remember the result on the row.
	 */
	public static function send_sheet( $row ) {
		global $wpdb;
		$s = DGWC_Settings::get();
		if ( empty( $s['sheet_url'] ) || empty( $row['code'] ) ) {
			return null;
		}
		$t   = self::sheet_times( $row['created_at'] );
		$res = self::post_sheet(
			$s['sheet_url'],
			array(
				'secret'   => DGWC_Settings::sheet_secret(),
				'gclid'    => (string) $row['gclid'],
				'ref_code' => (string) $row['code'],
				'utc'      => $t['utc'],
				'pk_time'  => $t['pk'],
			)
		);
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'sheet_status' => $res['ok'] ? 'sent' : 'failed',
				'sheet_detail' => mb_substr( $res['detail'], 0, 200 ),
			),
			array( 'code' => $row['code'] )
		);
		return $res;
	}

	/**
	 * POST to the Google Apps Script web app. Google answers with a redirect to the result, which is followed.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function post_sheet( $url, $data ) {
		// Google runs the script on this POST, then answers "302 Found" with the result at another URL.
		// That URL must be read with GET; WordPress would repeat the POST there and get an error,
		// so the redirect is followed here by hand.
		$resp = wp_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode( $data ),
				'data_format' => 'body',
			)
		);
		for ( $hop = 0; $hop < 3 && ! is_wp_error( $resp ); $hop++ ) {
			$status   = (int) wp_remote_retrieve_response_code( $resp );
			$location = (string) wp_remote_retrieve_header( $resp, 'location' );
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				break;
			}
			$resp = wp_remote_get( $location, array( 'timeout' => 20, 'redirection' => 0 ) );
		}
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'detail' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		$json = json_decode( $body, true );
		if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
			if ( ! empty( $json['ok'] ) ) {
				return array( 'ok' => true, 'detail' => ! empty( $json['duplicate'] ) ? 'Already in sheet' : ( isset( $json['row'] ) ? 'Row ' . (int) $json['row'] : 'Sent' ) );
			}
			return array( 'ok' => false, 'detail' => isset( $json['error'] ) ? (string) $json['error'] : 'Sheet refused the row' );
		}
		if ( $code >= 200 && $code < 300 && false !== stripos( $body, '<html' ) ) {
			return array( 'ok' => false, 'detail' => 'Google returned a web page, not the script. Check the web app access is set to "Anyone".' );
		}
		return array( 'ok' => false, 'detail' => 'HTTP ' . $code . ' ' . mb_substr( wp_strip_all_tags( $body ), 0, 120 ) );
	}

	/**
	 * Re-send clicks that did not reach the sheet (or were made before the sheet was connected).
	 *
	 * @return array{sent:int,failed:int}
	 */
	public static function resend_unsent( $limit = 200 ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'clicked' AND sheet_status <> 'sent' AND gclid <> '' ORDER BY created_at ASC LIMIT %d", (int) $limit ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		$out = array( 'sent' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $r ) {
			$res = self::send_sheet( $r );
			if ( $res && $res['ok'] ) {
				$out['sent']++;
			} else {
				$out['failed']++;
			}
		}
		return $out;
	}

	public static function unsent_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'clicked' AND sheet_status <> 'sent' AND gclid <> ''" ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------------ */
	/* Reading (clicked rows only)                                          */
	/* ------------------------------------------------------------------ */

	private static function where( $search ) {
		global $wpdb;
		$where  = " WHERE status <> 'reserved'";
		$search = trim( (string) $search );
		if ( '' === $search ) {
			return $where;
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return $where . $wpdb->prepare( ' AND (code LIKE %s OR gclid LIKE %s OR gbraid LIKE %s OR wbraid LIKE %s)', $like, $like, $like, $like );
	}

	public static function count( $search = '' ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . self::where( $search ) ); // phpcs:ignore WordPress.DB
	}

	public static function recent( $limit = 200, $offset = 0, $search = '' ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . self::where( $search ) . $wpdb->prepare( ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', (int) $limit, (int) $offset );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete the click log. Codes reserved on pages that are open right now are kept.
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() . " WHERE status <> 'reserved'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Unix time for a stored GMT datetime.
	 */
	public static function ts( $gmt ) {
		$t = strtotime( $gmt . ' UTC' );
		return $t ? $t : 0;
	}
}
