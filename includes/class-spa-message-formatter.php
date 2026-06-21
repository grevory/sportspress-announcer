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
