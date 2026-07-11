<?php
/**
 * Metabox en el editor de la nota: campos manuales del marcador para la placa.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Metabox {

	public const M_SCORE     = '_vd_social_score';
	public const M_SCORE_SUB = '_vd_social_score_sub';
	private const NONCE      = 'vd_social_placa_meta';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post_post', array( $this, 'save' ), 10, 2 );
	}

	public function register_meta(): void {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', (int) $post_id );
		};
		foreach ( array( self::M_SCORE, self::M_SCORE_SUB ) as $meta_key ) {
			register_post_meta(
				'post',
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $auth,
				)
			);
		}
	}

	public function add_box(): void {
		add_meta_box(
			'vd_social_placa',
			__( 'Placa de Instagram — Marcador', 'vd-social-pipeline' ),
			array( $this, 'render' ),
			'post',
			'side',
			'default'
		);
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$score = (string) get_post_meta( $post->ID, self::M_SCORE, true );
		$sub   = (string) get_post_meta( $post->ID, self::M_SCORE_SUB, true );
		?>
		<p>
			<label for="vd_social_score"><strong><?php esc_html_e( 'Marcador', 'vd-social-pipeline' ); ?></strong></label><br />
			<input type="text" id="vd_social_score" name="vd_social_score" class="widefat" value="<?php echo esc_attr( $score ); ?>" placeholder="2-1" />
		</p>
		<p>
			<label for="vd_social_score_sub"><strong><?php esc_html_e( 'Subtítulo del marcador', 'vd-social-pipeline' ); ?></strong></label><br />
			<input type="text" id="vd_social_score_sub" name="vd_social_score_sub" class="widefat" value="<?php echo esc_attr( $sub ); ?>" placeholder="ARGENTINA – SUIZA" />
		</p>
		<p class="description"><?php esc_html_e( 'Solo para notas de partido. Si quedan vacíos, el marcador no se dibuja en la placa.', 'vd-social-pipeline' ); ?></p>
		<?php
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$score = isset( $_POST['vd_social_score'] ) ? sanitize_text_field( wp_unslash( $_POST['vd_social_score'] ) ) : '';
		$sub   = isset( $_POST['vd_social_score_sub'] ) ? sanitize_text_field( wp_unslash( $_POST['vd_social_score_sub'] ) ) : '';

		update_post_meta( $post_id, self::M_SCORE, $score );
		update_post_meta( $post_id, self::M_SCORE_SUB, $sub );
	}
}
