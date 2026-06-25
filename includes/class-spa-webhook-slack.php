<?php
/**
 * Sends messages to a Slack Incoming Webhook.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends JSON payloads to a configured Slack Incoming Webhook.
 */
class SPA_Webhook_Slack {

	/**
	 * Slack Incoming Webhook endpoint.
	 *
	 * @var string
	 */
	private string $webhook_url;

	/**
	 * Create a Slack webhook client.
	 *
	 * @param string $webhook_url Slack Incoming Webhook endpoint.
	 */
	public function __construct( string $webhook_url ) {
		$this->webhook_url = $webhook_url;
	}

	/**
	 * POST a payload array to the configured webhook.
	 *
	 * @param array<string, mixed> $payload Slack Block Kit payload.
	 *
	 * @return true|\WP_Error
	 */
	public function send( array $payload ) {
		if ( empty( $this->webhook_url ) ) {
			return new \WP_Error( 'spa_no_webhook', __( 'No Slack webhook URL configured.', 'sportspress-announcer' ) );
		}

		$response = wp_remote_post(
			$this->webhook_url,
			array(
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'timeout'     => 10,
				'data_format' => 'body',
			)
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
					__( 'Slack webhook returned HTTP %d.', 'sportspress-announcer' ),
					$code
				)
			);
		}

		return true;
	}
}
