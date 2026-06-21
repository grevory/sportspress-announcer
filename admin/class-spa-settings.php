<?php
/**
 * Settings page: Settings → SportsPress Announcer.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPA_Settings {

	private const OPTION_WEBHOOK = 'spa_discord_webhook_url';
	private const MENU_SLUG      = 'sportspress-announcer';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'SportsPress Announcer', 'sportspress-announcer' ),
			__( 'SportsPress Announcer', 'sportspress-announcer' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'spa_settings_group',
			self::OPTION_WEBHOOK,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_webhook_url' ],
				'default'           => '',
			]
		);

		add_settings_section(
			'spa_section_discord',
			__( 'Discord', 'sportspress-announcer' ),
			'__return_false',
			self::MENU_SLUG
		);

		add_settings_field(
			self::OPTION_WEBHOOK,
			__( 'Webhook URL', 'sportspress-announcer' ),
			[ $this, 'render_webhook_field' ],
			self::MENU_SLUG,
			'spa_section_discord'
		);
	}

	public function sanitize_webhook_url( string $value ): string {
		$value = trim( $value );
		if ( empty( $value ) ) {
			return '';
		}
		// Discord webhook URLs start with https://discord.com/api/webhooks/
		if ( ! str_starts_with( $value, 'https://discord.com/api/webhooks/' ) ) {
			add_settings_error(
				self::OPTION_WEBHOOK,
				'spa_invalid_webhook',
				__( 'That doesn\'t look like a Discord webhook URL. It should start with https://discord.com/api/webhooks/', 'sportspress-announcer' )
			);
			return get_option( self::OPTION_WEBHOOK, '' );
		}
		return esc_url_raw( $value );
	}

	public function render_webhook_field(): void {
		$value = get_option( self::OPTION_WEBHOOK, '' );
		?>
		<input
			type="url"
			id="<?php echo esc_attr( self::OPTION_WEBHOOK ); ?>"
			name="<?php echo esc_attr( self::OPTION_WEBHOOK ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="https://discord.com/api/webhooks/…"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: URL to Discord docs */
				wp_kses(
					__( 'Paste your Discord channel\'s incoming webhook URL. <a href="%s" target="_blank" rel="noopener">How to create a webhook →</a>', 'sportspress-announcer' ),
					[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
				),
				'https://support.discord.com/hc/en-us/articles/228383668'
			);
			?>
		</p>
		<?php
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'spa_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'sportspress-announcer' ) );
				?>
			</form>
		</div>
		<?php
	}
}
