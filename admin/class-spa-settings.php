<?php
/**
 * Settings page: Settings → Announcer for SportsPress.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Announcer for SportsPress settings page.
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
	// (option keys delegated to SPA_Daily_Digest_Scheduler).

	private const MENU_SLUG = 'announcer-for-sportspress';

	/**
	 * Register settings-page callbacks.
	 */
	private const QS_USER_META = 'spa_qs_dismissed';

	/**
	 * Whether the shared confirm-before-send modal script has already been
	 * printed on this request. Prevents emitting the helper twice when more
	 * than one send button renders on the same page.
	 *
	 * @var bool
	 */
	private $confirm_modal_printed = false;

	/**
	 * Register the admin menu, settings, and AJAX handlers.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spa_test_webhook', array( $this, 'ajax_test_webhook' ) );
		add_action( 'wp_ajax_spa_test_slack_webhook', array( $this, 'ajax_test_slack_webhook' ) );
		add_action( 'wp_ajax_spa_qs_dismiss', array( $this, 'ajax_qs_dismiss' ) );
		add_action( 'wp_ajax_spa_retry_announcement', array( $this, 'ajax_retry_announcement' ) );
		add_action( 'wp_ajax_spa_generate_digest_preview', array( $this, 'ajax_generate_digest_preview' ) );
		add_action( 'wp_ajax_spa_send_weekly_digest_now', array( $this, 'ajax_send_weekly_digest_now' ) );
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
			wp_send_json_error( __( 'Permission denied.', 'announcer-for-sportspress' ) );
		}

		$uid = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );
		if ( '' === $uid ) {
			wp_send_json_error( __( 'Missing entry ID.', 'announcer-for-sportspress' ) );
		}

		$entry = $this->find_log_entry( $uid );
		if ( null === $entry ) {
			wp_send_json_error( __( 'Log entry not found.', 'announcer-for-sportspress' ) );
		}

		if ( 'result' === $entry['type'] ) {
			$this->retry_result_announcement( $uid, $entry );
		}

		// Digest retry: send directly without going through send_digest(), which would
		// write a new log entry and leave the original failed row unchanged.
		if ( 'digest' === $entry['type'] ) {
			$this->retry_digest_announcement( $uid, $entry );
		}

		wp_send_json_error( __( 'Unknown entry type.', 'announcer-for-sportspress' ) );
	}

	/**
	 * Find a log entry by its stable uid (array index is not stable).
	 *
	 * @param string $uid Log entry uid.
	 * @return array|null
	 */
	private function find_log_entry( string $uid ): ?array {
		foreach ( SPA_Log::get_all() as $candidate ) {
			if ( ( $candidate['uid'] ?? '' ) === $uid ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Re-send a failed result announcement. Ends the request via wp_send_json_*.
	 *
	 * @param string $uid   Log entry uid.
	 * @param array  $entry Log entry.
	 * @return void
	 */
	private function retry_result_announcement( string $uid, array $entry ): void {
		$platform    = $entry['platform'] ?? 'discord';
		$competition = $entry['competition'] ?? '';
		$post_id     = (int) ( $entry['id'] ?? 0 );

		$post = get_post( $post_id );
		if ( ! $post || 'sp_event' !== $post->post_type ) {
			wp_send_json_error( __( 'Event post not found.', 'announcer-for-sportspress' ) );
		}

		$handler   = new SPA_Event_Handler();
		$formatter = new SPA_Message_Formatter();

		$event = $handler->extract_event_data( $post_id );
		if ( ! $event ) {
			wp_send_json_error( __( 'Could not read event data.', 'announcer-for-sportspress' ) );
		}

		if ( 'discord' === $platform ) {
			$channel_map = (array) get_option( self::OPTION_DISCORD_CHANNEL_MAP, array() );
			$webhook_url = ( $competition && ! empty( $channel_map[ $competition ] ) )
				? $channel_map[ $competition ]
				: get_option( self::OPTION_WEBHOOK, '' );

			if ( empty( $webhook_url ) ) {
				wp_send_json_error( __( 'No Discord webhook configured.', 'announcer-for-sportspress' ) );
			}

			$result = ( new SPA_Webhook_Discord( $webhook_url ) )->send( $formatter->format_embed( $event ) );
		} else {
			$channel_map = (array) get_option( self::OPTION_SLACK_CHANNEL_MAP, array() );
			$webhook_url = ( $competition && ! empty( $channel_map[ $competition ] ) )
				? $channel_map[ $competition ]
				: get_option( self::OPTION_SLACK_WEBHOOK, '' );

			if ( empty( $webhook_url ) ) {
				wp_send_json_error( __( 'No Slack webhook configured.', 'announcer-for-sportspress' ) );
			}

			$result = ( new SPA_Webhook_Slack( $webhook_url ) )->send( $formatter->format_slack( $event ) );
		}

		$this->finish_retry( $uid, $result );
	}

	/**
	 * Re-send a failed upcoming-fixtures digest. Ends the request.
	 *
	 * @param string $uid   Log entry uid.
	 * @param array  $entry Log entry.
	 * @return void
	 */
	private function retry_digest_announcement( string $uid, array $entry ): void {
		$platform = $entry['platform'] ?? 'discord';

		$notice = new SPA_Upcoming_Notice();
		$games  = $notice->get_upcoming_games();

		if ( empty( $games ) ) {
			wp_send_json_error( __( 'No upcoming games found.', 'announcer-for-sportspress' ) );
		}

		if ( 'discord' === $platform ) {
			$webhook_url = get_option( self::OPTION_WEBHOOK, '' );
			if ( empty( $webhook_url ) ) {
				wp_send_json_error( __( 'No Discord webhook configured.', 'announcer-for-sportspress' ) );
			}
			$sender  = new SPA_Upcoming_Discord();
			$payload = array(
				'embeds' => array(
					array(
						'title'       => __( 'Upcoming Games', 'announcer-for-sportspress' ),
						'description' => $sender->build_description( $games ),
						'color'       => 0x5865F2,
					),
				),
			);
			$result  = ( new SPA_Webhook_Discord( $webhook_url ) )->send( $payload );
		} else {
			$webhook_url = get_option( self::OPTION_SLACK_WEBHOOK, '' );
			if ( empty( $webhook_url ) ) {
				wp_send_json_error( __( 'No Slack webhook configured.', 'announcer-for-sportspress' ) );
			}
			$sender  = new SPA_Upcoming_Slack();
			$mrkdwn  = $sender->build_mrkdwn( $games );
			$payload = array(
				'text'   => __( 'Upcoming Games', 'announcer-for-sportspress' ),
				'blocks' => array(
					array(
						'type' => 'header',
						'text' => array(
							'type'  => 'plain_text',
							'text'  => __( 'Upcoming Games', 'announcer-for-sportspress' ),
							'emoji' => true,
						),
					),
					array(
						'type' => 'section',
						'text' => array(
							'type' => 'mrkdwn',
							'text' => $mrkdwn,
						),
					),
				),
			);
			$result  = ( new SPA_Webhook_Slack( $webhook_url ) )->send( $payload );
		}

		$this->finish_retry( $uid, $result );
	}

	/**
	 * Mark a retried entry as sent on success, or return the error. Ends request.
	 *
	 * @param string $uid    Log entry uid.
	 * @param mixed  $result Webhook send result (WP_Error on failure).
	 * @return void
	 */
	private function finish_retry( string $uid, $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		SPA_Log::update_entry(
			$uid,
			array(
				'status'  => 'sent',
				'sent_at' => time(),
			)
		);
		wp_send_json_success();
	}

	/**
	 * Send the Weekly Recap for one league on demand.
	 *
	 * Mirrors the cron dispatch but for a single, user-chosen league, bypassing
	 * the 23h idempotency guard because the send is deliberate.
	 *
	 * @return void
	 */
	public function ajax_send_weekly_digest_now(): void {
		check_ajax_referer( 'spa_send_weekly_digest_now_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'announcer-for-sportspress' ) );
		}

		$league_id = isset( $_POST['league_id'] ) ? intval( wp_unslash( $_POST['league_id'] ) ) : 0;
		if ( $league_id <= 0 ) {
			wp_send_json_error( __( 'Choose a league first.', 'announcer-for-sportspress' ) );
		}

		$result = ( new SPA_Weekly_Digest_Scheduler() )->send_now( $league_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
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
			__( 'Announcer for SportsPress', 'announcer-for-sportspress' ),
			__( 'Announcer for SportsPress', 'announcer-for-sportspress' ),
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
		wp_enqueue_script(
			'spa-quickstart',
			SPA_PLUGIN_URL . 'assets/js/spa-quickstart.js',
			array(),
			SPA_VERSION,
			true
		);
		wp_localize_script(
			'spa-quickstart',
			'SPA_QS',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'spa_qs_dismiss_nonce' ),
			)
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
		$this->register_digest_settings();
		$this->register_announcements_settings();
		$this->register_sportspress_settings();
		$this->register_discord_settings();
		$this->register_slack_settings();
		$this->register_facebook_settings();
	}

	/**
	 * Register the score-column setting. The field renders on the Templates
	 * tab (spa_section_announcements) next to the templates it feeds.
	 *
	 * @return void
	 */
	private function register_sportspress_settings(): void {
		register_setting(
			'spa_settings_group',
			self::OPTION_SCORE_COLUMN,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => self::DEFAULT_SCORE_COLUMN,
			)
		);

		add_settings_field(
			self::OPTION_SCORE_COLUMN,
			__( 'Score Column', 'announcer-for-sportspress' ),
			array( $this, 'render_score_column_field' ),
			self::MENU_SLUG,
			'spa_section_announcements'
		);
	}

	/**
	 * Register the Digest settings section (templates + schedule).
	 *
	 * @return void
	 */
	private function register_digest_settings(): void {
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
			__( 'Fixtures Digest', 'announcer-for-sportspress' ),
			array( $this, 'render_digest_section_intro' ),
			self::MENU_SLUG
		);

		// The Fixtures Template editor lives on the Templates tab (see
		// register_announcements_settings) so all message copy is in one place.

		add_settings_field(
			'spa_upcoming_publish',
			__( 'Send now', 'announcer-for-sportspress' ),
			array( $this, 'render_upcoming_publish_field' ),
			self::MENU_SLUG,
			'spa_section_digest'
		);

		$this->register_digest_schedule_settings();

		add_settings_field(
			'spa_digest_schedule',
			__( 'Auto-send', 'announcer-for-sportspress' ),
			array( $this, 'render_digest_schedule_field' ),
			self::MENU_SLUG,
			'spa_section_digest'
		);

		$this->register_weekly_digest_settings();
	}

	/**
	 * Register the daily-digest auto-send schedule options.
	 *
	 * @return void
	 */
	private function register_digest_schedule_settings(): void {
		register_setting(
			'spa_settings_group',
			SPA_Daily_Digest_Scheduler::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'spa_settings_group',
			SPA_Daily_Digest_Scheduler::OPTION_FREQUENCY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_frequency' ),
				'default'           => 'weekly',
			)
		);

		register_setting(
			'spa_settings_group',
			SPA_Daily_Digest_Scheduler::OPTION_DAY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_day' ),
				'default'           => 'monday',
			)
		);

		register_setting(
			'spa_settings_group',
			SPA_Daily_Digest_Scheduler::OPTION_TIME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_time' ),
				'default'           => '08:00',
			)
		);
	}

	/**
	 * Register the Announcements section (result message template).
	 *
	 * @return void
	 */
	private function register_announcements_settings(): void {
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
			__( 'Announcements', 'announcer-for-sportspress' ),
			array( $this, 'render_announcements_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPTION_RESULT_TEMPLATE,
			__( 'Result Template', 'announcer-for-sportspress' ),
			array( $this, 'render_result_template_field' ),
			self::MENU_SLUG,
			'spa_section_announcements'
		);

		// Fixtures Template — relocated here from the Digest tab so every
		// message template lives on the Templates tab.
		add_settings_field(
			self::OPTION_UPCOMING_TEMPLATE,
			__( 'Fixtures Template', 'announcer-for-sportspress' ),
			array( $this, 'render_upcoming_template_field' ),
			self::MENU_SLUG,
			'spa_section_announcements'
		);
	}

	/**
	 * Register the Discord settings section.
	 *
	 * @return void
	 */
	private function register_discord_settings(): void {
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

		add_settings_section( 'spa_section_discord', __( 'Discord', 'announcer-for-sportspress' ), '__return_false', self::MENU_SLUG );

		add_settings_field(
			self::OPTION_DISCORD_ENABLED,
			__( 'Announcements', 'announcer-for-sportspress' ),
			array( $this, 'render_discord_enabled_field' ),
			self::MENU_SLUG,
			'spa_section_discord'
		);

		add_settings_field(
			self::OPTION_WEBHOOK,
			__( 'Webhook URL', 'announcer-for-sportspress' ),
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
			__( 'Channel Routing', 'announcer-for-sportspress' ),
			array( $this, 'render_channel_map_field' ),
			self::MENU_SLUG,
			'spa_section_discord'
		);
	}

	/**
	 * Register the Slack (Pro) settings section.
	 *
	 * @return void
	 */
	private function register_slack_settings(): void {
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
			__( 'Slack (Pro)', 'announcer-for-sportspress' ),
			array( $this, 'render_slack_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPTION_SLACK_ENABLED,
			__( 'Announcements', 'announcer-for-sportspress' ),
			array( $this, 'render_slack_enabled_field' ),
			self::MENU_SLUG,
			'spa_section_slack'
		);

		add_settings_field(
			self::OPTION_SLACK_WEBHOOK,
			__( 'Webhook URL', 'announcer-for-sportspress' ),
			array( $this, 'render_slack_webhook_field' ),
			self::MENU_SLUG,
			'spa_section_slack'
		);

		$this->register_slack_channel_map();
	}

	/**
	 * Register the Slack per-competition channel routing option.
	 *
	 * @return void
	 */
	private function register_slack_channel_map(): void {
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
			__( 'Channel Routing', 'announcer-for-sportspress' ),
			array( $this, 'render_slack_channel_map_field' ),
			self::MENU_SLUG,
			'spa_section_slack'
		);
	}

	/**
	 * Register the Facebook settings section.
	 *
	 * @return void
	 */
	private function register_facebook_settings(): void {
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

		add_settings_section( 'spa_section_facebook', __( 'Facebook', 'announcer-for-sportspress' ), '__return_false', self::MENU_SLUG );

		add_settings_field(
			self::OPTION_FACEBOOK_ENABLED,
			__( 'Share Button', 'announcer-for-sportspress' ),
			array( $this, 'render_facebook_enabled_field' ),
			self::MENU_SLUG,
			'spa_section_facebook'
		);

		add_settings_field(
			self::OPTION_FACEBOOK_TEMPLATE,
			__( 'Result Template', 'announcer-for-sportspress' ),
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
			wp_send_json_error( __( 'Permission denied.', 'announcer-for-sportspress' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) );
		if ( empty( $url ) ) {
			wp_send_json_error( __( 'No webhook URL entered.', 'announcer-for-sportspress' ) );
		}

		if ( 0 !== strpos( $url, 'https://discord.com/api/webhooks/' ) ) {
			wp_send_json_error( __( 'That doesn\'t look like a Discord webhook URL.', 'announcer-for-sportspress' ) );
		}

		$payload = array(
			'embeds' => array(
				array(
					'title'       => __( 'Announcer for SportsPress', 'announcer-for-sportspress' ),
					'description' => __( 'Webhook connection successful.', 'announcer-for-sportspress' ),
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
				__( 'That doesn\'t look like a Discord webhook URL. It should start with https://discord.com/api/webhooks/', 'announcer-for-sportspress' )
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
		<p class="description"><?php esc_html_e( 'Configure the result message posted to Discord, Slack, and other channels.', 'announcer-for-sportspress' ); ?></p>
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
			<button type="button" class="button spa-emoji-trigger" data-target="<?php echo esc_attr( self::OPTION_RESULT_TEMPLATE ); ?>" style="flex-shrink:0;">😀 <?php esc_html_e( 'Emoji', 'announcer-for-sportspress' ); ?></button>
			<p class="description" style="margin:0;">
				<?php
				$chips = array( '{home}', '{away}', '{home_score}', '{away_score}', '{competition}', '{event_url}' );
				foreach ( $chips as $chip ) {
					printf(
						'<code class="spa-placeholder" data-target="%s" style="cursor:pointer;" title="%s">%s</code> ',
						esc_attr( self::OPTION_RESULT_TEMPLATE ),
						esc_attr( __( 'Click to insert', 'announcer-for-sportspress' ) ),
						esc_html( $chip )
					);
				}
				?>
				<br><?php esc_html_e( 'Team names are auto-bolded per platform.', 'announcer-for-sportspress' ); ?>
				<?php if ( $this->slack_active() ) : ?>
					<?php esc_html_e( 'Slack mentions (<!channel>, <!here>) and emoji work too.', 'announcer-for-sportspress' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Emoji work too.', 'announcer-for-sportspress' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether Slack is configured and enabled — gates Slack-only help text.
	 *
	 * @return bool
	 */
	private function slack_active(): bool {
		return (bool) get_option( self::OPTION_SLACK_WEBHOOK, '' ) && (bool) get_option( self::OPTION_SLACK_ENABLED, false );
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
			<?php esc_html_e( 'Send automatic Discord announcements when event results are published', 'announcer-for-sportspress' ); ?>
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
					__( 'Paste your Discord channel\'s incoming webhook URL. <a href="%s" target="_blank" rel="noopener">How to create a webhook →</a>', 'announcer-for-sportspress' ),
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
				<?php esc_html_e( 'Send Test Message', 'announcer-for-sportspress' ); ?>
			</button>
			<span id="spa-test-result" style="display:inline-flex; align-items:center; min-height:30px; margin-left:8px; vertical-align:middle;"></span>
		</p>
		<?php
		$this->render_webhook_test_script();
	}

	/**
	 * Inline script wiring the Discord "Send Test Message" button.
	 *
	 * @return void
	 */
	private function render_webhook_test_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var btn    = document.getElementById( 'spa-test-webhook' );
			var result = document.getElementById( 'spa-test-result' );
			var input  = document.getElementById( '<?php echo esc_js( self::OPTION_WEBHOOK ); ?>' );
			if ( ! btn || ! result || ! input ) return;
			btn.addEventListener( 'click', function () {
				result.textContent = '<?php echo esc_js( __( 'Sending…', 'announcer-for-sportspress' ) ); ?>';
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
							result.textContent = '<?php echo esc_js( __( '✓ Test message sent!', 'announcer-for-sportspress' ) ); ?>';
							result.style.color = '#46b450';
						} else {
							result.textContent = '<?php echo esc_js( __( '✗ Error: ', 'announcer-for-sportspress' ) ); ?>' + ( json.data || '' );
							result.style.color = '#dc3232';
						}
					} )
					.catch( function () {
						result.textContent = '<?php echo esc_js( __( '✗ Request failed.', 'announcer-for-sportspress' ) ); ?>';
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
						__( 'Invalid Discord webhook URL for "%s" — must start with https://discord.com/api/webhooks/', 'announcer-for-sportspress' ),
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
		$map    = $this->seed_channel_map( self::OPTION_DISCORD_CHANNEL_MAP );
		$opt    = self::OPTION_DISCORD_CHANNEL_MAP;
		$ph_key = __( 'Division / competition name', 'announcer-for-sportspress' );
		$ph_url = __( 'https://discord.com/api/webhooks/…', 'announcer-for-sportspress' );
		?>
		<p class="description" style="margin-bottom:10px;">
			<?php esc_html_e( 'Route each division to its own Discord channel. The key must match the competition name exactly. Leave the URL blank to use the default webhook. Per-division routing applies to result announcements only — the digest always uses the default webhook.', 'announcer-for-sportspress' ); ?>
		</p>
		<table id="spa-channel-map-table" style="border-collapse:collapse; width:100%; max-width:700px;">
			<thead>
				<tr>
					<th style="text-align:left; padding:0 10px 6px 0; font-weight:600; width:35%;"><?php esc_html_e( 'Competition name', 'announcer-for-sportspress' ); ?></th>
					<th style="text-align:left; padding:0 0 6px 0; font-weight:600;"><?php esc_html_e( 'Discord webhook URL', 'announcer-for-sportspress' ); ?></th>
					<th style="width:30px;"></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$index = 0;
			foreach ( $map as $key => $url ) {
				$this->render_channel_map_row(
					array(
						'class'  => 'spa-channel-map-row',
						'opt'    => $opt,
						'index'  => $index,
						'key'    => $key,
						'url'    => $url,
						'ph_key' => $ph_key,
						'ph_url' => $ph_url,
					)
				);
				++$index;
			}
			?>
			</tbody>
		</table>
		<p style="margin-top:8px;">
			<button type="button" id="spa-channel-map-add" class="button">
				<?php esc_html_e( '+ Add channel', 'announcer-for-sportspress' ); ?>
			</button>
		</p>
		<?php
		$this->render_channel_map_script();
	}

	/**
	 * Load the saved channel map, seeding empty rows from sp_league terms.
	 *
	 * @param string $option Channel-map option name.
	 * @return array
	 */
	private function seed_channel_map( string $option ): array {
		$map = (array) get_option( $option, array() );
		if ( ! empty( $map ) ) {
			return $map;
		}

		$leagues = get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $leagues ) ) {
			return $map;
		}
		foreach ( $leagues as $term ) {
			$map[ $term->name ] = '';
		}
		return $map;
	}

	/**
	 * Render one editable channel-map row. The remove-button class is derived
	 * from the row class (e.g. "spa-channel-map-row" → "spa-channel-map-remove").
	 *
	 * @param array $row {
	 *     Row fields.
	 *
	 *     @type string $class  Row CSS class the field's script binds to.
	 *     @type string $opt    Option name used for input names.
	 *     @type int    $index  Row index.
	 *     @type string $key    Competition name.
	 *     @type string $url    Webhook URL.
	 *     @type string $ph_key Key input placeholder.
	 *     @type string $ph_url URL input placeholder.
	 * }
	 * @return void
	 */
	private function render_channel_map_row( array $row ): void {
		$remove_class = str_replace( '-row', '-remove', $row['class'] );
		?>
		<tr class="<?php echo esc_attr( $row['class'] ); ?>">
			<td style="padding:4px 10px 4px 0;">
					<input
						type="text"
						name="<?php echo esc_attr( $row['opt'] ); ?>[<?php echo absint( $row['index'] ); ?>][key]"
						value="<?php echo esc_attr( $row['key'] ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( $row['ph_key'] ); ?>"
						style="width:100%;"
					/>
			</td>
			<td style="padding:4px 6px 4px 0;">
					<input
						type="url"
						name="<?php echo esc_attr( $row['opt'] ); ?>[<?php echo absint( $row['index'] ); ?>][url]"
						value="<?php echo esc_attr( $row['url'] ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( $row['ph_url'] ); ?>"
						style="width:100%;"
					/>
			</td>
			<td style="padding:4px 0; text-align:center;">
				<button type="button" class="button-link <?php echo esc_attr( $remove_class ); ?>" title="<?php esc_attr_e( 'Remove', 'announcer-for-sportspress' ); ?>" style="color:#a00; padding:4px;">&#x2715;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Inline script for the Discord channel-map add/remove/reindex behavior.
	 *
	 * @return void
	 */
	private function render_channel_map_script(): void {
		?>
		<script>
		(function () {
			var table   = document.getElementById( 'spa-channel-map-table' );
			var addBtn  = document.getElementById( 'spa-channel-map-add' );
			var opt     = '<?php echo esc_js( self::OPTION_DISCORD_CHANNEL_MAP ); ?>';
			var phKey   = '<?php echo esc_js( __( 'Division / competition name', 'announcer-for-sportspress' ) ); ?>';
			var phUrl   = '<?php echo esc_js( __( 'https://discord.com/api/webhooks/…', 'announcer-for-sportspress' ) ); ?>';

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
						__( 'Invalid Slack webhook URL for "%s" — must start with https://hooks.slack.com/services/ or https://hooks.slack.com/workflows/', 'announcer-for-sportspress' ),
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
		$map    = $this->seed_channel_map( self::OPTION_SLACK_CHANNEL_MAP );
		$opt    = self::OPTION_SLACK_CHANNEL_MAP;
		$ph_key = __( 'Division / competition name', 'announcer-for-sportspress' );
		$ph_url = __( 'https://hooks.slack.com/services/…', 'announcer-for-sportspress' );
		?>
		<p class="description" style="margin-bottom:10px;">
			<?php esc_html_e( 'Optionally route each division to its own Slack channel. The key must match the competition name exactly. Leave the URL blank to use the default webhook above. Per-division routing applies to result announcements only.', 'announcer-for-sportspress' ); ?>
		</p>
		<table id="spa-slack-channel-map-table" style="border-collapse:collapse; width:100%; max-width:700px;">
			<thead>
				<tr>
					<th style="text-align:left; padding:0 10px 6px 0; font-weight:600; width:35%;"><?php esc_html_e( 'Competition name', 'announcer-for-sportspress' ); ?></th>
					<th style="text-align:left; padding:0 0 6px 0; font-weight:600;"><?php esc_html_e( 'Slack webhook URL', 'announcer-for-sportspress' ); ?></th>
					<th style="width:30px;"></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$index = 0;
			foreach ( $map as $key => $url ) {
				$this->render_channel_map_row(
					array(
						'class'  => 'spa-slack-channel-map-row',
						'opt'    => $opt,
						'index'  => $index,
						'key'    => $key,
						'url'    => $url,
						'ph_key' => $ph_key,
						'ph_url' => $ph_url,
					)
				);
				++$index;
			}
			?>
			</tbody>
		</table>
		<p style="margin-top:8px;">
			<button type="button" id="spa-slack-channel-map-add" class="button">
				<?php esc_html_e( '+ Add channel', 'announcer-for-sportspress' ); ?>
			</button>
		</p>
		<?php
		$this->render_slack_channel_map_script();
	}

	/**
	 * Inline script for the Slack channel-map add/remove/reindex behavior.
	 *
	 * @return void
	 */
	private function render_slack_channel_map_script(): void {
		?>
		<script>
		(function () {
			var table   = document.getElementById( 'spa-slack-channel-map-table' );
			var addBtn  = document.getElementById( 'spa-slack-channel-map-add' );
			var opt     = '<?php echo esc_js( self::OPTION_SLACK_CHANNEL_MAP ); ?>';
			var phKey   = '<?php echo esc_js( __( 'Division / competition name', 'announcer-for-sportspress' ) ); ?>';
			var phUrl   = '<?php echo esc_js( __( 'https://hooks.slack.com/services/…', 'announcer-for-sportspress' ) ); ?>';

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
			<?php esc_html_e( 'Show a "Share to Facebook" button in the admin results digest', 'announcer-for-sportspress' ); ?>
		</label>
		<div style="margin-top:6px;">
			<a href="#" id="spa-template-toggle" style="text-decoration:none;"><?php esc_html_e( 'Customize template ▸', 'announcer-for-sportspress' ); ?></a>
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
					? '<?php echo esc_js( __( 'Customize template ▸', 'announcer-for-sportspress' ) ); ?>'
					: '<?php echo esc_js( __( 'Customize template ▾', 'announcer-for-sportspress' ) ); ?>';
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
			<button type="button" class="button spa-emoji-trigger" data-target="<?php echo esc_attr( self::OPTION_FACEBOOK_TEMPLATE ); ?>" style="flex-shrink:0;">😀 <?php esc_html_e( 'Emoji', 'announcer-for-sportspress' ); ?></button>
			<p class="description" style="margin:0;">
				<?php
				$chips = array( '{home}', '{away}', '{home_score}', '{away_score}', '{competition}', '{venue}', '{time}', '{date}', '{event_url}' );
				foreach ( $chips as $chip ) {
					printf(
						'<code class="spa-placeholder" data-target="%s" style="cursor:pointer;" title="%s">%s</code> ',
						esc_attr( self::OPTION_FACEBOOK_TEMPLATE ),
						esc_attr( __( 'Click to insert', 'announcer-for-sportspress' ) ),
						esc_html( $chip )
					);
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the SportsPress score-column field: a dropdown of the site's
	 * actual result columns, or a free-text fallback when SportsPress data
	 * is unavailable.
	 *
	 * @return void
	 */
	public function render_score_column_field(): void {
		$value   = (string) get_option( self::OPTION_SCORE_COLUMN, self::DEFAULT_SCORE_COLUMN );
		$columns = $this->get_available_result_columns();

		if ( empty( $columns ) ) {
			$this->render_score_column_text_input( $value );
			return;
		}

		// Keep a custom/legacy value selectable even if SportsPress no longer lists it.
		if ( '' !== $value && ! isset( $columns[ $value ] ) ) {
			$columns[ $value ] = $value;
		}
		?>
		<select
			id="<?php echo esc_attr( self::OPTION_SCORE_COLUMN ); ?>"
			name="<?php echo esc_attr( self::OPTION_SCORE_COLUMN ); ?>"
		>
			<?php foreach ( $columns as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $value, $slug ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'The SportsPress result column read as the score, from SportsPress → Result Columns.', 'announcer-for-sportspress' ); ?>
		</p>
		<?php
	}

	/**
	 * Free-text fallback for the score column when SportsPress columns
	 * cannot be listed.
	 *
	 * @param string $value Saved column slug.
	 * @return void
	 */
	private function render_score_column_text_input( string $value ): void {
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
			<?php esc_html_e( 'The result column key used to read scores from SportsPress (e.g. "goals"). Must match the column slug in SportsPress → Result Columns.', 'announcer-for-sportspress' ); ?>
		</p>
		<?php
	}

	/**
	 * Available SportsPress result column slugs → labels, or an empty array
	 * when SportsPress data is unavailable.
	 *
	 * @return array<string,string>
	 */
	private function get_available_result_columns(): array {
		if ( ! function_exists( 'sp_get_var_labels' ) ) {
			return array();
		}
		$labels = sp_get_var_labels( 'sp_result' );
		return ( is_array( $labels ) && ! empty( $labels ) ) ? $labels : array();
	}

	/**
	 * Render the digest settings introduction.
	 *
	 * @return void
	 */
	public function render_digest_section_intro(): void {
		?>
		<p class="description"><?php esc_html_e( 'Upcoming games for the next 7 days. Preview the schedule below and send it to your configured channels on demand, or auto-send on a schedule. Edit the message copy on the Templates tab.', 'announcer-for-sportspress' ); ?></p>
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
			<button type="button" class="button spa-emoji-trigger" data-target="<?php echo esc_attr( self::OPTION_UPCOMING_TEMPLATE ); ?>" style="flex-shrink:0;">😀 <?php esc_html_e( 'Emoji', 'announcer-for-sportspress' ); ?></button>
			<p class="description" style="margin:0;">
				<?php
				$chips = array( '{home}', '{away}', '{competition}', '{venue}', '{time}', '{date}', '{event_url}' );
				foreach ( $chips as $chip ) {
					printf(
						'<code class="spa-placeholder" data-target="%s" style="cursor:pointer;" title="%s">%s</code> ',
						esc_attr( self::OPTION_UPCOMING_TEMPLATE ),
						esc_attr( __( 'Click to insert', 'announcer-for-sportspress' ) ),
						esc_html( $chip )
					);
				}
				?>
				<?php if ( $this->slack_active() ) : ?>
					<br><?php esc_html_e( 'Slack mentions (<!channel>, <!here>) and emoji work too.', 'announcer-for-sportspress' ); ?>
				<?php else : ?>
					<br><?php esc_html_e( 'Emoji work too.', 'announcer-for-sportspress' ); ?>
				<?php endif; ?>
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
		$platforms = $this->upcoming_send_platforms();

		if ( empty( $platforms ) ) {
			?>
			<p class="description"><?php esc_html_e( 'Configure a Discord or Slack webhook URL above to enable this.', 'announcer-for-sportspress' ); ?></p>
			<?php
			return;
		}

		$notice       = new SPA_Upcoming_Notice();
		$games        = $notice->get_upcoming_games();
		$preview_text = $this->build_upcoming_preview_text( $games );
		?>
		<?php if ( ! empty( $games ) ) : ?>
		<p style="margin:0 0 6px; font-weight:600;"><?php esc_html_e( 'Preview', 'announcer-for-sportspress' ); ?></p>
		<pre id="spa-preview-box" style="white-space:pre-wrap; background:#f6f7f7; border:1px solid #dcdcde; padding:10px 12px; margin:0 0 12px; font-size:12px; line-height:1.6; max-width:600px;"><?php echo esc_html( $preview_text ); ?></pre>
		<?php else : ?>
		<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'No upcoming games in the next 7 days.', 'announcer-for-sportspress' ); ?></p>
		<?php endif; ?>
		<div style="display:flex;align-items:center;gap:8px;">
			<button type="button" id="spa-publish-upcoming" class="button button-primary"<?php echo empty( $games ) ? ' disabled' : ''; ?>>
				<?php esc_html_e( 'Send now', 'announcer-for-sportspress' ); ?>
			</button>
			<span style="font-size:11px;color:#646970;"><?php echo esc_html( $this->upcoming_send_platforms_label( $platforms ) ); ?></span>
			<span id="spa-publish-result" class="spa-send-result"></span>
		</div>
		<?php
		$this->render_upcoming_send_script( 'spa-publish-upcoming', 'spa-publish-result', $platforms, '#spa-preview-box' );
	}

	/**
	 * Build the grouped plain-text preview of upcoming games.
	 *
	 * @param array[] $games Upcoming games from SPA_Upcoming_Notice.
	 * @return string
	 */
	private function build_upcoming_preview_text( array $games ): string {
		$by_date = array();
		foreach ( $games as $g ) {
			$by_date[ $g['date'] ][] = $g;
		}
		ksort( $by_date );

		$lines = array();
		$first = true;
		foreach ( $by_date as $date => $group ) {
			if ( ! $first ) {
				$lines[] = '';
			}
			$first   = false;
			$lines[] = $date;
			foreach ( $group as $g ) {
				$line = '• ' . $g['label'];
				if ( $g['time'] ) {
					$line .= ' - ' . $g['time'];
				}
				if ( $g['venue'] ) {
					$line .= ' @ ' . $g['venue'];
				}
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
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
		$enabled   = (bool) get_option( SPA_Daily_Digest_Scheduler::OPTION_ENABLED, false );
		$frequency = get_option( SPA_Daily_Digest_Scheduler::OPTION_FREQUENCY, 'weekly' );
		$day       = get_option( SPA_Daily_Digest_Scheduler::OPTION_DAY, 'monday' );
		$time      = get_option( SPA_Daily_Digest_Scheduler::OPTION_TIME, '08:00' );

		$next = wp_next_scheduled( 'spa_digest_send' );
		?>
		<label>
			<input
				type="checkbox"
				id="<?php echo esc_attr( SPA_Daily_Digest_Scheduler::OPTION_ENABLED ); ?>"
				name="<?php echo esc_attr( SPA_Daily_Digest_Scheduler::OPTION_ENABLED ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Automatically send the fixtures digest to your configured channels', 'announcer-for-sportspress' ); ?>
		</label>

		<div style="margin-top:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
			<select
				id="<?php echo esc_attr( SPA_Daily_Digest_Scheduler::OPTION_FREQUENCY ); ?>"
				name="<?php echo esc_attr( SPA_Daily_Digest_Scheduler::OPTION_FREQUENCY ); ?>"
			>
				<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'announcer-for-sportspress' ); ?></option>
				<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'announcer-for-sportspress' ); ?></option>
			</select>

			<span id="spa-digest-day-wrap" <?php echo 'daily' === $frequency ? 'style="display:none;"' : ''; ?>>
				<select
					name="<?php echo esc_attr( SPA_Daily_Digest_Scheduler::OPTION_DAY ); ?>"
				>
					<?php $this->render_weekday_options( $day ); ?>
				</select>
			</span>

			<input
				type="time"
				name="<?php echo esc_attr( SPA_Daily_Digest_Scheduler::OPTION_TIME ); ?>"
				value="<?php echo esc_attr( $time ); ?>"
			/>
		</div>

		<?php if ( $next ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: formatted date/time */
					esc_html__( 'Next send: %s', 'announcer-for-sportspress' ),
					esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		$this->render_digest_schedule_script();
	}

	/**
	 * Inline script showing/hiding the weekday selector by frequency.
	 *
	 * @return void
	 */
	private function render_digest_schedule_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var freq = document.getElementById( '<?php echo esc_js( SPA_Daily_Digest_Scheduler::OPTION_FREQUENCY ); ?>' );
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
			wp_send_json_error( __( 'Permission denied.', 'announcer-for-sportspress' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) );
		if ( empty( $url ) ) {
			wp_send_json_error( __( 'No webhook URL entered.', 'announcer-for-sportspress' ) );
		}

		if ( 0 !== strpos( $url, 'https://hooks.slack.com/services/' ) && 0 !== strpos( $url, 'https://hooks.slack.com/workflows/' ) ) {
			wp_send_json_error( __( 'That doesn\'t look like a Slack Incoming Webhook URL.', 'announcer-for-sportspress' ) );
		}

		$payload = array(
			'text' => __( 'Announcer for SportsPress - Slack webhook connection successful.', 'announcer-for-sportspress' ),
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
				__( 'That doesn\'t look like a Slack Incoming Webhook URL. It should start with https://hooks.slack.com/services/ or https://hooks.slack.com/workflows/', 'announcer-for-sportspress' )
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
		<p class="description"><?php esc_html_e( 'Post match results and upcoming game digests to a Slack channel via an Incoming Webhook.', 'announcer-for-sportspress' ); ?></p>
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
			<?php esc_html_e( 'Send automatic Slack announcements when event results are published', 'announcer-for-sportspress' ); ?>
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
					__( 'Paste your Slack channel\'s Incoming Webhook URL. <a href="%s" target="_blank" rel="noopener">How to create a webhook →</a>', 'announcer-for-sportspress' ),
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
				<?php esc_html_e( 'Send Test Message', 'announcer-for-sportspress' ); ?>
			</button>
			<span id="spa-test-slack-result" style="display:inline-flex; align-items:center; min-height:30px; margin-left:8px; vertical-align:middle;"></span>
		</p>
		<?php
		$this->render_slack_webhook_test_script();
	}

	/**
	 * Inline script wiring the Slack "Send Test Message" button.
	 *
	 * @return void
	 */
	private function render_slack_webhook_test_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var btn    = document.getElementById( 'spa-test-slack-webhook' );
			var result = document.getElementById( 'spa-test-slack-result' );
			var input  = document.getElementById( '<?php echo esc_js( self::OPTION_SLACK_WEBHOOK ); ?>' );
			if ( ! btn || ! result || ! input ) return;
			btn.addEventListener( 'click', function () {
				result.textContent = '<?php echo esc_js( __( 'Sending…', 'announcer-for-sportspress' ) ); ?>';
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
							result.textContent = '<?php echo esc_js( __( '✓ Test message sent!', 'announcer-for-sportspress' ) ); ?>';
							result.style.color = '#46b450';
						} else {
							result.textContent = '<?php echo esc_js( __( '✗ Error: ', 'announcer-for-sportspress' ) ); ?>' + ( json.data || '' );
							result.style.color = '#dc3232';
						}
					} )
					.catch( function () {
						result.textContent = '<?php echo esc_js( __( '✗ Request failed.', 'announcer-for-sportspress' ) ); ?>';
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
	 * URL of a tab on the plugin settings page.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Render the Dashboard tab.
	 *
	 * @param array $ctx Dashboard context from dashboard_context() — keys:
	 *                   discord_active, sent_today, log_failed, recent_log,
	 *                   log_total, last_digest_ts.
	 * @return void
	 */
	private function render_dashboard_tab( array $ctx ): void {
		$log_url = $this->tab_url( 'log' );

		$this->render_dashboard_status_bar( $ctx['discord_active'], $ctx['sent_today'], $ctx['log_failed'] );
		$this->render_dashboard_recent_card( $ctx['recent_log'], $ctx['log_total'], $log_url );
		$this->render_dashboard_latest_card();
		$this->render_dashboard_digest_card( $ctx['last_digest_ts'], $log_url );
		$this->render_dashboard_script();
	}

	/**
	 * Dashboard "Latest Announcement" card: the most recent result message
	 * rendered in Discord-style chrome, so admins see what actually went out.
	 * Skipped entirely until a result has been announced.
	 *
	 * @return void
	 */
	private function render_dashboard_latest_card(): void {
		$latest = SPA_Log::get_page( 1, 1, array( 'type' => 'result' ) );
		$entry  = $latest[0] ?? array();
		$text   = (string) ( $entry['message'] ?? '' );
		if ( '' === $text ) {
			// Entries written before message storage still carry the formatted
			// result as their label.
			$text = (string) ( $entry['label'] ?? '' );
		}
		if ( '' === $text ) {
			return;
		}
		?>
		<div class="spa-dashboard-card">
			<div class="spa-dashboard-card-head">
				<span class="spa-dashboard-card-title">&#128172; <?php esc_html_e( 'Latest Announcement', 'announcer-for-sportspress' ); ?></span>
				<span style="font-size:11px;color:#8c8f94"><?php echo esc_html( $this->log_time_label( (int) ( $entry['sent_at'] ?? 0 ) ) ); ?></span>
			</div>
			<div class="spa-dashboard-card-body">
				<?php $this->render_discord_message_preview( $text, (string) ( $entry['channel'] ?? '' ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one plain-text message inside Discord-style preview chrome.
	 *
	 * @param string $text    Message text; newlines are preserved.
	 * @param string $channel Optional competition label shown as the footer.
	 * @return void
	 */
	private function render_discord_message_preview( string $text, string $channel ): void {
		?>
		<div class="spa-discord-preview">
			<div class="spa-discord-preview-bot"><?php esc_html_e( 'SportsPress Bot', 'announcer-for-sportspress' ); ?></div>
			<?php echo wp_kses( nl2br( esc_html( $text ) ), array( 'br' => array() ) ); ?>
			<?php if ( '' !== $channel ) : ?>
				<br><span style="color:#72767d;font-size:11px"><?php echo esc_html( $channel ); ?> · <?php esc_html_e( 'Full Time', 'announcer-for-sportspress' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Dashboard status bar (channel status + today's counts).
	 *
	 * @param bool $discord_active Whether Discord is configured.
	 * @param int  $sent_today     Announcements sent today.
	 * @param int  $log_failed     Failed announcements.
	 * @return void
	 */
	private function render_dashboard_status_bar( bool $discord_active, int $sent_today, int $log_failed ): void {
		$settings_url = $this->tab_url( 'templates' );
		?>
		<div class="spa-status-bar">
			<?php if ( $discord_active ) : ?>
				<span class="spa-status-dot spa-status-dot--green"></span>
				<strong><?php esc_html_e( 'Discord', 'announcer-for-sportspress' ); ?></strong>
			<?php else : ?>
				<span class="spa-status-dot spa-status-dot--gray"></span>
				<span style="color:#8c8f94"><?php esc_html_e( 'Discord', 'announcer-for-sportspress' ); ?></span>
			<?php endif; ?>
			<span class="spa-status-sep">·</span>
			<?php if ( $this->slack_active() ) : ?>
				<span class="spa-status-dot spa-status-dot--green"></span>
				<strong><?php esc_html_e( 'Slack', 'announcer-for-sportspress' ); ?></strong>
			<?php else : ?>
				<span style="color:#8c8f94"><?php esc_html_e( 'Slack', 'announcer-for-sportspress' ); ?></span>
				<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'announcer-for-sportspress' ); ?></span>
			<?php endif; ?>
			<span class="spa-status-divider">|</span>
			<span style="color:#50575e">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of announcements sent today */
						_n( '%d sent today', '%d sent today', $sent_today, 'announcer-for-sportspress' ),
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
							_n( '%d failed', '%d failed', $log_failed, 'announcer-for-sportspress' ),
							$log_failed
						)
					);
					?>
				</strong>
			<?php endif; ?>
			<a href="<?php echo esc_url( $settings_url ); ?>" style="margin-left:auto;font-size:11px;color:#2271b1;white-space:nowrap">
				&#9881; <?php esc_html_e( 'Settings', 'announcer-for-sportspress' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Dashboard "Recent Announcements" card.
	 *
	 * @param array[] $recent_log Recent log entries.
	 * @param int     $log_total  Total log entry count.
	 * @param string  $log_url    URL to the Log tab.
	 * @return void
	 */
	private function render_dashboard_recent_card( array $recent_log, int $log_total, string $log_url ): void {
		$retry_nonce = wp_create_nonce( 'spa_retry_nonce' );
		?>
		<div class="spa-dashboard-card">
			<div class="spa-dashboard-card-head">
				<span class="spa-dashboard-card-title">&#128226; <?php esc_html_e( 'Recent Announcements', 'announcer-for-sportspress' ); ?></span>
				<span style="font-size:11px;color:#8c8f94"><?php esc_html_e( 'Auto-posts when a result is saved', 'announcer-for-sportspress' ); ?></span>
			</div>

			<?php if ( empty( $recent_log ) ) : ?>
				<div class="spa-dashboard-card-row" style="color:#8c8f94;font-style:italic">
					<?php esc_html_e( 'No announcements yet.', 'announcer-for-sportspress' ); ?>
				</div>
			<?php else : ?>
				<?php foreach ( $recent_log as $entry ) : ?>
					<?php $this->render_dashboard_log_row( $entry, $retry_nonce ); ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="spa-dashboard-card-foot">
				<a href="<?php echo esc_url( $log_url ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: total log entry count */
							__( 'See all %d entries in Log →', 'announcer-for-sportspress' ),
							$log_total
						)
					);
					?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one row in the dashboard recent-announcements card.
	 *
	 * @param array  $entry       Log entry.
	 * @param string $retry_nonce Retry action nonce.
	 * @return void
	 */
	private function render_dashboard_log_row( array $entry, string $retry_nonce ): void {
		$is_failed  = 'failed' === ( $entry['status'] ?? '' );
		$time_label = $this->relative_time_label( (int) ( $entry['sent_at'] ?? 0 ) );
		?>
		<div class="spa-dashboard-card-row<?php echo $is_failed ? ' spa-dashboard-card-row--failed' : ''; ?>">
			<span class="spa-status-dot <?php echo $is_failed ? 'spa-status-dot--red' : 'spa-status-dot--green'; ?>"></span>
			<span class="spa-dashboard-row-label"><?php echo esc_html( $entry['label'] ?? '' ); ?></span>
			<span class="spa-dashboard-row-meta"><?php echo esc_html( $entry['channel'] ?? '' ); ?></span>
			<span class="spa-dashboard-row-time"><?php echo $is_failed ? '<span style="color:#d63638">fail</span>' : esc_html( $time_label ); ?></span>
			<?php if ( $is_failed ) : ?>
				<button type="button" class="button spa-retry-btn" data-uid="<?php echo esc_attr( $entry['uid'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
					<?php esc_html_e( 'Retry', 'announcer-for-sportspress' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="button spa-retry-btn" data-uid="<?php echo esc_attr( $entry['uid'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>" data-resend="1">
					<?php esc_html_e( 'Resend', 'announcer-for-sportspress' ); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format a "time ago" label for a unix timestamp (blank when zero).
	 *
	 * @param int $ts Sent-at timestamp.
	 * @return string
	 */
	private function relative_time_label( int $ts ): string {
		if ( $ts <= 0 ) {
			return '';
		}
		$diff = time() - $ts;
		if ( $diff < 3600 ) {
			$mins = (int) floor( $diff / 60 );
			/* translators: %d: minutes ago */
			return sprintf( _n( '%dm', '%dm', $mins, 'announcer-for-sportspress' ), $mins );
		}
		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			/* translators: %d: hours ago */
			return sprintf( _n( '%dh', '%dh', $hours, 'announcer-for-sportspress' ), $hours );
		}
		return wp_date( 'M j', $ts );
	}

	/**
	 * Dashboard "Upcoming Digest" card.
	 *
	 * @param int    $last_digest_ts Timestamp of the last sent digest.
	 * @param string $log_url        URL to the Log tab.
	 * @return void
	 */
	private function render_dashboard_digest_card( int $last_digest_ts, string $log_url ): void {
		$notice          = new SPA_Upcoming_Notice();
		$upcoming_games  = $notice->get_upcoming_games();
		$next_date_label = '';
		if ( ! empty( $upcoming_games ) ) {
			$dates = array_column( $upcoming_games, 'date' );
			sort( $dates );
			$next_date_label = $dates[0];
		}

		$platforms = $this->upcoming_send_platforms();
		?>
		<div class="spa-dashboard-card">
			<div class="spa-dashboard-card-head">
				<span class="spa-dashboard-card-title">&#128197; <?php esc_html_e( 'Fixtures Digest', 'announcer-for-sportspress' ); ?></span>
				<?php if ( $next_date_label ) : ?>
					<span style="font-size:11px;color:#646970">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: next fixture date */
								__( 'Next fixtures: %s', 'announcer-for-sportspress' ),
								$next_date_label
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<div class="spa-dashboard-card-body">
				<?php if ( ! empty( $upcoming_games ) ) : ?>
					<?php $this->render_digest_preview( $upcoming_games ); ?>
				<?php else : ?>
					<p style="color:#8c8f94;font-size:12px;margin:0 0 10px"><?php esc_html_e( 'No upcoming games found in the next 7 days.', 'announcer-for-sportspress' ); ?></p>
				<?php endif; ?>

				<div style="display:flex;align-items:center;gap:10px;margin-top:10px">
					<button type="button" id="spa-send-digest-btn" class="button button-primary" <?php echo ( empty( $upcoming_games ) || empty( $platforms ) ) ? 'disabled' : ''; ?>>
						<?php esc_html_e( 'Send now', 'announcer-for-sportspress' ); ?>
					</button>
					<span id="spa-send-digest-result" class="spa-send-result"></span>
					<?php $this->render_dashboard_platform_hint( $platforms ); ?>
					<a href="<?php echo esc_url( $this->tab_url( 'digest' ) ); ?>" style="font-size:11px;">
						<?php esc_html_e( 'Auto-send schedule →', 'announcer-for-sportspress' ); ?>
					</a>
				</div>
			</div>

			<?php $this->render_digest_card_foot( $last_digest_ts, $log_url ); ?>
		</div>
		<?php
		$this->render_upcoming_send_script( 'spa-send-digest-btn', 'spa-send-digest-result', $platforms, '.spa-discord-preview' );
	}

	/**
	 * Active platforms for an on-demand upcoming-fixtures send, keyed by slug.
	 *
	 * @return array<string,array{action:string,nonce_action:string,label:string}>
	 *         Empty when no webhook is configured.
	 */
	private function upcoming_send_platforms(): array {
		$platforms = array();

		if ( get_option( self::OPTION_WEBHOOK, '' ) ) {
			$platforms['discord'] = array(
				'action'       => 'spa_send_upcoming',
				'nonce_action' => 'spa_send_upcoming_nonce',
				'label'        => __( 'Discord', 'announcer-for-sportspress' ),
			);
		}

		if ( get_option( self::OPTION_SLACK_WEBHOOK, '' ) ) {
			$platforms['slack'] = array(
				'action'       => 'spa_send_upcoming_slack',
				'nonce_action' => 'spa_send_upcoming_slack_nonce',
				'label'        => __( 'Slack', 'announcer-for-sportspress' ),
			);
		}

		return $platforms;
	}

	/**
	 * Human-readable list of the active send platforms (e.g. "Discord + Slack").
	 *
	 * @param array<string,array{label:string}> $platforms Platform config.
	 * @return string
	 */
	private function upcoming_send_platforms_label( array $platforms ): string {
		return implode( ' + ', wp_list_pluck( $platforms, 'label' ) );
	}

	/**
	 * Render the small "Discord + Slack" / "No webhook configured" hint span.
	 *
	 * @param array<string,array{label:string}> $platforms Active platforms.
	 * @return void
	 */
	private function render_dashboard_platform_hint( array $platforms ): void {
		if ( empty( $platforms ) ) {
			echo '<span style="font-size:11px;color:#8c8f94">' . esc_html__( 'No webhook configured', 'announcer-for-sportspress' ) . '</span>';
			return;
		}
		echo '<span style="font-size:11px;color:#646970">' . esc_html( $this->upcoming_send_platforms_label( $platforms ) ) . '</span>';
	}

	/**
	 * Print the shared confirm-before-send modal helper, once per request.
	 *
	 * Exposes a single global `window.spaConfirmSend( opts )` that resolves a
	 * Promise to `true` (confirmed) or `false` (cancelled). Because broadcasting
	 * a digest is immediate and cannot be undone, every "Send now" button routes
	 * its click through this so the admin sees a preview of exactly what will go
	 * out — and to which platforms — before committing.
	 *
	 * `opts`: { title, previewHtml, platformsLabel, confirmLabel, cancelLabel }.
	 * `previewHtml` is markup already present in the page (server-rendered or an
	 * already-fetched preview), so no additional request is made here.
	 *
	 * @return void
	 */
	private function render_confirm_modal_script(): void {
		if ( $this->confirm_modal_printed ) {
			return;
		}
		$this->confirm_modal_printed = true;

		$labels = wp_json_encode(
			array(
				'title'       => __( 'Send this now?', 'announcer-for-sportspress' ),
				'warn'        => __( 'This is sent immediately and cannot be undone.', 'announcer-for-sportspress' ),
				'broadcastTo' => __( 'Broadcasts to: ', 'announcer-for-sportspress' ),
				'cancel'      => __( 'Cancel', 'announcer-for-sportspress' ),
				'confirm'     => __( 'Send now', 'announcer-for-sportspress' ),
			)
		);
		?>
		<script>
		( function () {
			if ( window.spaConfirmSend ) { return; }
			var L = <?php echo $labels; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode is safe for script context. ?>;
			var els = {}, resolveFn = null, lastFocus = null;

			function close( result ) {
				if ( ! els.overlay ) { return; }
				els.overlay.style.display = 'none';
				document.removeEventListener( 'keydown', onKeydown );
				var fn = resolveFn;
				resolveFn = null;
				if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
				if ( fn ) { fn( result ); }
			}

			function onKeydown( e ) {
				if ( e.key === 'Escape' ) { close( false ); }
			}
			<?php $this->render_confirm_modal_builder_js(); ?>
			<?php $this->render_confirm_modal_open_js(); ?>
		} )();
		</script>
		<?php
	}

	/**
	 * JS for lazily constructing the modal DOM. Emitted inside the modal IIFE,
	 * where `els`, `close`, and `onKeydown` are already in scope.
	 *
	 * @return void
	 */
	private function render_confirm_modal_builder_js(): void {
		?>
		function el( tag, cls ) {
			var node = document.createElement( tag );
			if ( cls ) { node.className = cls; }
			return node;
		}

		function build() {
			els.overlay = el( 'div', 'spa-modal-overlay' );
			els.overlay.style.display = 'none';

			var dialog = el( 'div', 'spa-modal' );
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );

			els.title    = el( 'h2', 'spa-modal-title' );
			els.preview  = el( 'div', 'spa-modal-preview' );
			els.warning  = el( 'p', 'spa-modal-warning' );
			els.cancel   = el( 'button', 'button button-secondary' );
			els.confirm  = el( 'button', 'button button-primary' );
			els.cancel.type = 'button';
			els.confirm.type = 'button';

			var actions = el( 'div', 'spa-modal-actions' );
			actions.appendChild( els.cancel );
			actions.appendChild( els.confirm );
			dialog.appendChild( els.title );
			dialog.appendChild( els.preview );
			dialog.appendChild( els.warning );
			dialog.appendChild( actions );
			els.overlay.appendChild( dialog );
			document.body.appendChild( els.overlay );

			els.cancel.addEventListener( 'click', function () { close( false ); } );
			els.confirm.addEventListener( 'click', function () { close( true ); } );
			els.overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === els.overlay ) { close( false ); }
			} );
		}
		<?php
	}

	/**
	 * JS defining `window.spaConfirmSend`. Emitted inside the modal IIFE, where
	 * `els`, `L`, `build`, `onKeydown`, `resolveFn`, and `lastFocus` are in scope.
	 *
	 * @return void
	 */
	private function render_confirm_modal_open_js(): void {
		?>
		window.spaConfirmSend = function ( opts ) {
			opts = opts || {};
			if ( ! els.overlay ) { build(); }

			els.title.textContent   = opts.title || L.title;
			els.preview.innerHTML   = opts.previewHtml || '';
			els.warning.textContent = opts.platformsLabel
				? ( L.broadcastTo + opts.platformsLabel + '. ' + L.warn )
				: L.warn;
			els.cancel.textContent  = opts.cancelLabel || L.cancel;
			els.confirm.textContent = opts.confirmLabel || L.confirm;

			lastFocus = document.activeElement;
			els.overlay.style.display = 'flex';
			document.addEventListener( 'keydown', onKeydown );
			els.confirm.focus();

			return new Promise( function ( resolve ) { resolveFn = resolve; } );
		};
		<?php
	}

	/**
	 * Emit a self-contained fan-out send script for an upcoming-fixtures button.
	 *
	 * Fires one AJAX request per active platform in parallel and reports a
	 * combined success / partial / failure result. Shared by the dashboard card
	 * and the Digest-tab publish button so both reach the same platforms.
	 *
	 * @param string                                                 $button_id        DOM id of the trigger button.
	 * @param string                                                 $result_id        DOM id of the result/status element.
	 * @param array<string,array{action:string,nonce_action:string}> $platforms       Active platforms.
	 * @param string                                                 $preview_selector CSS selector for the element whose markup previews what will be sent. Empty for no preview.
	 * @return void
	 */
	private function render_upcoming_send_script( string $button_id, string $result_id, array $platforms, string $preview_selector = '' ): void {
		if ( empty( $platforms ) ) {
			return;
		}

		$this->render_confirm_modal_script();

		$requests = array();
		foreach ( $platforms as $p ) {
			$requests[] = array(
				'action' => $p['action'],
				'nonce'  => wp_create_nonce( $p['nonce_action'] ),
			);
		}
		?>
		<script>
		( function () {
			var btn    = document.getElementById( <?php echo wp_json_encode( $button_id ); ?> );
			var result = document.getElementById( <?php echo wp_json_encode( $result_id ); ?> );
			if ( ! btn ) { return; }
			var specs = <?php echo wp_json_encode( $requests ); ?>;
			var platformsLabel = <?php echo wp_json_encode( $this->upcoming_send_platforms_label( $platforms ) ); ?>;
			var idle  = btn.textContent;

			var previewSelector = <?php echo wp_json_encode( $preview_selector ); ?>;
			function previewHtml() {
				if ( ! previewSelector ) { return ''; }
				var el = document.querySelector( previewSelector );
				return el ? el.innerHTML : '';
			}
			<?php $this->render_upcoming_fanout_js(); ?>
			btn.addEventListener( 'click', function () {
				window.spaConfirmSend( {
					previewHtml: previewHtml(),
					platformsLabel: platformsLabel
				} ).then( function ( ok ) {
					if ( ok ) { send(); }
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * JS defining the parallel `send()` fan-out. Emitted inside the upcoming-send
	 * IIFE, where `btn`, `result`, `specs`, and `idle` are already in scope.
	 *
	 * @return void
	 */
	private function render_upcoming_fanout_js(): void {
		?>
		function send() {
			btn.disabled = true;
			btn.textContent = <?php echo wp_json_encode( __( 'Sending…', 'announcer-for-sportspress' ) ); ?>;
			if ( result ) { result.textContent = ''; }
			var reqs = specs.map( function ( s ) {
				var fd = new FormData();
				fd.append( 'action', s.action );
				fd.append( 'nonce', s.nonce );
				return fetch( ajaxurl, { method: 'POST', body: fd } ).then( function ( r ) { return r.json(); } );
			} );
			Promise.allSettled( reqs ).then( function ( results ) {
				var errors = [];
				results.forEach( function ( r ) {
					if ( r.status === 'rejected' || ( r.value && ! r.value.success ) ) {
						errors.push( r.value ? ( r.value.data || <?php echo wp_json_encode( __( 'Unknown error', 'announcer-for-sportspress' ) ); ?> ) : <?php echo wp_json_encode( __( 'Request failed', 'announcer-for-sportspress' ) ); ?> );
					}
				} );
				btn.disabled = false;
				btn.textContent = idle;
				if ( ! result ) { return; }
				if ( errors.length === 0 ) {
					result.textContent = <?php echo wp_json_encode( __( '✓ Sent', 'announcer-for-sportspress' ) ); ?>;
					result.style.color = '#00a32a';
				} else if ( errors.length === results.length ) {
					result.textContent = <?php echo wp_json_encode( __( '✗ ', 'announcer-for-sportspress' ) ); ?> + errors.join( '; ' );
					result.style.color = '#d63638';
				} else {
					result.textContent = <?php echo wp_json_encode( __( '⚠ Partial: ', 'announcer-for-sportspress' ) ); ?> + errors.join( '; ' );
					result.style.color = '#ffb900';
				}
			} );
		}
		<?php
	}

	/**
	 * Footer of the Upcoming Digest card (last-sent timestamp / empty state).
	 *
	 * @param int    $last_digest_ts Timestamp of the last sent digest.
	 * @param string $log_url        URL to the Log tab.
	 * @return void
	 */
	private function render_digest_card_foot( int $last_digest_ts, string $log_url ): void {
		?>
		<div class="spa-dashboard-card-foot">
			<?php if ( $last_digest_ts > 0 ) : ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: formatted date and time */
						__( 'Last digest: %s', 'announcer-for-sportspress' ),
						wp_date( 'D M j · g:ia', $last_digest_ts )
					)
				);
				?>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'View in log →', 'announcer-for-sportspress' ); ?></a>
			<?php else : ?>
				<span style="color:#8c8f94"><?php esc_html_e( 'No digest sent yet.', 'announcer-for-sportspress' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Discord-style preview of the next few upcoming games.
	 *
	 * @param array[] $upcoming_games Upcoming games.
	 * @return void
	 */
	private function render_digest_preview( array $upcoming_games ): void {
		?>
		<div class="spa-discord-preview">
			<div class="spa-discord-preview-bot"><?php esc_html_e( 'SportsPress Bot', 'announcer-for-sportspress' ); ?></div>
			<strong><?php esc_html_e( 'Upcoming Games', 'announcer-for-sportspress' ); ?></strong><br>
			<?php
			foreach ( array_slice( $upcoming_games, 0, 4 ) as $game ) {
				echo '&#127903; ' . esc_html( $game['label'] );
				if ( ! empty( $game['time'] ) ) {
					echo ' · ' . esc_html( $game['time'] );
				}
				echo '<br>';
			}
			$overflow = count( $upcoming_games ) - 4;
			if ( $overflow > 0 ) {
				echo '<span style="color:#72767d;font-size:11px">+ ' . esc_html( (string) $overflow ) . ' ' . esc_html__( 'more', 'announcer-for-sportspress' ) . '</span>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Inline script for the dashboard retry/resend buttons.
	 *
	 * @return void
	 */
	private function render_retry_button_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.spa-retry-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var uid     = btn.dataset.uid;
					var nonce   = btn.dataset.nonce;
					var isResend = btn.dataset.resend === '1';
					btn.disabled = true;
					btn.textContent = isResend
						? '<?php echo esc_js( __( 'Sending…', 'announcer-for-sportspress' ) ); ?>'
						: '<?php echo esc_js( __( 'Retrying…', 'announcer-for-sportspress' ) ); ?>';
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
								btn.textContent = '<?php echo esc_js( __( 'Resend', 'announcer-for-sportspress' ) ); ?>';
								btn.dataset.resend = '1';
								btn.disabled = false;
							} else {
								btn.textContent = isResend
									? '<?php echo esc_js( __( 'Resend', 'announcer-for-sportspress' ) ); ?>'
									: '<?php echo esc_js( __( 'Retry', 'announcer-for-sportspress' ) ); ?>';
								btn.disabled = false;
								alert( json.data || '<?php echo esc_js( __( 'Request failed.', 'announcer-for-sportspress' ) ); ?>' );
							}
						} )
						.catch( function () {
							btn.textContent = isResend
								? '<?php echo esc_js( __( 'Resend', 'announcer-for-sportspress' ) ); ?>'
								: '<?php echo esc_js( __( 'Retry', 'announcer-for-sportspress' ) ); ?>';
							btn.disabled = false;
						} );
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Inline script for the dashboard send-digest button.
	 *
	 * @return void
	 */
	private function render_dashboard_script(): void {
		// The fixtures-digest send button wires itself via
		// render_upcoming_send_script() inside render_dashboard_digest_card().
		$this->render_retry_button_script();
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
		$total_pages = (int) ceil( $total / $per_page );
		$retry_nonce = wp_create_nonce( 'spa_retry_nonce' );
		$base_url    = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'log',
			),
			admin_url( 'options-general.php' )
		);

		?>
		<?php
		$this->render_log_toolbar( $type_filter, $search, $base_url );
		$this->render_log_table( $entries, $retry_nonce );
		$this->render_log_pagination( $paged, $per_page, $total, $total_pages, $base_url );
		$this->render_log_retry_script();
		$this->render_log_expand_script();
	}

	/**
	 * Log tab: filter pills and search box.
	 *
	 * @param string $type_filter Active type filter ('' | result | digest).
	 * @param string $search      Current search term.
	 * @param string $base_url    Base Log-tab URL.
	 * @return void
	 */
	private function render_log_toolbar( string $type_filter, string $search, string $base_url ): void {
		$count_all    = SPA_Log::count();
		$count_result = SPA_Log::count( array( 'type' => 'result' ) );
		$count_digest = SPA_Log::count( array( 'type' => 'digest' ) );
		?>
		<div class="spa-log-toolbar">
			<div class="spa-filter-pills">
				<a href="<?php echo esc_url( $base_url ); ?>" class="spa-pill<?php echo '' === $type_filter ? ' spa-pill--active' : ''; ?>">
					<?php esc_html_e( 'All', 'announcer-for-sportspress' ); ?> <span class="spa-pill-count"><?php echo esc_html( (string) $count_all ); ?></span>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'log_type', 'result', $base_url ) ); ?>" class="spa-pill<?php echo 'result' === $type_filter ? ' spa-pill--active' : ''; ?>">
					<?php esc_html_e( 'Results', 'announcer-for-sportspress' ); ?> <span class="spa-pill-count"><?php echo esc_html( (string) $count_result ); ?></span>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'log_type', 'digest', $base_url ) ); ?>" class="spa-pill<?php echo 'digest' === $type_filter ? ' spa-pill--active' : ''; ?>">
					<?php esc_html_e( 'Digest', 'announcer-for-sportspress' ); ?> <span class="spa-pill-count"><?php echo esc_html( (string) $count_digest ); ?></span>
				</a>
			</div>
			<form method="get" style="margin-left:auto">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<?php if ( $type_filter ) : ?>
					<input type="hidden" name="log_type" value="<?php echo esc_attr( $type_filter ); ?>">
				<?php endif; ?>
				<input type="search" name="log_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'announcer-for-sportspress' ); ?>" class="spa-log-search">
			</form>
		</div>
		<?php
	}

	/**
	 * Log tab: the entries table.
	 *
	 * @param array[] $entries     Log entries for the current page.
	 * @param string  $retry_nonce Retry action nonce.
	 * @return void
	 */
	private function render_log_table( array $entries, string $retry_nonce ): void {
		?>
		<div class="spa-dashboard-card" style="margin-bottom:8px">
			<div class="spa-log-row spa-log-row--header">
				<span><?php esc_html_e( 'Type', 'announcer-for-sportspress' ); ?></span>
				<span><?php esc_html_e( 'Event', 'announcer-for-sportspress' ); ?></span>
				<span><?php esc_html_e( 'Channel', 'announcer-for-sportspress' ); ?></span>
				<span><?php esc_html_e( 'Sent', 'announcer-for-sportspress' ); ?></span>
				<span><?php esc_html_e( 'Status', 'announcer-for-sportspress' ); ?></span>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<div class="spa-log-row" style="color:#8c8f94;font-style:italic;grid-column:1/-1">
					<?php esc_html_e( 'No entries found.', 'announcer-for-sportspress' ); ?>
				</div>
			<?php else : ?>
				<?php foreach ( $entries as $entry ) : ?>
					<?php $this->render_log_table_row( $entry, $retry_nonce ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Log tab: a single entry row.
	 *
	 * @param array  $entry       Log entry.
	 * @param string $retry_nonce Retry action nonce.
	 * @return void
	 */
	private function render_log_table_row( array $entry, string $retry_nonce ): void {
		$is_failed  = 'failed' === ( $entry['status'] ?? '' );
		$is_digest  = 'digest' === ( $entry['type'] ?? '' );
		$is_result  = 'result' === ( $entry['type'] ?? '' );
		$time_label = $this->log_time_label( (int) ( $entry['sent_at'] ?? 0 ) );
		$message    = (string) ( $entry['message'] ?? '' );

		$row_class = 'spa-log-row';
		if ( $is_failed ) {
			$row_class .= ' spa-log-row--failed';
		} elseif ( $is_digest ) {
			$row_class .= ' spa-log-row--digest';
		}
		if ( '' !== $message ) {
			$row_class .= ' spa-log-row--expandable';
		}
		$type_label = $is_result
			? esc_html__( 'Result', 'announcer-for-sportspress' )
			: esc_html__( 'Digest', 'announcer-for-sportspress' );
		$type_color = $is_result ? '#2271b1' : '#996800';
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>"<?php echo '' !== $message ? ' style="cursor:pointer" title="' . esc_attr__( 'Click to show the sent message', 'announcer-for-sportspress' ) . '"' : ''; ?>>
			<span style="color:<?php echo esc_attr( $type_color ); ?>;font-weight:600"><?php echo $type_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span style="color:#1d2327"><?php echo '' !== $message ? '<span class="spa-log-chevron" style="color:#8c8f94;margin-right:4px">&#9656;</span>' : ''; ?><?php echo esc_html( $entry['label'] ?? '' ); ?></span>
			<span style="color:#50575e"><?php echo esc_html( $entry['channel'] ?? '' ); ?></span>
			<span style="color:#8c8f94"><?php echo esc_html( $time_label ); ?></span>
			<?php if ( $is_failed ) : ?>
				<button type="button" class="button spa-retry-btn spa-log-retry-btn" data-uid="<?php echo esc_attr( $entry['uid'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $retry_nonce ); ?>">
					&#10007; <?php esc_html_e( 'Retry', 'announcer-for-sportspress' ); ?>
				</button>
			<?php else : ?>
				<span style="color:#00a32a;font-weight:600">&#10003; <?php esc_html_e( 'Sent', 'announcer-for-sportspress' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $message ) : ?>
			<div class="spa-log-detail" style="display:none;padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #f6f6f6">
				<pre style="margin:0;white-space:pre-wrap;font-size:12px;line-height:1.6"><?php echo esc_html( $message ); ?></pre>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * "Time ago" label for the log table (blank timestamp → em dash).
	 *
	 * @param int $ts Sent-at timestamp.
	 * @return string
	 */
	private function log_time_label( int $ts ): string {
		$diff = time() - $ts;
		if ( $diff < 3600 ) {
			$mins = (int) floor( $diff / 60 );
			/* translators: %d: minutes ago */
			return sprintf( _n( '%dm ago', '%dm ago', $mins, 'announcer-for-sportspress' ), $mins );
		}
		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			/* translators: %d: hours ago */
			return sprintf( _n( '%dh ago', '%dh ago', $hours, 'announcer-for-sportspress' ), $hours );
		}
		return $ts > 0 ? wp_date( 'M j', $ts ) : '—';
	}

	/**
	 * Log tab: pagination bar.
	 *
	 * @param int    $paged       Current page.
	 * @param int    $per_page    Entries per page.
	 * @param int    $total       Total matching entries.
	 * @param int    $total_pages Total page count.
	 * @param string $base_url    Base Log-tab URL.
	 * @return void
	 */
	private function render_log_pagination( int $paged, int $per_page, int $total, int $total_pages, string $base_url ): void {
		$from = min( ( $paged - 1 ) * $per_page + 1, $total );
		$to   = min( $paged * $per_page, $total );
		?>
		<div class="spa-log-pagination">
			<span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: first entry, 2: last entry, 3: total */
						__( 'Showing %1$d–%2$d of %3$d entries', 'announcer-for-sportspress' ),
						$from,
						$to,
						$total
					)
				);
				?>
			</span>
			<div style="display:flex;gap:4px">
				<?php if ( $paged > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'log_paged', $paged - 1, $base_url ) ); ?>" class="button"><?php esc_html_e( '← Prev', 'announcer-for-sportspress' ); ?></a>
				<?php else : ?>
					<button class="button" disabled><?php esc_html_e( '← Prev', 'announcer-for-sportspress' ); ?></button>
				<?php endif; ?>
				<?php if ( $paged < $total_pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'log_paged', $paged + 1, $base_url ) ); ?>" class="button button-primary"><?php esc_html_e( 'Next →', 'announcer-for-sportspress' ); ?></a>
				<?php else : ?>
					<button class="button" disabled><?php esc_html_e( 'Next →', 'announcer-for-sportspress' ); ?></button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Log tab: inline retry-button script.
	 *
	 * @return void
	 */
	private function render_log_retry_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.spa-log-retry-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var uid   = btn.dataset.uid;
					var nonce = btn.dataset.nonce;
					btn.disabled = true;
					btn.textContent = '<?php echo esc_js( __( 'Retrying…', 'announcer-for-sportspress' ) ); ?>';
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
										textContent: '✓ <?php echo esc_js( __( 'Sent', 'announcer-for-sportspress' ) ); ?>',
									} ) );
								}
							} else {
								btn.disabled = false;
								btn.textContent = '✗ <?php echo esc_js( __( 'Retry', 'announcer-for-sportspress' ) ); ?>';
								alert( json.data || '<?php echo esc_js( __( 'Request failed.', 'announcer-for-sportspress' ) ); ?>' );
							}
						} )
						.catch( function () {
							btn.disabled = false;
							btn.textContent = '✗ <?php echo esc_js( __( 'Retry', 'announcer-for-sportspress' ) ); ?>';
						} );
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Log tab: inline script toggling the sent-message detail under a row.
	 *
	 * @return void
	 */
	private function render_log_expand_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.spa-log-row--expandable' ).forEach( function ( row ) {
				row.addEventListener( 'click', function ( e ) {
					if ( e.target.closest( '.spa-retry-btn' ) ) { return; }
					var detail = row.nextElementSibling;
					if ( ! detail || ! detail.classList.contains( 'spa-log-detail' ) ) { return; }
					var open = detail.style.display !== 'none';
					detail.style.display = open ? 'none' : '';
					var chevron = row.querySelector( '.spa-log-chevron' );
					if ( chevron ) { chevron.innerHTML = open ? '&#9656;' : '&#9662;'; }
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Assemble the data the settings page and its tabs need to render.
	 *
	 * @return array
	 */
	private function page_context(): array {
		$discord_active = ! empty( get_option( self::OPTION_WEBHOOK, '' ) );

		// Active tab — server-side, falls back to dashboard.
		$allowed_tabs = array( 'dashboard', 'channels', 'digest', 'templates', 'log', 'pro' );
		$active_tab   = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $allowed_tabs, true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'dashboard';

		return array(
			'discord_active'        => $discord_active,
			'discord_channel_count' => count( (array) get_option( self::OPTION_DISCORD_CHANNEL_MAP, array() ) ),
			'active_tab'            => $active_tab,
			'handled_sections'      => array(
				'spa_section_discord',
				'spa_section_slack',
				'spa_section_facebook',
				'spa_section_digest',
				'spa_section_announcements',
			),
			'discord_fields'        => array(
				self::OPTION_DISCORD_ENABLED,
				self::OPTION_WEBHOOK,
				self::OPTION_DISCORD_CHANNEL_MAP,
			),
			'slack_fields'          => array(
				self::OPTION_SLACK_ENABLED,
				self::OPTION_SLACK_WEBHOOK,
				self::OPTION_SLACK_CHANNEL_MAP,
			),
			'dashboard'             => $this->dashboard_context(),
			'quick_start'           => $this->quick_start_context( $discord_active ),
		);
	}

	/**
	 * Dashboard-tab context (counts + recent log).
	 *
	 * @return array
	 */
	private function dashboard_context(): array {
		$log = SPA_Log::get_all();
		return array(
			'discord_active' => ! empty( get_option( self::OPTION_WEBHOOK, '' ) ),
			'sent_today'     => count(
				array_filter(
					$log,
					static fn( $e ) => ( $e['sent_at'] ?? 0 ) >= strtotime( 'today midnight' )
				)
			),
			'log_failed'     => count( array_filter( $log, static fn( $e ) => 'failed' === ( $e['status'] ?? '' ) ) ),
			'recent_log'     => array_slice( $log, 0, 3 ),
			'log_total'      => count( $log ),
			'last_digest_ts' => (int) get_option( 'spa_last_digest_sent', 0 ),
		);
	}

	/**
	 * Quick Start checklist completion flags.
	 *
	 * @param bool $discord_active Whether Discord is configured.
	 * @return array
	 */
	private function quick_start_context( bool $discord_active ): array {
		$qs_raw       = get_user_meta( get_current_user_id(), self::QS_USER_META, true );
		$qs_dismissed = is_array( $qs_raw ) ? $qs_raw : array();

		return array(
			'connected' => $discord_active || ! empty( get_option( self::OPTION_SLACK_WEBHOOK, '' ) ) || ! empty( $qs_dismissed['connected'] ),
			'templated' => ( get_option( self::OPTION_RESULT_TEMPLATE, self::DEFAULT_RESULT_TEMPLATE ) !== self::DEFAULT_RESULT_TEMPLATE ) || ! empty( $qs_dismissed['templated'] ),
			'tested'    => ! empty( $qs_dismissed['tested'] ),
			'published' => ! empty( $qs_dismissed['published'] ),
		);
	}

	/**
	 * Render the tab navigation bar.
	 *
	 * @param string $active_tab            Active tab slug.
	 * @param bool   $discord_active        Whether Discord is configured.
	 * @param int    $discord_channel_count Number of routed Discord channels.
	 * @return void
	 */
	private function render_page_tabs( string $active_tab, bool $discord_active, int $discord_channel_count ): void {
		?>
		<nav class="spa-tabs" role="tablist">
			<button type="button" class="spa-tab<?php echo 'dashboard' === $active_tab ? ' is-active' : ''; ?>" data-tab="dashboard" role="tab" aria-selected="<?php echo 'dashboard' === $active_tab ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Dashboard', 'announcer-for-sportspress' ); ?>
			</button>
			<button type="button" class="spa-tab<?php echo 'channels' === $active_tab ? ' is-active' : ''; ?>" data-tab="channels" role="tab" aria-selected="<?php echo 'channels' === $active_tab ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Channels', 'announcer-for-sportspress' ); ?>
				<?php if ( $discord_active ) : ?>
					<span class="spa-tab-badge"><?php echo esc_html( (string) max( 1, $discord_channel_count ) ); ?></span>
				<?php endif; ?>
			</button>
			<button type="button" class="spa-tab<?php echo 'digest' === $active_tab ? ' is-active' : ''; ?>" data-tab="digest" role="tab" aria-selected="<?php echo 'digest' === $active_tab ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Digest', 'announcer-for-sportspress' ); ?>
			</button>
			<button type="button" class="spa-tab<?php echo 'templates' === $active_tab ? ' is-active' : ''; ?>" data-tab="templates" role="tab" aria-selected="<?php echo 'templates' === $active_tab ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Templates', 'announcer-for-sportspress' ); ?>
			</button>
			<button type="button" class="spa-tab<?php echo 'log' === $active_tab ? ' is-active' : ''; ?>" data-tab="log" role="tab" aria-selected="<?php echo 'log' === $active_tab ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Log', 'announcer-for-sportspress' ); ?>
			</button>
			<button type="button" class="spa-tab<?php echo 'pro' === $active_tab ? ' is-active' : ''; ?>" data-tab="pro" role="tab" aria-selected="<?php echo 'pro' === $active_tab ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Pro', 'announcer-for-sportspress' ); ?>
			</button>
		</nav>
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

		$ctx = $this->page_context();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'spa_settings_group' ); ?>

				<div class="spa-page-wrap">

					<!-- Main content -->
					<div class="spa-main">

						<?php $this->render_page_tabs( $ctx['active_tab'], $ctx['discord_active'], $ctx['discord_channel_count'] ); ?>
						<?php $this->render_page_panels( $ctx ); ?>

					</div><!-- .spa-main -->

					<?php $this->render_help_sidebar( $ctx['quick_start'] ); ?>

				</div><!-- .spa-page-wrap -->
			</form>
		</div>
		<?php
		$this->render_page_script( $ctx['active_tab'] );
	}

	/**
	 * Render all tab panels.
	 *
	 * @param array $ctx Page context from page_context().
	 * @return void
	 */
	private function render_page_panels( array $ctx ): void {
		$page       = self::MENU_SLUG;
		$active_tab = $ctx['active_tab'];
		?>
		<!-- Dashboard tab -->
		<div id="spa-panel-dashboard" class="spa-panel<?php echo 'dashboard' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
			<?php $this->render_dashboard_tab( $ctx['dashboard'] ); ?>
		</div>

		<!-- Channels tab -->
		<div id="spa-panel-channels" class="spa-panel<?php echo 'channels' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
			<?php $this->render_channels_panel( $page, $ctx['discord_active'], $ctx['discord_fields'], $ctx['slack_fields'] ); ?>
		</div>

		<!-- Digest tab -->
		<div id="spa-panel-digest" class="spa-panel<?php echo 'digest' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
			<?php $this->render_registered_section( $page, 'spa_section_digest' ); ?>
			<hr style="margin:24px 0">
			<?php $this->render_weekly_digest_section(); ?>
			<?php submit_button( __( 'Save Settings', 'announcer-for-sportspress' ) ); ?>
		</div>

		<!-- Templates tab -->
		<div id="spa-panel-templates" class="spa-panel<?php echo 'templates' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
			<?php
			foreach ( array( 'spa_section_announcements', 'spa_section_facebook' ) as $section_id ) {
				$this->render_registered_section( $page, $section_id );
			}
			$this->render_unhandled_registered_sections( $page, $ctx['handled_sections'] );
			?>
			<?php submit_button( __( 'Save Settings', 'announcer-for-sportspress' ) ); ?>
		</div>

		<!-- Log tab -->
		<div id="spa-panel-log" class="spa-panel<?php echo 'log' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
			<?php $this->render_log_tab(); ?>
		</div>

		<!-- Pro tab -->
		<div id="spa-panel-pro" class="spa-panel<?php echo 'pro' === $active_tab ? ' is-active' : ''; ?>" role="tabpanel">
			<?php SPA_Pro_Tab::render(); ?>
		</div>
		<?php
	}

	/**
	 * Channels tab: Discord, Slack, and Facebook integration cards.
	 *
	 * @param string $page           Settings page slug.
	 * @param bool   $discord_active Whether Discord is configured.
	 * @param array  $discord_fields Handled Discord field ids.
	 * @param array  $slack_fields   Handled Slack field ids.
	 * @return void
	 */
	private function render_channels_panel( string $page, bool $discord_active, array $discord_fields, array $slack_fields ): void {
		$this->render_discord_card( $page, $discord_active, $discord_fields );
		$this->render_slack_card( $page, $slack_fields );
		$this->render_facebook_card();
		submit_button( __( 'Save Settings', 'announcer-for-sportspress' ) );
	}

	/**
	 * Channels tab: Discord integration card.
	 *
	 * @param string $page           Settings page slug.
	 * @param bool   $discord_active Whether Discord is configured.
	 * @param array  $discord_fields Handled Discord field ids.
	 * @return void
	 */
	private function render_discord_card( string $page, bool $discord_active, array $discord_fields ): void {
		?>
		<div class="spa-integration-card">
			<div class="spa-integration-card-head">
				<div class="spa-integration-card-title">
					<span class="spa-platform-icon spa-platform-icon--discord">D</span>
					<?php esc_html_e( 'Discord', 'announcer-for-sportspress' ); ?>
				</div>
				<?php if ( $discord_active ) : ?>
					<span class="spa-status-active">&#9679; <?php esc_html_e( 'Active', 'announcer-for-sportspress' ); ?></span>
				<?php endif; ?>
			</div>

			<!-- Enabled toggle -->
			<div class="spa-integration-card-body spa-section-alt">
				<div class="spa-section-label"><?php esc_html_e( 'Announcements', 'announcer-for-sportspress' ); ?></div>
				<?php $this->render_registered_field( $page, 'spa_section_discord', self::OPTION_DISCORD_ENABLED ); ?>
			</div>

			<!-- Default webhook -->
			<div class="spa-integration-card-body spa-section-alt">
				<div class="spa-section-label"><?php esc_html_e( 'Default Channel', 'announcer-for-sportspress' ); ?></div>
				<?php $this->render_registered_field( $page, 'spa_section_discord', self::OPTION_WEBHOOK ); ?>
			</div>

			<!-- Channel routing -->
			<div class="spa-integration-card-body">
				<div class="spa-section-label"><?php esc_html_e( 'Channel Routing', 'announcer-for-sportspress' ); ?></div>
				<?php $this->render_registered_field( $page, 'spa_section_discord', self::OPTION_DISCORD_CHANNEL_MAP ); ?>
			</div>

			<?php $this->render_unhandled_registered_fields( $page, 'spa_section_discord', $discord_fields ); ?>
		</div>
		<?php
	}

	/**
	 * Channels tab: Slack (Pro) integration card.
	 *
	 * @param string $page         Settings page slug.
	 * @param array  $slack_fields Handled Slack field ids.
	 * @return void
	 */
	private function render_slack_card( string $page, array $slack_fields ): void {
		?>
		<div class="spa-integration-card">
			<div class="spa-integration-card-head">
				<div class="spa-integration-card-title">
					<span class="spa-platform-icon spa-platform-icon--slack">S</span>
					<?php esc_html_e( 'Slack', 'announcer-for-sportspress' ); ?>
					<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'announcer-for-sportspress' ); ?></span>
				</div>
			</div>
			<div class="spa-integration-card-body">
				<?php $this->render_registered_section_callback( $page, 'spa_section_slack' ); ?>
			</div>
			<div class="spa-integration-card-body spa-section-alt">
				<div class="spa-section-label"><?php esc_html_e( 'Announcements', 'announcer-for-sportspress' ); ?></div>
				<?php $this->render_registered_field( $page, 'spa_section_slack', self::OPTION_SLACK_ENABLED ); ?>
			</div>
			<div class="spa-integration-card-body spa-section-alt">
				<div class="spa-section-label"><?php esc_html_e( 'Webhook URL', 'announcer-for-sportspress' ); ?></div>
				<?php $this->render_registered_field( $page, 'spa_section_slack', self::OPTION_SLACK_WEBHOOK ); ?>
			</div>
			<div class="spa-integration-card-body">
				<div class="spa-section-label"><?php esc_html_e( 'Channel Routing', 'announcer-for-sportspress' ); ?></div>
				<?php $this->render_registered_field( $page, 'spa_section_slack', self::OPTION_SLACK_CHANNEL_MAP ); ?>
			</div>

			<?php $this->render_unhandled_registered_fields( $page, 'spa_section_slack', $slack_fields ); ?>
		</div>
		<?php
	}

	/**
	 * Channels tab: locked Facebook (Pro) upsell card.
	 *
	 * @return void
	 */
	private function render_facebook_card(): void {
		?>
		<div class="spa-integration-card spa-integration-card--locked">
			<div class="spa-integration-card-head">
				<div class="spa-integration-card-title">
					<span class="spa-platform-icon spa-platform-icon--facebook">f</span>
					<?php esc_html_e( 'Facebook', 'announcer-for-sportspress' ); ?>
					<span class="spa-pro-badge"><?php esc_html_e( 'Pro', 'announcer-for-sportspress' ); ?></span>
				</div>
				<a href="<?php echo esc_url( SPA_License::upgrade_url( 'facebook-card' ) ); ?>" style="font-size:11px;color:#2271b1;">
					<?php esc_html_e( 'Coming soon →', 'announcer-for-sportspress' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Help sidebar with the Quick Start checklist.
	 *
	 * @param array $qs {
	 *     Checklist completion flags.
	 *
	 *     @type bool $connected Channel connected.
	 *     @type bool $templated Result template customized.
	 *     @type bool $tested    Test announcement sent.
	 *     @type bool $published First result published.
	 * }
	 * @return void
	 */
	private function render_help_sidebar( array $qs ): void {
		?>
		<div class="spa-help-sidebar">
			<h3><?php esc_html_e( 'Quick Start', 'announcer-for-sportspress' ); ?></h3>
			<ul class="spa-checklist">
				<li>
					<span class="spa-check-done">✓</span>
					<span><?php esc_html_e( 'Activate plugin', 'announcer-for-sportspress' ); ?></span>
				</li>
				<li class="spa-qs-item<?php echo $qs['connected'] ? ' is-done' : ''; ?>" data-item="connected" title="<?php esc_attr_e( 'Click to mark done', 'announcer-for-sportspress' ); ?>">
					<span class="spa-qs-icon"><?php echo $qs['connected'] ? '✓' : '○'; ?></span>
					<span><?php esc_html_e( 'Connect a channel', 'announcer-for-sportspress' ); ?></span>
				</li>
				<li class="spa-qs-item<?php echo $qs['templated'] ? ' is-done' : ''; ?>" data-item="templated" title="<?php esc_attr_e( 'Click to mark done', 'announcer-for-sportspress' ); ?>">
					<span class="spa-qs-icon"><?php echo $qs['templated'] ? '✓' : '○'; ?></span>
					<span><?php esc_html_e( 'Customize result template', 'announcer-for-sportspress' ); ?></span>
				</li>
				<li class="spa-qs-item<?php echo $qs['tested'] ? ' is-done' : ''; ?>" data-item="tested" title="<?php esc_attr_e( 'Click to mark done', 'announcer-for-sportspress' ); ?>">
					<span class="spa-qs-icon"><?php echo $qs['tested'] ? '✓' : '○'; ?></span>
					<span><?php esc_html_e( 'Send a test announcement', 'announcer-for-sportspress' ); ?></span>
				</li>
				<li class="spa-qs-item<?php echo $qs['published'] ? ' is-done' : ''; ?>" data-item="published" title="<?php esc_attr_e( 'Click to mark done', 'announcer-for-sportspress' ); ?>">
					<span class="spa-qs-icon"><?php echo $qs['published'] ? '✓' : '○'; ?></span>
					<span><?php esc_html_e( 'Publish your first result', 'announcer-for-sportspress' ); ?></span>
				</li>
			</ul>

			<div class="spa-help-divider"></div>

			<h4 id="spa-help-tab-title"><?php esc_html_e( 'On this tab', 'announcer-for-sportspress' ); ?></h4>
			<p class="spa-help-tip" id="spa-help-tab-tip">
				<?php esc_html_e( 'Your daily cockpit — platform status, recent announcements, and upcoming digest.', 'announcer-for-sportspress' ); ?>
			</p>

			<div class="spa-help-links">
				<a href="https://support.discord.com/hc/en-us/articles/228383668" target="_blank" rel="noopener">📖 <?php esc_html_e( 'How to create a webhook', 'announcer-for-sportspress' ); ?></a>
				<a href="https://sportspress-announcer.com/docs" target="_blank" rel="noopener">? <?php esc_html_e( 'Full docs →', 'announcer-for-sportspress' ); ?></a>
			</div>
		</div><!-- .spa-help-sidebar -->
		<?php
	}

	/**
	 * Inline script for tab switching and the Quick Start checklist.
	 *
	 * @param string $active_tab Currently active tab slug.
	 * @return void
	 */
	private function render_tab_switch_script( string $active_tab ): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var tabs   = document.querySelectorAll( '.spa-tab' );
			var panels = document.querySelectorAll( '.spa-panel' );

			var tips = {
				dashboard: '<?php echo esc_js( __( 'Your daily cockpit — platform status, recent announcements, and upcoming digest.', 'announcer-for-sportspress' ) ); ?>',
				channels:  '<?php echo esc_js( __( 'One Discord channel handles most leagues. Add routing rules only if you run multiple divisions.', 'announcer-for-sportspress' ) ); ?>',
				digest:    '<?php echo esc_js( __( 'The digest lists upcoming games for the next 7 days. Use auto-send to post it to Discord on a schedule.', 'announcer-for-sportspress' ) ); ?>',
				templates: '<?php echo esc_js( __( 'Click any placeholder chip to insert it into the template. Team names are auto-bolded on each platform.', 'announcer-for-sportspress' ) ); ?>',
				log:       '<?php echo esc_js( __( 'Full history of every announcement sent. Filter by type or search by event name.', 'announcer-for-sportspress' ) ); ?>',
				pro:       '<?php echo esc_js( __( 'A look at what Pro will add. Not for sale yet, but coming soon.', 'announcer-for-sportspress' ) ); ?>',
			};

			var tipEl = document.getElementById( 'spa-help-tab-tip' );

			if ( tipEl && tips[ '<?php echo esc_js( $active_tab ); ?>' ] ) {
				tipEl.textContent = tips[ '<?php echo esc_js( $active_tab ); ?>' ];
			}

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
		} );
		</script>
		<?php
	}

	/**
	 * Render the settings-page inline scripts.
	 *
	 * The Quick Start checklist behavior lives in the enqueued
	 * assets/js/spa-quickstart.js; only the tab switcher is inlined here
	 * because its tab-tip strings are translated per-request.
	 *
	 * @param string $active_tab Currently active tab slug.
	 * @return void
	 */
	private function render_page_script( string $active_tab ): void {
		$this->render_tab_switch_script( $active_tab );
	}

	// -------------------------------------------------------------------------
	// Weekly Digest (results recap) — Pro-gated posting, free preview.
	// -------------------------------------------------------------------------

	/**
	 * Register all spa_weekly_digest_* settings under the shared group.
	 */
	private function register_weekly_digest_settings(): void {
		$bool_fields = array(
			'spa_weekly_digest_enabled',
			'spa_weekly_digest_include_results',
			'spa_weekly_digest_include_standings',
			'spa_weekly_digest_include_leaders',
			'spa_weekly_digest_include_upcoming',
			'spa_weekly_digest_publish_as_post',
		);
		foreach ( $bool_fields as $field ) {
			register_setting(
				'spa_settings_group',
				$field,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => false,
				)
			);
		}

		$this->register_weekly_digest_schedule_and_scope();
	}

	/**
	 * Register the weekly-digest day, time, leagues, and stat-key options.
	 *
	 * @return void
	 */
	private function register_weekly_digest_schedule_and_scope(): void {
		register_setting(
			'spa_settings_group',
			'spa_weekly_digest_day',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_day' ),
				'default'           => 'monday',
			)
		);

		register_setting(
			'spa_settings_group',
			'spa_weekly_digest_time',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_digest_time' ),
				'default'           => '09:00',
			)
		);

		register_setting(
			'spa_settings_group',
			'spa_weekly_digest_leagues',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_weekly_digest_leagues' ),
				'default'           => array(),
			)
		);

		register_setting(
			'spa_settings_group',
			'spa_weekly_digest_stat_keys',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_weekly_digest_stat_keys' ),
				'default'           => array(),
			)
		);

		register_setting(
			'spa_settings_group',
			'spa_weekly_digest_seasons',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_weekly_digest_seasons' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize the selected-leagues array to positive integers.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public function sanitize_weekly_digest_leagues( $value ): array {
		return array_values( array_filter( array_map( 'intval', (array) $value ) ) );
	}

	/**
	 * Sanitize stat keys — sanitize_key, cap at 3.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public function sanitize_weekly_digest_stat_keys( $value ): array {
		return array_slice( array_map( 'sanitize_key', (array) $value ), 0, 3 );
	}

	/**
	 * Sanitize the league_id => season_id map to positive integers.
	 * A season value of 0 means "all seasons" and is kept, not dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,int>
	 */
	public function sanitize_weekly_digest_seasons( $value ): array {
		$sanitized = array();
		foreach ( (array) $value as $league_id => $season_id ) {
			$league_id = intval( $league_id );
			if ( $league_id > 0 ) {
				$sanitized[ $league_id ] = max( 0, intval( $season_id ) );
			}
		}
		return $sanitized;
	}

	/**
	 * Render the Weekly Digest form. Free users see it disabled with a
	 * Pro upgrade strip; the preview button is always active.
	 *
	 * @return void
	 */
	public function render_weekly_digest_section(): void {
		$locked = ! SPA_License::is_pro();
		$dis    = $locked ? ' disabled' : '';

		$league_scope    = array(
			'leagues'          => $this->get_league_terms(),
			'seasons'          => $this->get_season_terms(),
			'selected_lg'      => array_map( 'strval', (array) get_option( 'spa_weekly_digest_leagues', array() ) ),
			'selected_seasons' => (array) get_option( 'spa_weekly_digest_seasons', array() ),
		);
		$available_stats = $this->get_available_stat_keys();
		$selected_stats  = (array) get_option( 'spa_weekly_digest_stat_keys', array() );
		?>
		<div class="spa-weekly-digest<?php echo $locked ? ' spa-pro-locked' : ''; ?>">

			<h3><?php esc_html_e( 'Weekly Recap', 'announcer-for-sportspress' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'A weekly rhythm: results, standings movement, and stat leaders, posted automatically to your channels.', 'announcer-for-sportspress' ); ?>
			</p>

			<?php if ( $locked ) : ?>
			<div class="spa-pro-strip">
				<span class="dashicons dashicons-lock"></span>
				<?php esc_html_e( 'Scheduling & automatic posting require Pro.', 'announcer-for-sportspress' ); ?>
				<a href="<?php echo esc_url( $this->weekly_digest_upgrade_url() ); ?>">
					<?php esc_html_e( 'Coming soon: see what Pro adds →', 'announcer-for-sportspress' ); ?>
				</a>
			</div>
			<?php endif; ?>

			<?php $this->render_weekly_digest_table( $dis, $league_scope, $available_stats, $selected_stats ); ?>

			<hr>

			<?php $this->render_weekly_digest_preview( $league_scope['leagues'] ); ?>
		</div>
		<?php
		$this->render_weekly_digest_preview_script();
	}

	/**
	 * Weekly-digest settings table (schedule, leagues, content, stat leaders).
	 *
	 * @param string $dis             ' disabled' when locked, else ''.
	 * @param array  $league_scope    Leagues, seasons, and their selected values; see render_digest_league_checkboxes().
	 * @param array  $available_stats stat_key => label.
	 * @param array  $selected_stats  Selected stat keys.
	 * @return void
	 */
	private function render_weekly_digest_table( string $dis, array $league_scope, array $available_stats, array $selected_stats ): void {
		?>
		<table class="form-table" role="presentation">
			<?php $this->render_weekly_digest_schedule_rows( $dis ); ?>

			<?php if ( ! empty( $league_scope['leagues'] ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Leagues', 'announcer-for-sportspress' ); ?></th>
				<td>
					<?php $this->render_digest_league_checkboxes( $league_scope, $dis ); ?>
					<p class="description"><?php esc_html_e( 'One digest is posted per selected league. Choose a season to isolate standings, results, and stat leaders to that season, or leave "All seasons" to combine every season under the league.', 'announcer-for-sportspress' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Content', 'announcer-for-sportspress' ); ?></th>
				<td>
					<?php $this->render_weekly_digest_content_toggles( $dis ); ?>
				</td>
			</tr>

			<?php if ( ! empty( $available_stats ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stat leaders (up to 3)', 'announcer-for-sportspress' ); ?></th>
				<td>
					<?php $this->render_digest_stat_checkboxes( $available_stats, $selected_stats, $dis ); ?>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Render a league checkbox, paired with a season selector, per available
	 * league term.
	 *
	 * @param array  $league_scope {
	 *   Leagues, seasons, and their selected values.
	 *
	 *   @type WP_Term[] $leagues          Available league terms.
	 *   @type WP_Term[] $seasons          Available season terms.
	 *   @type string[]  $selected_lg      Selected league ids (as strings).
	 *   @type array     $selected_seasons league_id => season_id.
	 * }
	 * @param string $dis          ' disabled' when locked, else ''.
	 * @return void
	 */
	private function render_digest_league_checkboxes( array $league_scope, string $dis ): void {
		foreach ( $league_scope['leagues'] as $league ) {
			?>
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
				<?php
				$this->render_digest_checkbox(
					'spa_weekly_digest_leagues[]',
					(string) $league->term_id,
					$league->name,
					in_array( (string) $league->term_id, $league_scope['selected_lg'], true ),
					$dis
				);
				?>
				<?php if ( ! empty( $league_scope['seasons'] ) ) : ?>
					<?php $this->render_digest_season_select( $league->term_id, $league_scope['seasons'], $league_scope['selected_seasons'], $dis ); ?>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Render the season <select> for one league row.
	 *
	 * @param int       $league_id        League term ID this select scopes.
	 * @param WP_Term[] $seasons          Available season terms.
	 * @param array     $selected_seasons league_id => season_id.
	 * @param string    $dis              ' disabled' when locked, else ''.
	 * @return void
	 */
	private function render_digest_season_select( int $league_id, array $seasons, array $selected_seasons, string $dis ): void {
		$selected = isset( $selected_seasons[ $league_id ] )
			? intval( $selected_seasons[ $league_id ] )
			: $this->get_current_season_id();
		?>
		<select name="spa_weekly_digest_seasons[<?php echo esc_attr( $league_id ); ?>]"<?php echo $dis; // phpcs:ignore ?>>
			<option value="0"<?php selected( $selected, 0 ); ?>><?php esc_html_e( 'All seasons', 'announcer-for-sportspress' ); ?></option>
			<?php foreach ( $seasons as $season ) : ?>
			<option value="<?php echo esc_attr( $season->term_id ); ?>"<?php selected( $selected, $season->term_id ); ?>><?php echo esc_html( $season->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render a stat checkbox per available stat key.
	 *
	 * @param array  $available_stats stat_key => label.
	 * @param array  $selected_stats  Selected stat keys.
	 * @param string $dis             ' disabled' when locked, else ''.
	 * @return void
	 */
	private function render_digest_stat_checkboxes( array $available_stats, array $selected_stats, string $dis ): void {
		foreach ( $available_stats as $stat_key => $stat_label ) {
			$this->render_digest_checkbox(
				'spa_weekly_digest_stat_keys[]',
				$stat_key,
				$stat_label,
				in_array( $stat_key, $selected_stats, true ),
				$dis
			);
		}
	}

	/**
	 * Weekly-digest schedule rows: enable, send day, and send time.
	 *
	 * @param string $dis ' disabled' when locked, else ''.
	 * @return void
	 */
	private function render_weekly_digest_schedule_rows( string $dis ): void {
		$current_day = get_option( 'spa_weekly_digest_day', 'monday' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable', 'announcer-for-sportspress' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="spa_weekly_digest_enabled" value="1"
						<?php checked( get_option( 'spa_weekly_digest_enabled' ) ); ?><?php echo $dis; // phpcs:ignore ?>>
					<?php esc_html_e( 'Send an automatic weekly recap to configured channels', 'announcer-for-sportspress' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Send day', 'announcer-for-sportspress' ); ?></th>
			<td>
				<select name="spa_weekly_digest_day"<?php echo $dis; // phpcs:ignore ?>>
					<?php $this->render_weekday_options( $current_day ); ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Send time', 'announcer-for-sportspress' ); ?></th>
			<td>
				<input type="time" name="spa_weekly_digest_time"
					value="<?php echo esc_attr( get_option( 'spa_weekly_digest_time', '09:00' ) ); ?>"<?php echo $dis; // phpcs:ignore ?>>
				<p class="description">
					<?php $this->render_weekly_digest_timezone_hint(); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render <option> elements for the weekday <select>.
	 *
	 * @param string $current_day Currently selected weekday value.
	 * @return void
	 */
	private function render_weekday_options( string $current_day ): void {
		foreach ( $this->weekday_choices() as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current_day, $value, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Timezone / wp-cron reliability hint under the send-time field.
	 *
	 * @return void
	 */
	private function render_weekly_digest_timezone_hint(): void {
		printf(
			/* translators: %s: site timezone string */
			esc_html__( 'Uses your site timezone (%s). On low-traffic sites, set up a real server cron hitting wp-cron.php for reliable delivery.', 'announcer-for-sportspress' ),
			esc_html( wp_timezone_string() )
		);
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo ' <strong>' . esc_html__( 'DISABLE_WP_CRON is active — a real cron job is required.', 'announcer-for-sportspress' ) . '</strong>';
		}
	}

	/**
	 * Render the weekly-digest "Content" section checkboxes.
	 *
	 * @param string $dis ' disabled' when locked, else ''.
	 * @return void
	 */
	private function render_weekly_digest_content_toggles( string $dis ): void {
		$toggles     = array(
			'spa_weekly_digest_include_results'   => __( 'Results', 'announcer-for-sportspress' ),
			'spa_weekly_digest_include_standings' => __( 'Standings & movement', 'announcer-for-sportspress' ),
			'spa_weekly_digest_include_leaders'   => __( 'Stat leaders', 'announcer-for-sportspress' ),
			'spa_weekly_digest_include_upcoming'  => __( 'Upcoming games', 'announcer-for-sportspress' ),
			'spa_weekly_digest_publish_as_post'   => __( 'Also publish as a site post', 'announcer-for-sportspress' ),
		);
		$defaults_on = array( 'spa_weekly_digest_include_results', 'spa_weekly_digest_include_standings', 'spa_weekly_digest_include_leaders' );

		foreach ( $toggles as $option => $label ) {
			$default = in_array( $option, $defaults_on, true );
			$this->render_digest_checkbox( $option, '1', $label, (bool) get_option( $option, $default ), $dis );
		}
	}

	/**
	 * Render one labeled checkbox used across the weekly-digest form.
	 *
	 * @param string $name    Input name attribute.
	 * @param string $value   Input value attribute.
	 * @param string $label   Visible label.
	 * @param bool   $checked Whether the box is checked.
	 * @param string $dis     ' disabled' when locked, else ''.
	 * @return void
	 */
	private function render_digest_checkbox( string $name, string $value, string $label, bool $checked, string $dis ): void {
		?>
		<label style="display:block;margin-bottom:4px">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
				<?php checked( $checked ); ?><?php echo $dis; // phpcs:ignore ?>>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * Weekly-digest preview UI (league picker + generate button).
	 *
	 * @param WP_Term[] $leagues Available league terms.
	 * @return void
	 */
	private function render_weekly_digest_preview( array $leagues ): void {
		?>
		<h4><?php esc_html_e( 'Preview', 'announcer-for-sportspress' ); ?></h4>
		<p class="description">
			<?php esc_html_e( 'Generate a preview using your real SportsPress data. Available on the free plan.', 'announcer-for-sportspress' ); ?>
		</p>

		<?php if ( ! empty( $leagues ) ) : ?>
		<p>
			<label for="spa-weekly-preview-league"><?php esc_html_e( 'League:', 'announcer-for-sportspress' ); ?></label>
			<select id="spa-weekly-preview-league">
				<?php foreach ( $leagues as $league ) : ?>
				<option value="<?php echo esc_attr( $league->term_id ); ?>"><?php echo esc_html( $league->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php endif; ?>

		<div style="display:flex;align-items:center;gap:8px;">
			<button type="button" id="spa-weekly-preview-btn" class="button button-secondary"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'spa_generate_digest_preview_nonce' ) ); ?>">
				<?php esc_html_e( 'Regenerate preview', 'announcer-for-sportspress' ); ?>
			</button>
			<button type="button" id="spa-weekly-send-btn" class="button button-primary"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'spa_send_weekly_digest_now_nonce' ) ); ?>">
				<?php esc_html_e( 'Send now', 'announcer-for-sportspress' ); ?>
			</button>
			<span id="spa-weekly-preview-spinner" class="spinner" style="float:none;vertical-align:middle;display:none;"></span>
			<span id="spa-weekly-send-result" class="spa-send-result"></span>
		</div>

		<div id="spa-weekly-preview-output" style="margin-top:16px;display:none;"></div>
		<?php
	}

	/**
	 * Inline script for the weekly-digest preview generator.
	 *
	 * @return void
	 */
	private function render_weekly_digest_preview_script(): void {
		$this->render_confirm_modal_script();
		?>
		<script>
		( function() {
			var btn = document.getElementById( 'spa-weekly-preview-btn' );
			if ( ! btn ) { return; }
			var sendBtn   = document.getElementById( 'spa-weekly-send-btn' );
			var leagueSel = document.getElementById( 'spa-weekly-preview-league' );
			var output    = document.getElementById( 'spa-weekly-preview-output' );
			var spinner   = document.getElementById( 'spa-weekly-preview-spinner' );
			var sendResult = document.getElementById( 'spa-weekly-send-result' );

			function generate() {
				spinner.style.display = 'inline-block';
				output.style.display  = 'none';

				var fd = new FormData();
				fd.append( 'action', 'spa_generate_digest_preview' );
				fd.append( 'nonce', btn.dataset.nonce );
				fd.append( 'league_id', leagueSel ? leagueSel.value : 0 );

				return fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function( r ) { return r.json(); } )
					.then( function( res ) {
						spinner.style.display = 'none';
						output.style.display  = 'block';
						output.innerHTML = res.success
							? res.data.html
							: '<p style="color:#d63638"><?php echo esc_js( __( 'Could not generate preview. Check that SportsPress is active and leagues are configured.', 'announcer-for-sportspress' ) ); ?></p>';
					} )
					.catch( function() {
						spinner.style.display = 'none';
						output.style.display  = 'block';
						output.innerHTML = '<p style="color:#d63638"><?php echo esc_js( __( 'Request failed.', 'announcer-for-sportspress' ) ); ?></p>';
					} );
			}

			btn.addEventListener( 'click', generate );
			if ( leagueSel ) { leagueSel.addEventListener( 'change', generate ); }

			// Auto-generate a preview on first view so the recap is always visible.
			generate();
			<?php $this->render_weekly_send_button_js(); ?>
		}() );
		</script>
		<?php
	}

	/**
	 * JS wiring for the weekly "Send now" button (inside the preview IIFE,
	 * where sendBtn/leagueSel/sendResult are already in scope).
	 *
	 * @return void
	 */
	private function render_weekly_send_button_js(): void {
		?>
		if ( sendBtn ) {
			function sendWeekly() {
				sendBtn.disabled  = true;
				var idle          = sendBtn.textContent;
				sendBtn.textContent = <?php echo wp_json_encode( __( 'Sending…', 'announcer-for-sportspress' ) ); ?>;
				if ( sendResult ) { sendResult.textContent = ''; }

				var fd = new FormData();
				fd.append( 'action', 'spa_send_weekly_digest_now' );
				fd.append( 'nonce', sendBtn.dataset.nonce );
				fd.append( 'league_id', leagueSel ? leagueSel.value : 0 );

				fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function( r ) { return r.json(); } )
					.then( function( res ) {
						sendBtn.disabled = false;
						sendBtn.textContent = idle;
						if ( ! sendResult ) { return; }
						sendResult.textContent = res.success
							? <?php echo wp_json_encode( __( '✓ Sent', 'announcer-for-sportspress' ) ); ?>
							: ( res.data || <?php echo wp_json_encode( __( '✗ Failed', 'announcer-for-sportspress' ) ); ?> );
						sendResult.style.color = res.success ? '#00a32a' : '#d63638';
					} )
					.catch( function() {
						sendBtn.disabled = false;
						sendBtn.textContent = idle;
						if ( sendResult ) {
							sendResult.textContent = <?php echo wp_json_encode( __( '✗ Failed', 'announcer-for-sportspress' ) ); ?>;
							sendResult.style.color = '#d63638';
						}
					} );
			}

			sendBtn.addEventListener( 'click', function() {
				window.spaConfirmSend( {
					title: <?php echo wp_json_encode( __( 'Send the weekly digest now?', 'announcer-for-sportspress' ) ); ?>,
					previewHtml: output ? output.innerHTML : ''
				} ).then( function( ok ) {
					if ( ok ) { sendWeekly(); }
				} );
			} );
		}
		<?php
	}

	/**
	 * League taxonomy terms, or an empty array on failure.
	 *
	 * @return WP_Term[]
	 */
	private function get_league_terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
			)
		);
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * Season taxonomy terms, or an empty array on failure.
	 *
	 * SportsPress registers sp_season conditionally, behind
	 * `apply_filters( 'sportspress_has_seasons', true )` — unlike sp_league,
	 * which is always registered. Guard with taxonomy_exists() so a site with
	 * seasons disabled (or not yet registered at render time) degrades to
	 * "no seasons" instead of a WP_Error silently doing the same thing less
	 * clearly.
	 *
	 * @return WP_Term[]
	 */
	private function get_season_terms(): array {
		if ( ! taxonomy_exists( 'sp_season' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
			)
		);
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * SportsPress's own "current season" term ID, used only as the default
	 * for a league that has no digest season saved yet. Falls back to 0
	 * ("all seasons") if SportsPress exposes no current-season setting.
	 *
	 * @return int
	 */
	private function get_current_season_id(): int {
		return intval( get_option( 'sportspress_season', 0 ) );
	}

	/**
	 * Weekday choices for the schedule selector.
	 *
	 * @return array<string,string> weekday slug => label
	 */
	private function weekday_choices(): array {
		return array(
			'monday'    => __( 'Monday', 'announcer-for-sportspress' ),
			'tuesday'   => __( 'Tuesday', 'announcer-for-sportspress' ),
			'wednesday' => __( 'Wednesday', 'announcer-for-sportspress' ),
			'thursday'  => __( 'Thursday', 'announcer-for-sportspress' ),
			'friday'    => __( 'Friday', 'announcer-for-sportspress' ),
			'saturday'  => __( 'Saturday', 'announcer-for-sportspress' ),
			'sunday'    => __( 'Sunday', 'announcer-for-sportspress' ),
		);
	}

	/**
	 * Available SportsPress performance stat slugs → labels, with a fallback.
	 *
	 * @return array<string,string>
	 */
	private function get_available_stat_keys(): array {
		if ( function_exists( 'sp_get_var_labels' ) ) {
			$labels = sp_get_var_labels( 'sp_performance' );
			if ( ! empty( $labels ) && is_array( $labels ) ) {
				return $labels;
			}
		}
		return array(
			'goals'   => __( 'Goals', 'announcer-for-sportspress' ),
			'assists' => __( 'Assists', 'announcer-for-sportspress' ),
			'points'  => __( 'Points', 'announcer-for-sportspress' ),
		);
	}

	/**
	 * Link target for the Weekly Digest Pro strip: the in-plugin Pro page.
	 *
	 * @return string
	 */
	private function weekly_digest_upgrade_url(): string {
		return SPA_License::upgrade_url( 'weekly-digest' );
	}

	/**
	 * AJAX: generate a digest preview for a given league.
	 * Free and Pro — no license gate.
	 */
	public function ajax_generate_digest_preview(): void {
		check_ajax_referer( 'spa_generate_digest_preview_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'announcer-for-sportspress' ) ) );
		}

		$league_id = intval( wp_unslash( $_POST['league_id'] ?? 0 ) );

		if ( ! class_exists( 'SPA_Digest_Builder' ) || ! class_exists( 'SPA_Digest_Formatter' ) ) {
			wp_send_json_error( array( 'message' => __( 'Digest classes not loaded.', 'announcer-for-sportspress' ) ) );
		}

		$builder = new SPA_Digest_Builder( $league_id, SPA_Digest_Builder::options_from_settings( $league_id ) );

		$data      = $builder->build();
		$formatter = new SPA_Digest_Formatter( $data );

		wp_send_json_success( array( 'html' => $formatter->to_html() ) );
	}
}
