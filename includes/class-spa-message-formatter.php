<?php
/**
 * Builds announcement messages from SportsPress event data.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats SportsPress event data for announcement channels.
 */
class SPA_Message_Formatter {

	private const COLOR_WIN  = 0x57F287;
	private const COLOR_LOSS = 0xED4245;
	private const COLOR_DRAW = 0xFEE75C;
	private const COLOR_NONE = 0x99AAB5;

	/**
	 * Render the user-configured result template as plain text.
	 *
	 * All platform-specific formatters call this first, then wrap
	 * team names in their own markup.
	 *
	 * @param array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string} $event Event data.
	 * @param string                                                                                                 $home Decoded home team name.
	 * @param string                                                                                                 $away Decoded away team name.
	 *
	 * @return string
	 */
	private function format_text( array $event, string $home, string $away ): string {
		$template = get_option( SPA_Settings::OPTION_RESULT_TEMPLATE, SPA_Settings::DEFAULT_RESULT_TEMPLATE );

		return str_replace(
			array( '{home}', '{away}', '{home_score}', '{away_score}', '{competition}' ),
			array( $home, $away, (string) $event['home_score'], (string) $event['away_score'], (string) $event['competition'] ),
			$template
		);
	}

	/**
	 * Build a Discord embed payload for a match result.
	 *
	 * @param array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string, home_color: string} $event Event data.
	 *
	 * @return array<string, mixed>
	 */
	public function format_embed( array $event ): array {
		$home        = wp_specialchars_decode( (string) $event['home'], ENT_QUOTES );
		$away        = wp_specialchars_decode( (string) $event['away'], ENT_QUOTES );
		$home_score  = $event['home_score'];
		$away_score  = $event['away_score'];
		$competition = wp_specialchars_decode( (string) $event['competition'], ENT_QUOTES );

		$plain = $this->format_text( $event, $home, $away );

		// Apply Discord bold (**name**) to team name occurrences.
		$description = str_replace(
			array( $home, $away ),
			array( '**' . $home . '**', '**' . $away . '**' ),
			$plain
		);

		$color = $this->resolve_color( $event['home_color'] ?? '', $home_score, $away_score );

		$footer_text = 'Full Time';
		if ( $competition ) {
			$footer_text = $competition . ' · ' . $footer_text;
		}

		return array(
			'embeds' => array(
				array(
					'title'       => __( 'Match Result', 'sportspress-announcer' ),
					'description' => $description,
					'color'       => $color,
					'footer'      => array( 'text' => $footer_text ),
				),
			),
		);
	}

	/**
	 * Resolve the embed color from the team brand or match outcome.
	 *
	 * @param string     $brand_hex  Home team brand color.
	 * @param int|string $home_score Home team score.
	 * @param int|string $away_score Away team score.
	 *
	 * @return int
	 */
	private function resolve_color( string $brand_hex, $home_score, $away_score ): int {
		if ( $brand_hex && preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_hex ) ) {
			return hexdec( ltrim( $brand_hex, '#' ) );
		}
		if ( ! is_numeric( $home_score ) || ! is_numeric( $away_score ) ) {
			return self::COLOR_NONE;
		}
		if ( (int) $home_score > (int) $away_score ) {
			return self::COLOR_WIN;
		}
		if ( (int) $home_score < (int) $away_score ) {
			return self::COLOR_LOSS;
		}
		return self::COLOR_DRAW;
	}

	/**
	 * Build a plain-text match result using the configured template.
	 *
	 * @param array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string} $event Event data.
	 *
	 * @return string
	 */
	public function format_result( array $event ): string {
		$home = wp_specialchars_decode( (string) $event['home'], ENT_QUOTES );
		$away = wp_specialchars_decode( (string) $event['away'], ENT_QUOTES );

		return $this->format_text( $event, $home, $away );
	}

	/**
	 * Build a Slack Block Kit payload for a match result.
	 *
	 * @param array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string} $event Event data.
	 *
	 * @return array<string, mixed>
	 */
	public function format_slack( array $event ): array {
		$home        = wp_specialchars_decode( (string) $event['home'], ENT_QUOTES );
		$away        = wp_specialchars_decode( (string) $event['away'], ENT_QUOTES );
		$competition = wp_specialchars_decode( (string) $event['competition'], ENT_QUOTES );

		$plain = $this->format_text( $event, $home, $away );

		// Apply Slack mrkdwn bold (*name*) to team name occurrences.
		$mrkdwn = str_replace(
			array( $home, $away ),
			array( '*' . $home . '*', '*' . $away . '*' ),
			$plain
		);

		$footer = 'Full Time';
		if ( $competition ) {
			$footer = $competition . ' · ' . $footer;
		}

		return array(
			'text'   => $plain,
			'blocks' => array(
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => $mrkdwn . "\n_" . $footer . '_',
					),
				),
			),
		);
	}
}
