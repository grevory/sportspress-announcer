<?php
/**
 * Settings page: Settings → SportsPress Announcer.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Settings {

	// ── Discord ───────────────────────────────────────────────────────────────
	private const OPTION_WEBHOOK         = 'spa_discord_webhook_url';
	public const  OPTION_DISCORD_ENABLED = 'spa_discord_enabled';

	// ── Facebook ──────────────────────────────────────────────────────────────
	public const OPTION_FACEBOOK_ENABLED  = 'spa_facebook_enabled';
	public const OPTION_FACEBOOK_TEMPLATE = 'spa_facebook_template';

	public const DEFAULT_FACEBOOK_TEMPLATE = '{home} {home_score} – {away_score} {away} ({time}) @ {venue} | {competition}';

	// ── Digest ────────────────────────────────────────────────────────────────
	public const OPTION_UPCOMING_TEMPLATE = 'spa_upcoming_template';

	public const DEFAULT_UPCOMING_TEMPLATE = '{home} vs {away}';

	private const MENU_SLUG = 'sportspress-announcer';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_spa_test_webhook', [ $this, 'ajax_test_webhook' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'SportsPress Announcer', 'sportspress-announcer' ),
			__( 'SportsPress Announcer', 'sportspress-announcer' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {

		// ── Discord section ───────────────────────────────────────────────────
		register_setting( 'spa_settings_group', self::OPTION_WEBHOOK, [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_webhook_url' ],
			'default'           => '',
		] );

		register_setting( 'spa_settings_group', self::OPTION_DISCORD_ENABLED, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		] );

		add_settings_section( 'spa_section_discord', __( 'Discord', 'sportspress-announcer' ), '__return_false', self::MENU_SLUG );

		add_settings_field( self::OPTION_DISCORD_ENABLED, __( 'Announcements', 'sportspress-announcer' ),
			[ $this, 'render_discord_enabled_field' ], self::MENU_SLUG, 'spa_section_discord' );

		add_settings_field( self::OPTION_WEBHOOK, __( 'Webhook URL', 'sportspress-announcer' ),
			[ $this, 'render_webhook_field' ], self::MENU_SLUG, 'spa_section_discord' );

		// ── Facebook section ──────────────────────────────────────────────────
		register_setting( 'spa_settings_group', self::OPTION_FACEBOOK_ENABLED, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		register_setting( 'spa_settings_group', self::OPTION_FACEBOOK_TEMPLATE, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => self::DEFAULT_FACEBOOK_TEMPLATE,
		] );

		add_settings_section( 'spa_section_facebook', __( 'Facebook', 'sportspress-announcer' ), '__return_false', self::MENU_SLUG );

		add_settings_field( self::OPTION_FACEBOOK_ENABLED, __( 'Share Button', 'sportspress-announcer' ),
			[ $this, 'render_facebook_enabled_field' ], self::MENU_SLUG, 'spa_section_facebook' );

		add_settings_field( self::OPTION_FACEBOOK_TEMPLATE, __( 'Result Template', 'sportspress-announcer' ),
			[ $this, 'render_facebook_template_field' ], self::MENU_SLUG, 'spa_section_facebook' );

		// ── Digest section ────────────────────────────────────────────────────
		register_setting( 'spa_settings_group', self::OPTION_UPCOMING_TEMPLATE, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => self::DEFAULT_UPCOMING_TEMPLATE,
		] );

		add_settings_section( 'spa_section_digest', __( 'Digest', 'sportspress-announcer' ),
			[ $this, 'render_digest_section_intro' ], self::MENU_SLUG );

		add_settings_field( self::OPTION_UPCOMING_TEMPLATE, __( 'Game Template', 'sportspress-announcer' ),
			[ $this, 'render_upcoming_template_field' ], self::MENU_SLUG, 'spa_section_digest' );

		add_settings_field( 'spa_upcoming_discord_send', __( 'Send to Discord', 'sportspress-announcer' ),
			[ $this, 'render_upcoming_discord_field' ], self::MENU_SLUG, 'spa_section_digest' );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	public function ajax_test_webhook(): void {
		check_ajax_referer( 'spa_test_webhook_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) );
		if ( empty( $url ) ) {
			wp_send_json_error( __( 'No webhook URL entered.', 'sportspress-announcer' ) );
		}

		if ( ! str_starts_with( $url, 'https://discord.com/api/webhooks/' ) ) {
			wp_send_json_error( __( 'That doesn\'t look like a Discord webhook URL.', 'sportspress-announcer' ) );
		}

		$payload = [
			'embeds' => [
				[
					'title'       => __( 'SportsPress Announcer', 'sportspress-announcer' ),
					'description' => __( 'Webhook connection successful.', 'sportspress-announcer' ),
					'color'       => 0x57F287,
				],
			],
		];

		$discord = new SPA_Webhook_Discord( $url );
		$result  = $discord->send( $payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	// ── Sanitize ──────────────────────────────────────────────────────────────

	public function sanitize_webhook_url( string $value ): string {
		$value = trim( $value );
		if ( empty( $value ) ) {
			return '';
		}
		if ( ! str_starts_with( $value, 'https://discord.com/api/webhooks/' ) ) {
			add_settings_error(
				self::OPTION_WEBHOOK,
				'spa_invalid_webhook',
				__( 'That doesn\'t look like a Discord webhook URL. It should start with https://discord.com/api/webhooks/', 'sportspress-announcer' )
			);
			return get_option( self::OPTION_WEBHOOK, '' );
		}
		return esc_url_raw( $value );
	}

	// ── Field renderers ───────────────────────────────────────────────────────

	public function render_discord_enabled_field(): void {
		$enabled = (bool) get_option( self::OPTION_DISCORD_ENABLED, true );
		?>
		<label>
			<input
				type="checkbox"
				id="<?php echo esc_attr( self::OPTION_DISCORD_ENABLED ); ?>"
				name="<?php echo esc_attr( self::OPTION_DISCORD_ENABLED ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Send automatic Discord announcements when event results are published', 'sportspress-announcer' ); ?>
		</label>
		<?php
	}

	public function render_webhook_field(): void {
		$value = get_option( self::OPTION_WEBHOOK, '' );
		?>
		<input
			type="url"
			id="<?php echo esc_attr( self::OPTION_WEBHOOK ); ?>"
			name="<?php echo esc_attr( self::OPTION_WEBHOOK ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="https://discord.com/api/webhooks/…"
		/>
		<p class="description">
			<?php
			printf(
				wp_kses(
					/* translators: %s: URL to Discord docs */
					__( 'Paste your Discord channel\'s incoming webhook URL. <a href="%s" target="_blank" rel="noopener">How to create a webhook →</a>', 'sportspress-announcer' ),
					[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
				),
				'https://support.discord.com/hc/en-us/articles/228383668'
			);
			?>
		</p>
		<p>
			<button type="button" id="spa-test-webhook" class="button">
				<?php esc_html_e( 'Send Test Message', 'sportspress-announcer' ); ?>
			</button>
			<span id="spa-test-result" style="margin-left:8px;"></span>
		</p>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var btn    = document.getElementById( 'spa-test-webhook' );
			var result = document.getElementById( 'spa-test-result' );
			var input  = document.getElementById( '<?php echo esc_js( self::OPTION_WEBHOOK ); ?>' );
			if ( ! btn || ! result || ! input ) return;
			btn.addEventListener( 'click', function () {
				result.textContent = '<?php echo esc_js( __( 'Sending…', 'sportspress-announcer' ) ); ?>';
				result.style.color = '';
				btn.disabled = true;
				var data = new FormData();
				data.append( 'action', 'spa_test_webhook' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'spa_test_webhook_nonce' ) ); ?>' );
				data.append( 'webhook_url', input.value );
				fetch( ajaxurl, { method: 'POST', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( json.success ) {
							result.textContent = '<?php echo esc_js( __( '✓ Test message sent!', 'sportspress-announcer' ) ); ?>';
							result.style.color = '#46b450';
						} else {
							result.textContent = '<?php echo esc_js( __( '✗ Error: ', 'sportspress-announcer' ) ); ?>' + ( json.data || '' );
							result.style.color = '#dc3232';
						}
					} )
					.catch( function () {
						result.textContent = '<?php echo esc_js( __( '✗ Request failed.', 'sportspress-announcer' ) ); ?>';
						result.style.color = '#dc3232';
					} )
					.finally( function () {
						btn.disabled = false;
					} );
			} );
		} );
		</script>
		<?php
	}

	public function render_facebook_enabled_field(): void {
		$enabled = (bool) get_option( self::OPTION_FACEBOOK_ENABLED, false );
		?>
		<label>
			<input
				type="checkbox"
				id="<?php echo esc_attr( self::OPTION_FACEBOOK_ENABLED ); ?>"
				name="<?php echo esc_attr( self::OPTION_FACEBOOK_ENABLED ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Show a "Share to Facebook" button in the admin results digest', 'sportspress-announcer' ); ?>
		</label>
		<div style="margin-top:6px;">
			<a href="#" id="spa-template-toggle" style="text-decoration:none;"><?php esc_html_e( 'Customize template ▸', 'sportspress-announcer' ); ?></a>
		</div>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var toggle   = document.getElementById( 'spa-template-toggle' );
			var textarea = document.getElementById( '<?php echo esc_js( self::OPTION_FACEBOOK_TEMPLATE ); ?>' );
			if ( ! toggle || ! textarea ) return;
			var row = textarea.closest( 'tr' );
			if ( ! row ) return;
			row.style.display = 'none';
			toggle.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var open = row.style.display !== 'none';
				row.style.display = open ? 'none' : '';
				toggle.textContent = open
					? '<?php echo esc_js( __( 'Customize template ▸', 'sportspress-announcer' ) ); ?>'
					: '<?php echo esc_js( __( 'Customize template ▾', 'sportspress-announcer' ) ); ?>';
			} );
		} );
		</script>
		<?php
	}

	public function render_facebook_template_field(): void {
		$value = get_option( self::OPTION_FACEBOOK_TEMPLATE, self::DEFAULT_FACEBOOK_TEMPLATE );
		?>
		<textarea
			id="<?php echo esc_attr( self::OPTION_FACEBOOK_TEMPLATE ); ?>"
			name="<?php echo esc_attr( self::OPTION_FACEBOOK_TEMPLATE ); ?>"
			rows="3"
			class="large-text"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Available placeholders: {home} {away} {home_score} {away_score} {competition} {venue} {time} {date}', 'sportspress-announcer' ); ?>
		</p>
		<?php
	}

	public function render_digest_section_intro(): void {
		?>
		<p class="description"><?php esc_html_e( 'Upcoming games for the next 7 days appear as an admin notice with a copy button. Use the button below to push the schedule to Discord on demand.', 'sportspress-announcer' ); ?></p>
		<?php
	}

	public function render_upcoming_template_field(): void {
		$value = get_option( self::OPTION_UPCOMING_TEMPLATE, self::DEFAULT_UPCOMING_TEMPLATE );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( self::OPTION_UPCOMING_TEMPLATE ); ?>"
			name="<?php echo esc_attr( self::OPTION_UPCOMING_TEMPLATE ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Available placeholders: {home} {away} {competition} {venue} {time} {date}', 'sportspress-announcer' ); ?>
		</p>
		<?php
	}

	public function render_upcoming_discord_field(): void {
		$webhook_url = get_option( self::OPTION_WEBHOOK, '' );
		if ( empty( $webhook_url ) ) {
			?>
			<p class="description"><?php esc_html_e( 'Configure a Discord webhook URL above to enable this.', 'sportspress-announcer' ); ?></p>
			<?php
			return;
		}
		?>
		<button type="button" id="spa-send-upcoming" class="button">
			<?php esc_html_e( 'Send upcoming games to Discord', 'sportspress-announcer' ); ?>
		</button>
		<span id="spa-upcoming-result" style="margin-left:8px;"></span>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var btn    = document.getElementById( 'spa-send-upcoming' );
			var result = document.getElementById( 'spa-upcoming-result' );
			if ( ! btn || ! result ) return;
			btn.addEventListener( 'click', function () {
				result.textContent = '<?php echo esc_js( __( 'Sending…', 'sportspress-announcer' ) ); ?>';
				result.style.color = '';
				btn.disabled = true;
				var data = new FormData();
				data.append( 'action', 'spa_send_upcoming' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'spa_send_upcoming_nonce' ) ); ?>' );
				fetch( ajaxurl, { method: 'POST', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( json.success ) {
							result.textContent = '<?php echo esc_js( __( '✓ Schedule sent!', 'sportspress-announcer' ) ); ?>';
							result.style.color = '#46b450';
						} else {
							result.textContent = '<?php echo esc_js( __( '✗ Error: ', 'sportspress-announcer' ) ); ?>' + ( json.data || '' );
							result.style.color = '#dc3232';
						}
					} )
					.catch( function () {
						result.textContent = '<?php echo esc_js( __( '✗ Request failed.', 'sportspress-announcer' ) ); ?>';
						result.style.color = '#dc3232';
					} )
					.finally( function () {
						btn.disabled = false;
					} );
			} );
		} );
		</script>
		<?php
	}

	// ── Page ──────────────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'spa_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'sportspress-announcer' ) );
				?>
			</form>
		</div>
		<?php
	}
}
