<?php
/**
 * Settings page: WhatsApp Popup (own sidebar menu).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Admin {

	const SLUG = 'dgwc-whatsapp-popup';
	const CAP  = 'manage_options';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_dgwc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_dgwc_clear_leads', array( __CLASS__, 'clear_leads' ) );
		add_action( 'admin_post_dgwc_export_leads', array( __CLASS__, 'export_leads' ) );
		add_action( 'admin_post_dgwc_clear_clicks', array( __CLASS__, 'clear_clicks' ) );
		add_action( 'admin_post_dgwc_export_clicks', array( __CLASS__, 'export_clicks' ) );
		add_action( 'wp_ajax_dgwc_test_webhook', array( __CLASS__, 'test_webhook' ) );
		add_action( 'wp_ajax_dgwc_test_sheet', array( __CLASS__, 'test_sheet' ) );
		add_action( 'admin_post_dgwc_resend_sheet', array( __CLASS__, 'resend_sheet' ) );
	}

	public static function menu() {
		self::$hook = add_menu_page(
			__( 'Digitum GHL WhatsApp Popup', 'digitum-whatsapp-chat' ),
			__( 'WhatsApp Popup', 'digitum-whatsapp-chat' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'page' ),
			self::menu_icon(),
			58
		);
	}

	/**
	 * WhatsApp-style menu icon (SVG, tinted by WordPress like dashicons).
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.6a8.3 8.3 0 0 0-7.1 12.6L1.7 18.4l4.3-1.1A8.3 8.3 0 1 0 10 1.6zm4.8 11.7c-.2.6-1.2 1.1-1.7 1.2-.4.1-1 .1-1.6-.1a14 14 0 0 1-1.4-.5c-2.5-1.1-4.2-3.6-4.3-3.8-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.4l.8 2c.1.1.1.3 0 .5l-.3.4-.3.4c-.1.1-.2.3-.1.5.2.3.7 1.1 1.4 1.8.9.8 1.7 1.1 2 1.2.2.1.4.1.5-.1l.7-.9c.2-.2.4-.2.6-.1l1.9.9c.2.1.4.2.4.3.1.1.1.6-.1 1.1z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'dgwc-iti', DGWC_URL . 'assets/vendor/intl-tel-input/css/intlTelInput.min.css', array(), '25.10.12' );
		wp_enqueue_style( 'dgwc-admin', DGWC_URL . 'assets/css/admin.css', array( 'dgwc-iti' ), DGWC_VERSION );
		wp_enqueue_script( 'dgwc-admin', DGWC_URL . 'assets/js/admin.js', array( 'jquery' ), DGWC_VERSION, true );

		$s = DGWC_Settings::get();
		wp_localize_script(
			'dgwc-admin',
			'DGWC_ADMIN',
			array(
				'pages'         => self::page_list( $s['page_ids'] ),
				'selected'      => array_map( 'intval', (array) $s['page_ids'] ),
				'ajax'          => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'dgwc_test' ),
				'defaultAvatar' => DGWC_URL . 'assets/img/avatar.webp',
				'defaultBg'     => DGWC_URL . 'assets/img/chat-bg.webp',
				'codes'         => DGWC_Settings::country_codes(),
			)
		);
	}

	/**
	 * Pages, landing pages and posts for the page picker.
	 */
	private static function page_list( $selected ) {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		$labels = array();
		foreach ( $types as $k => $t ) {
			$labels[ $k ] = $t->labels->singular_name;
		}
		$q   = new WP_Query(
			array(
				'post_type'              => array_keys( $labels ),
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => 800,
				'orderby'                => array(
					'type'  => 'DESC',
					'title' => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$out = array();
		$ids = array();
		foreach ( $q->posts as $p ) {
			$ids[] = $p->ID;
			$out[] = array(
				'id'   => $p->ID,
				't'    => html_entity_decode( get_the_title( $p ) ? get_the_title( $p ) : '(no title)', ENT_QUOTES ),
				'type' => isset( $labels[ $p->post_type ] ) ? $labels[ $p->post_type ] : $p->post_type,
			);
		}
		// Make sure every saved ID has a label even if it wasn't in the first batch.
		foreach ( (array) $selected as $id ) {
			if ( ! in_array( (int) $id, $ids, true ) ) {
				$p     = get_post( $id );
				$out[] = array(
					'id'   => (int) $id,
					't'    => $p ? html_entity_decode( get_the_title( $p ), ENT_QUOTES ) : 'Page',
					'type' => $p ? $p->post_type : '',
				);
			}
		}
		return $out;
	}

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'digitum-whatsapp-chat' ) );
		}
		check_admin_referer( 'dgwc_save' );
		$in  = isset( $_POST['dgwc'] ) ? $_POST['dgwc'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field below.
		$new = DGWC_Settings::sanitize( $in );
		update_option( DGWC_Settings::OPTION, $new );
		DGWC_Settings::flush();

		$tab = isset( $_POST['dgwc_tab'] ) ? sanitize_key( $_POST['dgwc_tab'] ) : 'general';
		$msg = 'saved';
		if ( 'iframe' === $new['form_source'] && '' === DGWC_Settings::parse_embed( $new['embed'] ) ) {
			$msg = 'saved_noembed';
		}
		wp_safe_redirect( self::page_url( array( 'dgwc_msg' => $msg, 'tab' => $tab ) ) );
		exit;
	}

	public static function clear_leads() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'digitum-whatsapp-chat' ) );
		}
		check_admin_referer( 'dgwc_clear_leads' );
		DGWC_Leads::clear();
		wp_safe_redirect( self::page_url( array( 'dgwc_msg' => 'cleared', 'tab' => 'leads' ) ) );
		exit;
	}

	public static function export_leads() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'digitum-whatsapp-chat' ) );
		}
		check_admin_referer( 'dgwc_export_leads' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=whatsapp-chat-leads-' . gmdate( 'Y-m-d' ) . '.csv' );
		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, array( 'Date', 'Name', 'Phone', 'Page', 'Click ID', 'UTM', 'GHL status', 'Detail' ), ',', '"', '\\' );
		foreach ( DGWC_Leads::all() as $l ) {
			$row = array(
				wp_date( 'Y-m-d H:i', $l['time'] ),
				$l['name'],
				$l['phone'],
				$l['page'],
				$l['gclid'],
				isset( $l['utm'] ) ? $l['utm'] : '',
				$l['status'],
				$l['detail'],
			);
			fputcsv( $fh, self::csv_safe( $row ), ',', '"', '\\' );
		}
		fclose( $fh ); // phpcs:ignore
		exit;
	}

	/**
	 * Neutralise spreadsheet formulas. Phone numbers stay as-is.
	 */
	private static function csv_safe( $row ) {
		return array_map(
			function ( $v ) {
				$v = (string) $v;
				return ( preg_match( '/^[=@\t\r]/', $v ) || preg_match( '/^[+\-](?![\d ]+$)/', $v ) ) ? "'" . $v : $v;
			},
			$row
		);
	}

	/**
	 * Google Apps Script code the user pastes into the sheet (Extensions → Apps Script).
	 */
	public static function sheet_script( $secret ) {
		$code = <<<'JS'
// WhatsApp clicks → this Google Sheet.
// Row 1 must have the headers: GCLID | Ref Code | UTC | Pk Time (other columns are left for your team).
const SECRET = '%SECRET%';   // must match the plugin; do not share
const SHEET_NAME = '';       // tab name, or leave empty to use the first tab

function doPost(e) {
  var data;
  try { data = JSON.parse(e.postData.contents); } catch (err) { return reply({ ok: false, error: 'Bad request' }); }
  if (!data || data.secret !== SECRET) return reply({ ok: false, error: 'Wrong secret: copy the script again from the plugin' });

  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sh = SHEET_NAME ? ss.getSheetByName(SHEET_NAME) : ss.getSheets()[0];
    if (!sh) return reply({ ok: false, error: 'Tab "' + SHEET_NAME + '" not found' });

    var head = sh.getRange(1, 1, 1, Math.max(sh.getLastColumn(), 1)).getValues()[0]
      .map(function (h) { return String(h).replace(/\s+/g, ' ').trim().toLowerCase(); });
    function col(names) { for (var i = 0; i < names.length; i++) { var c = head.indexOf(names[i]); if (c >= 0) return c + 1; } return 0; }
    var cG = col(['gclid']), cR = col(['ref code', 'red code', 'reference code', 'ref']),
        cU = col(['utc', 'utc time']), cP = col(['pk time', 'pkt', 'pakistan time']);
    if (!cG || !cR || !cU || !cP) return reply({ ok: false, error: 'Row 1 needs the headers GCLID, Ref Code, UTC and Pk Time' });

    // Next row after the last one that has a Ref Code or GCLID (team columns like checkboxes are ignored).
    var last = sh.getLastRow(), next = 2;
    if (last >= 2) {
      var refs = sh.getRange(2, cR, last - 1, 1).getDisplayValues(), ids = sh.getRange(2, cG, last - 1, 1).getDisplayValues();
      for (var r = 0; r < refs.length; r++) {
        if (refs[r][0] === data.ref_code) return reply({ ok: true, duplicate: true, row: r + 2 }); // already added (a retry)
        if (refs[r][0] !== '' || ids[r][0] !== '') next = r + 3;
      }
    }
    [[cG, data.gclid], [cR, data.ref_code], [cU, data.utc], [cP, data.pk_time]].forEach(function (p) {
      sh.getRange(next, p[0]).setNumberFormat('@').setValue(String(p[1] || '')); // plain text: "1:05 PM" stays as written
    });
    return reply({ ok: true, row: next });
  } finally {
    lock.releaseLock();
  }
}

