<?php
/**
 * Listens for SportsPress event saves and triggers announcements.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Event_Handler {

	public function __construct() {
		// Must hook save_post (not save_post_sp_event) at priority > 1 so SportsPress
		// has already written sp_results meta before we read it.
		// save_post_sp_event fires before save_post entirely, so scores would be stale.
		add_action( 'save_post', [ $this, 'on_event_save' ], 20, 2 );
	}

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

		if ( ! get_option( SPA_Settings::OPTION_DISCORD_ENABLED, true ) ) {
			return;
		}

		$webhook_url = get_option( 'spa_discord_webhook_url', '' );
		if ( empty( $webhook_url ) ) {
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
		static $posted_this_request = [];
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
		$payload   = $formatter->format_embed( $event );

		$discord = new SPA_Webhook_Discord( $webhook_url );
		$result  = $discord->send( $payload );

		if ( is_wp_error( $result ) ) {
			error_log( '[SportsPress Announcer] ' . $result->get_error_message() );
			return;
		}

		update_post_meta( $post_id, '_spa_last_score_hash', $score_hash );
	}

	/**
	 * Pull teams, scores, and competition from SportsPress post meta.
	 *
	 * @return array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string}|false
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
		$leagues     = wp_get_post_terms( $post_id, 'sp_league', [ 'fields' => 'names' ] );
		$competition = ( ! is_wp_error( $leagues ) && ! empty( $leagues ) ) ? $leagues[0] : '';

		return [
			'home'        => $home ?: __( 'Home', 'sportspress-announcer' ),
			'away'        => $away ?: __( 'Away', 'sportspress-announcer' ),
			'home_score'  => $home_score,
			'away_score'  => $away_score,
			'competition' => $competition,
			'home_color'  => (string) get_post_meta( $home_id, 'spa_brand_color', true ),
		];
	}
}
