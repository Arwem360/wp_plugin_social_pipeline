<?php
/**
 * Resolución de credenciales: si existe la constante en wp-config.php tiene
 * prioridad sobre lo guardado en la base de datos.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Credentials {

	/**
	 * Mapa clave-de-option => nombre de constante que la puede sobrescribir.
	 *
	 * @var array<string,string>
	 */
	private static array $const_map = array(
		'gemini_api_key'        => 'VD_GEMINI_API_KEY',
		'x_consumer_key'        => 'VD_X_CONSUMER_KEY',
		'x_consumer_secret'     => 'VD_X_CONSUMER_SECRET',
		'x_access_token'        => 'VD_X_ACCESS_TOKEN',
		'x_access_token_secret' => 'VD_X_ACCESS_TOKEN_SECRET',
		'meta_page_id'          => 'VD_META_PAGE_ID',
		'meta_ig_user_id'       => 'VD_META_IG_USER_ID',
		'meta_access_token'     => 'VD_META_ACCESS_TOKEN',
	);

	/**
	 * Devuelve el valor efectivo de una clave: constante si existe, si no la option.
	 */
	public static function get( string $key ): string {
		if ( isset( self::$const_map[ $key ] ) ) {
			$const = self::$const_map[ $key ];
			if ( defined( $const ) ) {
				return (string) constant( $const );
			}
		}
		return (string) VD_Social_Options::get( $key, '' );
	}

	/**
	 * ¿La clave está definida por constante? (para mostrar el campo bloqueado).
	 */
	public static function is_constant( string $key ): bool {
		return isset( self::$const_map[ $key ] ) && defined( self::$const_map[ $key ] );
	}

	/**
	 * ¿Esta clave es un secreto gestionable por constante? (tiene entrada en el mapa).
	 */
	public static function is_managed( string $key ): bool {
		return isset( self::$const_map[ $key ] );
	}

	/**
	 * Nombre de la constante asociada a una clave (o cadena vacía).
	 */
	public static function constant_name( string $key ): string {
		return isset( self::$const_map[ $key ] ) ? self::$const_map[ $key ] : '';
	}
}
