<?php
/**
 * Cola de aprobación: acciones (guardar/aprobar/descartar) y consulta de datos.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Queue {

	public const ACTION = 'vd_social_queue_action';
	public const NONCE  = 'vd_social_queue';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Procesa el form de una variante: guarda el texto editado y aprueba o descarta.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( VD_Social_Admin_Menu::CAP ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'vd-social-pipeline' ) );
		}
		check_admin_referer( self::NONCE );

		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		$do         = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		$post = $variant_id ? get_post( $variant_id ) : null;
		if ( ! $post || VD_Social_CPT::POST_TYPE !== $post->post_type ) {
			$this->redirect( 'invalid' );
		}

		// Guardar el texto editado (saneado) antes de actuar.
		if ( isset( $_POST['variant_text'] ) ) {
			$text = sanitize_textarea_field( wp_unslash( $_POST['variant_text'] ) );
			VD_Social_Variant::set_text( $variant_id, $text );
		}

		if ( 'approve' === $do ) {
			VD_Social_Variant::set_status( $variant_id, VD_Social_Variant::STATUS_APPROVED );
			// Resetear contador de reintentos por si venía de un error previo.
			update_post_meta( $variant_id, VD_Social_Variant::M_RETRIES, 0 );
			VD_Social_Scheduler::enqueue_publish( $variant_id );
			$this->redirect( 'approved' );
		}

		if ( 'discard' === $do ) {
			VD_Social_Variant::set_status( $variant_id, VD_Social_Variant::STATUS_DISCARDED );
			$this->redirect( 'discarded' );
		}

		// Sólo guardó cambios.
		$this->redirect( 'saved' );
	}

	private function redirect( string $notice ): void {
		$url = add_query_arg(
			array(
				'page'        => VD_Social_Admin_Menu::SLUG,
				'vd_notice'   => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Variantes activas (pendiente/error/aprobado) agrupadas por nota origen.
	 *
	 * @return array<int,array{post:?WP_Post,variants:array<int,WP_Post>}>
	 */
	public static function grouped_active(): array {
		$query = new WP_Query(
			array(
				'post_type'      => VD_Social_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 150,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => VD_Social_Variant::M_STATUS,
						'value'   => array(
							VD_Social_Variant::STATUS_PENDING,
							VD_Social_Variant::STATUS_APPROVED,
							VD_Social_Variant::STATUS_ERROR,
						),
						'compare' => 'IN',
					),
				),
			)
		);

		$groups = array();
		foreach ( $query->posts as $variant ) {
			$source = (int) get_post_meta( $variant->ID, VD_Social_Variant::M_SOURCE, true );
			if ( ! isset( $groups[ $source ] ) ) {
				$groups[ $source ] = array(
					'post'     => get_post( $source ),
					'variants' => array(),
				);
			}
			$groups[ $source ]['variants'][] = $variant;
		}

		return $groups;
	}
}
