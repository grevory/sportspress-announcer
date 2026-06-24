<?php
/**
 * Settings page: Settings → SportsPress Announcer.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the SportsPress Announcer settings page.
 */
class SPA_Settings {

	// ── Discord ───────────────────────────────────────────────────────────────
	private const OPTION_WEBHOOK         = 'spa_discord_webhook_url';
	public const  OPTION_DISCORD_ENABLED = 'spa_discord_enabled';

	// ── Score column ─────────────────────────────────────────────────────────
	public const OPTION_SCORE_COLUMN  = 'spa_score_column';
	public const DEFAULT_SCORE_COLUMN = 'goals';

	// ── Facebook ──────────────────────────────────────────────────────────────
	public const OPTION_FACEBOOK_ENABLED  = 'spa_facebook_enabled';
	public const OPTION_FACEBOOK_TEMPLATE = 'spa_facebook_template';

	public const DEFAULT_FACEBOOK_TEMPLATE = '{home} {home_score} – {away_score} {away} ({time}) @ {venue} | {competition}';

	// ── Digest ────────────────────────────────────────────────────────────────
	public const OPTION_UPCOMING_TEMPLATE = 'spa_upcoming_template';

	public const DEFAULT_UPCOMING_TEMPLATE = '{home} vs {away}';

	// ── Digest schedule ───────────────────────────────────────────────────────
	// (option keys delegated to SPA_Digest_Scheduler)

	private const MENU_SLUG = 'sportspress-announcer';

