<?php
/**
 * Adds a brand color picker to sp_team edit screens.
 *
 * @package SportsPress_Announcer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds and saves a brand color for SportsPress teams.
 */
class SPA_Team_Color {

	private const META_KEY = 'spa_brand_color';
	private const NONCE    = 'spa_team_color_nonce';

	/**
	 * Register team color callbacks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_sp_team', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * Register the team color meta box.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'spa_team_color',
			__( 'Announcer', 'sportspress-announcer' ),
			array( $this, 'render' ),
			'sp_team',
			'side',
			'default'
		);
	}

	/**
	 * Render the team color field.
	 *
	 * @param \WP_Post $post Team post object.
	 *
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$color = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! $color ) {
			$color = '#000000';
		}
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="<?php echo esc_attr( self::META_KEY ); ?>">
				<?php esc_html_e( 'Brand color', 'sportspress-announcer' ); ?>
			</label><br>
			<input
				type="color"
				id="<?php echo esc_attr( self::META_KEY ); ?>"
				name="<?php echo esc_attr( self::META_KEY ); ?>"
				value="<?php echo esc_attr( $color ); ?>"
				style="margin-top:4px;"
			/>
		</p>
		<p class="description">
			<?php esc_html_e( 'Used as the sidebar color in Discord match result embeds.', 'sportspress-announcer' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the team brand color.
	 *
	 * @param int $post_id Team post ID.
	 *
	 * @return void
	 */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST[ self::META_KEY ] ) ) {
			$color = sanitize_hex_color( wp_unslash( $_POST[ self::META_KEY ] ) );
			if ( $color ) {
				update_post_meta( $post_id, self::META_KEY, $color );
			} else {
				delete_post_meta( $post_id, self::META_KEY );
			}
		}
	}
}
