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

	/** Slack section-block text character limit. */
	private const SLACK_BLOCK_LIMIT = 3000;

	/** Standings movement arrows, keyed by movement direction. */
	private const MOVEMENT_ARROWS = array(
		'up'   => '↑',
		'down' => '↓',
		'same' => '→',
		'new'  => '✦',
	);

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
		$period = sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s – %2$s', 'announcer-for-sportspress' ),
			esc_html( $this->data['period']['start'] ),
			esc_html( $this->data['period']['end'] )
		);

		$footer_text = sprintf(
			/* translators: %s: site name */
			__( 'Posted automatically by Announcer for SportsPress · %s', 'announcer-for-sportspress' ),
			get_bloginfo( 'name' )
		);

		return array(
			'embeds' => array(
				array(
					'title'     => sprintf(
						/* translators: 1: league name, 2: date range */
						__( 'Weekly Recap — %1$s (%2$s)', 'announcer-for-sportspress' ),
						$this->league_name(),
						$period
					),
					'color'     => 0x5865F2,
					'fields'    => $this->discord_fields(),
					'footer'    => array( 'text' => $footer_text ),
					'timestamp' => gmdate( 'c' ),
				),
			),
		);
	}

	/**
	 * Build the ordered list of Discord embed fields, omitting empty sections.
	 *
	 * @return array
	 */
	private function discord_fields(): array {
		$sections = array(
			__( '📋 Results', 'announcer-for-sportspress' ) => $this->format_results_discord(),
			__( '📊 Standings', 'announcer-for-sportspress' ) => $this->format_standings_discord(),
			__( '🏆 Leaders', 'announcer-for-sportspress' ) => $this->format_leaders_discord(),
			__( '📅 Upcoming', 'announcer-for-sportspress' ) => $this->format_upcoming_discord(),
		);

		$fields = array();
		foreach ( $sections as $name => $value ) {
			if ( '' !== $value ) {
				$fields[] = array(
					'name'   => $name,
					'value'  => $value,
					'inline' => false,
				);
			}
		}

		return $fields;
	}

	/**
	 * League display name, falling back to a generic label.
	 *
	 * @return string
	 */
	private function league_name(): string {
		$league_term = get_term( $this->data['league_id'], 'sp_league' );
		return ( $league_term && ! is_wp_error( $league_term ) )
			? esc_html( $league_term->name )
			: esc_html__( 'League', 'announcer-for-sportspress' );
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
		$period = esc_html( $this->data['period']['start'] ) . ' – ' . esc_html( $this->data['period']['end'] );

		$html  = '<div class="spa-digest-preview">';
		$html .= '<h2 class="spa-digest-title">';
		$html .= sprintf(
			/* translators: 1: league name, 2: date range */
			esc_html__( 'Weekly Recap — %1$s (%2$s)', 'announcer-for-sportspress' ),
			$this->league_name(),
			$period
		);
		$html .= '</h2>';

		$html .= $this->results_html();
		$html .= $this->standings_html();
		$html .= $this->leaders_html();
		$html .= $this->upcoming_html();

		$html .= '<p class="spa-digest-footer">' . sprintf(
			/* translators: %s: site name */
			esc_html__( 'Posted automatically by Announcer for SportsPress · %s', 'announcer-for-sportspress' ),
			esc_html( get_bloginfo( 'name' ) )
		) . '</p>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the Results section as HTML, or '' when empty.
	 *
	 * @return string
	 */
	private function results_html(): string {
		if ( empty( $this->data['results'] ) ) {
			return '';
		}

		$html  = '<div class="spa-digest-section">';
		$html .= '<h3>' . esc_html__( 'Results', 'announcer-for-sportspress' ) . '</h3>';
		$html .= '<ul class="spa-digest-results">';
		foreach ( $this->data['results'] as $r ) {
			$html .= '<li><strong>' . esc_html( $r['home'] ) . ' ' . esc_html( $r['home_score'] ) . ' – ' . esc_html( $r['away_score'] ) . ' ' . esc_html( $r['away'] ) . '</strong>';
			if ( ! empty( $r['competition'] ) ) {
				$html .= ' <span class="spa-digest-comp">(' . esc_html( $r['competition'] ) . ')</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * Render the Standings section as HTML, or '' when empty.
	 *
	 * @return string
	 */
	private function standings_html(): string {
		if ( empty( $this->data['standings'] ) ) {
			return '';
		}

		$html  = '<div class="spa-digest-section">';
		$html .= '<h3>' . esc_html__( 'Standings', 'announcer-for-sportspress' ) . '</h3>';
		$html .= '<ol class="spa-digest-standings">';
		foreach ( $this->data['standings'] as $s ) {
			$arrow = $this->movement_html( $s['movement'] );
			$html .= '<li>' . $arrow . ' <strong>' . esc_html( $s['name'] ) . '</strong> <span class="spa-digest-pts">' . esc_html( $s['points'] ) . 'pts</span></li>';
		}
		return $html . '</ol></div>';
	}

	/**
	 * Render the Leaders section as HTML, or '' when empty.
	 *
	 * @return string
	 */
	private function leaders_html(): string {
		if ( empty( $this->data['stat_leaders'] ) ) {
			return '';
		}

		$html  = '<div class="spa-digest-section">';
		$html .= '<h3>' . esc_html__( 'Leaders', 'announcer-for-sportspress' ) . '</h3>';
		foreach ( $this->data['stat_leaders'] as $stat ) {
			$html   .= '<p><strong>' . esc_html( $stat['label'] ) . ':</strong> ';
			$entries = array();
			foreach ( $stat['players'] as $i => $p ) {
				$entries[] = ( $i + 1 ) . '. ' . esc_html( $p['name'] ) . ' (' . esc_html( $p['team'] ) . ') ' . esc_html( $p['value'] );
			}
			$html .= implode( '&nbsp;&nbsp;', $entries ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Render the Upcoming section as HTML, or '' when empty.
	 *
	 * @return string
	 */
	private function upcoming_html(): string {
		if ( empty( $this->data['upcoming'] ) ) {
			return '';
		}

		$html  = '<div class="spa-digest-section">';
		$html .= '<h3>' . esc_html__( 'Upcoming', 'announcer-for-sportspress' ) . '</h3>';
		$html .= '<ul class="spa-digest-upcoming">';
		foreach ( $this->data['upcoming'] as $g ) {
			$html .= '<li>' . esc_html( $g['label'] ?? '' );
			if ( ! empty( $g['date'] ) ) {
				$html .= ' — ' . esc_html( $g['date'] );
			}
			$html .= '</li>';
		}
		return $html . '</ul></div>';
	}

	// -------------------------------------------------------------------------
	// Slack (Block Kit)
	// -------------------------------------------------------------------------

	/**
	 * Build a Slack Block Kit payload for the digest.
	 *
	 * Mirrors the Discord embed: a header plus one mrkdwn section per non-empty
	 * area (Results, Standings, Leaders, Upcoming).
	 *
	 * @return array Ready to JSON-encode and POST to a Slack Incoming Webhook.
	 */
	public function to_slack_blocks(): array {
		$period = sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s – %2$s', 'announcer-for-sportspress' ),
			$this->data['period']['start'],
			$this->data['period']['end']
		);

		$title = sprintf(
			/* translators: 1: league name, 2: date range */
			__( 'Weekly Recap — %1$s (%2$s)', 'announcer-for-sportspress' ),
			$this->league_name(),
			$period
		);

		$blocks = array(
			array(
				'type' => 'header',
				'text' => array(
					'type'  => 'plain_text',
					'text'  => $title,
					'emoji' => true,
				),
			),
		);

		$sections = array(
			__( '📋 Results', 'announcer-for-sportspress' ) => $this->results_lines( '*' ),
			__( '📊 Standings', 'announcer-for-sportspress' ) => $this->standings_lines( '*' ),
			__( '🏆 Leaders', 'announcer-for-sportspress' ) => $this->leaders_lines( '*' ),
			__( '📅 Upcoming', 'announcer-for-sportspress' ) => $this->upcoming_lines(),
		);

		foreach ( $sections as $heading => $lines ) {
			if ( empty( $lines ) ) {
				continue;
			}
			$prefix   = '*' . $heading . '*' . "\n";
			$body     = $this->truncate_lines( $lines, home_url(), self::SLACK_BLOCK_LIMIT - mb_strlen( $prefix ) );
			$blocks[] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => $prefix . $body,
				),
			);
		}

		return array(
			'text'   => $title,
			'blocks' => $blocks,
		);
	}

	/**
	 * Render the digest as plain text, one titled block per non-empty section.
	 *
	 * Used for the activity log so the exact content that went out is
	 * auditable without platform markup.
	 *
	 * @return string
	 */
	public function to_text(): string {
		$sections = array(
			__( 'Results', 'announcer-for-sportspress' )   => $this->results_lines( '' ),
			__( 'Standings', 'announcer-for-sportspress' ) => $this->standings_lines( '' ),
			__( 'Leaders', 'announcer-for-sportspress' )   => $this->leaders_lines( '' ),
			__( 'Upcoming', 'announcer-for-sportspress' )  => $this->upcoming_lines(),
		);

		$parts = array();
		foreach ( $sections as $heading => $lines ) {
			if ( ! empty( $lines ) ) {
				$parts[] = $heading . "\n" . implode( "\n", $lines );
			}
		}

		return implode( "\n\n", $parts );
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
		$lines = $this->results_lines( '**' );
		return $lines ? $this->truncate_lines( $lines, home_url(), self::FIELD_LIMIT ) : '';
	}

	/**
	 * Render the Standings field for the Discord embed.
	 *
	 * @return string Empty string when there are no standings.
	 */
	private function format_standings_discord(): string {
		$lines = $this->standings_lines( '**' );
		return $lines ? $this->truncate_lines( $lines, home_url(), self::FIELD_LIMIT ) : '';
	}

	/**
	 * Render the Leaders field for the Discord embed.
	 *
	 * @return string Empty string when there are no stat leaders.
	 */
	private function format_leaders_discord(): string {
		$lines = $this->leaders_lines( '**' );
		return $lines ? $this->truncate_lines( $lines, home_url(), self::FIELD_LIMIT ) : '';
	}

	/**
	 * Render the Upcoming field for the Discord embed.
	 *
	 * @return string Empty string when there are no upcoming games.
	 */
	private function format_upcoming_discord(): string {
		$lines = $this->upcoming_lines();
		return $lines ? $this->truncate_lines( $lines, home_url(), self::FIELD_LIMIT ) : '';
	}

	// -------------------------------------------------------------------------
	// Shared line builders (platform-agnostic; $em is the bold marker)
	// -------------------------------------------------------------------------

	/**
	 * Build the Results lines. Discord passes '**', Slack passes '*'.
	 *
	 * @param string $em Bold emphasis marker for the target platform.
	 * @return string[] Empty when there are no results.
	 */
	private function results_lines( string $em ): array {
		$lines = array();
		foreach ( $this->data['results'] as $r ) {
			$lines[] = sprintf(
				'%1$s%2$s %3$s – %4$s %5$s%1$s',
				$em,
				$r['home'],
				$r['home_score'],
				$r['away_score'],
				$r['away']
			);
		}
		return $lines;
	}

	/**
	 * Build the Standings lines with movement arrows.
	 *
	 * @param string $em Bold emphasis marker for the target platform.
	 * @return string[] Empty when there are no standings.
	 */
	private function standings_lines( string $em ): array {
		$lines = array();
		foreach ( $this->data['standings'] as $s ) {
			$arrow   = self::MOVEMENT_ARROWS[ $s['movement'] ] ?? '→';
			$lines[] = sprintf( '%d. %s %s%s%s (%spts)', $s['rank'], $arrow, $em, $s['name'], $em, $s['points'] );
		}
		return $lines;
	}

	/**
	 * Build the Leaders lines.
	 *
	 * @param string $em Bold emphasis marker for the target platform.
	 * @return string[] Empty when there are no stat leaders.
	 */
	private function leaders_lines( string $em ): array {
		$lines = array();
		foreach ( $this->data['stat_leaders'] as $stat ) {
			$entries = array();
			foreach ( $stat['players'] as $i => $p ) {
				$entries[] = ( $i + 1 ) . '. ' . $p['name'] . ' (' . $p['team'] . ') ' . $p['value'];
			}
			$lines[] = $em . $stat['label'] . ':' . $em . ' ' . implode( '  ', $entries );
		}
		return $lines;
	}

	/**
	 * Build the Upcoming lines.
	 *
	 * @return string[] Empty when there are no upcoming games.
	 */
	private function upcoming_lines(): array {
		$lines = array();
		foreach ( $this->data['upcoming'] as $g ) {
			$label   = $g['label'] ?? '';
			$date    = $g['date'] ?? '';
			$lines[] = '• ' . $label . ( $date ? ' — ' . $date : '' );
		}
		return $lines;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Join lines; if over the limit, truncate with an "…and N more" link.
	 *
	 * @param string[] $lines    Pre-formatted lines.
	 * @param string   $more_url URL appended to the truncation notice.
	 * @param int      $limit    Maximum character length for the joined output.
	 * @return string
	 */
	private function truncate_lines( array $lines, string $more_url, int $limit ): string {
		$output = implode( "\n", $lines );

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
			'up'   => '<span class="spa-arrow-up" title="' . esc_attr__( 'Up', 'announcer-for-sportspress' ) . '">↑</span>',
			'down' => '<span class="spa-arrow-down" title="' . esc_attr__( 'Down', 'announcer-for-sportspress' ) . '">↓</span>',
			'same' => '<span class="spa-arrow-same" title="' . esc_attr__( 'No change', 'announcer-for-sportspress' ) . '">→</span>',
			'new'  => '<span class="spa-arrow-new" title="' . esc_attr__( 'New entry', 'announcer-for-sportspress' ) . '">✦</span>',
		);
		return $map[ $movement ] ?? '';
	}
}
