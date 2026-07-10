<?php
/**
 * Disparador del pipeline: al publicarse una nota (post type "post", primera
 * publicación) encola la generación asíncrona. Nunca llama a APIs en el request.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Pipeline {

	/** Meta en la nota origen: se marca cuando ya fue procesada (anti-reproceso). */
	public const M_PROCESSED = '_vd_social_processed';

	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
	}

	/**
	 * Detecta la primera transición a "publish" de una nota.
	 *
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 */
	public function on_transition( string $new_status, string $old_status, $post ): void {
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return; // Solo primera publicación, no updates de una nota ya publicada.
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( ! VD_Social_Options::get( 'pipeline_enabled', false ) ) {
			return;
		}
		// Anti-reproceso: si ya se procesó una vez, no volver a generar.
		if ( get_post_meta( $post->ID, self::M_PROCESSED, true ) ) {
			return;
		}
		// Anti-duplicado adicional: si ya existen variantes para la nota.
		if ( VD_Social_Variant::exists_for_source( $post->ID ) ) {
			return;
		}
		if ( $this->is_excluded( $post ) ) {
			return;
		}

		// Marcar procesada de inmediato para blindar contra doble disparo.
		update_post_meta( $post->ID, self::M_PROCESSED, time() );

		VD_Social_Scheduler::enqueue_generate( $post->ID );
	}

	/**
	 * ¿La nota pertenece a una categoría excluida?
	 */
	private function is_excluded( WP_Post $post ): bool {
		$excluded = VD_Social_Options::get( 'excluded_categories', array() );
		$excluded = is_array( $excluded ) ? array_map( 'intval', $excluded ) : array();
		if ( empty( $excluded ) ) {
			return false;
		}
		$terms = get_the_terms( $post->ID, 'category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( in_array( (int) $term->term_id, $excluded, true ) ) {
				return true;
			}
		}
		return false;
	}
}
