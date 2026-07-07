<?php
/**
 * Builds the DigestData array from SportsPress data.
 *
 * FREE — ships in the base plugin and powers both the wp-admin preview (free)
 * and the scheduled posting (Pro).
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries SportsPress and assembles a DigestData array for a single league.
 */
class SPA_Digest_Builder {

	/**
	 * League term ID.
	 *
	 * @var int
	 */
	private int $league_id;

	/**
	 * Days of history to include.
	 *
	 * @var int
	 */
	private int $date_range_days;

	/**
	 * Whether to include the results section.
	 *
	 * @var bool
	 */
	private bool $include_results;

	/**
	 * Whether to include the standings section.
	 *
	 * @var bool
	 */
	private bool $include_standings;

	/**
	 * Whether to include the stat-leaders section.
	 *
	 * @var bool
	 */
	private bool $include_leaders;

	/**
	 * Whether to include the upcoming-games section.
	 *
	 * @var bool
	 */
	private bool $include_upcoming;

	/**
	 * SP stat slugs to feature as leaders.
	 *
	 * @var string[]
	 */
	private array $stat_keys;

	/**
	 * Standings snapshot computed by the last build(), awaiting commit.
	 *
	 * @var array[]|null
	 */
	private ?array $pending_snapshot = null;

	/**
	 * Constructor.
	 *
	 * @param int   $league_id League term ID.
	 * @param array $options {
	 *   Optional. Build options.
	 *
	 *   @type int      $date_range_days   Default 7.
	 *   @type bool     $include_results   Default true.
	 *   @type bool     $include_standings Default true.
	 *   @type bool     $include_leaders   Default true.
	 *   @type bool     $include_upcoming  Default false.
	 *   @type string[] $stat_keys         SP stat slugs, up to 3.
	 * }
	 */
	public function __construct( int $league_id, array $options = array() ) {
		$this->league_id         = $league_id;
		$this->date_range_days   = isset( $options['date_range_days'] ) ? max( 1, intval( $options['date_range_days'] ) ) : 7;
		$this->include_results   = $options['include_results'] ?? true;
		$this->include_standings = $options['include_standings'] ?? true;
		$this->include_leaders   = $options['include_leaders'] ?? true;
		$this->include_upcoming  = ! empty( $options['include_upcoming'] );
		$this->stat_keys         = array_slice( (array) ( $options['stat_keys'] ?? array() ), 0, 3 );
	}

	/**
	 * Build the options array for a builder from the saved weekly-digest
	 * settings. Shared by the scheduler and the wp-admin preview so the two
	 * paths never diverge.
	 *
	 * @return array Options accepted by the constructor.
	 */
	public static function options_from_settings(): array {
		return array(
			'include_results'   => (bool) get_option( 'spa_weekly_digest_include_results', true ),
			'include_standings' => (bool) get_option( 'spa_weekly_digest_include_standings', true ),
			'include_leaders'   => (bool) get_option( 'spa_weekly_digest_include_leaders', true ),
			'include_upcoming'  => (bool) get_option( 'spa_weekly_digest_include_upcoming' ),
			'stat_keys'         => (array) get_option( 'spa_weekly_digest_stat_keys', array() ),
		);
	}

	/**
	 * Assemble and return DigestData.
	 *
	 * @return array{
	 *   league_id: int,
	 *   period: array{start: string, end: string},
	 *   results: array[],
	 *   standings: array[],
	 *   stat_leaders: array[],
	 *   upcoming: array[],
	 *   is_empty: bool
	 * }
	 */
	public function build(): array {
		$tz    = wp_timezone();
		$now   = new DateTimeImmutable( 'now', $tz );
		$start = $now->modify( "-{$this->date_range_days} days" )->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		// Always query the actual results — the emptiness check needs to know
		// whether any games happened, regardless of whether the user chose to
		// display the results section. Only expose them when included.
		$actual_results = $this->get_results( $start, $end );

		$results      = $this->include_results ? $actual_results : array();
		$standings    = $this->include_standings ? $this->get_standings_with_movement() : array();
		$stat_leaders = $this->include_leaders ? $this->get_stat_leaders() : array();
		$upcoming     = $this->include_upcoming ? $this->get_upcoming() : array();

		return array(
			'league_id'    => $this->league_id,
			'period'       => array(
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			),
			'results'      => $results,
			'standings'    => $standings,
			'stat_leaders' => $stat_leaders,
			'upcoming'     => $upcoming,
			// Emptiness reflects whether anything actually happened this week —
			// based on the real results, not the display toggle. Standings and
			// leaders are intentionally excluded: they only change when games
			// are played, so a game-less week would re-post an unchanged table.
			// A week with no games and no upcoming fixtures is skipped.
			'is_empty'     => empty( $actual_results ) && empty( $upcoming ),
		);
	}

