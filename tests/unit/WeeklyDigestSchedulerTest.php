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

	// -- Manual "Send now" / idempotency guard -----------------------------

	/**
	 * The 23h guard blocks a normal (cron) send for a just-sent league.
	 */
	public function test_guard_blocks_recent_send(): void {
		$this->options['spa_digest_last_sent_5'] = time(); // just sent.

		$result = $this->invokeSendForLeague( 5, false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'guard', $result->get_error_code() );
	}

	/**
	 * A deliberate manual send bypasses the 23h guard: it must NOT return the
	 * 'guard' error even for a just-sent league. (It proceeds to the builder,
	 * which with no data returns the 'empty' error — proving the guard was
	 * skipped.)
	 */
	public function test_send_now_bypasses_guard(): void {
		$this->options['spa_digest_last_sent_5'] = time(); // just sent.

		// Minimal stubs so the builder runs and reports an empty week (no
		// events, no standings), proving the guard was skipped rather than
		// short-circuiting on it.
		FakeSchedulerWpQuery::$posts = [];
		if ( ! class_exists( 'WP_Query' ) ) {
			class_alias( FakeSchedulerWpQuery::class, 'WP_Query' );
		}
		if ( ! class_exists( 'SP_League_Table' ) ) {
			class_alias( FakeSchedulerLeagueTable::class, 'SP_League_Table' );
		}
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_posts' )->justReturn( [] );

		$result = $this->scheduler()->send_now( 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'guard', $result->get_error_code(), 'Manual send must skip the 23h guard' );
		$this->assertSame( 'empty', $result->get_error_code() );
	}

	/**
	 * Call the private send_for_league() with an explicit $force flag.
	 *
	 * @param int  $league_id League term ID.
	 * @param bool $force     Whether to bypass the guard.
	 * @return mixed
	 */
	private function invokeSendForLeague( int $league_id, bool $force ) {
		$s      = $this->scheduler();
		$method = new ReflectionMethod( $s, 'send_for_league' );
		// Required to invoke private methods on PHP < 8.1; a no-op (and
		// deprecated) from 8.5 onward, so only call it where it has effect.
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( $s, $league_id, $force );
	}
}

/** Minimal WP_Query stand-in returning no posts. */
class FakeSchedulerWpQuery {
	/** @var array */
	public static array $posts = [];
	/** @var array */
	public array $posts_prop = [];
	/**
	 * @param array $args Query args (ignored).
	 */
	public function __construct( $args = [] ) {
		$this->posts_prop = self::$posts;
	}
	/** @return array */
	public function __get( $name ) {
		return 'posts' === $name ? self::$posts : null;
	}
}

/** Minimal SP_League_Table stand-in producing an empty table. */
class FakeSchedulerLeagueTable {
	/**
	 * @param int $id League ID (ignored).
	 */
	public function __construct( $id = 0 ) {}
	/** @return array */
	public function data() {
		return [];
	}
}
