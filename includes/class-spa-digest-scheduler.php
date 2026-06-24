<?php
/**
 * Schedules automatic digest sends via WP-Cron.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Digest_Scheduler {

	private const HOOK = 'spa_digest_send';

	public const OPTION_ENABLED   = 'spa_digest_schedule_enabled';
	public const OPTION_FREQUENCY = 'spa_digest_frequency';
	public const OPTION_DAY       = 'spa_digest_day';
	public const OPTION_TIME      = 'spa_digest_time';

	public function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
		add_action( 'update_option_' . self::OPTION_ENABLED,   [ $this, 'reschedule' ] );
		add_action( 'update_option_' . self::OPTION_FREQUENCY, [ $this, 'reschedule' ] );
		add_action( 'update_option_' . self::OPTION_DAY,       [ $this, 'reschedule' ] );
		add_action( 'update_option_' . self::OPTION_TIME,      [ $this, 'reschedule' ] );
	}

	public function run(): void {
		$discord = new SPA_Upcoming_Discord();
		$discord->send_digest();
	}

	public function maybe_schedule(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( $this->next_timestamp(), $this->recurrence(), self::HOOK );
		}
	}

	public function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( $this->is_enabled() ) {
			wp_schedule_event( $this->next_timestamp(), $this->recurrence(), self::HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Returns the Unix timestamp for the next scheduled send based on settings.
	 */
	public function next_timestamp(): int {
		$time_str  = get_option( self::OPTION_TIME, '08:00' );
		$frequency = get_option( self::OPTION_FREQUENCY, 'weekly' );

		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time_str . ':00' ) );

		$now  = current_time( 'timestamp' );
		$site_offset = get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS;

		if ( 'daily' === $frequency ) {
			$candidate = mktime( $hour, $minute, 0, (int) date( 'n', $now ), (int) date( 'j', $now ), (int) date( 'Y', $now ) );
			if ( $candidate <= $now ) {
				$candidate += DAY_IN_SECONDS;
			}
			return $candidate - $site_offset;
		}

		// weekly — find the next matching day-of-week
		$day_names = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];
		$target_day = array_search( get_option( self::OPTION_DAY, 'monday' ), $day_names, true );
		if ( $target_day === false ) {
			$target_day = 1; // Monday fallback
		}

		$current_dow = (int) date( 'w', $now );
		$days_ahead  = ( $target_day - $current_dow + 7 ) % 7;

		$candidate = mktime(
			$hour, $minute, 0,
			(int) date( 'n', $now ),
			(int) date( 'j', $now ) + $days_ahead,
			(int) date( 'Y', $now )
		);

		if ( $candidate <= $now ) {
			$candidate += 7 * DAY_IN_SECONDS;
		}

		return $candidate - $site_offset;
	}

	private function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	private function recurrence(): string {
		return 'weekly' === get_option( self::OPTION_FREQUENCY, 'weekly' ) ? 'weekly' : 'daily';
	}
}
