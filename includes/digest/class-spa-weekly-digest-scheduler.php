<?php
/**
 * Schedules and dispatches the weekly digest via WP-Cron.
 *
 * PRO-gated: every dispatch is guarded by SPA_License::is_pro().
 * Uses cron hook `spa_weekly_digest` (distinct from the existing
 * `spa_digest_send` hook used for upcoming-games digests).
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and dispatches the weekly results-recap digest via WP-Cron.
 */
class SPA_Weekly_Digest_Scheduler {

	private const CRON_HOOK = 'spa_weekly_digest';

	/**
	 * Register the cron hook and option-change re-schedulers.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );

		// Re-schedule when relevant options change.
		add_action( 'update_option_spa_weekly_digest_enabled', array( $this, 'reschedule' ) );
		add_action( 'update_option_spa_weekly_digest_day', array( $this, 'reschedule' ) );
		add_action( 'update_option_spa_weekly_digest_time', array( $this, 'reschedule' ) );

		$this->maybe_schedule();
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	/**
	 * Run the weekly digest for each configured league.
	 * Called by WP-Cron; also callable manually for testing.
	 */
	public function run(): void {
		// Single-event cron: always re-arm the next occurrence while the feature
		// is enabled — even when this run sends nothing (license lapsed, no
		// leagues yet). Otherwise the schedule would die permanently until
		// settings are re-saved, and never resume when a license renews. The
		// only thing that clears the schedule is disabling the feature, handled
		// by reschedule() on the option-change hook.
		if ( ! get_option( 'spa_weekly_digest_enabled' ) ) {
			return;
		}

		try {
			if ( ! SPA_License::is_pro() ) {
				return;
			}

			$league_ids = (array) get_option( 'spa_weekly_digest_leagues', array() );

			foreach ( $league_ids as $league_id ) {
				$league_id = intval( $league_id );
				if ( $league_id <= 0 ) {
					continue;
				}
				$this->send_for_league( $league_id );
			}
		} finally {
			$this->schedule_next();
		}
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	/** Initialize the schedule on plugin load if not already set. */
	private function maybe_schedule(): void {
		if ( ! get_option( 'spa_weekly_digest_enabled' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule_next();
		}
	}

	/** Called when schedule options change — clear and re-queue. */
	public function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( get_option( 'spa_weekly_digest_enabled' ) ) {
			$this->schedule_next();
		}
	}

	/** Queue the next single occurrence. */
	private function schedule_next(): void {
		$timestamp = $this->next_timestamp();
		if ( $timestamp ) {
			wp_schedule_single_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Calculate the next UTC timestamp for the configured day + time.
	 *
	 * Stores time as site-local H:i and converts properly via DateTimeZone,
	 * so :30 offsets (e.g. America/St_Johns, UTC-3:30) work correctly.
	 */
	private function next_timestamp(): ?int {
		$day  = get_option( 'spa_weekly_digest_day', 'monday' );
		$time = get_option( 'spa_weekly_digest_time', '09:00' );

		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '09:00';
		}

		try {
			$tz  = wp_timezone();
			$now = new DateTimeImmutable( 'now', $tz );

			// Find the next occurrence of $day at $time in site-local time.
			// 'next monday' etc. gives the next calendar day with that name.
			$candidate = new DateTimeImmutable( 'next ' . $day . ' ' . $time, $tz );

			// If today IS that day and the time hasn't passed yet, use today.
			$today_candidate = new DateTimeImmutable( 'today ' . $time, $tz );
			if ( strtolower( $now->format( 'l' ) ) === strtolower( $day ) && $today_candidate > $now ) {
				$candidate = $today_candidate;
			}

			return $candidate->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}

	// -------------------------------------------------------------------------
	// Per-league send
	// -------------------------------------------------------------------------

	/**
	 * Build, format, and dispatch the digest for a single league.
	 *
	 * @param int $league_id League term ID.
	 * @return void
	 */
	private function send_for_league( int $league_id ): void {
		// Idempotency guard: never double-send within 23 hours.
		$last_sent_key = "spa_digest_last_sent_{$league_id}";
		$last_sent     = (int) get_option( $last_sent_key, 0 );

		if ( $last_sent > 0 && ( time() - $last_sent ) < 23 * HOUR_IN_SECONDS ) {
			return;
		}

		if ( ! class_exists( 'SPA_Digest_Builder' ) || ! class_exists( 'SPA_Digest_Formatter' ) ) {
			return;
		}

		$builder = new SPA_Digest_Builder( $league_id, SPA_Digest_Builder::options_from_settings() );

		$data = $builder->build();

		// Skip if no content.
		if ( $data['is_empty'] ) {
			return;
		}

		$formatter = new SPA_Digest_Formatter( $data );
		$sent      = false;

		$league_term = get_term( $league_id, 'sp_league' );
		$league_name = ( $league_term && ! is_wp_error( $league_term ) ) ? $league_term->name : (string) $league_id;

		// Send to Discord if configured.
		$discord_url = get_option( 'spa_discord_webhook_url', '' );
		if ( $discord_url && get_option( 'spa_discord_enabled' ) ) {
			$webhook = new SPA_Webhook_Discord( $discord_url );
			$result  = $webhook->send( $formatter->to_discord_embed() );
			$status  = is_wp_error( $result ) ? 'failed' : 'sent';

			SPA_Log::write(
				array(
					'uid'      => uniqid( 'spa', true ),
					'type'     => 'digest',
					'label'    => sprintf(
					/* translators: %s: league name */
						__( 'Weekly Digest — %s', 'sportspress-announcer' ),
						$league_name
					),
					'channel'  => $league_name,
					'platform' => 'discord',
					'sent_at'  => time(),
					'status'   => $status,
				)
			);

			if ( 'sent' === $status ) {
				$sent = true;
			}
		}

		// Publish as post if enabled.
		if ( get_option( 'spa_weekly_digest_publish_as_post' ) ) {
			$this->publish_as_post( $data, $formatter );
			$sent = true;
		}

		if ( $sent ) {
			update_option( $last_sent_key, time(), false );
			// Persist the standings baseline only now that the digest went out,
			// so next week's movement arrows diff against what we actually sent.
			$builder->commit_standings_snapshot();
		}
	}

	/**
	 * Publish the digest as a WordPress post.
	 *
	 * @param array                $data      DigestData.
	 * @param SPA_Digest_Formatter $formatter Formatter for the same data.
	 * @return void
	 */
	private function publish_as_post( array $data, SPA_Digest_Formatter $formatter ): void {
		$league_term = get_term( $data['league_id'], 'sp_league' );
		$title       = $league_term && ! is_wp_error( $league_term )
			? sprintf(
				/* translators: 1: league name, 2: date */
				__( 'Weekly Digest — %1$s (%2$s)', 'sportspress-announcer' ),
				$league_term->name,
				$data['period']['end']
			)
			: sprintf(
				/* translators: %s: date */
				__( 'Weekly Digest (%s)', 'sportspress-announcer' ),
				$data['period']['end']
			);

		wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $formatter->to_html(),
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Plugin lifecycle
	// -------------------------------------------------------------------------

	/** Clear cron on plugin deactivation. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
