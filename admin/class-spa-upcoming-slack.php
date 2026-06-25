<?php
/**
 * Handles sending an upcoming games digest to Slack, via AJAX or cron.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends upcoming SportsPress games to Slack.
 */
class SPA_Upcoming_Slack {

	/**
	 * Register the AJAX callback.
	 */
	public function __construct() {
		add_action( 'wp_ajax_spa_send_upcoming_slack', array( $this, 'ajax_send_upcoming' ) );
	}

	/**
	 * Handle a request to send the upcoming-games digest to Slack.
	 *
	 * @return void
	 */
	public function ajax_send_upcoming(): void {
		check_ajax_referer( 'spa_send_upcoming_slack_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-announcer' ) );
		}

		$result = $this->send_digest();

		if ( false === $result ) {
			wp_send_json_error( __( 'No upcoming games found in the next 7 days.', 'sportspress-announcer' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	/**
	 * Sends the upcoming games digest to Slack.
	 * Returns false if there are no games, WP_Error on failure, true on success.
	 *
	 * @return bool|\WP_Error
	 */
	public function send_digest() {
		$webhook_url = get_option( SPA_Settings::OPTION_SLACK_WEBHOOK, '' );
		if ( empty( $webhook_url ) ) {
			return new \WP_Error( 'no_webhook', __( 'No Slack webhook URL configured.', 'sportspress-announcer' ) );
		}

		$notice = new SPA_Upcoming_Notice();
		$games  = $notice->get_upcoming_games();

		if ( empty( $games ) ) {
			return false;
		}

		$mrkdwn = $this->build_mrkdwn( $games );

		$payload = array(
			'text'   => __( 'Upcoming Games', 'sportspress-announcer' ),
			'blocks' => array(
				array(
					'type' => 'header',
					'text' => array(
						'type'  => 'plain_text',
						'text'  => __( 'Upcoming Games', 'sportspress-announcer' ),
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

		$slack  = new SPA_Webhook_Slack( $webhook_url );
		$result = $slack->send( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Build the Slack mrkdwn message body grouped by event date.
	 *
	 * @param array<int, array{date: string, time: string, venue: string, label: string}> $games Upcoming games.
	 *
	 * @return string
	 */
	private function build_mrkdwn( array $games ): string {
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
			$lines[] = '*' . $date . '*';
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
}
