<?php
/**
 * Construcción de URLs con parámetros UTM. Función única y reutilizable.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_UTM {

	/**
	 * Devuelve el permalink de la nota con los UTM de la red indicada.
	 *
	 * ?utm_source={red}&utm_medium=social&utm_campaign=pipeline&utm_content={post_id}
	 */
	public static function url( int $post_id, string $network ): string {
		$base = get_permalink( $post_id );
		if ( ! $base ) {
			return '';
		}
		return add_query_arg(
			array(
				'utm_source'   => rawurlencode( $network ),
				'utm_medium'   => 'social',
				'utm_campaign' => 'pipeline',
				'utm_content'  => $post_id,
			),
			$base
		);
	}
}
