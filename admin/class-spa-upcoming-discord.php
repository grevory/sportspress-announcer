<?php
/**
 * Handles sending an upcoming games digest to Discord, via AJAX or cron.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Upcoming_Discord {

	public function __construct() {
		add_action( 'wp_ajax_spa_send_upcoming', [ $this, 'ajax_send_upcoming' ] );
	}

	public function ajax_send_upcoming(): void {
		check_ajax_referer( 'spa_send_upcoming_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$result = $this->send_digest();

		if ( $result === false ) {
			wp_send_json_error( __( 'No upcoming games found in the next 7 days.', 'sportspress-announcer' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	/**
	 * Sends the upcoming games digest to Discord.
	 * Returns false if there are no games, WP_Error on failure, true on success.
	 *
	 * @return bool|WP_Error
	 */
	public function send_digest() {
		$webhook_url = get_option( 'spa_discord_webhook_url', '' );
		if ( empty( $webhook_url ) ) {
			return new WP_Error( 'no_webhook', __( 'No Discord webhook URL configured.', 'sportspress-announcer' ) );
		}

		$notice = new SPA_Upcoming_Notice();
		$games  = $notice->get_upcoming_games();

		if ( empty( $games ) ) {
			return false;
		}

		$payload = [
			'embeds' => [
				[
					'title'       => __( 'Upcoming Games', 'sportspress-announcer' ),
					'description' => $this->build_description( $games ),
					'color'       => 0x5865F2,
				],
			],
		];

		$discord = new SPA_Webhook_Discord( $webhook_url );
		$result  = $discord->send( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * @param array<int, array{date: string, time: string, venue: string, label: string}> $games
	 */
	private function build_description( array $games ): string {
		$by_date = [];
		foreach ( $games as $g ) {
			$by_date[ $g['date'] ][] = $g;
		}
		ksort( $by_date );

		$lines = [];
		foreach ( $by_date as $date => $group ) {
			$lines[] = '**' . $date . '**';
			foreach ( $group as $g ) {
				$line = '• ' . $g['label'];
				if ( $g['time'] ) {
					$line .= ' — ' . $g['time'];
				}
				if ( $g['venue'] ) {
					$line .= ' @ ' . $g['venue'];
				}
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}
}
