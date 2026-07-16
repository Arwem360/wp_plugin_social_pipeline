<?php
/**
 * Cache de escudos: descarga el logo de cada equipo a la librería de medios UNA
 * sola vez (los logos de API-Football son estables por team id) y reutiliza el
 * adjunto en cada sync. Devuelve el ID de adjunto para guardarlo en el meta que
 * el tema ya usa (fixture_home_logo_id / fixture_away_logo_id).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Fixtures_Logos {

	/** Option que mapea team id (API) => attachment id (WP). */
	private const MAP_OPTION = 'vd_fixtures_team_logos';

	/**
	 * Devuelve el ID de adjunto del escudo de un equipo, descargándolo si hace
	 * falta. 0 si no se pudo obtener.
	 *
	 * @param int    $team_id  ID del equipo en la API.
	 * @param string $logo_url URL del escudo.
	 * @param string $name     Nombre del equipo (para alt/título).
	 */
	public static function attachment_for_team( int $team_id, string $logo_url, string $name ): int {
		if ( $team_id <= 0 || '' === $logo_url ) {
			return 0;
		}

		$map = self::map();

		// ¿Ya lo tenemos y el adjunto sigue existiendo?
		if ( isset( $map[ $team_id ] ) ) {
			$existing = (int) $map[ $team_id ];
			if ( $existing > 0 && 'attachment' === get_post_type( $existing ) ) {
				return $existing;
			}
			// El adjunto se borró: lo sacamos del mapa y re-descargamos.
			unset( $map[ $team_id ] );
		}

		$attachment_id = self::sideload( $logo_url, $name );
		if ( $attachment_id > 0 ) {
			$map[ $team_id ] = $attachment_id;
			update_option( self::MAP_OPTION, $map, false );
		}

		return $attachment_id;
	}

	/**
	 * Descarga una imagen a la librería de medios y devuelve su attachment id.
	 */
	private static function sideload( string $url, string $name ): int {
		// Estas funciones no están cargadas en contexto de cron: las incluimos.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, self::download_timeout() );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		// media_handle_sideload usa el nombre del archivo; forzamos una extensión
		// válida a partir de la URL (los logos suelen ser .png).
		$filename = self::filename_from_url( $url, $name );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			$name,
			array(
				'post_title'   => $name,
				'post_excerpt' => $name,
				'post_content' => '',
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return 0;
		}

		// Texto alternativo accesible.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $name );

		return (int) $attachment_id;
	}

	/**
	 * @return array<int,int> team id => attachment id
	 */
	private static function map(): array {
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	private static function download_timeout(): int {
		return 15;
	}

	private static function filename_from_url( string $url, string $name ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = $path ? pathinfo( $path, PATHINFO_EXTENSION ) : '';
		$ext  = preg_match( '/^(png|jpe?g|gif|webp|svg)$/i', (string) $ext ) ? strtolower( $ext ) : 'png';
		$slug = sanitize_title( $name );
		$slug = '' !== $slug ? $slug : 'escudo';
		return $slug . '.' . $ext;
	}
}