	/**
	 * Register settings-page callbacks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_spa_test_webhook', array( $this, 'ajax_test_webhook' ) );
	}

	/**
	 * Add the plugin page to the WordPress Settings menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'SportsPress Announcer', 'sportspress-announcer' ),
			__( 'SportsPress Announcer', 'sportspress-announcer' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register plugin settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {

		// ── SportsPress section ───────────────────────────────────────────────
		register_setting(
			'spa_settings_group',
			self::OPTION_SCORE_COLUMN,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => self::DEFAULT_SCORE_COLUMN,
			)
		);

		add_settings_section( 'spa_section_sportspress', __( 'SportsPress', 'sportspress-announcer' ), '__return_false', self::MENU_SLUG );

		add_settings_field(
			self::OPTION_SCORE_COLUMN,
			__( 'Score Column', 'sportspress-announcer' ),
			array( $this, 'render_score_column_field' ),
			self::MENU_SLUG,
			'spa_section_sportspress'
		);

		// ── Digest section ────────────────────────────────────────────────────
		register_setting(
			'spa_settings_group',
			self::OPTION_UPCOMING_TEMPLATE,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => self::DEFAULT_UPCOMING_TEMPLATE,
			)
		);

		add_settings_section(
			'spa_section_digest',
			__( 'Digest', 'sportspress-announcer' ),
			array( $this, 'render_digest_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPTION_UPCOMING_TEMPLATE,
			__( 'Game Template', 'sportspress-announcer' ),
			array( $this, 'render_upcoming_template_field' ),
			self::MENU_SLUG,
			'spa_section_digest'
		);

		add_settings_field(
			'spa_upcoming_discord_send',
			__( 'Send to Discord', 'sportspress-announcer' ),
			array( $this, 'render_upcoming_discord_field' ),
			self::MENU_SLUG,
			'spa_section_digest'
		);

		register_setting(
			'spa_settings_group',
			SPA_Digest_Scheduler::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'spa_settings_group',
			SPA_Digest_Scheduler::OPTION_FREQUENCY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_frequency' ),
				'default'           => 'weekly',
			)
		);

		register_setting(
			'spa_settings_group',
			SPA_Digest_Scheduler::OPTION_DAY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_day' ),
				'default'           => 'monday',
			)
		);

		register_setting(
			'spa_settings_group',
			SPA_Digest_Scheduler::OPTION_TIME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_time' ),
				'default'           => '08:00',
			)
		);

		add_settings_field(
			'spa_digest_schedule',
			__( 'Auto-send', 'sportspress-announcer' ),
			array( $this, 'render_digest_schedule_field' ),
			self::MENU_SLUG,
			'spa_section_digest'
		);

		// ── Discord section ───────────────────────────────────────────────────
		register_setting(
			'spa_settings_group',
			self::OPTION_WEBHOOK,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_webhook_url' ),
				'default'           => '',
			)
		);

		register_setting(
			'spa_settings_group',
			self::OPTION_DISCORD_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		add_settings_section( 'spa_section_discord', __( 'Discord', 'sportspress-announcer' ), '__return_false', self::MENU_SLUG );

		add_settings_field(
			self::OPTION_DISCORD_ENABLED,
			__( 'Announcements', 'sportspress-announcer' ),
			array( $this, 'render_discord_enabled_field' ),
			self::MENU_SLUG,
			'spa_section_discord'
		);

		add_settings_field(
			self::OPTION_WEBHOOK,
			__( 'Webhook URL', 'sportspress-announcer' ),
			array( $this, 'render_webhook_field' ),
			self::MENU_SLUG,
			'spa_section_discord'
		);

		// ── Facebook section ──────────────────────────────────────────────────
		register_setting(
			'spa_settings_group',
			self::OPTION_FACEBOOK_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'spa_settings_group',
			self::OPTION_FACEBOOK_TEMPLATE,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => self::DEFAULT_FACEBOOK_TEMPLATE,
			)
		);

		add_settings_section( 'spa_section_facebook', __( 'Facebook', 'sportspress-announcer' ), '__return_false', self::MENU_SLUG );

		add_settings_field(
			self::OPTION_FACEBOOK_ENABLED,
			__( 'Share Button', 'sportspress-announcer' ),
			array( $this, 'render_facebook_enabled_field' ),
			self::MENU_SLUG,
			'spa_section_facebook'
		);

		add_settings_field(
			self::OPTION_FACEBOOK_TEMPLATE,
			__( 'Result Template', 'sportspress-announcer' ),
			array( $this, 'render_facebook_template_field' ),
			self::MENU_SLUG,
			'spa_section_facebook'
		);
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * Test a submitted Discord webhook URL.
	 *
	 * @return void
	 */
	public function ajax_test_webhook(): void {
		check_ajax_referer( 'spa_test_webhook_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) );
		if ( empty( $url ) ) {
			wp_send_json_error( __( 'No webhook URL entered.', 'sportspress-announcer' ) );
		}

		if ( 0 !== strpos( $url, 'https://discord.com/api/webhooks/' ) ) {
			wp_send_json_error( __( 'That doesn\'t look like a Discord webhook URL.', 'sportspress-announcer' ) );
		}

		$payload = array(
			'embeds' => array(
				array(
					'title'       => __( 'SportsPress Announcer', 'sportspress-announcer' ),
					'description' => __( 'Webhook connection successful.', 'sportspress-announcer' ),
					'color'       => 0x57F287,
				),
			),
		);

		$discord = new SPA_Webhook_Discord( $url );
		$result  = $discord->send( $payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	// ── Sanitize ──────────────────────────────────────────────────────────────

	/**
	 * Validate and sanitize a Discord webhook URL.
	 *
	 * @param string $value Submitted webhook URL.
	 *
	 * @return string
	 */
	public function sanitize_webhook_url( string $value ): string {
		$value = trim( $value );
		if ( empty( $value ) ) {
			return '';
		}
		if ( 0 !== strpos( $value, 'https://discord.com/api/webhooks/' ) ) {
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

	/**
	 * Render the Discord announcements toggle.
	 *
	 * @return void
	 */
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

	/**
	 * Render the Discord webhook URL field.
	 *
	 * @return void
	 */
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
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
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

	/**
	 * Render the Facebook sharing toggle.
	 *
	 * @return void
	 */
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

	/**
	 * Render the Facebook result template field.
	 *
	 * @return void
	 */
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

	/**
	 * Render the SportsPress score-column field.
	 *
	 * @return void
	 */
	public function render_score_column_field(): void {
		$value = get_option( self::OPTION_SCORE_COLUMN, self::DEFAULT_SCORE_COLUMN );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( self::OPTION_SCORE_COLUMN ); ?>"
			name="<?php echo esc_attr( self::OPTION_SCORE_COLUMN ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="goals"
		/>
		<p class="description">
			<?php esc_html_e( 'The result column key used to read scores from SportsPress (e.g. "goals"). Must match the column slug in SportsPress → Result Columns.', 'sportspress-announcer' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the digest settings introduction.
	 *
	 * @return void
	 */
	public function render_digest_section_intro(): void {
		?>
		<p class="description"><?php esc_html_e( 'Upcoming games for the next 7 days appear as an admin notice with a copy button. Use the button below to push the schedule to Discord on demand.', 'sportspress-announcer' ); ?></p>
		<?php
	}

	/**
	 * Render the upcoming-game template field.
	 *
	 * @return void
	 */
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

	/**
	 * Render the manual Discord digest control.
	 *
	 * @return void
	 */
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

	/**
	 * Sanitize the digest frequency.
	 *
	 * @param string $value Submitted frequency.
	 *
	 * @return string
	 */
	public function sanitize_digest_frequency( string $value ): string {
		return in_array( $value, array( 'daily', 'weekly' ), true ) ? $value : 'weekly';
	}

	/**
	 * Sanitize the weekly digest day.
	 *
	 * @param string $value Submitted weekday.
	 *
	 * @return string
	 */
	public function sanitize_digest_day( string $value ): string {
		$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		return in_array( $value, $days, true ) ? $value : 'monday';
	}

	/**
	 * Sanitize the digest send time.
	 *
	 * @param string $value Submitted 24-hour time.
	 *
	 * @return string
	 */
	public function sanitize_digest_time( string $value ): string {
		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		return '08:00';
	}

	/**
	 * Render the automatic digest schedule fields.
	 *
	 * @return void
	 */
	public function render_digest_schedule_field(): void {
		$enabled   = (bool) get_option( SPA_Digest_Scheduler::OPTION_ENABLED, false );
		$frequency = get_option( SPA_Digest_Scheduler::OPTION_FREQUENCY, 'weekly' );
		$day       = get_option( SPA_Digest_Scheduler::OPTION_DAY, 'monday' );
		$time      = get_option( SPA_Digest_Scheduler::OPTION_TIME, '08:00' );

		$days = array(
			'monday'    => __( 'Monday', 'sportspress-announcer' ),
			'tuesday'   => __( 'Tuesday', 'sportspress-announcer' ),
			'wednesday' => __( 'Wednesday', 'sportspress-announcer' ),
			'thursday'  => __( 'Thursday', 'sportspress-announcer' ),
			'friday'    => __( 'Friday', 'sportspress-announcer' ),
			'saturday'  => __( 'Saturday', 'sportspress-announcer' ),
			'sunday'    => __( 'Sunday', 'sportspress-announcer' ),
		);

		$next = wp_next_scheduled( 'spa_digest_send' );
		?>
		<label>
			<input
				type="checkbox"
				id="<?php echo esc_attr( SPA_Digest_Scheduler::OPTION_ENABLED ); ?>"
				name="<?php echo esc_attr( SPA_Digest_Scheduler::OPTION_ENABLED ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Automatically send upcoming games digest to Discord', 'sportspress-announcer' ); ?>
		</label>

		<div style="margin-top:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
			<select
				id="<?php echo esc_attr( SPA_Digest_Scheduler::OPTION_FREQUENCY ); ?>"
				name="<?php echo esc_attr( SPA_Digest_Scheduler::OPTION_FREQUENCY ); ?>"
			>
				<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'sportspress-announcer' ); ?></option>
				<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'sportspress-announcer' ); ?></option>
			</select>

			<span id="spa-digest-day-wrap" <?php echo 'daily' === $frequency ? 'style="display:none;"' : ''; ?>>
				<select
					name="<?php echo esc_attr( SPA_Digest_Scheduler::OPTION_DAY ); ?>"
				>
					<?php foreach ( $days as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $day, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>

			<input
				type="time"
				name="<?php echo esc_attr( SPA_Digest_Scheduler::OPTION_TIME ); ?>"
				value="<?php echo esc_attr( $time ); ?>"
			/>
		</div>

		<?php if ( $next ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: formatted date/time */
					esc_html__( 'Next send: %s', 'sportspress-announcer' ),
					esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
				);
				?>
			</p>
		<?php endif; ?>

		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var freq = document.getElementById( '<?php echo esc_js( SPA_Digest_Scheduler::OPTION_FREQUENCY ); ?>' );
			var wrap = document.getElementById( 'spa-digest-day-wrap' );
			if ( ! freq || ! wrap ) return;
			freq.addEventListener( 'change', function () {
				wrap.style.display = freq.value === 'weekly' ? '' : 'none';
			} );
		} );
		</script>
		<?php
	}

	// ── Page ──────────────────────────────────────────────────────────────────

	/**
	 * Render the plugin settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

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
