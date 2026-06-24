<?php
/**
 * Schedules automatic digest sends via WP-Cron.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages automatic upcoming-game digest events in WP-Cron.
 */
class SPA_Digest_Scheduler {

	private const HOOK = 'spa_digest_send';

	public const OPTION_ENABLED   = 'spa_digest_schedule_enabled';
	public const OPTION_FREQUENCY = 'spa_digest_frequency';
	public const OPTION_DAY       = 'spa_digest_day';
	public const OPTION_TIME      = 'spa_digest_time';

	/**
	 * Register cron and option-change callbacks.
	 */
	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'update_option_' . self::OPTION_ENABLED, array( $this, 'reschedule' ) );
		add_action( 'update_option_' . self::OPTION_FREQUENCY, array( $this, 'reschedule' ) );
		add_action( 'update_option_' . self::OPTION_DAY, array( $this, 'reschedule' ) );
		add_action( 'update_option_' . self::OPTION_TIME, array( $this, 'reschedule' ) );
	}

	/**
	 * Send the scheduled upcoming-games digest.
	 *
	 * @return void
	 */
	public function run(): void {
		$discord = new SPA_Upcoming_Discord();
		$discord->send_digest();
	}

	/**
	 * Schedule the digest when enabled and not already scheduled.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( $this->next_timestamp(), $this->recurrence(), self::HOOK );
		}
	}

	/**
	 * Replace the existing digest schedule after a setting changes.
	 *
	 * @return void
	 */
	public function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( $this->is_enabled() ) {
			wp_schedule_event( $this->next_timestamp(), $this->recurrence(), self::HOOK );
		}
	}

	/**
	 * Remove the scheduled digest when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Returns the Unix timestamp for the next scheduled send based on settings.
	 *
	 * @return int
	 */
	public function next_timestamp(): int {
		$time_str  = (string) get_option( self::OPTION_TIME, '08:00' );
		$frequency = get_option( self::OPTION_FREQUENCY, 'weekly' );

		$time_parts = array_map( 'intval', explode( ':', $time_str ) );
		$hour       = $time_parts[0] ?? 8;
		$minute     = $time_parts[1] ?? 0;
		$now        = current_datetime();

		$candidate = $now->setTime( $hour, $minute, 0 );

		if ( 'daily' === $frequency ) {
			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( '+1 day' );
			}
			return $candidate->getTimestamp();
		}

		$day_numbers = array(
			'monday'    => 1,
			'tuesday'   => 2,
			'wednesday' => 3,
			'thursday'  => 4,
			'friday'    => 5,
			'saturday'  => 6,
			'sunday'    => 7,
		);
		$day_setting = get_option( self::OPTION_DAY, 'monday' );
		$target_day  = $day_numbers[ $day_setting ] ?? 1;
		$current_day = (int) $now->format( 'N' );
		$days_ahead  = ( $target_day - $current_day + 7 ) % 7;
		$candidate   = $candidate->modify( '+' . $days_ahead . ' days' );

		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+7 days' );
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Determine whether automatic digests are enabled.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Get the WP-Cron recurrence key.
	 *
	 * @return string
	 */
	private function recurrence(): string {
		return 'weekly' === get_option( self::OPTION_FREQUENCY, 'weekly' ) ? 'weekly' : 'daily';
	}
}
