<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Expose the protected extract_event_data method for unit testing.
 */
class TestableSPA_Event_Handler extends SPA_Event_Handler {
	public function extract( int $post_id ) {
		return $this->extract_event_data( $post_id );
	}
}

class EventHandlerTest extends TestCase {

	/** @var array<string,mixed> Drives the get_post_meta alias for each test. */
	private array $post_meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->post_meta = [];

		Functions\when( 'add_action' )->justReturn();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = null ) => $key === 'spa_score_column' ? ( $this->post_meta['_score_column'] ?? 'goals' ) : $default
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				if ( 'sp_team' === $key ) {
					return $this->post_meta['sp_team'] ?? [];
				}
				if ( 'sp_results' === $key ) {
					return $this->post_meta['sp_results'] ?? null;
				}
				if ( 'spa_brand_color' === $key ) {
					return $this->post_meta['spa_brand_color'] ?? '';
				}
				return $single ? '' : [];
			}
		);
		Functions\when( 'get_the_title' )->returnArg();
		Functions\when( 'wp_get_post_terms' )->alias(
			fn() => $this->post_meta['_league_terms'] ?? []
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function handler(): TestableSPA_Event_Handler {
		return new TestableSPA_Event_Handler();
	}

	// ── guard clauses ─────────────────────────────────────────────────────────

	public function test_returns_false_when_fewer_than_two_teams(): void {
		$this->post_meta['sp_team'] = [ 10 ];

		$this->assertFalse( $this->handler()->extract( 1 ) );
	}

	public function test_returns_false_when_no_teams(): void {
		$this->post_meta['sp_team'] = [];

		$this->assertFalse( $this->handler()->extract( 1 ) );
	}

	// ── score reading ─────────────────────────────────────────────────────────

	public function test_reads_configured_score_column(): void {
		$this->stub( 4, 2, 'goals' );

		$data = $this->handler()->extract( 42 );

		$this->assertSame( 4, $data['home_score'] );
		$this->assertSame( 2, $data['away_score'] );
	}

	public function test_falls_back_to_outcome_when_column_missing(): void {
		$this->post_meta['sp_team']    = [ 10, 20 ];
		$this->post_meta['sp_results'] = [
			10 => [ 'outcome' => 'W' ],
			20 => [ 'outcome' => 'L' ],
		];
		$this->post_meta['_score_column'] = 'goals'; // column not present in results

		$data = $this->handler()->extract( 42 );

		$this->assertSame( 'W', $data['home_score'] );
		$this->assertSame( 'L', $data['away_score'] );
	}

	public function test_empty_scores_when_results_missing(): void {
		$this->post_meta['sp_team']    = [ 10, 20 ];
		$this->post_meta['sp_results'] = null;

		$data = $this->handler()->extract( 42 );

		$this->assertSame( '', $data['home_score'] );
		$this->assertSame( '', $data['away_score'] );
	}

	// ── team / competition ────────────────────────────────────────────────────

	public function test_returns_competition_from_league_taxonomy(): void {
		$this->stub( 2, 1, 'goals', 'Premier League' );

		$data = $this->handler()->extract( 42 );

		$this->assertSame( 'Premier League', $data['competition'] );
	}

	public function test_competition_empty_when_no_league_terms(): void {
		$this->stub( 2, 1, 'goals', '' );

		$data = $this->handler()->extract( 42 );

		$this->assertSame( '', $data['competition'] );
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	private function stub(
		int $home_score = 2,
		int $away_score = 1,
		string $column = 'goals',
		string $competition = ''
	): void {
		$this->post_meta['sp_team']       = [ 10, 20 ];
		$this->post_meta['_score_column'] = $column;
		$this->post_meta['sp_results']    = [
			10 => [ $column => $home_score ],
			20 => [ $column => $away_score ],
		];
		$this->post_meta['_league_terms'] = $competition !== '' ? [ $competition ] : [];
	}
}
