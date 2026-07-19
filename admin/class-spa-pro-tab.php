<?php
/**
 * "Pro" tab on the settings page: what Pro will include and its launch state.
 *
 * Pro is not purchasable yet, so this page announces it as coming soon. When
 * the licensing sprint ships, the buy button replaces the coming-soon strip
 * here and no other upgrade link in the plugin needs to change; they all
 * point at this tab via SPA_License::upgrade_url().
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Pro tab panel for SPA_Settings.
 */
class SPA_Pro_Tab {

	/**
	 * Planned launch price. Shown only on this page so the plugin quotes a
	 * single price everywhere it matters.
	 */
	private const LAUNCH_PRICE = '$39/yr';

	/**
	 * Render the tab content.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="spa-pro-page">
			<h2>
				<?php esc_html_e( 'Announcer for SportsPress Pro', 'announcer-for-sportspress' ); ?>
				<span class="spa-pro-badge"><?php esc_html_e( 'Coming soon', 'announcer-for-sportspress' ); ?></span>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Everything in the free plugin stays free: Discord result announcements, templates, and the upcoming-fixtures digest. Pro adds the extras below.', 'announcer-for-sportspress' ); ?>
			</p>

			<ul class="spa-pro-feature-list">
				<?php foreach ( self::features() as $feature ) : ?>
					<li>
						<strong><?php echo esc_html( $feature['name'] ); ?></strong><br>
						<?php echo esc_html( $feature['blurb'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php self::render_availability_strip(); ?>
		</div>
		<?php
	}

	/**
	 * Pro feature blurbs shown on the page.
	 *
	 * @return array<int, array{name: string, blurb: string}>
	 */
	private static function features(): array {
		return array(
			array(
				'name'  => __( 'Slack announcements', 'announcer-for-sportspress' ),
				'blurb' => __( 'Post results to Slack alongside Discord, with per-league channel routing.', 'announcer-for-sportspress' ),
			),
			array(
				'name'  => __( 'Automatic Weekly Recap', 'announcer-for-sportspress' ),
				'blurb' => __( 'Results, standings movement, and stat leaders posted to your channels every week on a schedule.', 'announcer-for-sportspress' ),
			),
			array(
				'name'  => __( 'Facebook sharing tools', 'announcer-for-sportspress' ),
				'blurb' => __( 'Share recent results to your league Facebook page in a couple of clicks.', 'announcer-for-sportspress' ),
			),
		);
	}

	/**
	 * The launch-state strip. Once Pro is purchasable this is where the buy
	 * button goes.
	 *
	 * @return void
	 */
	private static function render_availability_strip(): void {
		?>
		<div class="spa-pro-strip">
			<span class="dashicons dashicons-clock"></span>
			<?php
			printf(
				/* translators: %s: planned yearly price, e.g. "$39/yr". */
				esc_html__( 'Pro is not available to buy yet. It is planned to launch at %s.', 'announcer-for-sportspress' ),
				esc_html( self::LAUNCH_PRICE )
			);
			if ( SPA_License::is_pro() ) {
				echo ' ';
				esc_html_e( 'Until launch, all Pro features are unlocked free, so try them out.', 'announcer-for-sportspress' );
			}
			?>
		</div>
		<?php
	}
}
