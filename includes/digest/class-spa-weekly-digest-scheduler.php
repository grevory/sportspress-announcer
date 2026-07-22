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
	 * Send the digest for one league on demand, bypassing the idempotency guard.
	 *
	 * Used by the "Send now" button. Skips the 23h double-send guard (the user
	 * asked for it explicitly) but still commits the standings snapshot so next
	 * week's movement arrows diff against what was just sent.
	 *
	 * @param int $league_id League term ID.
	 * @return true|\WP_Error True on send, WP_Error with a code of 'empty' when
	 *                        there is nothing to send or 'unsent' when no channel
	 *                        is configured.
	 */
	public function send_now( int $league_id ) {
		return $this->send_for_league( $league_id, true );
	}

	/**
	 * Build, format, and dispatch the digest for a single league.
	 *
	 * @param int  $league_id League term ID.
	 * @param bool $force     When true, bypass the 23h idempotency guard.
	 * @return true|\WP_Error True when a channel sent; WP_Error otherwise
	 *                        ('guard', 'unavailable', 'empty', or 'unsent').
	 */
	private function send_for_league( int $league_id, bool $force = false ) {
		// Idempotency guard: never double-send within 23 hours (skipped on a
		// deliberate manual send).
		$last_sent_key = "spa_digest_last_sent_{$league_id}";
		$last_sent     = (int) get_option( $last_sent_key, 0 );

		if ( ! $force && $last_sent > 0 && ( time() - $last_sent ) < 23 * HOUR_IN_SECONDS ) {
			return new \WP_Error( 'guard', __( 'Digest already sent in the last 23 hours.', 'announcer-for-sportspress' ) );
		}

		if ( ! class_exists( 'SPA_Digest_Builder' ) || ! class_exists( 'SPA_Digest_Formatter' ) ) {
			return new \WP_Error( 'unavailable', __( 'Digest builder unavailable.', 'announcer-for-sportspress' ) );
		}

		$builder = new SPA_Digest_Builder( $league_id, SPA_Digest_Builder::options_from_settings( $league_id ) );

		$data = $builder->build();

		// Skip if no content.
		if ( $data['is_empty'] ) {
			return new \WP_Error( 'empty', __( 'No results, standings, or fixtures for this period.', 'announcer-for-sportspress' ) );
		}

		$formatter = new SPA_Digest_Formatter( $data );
		$sent      = false;

		$league_term = get_term( $league_id, 'sp_league' );
		$league_name = ( $league_term && ! is_wp_error( $league_term ) ) ? $league_term->name : (string) $league_id;

		// Send to Discord if configured.
		$sent = $this->send_to_discord( $formatter, $league_name ) || $sent;

		// Send to Slack if configured.
		$sent = $this->send_to_slack( $formatter, $league_name ) || $sent;

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
			return true;
		}

		return new \WP_Error( 'unsent', __( 'No channel is configured to receive the digest.', 'announcer-for-sportspress' ) );
	}

	/**
	 * Dispatch the digest to Discord if enabled, logging the outcome.
	 *
	 * @param SPA_Digest_Formatter $formatter   Formatter for the digest data.
	 * @param string               $league_name Human-readable league label.
	 * @return bool True if a message was sent successfully.
	 */
	private function send_to_discord( SPA_Digest_Formatter $formatter, string $league_name ): bool {
		$discord_url = get_option( 'spa_discord_webhook_url', '' );
		if ( ! $discord_url || ! get_option( 'spa_discord_enabled' ) ) {
			return false;
		}

		$webhook = new SPA_Webhook_Discord( $discord_url );
		$result  = $webhook->send( $formatter->to_discord_embed() );

		return $this->log_dispatch( $result, $league_name, 'discord', $formatter->to_text() );
	}

	/**
	 * Dispatch the digest to Slack if enabled, logging the outcome.
	 *
	 * @param SPA_Digest_Formatter $formatter   Formatter for the digest data.
	 * @param string               $league_name Human-readable league label.
	 * @return bool True if a message was sent successfully.
	 */
	private function send_to_slack( SPA_Digest_Formatter $formatter, string $league_name ): bool {
		$slack_url = get_option( 'spa_slack_webhook_url', '' );
		if ( ! $slack_url || ! get_option( 'spa_slack_enabled' ) ) {
			return false;
		}

		$webhook = new SPA_Webhook_Slack( $slack_url );
		$result  = $webhook->send( $formatter->to_slack_blocks() );

		return $this->log_dispatch( $result, $league_name, 'slack', $formatter->to_text() );
	}

	/**
	 * Record a digest send outcome in the activity log.
	 *
	 * @param true|\WP_Error $result      Webhook send result.
	 * @param string         $league_name Human-readable league label.
	 * @param string         $platform    'discord' or 'slack'.
	 * @param string         $message     Plain-text digest body that was sent.
	 * @return bool True if the message was sent successfully.
	 */
	private function log_dispatch( $result, string $league_name, string $platform, string $message ): bool {
		$status = is_wp_error( $result ) ? 'failed' : 'sent';

		SPA_Log::write(
			array(
				'uid'      => uniqid( 'spa', true ),
				'type'     => 'digest',
				'label'    => sprintf(
				/* translators: %s: league name */
					__( 'Weekly Recap — %s', 'announcer-for-sportspress' ),
					$league_name
				),
				'channel'  => $league_name,
				'platform' => $platform,
				'message'  => $message,
				'sent_at'  => time(),
				'status'   => $status,
			)
		);

		return 'sent' === $status;
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
				__( 'Weekly Recap — %1$s (%2$s)', 'announcer-for-sportspress' ),
				$league_term->name,
				$data['period']['end']
			)
			: sprintf(
				/* translators: %s: date */
				__( 'Weekly Recap (%s)', 'announcer-for-sportspress' ),
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
