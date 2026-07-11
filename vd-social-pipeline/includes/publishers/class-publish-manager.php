<?php
/**
 * Gestor de publicación: despacha una variante a su red, con anti-duplicados,
 * reintentos con backoff y logging.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Publish_Manager {

	public function register_hooks(): void {
		add_action( VD_Social_Scheduler::HOOK_PUBLISH, array( $this, 'publish_variant' ), 10, 1 );
	}

	/**
	 * Publica una variante en su red. Handler del job de background.
	 */
	public function publish_variant( int $variant_id ): void {
		$post = get_post( $variant_id );
		if ( ! $post || VD_Social_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		$status = VD_Social_Variant::get_status( $variant_id );
		if ( in_array( $status, array( VD_Social_Variant::STATUS_PUBLISHED, VD_Social_Variant::STATUS_DISCARDED ), true ) ) {
			return; // Nada que hacer.
		}

		$network = VD_Social_Variant::get_network( $variant_id );
		$source  = VD_Social_Variant::get_source( $variant_id );

		// Anti-duplicado: si ya hay una variante publicada para esta nota+red.
		if ( VD_Social_Variant::already_published( $source, $network, $variant_id ) ) {
			VD_Social_Variant::set_status( $variant_id, VD_Social_Variant::STATUS_DISCARDED );
			VD_Social_Logger::log( $network, $source, $variant_id, 'info', '', 'Descartada: ya existía una publicación para esta nota y red.' );
			return;
		}

		$text = trim( VD_Social_Variant::get_text( $variant_id ) );
		if ( '' === $text ) {
			VD_Social_Variant::mark_error( $variant_id, __( 'El texto de la variante está vacío.', 'vd-social-pipeline' ) );
			VD_Social_Logger::log( $network, $source, $variant_id, 'error', '', 'Texto vacío.' );
			return;
		}

		$result = $this->dispatch( $network, $source, $text );

		// Diferimiento explícito (ej. límite diario de IG): reintentar sin contar error.
		if ( ! $result['ok'] && isset( $result['retry_after'] ) && $result['retry_after'] > 0 ) {
			VD_Social_Logger::log( $network, $source, $variant_id, 'info', $result['code'], $result['message'] );
			VD_Social_Scheduler::enqueue_publish( $variant_id, (int) $result['retry_after'] );
			return;
		}

		if ( $result['ok'] ) {
			VD_Social_Variant::mark_published( $variant_id, $result['remote_id'], $result['message'] );
			VD_Social_Logger::log( $network, $source, $variant_id, 'ok', $result['code'], 'Publicado. ID: ' . $result['remote_id'] );
			return;
		}

		// Falla: reintentos con backoff 1 min / 5 min; luego error definitivo.
		$attempt = VD_Social_Variant::bump_retries( $variant_id );
		VD_Social_Logger::log( $network, $source, $variant_id, 'error', $result['code'], $result['message'] );

		if ( 1 === $attempt ) {
			VD_Social_Scheduler::enqueue_publish( $variant_id, MINUTE_IN_SECONDS );
		} elseif ( 2 === $attempt ) {
			VD_Social_Scheduler::enqueue_publish( $variant_id, 5 * MINUTE_IN_SECONDS );
		} else {
			VD_Social_Variant::mark_error( $variant_id, $result['message'] );
		}
	}

	/**
	 * Despacha al publicador de la red construyendo mensaje/link/imagen.
	 *
	 * @return array{ok:bool,remote_id:string,code:string,message:string,retry_after?:int}
	 */
	private function dispatch( string $network, int $source, string $text ): array {
		if ( VD_Social_Variant::NET_X === $network ) {
			$url     = VD_Social_UTM::url( $source, $network );
			$message = '' !== $url ? $text . "\n\n" . $url : $text;
			return ( new VD_Social_X_Publisher() )->publish( $message );
		}

		if ( VD_Social_Variant::NET_FACEBOOK === $network ) {
			$url = VD_Social_UTM::url( $source, $network );
			return ( new VD_Social_Facebook_Publisher() )->publish( $text, $url );
		}

		if ( VD_Social_Variant::NET_INSTAGRAM === $network ) {
			$image = $this->instagram_image_url( $source );
			return ( new VD_Social_Instagram_Publisher() )->publish( $text, $image );
		}

		return array(
			'ok'        => false,
			'remote_id' => '',
			'code'      => '',
			'message'   => __( 'Red no soportada.', 'vd-social-pipeline' ),
		);
	}

	/**
	 * Imagen para Instagram: la placa (feed) si el toggle está activo y existe;
	 * si no, la imagen destacada en tamaño "large". Con el toggle apagado el
	 * comportamiento es idéntico al anterior.
	 */
	private function instagram_image_url( int $source ): string {
		if ( VD_Social_Options::get( 'placa_use_as_ig_image', false ) ) {
			$placa = (string) get_post_meta( $source, VD_Social_Placa_Storage::M_FEED_URL, true );
			if ( '' !== $placa ) {
				return $placa;
			}
		}
		return $this->featured_image_url( $source );
	}

	/**
	 * URL pública de la imagen destacada en tamaño "large" (para Instagram).
	 */
	private function featured_image_url( int $source ): string {
		$thumb_id = get_post_thumbnail_id( $source );
		if ( ! $thumb_id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $thumb_id, 'large' );
		return $src ? (string) $src : '';
	}
}
