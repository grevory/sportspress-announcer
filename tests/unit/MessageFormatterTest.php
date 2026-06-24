<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class MessageFormatterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// wp_specialchars_decode: just strip HTML entities for test purposes.
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function formatter(): SPA_Message_Formatter {
		return new SPA_Message_Formatter();
	}

	private function event( array $overrides = [] ): array {
		return array_merge( [
			'home'        => 'Sharks',
			'away'        => 'Eels',
			'home_score'  => 3,
			'away_score'  => 1,
			'competition' => 'Premier League',
			'home_color'  => '',
		], $overrides );
	}

	// ── format_embed ──────────────────────────────────────────────────────────

	public function test_embed_contains_score_description(): void {
		$payload = $this->formatter()->format_embed( $this->event() );
		$desc    = $payload['embeds'][0]['description'];
		$this->assertStringContainsString( 'Sharks', $desc );
		$this->assertStringContainsString( 'Eels', $desc );
		$this->assertStringContainsString( '3', $desc );
		$this->assertStringContainsString( '1', $desc );
	}

	public function test_embed_footer_includes_competition(): void {
		$payload = $this->formatter()->format_embed( $this->event() );
		$this->assertStringContainsString( 'Premier League', $payload['embeds'][0]['footer']['text'] );
	}

	public function test_embed_footer_without_competition(): void {
		$payload = $this->formatter()->format_embed( $this->event( [ 'competition' => '' ] ) );
		$this->assertSame( 'Full Time', $payload['embeds'][0]['footer']['text'] );
	}

	public function test_embed_win_color(): void {
		$payload = $this->formatter()->format_embed( $this->event( [ 'home_score' => 3, 'away_score' => 1 ] ) );
		$this->assertSame( 0x57F287, $payload['embeds'][0]['color'] );
	}

	public function test_embed_loss_color(): void {
		$payload = $this->formatter()->format_embed( $this->event( [ 'home_score' => 1, 'away_score' => 3 ] ) );
		$this->assertSame( 0xED4245, $payload['embeds'][0]['color'] );
	}

	public function test_embed_draw_color(): void {
		$payload = $this->formatter()->format_embed( $this->event( [ 'home_score' => 2, 'away_score' => 2 ] ) );
		$this->assertSame( 0xFEE75C, $payload['embeds'][0]['color'] );
	}

	public function test_embed_brand_color_overrides_outcome_color(): void {
		$payload = $this->formatter()->format_embed( $this->event( [ 'home_color' => '#FF5500' ] ) );
		$this->assertSame( 0xFF5500, $payload['embeds'][0]['color'] );
	}

	public function test_embed_non_numeric_score_uses_neutral_color(): void {
		$payload = $this->formatter()->format_embed( $this->event( [ 'home_score' => 'W', 'away_score' => 'L', 'home_color' => '' ] ) );
		$this->assertSame( 0x99AAB5, $payload['embeds'][0]['color'] );
	}

	// ── format_result ─────────────────────────────────────────────────────────

	public function test_format_result_contains_teams_and_score(): void {
		$line = $this->formatter()->format_result( $this->event() );
		$this->assertStringContainsString( 'Sharks', $line );
		$this->assertStringContainsString( 'Eels', $line );
		$this->assertStringContainsString( '3', $line );
		$this->assertStringContainsString( '1', $line );
	}

	public function test_format_result_appends_competition(): void {
		$line = $this->formatter()->format_result( $this->event() );
		$this->assertStringContainsString( 'Premier League', $line );
	}

	public function test_format_result_no_competition(): void {
		$line = $this->formatter()->format_result( $this->event( [ 'competition' => '' ] ) );
		$this->assertStringNotContainsString( '·', $line );
	}
}
