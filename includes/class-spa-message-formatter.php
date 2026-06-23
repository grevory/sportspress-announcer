<?php
/**
 * Builds announcement messages from SportsPress event data.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Message_Formatter {

	private const COLOR_WIN  = 0x57F287;
	private const COLOR_LOSS = 0xED4245;
	private const COLOR_DRAW = 0xFEE75C;
	private const COLOR_NONE = 0x99AAB5;

	/**
	 * @param array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string, home_color: string} $event
	 */
	public function format_embed( array $event ): array {
		$home        = wp_specialchars_decode( (string) $event['home'], ENT_QUOTES );
		$away        = wp_specialchars_decode( (string) $event['away'], ENT_QUOTES );
		$home_score  = $event['home_score'];
		$away_score  = $event['away_score'];
		$competition = wp_specialchars_decode( (string) $event['competition'], ENT_QUOTES );

		$description = sprintf( '**%s**  %s – %s  %s', $home, $home_score, $away_score, $away );

		$color = $this->resolve_color( $event['home_color'] ?? '', $home_score, $away_score );

		$footer_text = 'Full Time';
		if ( $competition ) {
			$footer_text = $competition . ' · ' . $footer_text;
		}

		return [
			'embeds' => [
				[
					'title'       => __( 'Match Result', 'sportspress-announcer' ),
					'description' => $description,
					'color'       => $color,
					'footer'      => [ 'text' => $footer_text ],
				],
			],
		];
	}

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
	 * @param array{home: string, away: string, home_score: int|string, away_score: int|string, competition: string} $event
	 */
	public function format_result( array $event ): string {
		$home        = wp_specialchars_decode( (string) $event['home'], ENT_QUOTES );
		$away        = wp_specialchars_decode( (string) $event['away'], ENT_QUOTES );
		$home_score  = (int) $event['home_score'];
		$away_score  = (int) $event['away_score'];
		$competition = wp_specialchars_decode( (string) $event['competition'], ENT_QUOTES );

		$result_line = sprintf(
			'**Final** | %s %d – %d %s',
			$home,
			$home_score,
			$away_score,
			$away
		);

		if ( $competition ) {
			$result_line .= sprintf( ' *(• %s)*', $competition );
		}

		return $result_line;
	}
}
