<?php
/**
 * Generador de placas: elige el motor, renderiza las dos variantes, las guarda
 * y persiste las metas. Corre en background (nunca en el request de publicación).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Generator {

	private const JPEG_QUALITY = 90;

	/**
	 * ¿Se puede generar? (fuentes presentes y algún motor disponible).
	 */
	public static function can_render(): bool {
		return VD_Social_Placa_Fonts::available() && ( VD_Social_Placa_Canvas_Imagick::available() || VD_Social_Placa_Canvas_GD::available() );
	}

	public static function engine_name(): string {
		if ( VD_Social_Placa_Canvas_Imagick::available() ) {
			return 'imagick';
		}
		if ( VD_Social_Placa_Canvas_GD::available() ) {
			return 'gd';
		}
		return '';
	}

	/**
	 * Genera (o regenera) las dos placas de una nota.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'invalid_post', __( 'Nota no válida.', 'vd-social-pipeline' ) );
		}
		if ( ! VD_Social_Placa_Fonts::available() ) {
			return new WP_Error( 'no_fonts', __( 'Faltan las fuentes en assets/fonts/.', 'vd-social-pipeline' ) );
		}
		$engine = self::engine_name();
		if ( '' === $engine ) {
			return new WP_Error( 'no_engine', __( 'No hay Imagick ni GD disponibles en el servidor.', 'vd-social-pipeline' ) );
		}

		$target = VD_Social_Placa_Storage::target_dir();
		if ( '' === $target['dir'] ) {
			return new WP_Error( 'no_dir', __( 'No se pudo crear la carpeta de placas en uploads.', 'vd-social-pipeline' ) );
		}

		$data    = VD_Social_Placa_Data::build( $post_id );
		$noimage = ( '' === (string) $data['image_path'] );
		$lowres  = ( ! $noimage && (int) $data['image_width'] > 0 && (int) $data['image_width'] < 1080 );

		$renderer = new VD_Social_Placa_Renderer();

		$variants = array(
			'feed'  => array(
				'cfg'  => VD_Social_Placa_Renderer::cfg_feed(),
				'file' => VD_Social_Placa_Storage::feed_filename( $post_id ),
			),
			'story' => array(
				'cfg'  => VD_Social_Placa_Renderer::cfg_story(),
				'file' => VD_Social_Placa_Storage::story_filename( $post_id ),
			),
		);

		$saved = array();
		foreach ( $variants as $key => $v ) {
			$canvas = $this->make_canvas( (int) $v['cfg']['w'], (int) $v['cfg']['h'], $engine );
			if ( null === $canvas ) {
				return new WP_Error( 'canvas_fail', __( 'No se pudo inicializar el motor de imagen.', 'vd-social-pipeline' ) );
			}
			$renderer->render( $canvas, $data, $v['cfg'] );
			$path = $target['dir'] . $v['file'];
			$ok   = $canvas->save_jpeg( $path, self::JPEG_QUALITY );
			$canvas->destroy();
			if ( ! $ok ) {
				return new WP_Error( 'save_fail', __( 'No se pudo guardar la placa.', 'vd-social-pipeline' ) );
			}
			$saved[ $key ] = array(
				'path' => $path,
				'url'  => $target['url'] . $v['file'],
			);
		}

		VD_Social_Placa_Storage::save_meta(
			$post_id,
			array(
				'feed_path'  => $saved['feed']['path'],
				'feed_url'   => $saved['feed']['url'],
				'story_path' => $saved['story']['path'],
				'story_url'  => $saved['story']['url'],
				'lowres'     => $lowres,
				'noimage'    => $noimage,
				'engine'     => $engine,
			)
		);

		$note = $noimage ? 'sin imagen destacada (fondo de marca)' : ( $lowres ? 'imagen de origen de baja resolución' : 'OK' );
		VD_Social_Logger::log( 'placa', $post_id, 0, $noimage || $lowres ? 'info' : 'ok', $engine, 'Placas generadas: ' . $note );

		return array(
			'feed_url'  => $saved['feed']['url'],
			'story_url' => $saved['story']['url'],
			'lowres'    => $lowres,
			'noimage'   => $noimage,
			'engine'    => $engine,
		);
	}

	/**
	 * @return VD_Social_Placa_Canvas|null
	 */
	private function make_canvas( int $w, int $h, string $engine ) {
		try {
			if ( 'imagick' === $engine ) {
				return new VD_Social_Placa_Canvas_Imagick( $w, $h );
			}
			return new VD_Social_Placa_Canvas_GD( $w, $h );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
