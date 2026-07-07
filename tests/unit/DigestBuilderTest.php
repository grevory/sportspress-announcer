<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SPA_Digest_Builder.
 *
 * The DB-heavy query paths (results/players) are exercised indirectly; the
 * focus here is the pure logic: standings movement diff and empty-week
 * detection, which are the parts most likely to regress.
 */
class DigestBuilderTest extends TestCase {

	/** @var array In-memory option store shared with mocked get/update_option. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return $this->options[ $key ] ?? $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) {
			$this->options[ $key ] = $value;
			return true;
		} );

		// Default: no events, no players. Individual tests can override.
		Functions\when( 'get_posts' )->justReturn( [] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		// Reset the SP_League_Table stub between tests.
		FakeLeagueTable::$rows = [];
	}

	// -- Empty-week detection ----------------------------------------------

	public function test_is_empty_true_when_no_results_and_no_upcoming(): void {
		$this->stubEmptyResultsQuery();

		$data = ( new SPA_Digest_Builder( 5, [ 'include_upcoming' => false ] ) )->build();

		$this->assertTrue( $data['is_empty'] );
		$this->assertSame( [], $data['results'] );
	}

	// -- Content toggles ---------------------------------------------------

	public function test_results_included_by_default(): void {
		$this->stubOneResult();
		FakeLeagueTable::$rows = [];

		$data = ( new SPA_Digest_Builder( 5 ) )->build();

		$this->assertCount( 1, $data['results'] );
		$this->assertSame( 'Sharks', $data['results'][0]['home'] );
		$this->assertFalse( $data['is_empty'] );
	}

	public function test_results_hidden_when_toggle_off_but_not_empty(): void {
		// A game happened, but the user chose not to show results. The section
		// is blank, yet is_empty must stay false so the digest still sends.
		$this->stubOneResult();
		FakeLeagueTable::$rows = [];

		$data = ( new SPA_Digest_Builder( 5, [ 'include_results' => false ] ) )->build();

		$this->assertSame( [], $data['results'] );
		$this->assertFalse( $data['is_empty'], 'is_empty must reflect real results, not the display toggle' );
	}

	public function test_standings_hidden_when_toggle_off(): void {
		$this->stubEmptyResultsQuery();
		FakeLeagueTable::$rows = [
			0  => [ 'name' => 'Team' ],
			10 => [ 'name' => 'Sharks', 'p' => 5, 'pts' => 15 ],
		];

		$data = ( new SPA_Digest_Builder( 5, [ 'include_standings' => false ] ) )->build();

		$this->assertSame( [], $data['standings'] );
	}

	public function test_leaders_hidden_when_toggle_off(): void {
		$this->stubEmptyResultsQuery();

		// stat_keys present, but leaders toggled off → no get_stat_leaders call.
		$data = ( new SPA_Digest_Builder( 5, [
			'include_leaders' => false,
			'stat_keys'       => [ 'goals' ],
		] ) )->build();

		$this->assertSame( [], $data['stat_leaders'] );
	}

	// -- Standings movement diff -------------------------------------------

	public function test_standings_first_run_all_new(): void {
		$this->stubEmptyResultsQuery();
		FakeLeagueTable::$rows = [
			0        => [ 'name' => 'Team', 'pts' => 'Pts' ], // label row
			10       => [ 'name' => 'Sharks', 'p' => 5, 'pts' => 15 ],
			20       => [ 'name' => 'Eels',   'p' => 5, 'pts' => 12 ],
		];

		$data      = ( new SPA_Digest_Builder( 5 ) )->build();
		$standings = $data['standings'];

		$this->assertCount( 2, $standings );
		$this->assertSame( 'new', $standings[0]['movement'] );
		$this->assertSame( 'new', $standings[1]['movement'] );
		$this->assertSame( 1, $standings[0]['rank'] );
		$this->assertSame( 'Sharks', $standings[0]['name'] );
	}

	public function test_standings_movement_against_previous_snapshot(): void {
		$this->stubEmptyResultsQuery();

		// Seed a previous snapshot: Eels 1st, Sharks 2nd, Rays 3rd.
		$this->options['spa_digest_standings_snapshot_5'] = [
			[ 'name' => 'Eels',   'rank' => 1 ],
			[ 'name' => 'Sharks', 'rank' => 2 ],
			[ 'name' => 'Rays',   'rank' => 3 ],
		];

		// Now: Sharks 1st (up), Eels 2nd (down), Rays 3rd (same), Cod 4th (new).
		FakeLeagueTable::$rows = [
			0  => [ 'name' => 'Team' ],
			10 => [ 'name' => 'Sharks', 'p' => 6, 'pts' => 18 ],
			20 => [ 'name' => 'Eels',   'p' => 6, 'pts' => 15 ],
			30 => [ 'name' => 'Rays',   'p' => 6, 'pts' => 12 ],
			40 => [ 'name' => 'Cod',    'p' => 6, 'pts' => 9 ],
		];

		$standings = ( new SPA_Digest_Builder( 5 ) )->build()['standings'];
		$byName    = [];
		foreach ( $standings as $row ) {
			$byName[ $row['name'] ] = $row['movement'];
		}

		$this->assertSame( 'up', $byName['Sharks'] );
		$this->assertSame( 'down', $byName['Eels'] );
		$this->assertSame( 'same', $byName['Rays'] );
		$this->assertSame( 'new', $byName['Cod'] );
	}

	public function test_build_does_not_persist_snapshot(): void {
		// build() must be side-effect free: the free preview path calls it and
		// must never mutate the movement baseline.
		$this->stubEmptyResultsQuery();
		FakeLeagueTable::$rows = [
			0  => [ 'name' => 'Team' ],
			10 => [ 'name' => 'Sharks', 'p' => 5, 'pts' => 15 ],
		];

		( new SPA_Digest_Builder( 5 ) )->build();

		$this->assertArrayNotHasKey(
			'spa_digest_standings_snapshot_5',
			$this->options,
			'build() should not write the standings snapshot'
		);
	}

	public function test_commit_persists_snapshot_after_build(): void {
		$this->stubEmptyResultsQuery();
		FakeLeagueTable::$rows = [
			0  => [ 'name' => 'Team' ],
			10 => [ 'name' => 'Sharks', 'p' => 5, 'pts' => 15 ],
		];

		$builder = new SPA_Digest_Builder( 5 );
		$builder->build();

		// Still unwritten until an explicit commit.
		$this->assertArrayNotHasKey( 'spa_digest_standings_snapshot_5', $this->options );

		$builder->commit_standings_snapshot();

		$snapshot = $this->options['spa_digest_standings_snapshot_5'] ?? null;
		$this->assertIsArray( $snapshot );
		$this->assertSame( 'Sharks', $snapshot[0]['name'] );
		$this->assertSame( 1, $snapshot[0]['rank'] );
	}

	public function test_commit_is_noop_without_standings(): void {
		// No SP_League_Table rows → no standings → commit writes nothing.
		$this->stubEmptyResultsQuery();
		FakeLeagueTable::$rows = [];

		$builder = new SPA_Digest_Builder( 5 );
		$builder->build();
		$builder->commit_standings_snapshot();

		$this->assertArrayNotHasKey( 'spa_digest_standings_snapshot_5', $this->options );
	}

	// -- Helpers -----------------------------------------------------------

	/** Stub the results WP_Query to return zero posts. */
	private function stubEmptyResultsQuery(): void {
		$this->ensureWpQuery();
		FakeWpQuery::$next_posts = [];
	}

