<?php
/**
 * Almacenamiento de las placas en uploads/vd-placas/{YYYY}/{MM}/ y sus metas.
 *
 * Las rutas/URLs se guardan como meta de la NOTA origen (siempre disponible,
 * aun antes de que existan las variantes del pipeline). La cola las muestra
 * junto a la variante de Instagram de esa nota.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Storage {

	public const M_FEED_PATH  = '_vd_social_placa_feed_path';
	public const M_FEED_URL   = '_vd_social_placa_feed_url';
	public const M_STORY_PATH = '_vd_social_placa_story_path';
	public const M_STORY_URL  = '_vd_social_placa_story_url';
	public const M_LOWRES     = '_vd_social_placa_lowres';
	public const M_NOIMAGE    = '_vd_social_placa_noimage';
	public const M_ENGINE     = '_vd_social_placa_engine';
	public const M_GENERATED  = '_vd_social_placa_generated_at';

	/**
	 * Carpeta destino (crea el subdirectorio por año/mes). Devuelve dir + url base.
	 *
	 * @return array{dir:string,url:string}|WP_Error
	 */
	public static function target_dir(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array( 'dir' => '', 'url' => '' );
		}
		$sub  = 'vd-placas/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$dir  = trailingslashit( $uploads['basedir'] ) . $sub;
		$url  = trailingslashit( $uploads['baseurl'] ) . $sub;
		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'dir' => '', 'url' => '' );
		}
		return array(
			'dir' => trailingslashit( $dir ),
			'url' => trailingslashit( $url ),
		);
	}

	public static function feed_filename( int $post_id ): string {
		return 'placa-' . $post_id . '.jpg';
	}

	public static function story_filename( int $post_id ): string {
		return 'placa-' . $post_id . '-story.jpg';
	}

	/**
	 * Persiste las rutas/URLs y flags en la meta de la nota.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function save_meta( int $post_id, array $data ): void {
		update_post_meta( $post_id, self::M_FEED_PATH, (string) ( $data['feed_path'] ?? '' ) );
		update_post_meta( $post_id, self::M_FEED_URL, (string) ( $data['feed_url'] ?? '' ) );
		update_post_meta( $post_id, self::M_STORY_PATH, (string) ( $data['story_path'] ?? '' ) );
		update_post_meta( $post_id, self::M_STORY_URL, (string) ( $data['story_url'] ?? '' ) );
		update_post_meta( $post_id, self::M_LOWRES, ! empty( $data['lowres'] ) ? 1 : 0 );
		update_post_meta( $post_id, self::M_NOIMAGE, ! empty( $data['noimage'] ) ? 1 : 0 );
		update_post_meta( $post_id, self::M_ENGINE, (string) ( $data['engine'] ?? '' ) );
		update_post_meta( $post_id, self::M_GENERATED, time() );
	}

	/**
	 * Lee el estado de placas de una nota para la UI.
	 *
	 * @return array<string,mixed>
	 */
	public static function read_meta( int $post_id ): array {
		return array(
			'feed_url'   => (string) get_post_meta( $post_id, self::M_FEED_URL, true ),
			'story_url'  => (string) get_post_meta( $post_id, self::M_STORY_URL, true ),
			'feed_path'  => (string) get_post_meta( $post_id, self::M_FEED_PATH, true ),
			'story_path' => (string) get_post_meta( $post_id, self::M_STORY_PATH, true ),
			'lowres'     => (bool) get_post_meta( $post_id, self::M_LOWRES, true ),
			'noimage'    => (bool) get_post_meta( $post_id, self::M_NOIMAGE, true ),
			'engine'     => (string) get_post_meta( $post_id, self::M_ENGINE, true ),
			'generated'  => (int) get_post_meta( $post_id, self::M_GENERATED, true ),
		);
	}

	public static function has_placas( int $post_id ): bool {
		$feed = (string) get_post_meta( $post_id, self::M_FEED_PATH, true );
		return '' !== $feed && is_readable( $feed );
	}
}
