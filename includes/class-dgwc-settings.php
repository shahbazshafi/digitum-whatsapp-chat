<?php
/**
 * Settings: defaults, reading and sanitising.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Settings {

	const OPTION = 'dgwc_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			// General.
			'enabled'         => 1,
			'visibility'      => 'all',
			'page_ids'        => array(),
			'position'        => 'right',
			'pulse'           => 1,
			'badge'           => 1,
			'teaser'          => 1,
			'teaser_text'     => 'Hi there! Need a quote? Get today\'s prices on WhatsApp.',
			'teaser_delay'    => 4,

			// Form & GHL.
			'form_source'     => 'builtin',
			'webhook_url'     => '',
			'default_cc'      => '+971',
			'cc_auto'         => 1,
			'button_text'     => 'Send message',
			'consent_text'    => 'I agree to be contacted by WhatsApp',
			'consent_default' => 1,
			'after_submit'    => 'wa',
			'wa_number'       => '',
			'wa_text'         => 'Hi, I\'m {name}. I\'d like to know more.',
			'wa_reply'        => 'Thanks {name}! Tap below to continue on WhatsApp.',
			'thanks_text'     => 'Thanks {name}! We\'ll message you on WhatsApp in a few minutes.',
			'embed'           => '',
			'iframe_height'   => 365,
			'iframe_trim'     => 90,

			// Appearance.
			'business_name'   => '',
			'heading'         => 'Online · replies in a few minutes',
			'welcome'         => 'Hi there 👋 Share your name and WhatsApp number and we\'ll get back to you on WhatsApp.',
			'reply_notice'    => 'Typically replies within 5 minutes',
			'verified'        => 1,
			'powered_by'      => 'Digitum',
			'powered_url'     => '',
			'avatar_id'       => 0,
			'bg'              => 'doodle',
			'bg_id'           => 0,
			'theme'           => 'light',

			// Tracking.
			'gclid_key'       => 'gclid',
			'conv_enabled'    => 1,
			'conv_trigger'    => 'submit',
			'conv_id'         => '',
			'conv_label'      => '',
			'enhanced'        => 1,

			// WhatsApp button references.
			'ref_enabled'     => 1,
			'ref_prefix'      => 'REF',
			'ref_label'       => 'Ref:',
			'ref_text'        => 'Hi! I\'d like to know more.',

			// Google Sheet.
			'sheet_url'       => '',
			'sheet_secret'    => '',
		);
	}

	public static function get() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Google Apps Script web app URL (…/macros/s/…/exec).
	 */
	public static function valid_sheet_url( $url ) {
		return (bool) preg_match( '#^https://script\.google(usercontent)?\.com/(a/[^/]+/)?macros/s/[A-Za-z0-9_\-]+/exec/?$#', (string) $url );
	}

	/**
	 * Shared secret between the plugin and the sheet script. Created once, then kept.
	 */
	public static function sheet_secret() {
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && ! empty( $saved['sheet_secret'] ) ) {
			return $saved['sheet_secret'];
		}
		$secret = wp_generate_password( 24, false, false );
		if ( is_array( $saved ) ) {
			$saved['sheet_secret'] = $secret;
			update_option( self::OPTION, $saved );
		} else {
			add_option( self::OPTION, array_merge( self::defaults(), array( 'sheet_secret' => $secret ) ) );
		}
		self::flush();
		return $secret;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Country dial codes offered in the chat form.
	 */
	public static function country_codes() {
		return array(
			'+971' => 'AE',
			'+966' => 'SA',
			'+974' => 'QA',
			'+968' => 'OM',
			'+973' => 'BH',
			'+965' => 'KW',
			'+44'  => 'GB',
			'+1'   => 'US',
			'+91'  => 'IN',
			'+92'  => 'PK',
			'+880' => 'BD',
			'+94'  => 'LK',
			'+977' => 'NP',
			'+63'  => 'PH',
			'+86'  => 'CN',
			'+7'   => 'RU',
			'+49'  => 'DE',
			'+33'  => 'FR',
			'+39'  => 'IT',
			'+34'  => 'ES',
			'+31'  => 'NL',
			'+48'  => 'PL',
			'+90'  => 'TR',
			'+20'  => 'EG',
			'+962' => 'JO',
			'+961' => 'LB',
			'+98'  => 'IR',
			'+234' => 'NG',
			'+254' => 'KE',
			'+27'  => 'ZA',
			'+61'  => 'AU',
			'+64'  => 'NZ',
			'+353' => 'IE',
			'+60'  => 'MY',
			'+65'  => 'SG',
			'+62'  => 'ID',
			'+82'  => 'KR',
			'+81'  => 'JP',
			'+55'  => 'BR',
			'+52'  => 'MX',
		);
	}

	/**
	 * ISO country for a dial code in the list (falls back to AE).
	 */
	public static function iso_for( $dial ) {
		$codes = self::country_codes();
		return isset( $codes[ $dial ] ) ? $codes[ $dial ] : 'AE';
	}

	/**
	 * Pull a safe HTTPS GHL form/survey URL out of an iframe snippet or a bare URL.
	 * Returns '' when nothing valid is found.
	 */
	public static function parse_embed( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
			$raw = $m[1];
		}
		$raw = trim( html_entity_decode( $raw, ENT_QUOTES ) );
		if ( ! preg_match( '#^https://[A-Za-z0-9.\-]+(:\d+)?/widget/(form|survey|booking)/[A-Za-z0-9_\-]+#', $raw ) ) {
			return '';
		}
		$url = esc_url_raw( $raw, array( 'https' ) );
		return $url ? $url : '';
	}

	private static function bool( $in, $key ) {
		return ! empty( $in[ $key ] ) ? 1 : 0;
	}

	private static function choice( $value, $allowed, $fallback ) {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function int_range( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Sanitise raw POST input into a full settings array.
	 */
	public static function sanitize( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? wp_unslash( $in ) : array();
		$out = array();

		$text = function ( $key ) use ( $in, $d ) {
			return isset( $in[ $key ] ) ? sanitize_text_field( $in[ $key ] ) : $d[ $key ];
		};

		$out['enabled']    = self::bool( $in, 'enabled' );
		$out['visibility'] = self::choice( isset( $in['visibility'] ) ? $in['visibility'] : '', array( 'all', 'pages' ), 'all' );

		$ids = array();
		if ( isset( $in['page_ids'] ) ) {
			$list = is_array( $in['page_ids'] ) ? $in['page_ids'] : explode( ',', (string) $in['page_ids'] );
			foreach ( $list as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		$out['page_ids'] = array_values( array_unique( $ids ) );

		$out['position']     = self::choice( isset( $in['position'] ) ? $in['position'] : '', array( 'right', 'left' ), 'right' );
		$out['pulse']        = self::bool( $in, 'pulse' );
		$out['badge']        = self::bool( $in, 'badge' );
		$out['teaser']       = self::bool( $in, 'teaser' );
		$out['teaser_text']  = $text( 'teaser_text' );
		$out['teaser_delay'] = self::int_range( isset( $in['teaser_delay'] ) ? $in['teaser_delay'] : '', 0, 120, 4 );

		$out['form_source'] = self::choice( isset( $in['form_source'] ) ? $in['form_source'] : '', array( 'builtin', 'iframe' ), 'builtin' );

		$hook               = isset( $in['webhook_url'] ) ? trim( $in['webhook_url'] ) : '';
		$out['webhook_url'] = ( $hook && 0 === strpos( $hook, 'https://' ) ) ? esc_url_raw( $hook, array( 'https' ) ) : '';

		$cc                     = isset( $in['default_cc'] ) ? $in['default_cc'] : '';
		$out['default_cc']      = array_key_exists( $cc, self::country_codes() ) ? $cc : '+971';
		$out['cc_auto']         = self::bool( $in, 'cc_auto' );
		$out['button_text']     = $text( 'button_text' );
		$out['consent_text']    = $text( 'consent_text' );
		$out['consent_default'] = self::bool( $in, 'consent_default' );
		$out['after_submit']    = self::choice( isset( $in['after_submit'] ) ? $in['after_submit'] : '', array( 'wa', 'thanks' ), 'wa' );
		$out['wa_number']       = isset( $in['wa_number'] ) ? preg_replace( '/[^\d+ \-()]/', '', $in['wa_number'] ) : '';
		$out['wa_text']         = $text( 'wa_text' );
		$out['wa_reply']        = $text( 'wa_reply' );
		$out['thanks_text']     = $text( 'thanks_text' );

		// Keep the raw snippet for display, but only ever output the parsed URL.
		// Store just the clean form URL when one is found (an <iframe> snippet would be stripped by sanitising).
		$raw_embed    = isset( $in['embed'] ) ? (string) $in['embed'] : '';
		$parsed       = self::parse_embed( $raw_embed );
		$out['embed'] = $parsed ? $parsed : sanitize_textarea_field( $raw_embed );
		$out['iframe_height'] = self::int_range( isset( $in['iframe_height'] ) ? $in['iframe_height'] : '', 150, 1200, 365 );
		$out['iframe_trim']   = self::int_range( isset( $in['iframe_trim'] ) ? $in['iframe_trim'] : '', 0, 600, 90 );

		$out['business_name'] = $text( 'business_name' );
		$out['heading']       = $text( 'heading' );
		$out['welcome']       = isset( $in['welcome'] ) ? sanitize_textarea_field( $in['welcome'] ) : $d['welcome'];
		$out['reply_notice']  = $text( 'reply_notice' );
		$out['verified']      = self::bool( $in, 'verified' );
		$out['powered_by']    = $text( 'powered_by' );
		$out['powered_url']   = isset( $in['powered_url'] ) ? esc_url_raw( trim( $in['powered_url'] ), array( 'http', 'https' ) ) : '';
		$out['avatar_id']     = isset( $in['avatar_id'] ) ? absint( $in['avatar_id'] ) : 0;
		$out['bg']            = self::choice( isset( $in['bg'] ) ? $in['bg'] : '', array( 'doodle', 'plain', 'custom' ), 'doodle' );
		$out['bg_id']         = isset( $in['bg_id'] ) ? absint( $in['bg_id'] ) : 0;
		$out['theme']         = self::choice( isset( $in['theme'] ) ? $in['theme'] : '', array( 'light', 'auto' ), 'light' );

		$key              = isset( $in['gclid_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $in['gclid_key'] ) : '';
		$out['gclid_key'] = $key ? $key : 'gclid';

		$out['conv_enabled'] = self::bool( $in, 'conv_enabled' );
		$out['conv_trigger'] = self::choice( isset( $in['conv_trigger'] ) ? $in['conv_trigger'] : '', array( 'submit', 'open' ), 'submit' );

		$cid = isset( $in['conv_id'] ) ? strtoupper( preg_replace( '/\s+/', '', $in['conv_id'] ) ) : '';
		if ( preg_match( '/^\d{6,}$/', $cid ) ) {
			$cid = 'AW-' . $cid;
		}
		$out['conv_id']    = preg_match( '/^AW-\d{6,}$/', $cid ) ? $cid : '';
		$label             = isset( $in['conv_label'] ) ? trim( $in['conv_label'] ) : '';
		$out['conv_label'] = preg_match( '/^[A-Za-z0-9_\-]{4,64}$/', $label ) ? $label : '';
		$out['enhanced']   = self::bool( $in, 'enhanced' );

		$out['ref_enabled'] = self::bool( $in, 'ref_enabled' );
		$prefix             = isset( $in['ref_prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $in['ref_prefix'] ) ) : '';
		$out['ref_prefix']  = '' !== $prefix ? substr( $prefix, 0, 10 ) : $d['ref_prefix'];
		$label              = isset( $in['ref_label'] ) ? trim( sanitize_text_field( $in['ref_label'] ) ) : '';
		$out['ref_label']   = '' !== $label ? mb_substr( $label, 0, 20 ) : $d['ref_label'];
		$out['ref_text']    = isset( $in['ref_text'] ) ? sanitize_text_field( $in['ref_text'] ) : $d['ref_text'];

		$sheet            = isset( $in['sheet_url'] ) ? trim( $in['sheet_url'] ) : '';
		$out['sheet_url'] = self::valid_sheet_url( $sheet ) ? esc_url_raw( $sheet, array( 'https' ) ) : '';
		// The secret is not edited in the form: keep the one already saved.
		$out['sheet_secret'] = self::sheet_secret();

		return $out;
	}

	public static function avatar_url( $s = null ) {
		$s = $s ? $s : self::get();
		if ( ! empty( $s['avatar_id'] ) ) {
			$src = wp_get_attachment_image_url( $s['avatar_id'], 'thumbnail' );
			if ( $src ) {
				return $src;
			}
		}
		return DGWC_URL . 'assets/img/avatar.webp';
	}

	public static function bg_url( $s = null ) {
		$s = $s ? $s : self::get();
		if ( 'plain' === $s['bg'] ) {
			return '';
		}
		if ( 'custom' === $s['bg'] && ! empty( $s['bg_id'] ) ) {
			$src = wp_get_attachment_image_url( $s['bg_id'], 'large' );
			if ( $src ) {
				return $src;
			}
		}
		return DGWC_URL . 'assets/img/chat-bg.webp';
	}

	/**
	 * Digits-only WhatsApp number for wa.me links.
	 */
	public static function wa_digits( $s = null ) {
		$s      = $s ? $s : self::get();
		$digits = preg_replace( '/\D/', '', (string) $s['wa_number'] );
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		return $digits;
	}
}