function reply(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}
JS;
		return str_replace( '%SECRET%', $secret, $code );
	}

	public static function test_sheet() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed' ), 403 );
		}
		check_ajax_referer( 'dgwc_test', 'nonce' );
		$url = isset( $_POST['url'] ) ? trim( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! DGWC_Settings::valid_sheet_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'Paste the web app URL from Apps Script (it ends with /exec).' ) );
		}
		$t   = DGWC_Clicks::sheet_times( current_time( 'mysql', true ) );
		$res = DGWC_Clicks::post_sheet(
			esc_url_raw( $url, array( 'https' ) ),
			array(
				'secret'   => DGWC_Settings::sheet_secret(),
				'gclid'    => 'TEST-GCLID',
				'ref_code' => 'TEST-' . strtoupper( wp_generate_password( 4, false, false ) ),
				'utc'      => $t['utc'],
				'pk_time'  => $t['pk'],
			)
		);
		if ( $res['ok'] ) {
			wp_send_json_success( array( 'message' => 'Test row added (' . $res['detail'] . '). You can delete it from the sheet. Now press Save settings.' ) );
		}
		wp_send_json_error( array( 'message' => 'Failed: ' . $res['detail'] ) );
	}

	public static function resend_sheet() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'digitum-whatsapp-chat' ) );
		}
		check_admin_referer( 'dgwc_resend_sheet' );
		$r = DGWC_Clicks::resend_unsent();
		wp_safe_redirect( self::page_url( array( 'dgwc_msg' => 'resent', 'sent' => $r['sent'], 'failed' => $r['failed'], 'tab' => 'clicks' ) ) );
		exit;
	}

	public static function clear_clicks() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'digitum-whatsapp-chat' ) );
		}
		check_admin_referer( 'dgwc_clear_clicks' );
		DGWC_Clicks::clear();
		wp_safe_redirect( self::page_url( array( 'dgwc_msg' => 'cleared_clicks', 'tab' => 'clicks' ) ) );
		exit;
	}

	public static function export_clicks() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'digitum-whatsapp-chat' ) );
		}
		check_admin_referer( 'dgwc_export_clicks' );
		$search = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=whatsapp-clicks-' . gmdate( 'Y-m-d' ) . '.csv' );
		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, array( 'Code', 'GCLID', 'Click Time', 'Button', 'Page', 'GBRAID', 'WBRAID', 'FBCLID', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content', 'WhatsApp Number', 'Page Title', 'Referrer' ), ',', '"', '\\' );
		$offset = 0;
		do {
			$rows = DGWC_Clicks::recent( 1000, $offset, $search );
			foreach ( $rows as $r ) {
				fputcsv(
					$fh,
					self::csv_safe(
						array(
							$r['code'],
							$r['gclid'],
							wp_date( 'Y-m-d H:i:s', DGWC_Clicks::ts( $r['created_at'] ) ),
							$r['button'],
							$r['page_url'],
							$r['gbraid'],
							$r['wbraid'],
							$r['fbclid'],
							$r['utm_source'],
							$r['utm_medium'],
							$r['utm_campaign'],
							$r['utm_term'],
							$r['utm_content'],
							$r['wa_number'],
							$r['page_title'],
							$r['referrer'],
						)
					),
					',',
					'"',
					'\\'
				);
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );
		fclose( $fh ); // phpcs:ignore
		exit;
	}

	public static function test_webhook() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed' ), 403 );
		}
		check_ajax_referer( 'dgwc_test', 'nonce' );
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'https' ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'Enter an https:// webhook URL first.' ) );
		}
		$res = DGWC_Submit::send_webhook(
			$url,
			array(
				'full_name'    => 'Test Lead',
				'first_name'   => 'Test',
				'last_name'    => 'Lead',
				'phone'        => '+971500000000',
				'country_code' => '+971',
				'consent'      => 'Yes',
				'page_url'     => home_url( '/' ),
				'page_title'   => 'Webhook test',
				'gclid'        => 'TEST_GCLID',
				'source'       => 'Digitum WhatsApp Chat (test)',
				'website'      => home_url( '/' ),
				'submitted_at' => gmdate( 'c' ),
				'is_test'      => true,
			)
		);
		if ( $res['ok'] ) {
			wp_send_json_success( array( 'message' => 'Sent (' . $res['detail'] . '). Check the workflow\'s trigger in GHL for the sample data.' ) );
		}
		wp_send_json_error( array( 'message' => 'Failed: ' . $res['detail'] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Field helpers                                                        */
	/* ------------------------------------------------------------------ */

	private static function n( $key ) {
		return 'dgwc[' . $key . ']';
	}

	private static function toggle( $key, $label, $s ) {
		printf(
			'<label class="tg"><input type="hidden" name="%1$s" value="0"><input type="checkbox" id="s-%2$s" name="%1$s" value="1" role="switch" aria-label="%3$s" %4$s><span></span></label>',
			esc_attr( self::n( $key ) ),
			esc_attr( $key ),
			esc_attr( $label ),
			checked( ! empty( $s[ $key ] ), true, false )
		);
	}

	private static function text( $key, $label, $s, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'  => 'text',
				'help'  => '',
				'class' => '',
				'mono'  => false,
				'ph'    => '',
				'wrap'  => 'fld',
			)
		);
		echo '<div class="' . esc_attr( $args['wrap'] ) . '"><label class="lbl" for="s-' . esc_attr( $key ) . '">' . wp_kses_post( $label ) . '</label>';
		printf(
			'<input class="ai%1$s" id="s-%2$s" name="%3$s" type="%4$s" value="%5$s" placeholder="%6$s">',
			$args['mono'] ? ' mono' : '',
			esc_attr( $key ),
			esc_attr( self::n( $key ) ),
			esc_attr( $args['type'] ),
			esc_attr( is_array( $s[ $key ] ) ? '' : $s[ $key ] ),
			esc_attr( $args['ph'] )
		);
		if ( $args['help'] ) {
			echo '<p class="help">' . wp_kses_post( $args['help'] ) . '</p>';
		}
		echo '</div>';
	}

	private static function number( $key, $label, $s, $min, $max, $suffix, $help = '' ) {
		echo '<div class="fld"><label class="lbl" for="s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		printf(
			'<div class="suf"><input class="ai" id="s-%1$s" name="%2$s" type="number" min="%3$d" max="%4$d" value="%5$d"><span>%6$s</span></div>',
			esc_attr( $key ),
			esc_attr( self::n( $key ) ),
			(int) $min,
			(int) $max,
			(int) $s[ $key ],
			esc_html( $suffix )
		);
		if ( $help ) {
			echo '<p class="help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	private static function seg( $key, $options, $s, $aria ) {
		echo '<div class="seg2" role="radiogroup" aria-label="' . esc_attr( $aria ) . '">';
		foreach ( $options as $val => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s><span>%4$s</span></label>',
				esc_attr( self::n( $key ) ),
				esc_attr( $val ),
				checked( $s[ $key ], $val, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	private static function wa_icon( $size = 26 ) {
		return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3.5C9 3.5 3.4 8.9 3.4 15.6c0 2.3.7 4.5 1.9 6.3L4 28l6.3-1.7c1.7.9 3.6 1.4 5.7 1.4 7 0 12.6-5.4 12.6-12.1S23 3.5 16 3.5z" fill="none" stroke="#fff" stroke-width="2.4" stroke-linejoin="round"/></svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                 */
	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s      = DGWC_Settings::get();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs   = array(
			'general' => __( 'General', 'digitum-whatsapp-chat' ),
			'form'    => __( 'Form & GHL', 'digitum-whatsapp-chat' ),
			'look'    => __( 'Appearance', 'digitum-whatsapp-chat' ),
			'track'   => __( 'Tracking', 'digitum-whatsapp-chat' ),
			'leads'   => __( 'Chat leads', 'digitum-whatsapp-chat' ),
			'clicks'  => __( 'WhatsApp clicks', 'digitum-whatsapp-chat' ),
		);
		$tab    = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$msg    = isset( $_GET['dgwc_msg'] ) ? sanitize_key( $_GET['dgwc_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$leads  = DGWC_Leads::all();

		// WhatsApp clicks: search + pages of 100.
		$cq        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$cpage     = isset( $_GET['cp'] ) ? max( 1, absint( $_GET['cp'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$cper      = 100;
		$ctotal    = DGWC_Clicks::count();
		$cfound    = '' === $cq ? $ctotal : DGWC_Clicks::count( $cq );
		$cpages    = max( 1, (int) ceil( $cfound / $cper ) );
		$cpage     = min( $cpage, $cpages );
		$clicks    = DGWC_Clicks::recent( $cper, ( $cpage - 1 ) * $cper, $cq );
		$avatar = DGWC_Settings::avatar_url( $s );
		$bgimg  = DGWC_Settings::bg_url( array_merge( $s, array( 'bg' => 'custom' ) ) );
		$embed  = DGWC_Settings::parse_embed( $s['embed'] );
		$codes  = DGWC_Settings::country_codes();
		$hidden = function ( $panel ) use ( $tab ) {
			return $panel === $tab ? '' : ' hidden';
		};
		?>
<div class="wrap dgwc-admin">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Digitum GHL WhatsApp Popup', 'digitum-whatsapp-chat' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( 'saved' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'digitum-whatsapp-chat' ); ?></p></div>
	<?php elseif ( 'saved_noembed' === $msg ) : ?>
		<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Settings saved, but the GHL form URL is not valid, so the button stays hidden until you add a valid one.', 'digitum-whatsapp-chat' ); ?></p></div>
	<?php elseif ( 'cleared' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lead log cleared.', 'digitum-whatsapp-chat' ); ?></p></div>
	<?php elseif ( 'resent' === $msg ) : ?>
		<?php $rs = isset( $_GET['sent'] ) ? absint( $_GET['sent'] ) : 0; $rf = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0; // phpcs:ignore ?>
		<div class="notice <?php echo $rf ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( sprintf( 'Sent to the Google Sheet: %d. Failed: %d.', $rs, $rf ) ); ?></p></div>
	<?php elseif ( 'cleared_clicks' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'WhatsApp click log cleared.', 'digitum-whatsapp-chat' ); ?></p></div>
	<?php endif; ?>

	<header class="phd">
		<div class="phd-ic" aria-hidden="true"><?php echo self::wa_icon(); // phpcs:ignore ?></div>
		<div class="phd-t">
			<h2><?php esc_html_e( 'Digitum GHL WhatsApp Popup', 'digitum-whatsapp-chat' ); ?></h2>
			<p><?php esc_html_e( 'Floating WhatsApp button with a compact chat card that sends leads to HighLevel.', 'digitum-whatsapp-chat' ); ?></p>
		</div>
		<div class="phd-r">
			<span class="pill" id="st-pill"><i></i><span id="st-text"></span></span>
			<span class="ver">v<?php echo esc_html( DGWC_VERSION ); ?></span>
		</div>
	</header>

	<nav class="tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'digitum-whatsapp-chat' ); ?>">
		<?php foreach ( $tabs as $k => $label ) : ?>
			<button type="button" role="tab" aria-selected="<?php echo $k === $tab ? 'true' : 'false'; ?>" data-tab="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?><?php echo 'leads' === $k && $leads ? ' <span class="count">' . count( $leads ) . '</span>' : ''; ?><?php echo 'clicks' === $k && $ctotal ? ' <span class="count">' . (int) $ctotal . '</span>' : ''; ?></button>
		<?php endforeach; ?>
	</nav>

	<div class="agrid">
		<form class="aforms" id="aform" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<input type="hidden" name="action" value="dgwc_save">
			<input type="hidden" name="dgwc_tab" id="dgwc-tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'dgwc_save' ); ?>

			<!-- GENERAL -->
			<section class="tabp" data-panel="general"<?php echo $hidden( 'general' ); // phpcs:ignore ?>>
				<div class="acard">
					<div class="arow">
						<div class="atext"><h3><?php esc_html_e( 'Show WhatsApp button', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Turns the floating button on or off for every visitor.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'enabled', 'Show WhatsApp button', $s ); ?>
					</div>
				</div>

				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Where it appears', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Choose the whole site or only specific pages.', 'digitum-whatsapp-chat' ); ?></p></div>
					<?php self::seg( 'visibility', array( 'all' => 'Entire website', 'pages' => 'Selected pages only' ), $s, 'Widget visibility' ); ?>
					<div class="sub" id="pages-box">
						<label class="lbl" for="pg-input"><?php esc_html_e( 'Pages', 'digitum-whatsapp-chat' ); ?></label>
						<div class="chips" id="chips">
							<input id="pg-input" type="text" placeholder="<?php esc_attr_e( 'Search pages or type an ID', 'digitum-whatsapp-chat' ); ?>" autocomplete="off">
						</div>
						<div class="sugg" id="sugg" hidden></div>
						<input type="hidden" name="<?php echo esc_attr( self::n( 'page_ids' ) ); ?>" id="s-page_ids" value="<?php echo esc_attr( implode( ',', (array) $s['page_ids'] ) ); ?>">
						<p class="help"><?php esc_html_e( 'Works for pages, posts and landing pages. Saved page IDs are kept if you switch back to Entire website.', 'digitum-whatsapp-chat' ); ?></p>
					</div>
				</div>

				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Launcher button', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'The chat only opens when a visitor clicks.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="fld"><span class="lbl"><?php esc_html_e( 'Position', 'digitum-whatsapp-chat' ); ?></span>
						<?php self::seg( 'position', array( 'right' => 'Bottom right', 'left' => 'Bottom left' ), $s, 'Position' ); ?>
					</div>
					<div class="arow">
						<div class="atext"><h4><?php esc_html_e( 'Pulse animation', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Green ring pulses until the first click. Off automatically for reduced-motion users.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'pulse', 'Pulse animation', $s ); ?>
					</div>
					<div class="arow">
						<div class="atext"><h4><?php esc_html_e( 'Unread badge', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Red "1" on the button until the visitor opens the chat.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'badge', 'Unread badge', $s ); ?>
					</div>
					<div class="arow">
						<div class="atext"><h4><?php esc_html_e( 'Greeting bubble', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Small message beside the button after a few seconds. Shown once per visit.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'teaser', 'Greeting bubble', $s ); ?>
					</div>
					<div class="sub" id="teaser-box">
						<div class="f2">
							<?php self::text( 'teaser_text', 'Greeting text', $s, array( 'wrap' => 'fld grow' ) ); ?>
							<?php self::number( 'teaser_delay', 'Show after', $s, 0, 120, 'sec' ); ?>
						</div>
					</div>
				</div>
			</section>

			<!-- FORM & GHL -->
			<section class="tabp" data-panel="form"<?php echo $hidden( 'form' ); // phpcs:ignore ?>>
				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Form source', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'How visitors leave their details inside the chat.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="opts" role="radiogroup" aria-label="Form source">
						<label class="opt"><input type="radio" name="<?php echo esc_attr( self::n( 'form_source' ) ); ?>" value="builtin" <?php checked( $s['form_source'], 'builtin' ); ?>>
							<span><b><?php esc_html_e( 'Built-in form', 'digitum-whatsapp-chat' ); ?></b><em><?php esc_html_e( 'Name and WhatsApp number in chat bubbles. Sent to GHL by webhook. Most reliable for conversion tracking.', 'digitum-whatsapp-chat' ); ?></em></span></label>
						<label class="opt"><input type="radio" name="<?php echo esc_attr( self::n( 'form_source' ) ); ?>" value="iframe" <?php checked( $s['form_source'], 'iframe' ); ?>>
							<span><b><?php esc_html_e( 'GHL embedded form', 'digitum-whatsapp-chat' ); ?></b><em><?php esc_html_e( 'Show an existing HighLevel form inside the chat card.', 'digitum-whatsapp-chat' ); ?></em></span></label>
					</div>
				</div>

				<div class="acard" id="box-builtin">
					<div class="ahead"><h3><?php esc_html_e( 'Built-in form', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Fields: name, country code, WhatsApp number, consent.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="fld"><label class="lbl" for="s-webhook_url"><?php esc_html_e( 'GHL inbound webhook URL', 'digitum-whatsapp-chat' ); ?></label>
						<div class="inrow">
							<input class="ai mono" id="s-webhook_url" name="<?php echo esc_attr( self::n( 'webhook_url' ) ); ?>" type="url" value="<?php echo esc_attr( $s['webhook_url'] ); ?>" placeholder="https://services.leadconnectorhq.com/hooks/…">
							<button type="button" class="ab" id="test-hook"><?php esc_html_e( 'Send test', 'digitum-whatsapp-chat' ); ?></button>
						</div>
						<p class="help" id="test-hook-msg" hidden></p>
						<p class="help"><?php echo wp_kses_post( __( 'In GHL: Automation → Workflow → trigger <b>Inbound Webhook</b>. Sends <code>full_name</code>, <code>first_name</code>, <code>last_name</code>, <code>phone</code>, <code>country_code</code>, <code>consent</code>, <code>page_url</code>, <code>gclid</code>, <code>gbraid</code>, <code>wbraid</code> and UTMs. Sent from your server, so the URL is never exposed to visitors.', 'digitum-whatsapp-chat' ) ); ?></p>
					</div>
					<div class="f2">
						<div class="fld"><label class="lbl" for="s-default_cc"><?php esc_html_e( 'Default country code', 'digitum-whatsapp-chat' ); ?></label>
							<select class="ai" id="s-default_cc" name="<?php echo esc_attr( self::n( 'default_cc' ) ); ?>">
								<?php foreach ( $codes as $code => $iso ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['default_cc'], $code ); ?>><?php echo esc_html( $iso . ' ' . $code ); ?></option>
								<?php endforeach; ?>
							</select></div>
						<?php self::text( 'button_text', 'Button text', $s, array( 'wrap' => 'fld grow' ) ); ?>
					</div>
					<div class="arow">
						<div class="atext"><h4><?php esc_html_e( 'Detect visitor\'s country automatically', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Pre-selects the flag and dial code from the visitor\'s device time zone (no extra lookups). Falls back to the default country. Visitors can still change it, or type a +code.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'cc_auto', 'Detect country automatically', $s ); ?>
					</div>
					<?php self::text( 'consent_text', 'Consent text', $s ); ?>
					<div class="arow">
						<div class="atext"><h4><?php esc_html_e( 'Consent box ticked by default', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Turn off where your market needs an explicit opt-in (for example UK/EU).', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'consent_default', 'Consent ticked by default', $s ); ?>
					</div>
					<div class="fld"><span class="lbl"><?php esc_html_e( 'After submit', 'digitum-whatsapp-chat' ); ?></span>
						<?php self::seg( 'after_submit', array( 'wa' => 'Open WhatsApp chat', 'thanks' => 'Show thank-you message' ), $s, 'After submit' ); ?>
					</div>
					<div class="sub" id="after-wa-box">
						<div class="f2">
							<?php self::text( 'wa_number', 'Business WhatsApp number', $s, array( 'type' => 'tel', 'mono' => true, 'ph' => '+971 50 123 4567', 'help' => 'All WhatsApp buttons on the site and the chat popup open this number.' ) ); ?>
							<?php self::text( 'wa_text', 'Pre-filled message', $s, array( 'wrap' => 'fld grow', 'help' => 'Use {name}, {phone} or {page_title}.' ) ); ?>
						</div>
						<?php self::text( 'wa_reply', 'Reply bubble in the chat', $s, array( 'help' => 'Shown above the "Open WhatsApp" button. Use {name}.' ) ); ?>
					</div>
					<div class="sub" id="after-th-box">
						<?php self::text( 'thanks_text', 'Thank-you message', $s, array( 'help' => 'Use {name} or {phone}.' ) ); ?>
					</div>
				</div>

				<div class="acard" id="box-iframe">
					<div class="ahead"><h3><?php esc_html_e( 'GHL embedded form', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'HighLevel handles submissions and redirects.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="fld"><label class="lbl" for="s-embed"><?php esc_html_e( 'Embed code or form URL', 'digitum-whatsapp-chat' ); ?> <span class="vpill" id="embed-ok"></span></label>
						<textarea class="ai mono" id="s-embed" name="<?php echo esc_attr( self::n( 'embed' ) ); ?>" rows="3" placeholder="https://link.yourdomain.com/widget/form/…"><?php echo esc_textarea( $s['embed'] ); ?></textarea>
						<p class="help"><?php esc_html_e( 'Paste the full <iframe> snippet or only its HTTPS src. Other scripts are ignored. The button stays hidden until a valid URL is saved.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="f2">
						<?php self::number( 'iframe_height', 'Form height', $s, 150, 1200, 'px', 'Default 365. Raise it if the button gets cut off.' ); ?>
						<?php self::number( 'iframe_trim', 'Trim space below button', $s, 0, 600, 'px', 'Default 90. Lower it if validation messages get clipped.' ); ?>
					</div>
				</div>
			</section>

			<!-- APPEARANCE -->
			<section class="tabp" data-panel="look"<?php echo $hidden( 'look' ); // phpcs:ignore ?>>
				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Chat text', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'What visitors read when the chat opens.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="f2">
						<?php self::text( 'business_name', 'Business name', $s, array( 'wrap' => 'fld grow', 'help' => 'Chat header, greeting and message sender.' ) ); ?>
						<?php self::text( 'heading', 'Popup heading', $s, array( 'wrap' => 'fld grow', 'help' => 'Line under the business name.' ) ); ?>
					</div>
					<div class="fld"><label class="lbl" for="s-welcome"><?php esc_html_e( 'Welcome message', 'digitum-whatsapp-chat' ); ?></label>
						<textarea class="ai" id="s-welcome" name="<?php echo esc_attr( self::n( 'welcome' ) ); ?>" rows="2"><?php echo esc_textarea( $s['welcome'] ); ?></textarea></div>
					<?php self::text( 'reply_notice', 'Reply-time notice', $s, array( 'help' => 'Yellow note at the top of the chat. Leave empty to hide it.' ) ); ?>
					<div class="f2">
						<?php self::text( 'powered_by', 'Powered by label', $s, array( 'help' => 'Leave empty to hide the footer.' ) ); ?>
						<?php self::text( 'powered_url', 'Powered by link (optional)', $s, array( 'type' => 'url', 'wrap' => 'fld grow', 'ph' => 'https://' ) ); ?>
					</div>
					<div class="arow">
						<div class="atext"><h4><?php esc_html_e( 'Verified badge', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Green tick next to the business name.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'verified', 'Verified badge', $s ); ?>
					</div>
				</div>

				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Avatar', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Shown in the header and greeting. Square image, at least 120 × 120.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="media">
						<div class="mthumb round"><img id="avatar-img" src="<?php echo esc_url( $avatar ); ?>" alt=""></div>
						<div class="minfo"><b id="avatar-name"><?php echo $s['avatar_id'] ? esc_html( basename( (string) get_attached_file( $s['avatar_id'] ) ) ) : esc_html__( 'Default Digitum avatar', 'digitum-whatsapp-chat' ); ?></b><span><?php esc_html_e( 'From the Media Library', 'digitum-whatsapp-chat' ); ?></span></div>
						<div class="mact"><button type="button" class="ab" id="avatar-pick"><?php esc_html_e( 'Replace', 'digitum-whatsapp-chat' ); ?></button><button type="button" class="ab ghost" id="avatar-reset"><?php esc_html_e( 'Use default', 'digitum-whatsapp-chat' ); ?></button></div>
						<input type="hidden" id="s-avatar_id" name="<?php echo esc_attr( self::n( 'avatar_id' ) ); ?>" value="<?php echo (int) $s['avatar_id']; ?>">
					</div>
				</div>

				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Chat background', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Wallpaper behind the messages and form.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="bgopts" role="radiogroup" aria-label="Chat background">
						<label class="bgo"><input type="radio" name="<?php echo esc_attr( self::n( 'bg' ) ); ?>" value="doodle" <?php checked( $s['bg'], 'doodle' ); ?>><span class="sw sw-doodle" style="background-image:url(<?php echo esc_url( DGWC_URL . 'assets/img/chat-bg.webp' ); ?>)"></span><b><?php esc_html_e( 'Doodle', 'digitum-whatsapp-chat' ); ?></b></label>
						<label class="bgo"><input type="radio" name="<?php echo esc_attr( self::n( 'bg' ) ); ?>" value="plain" <?php checked( $s['bg'], 'plain' ); ?>><span class="sw sw-plain"></span><b><?php esc_html_e( 'Plain', 'digitum-whatsapp-chat' ); ?></b></label>
						<label class="bgo"><input type="radio" name="<?php echo esc_attr( self::n( 'bg' ) ); ?>" value="custom" <?php checked( $s['bg'], 'custom' ); ?>><span class="sw sw-custom">+</span><b><?php esc_html_e( 'Custom image', 'digitum-whatsapp-chat' ); ?></b></label>
					</div>
					<div class="sub" id="bg-custom-box">
						<div class="media">
							<div class="mthumb"><img id="bg-img" src="<?php echo esc_url( $bgimg ); ?>" alt=""></div>
							<div class="minfo"><b id="bg-name"><?php echo $s['bg_id'] ? esc_html( basename( (string) get_attached_file( $s['bg_id'] ) ) ) : esc_html__( 'No image chosen yet', 'digitum-whatsapp-chat' ); ?></b><span><?php esc_html_e( 'Portrait images work best (e.g. 800 × 1600).', 'digitum-whatsapp-chat' ); ?></span></div>
							<div class="mact"><button type="button" class="ab" id="bg-pick"><?php esc_html_e( 'Choose image', 'digitum-whatsapp-chat' ); ?></button></div>
							<input type="hidden" id="s-bg_id" name="<?php echo esc_attr( self::n( 'bg_id' ) ); ?>" value="<?php echo (int) $s['bg_id']; ?>">
						</div>
					</div>
					<div class="fld"><span class="lbl"><?php esc_html_e( 'Colour mode', 'digitum-whatsapp-chat' ); ?></span>
						<?php self::seg( 'theme', array( 'light' => 'Always light', 'auto' => 'Follow visitor\'s device (dark mode)' ), $s, 'Colour mode' ); ?>
					</div>
				</div>
			</section>

			<!-- TRACKING -->
			<section class="tabp" data-panel="track"<?php echo $hidden( 'track' ); // phpcs:ignore ?>>
				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Google Ads click ID', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'The plugin stores gclid, gbraid, wbraid and UTMs for 90 days and passes them to GHL with every lead.', 'digitum-whatsapp-chat' ); ?></p></div>
					<?php self::text( 'gclid_key', 'GHL hidden field query key', $s, array( 'mono' => true, 'wrap' => 'fld half', 'help' => 'Must match the Query Key of your GHL hidden field (label: My GCLID). For the embedded form it is added as <code>?gclid=VALUE</code>. For the built-in form it is also sent under this name.' ) ); ?>
				</div>

				<div class="acard">
					<div class="arow">
						<div class="atext"><h3><?php esc_html_e( 'Google Ads conversion', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Fires your conversion action from the chat. Loads the Google tag automatically if the site does not have it.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'conv_enabled', 'Google Ads conversion', $s ); ?>
					</div>
					<div id="conv-box" class="convbox">
						<div class="fld"><span class="lbl"><?php esc_html_e( 'Count a conversion when', 'digitum-whatsapp-chat' ); ?></span>
							<?php self::seg( 'conv_trigger', array( 'submit' => 'Form is submitted (recommended)', 'open' => 'Chat is opened' ), $s, 'Conversion trigger' ); ?>
						</div>
						<div class="note warn" id="conv-open-warn"><b><?php esc_html_e( 'Counts chat opens, not leads.', 'digitum-whatsapp-chat' ); ?></b> <?php esc_html_e( 'Visitors who open the chat and never fill the form are counted too, which misleads Smart Bidding. Use this only for a Secondary conversion action.', 'digitum-whatsapp-chat' ); ?></div>
						<div class="f2">
							<?php self::text( 'conv_id', 'Conversion ID', $s, array( 'mono' => true, 'wrap' => 'fld grow', 'ph' => 'AW-123456789' ) ); ?>
							<?php self::text( 'conv_label', 'Conversion label', $s, array( 'mono' => true, 'wrap' => 'fld grow', 'ph' => 'AbCdEfGhIjKlMnOp' ) ); ?>
						</div>
						<div class="arow">
							<div class="atext"><h4><?php esc_html_e( 'Enhanced conversions (phone)', 'digitum-whatsapp-chat' ); ?></h4><p><?php esc_html_e( 'Sends the submitted phone number with the conversion; Google hashes it before sending. Turn on enhanced conversions in the Google Ads conversion settings too.', 'digitum-whatsapp-chat' ); ?></p></div>
							<?php self::toggle( 'enhanced', 'Enhanced conversions', $s ); ?>
						</div>
						<p class="help"><?php echo wp_kses_post( __( 'Also pushed to the dataLayer for GTM: <code>dgwc_chat_open</code> and <code>dgwc_lead</code>. If GTM already fires this conversion, leave this switch off to avoid double counting.', 'digitum-whatsapp-chat' ) ); ?></p>
					</div>
				</div>

				<div class="acard">
					<div class="arow">
						<div class="atext"><h3><?php esc_html_e( 'WhatsApp button references', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'When the page URL has a gclid (Google Ads visit), every click on a wa.me / WhatsApp link (hero buttons, menus, text links) gets a new unique code, e.g. REF-KM3P. The code is added to the end of the prefilled message and the click is saved under WhatsApp clicks with the GCLID. Without a gclid in the page URL, only the prefilled message is sent and nothing is logged.', 'digitum-whatsapp-chat' ); ?></p></div>
						<?php self::toggle( 'ref_enabled', 'WhatsApp button references', $s ); ?>
					</div>
					<div class="sub" id="ref-box">
						<div class="f2">
							<?php self::text( 'ref_prefix', 'Code prefix', $s, array( 'mono' => true, 'ph' => 'REF', 'help' => 'Capital letters and digits, up to 10. Codes look like REF-KM3P (4 random capital letters/digits).' ) ); ?>
							<?php self::text( 'ref_label', 'Label before the code', $s, array( 'mono' => true, 'ph' => 'Ref:' ) ); ?>
						</div>
						<?php self::text( 'ref_text', 'Message when the link has no prefilled text', $s, array( 'help' => 'Links that already have <code>?text=…</code> keep their own message; the reference is added after it. Links without text (like <code>https://wa.me/971501234567</code>) use this message.' ) ); ?>
						<p class="help" id="ref-example"></p>
					</div>
				</div>

				<div class="acard">
					<div class="ahead"><h3><?php esc_html_e( 'Google Sheet', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Each WhatsApp click with a Ref code is also added to your sheet: GCLID, Ref Code, UTC and Pk Time (12-hour clock, e.g. 19 Oct 2026, 1:05 PM). The other columns stay free for your team.', 'digitum-whatsapp-chat' ); ?></p></div>
					<ol class="steps">
						<li><?php echo wp_kses_post( __( 'In the sheet, row 1 must have the headers <b>GCLID</b>, <b>Ref Code</b>, <b>UTC</b> and <b>Pk Time</b> (any order, other columns are fine).', 'digitum-whatsapp-chat' ) ); ?></li>
						<li><?php echo wp_kses_post( __( 'Open <b>Extensions → Apps Script</b>, delete what is there, paste the script below and press <b>Save</b>.', 'digitum-whatsapp-chat' ) ); ?></li>
						<li><?php echo wp_kses_post( __( '<b>Deploy → New deployment</b> → type <b>Web app</b>. Execute as: <b>Me</b>. Who has access: <b>Anyone</b>. Press Deploy and allow access with your Google account.', 'digitum-whatsapp-chat' ) ); ?></li>
						<li><?php echo wp_kses_post( __( 'Copy the <b>Web app URL</b> (ends with <code>/exec</code>), paste it below, press <b>Send test row</b>, then <b>Save settings</b>.', 'digitum-whatsapp-chat' ) ); ?></li>
					</ol>
					<div class="fld"><label class="lbl" for="sheet-script"><?php esc_html_e( 'Script for the sheet', 'digitum-whatsapp-chat' ); ?> <button type="button" class="ab ghost small" id="copy-script"><?php esc_html_e( 'Copy', 'digitum-whatsapp-chat' ); ?></button></label>
						<textarea class="ai mono" id="sheet-script" rows="8" readonly><?php echo esc_textarea( self::sheet_script( DGWC_Settings::sheet_secret() ) ); ?></textarea>
						<p class="help"><?php esc_html_e( 'It contains a secret key so only this website can add rows. Keep it private.', 'digitum-whatsapp-chat' ); ?></p></div>
					<div class="fld"><label class="lbl" for="s-sheet_url"><?php esc_html_e( 'Web app URL', 'digitum-whatsapp-chat' ); ?></label>
						<div class="inrow">
							<input class="ai mono" id="s-sheet_url" name="<?php echo esc_attr( self::n( 'sheet_url' ) ); ?>" type="url" value="<?php echo esc_attr( $s['sheet_url'] ); ?>" placeholder="https://script.google.com/macros/s/…/exec">
							<button type="button" class="ab" id="test-sheet"><?php esc_html_e( 'Send test row', 'digitum-whatsapp-chat' ); ?></button>
						</div>
						<p class="help" id="test-sheet-msg" hidden></p>
						<p class="help"><?php esc_html_e( 'Leave empty to stop sending to the sheet. Clicks are always kept in the WhatsApp clicks tab too.', 'digitum-whatsapp-chat' ); ?></p></div>
				</div>
			</section>
			<p class="formsave"><button type="submit" class="ab primary"><?php esc_html_e( 'Save settings', 'digitum-whatsapp-chat' ); ?></button></p>
		</form>

		<!-- LEADS (outside the settings form) -->
		<section class="tabp tabp-wide" data-panel="leads"<?php echo $hidden( 'leads' ); // phpcs:ignore ?>>
			<div class="acard">
				<div class="arow arow-top">
					<div class="atext"><h3><?php esc_html_e( 'Chat form leads', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'Last 100 submissions from the chat popup form, with their GHL webhook result. Kept here so no lead is lost if GHL is down. WhatsApp button clicks are in their own tab.', 'digitum-whatsapp-chat' ); ?></p></div>
					<?php if ( $leads ) : ?>
					<div class="mact">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dgwc_export_leads"><?php wp_nonce_field( 'dgwc_export_leads' ); ?><button class="ab" type="submit"><?php esc_html_e( 'Export CSV', 'digitum-whatsapp-chat' ); ?></button></form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Clear the lead log?');"><input type="hidden" name="action" value="dgwc_clear_leads"><?php wp_nonce_field( 'dgwc_clear_leads' ); ?><button class="ab ghost" type="submit"><?php esc_html_e( 'Clear', 'digitum-whatsapp-chat' ); ?></button></form>
					</div>
					<?php endif; ?>
				</div>
				<?php if ( ! $leads ) : ?>
					<p class="empty"><?php esc_html_e( 'No submissions yet. Send a test from the chat on your site and it will appear here.', 'digitum-whatsapp-chat' ); ?></p>
				<?php else : ?>
				<div class="tablewrap">
					<table class="ltable">
						<thead><tr><th><?php esc_html_e( 'Date', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Name', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Phone', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Page', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Click ID', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'GHL', 'digitum-whatsapp-chat' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $leads as $l ) : ?>
							<tr>
								<td class="nowrap"><?php echo esc_html( wp_date( 'j M, H:i', (int) $l['time'] ) ); ?></td>
								<td><?php echo esc_html( $l['name'] ); ?></td>
								<td class="mono nowrap"><a href="<?php echo esc_url( 'https://wa.me/' . preg_replace( '/\D/', '', $l['phone'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $l['phone'] ); ?></a></td>
								<td class="trunc" title="<?php echo esc_attr( $l['page'] ); ?>"><?php echo esc_html( preg_replace( '#^https?://[^/]+#', '', $l['page'] ) ? preg_replace( '#^https?://[^/]+#', '', $l['page'] ) : '/' ); ?></td>
								<td class="mono trunc" title="<?php echo esc_attr( $l['gclid'] ); ?>"><?php echo $l['gclid'] ? esc_html( $l['gclid'] ) : '<span class="dim">—</span>'; ?></td>
								<td><span class="vpill <?php echo 'sent' === $l['status'] ? '' : 'bad'; ?>" title="<?php echo esc_attr( $l['detail'] ); ?>"><?php echo 'sent' === $l['status'] ? esc_html__( 'Sent', 'digitum-whatsapp-chat' ) : esc_html__( 'Failed', 'digitum-whatsapp-chat' ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
		</section>

		<!-- WHATSAPP CLICKS (outside the settings form) -->
		<section class="tabp tabp-wide" data-panel="clicks"<?php echo $hidden( 'clicks' ); // phpcs:ignore ?>>
			<div class="acard">
				<div class="arow arow-top">
					<div class="atext"><h3><?php esc_html_e( 'WhatsApp clicks', 'digitum-whatsapp-chat' ); ?></h3><p><?php esc_html_e( 'One row per WhatsApp button click from a page with a gclid in its URL. Find the Ref code from the customer\'s WhatsApp message here to see the GCLID of their ad click.', 'digitum-whatsapp-chat' ); ?></p></div>
					<?php if ( $ctotal ) : ?>
					<div class="mact">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dgwc_export_clicks"><input type="hidden" name="q" value="<?php echo esc_attr( $cq ); ?>"><?php wp_nonce_field( 'dgwc_export_clicks' ); ?><button class="ab" type="submit"><?php echo '' === $cq ? esc_html__( 'Export CSV', 'digitum-whatsapp-chat' ) : esc_html__( 'Export results', 'digitum-whatsapp-chat' ); ?></button></form>
						<?php if ( $s['sheet_url'] && ( $unsent = DGWC_Clicks::unsent_count() ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dgwc_resend_sheet"><?php wp_nonce_field( 'dgwc_resend_sheet' ); ?><button class="ab" type="submit"><?php echo esc_html( sprintf( 'Send %d unsent to sheet', $unsent ) ); ?></button></form>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Delete every WhatsApp click row? Codes already sent by customers can no longer be matched.');"><input type="hidden" name="action" value="dgwc_clear_clicks"><?php wp_nonce_field( 'dgwc_clear_clicks' ); ?><button class="ab ghost" type="submit"><?php esc_html_e( 'Clear', 'digitum-whatsapp-chat' ); ?></button></form>
					</div>
					<?php endif; ?>
				</div>
				<?php if ( empty( $s['ref_enabled'] ) ) : ?>
					<div class="note warn"><?php esc_html_e( 'WhatsApp button references are switched off (Tracking tab), so new clicks are not being logged.', 'digitum-whatsapp-chat' ); ?></div>
				<?php endif; ?>
				<?php if ( ! $ctotal ) : ?>
					<p class="empty"><?php esc_html_e( 'No clicks yet. Open your site in a private window, click a WhatsApp button, and the code will appear here.', 'digitum-whatsapp-chat' ); ?></p>
				<?php else : ?>
				<form class="csearch" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="tab" value="clicks">
					<input class="ai mono" type="search" name="q" value="<?php echo esc_attr( $cq ); ?>" placeholder="<?php esc_attr_e( 'Search a Ref code or GCLID, e.g. REF-KM3P', 'digitum-whatsapp-chat' ); ?>" aria-label="<?php esc_attr_e( 'Search clicks', 'digitum-whatsapp-chat' ); ?>">
					<button class="ab" type="submit"><?php esc_html_e( 'Search', 'digitum-whatsapp-chat' ); ?></button>
					<?php if ( '' !== $cq ) : ?><a class="ab ghost" href="<?php echo esc_url( self::page_url( array( 'tab' => 'clicks' ) ) ); ?>"><?php esc_html_e( 'Show all', 'digitum-whatsapp-chat' ); ?></a><?php endif; ?>
					<span class="cfound"><?php echo esc_html( sprintf( /* translators: %d rows */ _n( '%d click', '%d clicks', $cfound, 'digitum-whatsapp-chat' ), $cfound ) ); ?></span>
				</form>
					<?php if ( ! $clicks ) : ?>
					<p class="empty"><?php esc_html_e( 'No click matches that search.', 'digitum-whatsapp-chat' ); ?></p>
					<?php else : ?>
				<div class="tablewrap">
					<table class="ltable">
						<thead><tr><th><?php esc_html_e( 'Code', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'GCLID', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Click time', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Button', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Page', 'digitum-whatsapp-chat' ); ?></th><th><?php esc_html_e( 'Sheet', 'digitum-whatsapp-chat' ); ?></th></tr></thead>
						<tbody>
						<?php
						foreach ( $clicks as $c ) :
							$cid  = $c['gclid'] ? $c['gclid'] : ( $c['gbraid'] ? 'gbraid:' . $c['gbraid'] : ( $c['wbraid'] ? 'wbraid:' . $c['wbraid'] : '' ) );
							$path = preg_replace( '#^https?://[^/]+#', '', $c['page_url'] );
							$utm  = $c['utm_source'] ? trim( $c['utm_source'] . ' / ' . $c['utm_medium'] . ' / ' . $c['utm_campaign'], ' /' ) : '';
							?>
							<tr>
								<td class="mono nowrap"><b><?php echo esc_html( $c['code'] ); ?></b></td>
								<td class="mono trunc" title="<?php echo esc_attr( $cid . ( $utm ? ' · ' . $utm : '' ) ); ?>"><?php echo $cid ? esc_html( $cid ) : '<span class="dim">—</span>'; ?></td>
								<td class="nowrap"><?php echo esc_html( wp_date( 'j M Y, H:i', DGWC_Clicks::ts( $c['created_at'] ) ) ); ?></td>
								<td class="trunc" title="<?php echo esc_attr( $c['button'] ); ?>"><?php echo esc_html( $c['button'] ); ?></td>
								<td class="trunc" title="<?php echo esc_attr( $c['page_url'] ); ?>"><?php echo esc_html( $path ? $path : '/' ); ?></td>
								<td class="nowrap"><?php if ( 'sent' === $c['sheet_status'] ) : ?><span class="vpill" title="<?php echo esc_attr( $c['sheet_detail'] ); ?>"><?php esc_html_e( 'Sent', 'digitum-whatsapp-chat' ); ?></span><?php elseif ( 'failed' === $c['sheet_status'] ) : ?><span class="vpill bad" title="<?php echo esc_attr( $c['sheet_detail'] ); ?>"><?php esc_html_e( 'Failed', 'digitum-whatsapp-chat' ); ?></span><?php else : ?><span class="dim">—</span><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
						<?php if ( $cpages > 1 ) : ?>
					<div class="cpager">
							<?php if ( $cpage > 1 ) : ?><a class="ab ghost" href="<?php echo esc_url( self::page_url( array( 'tab' => 'clicks', 'q' => $cq, 'cp' => $cpage - 1 ) ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'digitum-whatsapp-chat' ); ?></a><?php endif; ?>
						<span><?php echo esc_html( sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'digitum-whatsapp-chat' ), $cpage, $cpages ) ); ?></span>
							<?php if ( $cpage < $cpages ) : ?><a class="ab ghost" href="<?php echo esc_url( self::page_url( array( 'tab' => 'clicks', 'q' => $cq, 'cp' => $cpage + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'digitum-whatsapp-chat' ); ?> &rarr;</a><?php endif; ?>
					</div>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>

		<aside class="pvw" aria-label="<?php esc_attr_e( 'Live preview', 'digitum-whatsapp-chat' ); ?>">
			<div class="pvw-h"><b><?php esc_html_e( 'Live preview', 'digitum-whatsapp-chat' ); ?></b><span><?php esc_html_e( 'Updates as you edit', 'digitum-whatsapp-chat' ); ?></span></div>
			<div class="pvw-stage">
				<div class="pv-win" id="pv-win">
					<div class="head">
						<div class="av" style="width:38px;height:38px"><img id="pv-av" src="<?php echo esc_url( $avatar ); ?>" alt=""><span class="dot"></span></div>
						<div class="who"><b><span id="pv-name"></span><svg id="pv-ver" class="verified" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 .8l1.8 1.3 2.2-.1.7 2.1 1.8 1.3-.7 2.1.7 2.1-1.8 1.3-.7 2.1-2.2-.1L8 15.2l-1.8-1.3-2.2.1-.7-2.1L1.5 10.6l.7-2.1-.7-2.1 1.8-1.3.7-2.1 2.2.1z" fill="#25d366"/><path d="M5 8.2l2 2 4-4" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></b><span id="pv-sub"></span></div>
						<svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</div>
					<div class="body" id="pv-body">
						<div class="pv-note" id="pv-notice"></div>
						<div class="msg in tail"><span class="sender" id="pv-sender"></span><span id="pv-welcome"></span><span class="meta">10:42</span></div>
						<div class="formb" id="pv-form">
							<div class="inp pv-ph"><?php esc_html_e( 'Your name', 'digitum-whatsapp-chat' ); ?></div>
							<div class="inp pv-ph pv-tel"><span class="iti__flag" id="pv-flag"></span><span class="pv-caret"></span><span id="pv-cc"></span><span class="pv-telph"><?php esc_html_e( 'WhatsApp number', 'digitum-whatsapp-chat' ); ?></span></div>
							<div class="consent"><span class="pv-check" id="pv-check"></span><span id="pv-consent"></span></div>
						</div>
						<div class="send" id="pv-btn"></div>
						<div class="pv-iframe" id="pv-iframe"><span><?php esc_html_e( 'HighLevel form', 'digitum-whatsapp-chat' ); ?></span><em id="pv-h"></em></div>
					</div>
					<div class="foot" id="pv-foot"><?php esc_html_e( 'Powered by', 'digitum-whatsapp-chat' ); ?> <b id="pv-powered"></b></div>
				</div>
				<div class="pv-row" id="pv-row">
					<div class="pv-teaser" id="pv-teaser"></div>
					<div class="pv-fab" id="pv-fab"><?php echo self::wa_icon( 27 ); // phpcs:ignore ?><span class="pv-badge" id="pv-badge">1</span></div>
				</div>
				<div class="pv-off" id="pv-off" hidden><b><?php esc_html_e( 'Widget is off', 'digitum-whatsapp-chat' ); ?></b><span><?php esc_html_e( 'Visitors won\'t see the button.', 'digitum-whatsapp-chat' ); ?></span></div>
			</div>
			<a class="ab ghost wide" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the website to try it', 'digitum-whatsapp-chat' ); ?></a>
		</aside>
	</div>

	<div class="howto"><b><?php esc_html_e( 'How it works.', 'digitum-whatsapp-chat' ); ?></b> <?php esc_html_e( 'The chat card opens only when the button is clicked. With the built-in form, details go from your server to the GHL webhook, the conversion fires on the real submit, and the visitor continues on WhatsApp. With a GHL embedded form, HighLevel handles the submission and any redirect; the plugin listens for the form\'s submit message to fire the conversion.', 'digitum-whatsapp-chat' ); ?></div>

	<div class="savebar" id="savebar" hidden>
		<span><?php esc_html_e( 'You have unsaved changes', 'digitum-whatsapp-chat' ); ?></span>
		<button type="button" class="ab ghost" id="discard"><?php esc_html_e( 'Discard', 'digitum-whatsapp-chat' ); ?></button>
		<button type="submit" form="aform" class="ab primary" id="save"><?php esc_html_e( 'Save settings', 'digitum-whatsapp-chat' ); ?></button>
	</div>
</div>
		<?php
	}
}
