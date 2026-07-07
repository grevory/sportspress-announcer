<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the single-event cron self-scheduling.
 *
 * The scheduler must always re-arm the next occurrence while the feature is
 * enabled — even when a given run sends nothing (license lapsed, no leagues) —
 * otherwise the schedule dies until settings are re-saved.
 */
class WeeklyDigestSchedulerTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var int Number of times wp_schedule_single_event was called. */
	private int $scheduled;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options   = [];
		$this->scheduled = 0;

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn();
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return $this->options[ $key ] ?? $default;
		} );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->alias( function () {
			$this->scheduled++;
			return true;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function scheduler(): SPA_Weekly_Digest_Scheduler {
		// Constructor calls maybe_schedule(); disabled by default here so it
		// does not schedule. Reset the counter afterwards.
		$s              = new SPA_Weekly_Digest_Scheduler();
		$this->scheduled = 0;
		return $s;
	}

	public function test_disabled_does_not_reschedule(): void {
		$this->options['spa_weekly_digest_enabled'] = false;
		$this->scheduler()->run();
		$this->assertSame( 0, $this->scheduled, 'Disabled feature must not re-arm the cron' );
	}

	public function test_no_leagues_still_reschedules(): void {
		$this->options['spa_weekly_digest_enabled'] = true;
		$this->options['spa_weekly_digest_leagues'] = [];
		$this->scheduler()->run();
		$this->assertSame( 1, $this->scheduled, 'Empty leagues must not kill the schedule' );
	}
}