	/**
	 * Stub the results WP_Query to return a single scored event, wiring up the
	 * post-meta/title/term functions the builder reads for each result.
	 */
	private function stubOneResult(): void {
		$this->ensureWpQuery();
		FakeWpQuery::$next_posts = [ (object) [ 'ID' => 100 ] ];

		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
			if ( 'sp_team' === $key ) {
				return [ 11, 22 ];
			}
			if ( 'sp_results' === $key ) {
				return [
					11 => [ 'goals' => 3 ],
					22 => [ 'goals' => 1 ],
				];
			}
			return $single ? '' : [];
		} );
		Functions\when( 'get_the_terms' )->justReturn( [ (object) [ 'name' => 'Div 1' ] ] );
		Functions\when( 'get_the_title' )->alias( fn( $id ) => 11 === $id ? 'Sharks' : 'Eels' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/e' );
		Functions\when( 'get_the_date' )->justReturn( '2026-01-03' );
	}

	private function ensureWpQuery(): void {
		if ( ! class_exists( 'WP_Query' ) ) {
			class_alias( 'FakeWpQuery', 'WP_Query' );
		}
	}
}

/**
 * Minimal WP_Query stand-in. Tests set the class-level $next_posts; each
 * instance copies it into the instance $posts the builder reads.
 */
class FakeWpQuery {
	/** @var array */
	public static $next_posts = [];
	/** @var array */
	public array $posts;
	public function __construct( $args = [] ) {
		$this->posts = self::$next_posts;
	}
}

/**
 * Minimal SP_League_Table stand-in. The builder only calls ->data().
 */
class FakeLeagueTable {
	/** @var array */
	public static $rows = [];
	public function __construct( $league_id ) {}
	public function data() {
		return self::$rows;
	}
}

// Alias the stub to the real class name the builder looks for.
if ( ! class_exists( 'SP_League_Table' ) ) {
	class_alias( 'FakeLeagueTable', 'SP_League_Table' );
}