	// -------------------------------------------------------------------------
	// Results
	// -------------------------------------------------------------------------

	/**
	 * Query final events in range and shape them into result rows.
	 *
	 * @param DateTimeImmutable $start Range start.
	 * @param DateTimeImmutable $end   Range end.
	 * @return array[]
	 */
	private function get_results( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'date_query'     => array(
					array(
						'after'     => $start->format( 'Y-m-d H:i:s' ),
						'before'    => $end->format( 'Y-m-d H:i:s' ),
						'inclusive' => true,
					),
				),
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_league',
						'field'    => 'term_id',
						'terms'    => $this->league_id,
					),
				),
				'no_found_rows'  => true,
			)
		);

		$score_column = get_option( 'spa_score_column', 'goals' );
		$results      = array();

		foreach ( $query->posts as $post ) {
			$team_ids = get_post_meta( $post->ID, 'sp_team', false );
			$raw      = get_post_meta( $post->ID, 'sp_results', true );

			if ( empty( $team_ids ) || empty( $raw ) || ! is_array( $raw ) ) {
				continue;
			}

			$home_id    = $team_ids[0] ?? 0;
			$away_id    = $team_ids[1] ?? 0;
			$home_score = $raw[ $home_id ][ $score_column ] ?? null;
			$away_score = $raw[ $away_id ][ $score_column ] ?? null;

			if ( null === $home_score || null === $away_score ) {
				continue;
			}

			$competition_terms = get_the_terms( $post->ID, 'sp_league' );
			$competition       = ( $competition_terms && ! is_wp_error( $competition_terms ) )
				? $competition_terms[0]->name
				: '';

			$results[] = array(
				'home'        => wp_specialchars_decode( get_the_title( $home_id ), ENT_QUOTES ),
				'away'        => wp_specialchars_decode( get_the_title( $away_id ), ENT_QUOTES ),
				'home_score'  => $home_score,
				'away_score'  => $away_score,
				'competition' => $competition,
				'event_url'   => get_permalink( $post->ID ),
				'date'        => get_the_date( 'Y-m-d', $post ),
			);
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Standings with movement
	// -------------------------------------------------------------------------

	/**
	 * Build the current standings and diff against the stored snapshot.
	 *
	 * @return array[]
	 */
	private function get_standings_with_movement(): array {
		if ( ! class_exists( 'SP_League_Table' ) ) {
			return array();
		}

		$table = new SP_League_Table( $this->league_id );
		$data  = $table->data();

		if ( empty( $data ) || ! is_array( $data ) ) {
			return array();
		}

		// SP_League_Table::data() (non-admin) returns an array keyed by team_id,
		// each row an associative array with 'name', 'pos', and configurable
		// stat columns (commonly 'p' = played, 'pts' = points). Index 0 holds
		// the column-label row — drop it. Rows are already sorted by position.
		unset( $data[0] );

		$snapshot_key  = "spa_digest_standings_snapshot_{$this->league_id}";
		$prev_snapshot = get_option( $snapshot_key, array() );

		$prev_ranks = array();
		foreach ( $prev_snapshot as $prev_row ) {
			if ( isset( $prev_row['name'], $prev_row['rank'] ) ) {
				$prev_ranks[ $prev_row['name'] ] = (int) $prev_row['rank'];
			}
		}

		$current  = array();
		$new_snap = array();
		$rank     = 1;

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = $row['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}

			$played = $row['p'] ?? ( $row['eventsplayed'] ?? 0 );
			$points = $row['pts'] ?? 0;

			if ( ! isset( $prev_ranks[ $name ] ) ) {
				$movement = 'new';
			} elseif ( $rank < $prev_ranks[ $name ] ) {
				$movement = 'up';
			} elseif ( $rank > $prev_ranks[ $name ] ) {
				$movement = 'down';
			} else {
				$movement = 'same';
			}

			$current[]  = compact( 'rank', 'name', 'played', 'points', 'movement' );
			$new_snap[] = array(
				'name' => $name,
				'rank' => $rank,
			);
			++$rank;
		}

		// Stash the computed snapshot but DO NOT persist it here — build() must
		// stay side-effect free so the free preview path can't corrupt the
		// movement baseline. The scheduler calls commit_standings_snapshot()
		// only after a digest is actually sent.
		$this->pending_snapshot = $new_snap;

		return $current;
	}

	/**
	 * Persist the standings snapshot computed during the last build().
	 *
	 * Call this only when a digest was actually sent, so movement arrows in the
	 * next digest diff against the last *sent* standings — never against a
	 * preview. No-op if build() has not been called or produced no standings.
	 *
	 * @return void
	 */
	public function commit_standings_snapshot(): void {
		if ( null === $this->pending_snapshot ) {
			return;
		}
		update_option( "spa_digest_standings_snapshot_{$this->league_id}", $this->pending_snapshot, false );
		$this->pending_snapshot = null;
	}

	// -------------------------------------------------------------------------
	// Stat leaders
	// -------------------------------------------------------------------------

	/**
	 * Compute the top-3 players for each configured stat.
	 *
	 * @return array[]
	 */
	private function get_stat_leaders(): array {
		if ( empty( $this->stat_keys ) ) {
			return array();
		}

		$players = get_posts(
			array(
				'post_type'      => 'sp_player',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_league',
						'field'    => 'term_id',
						'terms'    => $this->league_id,
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( empty( $players ) ) {
			return array();
		}

		$player_data = array();
		foreach ( $players as $player ) {
			$stats    = get_post_meta( $player->ID, 'sp_statistics', true );
			$team_ids = get_post_meta( $player->ID, 'sp_current_team', false );
			$team     = ! empty( $team_ids )
				? wp_specialchars_decode( get_the_title( $team_ids[0] ), ENT_QUOTES )
				: '';

			$player_data[] = array(
				'name'  => wp_specialchars_decode( get_the_title( $player->ID ), ENT_QUOTES ),
				'team'  => $team,
				'stats' => is_array( $stats ) ? $stats : array(),
			);
		}

		$leaders = array();

		foreach ( $this->stat_keys as $stat_key ) {
			$scored = array();
			foreach ( $player_data as $p ) {
				$value = $this->extract_stat_value( $p['stats'], $stat_key );
				if ( null !== $value ) {
					$scored[] = array(
						'name'  => $p['name'],
						'team'  => $p['team'],
						'value' => $value,
					);
				}
			}

			if ( empty( $scored ) ) {
				continue;
			}

			usort( $scored, static fn( $a, $b ) => $b['value'] <=> $a['value'] );

			$leaders[] = array(
				'stat'    => $stat_key,
				'label'   => ucwords( str_replace( '_', ' ', $stat_key ) ),
				'players' => array_slice( $scored, 0, 3 ),
			);
		}

		return $leaders;
	}

	/**
	 * Extract a named stat value from SP's nested statistics array.
	 *
	 * SP stores static player stats as: [ league_id => [ season_id => [ stat_key => value ] ] ]
	 * (see SP_Player_List). We scope to this digest's league (plus league 0 =
	 * "all leagues") and sum across every season so the leader reflects the
	 * player's cumulative total for the competition.
	 *
	 * @param array  $stats    Player's sp_statistics meta.
	 * @param string $stat_key Stat slug to total.
	 * @return int|float|null Null when the player has no recorded value.
	 */
	private function extract_stat_value( array $stats, string $stat_key ) {
		$league_buckets = array();
		if ( isset( $stats[ $this->league_id ] ) && is_array( $stats[ $this->league_id ] ) ) {
			$league_buckets[] = $stats[ $this->league_id ];
		}
		if ( isset( $stats[0] ) && is_array( $stats[0] ) ) {
			$league_buckets[] = $stats[0];
		}

		$total = null;
		foreach ( $league_buckets as $seasons ) {
			foreach ( $seasons as $season_stats ) {
				if ( is_array( $season_stats ) && isset( $season_stats[ $stat_key ] ) && is_numeric( $season_stats[ $stat_key ] ) ) {
					$total = ( $total ?? 0 ) + ( $season_stats[ $stat_key ] + 0 );
				}
			}
		}

		return $total;
	}

	// -------------------------------------------------------------------------
	// Upcoming
	// -------------------------------------------------------------------------

	/**
	 * Fetch upcoming games filtered to this league.
	 *
	 * @return array[]
	 */
	private function get_upcoming(): array {
		if ( ! class_exists( 'SPA_Upcoming_Notice' ) ) {
			return array();
		}

		$notice   = new SPA_Upcoming_Notice();
		$all      = $notice->get_upcoming_games();
		$filtered = array();

		foreach ( $all as $game ) {
			$leagues = get_the_terms( $game['id'], 'sp_league' );
			if ( ! $leagues || is_wp_error( $leagues ) ) {
				continue;
			}
			foreach ( $leagues as $league ) {
				if ( (int) $league->term_id === $this->league_id ) {
					$filtered[] = $game;
					break;
				}
			}
		}

		return $filtered;
	}
}
