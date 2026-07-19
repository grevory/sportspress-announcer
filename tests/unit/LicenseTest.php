<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SPA_License::upgrade_url(). Every upsell link in the plugin
 * routes through it, so it must always resolve to the in-plugin Pro tab
 * rather than an external (possibly nonexistent) URL.
 */
class LicenseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://league.test/wp-admin/' . $path;
		} );
		Functions\when( 'add_query_arg' )->alias( function ( $key, $value, $url ) {
			return $url . '&' . $key . '=' . $value;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_upgrade_url_points_at_the_in_plugin_pro_tab(): void {
		$url = SPA_License::upgrade_url();

		$this->assertSame(
			'https://league.test/wp-admin/options-general.php?page=announcer-for-sportspress&tab=pro',
			$url
		);
	}

	public function test_upgrade_url_records_the_link_context(): void {
		$url = SPA_License::upgrade_url( 'weekly-digest' );

		$this->assertStringContainsString( 'tab=pro', $url );
		$this->assertStringContainsString( 'spa_ref=weekly-digest', $url );
	}
}
