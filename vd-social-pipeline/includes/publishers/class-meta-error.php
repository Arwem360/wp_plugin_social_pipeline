<?php
/**
 * Extrae un mensaje legible de un error de la Meta Graph API.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Meta_Error {

	/**
	 * @param mixed $body Respuesta ya decodificada de la Graph API.
	 */
	public static function message( $body ): string {
		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$err  = $body['error'];
			$msg  = isset( $err['message'] ) ? (string) $err['message'] : '';
			$code = isset( $err['code'] ) ? (string) $err['code'] : '';
			if ( '' !== $msg ) {
				return '' !== $code ? sprintf( '%s (code %s)', $msg, $code ) : $msg;
			}
		}
		return __( 'Error desconocido de la Graph API.', 'vd-social-pipeline' );
	}
}
