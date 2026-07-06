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
	private const OPTION_WEBHOOK             = 'spa_discord_webhook_url';
	public const  OPTION_DISCORD_ENABLED     = 'spa_discord_enabled';
	public const  OPTION_DISCORD_CHANNEL_MAP = 'spa_discord_channel_map';

	// Slack (Pro).
	public const OPTION_SLACK_WEBHOOK     = 'spa_slack_webhook_url';
	public const OPTION_SLACK_ENABLED     = 'spa_slack_enabled';
	public const OPTION_SLACK_CHANNEL_MAP = 'spa_slack_channel_map';

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
	private const QS_USER_META = 'spa_qs_dismissed';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spa_test_webhook', array( $this, 'ajax_test_webhook' ) );
		add_action( 'wp_ajax_spa_test_slack_webhook', array( $this, 'ajax_test_slack_webhook' ) );
		add_action( 'wp_ajax_spa_qs_dismiss', array( $this, 'ajax_qs_dismiss' ) );
		add_action( 'wp_ajax_spa_retry_announcement', array( $this, 'ajax_retry_announcement' ) );
		add_action( 'wp_ajax_spa_send_digest', array( $this, 'ajax_send_digest' ) );
	}

	/**
	 * Toggle a Quick Start checklist item for the current user.
	 *
	 * @return void
	 */
	public function ajax_qs_dismiss(): void {
		check_ajax_referer( 'spa_qs_dismiss_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$item      = sanitize_key( wp_unslash( $_POST['item'] ?? '' ) );
		$checked   = rest_sanitize_boolean( $_POST['checked'] ?? false );
		$user_id   = get_current_user_id();
		$raw       = get_user_meta( $user_id, self::QS_USER_META, true );
		$dismissed = is_array( $raw ) ? $raw : array();

		if ( $checked ) {
			$dismissed[ $item ] = true;
		} else {
			unset( $dismissed[ $item ] );
		}

		update_user_meta( $user_id, self::QS_USER_META, $dismissed );
		wp_send_json_success();
	}

	/**
	 * Retry a failed announcement by re-sending to the current webhook.
	 *
	 * Resolves the webhook URL from current options using the stored platform
	 * and competition — secrets are never persisted in the log.
	 *
	 * @return void
	 */
	public function ajax_retry_announcement(): void {
		check_ajax_referer( 'spa_retry_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$uid = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );
		if ( '' === $uid ) {
			wp_send_json_error( __( 'Missing entry ID.', 'sportspress-announcer' ) );
		}

		// Find the entry by stable uid — array index is not stable across concurrent writes.
		$entry = null;
		foreach ( SPA_Log::get_all() as $candidate ) {
			if ( ( $candidate['uid'] ?? '' ) === $uid ) {
				$entry = $candidate;
				break;
			}
		}

		if ( null === $entry ) {
			wp_send_json_error( __( 'Log entry not found.', 'sportspress-announcer' ) );
		}

		$platform    = $entry['platform'] ?? 'discord';
		$competition = $entry['competition'] ?? '';
		$post_id     = (int) ( $entry['id'] ?? 0 );

		if ( 'result' === $entry['type'] ) {
			$post = get_post( $post_id );
			if ( ! $post || 'sp_event' !== $post->post_type ) {
				wp_send_json_error( __( 'Event post not found.', 'sportspress-announcer' ) );
			}

			$handler   = new SPA_Event_Handler();
			$formatter = new SPA_Message_Formatter();

			$event = $handler->extract_event_data( $post_id );
			if ( ! $event ) {
				wp_send_json_error( __( 'Could not read event data.', 'sportspress-announcer' ) );
			}

			if ( 'discord' === $platform ) {
				$channel_map = (array) get_option( self::OPTION_DISCORD_CHANNEL_MAP, array() );
				$webhook_url = ( $competition && ! empty( $channel_map[ $competition ] ) )
					? $channel_map[ $competition ]
					: get_option( self::OPTION_WEBHOOK, '' );

				if ( empty( $webhook_url ) ) {
					wp_send_json_error( __( 'No Discord webhook configured.', 'sportspress-announcer' ) );
				}

				$result = ( new SPA_Webhook_Discord( $webhook_url ) )->send( $formatter->format_embed( $event ) );
			} else {
				$channel_map = (array) get_option( self::OPTION_SLACK_CHANNEL_MAP, array() );
				$webhook_url = ( $competition && ! empty( $channel_map[ $competition ] ) )
					? $channel_map[ $competition ]
					: get_option( self::OPTION_SLACK_WEBHOOK, '' );

				if ( empty( $webhook_url ) ) {
					wp_send_json_error( __( 'No Slack webhook configured.', 'sportspress-announcer' ) );
				}

				$result = ( new SPA_Webhook_Slack( $webhook_url ) )->send( $formatter->format_slack( $event ) );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			SPA_Log::update_entry( $uid, array( 'status' => 'sent', 'sent_at' => time() ) );
			wp_send_json_success();
		}

		// Digest retry: send directly without going through send_digest(), which would
		// write a new log entry and leave the original failed row unchanged.
		if ( 'digest' === $entry['type'] ) {
			$notice = new SPA_Upcoming_Notice();
			$games  = $notice->get_upcoming_games();

			if ( empty( $games ) ) {
				wp_send_json_error( __( 'No upcoming games found.', 'sportspress-announcer' ) );
			}

			if ( 'discord' === $platform ) {
				$webhook_url = get_option( self::OPTION_WEBHOOK, '' );
				if ( empty( $webhook_url ) ) {
					wp_send_json_error( __( 'No Discord webhook configured.', 'sportspress-announcer' ) );
				}
				$sender  = new SPA_Upcoming_Discord();
				$payload = array(
					'embeds' => array(
						array(
							'title'       => __( 'Upcoming Games', 'sportspress-announcer' ),
							'description' => $sender->build_description( $games ),
							'color'       => 0x5865F2,
						),
					),
				);
				$result = ( new SPA_Webhook_Discord( $webhook_url ) )->send( $payload );
			} else {
				$webhook_url = get_option( self::OPTION_SLACK_WEBHOOK, '' );
				if ( empty( $webhook_url ) ) {
					wp_send_json_error( __( 'No Slack webhook configured.', 'sportspress-announcer' ) );
				}
				$sender  = new SPA_Upcoming_Slack();
				$mrkdwn  = $sender->build_mrkdwn( $games );
				$payload = array(
					'text'   => __( 'Upcoming Games', 'sportspress-announcer' ),
					'blocks' => array(
						array( 'type' => 'header', 'text' => array( 'type' => 'plain_text', 'text' => __( 'Upcoming Games', 'sportspress-announcer' ), 'emoji' => true ) ),
						array( 'type' => 'section', 'text' => array( 'type' => 'mrkdwn', 'text' => $mrkdwn ) ),
					),
				);
				$result = ( new SPA_Webhook_Slack( $webhook_url ) )->send( $payload );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			SPA_Log::update_entry( $uid, array( 'status' => 'sent', 'sent_at' => time() ) );
			wp_send_json_success();
		}

		wp_send_json_error( __( 'Unknown entry type.', 'sportspress-announcer' ) );
	}

	/**
	 * Send the upcoming fixtures digest to Discord now.
	 *
	 * @return void
	 */
	public function ajax_send_digest(): void {
		check_ajax_referer( 'spa_send_digest_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$sender = new SPA_Upcoming_Discord();
		$result = $sender->send_digest();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		if ( false === $result ) {
			wp_send_json_error( __( 'No upcoming games found in the next 7 days.', 'sportspress-announcer' ) );
		}

		wp_send_json_success();
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

		register_setting(
			'spa_settings_group',
			self::OPTION_SLACK_CHANNEL_MAP,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_slack_channel_map' ),
				'default'           => '',
			)
		);

		add_settings_field(
			self::OPTION_SLACK_CHANNEL_MAP,
			__( 'Channel Routing', 'sportspress-announcer' ),
			array( $this, 'render_slack_channel_map_field' ),
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
				// Form-submitted rows provide a competition key and webhook URL.
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
		$opt    = self::OPTION_DISCORD_CHANNEL_MAP;
		$ph_key = __( 'Division / competition name', 'sportspress-announcer' );
		$ph_url = __( 'https://discord.com/api/webhooks/…', 'sportspress-announcer' );

		// Seed saved rows; if none, pre-populate from sp_league terms.
		if ( empty( $map ) ) {
			$leagues = get_terms(
				array(
					'taxonomy'   => 'sp_league',
					'hide_empty' => false,
				)
			);
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
								name="<?php echo esc_attr( $opt ); ?>[<?php echo absint( $index ); ?>][key]"
								value="<?php echo esc_attr( $key ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $ph_key ); ?>"
								style="width:100%;"
							/>
					</td>
					<td style="padding:4px 6px 4px 0;">
							<input
								type="url"
								name="<?php echo esc_attr( $opt ); ?>[<?php echo absint( $index ); ?>][url]"
								value="<?php echo esc_attr( $url ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $ph_url ); ?>"
								style="width:100%;"
							/>
					</td>
					<td style="padding:4px 0; text-align:center;">
						<button type="button" class="button-link spa-channel-map-remove" title="<?php esc_attr_e( 'Remove', 'sportspress-announcer' ); ?>" style="color:#a00; padding:4px;">&#x2715;</button>
					</td>
				</tr>
				<?php
				++$index;
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
	 * Sanitize the per-league Slack channel map.
	 *
	 * @param mixed $value Raw input.
	 * @return array
	 */
	public function sanitize_slack_channel_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $row_key => $row ) {
			// Already-sanitized format: [ 'Competition Name' => 'https://...' ].
			if ( is_string( $row ) ) {
				$key = sanitize_text_field( trim( (string) $row_key ) );
				$url = trim( $row );
			} else {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$key = sanitize_text_field( trim( (string) ( $row['key'] ?? '' ) ) );
				$url = trim( (string) ( $row['url'] ?? '' ) );
			}

			if ( '' === $key || '' === $url ) {
				continue;
			}
			if ( 0 !== strpos( $url, 'https://hooks.slack.com/services/' ) && 0 !== strpos( $url, 'https://hooks.slack.com/workflows/' ) ) {
				add_settings_error(
					self::OPTION_SLACK_CHANNEL_MAP,
					'spa_invalid_slack_channel_map_url',
					sprintf(
						/* translators: %s: competition/league label */
						__( 'Invalid Slack webhook URL for "%s" — must start with https://hooks.slack.com/services/ or https://hooks.slack.com/workflows/', 'sportspress-announcer' ),
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
	 * Render the per-league Slack channel routing field.
	 *
	 * @return void
	 */
	public function render_slack_channel_map_field(): void {
		$map    = (array) get_option( self::OPTION_SLACK_CHANNEL_MAP, array() );
		$opt    = self::OPTION_SLACK_CHANNEL_MAP;
		$ph_key = __( 'Division / competition name', 'sportspress-announcer' );
		$ph_url = __( 'https://hooks.slack.com/services/…', 'sportspress-announcer' );

		if ( empty( $map ) ) {
			$leagues = get_terms(
				array(
					'taxonomy'   => 'sp_league',
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $leagues ) ) {
				foreach ( $leagues as $term ) {
					$map[ $term->name ] = '';
				}
			}
		}
		?>
		<p class="description" style="margin-bottom:10px;">
			<?php esc_html_e( 'Optionally route each division to its own Slack channel. The key must match the competition name exactly. Leave the URL blank to use the default webhook above. Per-division routing applies to result announcements only.', 'sportspress-announcer' ); ?>
		</p>
		<table id="spa-slack-channel-map-table" style="border-collapse:collapse; width:100%; max-width:700px;">
			<thead>
				<tr>
					<th style="text-align:left; padding:0 10px 6px 0; font-weight:600; width:35%;"><?php esc_html_e( 'Competition name', 'sportspress-announcer' ); ?></th>
					<th style="text-align:left; padding:0 0 6px 0; font-weight:600;"><?php esc_html_e( 'Slack webhook URL', 'sportspress-announcer' ); ?></th>
					<th style="width:30px;"></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$index = 0;
			foreach ( $map as $key => $url ) :
				?>
				<tr class="spa-slack-channel-map-row">
					<td style="padding:4px 10px 4px 0;">
							<input
								type="text"
								name="<?php echo esc_attr( $opt ); ?>[<?php echo absint( $index ); ?>][key]"
								value="<?php echo esc_attr( $key ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $ph_key ); ?>"
								style="width:100%;"
							/>
					</td>
					<td style="padding:4px 6px 4px 0;">
							<input
								type="url"
								name="<?php echo esc_attr( $opt ); ?>[<?php echo absint( $index ); ?>][url]"
								value="<?php echo esc_attr( $url ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $ph_url ); ?>"
								style="width:100%;"
							/>
					</td>
					<td style="padding:4px 0; text-align:center;">
						<button type="button" class="button-link spa-slack-channel-map-remove" title="<?php esc_attr_e( 'Remove', 'sportspress-announcer' ); ?>" style="color:#a00; padding:4px;">&#x2715;</button>
					</td>
				</tr>
				<?php
				++$index;
			endforeach;
			?>
			</tbody>
		</table>
		<p style="margin-top:8px;">
			<button type="button" id="spa-slack-channel-map-add" class="button">
				<?php esc_html_e( '+ Add channel', 'sportspress-announcer' ); ?>
			</button>
		</p>
		<script>
		(function () {
			var table   = document.getElementById( 'spa-slack-channel-map-table' );
			var addBtn  = document.getElementById( 'spa-slack-channel-map-add' );
			var opt     = '<?php echo esc_js( self::OPTION_SLACK_CHANNEL_MAP ); ?>';
			var phKey   = '<?php echo esc_js( __( 'Division / competition name', 'sportspress-announcer' ) ); ?>';
			var phUrl   = '<?php echo esc_js( __( 'https://hooks.slack.com/services/…', 'sportspress-announcer' ) ); ?>';

			function nextIndex() {
				return table.querySelectorAll( '.spa-slack-channel-map-row' ).length;
			}

			function bindRemove( btn ) {
				btn.addEventListener( 'click', function () {
					var row = btn.closest( 'tr' );
					row.parentNode.removeChild( row );
					reindex();
				} );
			}

			function reindex() {
				table.querySelectorAll( '.spa-slack-channel-map-row' ).forEach( function ( row, i ) {
					row.querySelectorAll( 'input' ).forEach( function ( input ) {
						input.name = input.name.replace( /\[\d+\]/, '[' + i + ']' );
					} );
				} );
			}

			addBtn.addEventListener( 'click', function () {
				var i     = nextIndex();
				var tbody = table.querySelector( 'tbody' );
				var tr    = document.createElement( 'tr' );
				tr.className = 'spa-slack-channel-map-row';
				tr.innerHTML =
					'<td style="padding:4px 10px 4px 0;">' +
						'<input type="text" name="' + opt + '[' + i + '][key]" value="" class="regular-text" placeholder="' + phKey + '" style="width:100%;"/>' +
					'</td>' +
					'<td style="padding:4px 6px 4px 0;">' +
						'<input type="url" name="' + opt + '[' + i + '][url]" value="" class="regular-text" placeholder="' + phUrl + '" style="width:100%;"/>' +
					'</td>' +
					'<td style="padding:4px 0; text-align:center;">' +
						'<button type="button" class="button-link spa-slack-channel-map-remove" title="Remove" style="color:#a00; padding:4px;">&#x2715;</button>' +
					'</td>';
				tbody.appendChild( tr );
				bindRemove( tr.querySelector( '.spa-slack-channel-map-remove' ) );
				tr.querySelector( 'input' ).focus();
			} );

			table.querySelectorAll( '.spa-slack-channel-map-remove' ).forEach( bindRemove );
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

	/**
	 * Render a settings field registered for this page.
	 *
	 * @param string $page       Settings page slug.
	 * @param string $section_id Settings section ID.
	 * @param string $field_id   Settings field ID.
	 * @return void
	 */
	private function render_registered_field( string $page, string $section_id, string $field_id ): void {
		global $wp_settings_fields;

		$field = $wp_settings_fields[ $page ][ $section_id ][ $field_id ] ?? null;

		if ( ! $field || ! is_callable( $field['callback'] ) ) {
			return;
		}

		call_user_func( $field['callback'], $field['args'] ?? array() );
	}

	/**
	 * Render registered fields that are not already placed in the custom tab layout.
	 *
	 * @param string $page        Settings page slug.
	 * @param string $section_id  Settings section ID.
	 * @param array  $handled_ids Field IDs already rendered.
	 * @return void
	 */
	private function render_unhandled_registered_fields( string $page, string $section_id, array $handled_ids ): void {
		global $wp_settings_fields;

		$fields = $wp_settings_fields[ $page ][ $section_id ] ?? array();

		foreach ( $handled_ids as $field_id ) {
			unset( $fields[ $field_id ] );
		}

		if ( empty( $fields ) ) {
			return;
		}
		?>
		<table class="form-table" role="presentation"><tbody>
			<?php foreach ( array_keys( $fields ) as $field_id ) : ?>
				<?php $this->render_registered_field_row( $page, $section_id, $field_id ); ?>
			<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Render a registered settings field row.
	 *
	 * @param string $page       Settings page slug.
	 * @param string $section_id Settings section ID.
	 * @param string $field_id   Settings field ID.
	 * @return void
	 */
	private function render_registered_field_row( string $page, string $section_id, string $field_id ): void {
		global $wp_settings_fields;

		$field = $wp_settings_fields[ $page ][ $section_id ][ $field_id ] ?? null;

		if ( ! $field ) {
			return;
		}
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $field['title'] ?? '' ); ?></th>
			<td><?php $this->render_registered_field( $page, $section_id, $field_id ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render a registered section callback.
	 *
	 * @param string $page       Settings page slug.
	 * @param string $section_id Settings section ID.
	 * @return void
	 */
	private function render_registered_section_callback( string $page, string $section_id ): void {
		global $wp_settings_sections;

		$section = $wp_settings_sections[ $page ][ $section_id ] ?? null;

		if ( $section && $section['callback'] && '__return_false' !== $section['callback'] ) {
			call_user_func( $section['callback'], $section );
		}
	}

	/**
	 * Render a registered settings section.
	 *
	 * @param string $page       Settings page slug.
	 * @param string $section_id Settings section ID.
	 * @return void
	 */
	private function render_registered_section( string $page, string $section_id ): void {
		global $wp_settings_sections, $wp_settings_fields;

		$section = $wp_settings_sections[ $page ][ $section_id ] ?? null;

		if ( ! $section ) {
			return;
		}
		?>
		<div class="spa-global-section">
			<h3><?php echo esc_html( $section['title'] ); ?></h3>
			<?php $this->render_registered_section_callback( $page, $section_id ); ?>
			<?php if ( isset( $wp_settings_fields[ $page ][ $section_id ] ) ) : ?>
			<table class="form-table" role="presentation"><tbody>
				<?php do_settings_fields( $page, $section_id ); ?>
			</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render any registered sections that are not assigned to a tab.
	 *
	 * @param string $page                Settings page slug.
	 * @param array  $handled_section_ids Section IDs already rendered.
	 * @return void
	 */
	private function render_unhandled_registered_sections( string $page, array $handled_section_ids ): void {
		global $wp_settings_sections;

		$sections = $wp_settings_sections[ $page ] ?? array();

		foreach ( $handled_section_ids as $section_id ) {
			unset( $sections[ $section_id ] );
		}

		foreach ( array_keys( $sections ) as $section_id ) {
			$this->render_registered_section( $page, $section_id );
		}
	}

	// Page.

	/**
	 * Render the Dashboard tab.
	 *
	 * @param bool    $discord_active  Whether a Discord webhook is configured.
	 * @param int     $sent_today      Announcements sent since midnight.
	 * @param int     $log_failed      Total failed entries in the log.
	 * @param array[] $recent_log      Last 3 log entries.
	 * @param int     $log_total       Total log entries.
	 * @param int     $last_digest_ts  Unix timestamp of last digest send (0 if never).
	 * @return void
	 */
	private function render_dashboard_tab( bool $discord_active, int $sent_today, int $log_failed, array $recent_log, int $log_total, int $last_digest_ts ): void {
		$slack_active    = ! empty( get_option( self::OPTION_SLACK_WEBHOOK, '' ) );
		$retry_nonce     = wp_create_nonce( 'spa_retry_nonce' );
		$send_digest_nonce = wp_create_nonce( 'spa_send_digest_nonce' );
		$log_url         = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'log' ), admin_url( 'options-general.php' ) );
		$general_url     = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'general' ), admin_url( 'options-general.php' ) );

		// Upcoming digest preview.
		$notice          = new SPA_Upcoming_Notice();
		$upcoming_games  = $notice->get_upcoming_games();
		$next_date_label = '';
		if ( ! empty( $upcoming_games ) ) {
			$dates           = array_column( $upcoming_games, 'date' );
			sort( $dates );
			$next_date_label = $dates[0];
		}
		?>

		<!-- Status bar -->
		<div class="spa-status-bar">
			<?php if ( $discord_active ) : ?>
				<span class="spa-status-dot spa-status-dot--green"></span>
				<strong><?php esc_html_e( 'Discord', 'sportspress-announcer' ); ?></strong>
			<?php else : ?>
				<span class="spa-status-dot spa-status-dot--gray"></span>
				<span style="color:#8c8f94"><?php esc_html_e( 'Discord', 'sportspress-announcer' ); ?></span>
			<?php endif; ?>
			<span class="spa-status-sep">·</span>
			<span style="color:#8c8f94"><?php esc_html_e( 'Slack', 'sportspress-announcer' ); ?></span>
			<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'sportspress-announcer' ); ?></span>
			<span class="spa-status-divider">|</span>
			<span style="color:#50575e">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of announcements sent today */
						_n( '%d sent today', '%d sent today', $sent_today, 'sportspress-announcer' ),
						$sent_today
					)
				);
				?>
			</span>
			<?php if ( $log_failed > 0 ) : ?>
				<span class="spa-status-divider">|</span>
				<strong style="color:#d63638">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of failed announcements */
							_n( '%d failed', '%d failed', $log_failed, 'sportspress-announcer' ),
							$log_failed
						)
					);
					?>
				</strong>
			<?php endif; ?>
			<a href="<?php echo esc_url( $general_url ); ?>" style="margin-left:auto;font-size:11px;color:#2271b1;white-space:nowrap">
				&#9881; <?php esc_html_e( 'General settings', 'sportspress-announcer' ); ?>
			</a>
		</div>

		<!-- Recent Announcements card -->
		<div class="spa-dashboard-card">
			<div class="spa-dashboard-card-head">
				<span class="spa-dashboard-card-title">&#128226; <?php esc_html_e( 'Recent Announcements', 'sportspress-announcer' ); ?></span>
				<span style="font-size:11px;color:#8c8f94"><?php esc_html_e( 'Auto-posts when a result is saved', 'sportspress-announcer' ); ?></span>
			</div>

			<?php if ( empty( $recent_log ) ) : ?>
				<div class="spa-dashboard-card-row" style="color:#8c8f94;font-style:italic">
					<?php esc_html_e( 'No announcements yet.', 'sportspress-announcer' ); ?>
				</div>
			<?php else : ?>
				<?php foreach ( $recent_log as $entry ) : ?>
					<?php
					$is_failed  = 'failed' === ( $entry['status'] ?? '' );
					$time_label = '';
					$ts         = (int) ( $entry['sent_at'] ?? 0 );
					if ( $ts > 0 ) {
						$diff = time() - $ts;
						if ( $diff < 3600 ) {
							$time_label = sprintf(
								/* translators: %d: minutes ago */
								_n( '%dm', '%dm', (int) floor( $diff / 60 ), 'sportspress-announcer' ),
								(int) floor( $diff / 60 )
							);
						} elseif ( $diff < 86400 ) {
							$time_label = sprintf(
								/* translators: %d: hours ago */
								_n( '%dh', '%dh', (int) floor( $diff / 3600 ), 'sportspress-announcer' ),
								(int) floor( $diff / 3600 )
							);
						} else {
							$time_label = wp_date( 'M j', $ts );
						}
					}
					?>
					<div class="spa-dashboard-card-row<?php echo $is_failed ? ' spa-dashboard-card-row--failed' : ''; ?>">
						<span class="spa-status-dot <?php echo $is_failed ? 'spa-status-dot--red' : 'spa-status-dot--green'; ?>"></span>
						<span class="spa-dashboard-row-label"><?php echo esc_html( $entry['label'] ?? '' ); ?></span>
						<span class="spa-dashboard-row-meta"><?php echo esc_html( $entry['channel'] ?? '' ); ?></span>
						<span class="spa-dashboard-row-time"><?php echo $is_failed ? '<span style="color:#d63638">fail</span>' : esc_html( $time_label ); ?></span>
						<?php if ( $is_failed ) : ?>
							<button type="button" class="button spa-retry-btn" data-uid="<?php echo esc_attr( $entry['uid'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
								<?php esc_html_e( 'Retry', 'sportspress-announcer' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button spa-retry-btn" data-uid="<?php echo esc_attr( $entry['uid'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>" data-resend="1">
								<?php esc_html_e( 'Resend', 'sportspress-announcer' ); ?>
							</button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="spa-dashboard-card-foot">
				<a href="<?php echo esc_url( $log_url ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: total log entry count */
							__( 'See all %d entries in Log →', 'sportspress-announcer' ),
							$log_total
						)
					);
					?>
				</a>
			</div>
		</div>

		<!-- Upcoming Digest card -->
		<div class="spa-dashboard-card">
			<div class="spa-dashboard-card-head">
				<span class="spa-dashboard-card-title">&#128197; <?php esc_html_e( 'Upcoming Digest', 'sportspress-announcer' ); ?></span>
				<?php if ( $next_date_label ) : ?>
					<span style="font-size:11px;color:#646970">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: next fixture date */
								__( 'Next fixtures: %s', 'sportspress-announcer' ),
								$next_date_label
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<div class="spa-dashboard-card-body">
				<?php if ( ! empty( $upcoming_games ) ) : ?>
					<!-- Discord-style preview -->
					<div class="spa-discord-preview">
						<div class="spa-discord-preview-bot"><?php esc_html_e( 'SportsPress Bot', 'sportspress-announcer' ); ?></div>
						<strong><?php esc_html_e( 'Upcoming Games', 'sportspress-announcer' ); ?></strong><br>
						<?php
						$preview_games = array_slice( $upcoming_games, 0, 4 );
						foreach ( $preview_games as $game ) {
							echo '&#127903; ' . esc_html( $game['label'] );
							if ( ! empty( $game['time'] ) ) {
								echo ' · ' . esc_html( $game['time'] );
							}
							echo '<br>';
						}
						$overflow = count( $upcoming_games ) - 4;
						if ( $overflow > 0 ) {
							echo '<span style="color:#72767d;font-size:11px">+ ' . esc_html( (string) $overflow ) . ' ' . esc_html__( 'more', 'sportspress-announcer' ) . '</span>';
						}
						?>
					</div>
				<?php else : ?>
					<p style="color:#8c8f94;font-size:12px;margin:0 0 10px"><?php esc_html_e( 'No upcoming games found in the next 7 days.', 'sportspress-announcer' ); ?></p>
				<?php endif; ?>

				<div style="display:flex;align-items:center;gap:10px;margin-top:10px">
					<button type="button" id="spa-send-digest-btn" class="button button-primary" data-nonce="<?php echo esc_attr( $send_digest_nonce ); ?>" <?php echo empty( $upcoming_games ) ? 'disabled' : ''; ?>>
						<?php esc_html_e( 'Send to Discord now', 'sportspress-announcer' ); ?>
					</button>
					<span id="spa-send-digest-result" style="font-size:12px"></span>
					<div class="spa-pro-lock-inline">
						&#128274; <?php esc_html_e( 'Auto-schedule weekly', 'sportspress-announcer' ); ?>
						<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'sportspress-announcer' ); ?></span>
					</div>
				</div>
			</div>

			<div class="spa-dashboard-card-foot">
				<?php if ( $last_digest_ts > 0 ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: formatted date and time */
							__( 'Last digest: %s', 'sportspress-announcer' ),
							wp_date( 'D M j · g:ia', $last_digest_ts )
						)
					);
					?>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'View in log →', 'sportspress-announcer' ); ?></a>
				<?php else : ?>
					<span style="color:#8c8f94"><?php esc_html_e( 'No digest sent yet.', 'sportspress-announcer' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			// Retry / Resend buttons.
			document.querySelectorAll( '.spa-retry-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var uid     = btn.dataset.uid;
					var nonce   = btn.dataset.nonce;
					var isResend = btn.dataset.resend === '1';
					btn.disabled = true;
					btn.textContent = isResend
						? '<?php echo esc_js( __( 'Sending…', 'sportspress-announcer' ) ); ?>'
						: '<?php echo esc_js( __( 'Retrying…', 'sportspress-announcer' ) ); ?>';
					var fd = new FormData();
					fd.append( 'action', 'spa_retry_announcement' );
					fd.append( 'nonce', nonce );
					fd.append( 'uid', uid );
					fetch( ajaxurl, { method: 'POST', body: fd } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( json ) {
							if ( json.success ) {
								var row = btn.closest( '.spa-dashboard-card-row' );
								if ( row ) {
									row.classList.remove( 'spa-dashboard-card-row--failed' );
									var dot = row.querySelector( '.spa-status-dot' );
									if ( dot ) { dot.className = 'spa-status-dot spa-status-dot--green'; }
								}
								btn.textContent = '<?php echo esc_js( __( 'Resend', 'sportspress-announcer' ) ); ?>';
								btn.dataset.resend = '1';
								btn.disabled = false;
							} else {
								btn.textContent = isResend
									? '<?php echo esc_js( __( 'Resend', 'sportspress-announcer' ) ); ?>'
									: '<?php echo esc_js( __( 'Retry', 'sportspress-announcer' ) ); ?>';
								btn.disabled = false;
								alert( json.data || '<?php echo esc_js( __( 'Request failed.', 'sportspress-announcer' ) ); ?>' );
							}
						} )
						.catch( function () {
							btn.textContent = isResend
								? '<?php echo esc_js( __( 'Resend', 'sportspress-announcer' ) ); ?>'
								: '<?php echo esc_js( __( 'Retry', 'sportspress-announcer' ) ); ?>';
							btn.disabled = false;
						} );
				} );
			} );

			// Send digest button.
			var digestBtn    = document.getElementById( 'spa-send-digest-btn' );
			var digestResult = document.getElementById( 'spa-send-digest-result' );
			if ( digestBtn ) {
				digestBtn.addEventListener( 'click', function () {
					digestBtn.disabled = true;
					digestBtn.textContent = '<?php echo esc_js( __( 'Sending…', 'sportspress-announcer' ) ); ?>';
					if ( digestResult ) { digestResult.textContent = ''; }
					var fd = new FormData();
					fd.append( 'action', 'spa_send_digest' );
					fd.append( 'nonce', digestBtn.dataset.nonce );
					fetch( ajaxurl, { method: 'POST', body: fd } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( json ) {
							digestBtn.disabled = false;
							digestBtn.textContent = '<?php echo esc_js( __( 'Send to Discord now', 'sportspress-announcer' ) ); ?>';
							if ( digestResult ) {
								digestResult.textContent = json.success
									? '<?php echo esc_js( __( '✓ Digest sent', 'sportspress-announcer' ) ); ?>'
									: ( json.data || '<?php echo esc_js( __( '✗ Failed', 'sportspress-announcer' ) ); ?>' );
								digestResult.style.color = json.success ? '#00a32a' : '#d63638';
							}
						} )
						.catch( function () {
							digestBtn.disabled = false;
							digestBtn.textContent = '<?php echo esc_js( __( 'Send to Discord now', 'sportspress-announcer' ) ); ?>';
						} );
				} );
			}
		} );
		</script>
		<?php
	}

	/**
	 * Render the Log tab — filterable history with pagination.
	 *
	 * @return void
	 */
	private function render_log_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$type_filter = isset( $_GET['log_type'] ) && in_array( $_GET['log_type'], array( 'result', 'digest' ), true )
			? sanitize_key( $_GET['log_type'] )
			: '';
		$search      = isset( $_GET['log_search'] ) ? sanitize_text_field( wp_unslash( $_GET['log_search'] ) ) : '';
		$paged       = isset( $_GET['log_paged'] ) ? max( 1, (int) $_GET['log_paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 10;
		$filters  = $type_filter ? array( 'type' => $type_filter ) : array();
		$entries  = SPA_Log::get_page( $per_page, $paged, $filters, $search );
		$total    = SPA_Log::count( $filters );
		// Re-count with search for accurate pagination.
		if ( '' !== $search ) {
			$all_filtered = SPA_Log::get_page( 9999, 1, $filters, $search );
			$total        = count( $all_filtered );
		}
		$total_pages  = (int) ceil( $total / $per_page );
		$retry_nonce  = wp_create_nonce( 'spa_retry_nonce' );
		$base_url     = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'log' ), admin_url( 'options-general.php' ) );

		$count_all    = SPA_Log::count();
		$count_result = SPA_Log::count( array( 'type' => 'result' ) );
		$count_digest = SPA_Log::count( array( 'type' => 'digest' ) );
		?>

		<!-- Filter pills + search -->
		<div class="spa-log-toolbar">
			<div class="spa-filter-pills">
				<a href="<?php echo esc_url( $base_url ); ?>" class="spa-pill<?php echo '' === $type_filter ? ' spa-pill--active' : ''; ?>">
					<?php esc_html_e( 'All', 'sportspress-announcer' ); ?> <span class="spa-pill-count"><?php echo esc_html( (string) $count_all ); ?></span>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'log_type', 'result', $base_url ) ); ?>" class="spa-pill<?php echo 'result' === $type_filter ? ' spa-pill--active' : ''; ?>">
					<?php esc_html_e( 'Results', 'sportspress-announcer' ); ?> <span class="spa-pill-count"><?php echo esc_html( (string) $count_result ); ?></span>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'log_type', 'digest', $base_url ) ); ?>" class="spa-pill<?php echo 'digest' === $type_filter ? ' spa-pill--active' : ''; ?>">
					<?php esc_html_e( 'Digest', 'sportspress-announcer' ); ?> <span class="spa-pill-count"><?php echo esc_html( (string) $count_digest ); ?></span>
				</a>
			</div>
			<form method="get" style="margin-left:auto">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<?php if ( $type_filter ) : ?>
					<input type="hidden" name="log_type" value="<?php echo esc_attr( $type_filter ); ?>">
				<?php endif; ?>
				<input type="search" name="log_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'sportspress-announcer' ); ?>" class="spa-log-search">
			</form>
		</div>

		<!-- Log table -->
		<div class="spa-dashboard-card" style="margin-bottom:8px">
			<!-- Header -->
			<div class="spa-log-row spa-log-row--header">
				<span><?php esc_html_e( 'Type', 'sportspress-announcer' ); ?></span>
				<span><?php esc_html_e( 'Event', 'sportspress-announcer' ); ?></span>
				<span><?php esc_html_e( 'Channel', 'sportspress-announcer' ); ?></span>
				<span><?php esc_html_e( 'Sent', 'sportspress-announcer' ); ?></span>
				<span><?php esc_html_e( 'Status', 'sportspress-announcer' ); ?></span>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<div class="spa-log-row" style="color:#8c8f94;font-style:italic;grid-column:1/-1">
					<?php esc_html_e( 'No entries found.', 'sportspress-announcer' ); ?>
				</div>
			<?php else : ?>
				<?php foreach ( $entries as $entry ) :
					$is_failed  = 'failed' === ( $entry['status'] ?? '' );
					$is_digest  = 'digest' === ( $entry['type'] ?? '' );
					$ts         = (int) ( $entry['sent_at'] ?? 0 );
					$diff       = time() - $ts;
					if ( $diff < 3600 ) {
						$time_label = sprintf(
							/* translators: %d: minutes ago */
							_n( '%dm ago', '%dm ago', (int) floor( $diff / 60 ), 'sportspress-announcer' ),
							(int) floor( $diff / 60 )
						);
					} elseif ( $diff < 86400 ) {
						$time_label = sprintf(
							/* translators: %d: hours ago */
							_n( '%dh ago', '%dh ago', (int) floor( $diff / 3600 ), 'sportspress-announcer' ),
							(int) floor( $diff / 3600 )
						);
					} else {
						$time_label = $ts > 0 ? wp_date( 'M j', $ts ) : '—';
					}
					$row_class = 'spa-log-row';
					if ( $is_failed ) {
						$row_class .= ' spa-log-row--failed';
					} elseif ( $is_digest ) {
						$row_class .= ' spa-log-row--digest';
					}
					$type_label  = 'result' === ( $entry['type'] ?? '' )
						? esc_html__( 'Result', 'sportspress-announcer' )
						: esc_html__( 'Digest', 'sportspress-announcer' );
					$type_color  = 'result' === ( $entry['type'] ?? '' ) ? '#2271b1' : '#996800';
					?>
					<div class="<?php echo esc_attr( $row_class ); ?>">
						<span style="color:<?php echo esc_attr( $type_color ); ?>;font-weight:600"><?php echo $type_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span style="color:#1d2327"><?php echo esc_html( $entry['label'] ?? '' ); ?></span>
						<span style="color:#50575e"><?php echo esc_html( $entry['channel'] ?? '' ); ?></span>
						<span style="color:#8c8f94"><?php echo esc_html( $time_label ); ?></span>
						<?php if ( $is_failed ) : ?>
							<button type="button" class="button spa-retry-btn spa-log-retry-btn" data-uid="<?php echo esc_attr( $entry['uid'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
								&#10007; <?php esc_html_e( 'Retry', 'sportspress-announcer' ); ?>
							</button>
						<?php else : ?>
							<span style="color:#00a32a;font-weight:600">&#10003; <?php esc_html_e( 'Sent', 'sportspress-announcer' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<!-- Pagination -->
		<div class="spa-log-pagination">
			<span>
				<?php
				$from = min( ( $paged - 1 ) * $per_page + 1, $total );
				$to   = min( $paged * $per_page, $total );
				echo esc_html(
					sprintf(
						/* translators: 1: first entry, 2: last entry, 3: total */
						__( 'Showing %1$d–%2$d of %3$d entries', 'sportspress-announcer' ),
						$from,
						$to,
						$total
					)
				);
				?>
			</span>
			<div style="display:flex;gap:4px">
				<?php if ( $paged > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'log_paged', $paged - 1, $base_url ) ); ?>" class="button"><?php esc_html_e( '← Prev', 'sportspress-announcer' ); ?></a>
				<?php else : ?>
					<button class="button" disabled><?php esc_html_e( '← Prev', 'sportspress-announcer' ); ?></button>
				<?php endif; ?>
				<?php if ( $paged < $total_pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'log_paged', $paged + 1, $base_url ) ); ?>" class="button button-primary"><?php esc_html_e( 'Next →', 'sportspress-announcer' ); ?></a>
				<?php else : ?>
					<button class="button" disabled><?php esc_html_e( 'Next →', 'sportspress-announcer' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.spa-log-retry-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var uid   = btn.dataset.uid;
					var nonce = btn.dataset.nonce;
					btn.disabled = true;
					btn.textContent = '<?php echo esc_js( __( 'Retrying…', 'sportspress-announcer' ) ); ?>';
					var fd = new FormData();
					fd.append( 'action', 'spa_retry_announcement' );
					fd.append( 'nonce', nonce );
					fd.append( 'uid', uid );
					fetch( ajaxurl, { method: 'POST', body: fd } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( json ) {
							if ( json.success ) {
								var row = btn.closest( '.spa-log-row' );
								if ( row ) {
									row.classList.remove( 'spa-log-row--failed' );
									btn.replaceWith( Object.assign( document.createElement( 'span' ), {
										style: 'color:#00a32a;font-weight:600',
										textContent: '✓ <?php echo esc_js( __( 'Sent', 'sportspress-announcer' ) ); ?>',
									} ) );
								}
							} else {
								btn.disabled = false;
								btn.textContent = '✗ <?php echo esc_js( __( 'Retry', 'sportspress-announcer' ) ); ?>';
								alert( json.data || '<?php echo esc_js( __( 'Request failed.', 'sportspress-announcer' ) ); ?>' );
							}
						} )
						.catch( function () {
							btn.disabled = false;
							btn.textContent = '✗ <?php echo esc_js( __( 'Retry', 'sportspress-announcer' ) ); ?>';
						} );
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Render the plugin settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = self::MENU_SLUG;

		$discord_url           = get_option( self::OPTION_WEBHOOK, '' );
		$discord_active        = ! empty( $discord_url );
		$discord_channel_count = count( (array) get_option( self::OPTION_DISCORD_CHANNEL_MAP, array() ) );

		// Active tab — server-side, falls back to dashboard.
		$allowed_tabs = array( 'dashboard', 'channels', 'digest', 'templates', 'general', 'log' );
		$active_tab   = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $allowed_tabs, true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'dashboard';

		// Log data for Dashboard + Log tabs.
		$log            = SPA_Log::get_all();
		$log_total      = count( $log );
		$log_failed     = count( array_filter( $log, static fn( $e ) => 'failed' === ( $e['status'] ?? '' ) ) );
		$sent_today     = count(
			array_filter(
				$log,
				static fn( $e ) => ( $e['sent_at'] ?? 0 ) >= strtotime( 'today midnight' )
			)
		);
		$last_digest_ts = (int) get_option( 'spa_last_digest_sent', 0 );
		$recent_log     = array_slice( $log, 0, 3 );
		$handled_sections      = array(
			'spa_section_sportspress',
			'spa_section_discord',
			'spa_section_slack',
			'spa_section_facebook',
			'spa_section_digest',
			'spa_section_announcements',
		);
		$discord_fields        = array(
			self::OPTION_DISCORD_ENABLED,
			self::OPTION_WEBHOOK,
			self::OPTION_DISCORD_CHANNEL_MAP,
		);
		$slack_fields          = array(
			self::OPTION_SLACK_ENABLED,
			self::OPTION_SLACK_WEBHOOK,
			self::OPTION_SLACK_CHANNEL_MAP,
		);

		// Determine Quick Start checklist state.
		$qs_raw       = get_user_meta( get_current_user_id(), self::QS_USER_META, true );
		$qs_dismissed = is_array( $qs_raw ) ? $qs_raw : array();
		$qs_connected = $discord_active || ! empty( get_option( self::OPTION_SLACK_WEBHOOK, '' ) ) || ! empty( $qs_dismissed['connected'] );
		$qs_templated = ( get_option( self::OPTION_RESULT_TEMPLATE, self::DEFAULT_RESULT_TEMPLATE ) !== self::DEFAULT_RESULT_TEMPLATE ) || ! empty( $qs_dismissed['templated'] );
		$qs_tested    = ! empty( $qs_dismissed['tested'] );
		$qs_published = ! empty( $qs_dismissed['published'] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'spa_settings_group' ); ?>

				<div class="spa-page-wrap">

					<!-- Main content -->
					<div class="spa-main">

						<!-- Tab nav -->
						<nav class="spa-tabs" role="tablist">
							<button type="button" class="spa-tab<?php echo 'dashboard' === $active_tab ? ' is-active' : ''; ?>" data-tab="dashboard" role="tab" aria-selected="<?php echo 'dashboard' === $active_tab ? 'true' : 'false'; ?>">
								<?php esc_html_e( 'Dashboard', 'sportspress-announcer' ); ?>
							</button>
							<button type="button" class="spa-tab<?php echo 'channels' === $active_tab ? ' is-active' : ''; ?>" data-tab="channels" role="tab" aria-selected="<?php echo 'channels' === $active_tab ? 'true' : 'false'; ?>">
								<?php esc_html_e( 'Channels', 'sportspress-announcer' ); ?>
								<?php if ( $discord_active ) : ?>
									<span class="spa-tab-badge"><?php echo esc_html( (string) max( 1, $discord_channel_count ) ); ?></span>
								<?php endif; ?>
							</button>
							<button type="button" class="spa-tab<?php echo 'digest' === $active_tab ? ' is-active' : ''; ?>" data-tab="digest" role="tab" aria-selected="<?php echo 'digest' === $active_tab ? 'true' : 'false'; ?>">
								<?php esc_html_e( 'Digest', 'sportspress-announcer' ); ?>
							</button>
							<button type="button" class="spa-tab<?php echo 'templates' === $active_tab ? ' is-active' : ''; ?>" data-tab="templates" role="tab" aria-selected="<?php echo 'templates' === $active_tab ? 'true' : 'false'; ?>">
								<?php esc_html_e( 'Templates', 'sportspress-announcer' ); ?>
							</button>
							<button type="button" class="spa-tab<?php echo 'general' === $active_tab ? ' is-active' : ''; ?>" data-tab="general" role="tab" aria-selected="<?php echo 'general' === $active_tab ? 'true' : 'false'; ?>">
								<?php esc_html_e( 'General', 'sportspress-announcer' ); ?>
							</button>
							<button type="button" class="spa-tab<?php echo 'log' === $active_tab ? ' is-active' : ''; ?>" data-tab="log" role="tab" aria-selected="<?php echo 'log' === $active_tab ? 'true' : 'false'; ?>">
								<?php esc_html_e( 'Log', 'sportspress-announcer' ); ?>
							</button>
						</nav>

						<!-- Dashboard tab -->
						<div id="spa-panel-dashboard" class="spa-panel<?php echo 'dashboard' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
							<?php $this->render_dashboard_tab( $discord_active, $sent_today, $log_failed, $recent_log, $log_total, $last_digest_ts ); ?>
						</div>

						<!-- General tab -->
						<div id="spa-panel-general" class="spa-panel<?php echo 'general' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
							<?php
							$this->render_registered_section( $page, 'spa_section_sportspress' );
							$this->render_unhandled_registered_sections( $page, $handled_sections );
							?>
							<?php submit_button( __( 'Save Settings', 'sportspress-announcer' ) ); ?>
						</div>

						<!-- Channels tab -->
						<div id="spa-panel-channels" class="spa-panel<?php echo 'channels' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">

							<!-- Discord -->
							<div class="spa-integration-card">
								<div class="spa-integration-card-head">
									<div class="spa-integration-card-title">
										<span class="spa-platform-icon spa-platform-icon--discord">D</span>
										<?php esc_html_e( 'Discord', 'sportspress-announcer' ); ?>
									</div>
									<?php if ( $discord_active ) : ?>
										<span class="spa-status-active">&#9679; <?php esc_html_e( 'Active', 'sportspress-announcer' ); ?></span>
									<?php endif; ?>
								</div>

								<!-- Enabled toggle -->
								<div class="spa-integration-card-body spa-section-alt">
									<div class="spa-section-label"><?php esc_html_e( 'Announcements', 'sportspress-announcer' ); ?></div>
									<?php $this->render_registered_field( $page, 'spa_section_discord', self::OPTION_DISCORD_ENABLED ); ?>
								</div>

								<!-- Default webhook -->
								<div class="spa-integration-card-body spa-section-alt">
									<div class="spa-section-label"><?php esc_html_e( 'Default Channel', 'sportspress-announcer' ); ?></div>
									<?php $this->render_registered_field( $page, 'spa_section_discord', self::OPTION_WEBHOOK ); ?>
								</div>

								<!-- Channel routing -->
								<div class="spa-integration-card-body">
									<div class="spa-section-label"><?php esc_html_e( 'Channel Routing', 'sportspress-announcer' ); ?></div>
									<?php $this->render_registered_field( $page, 'spa_section_discord', self::OPTION_DISCORD_CHANNEL_MAP ); ?>
								</div>

								<?php $this->render_unhandled_registered_fields( $page, 'spa_section_discord', $discord_fields ); ?>
							</div>

							<!-- Slack (Pro) -->
							<div class="spa-integration-card">
								<div class="spa-integration-card-head">
									<div class="spa-integration-card-title">
										<span class="spa-platform-icon spa-platform-icon--slack">S</span>
										<?php esc_html_e( 'Slack', 'sportspress-announcer' ); ?>
										<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'sportspress-announcer' ); ?></span>
									</div>
								</div>
								<div class="spa-integration-card-body">
									<?php $this->render_registered_section_callback( $page, 'spa_section_slack' ); ?>
								</div>
								<div class="spa-integration-card-body spa-section-alt">
									<div class="spa-section-label"><?php esc_html_e( 'Announcements', 'sportspress-announcer' ); ?></div>
									<?php $this->render_registered_field( $page, 'spa_section_slack', self::OPTION_SLACK_ENABLED ); ?>
								</div>
								<div class="spa-integration-card-body spa-section-alt">
									<div class="spa-section-label"><?php esc_html_e( 'Webhook URL', 'sportspress-announcer' ); ?></div>
									<?php $this->render_registered_field( $page, 'spa_section_slack', self::OPTION_SLACK_WEBHOOK ); ?>
								</div>
								<div class="spa-integration-card-body">
									<div class="spa-section-label"><?php esc_html_e( 'Channel Routing', 'sportspress-announcer' ); ?></div>
									<?php $this->render_registered_field( $page, 'spa_section_slack', self::OPTION_SLACK_CHANNEL_MAP ); ?>
								</div>

								<?php $this->render_unhandled_registered_fields( $page, 'spa_section_slack', $slack_fields ); ?>
							</div>

							<!-- Facebook (Pro) -->
							<div class="spa-integration-card spa-integration-card--locked">
								<div class="spa-integration-card-head">
									<div class="spa-integration-card-title">
										<span class="spa-platform-icon spa-platform-icon--facebook">f</span>
										<?php esc_html_e( 'Facebook', 'sportspress-announcer' ); ?>
										<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'sportspress-announcer' ); ?></span>
									</div>
									<a href="https://sportspress-announcer.com/pro" target="_blank" rel="noopener" style="font-size:11px;color:#2271b1;">
										<?php esc_html_e( 'Upgrade to unlock →', 'sportspress-announcer' ); ?>
									</a>
								</div>
							</div>

							<?php submit_button( __( 'Save Settings', 'sportspress-announcer' ) ); ?>
						</div>

						<!-- Digest tab -->
						<div id="spa-panel-digest" class="spa-panel<?php echo 'digest' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
							<?php $this->render_registered_section( $page, 'spa_section_digest' ); ?>
							<?php submit_button( __( 'Save Settings', 'sportspress-announcer' ) ); ?>
						</div>

						<!-- Templates tab -->
							<div id="spa-panel-templates" class="spa-panel<?php echo 'templates' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
								<?php
								foreach ( array( 'spa_section_announcements', 'spa_section_facebook' ) as $section_id ) {
									$this->render_registered_section( $page, $section_id );
								}
								?>
								<?php submit_button( __( 'Save Settings', 'sportspress-announcer' ) ); ?>
							</div>

						<!-- Log tab -->
						<div id="spa-panel-log" class="spa-panel<?php echo 'log' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
							<?php $this->render_log_tab(); ?>
						</div>

					</div><!-- .spa-main -->

					<!-- Help sidebar -->
					<div class="spa-help-sidebar">
						<h3><?php esc_html_e( 'Quick Start', 'sportspress-announcer' ); ?></h3>
						<ul class="spa-checklist">
							<li>
								<span class="spa-check-done">✓</span>
								<span><?php esc_html_e( 'Activate plugin', 'sportspress-announcer' ); ?></span>
							</li>
							<li class="spa-qs-item<?php echo $qs_connected ? ' is-done' : ''; ?>" data-item="connected" title="<?php esc_attr_e( 'Click to mark done', 'sportspress-announcer' ); ?>">
								<span class="spa-qs-icon"><?php echo $qs_connected ? '✓' : '○'; ?></span>
								<span><?php esc_html_e( 'Connect a channel', 'sportspress-announcer' ); ?></span>
							</li>
							<li class="spa-qs-item<?php echo $qs_templated ? ' is-done' : ''; ?>" data-item="templated" title="<?php esc_attr_e( 'Click to mark done', 'sportspress-announcer' ); ?>">
								<span class="spa-qs-icon"><?php echo $qs_templated ? '✓' : '○'; ?></span>
								<span><?php esc_html_e( 'Customize result template', 'sportspress-announcer' ); ?></span>
							</li>
							<li class="spa-qs-item<?php echo $qs_tested ? ' is-done' : ''; ?>" data-item="tested" title="<?php esc_attr_e( 'Click to mark done', 'sportspress-announcer' ); ?>">
								<span class="spa-qs-icon"><?php echo $qs_tested ? '✓' : '○'; ?></span>
								<span><?php esc_html_e( 'Send a test announcement', 'sportspress-announcer' ); ?></span>
							</li>
							<li class="spa-qs-item<?php echo $qs_published ? ' is-done' : ''; ?>" data-item="published" title="<?php esc_attr_e( 'Click to mark done', 'sportspress-announcer' ); ?>">
								<span class="spa-qs-icon"><?php echo $qs_published ? '✓' : '○'; ?></span>
								<span><?php esc_html_e( 'Publish your first result', 'sportspress-announcer' ); ?></span>
							</li>
						</ul>

						<div class="spa-help-divider"></div>

						<h4 id="spa-help-tab-title"><?php esc_html_e( 'On this tab', 'sportspress-announcer' ); ?></h4>
						<p class="spa-help-tip" id="spa-help-tab-tip">
							<?php esc_html_e( 'Your score column key must match the result column slug set up in SportsPress → Result Columns.', 'sportspress-announcer' ); ?>
						</p>

						<div class="spa-help-links">
							<a href="https://support.discord.com/hc/en-us/articles/228383668" target="_blank" rel="noopener">📖 <?php esc_html_e( 'How to create a webhook', 'sportspress-announcer' ); ?></a>
							<a href="https://sportspress-announcer.com/docs" target="_blank" rel="noopener">? <?php esc_html_e( 'Full docs →', 'sportspress-announcer' ); ?></a>
						</div>
					</div><!-- .spa-help-sidebar -->

				</div><!-- .spa-page-wrap -->
			</form>
		</div>

		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			// ── Tab switching ──
			var tabs   = document.querySelectorAll( '.spa-tab' );
			var panels = document.querySelectorAll( '.spa-panel' );

			var tips = {
				dashboard: '<?php echo esc_js( __( 'Your daily cockpit — platform status, recent announcements, and upcoming digest.', 'sportspress-announcer' ) ); ?>',
				channels:  '<?php echo esc_js( __( 'One Discord channel handles most leagues. Add routing rules only if you run multiple divisions.', 'sportspress-announcer' ) ); ?>',
				digest:    '<?php echo esc_js( __( 'The digest lists upcoming games for the next 7 days. Use auto-send to post it to Discord on a schedule.', 'sportspress-announcer' ) ); ?>',
				templates: '<?php echo esc_js( __( 'Click any placeholder chip to insert it into the template. Team names are auto-bolded on each platform.', 'sportspress-announcer' ) ); ?>',
				general:   '<?php echo esc_js( __( 'Your score column key must match the result column slug set up in SportsPress → Result Columns.', 'sportspress-announcer' ) ); ?>',
				log:       '<?php echo esc_js( __( 'Full history of every announcement sent. Filter by type or search by event name.', 'sportspress-announcer' ) ); ?>',
			};

			if ( tipEl && tips[ '<?php echo esc_js( $active_tab ); ?>' ] ) {
				tipEl.textContent = tips[ '<?php echo esc_js( $active_tab ); ?>' ];
			}

			var tipEl = document.getElementById( 'spa-help-tab-tip' );

			tabs.forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					var target = tab.dataset.tab;
					tabs.forEach( function ( t ) {
						t.classList.remove( 'is-active' );
						t.setAttribute( 'aria-selected', 'false' );
					} );
					panels.forEach( function ( p ) { p.classList.remove( 'is-active' ); } );
					tab.classList.add( 'is-active' );
					tab.setAttribute( 'aria-selected', 'true' );
					var panel = document.getElementById( 'spa-panel-' + target );
					if ( panel ) { panel.classList.add( 'is-active' ); }
					if ( tipEl && tips[ target ] ) { tipEl.textContent = tips[ target ]; }
				} );
			} );

			// ── Quick Start checklist ──
			var qsNonce = '<?php echo esc_js( wp_create_nonce( 'spa_qs_dismiss_nonce' ) ); ?>';

			function spaQsSetUI( li, done ) {
				var icon = li.querySelector( '.spa-qs-icon' );
				if ( done ) {
					li.classList.add( 'is-done' );
					if ( icon ) { icon.textContent = '✓'; }
				} else {
					li.classList.remove( 'is-done' );
					if ( icon ) { icon.textContent = '○'; }
				}
			}

			function spaQsMark( item, done ) {
				var li = document.querySelector( '.spa-qs-item[data-item="' + item + '"]' );
				if ( ! li ) { return; }
				spaQsSetUI( li, done );
				var fd = new FormData();
				fd.append( 'action', 'spa_qs_dismiss' );
				fd.append( 'nonce', qsNonce );
				fd.append( 'item', item );
				fd.append( 'checked', done ? '1' : '0' );
				fetch( ajaxurl, { method: 'POST', body: fd } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( ! json.success ) {
							// Revert optimistic UI and log.
							spaQsSetUI( li, ! done );
							window.console && console.warn( 'SPA QS save failed', json );
						}
					} )
					.catch( function ( err ) {
						spaQsSetUI( li, ! done );
						window.console && console.warn( 'SPA QS request failed', err );
					} );
			}

			// Click to toggle any unchecked (or checked) item.
			document.querySelectorAll( '.spa-qs-item' ).forEach( function ( li ) {
				li.addEventListener( 'click', function () {
					var isDone = li.classList.contains( 'is-done' );
					spaQsMark( li.dataset.item, ! isDone );
				} );
			} );

			// Auto-check "tested" when a Discord or Slack test message succeeds.
			function observeTestSuccess( resultElId ) {
				var el = document.getElementById( resultElId );
				if ( ! el ) { return; }
				var obs = new MutationObserver( function () {
					if ( el.style.color === 'rgb(70, 180, 80)' || el.style.color === '#46b450' ) {
						spaQsMark( 'tested', true );
					}
				} );
				obs.observe( el, { attributes: true, childList: true, subtree: true } );
			}
			observeTestSuccess( 'spa-test-result' );
			observeTestSuccess( 'spa-test-slack-result' );

			// Auto-check "published" when the digest publish succeeds.
			var publishResult = document.getElementById( 'spa-publish-result' );
			if ( publishResult ) {
				new MutationObserver( function () {
					if ( publishResult.style.color === 'rgb(70, 180, 80)' || publishResult.style.color === '#46b450' ) {
						spaQsMark( 'published', true );
					}
				} ).observe( publishResult, { attributes: true, childList: true, subtree: true } );
			}
		} );
		</script>
		<?php
	}
}
