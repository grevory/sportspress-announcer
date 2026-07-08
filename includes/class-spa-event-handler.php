<?php
/**
 * Listens for SportsPress event saves and triggers announcements.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles SportsPress event saves and sends result announcements.
 */
class SPA_Event_Handler {

	/**
	 * Register the event-save callback.
	 */
	public function __construct() {
		// Must hook save_post (not save_post_sp_event) at priority > 1 so SportsPress
		// has already written sp_results meta before we read it.
		// save_post_sp_event fires before save_post entirely, so scores would be stale.
		add_action( 'save_post', array( $this, 'on_event_save' ), 20, 2 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'maybe_render_weekly_upsell' ) );
			add_action( 'wp_ajax_spa_dismiss_weekly_upsell', array( $this, 'ajax_dismiss_weekly_upsell' ) );
		}
	}

	/**
	 * Render the one-time Weekly Digest upsell after a successful announcement.
	 * Free users only; dismissible per-user (WP.org: one dismissible notice max).
	 *
	 * @return void
	 */
	public function maybe_render_weekly_upsell(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( 'spa_show_weekly_upsell' ) ) {
			return;
		}
		delete_transient( 'spa_show_weekly_upsell' );

		if ( SPA_License::is_pro() || get_user_meta( get_current_user_id(), 'spa_weekly_upsell_dismissed', true ) ) {
			return;
		}

		$digest_url = admin_url( 'options-general.php?page=sportspress-announcer&tab=digest' );
		$nonce      = wp_create_nonce( 'spa_dismiss_weekly_upsell_nonce' );
		?>
		<div class="notice notice-info is-dismissible spa-weekly-upsell" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<?php esc_html_e( 'Result announced! 🏆', 'sportspress-announcer' ); ?>
				<?php esc_html_e( 'Turn one-off scores into a weekly rhythm —', 'sportspress-announcer' ); ?>
				<a href="<?php echo esc_url( $digest_url ); ?>"><?php esc_html_e( 'set up the Weekly Digest →', 'sportspress-announcer' ); ?></a>
			</p>
		</div>
		<script>
		( function() {
			var notice = document.querySelector( '.spa-weekly-upsell' );
			if ( ! notice ) { return; }
			notice.addEventListener( 'click', function( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var fd = new FormData();
				fd.append( 'action', 'spa_dismiss_weekly_upsell' );
				fd.append( 'nonce', notice.dataset.nonce );
				fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * AJAX: remember that the current user dismissed the Weekly Digest upsell.
	 *
	 * @return void
	 */
	public function ajax_dismiss_weekly_upsell(): void {
		check_ajax_referer( 'spa_dismiss_weekly_upsell_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		update_user_meta( get_current_user_id(), 'spa_weekly_upsell_dismissed', 1 );
		wp_send_json_success();
	}

	/**
	 * Send an announcement after a published SportsPress event is saved.
	 *
	 * @param int      $post_id Event post ID.
	 * @param \WP_Post $post    Event post object.
	 *
	 * @return void
	 */
	public function on_event_save( int $post_id, \WP_Post $post ): void {
		if ( 'sp_event' !== $post->post_type ) {
			return;
		}
		// Skip autosaves, revisions, and trashed posts.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$event = $this->extract_event_data( $post_id );
		if ( ! $event ) {
			return;
		}

		// Don't announce fixtures where scores haven't been entered yet.
		if ( '' === $event['home_score'] || '' === $event['away_score'] ) {
			return;
		}

		// Deduplicate within the same request (save_post can fire multiple times per click).
		static $posted_this_request = array();

		$score_hash = md5( $event['home_score'] . ':' . $event['away_score'] );
		if ( isset( $posted_this_request[ $post_id ] ) ) {
			return;
		}

		// Skip if the score hasn't changed since the last announcement.
		$last_hash = get_post_meta( $post_id, '_spa_last_score_hash', true );
		if ( $score_hash === $last_hash ) {
			return;
		}

		$posted_this_request[ $post_id ] = true;

		$formatter = new SPA_Message_Formatter();

		$announced = $this->announce_discord( $event, $formatter, $post_id );
		$announced = $this->announce_slack( $event, $formatter, $post_id ) || $announced;

		if ( $announced ) {
			update_post_meta( $post_id, '_spa_last_score_hash', $score_hash );
			$this->maybe_flag_weekly_upsell();
		}
	}

	/**
	 * Announce a result to Discord if enabled and configured.
	 *
	 * @param array                 $event     Extracted event data.
	 * @param SPA_Message_Formatter $formatter Message formatter.
	 * @param int                   $post_id   Event post ID.
	 * @return bool True on a successful send.
	 */
	private function announce_discord( array $event, SPA_Message_Formatter $formatter, int $post_id ): bool {
		if ( ! get_option( SPA_Settings::OPTION_DISCORD_ENABLED, true ) ) {
			return false;
		}

		$channel_map = (array) get_option( SPA_Settings::OPTION_DISCORD_CHANNEL_MAP, array() );
		$competition = $event['competition'];
		$discord_url = ( $competition && ! empty( $channel_map[ $competition ] ) )
			? $channel_map[ $competition ]
			: get_option( 'spa_discord_webhook_url', '' );

		if ( empty( $discord_url ) ) {
			return false;
		}

		$webhook = new SPA_Webhook_Discord( $discord_url );
		$result  = $webhook->send( $formatter->format_embed( $event ) );

		return $this->handle_send_result( $result, $event, $formatter, $post_id, 'discord' );
	}

	/**
	 * Announce a result to Slack (Pro) if enabled and configured.
	 *
	 * @param array                 $event     Extracted event data.
	 * @param SPA_Message_Formatter $formatter Message formatter.
	 * @param int                   $post_id   Event post ID.
	 * @return bool True on a successful send.
	 */
	private function announce_slack( array $event, SPA_Message_Formatter $formatter, int $post_id ): bool {
		if ( ! get_option( SPA_Settings::OPTION_SLACK_ENABLED, false ) ) {
			return false;
		}

		$channel_map = (array) get_option( SPA_Settings::OPTION_SLACK_CHANNEL_MAP, array() );
		$competition = $event['competition'];
		$slack_url   = ( $competition && ! empty( $channel_map[ $competition ] ) )
			? $channel_map[ $competition ]
			: get_option( SPA_Settings::OPTION_SLACK_WEBHOOK, '' );

		if ( empty( $slack_url ) ) {
			return false;
		}

		$webhook = new SPA_Webhook_Slack( $slack_url );
		$result  = $webhook->send( $formatter->format_slack( $event ) );

		return $this->handle_send_result( $result, $event, $formatter, $post_id, 'slack' );
	}

	/**
	 * Log a webhook send outcome, firing an error action on failure.
	 *
	 * @param mixed                 $result    Webhook return value (WP_Error on failure).
	 * @param array                 $event     Extracted event data.
	 * @param SPA_Message_Formatter $formatter Message formatter.
	 * @param int                   $post_id   Event post ID.
	 * @param string                $platform  'discord' or 'slack'.
	 * @return bool True when the send succeeded.
	 */
	private function handle_send_result( $result, array $event, SPA_Message_Formatter $formatter, int $post_id, string $platform ): bool {
		$failed = is_wp_error( $result );

		if ( $failed ) {
			/**
			 * Fires when a result announcement fails.
			 *
			 * @param \WP_Error $result  Webhook error.
			 * @param int       $post_id Event post ID.
			 */
			do_action( "spa_{$platform}_webhook_error", $result, $post_id );
		}

		SPA_Log::write(
			array(
				'id'          => $post_id,
				'type'        => 'result',
				'label'       => $formatter->format_result( $event ),
				'channel'     => $event['competition'] ? $event['competition'] : '',
				'competition' => $event['competition'],
				'platform'    => $platform,
				'status'      => $failed ? 'failed' : 'sent',
			)
		);

		return ! $failed;
	}

	/**
	 * Free-tier upsell: flag a one-time Weekly Digest hint for the next admin
	 * page load, for free users who haven't dismissed it.
	 *
	 * @return void
	 */
	private function maybe_flag_weekly_upsell(): void {
		if ( ! SPA_License::is_pro() && ! get_user_meta( get_current_user_id(), 'spa_weekly_upsell_dismissed', true ) ) {
			set_transient( 'spa_show_weekly_upsell', 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Pull teams, scores, and competition from SportsPress post meta.
	 *
	 * @param int $post_id Event post ID.
	 *
	 * @return array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string, home_color: string, event_url: string}|false
	 */
	public function extract_event_data( int $post_id ) {
		// SportsPress stores teams as a post meta array keyed by team post IDs.
		$team_ids = get_post_meta( $post_id, 'sp_team', false );
		if ( empty( $team_ids ) || count( $team_ids ) < 2 ) {
			return false;
		}

		$home_id = (int) $team_ids[0];
		$away_id = (int) $team_ids[1];

		$home = get_the_title( $home_id );
		$away = get_the_title( $away_id );

		// Scores are stored as serialised results keyed by team ID then column key.
		$results = get_post_meta( $post_id, 'sp_results', true );

		$home_score = '';
		$away_score = '';

		if ( is_array( $results ) ) {
			$col        = (string) get_option( SPA_Settings::OPTION_SCORE_COLUMN, SPA_Settings::DEFAULT_SCORE_COLUMN );
			$home_score = $results[ $home_id ][ $col ] ?? ( $results[ $home_id ]['outcome'] ?? '' );
			$away_score = $results[ $away_id ][ $col ] ?? ( $results[ $away_id ]['outcome'] ?? '' );
		}

		// Competition (league/cup) linked via taxonomy.
		$leagues     = wp_get_post_terms( $post_id, 'sp_league' );
		$competition = '';
		$league_id   = 0;
		if ( ! is_wp_error( $leagues ) && ! empty( $leagues ) ) {
			$competition = $leagues[0]->name;
			$league_id   = (int) $leagues[0]->term_id;
		}

		return array(
			'home'        => $home ? $home : __( 'Home', 'sportspress-announcer' ),
			'away'        => $away ? $away : __( 'Away', 'sportspress-announcer' ),
			'home_score'  => $home_score,
			'away_score'  => $away_score,
			'competition' => $competition,
			'league_id'   => $league_id,
			'home_color'  => (string) get_post_meta( $home_id, 'spa_brand_color', true ),
			'event_url'   => (string) get_permalink( $post_id ),
		);
	}
}
