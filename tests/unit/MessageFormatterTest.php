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
		Functions\when( 'get_option' )->alias( function ( string $key, $default = false ) {
			if ( SPA_Settings::OPTION_RESULT_TEMPLATE === $key ) {
				return SPA_Settings::DEFAULT_RESULT_TEMPLATE;
			}
			return $default;
		} );
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

	// format_embed

	public function test_embed_contains_score_description(): void {
		$payload = $this->formatter()->format_embed( $this->event() );
		$desc    = $payload['embeds'][0]['description'];
		$this->assertStringContainsString( 'Sharks', $desc );
		$this->assertStringContainsString( 'Eels', $desc );
		$this->assertStringContainsString( '3', $desc );
		$this->assertStringContainsString( '1', $desc );
	}

	public function test_embed_description_bolds_team_names(): void {
		$payload = $this->formatter()->format_embed( $this->event() );
		$desc    = $payload['embeds'][0]['description'];
		$this->assertStringContainsString( '**Sharks**', $desc );
		$this->assertStringContainsString( '**Eels**', $desc );
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

	// format_result

	public function test_format_result_contains_teams_and_score(): void {
		$line = $this->formatter()->format_result( $this->event() );
		$this->assertStringContainsString( 'Sharks', $line );
		$this->assertStringContainsString( 'Eels', $line );
		$this->assertStringContainsString( '3', $line );
		$this->assertStringContainsString( '1', $line );
	}

	public function test_format_result_substitutes_all_placeholders(): void {
		// Default template is "{home} {home_score} - {away_score} {away}".
		// Verify every placeholder is substituted with the event value.
		$line = $this->formatter()->format_result( $this->event() );
		$this->assertSame( 'Sharks 3 - 1 Eels', $line );
		$this->assertStringNotContainsString( '{home}', $line );
		$this->assertStringNotContainsString( '{away}', $line );
		$this->assertStringNotContainsString( '{home_score}', $line );
		$this->assertStringNotContainsString( '{away_score}', $line );
	}

	// format_slack

	public function test_slack_mrkdwn_bolds_team_names(): void {
		$payload = $this->formatter()->format_slack( $this->event() );
		$text    = $payload['blocks'][0]['text']['text'];
		$this->assertStringContainsString( '*Sharks*', $text );
		$this->assertStringContainsString( '*Eels*', $text );
	}

	public function test_slack_plain_text_fallback_has_no_markup(): void {
		$payload = $this->formatter()->format_slack( $this->event() );
		$this->assertStringNotContainsString( '*', $payload['text'] );
	}

	public function test_slack_footer_includes_competition(): void {
		$payload = $this->formatter()->format_slack( $this->event() );
		$text    = $payload['blocks'][0]['text']['text'];
		$this->assertStringContainsString( 'Premier League', $text );
	}
}
