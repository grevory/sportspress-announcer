<?php
/**
 * Renders DigestData into channel-specific payloads.
 *
 * FREE — the formatter ships in the base plugin and is used for both the
 * wp-admin preview (free) and scheduled posting (Pro).
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a DigestData array into Discord-embed and HTML representations.
 */
class SPA_Digest_Formatter {

	/**
	 * DigestData produced by SPA_Digest_Builder.
	 *
	 * @var array
	 */
	private array $data;

	/** Discord embed field character limit. */
	private const FIELD_LIMIT = 1024;

	/** Discord total embed character limit. */
	private const EMBED_LIMIT = 6000;

	/**
	 * Constructor.
	 *
	 * @param array $digest_data DigestData from SPA_Digest_Builder::build().
	 */
	public function __construct( array $digest_data ) {
		$this->data = $digest_data;
	}

	// -------------------------------------------------------------------------
	// Discord
	// -------------------------------------------------------------------------

	/**
	 * Build a Discord embed payload for the digest.
	 *
	 * @return array Ready to JSON-encode and POST to a Discord webhook.
	 */
	public function to_discord_embed(): array {
		$league_term = get_term( $this->data['league_id'], 'sp_league' );
		$league_name = ( $league_term && ! is_wp_error( $league_term ) )
			? esc_html( $league_term->name )
			: __( 'League', 'sportspress-announcer' );

		$period = sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s – %2$s', 'sportspress-announcer' ),
			esc_html( $this->data['period']['start'] ),
			esc_html( $this->data['period']['end'] )
		);

		$fields = array();

		$results_text = $this->format_results_discord();
		if ( '' !== $results_text ) {
			$fields[] = array(
				'name'   => __( '📋 Results', 'sportspress-announcer' ),
				'value'  => $results_text,
				'inline' => false,
			);
		}

		$standings_text = $this->format_standings_discord();
		if ( '' !== $standings_text ) {
			$fields[] = array(
				'name'   => __( '📊 Standings', 'sportspress-announcer' ),
				'value'  => $standings_text,
				'inline' => false,
			);
		}

		$leaders_text = $this->format_leaders_discord();
		if ( '' !== $leaders_text ) {
			$fields[] = array(
				'name'   => __( '🏆 Leaders', 'sportspress-announcer' ),
				'value'  => $leaders_text,
				'inline' => false,
			);
		}

		if ( ! empty( $this->data['upcoming'] ) ) {
			$upcoming_text = $this->format_upcoming_discord();
			if ( '' !== $upcoming_text ) {
				$fields[] = array(
					'name'   => __( '📅 Upcoming', 'sportspress-announcer' ),
					'value'  => $upcoming_text,
					'inline' => false,
				);
			}
		}

		$footer_text = sprintf(
			/* translators: %s: site name */
			__( 'Posted automatically by SportsPress Announcer Pro · %s', 'sportspress-announcer' ),
			get_bloginfo( 'name' )
		);

