<?php
/**
 * Registers the "Announcer for SportsPress" Gutenberg block.
 *
 * FREE: a dynamic block whose server render reuses SPA_Shortcode::render_recap(),
 * so the editor block, the [spa_announcement] shortcode, and the digest all
 * produce identical output. The editor script is hand-written against the global
 * wp.* packages (no build step), matching the plugin's plain-JS asset convention.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the spa/announcement block: its type, editor assets, and league list.
 */
class SPA_Announcement_Block {

	/**
	 * Block type name.
	 */
	private const BLOCK = 'spa/announcement';

	/**
	 * Register the block and its editor script.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the editor script and the dynamic block type.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_script(
			'spa-announcement-block',
			SPA_PLUGIN_URL . 'assets/js/spa-announcement-block.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
			SPA_VERSION,
			true
		);

		wp_localize_script( 'spa-announcement-block', 'SPA_BLOCK', array( 'leagues' => $this->league_options() ) );

		wp_set_script_translations( 'spa-announcement-block', 'announcer-for-sportspress' );

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 2,
				'editor_script'   => 'spa-announcement-block',
				'attributes'      => array(
					'leagueId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'days'     => array(
						'type'    => 'integer',
						'default' => 7,
					),
				),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Server render for the dynamic block.
	 *
	 * @param array $attributes Saved block attributes.
	 * @return string Escaped HTML, or a placeholder notice in the editor.
	 */
	public function render( array $attributes ): string {
		$league_id = (int) ( $attributes['leagueId'] ?? 0 );
		$days      = (int) ( $attributes['days'] ?? 7 );

		$html = SPA_Shortcode::render_recap( $league_id, $days );

		if ( '' === $html ) {
			// Give editors a visible cue instead of an empty block; on the front
			// end an empty recap should simply render nothing.
			return $this->is_editor_request()
				? '<div class="spa-announcement-empty">' . esc_html__( 'No recap to show yet. Pick a league with recent games in the block settings.', 'announcer-for-sportspress' ) . '</div>'
				: '';
		}

		return $html;
	}

	/**
	 * Whether the current render is a REST/editor preview request.
	 *
	 * @return bool
	 */
	private function is_editor_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * League term IDs and names for the block's sidebar dropdown.
	 *
	 * @return array[] List of { value: int, label: string }.
	 */
	private function league_options(): array {
		$options = array();

		if ( ! class_exists( 'SportsPress' ) ) {
			return $options;
		}

		$leagues = get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $leagues ) ) {
			return $options;
		}

		foreach ( $leagues as $term ) {
			$options[] = array(
				'value' => (int) $term->term_id,
				'label' => $term->name,
			);
		}

		return $options;
	}
}
