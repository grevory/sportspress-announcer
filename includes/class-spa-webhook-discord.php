<?php
/**
 * Sends messages to a Discord webhook.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Webhook_Discord {

	private string $webhook_url;

	public function __construct( string $webhook_url ) {
		$this->webhook_url = $webhook_url;
	}

	/**
	 * POST a plain-text message to the configured webhook.
	 *
	 * @return true|\WP_Error
	 */
	public function send( string $message ) {
		if ( empty( $this->webhook_url ) ) {
			return new \WP_Error( 'spa_no_webhook', __( 'No Discord webhook URL configured.', 'sportspress-announcer' ) );
		}

		$response = wp_remote_post(
			$this->webhook_url,
			[
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => wp_json_encode( [ 'content' => $message ] ),
				'timeout'     => 10,
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'spa_webhook_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Discord webhook returned HTTP %d.', 'sportspress-announcer' ),
					$code
				)
			);
		}

		return true;
	}
}
