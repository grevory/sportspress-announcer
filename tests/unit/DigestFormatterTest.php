<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class DigestFormatterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test League Site' );
		Functions\when( 'get_term' )->justReturn( (object) [ 'name' => 'Div 1' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// gmdate() is a PHP internal — left un-mocked; the real function is fine.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function data( array $overrides = [] ): array {
		return array_merge( [
			'league_id'    => 5,
			'period'       => [ 'start' => '2026-01-01', 'end' => '2026-01-07' ],
			'results'      => [],
			'standings'    => [],
			'stat_leaders' => [],
			'upcoming'     => [],
			'is_empty'     => false,
		], $overrides );
	}

	private function result( string $home, int $hs, int $as, string $away ): array {
		return [
			'home'        => $home,
			'away'        => $away,
			'home_score'  => $hs,
			'away_score'  => $as,
			'competition' => 'Div 1',
			'event_url'   => 'https://example.test/e',
			'date'        => '2026-01-03',
		];
	}

	// -- Discord embed structure -------------------------------------------

	public function test_embed_omits_empty_sections(): void {
		$payload = ( new SPA_Digest_Formatter( $this->data() ) )->to_discord_embed();
		$this->assertSame( [], $payload['embeds'][0]['fields'], 'No fields when all sections empty' );
	}

	public function test_embed_includes_results_field_when_present(): void {
		$data    = $this->data( [ 'results' => [ $this->result( 'Sharks', 3, 1, 'Eels' ) ] ] );
		$payload = ( new SPA_Digest_Formatter( $data ) )->to_discord_embed();
		$fields  = $payload['embeds'][0]['fields'];
		$this->assertCount( 1, $fields );
		$this->assertStringContainsString( '**Sharks 3 – 1 Eels**', $fields[0]['value'] );
	}

	public function test_embed_standings_movement_arrows(): void {
		$data = $this->data( [ 'standings' => [
			[ 'rank' => 1, 'name' => 'Sharks', 'played' => 5, 'points' => 15, 'movement' => 'up' ],
			[ 'rank' => 2, 'name' => 'Eels',   'played' => 5, 'points' => 12, 'movement' => 'down' ],
			[ 'rank' => 3, 'name' => 'Rays',   'played' => 5, 'points' => 10, 'movement' => 'same' ],
			[ 'rank' => 4, 'name' => 'Cod',    'played' => 5, 'points' => 9,  'movement' => 'new' ],
		] ] );
		$value = ( new SPA_Digest_Formatter( $data ) )->to_discord_embed()['embeds'][0]['fields'][0]['value'];
		$this->assertStringContainsString( '↑', $value );
		$this->assertStringContainsString( '↓', $value );
		$this->assertStringContainsString( '→', $value );
		$this->assertStringContainsString( '✦', $value );
	}

	// -- Truncation --------------------------------------------------------

	public function test_results_truncated_under_field_limit_with_more_link(): void {
		$results = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$results[] = $this->result( "Home Team Number {$i}", $i, 0, "Away Team Number {$i}" );
		}
		$value = ( new SPA_Digest_Formatter( $this->data( [ 'results' => $results ] ) ) )
			->to_discord_embed()['embeds'][0]['fields'][0]['value'];

		$this->assertLessThanOrEqual( 1024, mb_strlen( $value ), 'Discord field limit respected' );
		$this->assertStringContainsString( 'and', $value );
		$this->assertStringContainsString( 'more', $value );
		$this->assertStringContainsString( 'https://example.test', $value );
	}

	public function test_short_results_not_truncated(): void {
		$results = [ $this->result( 'A', 1, 0, 'B' ), $this->result( 'C', 2, 2, 'D' ) ];
		$value   = ( new SPA_Digest_Formatter( $this->data( [ 'results' => $results ] ) ) )
			->to_discord_embed()['embeds'][0]['fields'][0]['value'];
		$this->assertStringNotContainsString( 'more', $value );
	}

	// -- Leaders -----------------------------------------------------------

	public function test_leaders_field_lists_top_players(): void {
		$data = $this->data( [ 'stat_leaders' => [
			[
				'stat'    => 'goals',
				'label'   => 'Goals',
				'players' => [
					[ 'name' => 'Alice', 'team' => 'Sharks', 'value' => 12 ],
					[ 'name' => 'Bob',   'team' => 'Eels',   'value' => 9 ],
				],
			],
		] ] );
		$value = ( new SPA_Digest_Formatter( $data ) )->to_discord_embed()['embeds'][0]['fields'][0]['value'];
		$this->assertStringContainsString( 'Goals', $value );
		$this->assertStringContainsString( 'Alice', $value );
		$this->assertStringContainsString( '12', $value );
	}

	// -- HTML preview ------------------------------------------------------

	public function test_html_preview_escapes_and_includes_sections(): void {
		$data = $this->data( [
			'results'   => [ $this->result( 'Sharks', 3, 1, 'Eels' ) ],
			'standings' => [ [ 'rank' => 1, 'name' => 'Sharks', 'played' => 5, 'points' => 15, 'movement' => 'up' ] ],
		] );
		$html = ( new SPA_Digest_Formatter( $data ) )->to_html();
		$this->assertStringContainsString( 'spa-digest-preview', $html );
		$this->assertStringContainsString( 'Sharks', $html );
		$this->assertStringContainsString( 'spa-arrow-up', $html );
	}
}