		return array(
			'embeds' => array(
				array(
					'title'     => sprintf(
						/* translators: 1: league name, 2: date range */
						__( 'Weekly Digest — %1$s (%2$s)', 'sportspress-announcer' ),
						$league_name,
						$period
					),
					'color'     => 0x5865F2,
					'fields'    => $fields,
					'footer'    => array( 'text' => $footer_text ),
					'timestamp' => gmdate( 'c' ),
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// HTML (wp-admin preview + publish-as-post)
	// -------------------------------------------------------------------------

	/**
	 * Render the digest as HTML for the wp-admin preview pane.
	 *
	 * @return string Safe HTML — all dynamic values are escaped.
	 */
	public function to_html(): string {
		$league_term = get_term( $this->data['league_id'], 'sp_league' );
		$league_name = ( $league_term && ! is_wp_error( $league_term ) )
			? esc_html( $league_term->name )
			: esc_html__( 'League', 'sportspress-announcer' );

		$period = esc_html( $this->data['period']['start'] ) . ' – ' . esc_html( $this->data['period']['end'] );

		$html  = '<div class="spa-digest-preview">';
		$html .= '<h2 class="spa-digest-title">';
		$html .= sprintf(
			/* translators: 1: league name, 2: date range */
			esc_html__( 'Weekly Digest — %1$s (%2$s)', 'sportspress-announcer' ),
			$league_name,
			$period
		);
		$html .= '</h2>';

		if ( ! empty( $this->data['results'] ) ) {
			$html .= '<div class="spa-digest-section">';
			$html .= '<h3>' . esc_html__( 'Results', 'sportspress-announcer' ) . '</h3>';
			$html .= '<ul class="spa-digest-results">';
			foreach ( $this->data['results'] as $r ) {
				$html .= '<li><strong>' . esc_html( $r['home'] ) . ' ' . esc_html( $r['home_score'] ) . ' – ' . esc_html( $r['away_score'] ) . ' ' . esc_html( $r['away'] ) . '</strong>';
				if ( ! empty( $r['competition'] ) ) {
					$html .= ' <span class="spa-digest-comp">(' . esc_html( $r['competition'] ) . ')</span>';
				}
				$html .= '</li>';
			}
			$html .= '</ul></div>';
		}

		if ( ! empty( $this->data['standings'] ) ) {
			$html .= '<div class="spa-digest-section">';
			$html .= '<h3>' . esc_html__( 'Standings', 'sportspress-announcer' ) . '</h3>';
			$html .= '<ol class="spa-digest-standings">';
			foreach ( $this->data['standings'] as $s ) {
				$arrow = $this->movement_html( $s['movement'] );
				$html .= '<li>' . $arrow . ' <strong>' . esc_html( $s['name'] ) . '</strong> <span class="spa-digest-pts">' . esc_html( $s['points'] ) . 'pts</span></li>';
			}
			$html .= '</ol></div>';
		}

		if ( ! empty( $this->data['stat_leaders'] ) ) {
			$html .= '<div class="spa-digest-section">';
			$html .= '<h3>' . esc_html__( 'Leaders', 'sportspress-announcer' ) . '</h3>';
			foreach ( $this->data['stat_leaders'] as $stat ) {
				$html   .= '<p><strong>' . esc_html( $stat['label'] ) . ':</strong> ';
				$entries = array();
				foreach ( $stat['players'] as $i => $p ) {
					$entries[] = ( $i + 1 ) . '. ' . esc_html( $p['name'] ) . ' (' . esc_html( $p['team'] ) . ') ' . esc_html( $p['value'] );
				}
				$html .= implode( '&nbsp;&nbsp;', $entries ) . '</p>';
			}
			$html .= '</div>';
		}

		if ( ! empty( $this->data['upcoming'] ) ) {
			$html .= '<div class="spa-digest-section">';
			$html .= '<h3>' . esc_html__( 'Upcoming', 'sportspress-announcer' ) . '</h3>';
			$html .= '<ul class="spa-digest-upcoming">';
			foreach ( $this->data['upcoming'] as $g ) {
				$html .= '<li>' . esc_html( $g['label'] ?? '' );
				if ( ! empty( $g['date'] ) ) {
					$html .= ' — ' . esc_html( $g['date'] );
				}
				$html .= '</li>';
			}
			$html .= '</ul></div>';
		}

		$html .= '<p class="spa-digest-footer">' . sprintf(
			/* translators: %s: site name */
			esc_html__( 'Posted automatically by SportsPress Announcer Pro · %s', 'sportspress-announcer' ),
			esc_html( get_bloginfo( 'name' ) )
		) . '</p>';

		$html .= '</div>';

		return $html;
	}

	// -------------------------------------------------------------------------
	// Discord field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the Results field for the Discord embed.
	 *
	 * @return string Empty string when there are no results.
	 */
	private function format_results_discord(): string {
		if ( empty( $this->data['results'] ) ) {
			return '';
		}

		$lines    = array();
		$site_url = home_url();

		foreach ( $this->data['results'] as $r ) {
			$lines[] = sprintf(
				'**%s %s – %s %s**',
				$r['home'],
				$r['home_score'],
				$r['away_score'],
				$r['away']
			);
		}

		return $this->truncate_lines( $lines, $site_url );
	}

	/**
	 * Render the Standings field for the Discord embed.
	 *
	 * @return string Empty string when there are no standings.
	 */
	private function format_standings_discord(): string {
		if ( empty( $this->data['standings'] ) ) {
			return '';
		}

		$arrow_map = array(
			'up'   => '↑',
			'down' => '↓',
			'same' => '→',
			'new'  => '✦',
		);

		$lines = array();
		foreach ( $this->data['standings'] as $s ) {
			$arrow   = $arrow_map[ $s['movement'] ] ?? '→';
			$lines[] = sprintf( '%d. %s **%s** (%spts)', $s['rank'], $arrow, $s['name'], $s['points'] );
		}

		return $this->truncate_lines( $lines, home_url() );
	}

	/**
	 * Render the Leaders field for the Discord embed.
	 *
	 * @return string Empty string when there are no stat leaders.
	 */
	private function format_leaders_discord(): string {
		if ( empty( $this->data['stat_leaders'] ) ) {
			return '';
		}

		$lines = array();
		foreach ( $this->data['stat_leaders'] as $stat ) {
			$entries = array();
			foreach ( $stat['players'] as $i => $p ) {
				$entries[] = ( $i + 1 ) . '. ' . $p['name'] . ' (' . $p['team'] . ') ' . $p['value'];
			}
			$lines[] = '**' . $stat['label'] . ':** ' . implode( '  ', $entries );
		}

		return $this->truncate_lines( $lines, home_url() );
	}

	/**
	 * Render the Upcoming field for the Discord embed.
	 *
	 * @return string Empty string when there are no upcoming games.
	 */
	private function format_upcoming_discord(): string {
		if ( empty( $this->data['upcoming'] ) ) {
			return '';
		}

		$lines = array();
		foreach ( $this->data['upcoming'] as $g ) {
			$label   = $g['label'] ?? '';
			$date    = $g['date'] ?? '';
			$lines[] = '• ' . $label . ( $date ? ' — ' . $date : '' );
		}

		return $this->truncate_lines( $lines, home_url() );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Join lines; if over FIELD_LIMIT, truncate with "…and N more" link.
	 *
	 * @param string[] $lines    Pre-formatted lines.
	 * @param string   $more_url URL appended to the truncation notice.
	 * @return string
	 */
	private function truncate_lines( array $lines, string $more_url ): string {
		$output = implode( "\n", $lines );
		$limit  = self::FIELD_LIMIT;

		if ( mb_strlen( $output ) <= $limit ) {
			return $output;
		}

		$kept  = array();
		$total = count( $lines );

		foreach ( $lines as $line ) {
			$candidate = implode( "\n", array_merge( $kept, array( $line ) ) );
			$suffix    = sprintf( "\n…and %d more — %s", $total - count( $kept ) - 1, $more_url );

			if ( mb_strlen( $candidate . $suffix ) > $limit ) {
				break;
			}

			$kept[] = $line;
		}

		$remaining = $total - count( $kept );
		return implode( "\n", $kept ) . sprintf( "\n…and %d more — %s", $remaining, $more_url );
	}

	/**
	 * Return an HTML arrow span for standings movement.
	 *
	 * @param string $movement One of up|down|same|new.
	 * @return string
	 */
	private function movement_html( string $movement ): string {
		$map = array(
			'up'   => '<span class="spa-arrow-up" title="' . esc_attr__( 'Up', 'sportspress-announcer' ) . '">↑</span>',
			'down' => '<span class="spa-arrow-down" title="' . esc_attr__( 'Down', 'sportspress-announcer' ) . '">↓</span>',
			'same' => '<span class="spa-arrow-same" title="' . esc_attr__( 'No change', 'sportspress-announcer' ) . '">→</span>',
			'new'  => '<span class="spa-arrow-new" title="' . esc_attr__( 'New entry', 'sportspress-announcer' ) . '">✦</span>',
		);
		return $map[ $movement ] ?? '';
	}
}
