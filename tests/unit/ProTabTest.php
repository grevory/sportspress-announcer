<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Pro tab's launch-state rendering: while Pro is not
 * purchasable the page must say "coming soon" and must not link out to
 * any external purchase URL.
 */
class ProTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		SPA_Pro_Tab::render();
		return (string) ob_get_clean();
	}

	public function test_render_announces_coming_soon_with_the_planned_price(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Coming soon', $html );
		$this->assertStringContainsString( '$39/yr', $html );
	}

	public function test_render_contains_no_external_purchase_link(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'example.com', $html );
		$this->assertStringNotContainsString( 'https://', $html );
	}

	public function test_render_lists_the_gated_features(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Slack announcements', $html );
		$this->assertStringContainsString( 'Automatic Weekly Recap', $html );
		$this->assertStringContainsString( 'Facebook sharing tools', $html );
	}
}
