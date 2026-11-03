<?php
/**
 * Front-end widget output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGWC_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 ); // Before footer scripts (priority 20).
	}

	/**
	 * Decide whether the widget should appear on the current request.
	 */
	public static function should_display() {
		$s = DGWC_Settings::get();
		$show = true;

		if ( empty( $s['enabled'] ) || is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			$show = false;
		}
		// Page builders' editor canvases.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$show = false;
		}
		if ( $show && 'iframe' === $s['form_source'] && '' === DGWC_Settings::parse_embed( $s['embed'] ) ) {
			$show = false; // Never show an empty chat card.
		}
		if ( $show && 'pages' === $s['visibility'] ) {
			$id   = (int) get_queried_object_id();
			$show = $id && ( is_singular() || is_front_page() || is_home() ) && in_array( $id, array_map( 'intval', (array) $s['page_ids'] ), true );
		}

		return (bool) apply_filters( 'dgwc_should_display', $show, $s );
	}

	/**
	 * WhatsApp button references run on every front-end page, independent of where the chat widget shows.
	 */
	public static function should_track_clicks() {
		$s    = DGWC_Settings::get();
		$show = ! empty( $s['ref_enabled'] ) && ! is_admin() && ! is_feed() && ! is_embed();
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$show = false;
		}
		return (bool) apply_filters( 'dgwc_track_wa_clicks', $show, $s );
	}

	public static function enqueue() {
		$s = DGWC_Settings::get();
		if ( self::should_track_clicks() ) {
			wp_enqueue_script( 'dgwc-wa-ref', DGWC_URL . 'assets/js/wa-ref.js', array(), DGWC_VERSION, true );
			wp_localize_script(
				'dgwc-wa-ref',
				'DGWC_REF',
				array(
					'ajax'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'action'        => DGWC_Clicks::AJAX,
					'reserveAction' => DGWC_Clicks::AJAX_RESERVE,
					'prefix'      => $s['ref_prefix'],
					'label'       => $s['ref_label'],
					'defaultText'   => $s['ref_text'],
					'wa'            => DGWC_Settings::wa_digits( $s ),
				)
			);
		}

		if ( ! self::should_display() ) {
			return;
		}
		wp_enqueue_style( 'dgwc-widget', DGWC_URL . 'assets/css/widget.css', array(), DGWC_VERSION );

		$deps = array();
		if ( 'builtin' === $s['form_source'] ) {
			// Phone field with country flags (intl-tel-input, MIT) + time-zone based country detection.
			wp_enqueue_style( 'dgwc-iti', DGWC_URL . 'assets/vendor/intl-tel-input/css/intlTelInput.min.css', array(), '25.10.12' );
			wp_register_script( 'dgwc-iti', DGWC_URL . 'assets/vendor/intl-tel-input/js/intlTelInput.min.js', array(), '25.10.12', true );
			wp_register_script( 'dgwc-tz', DGWC_URL . 'assets/js/tz-country.js', array(), DGWC_VERSION, true );
			$deps = array( 'dgwc-iti', 'dgwc-tz' );
		}
		wp_register_script( 'dgwc-widget', DGWC_URL . 'assets/js/widget.js', $deps, DGWC_VERSION, true );
		wp_localize_script( 'dgwc-widget', 'DGWC', self::config( $s ) );
		wp_enqueue_script( 'dgwc-widget' );
	}

	public static function config( $s ) {
		$conv_ok = ! empty( $s['conv_enabled'] ) && $s['conv_id'] && $s['conv_label'];
		return array(
			'rest'        => esc_url_raw( rest_url( DGWC_Submit::REST_NS . '/lead' ) ),
			'ajax'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'ajaxAction'  => DGWC_Submit::AJAX,
			'source'      => $s['form_source'],
			'name'        => $s['business_name'],
			'status'      => $s['heading'],
			'teaser'      => (int) $s['teaser'],
			'teaserDelay' => (int) $s['teaser_delay'],
			'after'       => $s['after_submit'],
			'wa'          => DGWC_Settings::wa_digits( $s ),
			'waText'      => $s['wa_text'],
			'waReply'     => $s['wa_reply'],
			'thanks'      => $s['thanks_text'],
			'defaultCc'   => $s['default_cc'],
			'defaultIso'  => strtolower( DGWC_Settings::iso_for( $s['default_cc'] ) ),
			'autoCountry' => (int) $s['cc_auto'],
			'itiUtils'    => DGWC_URL . 'assets/vendor/intl-tel-input/js/utils.js?ver=25.10.12',
			'embed'       => 'iframe' === $s['form_source'] ? DGWC_Settings::parse_embed( $s['embed'] ) : '',
			'gclidKey'    => $s['gclid_key'],
			'conv'        => $conv_ok ? 1 : 0,
			'convTrigger' => $s['conv_trigger'],
			'convId'      => $conv_ok ? $s['conv_id'] : '',
			'convLabel'   => $conv_ok ? $s['conv_label'] : '',
			'enhanced'    => (int) $s['enhanced'],
			'i18n'        => array(
				'needName'    => __( 'your name', 'digitum-whatsapp-chat' ),
				'needPhone'   => __( 'a valid WhatsApp number', 'digitum-whatsapp-chat' ),
				'search'      => __( 'Search country', 'digitum-whatsapp-chat' ),
				'pleaseAdd'   => __( 'Please add %s.', 'digitum-whatsapp-chat' ),
				'and'         => __( 'and', 'digitum-whatsapp-chat' ),
				'needConsent' => __( 'Please tick the box so we can message you.', 'digitum-whatsapp-chat' ),
				'sending'     => __( 'Sending…', 'digitum-whatsapp-chat' ),
				'typing'      => __( 'typing…', 'digitum-whatsapp-chat' ),
				'openWa'      => __( 'Open WhatsApp', 'digitum-whatsapp-chat' ),
				'netError'    => __( 'Connection problem. Please try again.', 'digitum-whatsapp-chat' ),
			),
		);
	}

	private static function icon_chat() {
		return '<svg class="dgwc-ic-chat" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16 3.5C9 3.5 3.4 8.9 3.4 15.6c0 2.3.7 4.5 1.9 6.3L4 28l6.3-1.7c1.7.9 3.6 1.4 5.7 1.4 7 0 12.6-5.4 12.6-12.1S23 3.5 16 3.5z" fill="none" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/><path d="M12.4 10.2c-.3-.6-.6-.6-.9-.6h-.8c-.3 0-.7.1-1 .5-.4.4-1.3 1.3-1.3 3.1s1.4 3.6 1.5 3.8c.2.3 2.7 4.2 6.6 5.7 3.2 1.3 3.9 1 4.6.9.7-.1 2.3-.9 2.6-1.8.3-.9.3-1.7.2-1.8-.1-.2-.4-.3-.8-.5s-2.3-1.1-2.6-1.2c-.4-.1-.6-.2-.9.2-.3.4-1 1.2-1.2 1.5-.2.3-.5.3-.8.1-.4-.2-1.6-.6-3-1.9-1.1-1-1.9-2.2-2.1-2.6-.2-.4 0-.6.2-.8l.6-.7c.2-.2.3-.4.4-.7.1-.3.1-.5 0-.7z" fill="#fff"/></svg>';
	}

	public static function render() {
		if ( ! self::should_display() ) {
			return;
		}
		$s      = DGWC_Settings::get();
		$name   = $s['business_name'] ? $s['business_name'] : get_bloginfo( 'name' );
		$avatar = DGWC_Settings::avatar_url( $s );
		$bg     = DGWC_Settings::bg_url( $s );

		$classes = array( 'dgwc', 'dgwc-pos-' . $s['position'], 'dgwc-theme-' . $s['theme'], 'dgwc-bg-' . $s['bg'] );
		if ( empty( $s['pulse'] ) ) {
			$classes[] = 'dgwc-seen';
		}
		$style = $bg ? '--dgwc-wallpaper:url(' . esc_url( $bg ) . ')' : '';

		$avatar_html = function ( $size, $dot ) use ( $avatar ) {
			return '<span class="dgwc-av" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px"><img src="' . esc_url( $avatar ) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async">' . ( $dot ? '<span class="dgwc-dot"></span>' : '' ) . '</span>';
		};
		?>
<div id="dgwc" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $style ); ?>" data-nosnippet>
	<?php if ( ! empty( $s['teaser'] ) && '' !== trim( $s['teaser_text'] ) ) : ?>
	<div class="dgwc-teaser dgwc-gone" id="dgwc-teaser" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Open chat', 'digitum-whatsapp-chat' ); ?>">
		<?php echo $avatar_html( 44, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>
		<p><small><?php echo esc_html( $name ); ?></small><?php echo esc_html( $s['teaser_text'] ); ?></p>
		<button class="dgwc-x" id="dgwc-teaser-x" type="button" aria-label="<?php esc_attr_e( 'Dismiss greeting', 'digitum-whatsapp-chat' ); ?>"><svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
	</div>
	<?php endif; ?>

	<section class="dgwc-win" id="dgwc-win" role="dialog" aria-modal="false" aria-label="<?php echo esc_attr( sprintf( /* translators: %s business name */ __( 'Chat with %s', 'digitum-whatsapp-chat' ), $name ) ); ?>">
		<header class="dgwc-head">
			<?php echo $avatar_html( 42, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="dgwc-who">
				<b><span class="dgwc-bname"><?php echo esc_html( $name ); ?></span>
				<?php if ( ! empty( $s['verified'] ) ) : ?>
					<svg class="dgwc-verified" viewBox="0 0 16 16" aria-label="<?php esc_attr_e( 'Verified business', 'digitum-whatsapp-chat' ); ?>" role="img"><path d="M8 .8l1.8 1.3 2.2-.1.7 2.1 1.8 1.3-.7 2.1.7 2.1-1.8 1.3-.7 2.1-2.2-.1L8 15.2l-1.8-1.3-2.2.1-.7-2.1L1.5 10.6l.7-2.1-.7-2.1 1.8-1.3.7-2.1 2.2.1z" fill="#25d366"/><path d="M5 8.2l2 2 4-4" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<?php endif; ?></b>
				<span id="dgwc-status"><?php echo esc_html( $s['heading'] ); ?></span>
			</div>
			<button class="dgwc-hbtn" id="dgwc-min" type="button" aria-label="<?php esc_attr_e( 'Minimise chat', 'digitum-whatsapp-chat' ); ?>"><svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
		</header>

		<div class="dgwc-body" id="dgwc-body">
			<div class="dgwc-chip"><?php esc_html_e( 'Today', 'digitum-whatsapp-chat' ); ?></div>
			<?php if ( '' !== trim( $s['reply_notice'] ) ) : ?>
			<div class="dgwc-notice"><svg viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="6" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M7 3.8V7l2.2 1.4" stroke="currentColor" stroke-width="1.4" fill="none" stroke-linecap="round"/></svg><?php echo esc_html( $s['reply_notice'] ); ?></div>
			<?php endif; ?>
			<div class="dgwc-msg dgwc-in dgwc-tail"><span class="dgwc-sender"><?php echo esc_html( $name ); ?></span><span class="dgwc-text"><?php echo nl2br( esc_html( $s['welcome'] ) ); ?></span><span class="dgwc-meta" data-time></span></div>

			<?php if ( 'iframe' === $s['form_source'] ) : ?>
				<?php
				$h    = (int) $s['iframe_height'];
				$trim = min( (int) $s['iframe_trim'], $h - 100 );
				?>
				<div class="dgwc-iframe-wrap" style="height:<?php echo (int) ( $h - $trim ); ?>px">
					<iframe id="dgwc-iframe" title="<?php esc_attr_e( 'Contact form', 'digitum-whatsapp-chat' ); ?>" data-src="<?php echo esc_url( DGWC_Settings::parse_embed( $s['embed'] ) ); ?>" style="height:<?php echo (int) $h; ?>px" loading="lazy"></iframe>
				</div>
			<?php else : ?>
			<form class="dgwc-form" id="dgwc-form" novalidate>
				<div class="dgwc-card">
					<input class="dgwc-inp" id="dgwc-name" name="full_name" type="text" autocomplete="name" maxlength="80" placeholder="<?php esc_attr_e( 'Your name', 'digitum-whatsapp-chat' ); ?>" aria-label="<?php esc_attr_e( 'Your name', 'digitum-whatsapp-chat' ); ?>">
					<input class="dgwc-inp" id="dgwc-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" placeholder="<?php esc_attr_e( 'WhatsApp number', 'digitum-whatsapp-chat' ); ?>" aria-label="<?php esc_attr_e( 'WhatsApp number', 'digitum-whatsapp-chat' ); ?>">
					<div class="dgwc-hp" aria-hidden="true"><input type="text" name="website" id="dgwc-website" tabindex="-1" autocomplete="off"></div>
					<label class="dgwc-consent" for="dgwc-consent"><input type="checkbox" id="dgwc-consent" name="consent" <?php checked( ! empty( $s['consent_default'] ) ); ?>><span><?php echo esc_html( $s['consent_text'] ); ?></span></label>
					<div class="dgwc-err" id="dgwc-err" role="alert"></div>
				</div>
				<button class="dgwc-send" type="submit"><span class="dgwc-send-t"><?php echo esc_html( $s['button_text'] ? $s['button_text'] : __( 'Send message', 'digitum-whatsapp-chat' ) ); ?></span>
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.4l17.4-7.5c.8-.4.8-1.5 0-1.8L3.4 3.6c-.7-.3-1.4.3-1.3 1l.9 5.6c.1.5.5.9 1 .9l8 1-8 1c-.5 0-.9.4-1 .9l-.9 5.6c-.1.7.6 1.2 1.3.8z" fill="currentColor"/></svg>
				</button>
			</form>
			<?php endif; ?>
		</div>

		<?php if ( '' !== trim( $s['powered_by'] ) ) : ?>
		<div class="dgwc-foot"><?php esc_html_e( 'Powered by', 'digitum-whatsapp-chat' ); ?>
			<?php if ( $s['powered_url'] ) : ?>
				<a href="<?php echo esc_url( $s['powered_url'] ); ?>" target="_blank" rel="noopener"><b><?php echo esc_html( $s['powered_by'] ); ?></b></a>
			<?php else : ?>
				<b><?php echo esc_html( $s['powered_by'] ); ?></b>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</section>

	<button class="dgwc-fab" id="dgwc-fab" type="button" aria-label="<?php esc_attr_e( 'Open chat', 'digitum-whatsapp-chat' ); ?>" aria-expanded="false" aria-controls="dgwc-win">
		<?php echo self::icon_chat(); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
		<svg class="dgwc-ic-x" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/></svg>
		<?php if ( ! empty( $s['badge'] ) ) : ?><span class="dgwc-badge" id="dgwc-badge">1</span><?php endif; ?>
	</button>
</div>
		<?php
	}
}
