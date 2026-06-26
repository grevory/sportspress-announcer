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

	// Discord.
	private const OPTION_WEBHOOK              = 'spa_discord_webhook_url';
	public const  OPTION_DISCORD_ENABLED      = 'spa_discord_enabled';
	public const  OPTION_DISCORD_CHANNEL_MAP  = 'spa_discord_channel_map';

	// Slack (Pro).
	public const OPTION_SLACK_WEBHOOK = 'spa_slack_webhook_url';
	public const OPTION_SLACK_ENABLED = 'spa_slack_enabled';

	// Score column.
	public const OPTION_SCORE_COLUMN  = 'spa_score_column';
	public const DEFAULT_SCORE_COLUMN = 'goals';

	// Facebook.
	public const OPTION_FACEBOOK_ENABLED  = 'spa_facebook_enabled';
	public const OPTION_FACEBOOK_TEMPLATE = 'spa_facebook_template';

	public const DEFAULT_FACEBOOK_TEMPLATE = '{home} {home_score} – {away_score} {away} ({time}) @ {venue} | {competition}';

	// Result template (shared across all announcement channels).
	public const OPTION_RESULT_TEMPLATE = 'spa_result_template';

	public const DEFAULT_RESULT_TEMPLATE = '{home} {home_score} - {away_score} {away}';

	// Digest.
	public const OPTION_UPCOMING_TEMPLATE = 'spa_upcoming_template';

	public const DEFAULT_UPCOMING_TEMPLATE = '{home} vs {away}';

	// Digest schedule.
	// (option keys delegated to SPA_Digest_Scheduler).

	private const MENU_SLUG = 'sportspress-announcer';

	/**
	 * Register settings-page callbacks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spa_test_webhook', array( $this, 'ajax_test_webhook' ) );
		add_action( 'wp_ajax_spa_test_slack_webhook', array( $this, 'ajax_test_slack_webhook' ) );
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
	 * Enqueue admin assets on the plugin settings page.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'spa-emoji-picker',
			SPA_PLUGIN_URL . 'assets/js/spa-emoji-picker.js',
			array(),
			SPA_VERSION,
			true
		);
		wp_enqueue_style(
			'spa-admin',
			SPA_PLUGIN_URL . 'assets/css/spa-admin.css',
			array(),
			SPA_VERSION
		);
	}

	/**
	 * Register plugin settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {

		// SportsPress section.
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

		// Digest section.
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
			'spa_upcoming_publish',
			__( 'Send digest', 'sportspress-announcer' ),
			array( $this, 'render_upcoming_publish_field' ),
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

		// Result message template (all channels).
		register_setting(
			'spa_settings_group',
			self::OPTION_RESULT_TEMPLATE,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => self::DEFAULT_RESULT_TEMPLATE,
			)
		);

		add_settings_section(
			'spa_section_announcements',
			__( 'Announcements', 'sportspress-announcer' ),
			array( $this, 'render_announcements_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPTION_RESULT_TEMPLATE,
			__( 'Result Template', 'sportspress-announcer' ),
			array( $this, 'render_result_template_field' ),
			self::MENU_SLUG,
			'spa_section_announcements'
		);

		// Discord section.
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

		register_setting(
			'spa_settings_group',
			self::OPTION_DISCORD_CHANNEL_MAP,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_channel_map' ),
				'default'           => '',
			)
		);

		add_settings_field(
			self::OPTION_DISCORD_CHANNEL_MAP,
			__( 'Channel Routing', 'sportspress-announcer' ),
			array( $this, 'render_channel_map_field' ),
			self::MENU_SLUG,
			'spa_section_discord'
		);

		// Slack section (Pro).
		register_setting(
			'spa_settings_group',
			self::OPTION_SLACK_WEBHOOK,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_slack_webhook_url' ),
				'default'           => '',
			)
		);

		register_setting(
			'spa_settings_group',
			self::OPTION_SLACK_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		add_settings_section(
			'spa_section_slack',
			__( 'Slack (Pro)', 'sportspress-announcer' ),
			array( $this, 'render_slack_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPTION_SLACK_ENABLED,
			__( 'Announcements', 'sportspress-announcer' ),
			array( $this, 'render_slack_enabled_field' ),
			self::MENU_SLUG,
			'spa_section_slack'
		);

		add_settings_field(
			self::OPTION_SLACK_WEBHOOK,
			__( 'Webhook URL', 'sportspress-announcer' ),
			array( $this, 'render_slack_webhook_field' ),
			self::MENU_SLUG,
			'spa_section_slack'
		);

		// Facebook section.
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

	// AJAX.

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

	// Sanitize.

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

	// Field renderers.

	/**
	 * Render the announcements section intro.
	 *
	 * @return void
	 */
	public function render_announcements_section_intro(): void {
		?>
		<p class="description"><?php esc_html_e( 'Configure the result message posted to Discord, Slack, and other channels.', 'sportspress-announcer' ); ?></p>
		<?php
	}

	/**
	 * Render the result message template field.
	 *
	 * @return void
	 */
	public function render_result_template_field(): void {
		$value = get_option( self::OPTION_RESULT_TEMPLATE, self::DEFAULT_RESULT_TEMPLATE );
		?>
		<textarea
			id="<?php echo esc_attr( self::OPTION_RESULT_TEMPLATE ); ?>"
			name="<?php echo esc_attr( self::OPTION_RESULT_TEMPLATE ); ?>"
			rows="2"
			class="large-text"
			style="resize:vertical;"
		><?php echo esc_textarea( $value ); ?></textarea>
		<div style="display:flex; align-items:flex-start; gap:8px; margin-top:6px;">
			<button type="button" class="button spa-emoji-trigger" data-target="<?php echo esc_attr( self::OPTION_RESULT_TEMPLATE ); ?>" style="flex-shrink:0;">😀 <?php esc_html_e( 'Emoji', 'sportspress-announcer' ); ?></button>
			<p class="description" style="margin:0;">
				<?php
				$chips = array( '{home}', '{away}', '{home_score}', '{away_score}', '{competition}', '{event_url}' );
				foreach ( $chips as $chip ) {
					printf(
						'<code class="spa-placeholder" data-target="%s" style="cursor:pointer;" title="%s">%s</code> ',
						esc_attr( self::OPTION_RESULT_TEMPLATE ),
						esc_attr( __( 'Click to insert', 'sportspress-announcer' ) ),
						esc_html( $chip )
					);
				}
				?>
				<br><?php esc_html_e( 'Team names are auto-bolded per platform. Slack mentions (<!channel>, <!here>) and emoji work too.', 'sportspress-announcer' ); ?>
			</p>
		</div>
		<?php
	}

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
			<span id="spa-test-result" style="display:inline-flex; align-items:center; min-height:30px; margin-left:8px; vertical-align:middle;"></span>
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
	 * Sanitize the per-league Discord channel map.
	 *
	 * @param mixed $value Raw input (expected array of term_id => url).
	 * @return array
	 */
	public function sanitize_channel_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $row_key => $row ) {
			// Already-sanitized format: [ 'Competition Name' => 'https://...' ] (e.g. double-sanitize pass).
			if ( is_string( $row ) ) {
				$key = sanitize_text_field( trim( (string) $row_key ) );
				$url = trim( $row );
			} else {
				// Form-submitted format: [ 0 => [ 'key' => ..., 'url' => ... ] ].
				if ( ! is_array( $row ) ) {
					continue;
				}
				$key = sanitize_text_field( trim( (string) ( $row['key'] ?? '' ) ) );
				$url = trim( (string) ( $row['url'] ?? '' ) );
			}

			if ( '' === $key || '' === $url ) {
				continue;
			}
			if ( 0 !== strpos( $url, 'https://discord.com/api/webhooks/' ) ) {
				add_settings_error(
					self::OPTION_DISCORD_CHANNEL_MAP,
					'spa_invalid_channel_map_url',
					sprintf(
						/* translators: %s: competition/league label */
						__( 'Invalid Discord webhook URL for "%s" — must start with https://discord.com/api/webhooks/', 'sportspress-announcer' ),
						$key
					)
				);
				continue;
			}
			$clean[ $key ] = esc_url_raw( $url );
		}

		return $clean;
	}

	/**
	 * Render the per-league Discord channel routing field.
	 *
	 * @return void
	 */
	public function render_channel_map_field(): void {
		$map    = (array) get_option( self::OPTION_DISCORD_CHANNEL_MAP, array() );
		$opt    = esc_attr( self::OPTION_DISCORD_CHANNEL_MAP );
		$ph_key = esc_attr__( 'Division / competition name', 'sportspress-announcer' );
		$ph_url = esc_attr__( 'https://discord.com/api/webhooks/…', 'sportspress-announcer' );

		// Seed saved rows; if none, pre-populate from sp_league terms.
		if ( empty( $map ) ) {
			$leagues = get_terms( array( 'taxonomy' => 'sp_league', 'hide_empty' => false ) );
			if ( ! is_wp_error( $leagues ) ) {
				foreach ( $leagues as $term ) {
					$map[ $term->name ] = '';
				}
			}
		}
		?>
		<p class="description" style="margin-bottom:10px;">
			<?php esc_html_e( 'Route each division to its own Discord channel. The key must match the competition name exactly. Leave the URL blank to use the default webhook. Per-division routing applies to result announcements only — the digest always uses the default webhook.', 'sportspress-announcer' ); ?>
		</p>
		<table id="spa-channel-map-table" style="border-collapse:collapse; width:100%; max-width:700px;">
			<thead>
				<tr>
					<th style="text-align:left; padding:0 10px 6px 0; font-weight:600; width:35%;"><?php esc_html_e( 'Competition name', 'sportspress-announcer' ); ?></th>
					<th style="text-align:left; padding:0 0 6px 0; font-weight:600;"><?php esc_html_e( 'Discord webhook URL', 'sportspress-announcer' ); ?></th>
					<th style="width:30px;"></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$index = 0;
			foreach ( $map as $key => $url ) :
			?>
				<tr class="spa-channel-map-row">
					<td style="padding:4px 10px 4px 0;">
						<input
							type="text"
							name="<?php echo $opt; ?>[<?php echo $index; ?>][key]"
							value="<?php echo esc_attr( $key ); ?>"
							class="regular-text"
							placeholder="<?php echo $ph_key; ?>"
							style="width:100%;"
						/>
					</td>
					<td style="padding:4px 6px 4px 0;">
						<input
							type="url"
							name="<?php echo $opt; ?>[<?php echo $index; ?>][url]"
							value="<?php echo esc_attr( $url ); ?>"
							class="regular-text"
							placeholder="<?php echo $ph_url; ?>"
							style="width:100%;"
						/>
					</td>
					<td style="padding:4px 0; text-align:center;">
						<button type="button" class="button-link spa-channel-map-remove" title="<?php esc_attr_e( 'Remove', 'sportspress-announcer' ); ?>" style="color:#a00; padding:4px;">&#x2715;</button>
					</td>
				</tr>
			<?php
			$index++;
			endforeach;
			?>
			</tbody>
		</table>
		<p style="margin-top:8px;">
			<button type="button" id="spa-channel-map-add" class="button">
				<?php esc_html_e( '+ Add channel', 'sportspress-announcer' ); ?>
			</button>
		</p>
		<script>
		(function () {
			var table   = document.getElementById( 'spa-channel-map-table' );
			var addBtn  = document.getElementById( 'spa-channel-map-add' );
			var opt     = '<?php echo esc_js( self::OPTION_DISCORD_CHANNEL_MAP ); ?>';
			var phKey   = '<?php echo esc_js( __( 'Division / competition name', 'sportspress-announcer' ) ); ?>';
			var phUrl   = '<?php echo esc_js( __( 'https://discord.com/api/webhooks/…', 'sportspress-announcer' ) ); ?>';

			function nextIndex() {
				return table.querySelectorAll( '.spa-channel-map-row' ).length;
			}

			function bindRemove( btn ) {
				btn.addEventListener( 'click', function () {
					var row = btn.closest( 'tr' );
					row.parentNode.removeChild( row );
					reindex();
				} );
			}

			function reindex() {
				table.querySelectorAll( '.spa-channel-map-row' ).forEach( function ( row, i ) {
					row.querySelectorAll( 'input' ).forEach( function ( input ) {
						input.name = input.name.replace( /\[\d+\]/, '[' + i + ']' );
					} );
				} );
			}

			addBtn.addEventListener( 'click', function () {
				var i    = nextIndex();
				var tbody = table.querySelector( 'tbody' );
				var tr   = document.createElement( 'tr' );
				tr.className = 'spa-channel-map-row';
				tr.innerHTML =
					'<td style="padding:4px 10px 4px 0;">' +
						'<input type="text" name="' + opt + '[' + i + '][key]" value="" class="regular-text" placeholder="' + phKey + '" style="width:100%;"/>' +
					'</td>' +
					'<td style="padding:4px 6px 4px 0;">' +
						'<input type="url" name="' + opt + '[' + i + '][url]" value="" class="regular-text" placeholder="' + phUrl + '" style="width:100%;"/>' +
					'</td>' +
					'<td style="padding:4px 0; text-align:center;">' +
						'<button type="button" class="button-link spa-channel-map-remove" title="Remove" style="color:#a00; padding:4px;">&#x2715;</button>' +
					'</td>';
				tbody.appendChild( tr );
				bindRemove( tr.querySelector( '.spa-channel-map-remove' ) );
				tr.querySelector( 'input' ).focus();
			} );

			table.querySelectorAll( '.spa-channel-map-remove' ).forEach( bindRemove );
		}());
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
		<div style="display:flex; align-items:flex-start; gap:8px; margin-top:6px;">
			<button type="button" class="button spa-emoji-trigger" data-target="<?php echo esc_attr( self::OPTION_FACEBOOK_TEMPLATE ); ?>" style="flex-shrink:0;">😀 <?php esc_html_e( 'Emoji', 'sportspress-announcer' ); ?></button>
			<p class="description" style="margin:0;">
				<?php
				$chips = array( '{home}', '{away}', '{home_score}', '{away_score}', '{competition}', '{venue}', '{time}', '{date}', '{event_url}' );
				foreach ( $chips as $chip ) {
					printf(
						'<code class="spa-placeholder" data-target="%s" style="cursor:pointer;" title="%s">%s</code> ',
						esc_attr( self::OPTION_FACEBOOK_TEMPLATE ),
						esc_attr( __( 'Click to insert', 'sportspress-announcer' ) ),
						esc_html( $chip )
					);
				}
				?>
			</p>
		</div>
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
		<textarea
			id="<?php echo esc_attr( self::OPTION_UPCOMING_TEMPLATE ); ?>"
			name="<?php echo esc_attr( self::OPTION_UPCOMING_TEMPLATE ); ?>"
			rows="3"
			class="large-text"
		><?php echo esc_textarea( $value ); ?></textarea>
		<div style="display:flex; align-items:flex-start; gap:8px; margin-top:6px;">
			<button type="button" class="button spa-emoji-trigger" data-target="<?php echo esc_attr( self::OPTION_UPCOMING_TEMPLATE ); ?>" style="flex-shrink:0;">😀 <?php esc_html_e( 'Emoji', 'sportspress-announcer' ); ?></button>
			<p class="description" style="margin:0;">
				<?php
				$chips = array( '{home}', '{away}', '{competition}', '{venue}', '{time}', '{date}', '{event_url}' );
				foreach ( $chips as $chip ) {
					printf(
						'<code class="spa-placeholder" data-target="%s" style="cursor:pointer;" title="%s">%s</code> ',
						esc_attr( self::OPTION_UPCOMING_TEMPLATE ),
						esc_attr( __( 'Click to insert', 'sportspress-announcer' ) ),
						esc_html( $chip )
					);
				}
				?>
				<br><?php esc_html_e( 'Slack mentions (<!channel>, <!here>) and emoji work too.', 'sportspress-announcer' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the unified publish button that sends the digest to all enabled services.
	 *
	 * @return void
	 */
	public function render_upcoming_publish_field(): void {
		$discord_url  = get_option( self::OPTION_WEBHOOK, '' );
		$slack_url    = get_option( self::OPTION_SLACK_WEBHOOK, '' );
		$send_discord = ! empty( $discord_url );
		$send_slack   = ! empty( $slack_url );

		if ( ! $send_discord && ! $send_slack ) {
			?>
			<p class="description"><?php esc_html_e( 'Configure a Discord or Slack webhook URL above to enable this.', 'sportspress-announcer' ); ?></p>
			<?php
			return;
		}

		// Build preview text (same grouping logic as the digest senders).
		$notice  = new SPA_Upcoming_Notice();
		$games   = $notice->get_upcoming_games();
		$by_date = array();
		foreach ( $games as $g ) {
			$by_date[ $g['date'] ][] = $g;
		}
		ksort( $by_date );
		$preview_lines = array();
		$first         = true;
		foreach ( $by_date as $date => $group ) {
			if ( ! $first ) {
				$preview_lines[] = '';
			}
			$first           = false;
			$preview_lines[] = $date;
			foreach ( $group as $g ) {
				$line = '• ' . $g['label'];
				if ( $g['time'] ) {
					$line .= ' - ' . $g['time'];
				}
				if ( $g['venue'] ) {
					$line .= ' @ ' . $g['venue'];
				}
				$preview_lines[] = $line;
			}
		}
		$preview_text = implode( "\n", $preview_lines );
		?>
		<?php if ( ! empty( $games ) ) : ?>
		<p style="margin-bottom:6px;">
			<a href="#" id="spa-preview-toggle" aria-expanded="false">
				<?php esc_html_e( 'Preview digest ▸', 'sportspress-announcer' ); ?>
			</a>
		</p>
		<pre id="spa-preview-box" style="display:none; white-space:pre-wrap; background:#f6f7f7; border:1px solid #dcdcde; padding:10px 12px; margin:0 0 12px; font-size:12px; line-height:1.6; max-width:600px;"><?php echo esc_html( $preview_text ); ?></pre>
		<?php else : ?>
		<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'No upcoming games in the next 7 days.', 'sportspress-announcer' ); ?></p>
		<?php endif; ?>
		<p>
			<button type="button" id="spa-publish-upcoming" class="button button-primary"<?php echo empty( $games ) ? ' disabled' : ''; ?>>
				<?php esc_html_e( 'Publish', 'sportspress-announcer' ); ?>
			</button>
			<span id="spa-publish-result" style="display:inline-flex; align-items:center; min-height:30px; margin-left:8px; vertical-align:middle;"></span>
		</p>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var toggle  = document.getElementById( 'spa-preview-toggle' );
			var preview = document.getElementById( 'spa-preview-box' );
			if ( toggle && preview ) {
				toggle.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var open = preview.style.display !== 'none';
					preview.style.display = open ? 'none' : 'block';
					toggle.textContent    = open
						? '<?php echo esc_js( __( 'Preview digest ▸', 'sportspress-announcer' ) ); ?>'
						: '<?php echo esc_js( __( 'Preview digest ▾', 'sportspress-announcer' ) ); ?>';
					toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				} );
			}

			var btn    = document.getElementById( 'spa-publish-upcoming' );
			var result = document.getElementById( 'spa-publish-result' );
			if ( ! btn || ! result ) return;
			btn.addEventListener( 'click', function () {
				result.textContent = '<?php echo esc_js( __( 'Sending…', 'sportspress-announcer' ) ); ?>';
				result.style.color = '';
				btn.disabled = true;

				var requests = [];

				<?php if ( $send_discord ) : ?>
				var discordData = new FormData();
				discordData.append( 'action', 'spa_send_upcoming' );
				discordData.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'spa_send_upcoming_nonce' ) ); ?>' );
				requests.push( fetch( ajaxurl, { method: 'POST', body: discordData } ).then( function ( r ) { return r.json(); } ) );
				<?php endif; ?>

				<?php if ( $send_slack ) : ?>
				var slackData = new FormData();
				slackData.append( 'action', 'spa_send_upcoming_slack' );
				slackData.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'spa_send_upcoming_slack_nonce' ) ); ?>' );
				requests.push( fetch( ajaxurl, { method: 'POST', body: slackData } ).then( function ( r ) { return r.json(); } ) );
				<?php endif; ?>

				Promise.allSettled( requests ).then( function ( results ) {
					var errors = [];
					results.forEach( function ( r ) {
						if ( r.status === 'rejected' || ( r.value && ! r.value.success ) ) {
							errors.push( r.value ? ( r.value.data || '<?php echo esc_js( __( 'Unknown error', 'sportspress-announcer' ) ); ?>' ) : '<?php echo esc_js( __( 'Request failed', 'sportspress-announcer' ) ); ?>' );
						}
					} );
					if ( errors.length === 0 ) {
						result.textContent = '<?php echo esc_js( __( '✓ Published!', 'sportspress-announcer' ) ); ?>';
						result.style.color = '#46b450';
						setTimeout( function () {
							var notice = document.querySelector( '.spa-upcoming-notice' );
							if ( notice ) { notice.style.display = 'none'; }
							fetch( '<?php echo esc_js( wp_nonce_url( admin_url( 'admin-post.php?action=spa_dismiss_upcoming_notice' ), 'spa_dismiss_upcoming_notice' ) ); ?>', { method: 'GET', redirect: 'manual' } );
						}, 1500 );
					} else if ( errors.length === results.length ) {
						result.textContent = '<?php echo esc_js( __( '✗ Error: ', 'sportspress-announcer' ) ); ?>' + errors.join( '; ' );
						result.style.color = '#dc3232';
						btn.disabled = false;
					} else {
						result.textContent = '<?php echo esc_js( __( '⚠ Partial: ', 'sportspress-announcer' ) ); ?>' + errors.join( '; ' );
						result.style.color = '#ffb900';
						btn.disabled = false;
					}
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

	// Slack AJAX + renderers.

	/**
	 * Test a submitted Slack webhook URL.
	 *
	 * @return void
	 */
	public function ajax_test_slack_webhook(): void {
		check_ajax_referer( 'spa_test_slack_webhook_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) );
		if ( empty( $url ) ) {
			wp_send_json_error( __( 'No webhook URL entered.', 'sportspress-announcer' ) );
		}

		if ( 0 !== strpos( $url, 'https://hooks.slack.com/services/' ) && 0 !== strpos( $url, 'https://hooks.slack.com/workflows/' ) ) {
			wp_send_json_error( __( 'That doesn\'t look like a Slack Incoming Webhook URL.', 'sportspress-announcer' ) );
		}

		$payload = array(
			'text' => __( 'SportsPress Announcer - Slack webhook connection successful.', 'sportspress-announcer' ),
		);

		$slack  = new SPA_Webhook_Slack( $url );
		$result = $slack->send( $payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	/**
	 * Validate and sanitize a Slack Incoming Webhook URL.
	 *
	 * @param string $value Submitted webhook URL.
	 *
	 * @return string
	 */
	public function sanitize_slack_webhook_url( string $value ): string {
		$value = trim( $value );
		if ( empty( $value ) ) {
			return '';
		}
		if ( 0 !== strpos( $value, 'https://hooks.slack.com/services/' ) && 0 !== strpos( $value, 'https://hooks.slack.com/workflows/' ) ) {
			add_settings_error(
				self::OPTION_SLACK_WEBHOOK,
				'spa_invalid_slack_webhook',
				__( 'That doesn\'t look like a Slack Incoming Webhook URL. It should start with https://hooks.slack.com/services/ or https://hooks.slack.com/workflows/', 'sportspress-announcer' )
			);
			return get_option( self::OPTION_SLACK_WEBHOOK, '' );
		}
		return esc_url_raw( $value );
	}

	/**
	 * Render the Slack section description.
	 *
	 * @return void
	 */
	public function render_slack_section_intro(): void {
		?>
		<p class="description"><?php esc_html_e( 'Post match results and upcoming game digests to a Slack channel via an Incoming Webhook.', 'sportspress-announcer' ); ?></p>
		<?php
	}

	/**
	 * Render the Slack announcements toggle.
	 *
	 * @return void
	 */
	public function render_slack_enabled_field(): void {
		$enabled = (bool) get_option( self::OPTION_SLACK_ENABLED, false );
		?>
		<label>
			<input
				type="checkbox"
				id="<?php echo esc_attr( self::OPTION_SLACK_ENABLED ); ?>"
				name="<?php echo esc_attr( self::OPTION_SLACK_ENABLED ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Send automatic Slack announcements when event results are published', 'sportspress-announcer' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the Slack webhook URL field.
	 *
	 * @return void
	 */
	public function render_slack_webhook_field(): void {
		$value = get_option( self::OPTION_SLACK_WEBHOOK, '' );
		?>
		<input
			type="url"
			id="<?php echo esc_attr( self::OPTION_SLACK_WEBHOOK ); ?>"
			name="<?php echo esc_attr( self::OPTION_SLACK_WEBHOOK ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="https://hooks.slack.com/services/… or /workflows/…"
		/>
		<p class="description">
			<?php
			printf(
				wp_kses(
					/* translators: %s: URL to Slack docs */
					__( 'Paste your Slack channel\'s Incoming Webhook URL. <a href="%s" target="_blank" rel="noopener">How to create a webhook →</a>', 'sportspress-announcer' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				),
				'https://api.slack.com/messaging/webhooks'
			);
			?>
		</p>
		<p>
			<button type="button" id="spa-test-slack-webhook" class="button">
				<?php esc_html_e( 'Send Test Message', 'sportspress-announcer' ); ?>
			</button>
			<span id="spa-test-slack-result" style="display:inline-flex; align-items:center; min-height:30px; margin-left:8px; vertical-align:middle;"></span>
		</p>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var btn    = document.getElementById( 'spa-test-slack-webhook' );
			var result = document.getElementById( 'spa-test-slack-result' );
			var input  = document.getElementById( '<?php echo esc_js( self::OPTION_SLACK_WEBHOOK ); ?>' );
			if ( ! btn || ! result || ! input ) return;
			btn.addEventListener( 'click', function () {
				result.textContent = '<?php echo esc_js( __( 'Sending…', 'sportspress-announcer' ) ); ?>';
				result.style.color = '';
				btn.disabled = true;
				var data = new FormData();
				data.append( 'action', 'spa_test_slack_webhook' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'spa_test_slack_webhook_nonce' ) ); ?>' );
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

	// Page.

	/**
	 * Render the plugin settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wp_settings_sections, $wp_settings_fields;
		$page = self::MENU_SLUG;

		$global_sections = array( 'spa_section_sportspress', 'spa_section_digest', 'spa_section_announcements' );
		$integration_sections = array(
			'spa_section_discord'  => 'discord',
			'spa_section_slack'    => 'slack',
			'spa_section_facebook' => 'facebook',
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'spa_settings_group' ); ?>

				<?php foreach ( $global_sections as $section_id ) :
					if ( ! isset( $wp_settings_sections[ $page ][ $section_id ] ) ) {
						continue;
					}
					$section = $wp_settings_sections[ $page ][ $section_id ];
				?>
				<div class="spa-global-section">
					<h2><?php echo esc_html( $section['title'] ); ?></h2>
					<?php if ( $section['callback'] && '__return_false' !== $section['callback'] ) {
						call_user_func( $section['callback'], $section );
					} ?>
					<?php if ( isset( $wp_settings_fields[ $page ][ $section_id ] ) ) : ?>
					<table class="form-table" role="presentation"><tbody>
						<?php do_settings_fields( $page, $section_id ); ?>
					</tbody></table>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>

				<h2 class="spa-integrations-header"><?php esc_html_e( 'Integrations', 'sportspress-announcer' ); ?></h2>

				<?php foreach ( $integration_sections as $section_id => $modifier ) :
					if ( ! isset( $wp_settings_sections[ $page ][ $section_id ] ) ) {
						continue;
					}
					$section = $wp_settings_sections[ $page ][ $section_id ];
				?>
				<div class="spa-integration-card spa-integration-card--<?php echo esc_attr( $modifier ); ?>">
					<h2><?php echo esc_html( $section['title'] ); ?></h2>
					<?php if ( $section['callback'] && '__return_false' !== $section['callback'] ) {
						call_user_func( $section['callback'], $section );
					} ?>
					<?php if ( isset( $wp_settings_fields[ $page ][ $section_id ] ) ) : ?>
					<table class="form-table" role="presentation"><tbody>
						<?php do_settings_fields( $page, $section_id ); ?>
					</tbody></table>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save Settings', 'sportspress-announcer' ) ); ?>
			</form>
		</div>
		<?php
	}
}
