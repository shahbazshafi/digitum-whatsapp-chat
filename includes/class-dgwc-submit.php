<?php
/**
 * Receives the built-in chat form and forwards it to the GHL inbound webhook server-side.
 * Exposed both as a REST route and as an admin-ajax action (fallback for sites that lock down REST).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Submit {

	const REST_NS    = 'digitum-chat/v1';
	const AJAX       = 'dgwc_submit';
	const RATE_LIMIT = 6;   // submissions…
	const RATE_SPAN  = 600; // …per 10 minutes per visitor IP.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX, array( __CLASS__, 'ajax' ) );
	}

	public static function routes() {
		register_rest_route(
			self::REST_NS,
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $req->get_body_params();
		}
		$res = self::handle( is_array( $params ) ? $params : array() );
		return new WP_REST_Response( $res['body'], $res['status'] );
	}

	public static function ajax() {
		$params = array();
		if ( isset( $_POST['payload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- public lead form; protected by honeypot, timing and rate limit.
			$decoded = json_decode( wp_unslash( $_POST['payload'] ), true ); // phpcs:ignore
			$params  = is_array( $decoded ) ? $decoded : array();
		}
		$res = self::handle( $params );
		wp_send_json( $res['body'], $res['status'] );
	}

	private static function str( $p, $key, $max = 300 ) {
		if ( ! isset( $p[ $key ] ) || is_array( $p[ $key ] ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( (string) $p[ $key ] ), 0, $max );
	}

	/**
	 * Normalise a phone to E.164. Accepts a dial code plus local number, or a full
	 * international number typed straight into the field (+44…, 0044…).
	 */
	public static function to_e164( $cc, $raw ) {
		$raw = trim( (string) $raw );
		$cc  = preg_replace( '/\D/', '', (string) $cc );
		$dig = preg_replace( '/\D/', '', $raw );
		if ( '' === $dig ) {
			return '';
		}
		if ( 0 === strpos( $raw, '+' ) ) {
			$full = $dig;
		} elseif ( 0 === strpos( $dig, '00' ) ) {
			$full = substr( $dig, 2 );
		} else {
			$local = ltrim( $dig, '0' );
			// Visitor already typed the country code without "+", e.g. 971501234567 with +971 selected.
			if ( $cc && 0 === strpos( $local, $cc ) && strlen( $local ) >= 11 && strlen( $local ) - strlen( $cc ) >= 8 ) {
				$full = $local;
			} else {
				$full = $cc . $local;
			}
		}
		$len = strlen( $full );
		if ( $len < 8 || $len > 15 ) {
			return '';
		}
		return '+' . $full;
	}

	private static function fail( $code, $message, $status = 400 ) {
		return array(
			'status' => $status,
			'body'   => array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
				break;
			}
		}
		return $ip;
	}

	public static function handle( array $p ) {
		$s = DGWC_Settings::get();

		if ( empty( $s['enabled'] ) || 'builtin' !== $s['form_source'] ) {
			return self::fail( 'disabled', 'This form is not active.', 403 );
		}

		// Honeypot: real visitors never see or fill this field.
		if ( '' !== self::str( $p, 'website' ) ) {
			return array(
				'status' => 200,
				'body'   => array( 'ok' => true ),
			);
		}
		// Timing trap: a human needs at least a second or two to type a name and number.
		$elapsed = isset( $p['elapsed'] ) ? (int) $p['elapsed'] : 0;
		if ( $elapsed > 0 && $elapsed < 1200 ) {
			return self::fail( 'too_fast', 'Please try again.' );
		}

		$ip_key = 'dgwc_rl_' . md5( self::client_ip() . wp_salt( 'nonce' ) );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= self::RATE_LIMIT ) {
			return self::fail( 'rate_limited', 'Too many attempts. Please message us on WhatsApp directly.', 429 );
		}

		$name = trim( preg_replace( '/\s+/', ' ', self::str( $p, 'full_name', 80 ) ) );
		if ( mb_strlen( $name ) < 2 ) {
			return self::fail( 'name', 'Please add your name.' );
		}
		$cc = self::str( $p, 'country_code', 6 );
		if ( ! preg_match( '/^\+\d{1,4}$/', $cc ) ) {
			$cc = $s['default_cc'];
		}
		$phone = self::to_e164( $cc, self::str( $p, 'phone', 30 ) );
		if ( '' === $phone ) {
			return self::fail( 'phone', 'Please add a valid WhatsApp number.' );
		}
		// If the visitor typed a full international number, report the dial code it actually starts with.
		if ( 0 !== strpos( $phone, '+' . ltrim( $cc, '+' ) ) ) {
			$codes = array_keys( DGWC_Settings::country_codes() );
			usort(
				$codes,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			foreach ( $codes as $code ) {
				if ( 0 === strpos( $phone, $code ) ) {
					$cc = $code;
					break;
				}
			}
		}
		$consent = ! empty( $p['consent'] );
		if ( ! $consent ) {
			return self::fail( 'consent', 'Please tick the box so we can message you.' );
		}

		set_transient( $ip_key, $count + 1, self::RATE_SPAN );

		$parts = explode( ' ', $name, 2 );
		$attr  = array();
		foreach ( array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $k ) {
			$attr[ $k ] = self::str( $p, $k, 300 );
		}

		$page_url = isset( $p['page_url'] ) ? esc_url_raw( (string) $p['page_url'] ) : '';
		$payload  = array_merge(
			array(
				'full_name'    => $name,
				'first_name'   => $parts[0],
				'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
				'phone'        => $phone,
				'country_code' => $cc,
				'consent'      => 'Yes',
				'consent_text' => $s['consent_text'],
				'page_url'     => $page_url,
				'page_title'   => self::str( $p, 'page_title', 200 ),
				'referrer'     => isset( $p['referrer'] ) ? esc_url_raw( (string) $p['referrer'] ) : '',
				'source'       => 'Digitum WhatsApp Chat',
				'website'      => home_url( '/' ),
				'submitted_at' => gmdate( 'c' ),
			),
			$attr
		);
		// Also send the click ID under the custom GHL query key when it differs from "gclid".
		if ( 'gclid' !== $s['gclid_key'] && '' !== $attr['gclid'] ) {
			$payload[ $s['gclid_key'] ] = $attr['gclid'];
		}

		/**
		 * Filter the data sent to the GHL webhook.
		 */
		$payload = apply_filters( 'dgwc_webhook_payload', $payload, $p );

		$hook = self::send_webhook( $s['webhook_url'], $payload );

		DGWC_Leads::add(
			array(
				'time'    => time(),
				'name'    => $name,
				'phone'   => $phone,
				'page'    => $page_url,
				'gclid'   => $attr['gclid'] ? $attr['gclid'] : ( $attr['gbraid'] ? 'gbraid:' . $attr['gbraid'] : ( $attr['wbraid'] ? 'wbraid:' . $attr['wbraid'] : '' ) ),
				'utm'     => $attr['utm_source'] ? trim( $attr['utm_source'] . ' / ' . $attr['utm_medium'] . ' / ' . $attr['utm_campaign'], ' /' ) : '',
				'status'  => $hook['ok'] ? 'sent' : 'failed',
				'detail'  => $hook['detail'],
			)
		);

		do_action( 'dgwc_lead_submitted', $payload, $hook );

		// The lead is stored either way, so the visitor always gets a success and can continue to WhatsApp.
		return array(
			'status' => 200,
			'body'   => array(
				'ok'      => true,
				'phone'   => $phone,
				'webhook' => $hook['ok'],
			),
		);
	}

	/**
	 * POST JSON to the GHL inbound webhook.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function send_webhook( $url, $payload ) {
		if ( empty( $url ) ) {
			return array(
				'ok'     => false,
				'detail' => 'No webhook URL set',
			);
		}
		$resp = wp_remote_post(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 2,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'     => false,
				'detail' => mb_substr( $resp->get_error_message(), 0, 200 ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$ok   = $code >= 200 && $code < 300;
		return array(
			'ok'     => $ok,
			'detail' => 'HTTP ' . $code . ( $ok ? '' : ' ' . mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $resp ) ), 0, 160 ) ),
		);
	}
}
