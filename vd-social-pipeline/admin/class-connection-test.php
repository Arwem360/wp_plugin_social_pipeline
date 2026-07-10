<?php
/**
 * "Probar conexión" por servicio (admin-ajax). Devuelve JSON con el resultado.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Connection_Test {

	public function register_hooks(): void {
		add_action( 'wp_ajax_vd_social_test', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'vd-social-pipeline' ) ) );
		}
		check_ajax_referer( 'vd_social_test', 'nonce' );

		$service = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';

		if ( 'gemini' === $service ) {
			$result = ( new VD_Social_Gemini_Client() )->test();
		} elseif ( 'x' === $service ) {
			$result = ( new VD_Social_X_Publisher() )->test();
		} elseif ( 'facebook' === $service ) {
			$result = ( new VD_Social_Facebook_Publisher() )->test();
		} elseif ( 'instagram' === $service ) {
			$result = ( new VD_Social_Instagram_Publisher() )->test();
		} else {
			wp_send_json_error( array( 'message' => __( 'Servicio desconocido.', 'vd-social-pipeline' ) ) );
		}

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
}
