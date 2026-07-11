<?php
/**
 * Rutas a las fuentes bundleadas (OFL) usadas por las placas.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Fonts {

	/** Anton: títulos y marcador (siempre en mayúsculas). */
	public static function anton(): string {
		return VD_SOCIAL_DIR . 'assets/fonts/Anton-Regular.ttf';
	}

	/** Bebas Neue: wordmark, cinta, fecha, subtítulo y franja. */
	public static function bebas(): string {
		return VD_SOCIAL_DIR . 'assets/fonts/BebasNeue-Regular.ttf';
	}

	/**
	 * ¿Están las dos fuentes presentes y legibles?
	 */
	public static function available(): bool {
		return is_readable( self::anton() ) && is_readable( self::bebas() );
	}
}
