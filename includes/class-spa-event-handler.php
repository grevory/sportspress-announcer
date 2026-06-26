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
		$announced = false;

		// -- Discord
		$discord_enabled = get_option( SPA_Settings::OPTION_DISCORD_ENABLED, true );

		if ( $discord_enabled ) {
			$channel_map = (array) get_option( SPA_Settings::OPTION_DISCORD_CHANNEL_MAP, array() );
			$competition = $event['competition'];
			$discord_url = ( $competition && ! empty( $channel_map[ $competition ] ) )
				? $channel_map[ $competition ]
				: get_option( 'spa_discord_webhook_url', '' );

			if ( ! empty( $discord_url ) ) {
				$payload = $formatter->format_embed( $event );
				$discord = new SPA_Webhook_Discord( $discord_url );
				$result  = $discord->send( $payload );

				if ( is_wp_error( $result ) ) {
					/**
					 * Fires when a Discord result announcement fails.
					 *
					 * @param \WP_Error $result  Webhook error.
					 * @param int       $post_id Event post ID.
					 */
					do_action( 'spa_discord_webhook_error', $result, $post_id );
				} else {
					$announced = true;
				}
			}
		}

		// -- Slack (Pro)
		$slack_enabled = get_option( SPA_Settings::OPTION_SLACK_ENABLED, false );
		$slack_url     = get_option( SPA_Settings::OPTION_SLACK_WEBHOOK, '' );

		if ( $slack_enabled && ! empty( $slack_url ) ) {
			$payload = $formatter->format_slack( $event );
			$slack   = new SPA_Webhook_Slack( $slack_url );
			$result  = $slack->send( $payload );

			if ( is_wp_error( $result ) ) {
				/**
				 * Fires when a Slack result announcement fails.
				 *
				 * @param \WP_Error $result  Webhook error.
				 * @param int       $post_id Event post ID.
				 */
				do_action( 'spa_slack_webhook_error', $result, $post_id );
			} else {
				$announced = true;
			}
		}

		if ( $announced ) {
			update_post_meta( $post_id, '_spa_last_score_hash', $score_hash );
		}
	}

	/**
	 * Pull teams, scores, and competition from SportsPress post meta.
	 *
	 * @param int $post_id Event post ID.
	 *
	 * @return array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string, home_color: string, event_url: string}|false
	 */
	protected function extract_event_data( int $post_id ) {
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
