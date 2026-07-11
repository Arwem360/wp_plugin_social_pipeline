<?php
/**
 * Motor de dibujo Imagick (preferido: mejor texto y degradados).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Canvas_Imagick extends VD_Social_Placa_Canvas {

	private Imagick $img;

	public function __construct( int $w, int $h ) {
		$this->w   = $w;
		$this->h   = $h;
		$this->img = new Imagick();
		$this->img->newImage( $w, $h, new ImagickPixel( 'black' ) );
		$this->img->setImageFormat( 'png' );
		$this->img->setImageColorspace( Imagick::COLORSPACE_SRGB );
	}

	public static function available(): bool {
		return extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
	}

	private function rgba( string $hex, int $alpha = 255 ): string {
		list( $r, $g, $b ) = self::hex2rgb( $hex );
		$a                 = max( 0, min( 255, $alpha ) ) / 255;
		return sprintf( 'rgba(%d,%d,%d,%.4f)', $r, $g, $b, $a );
	}

	public function draw_cover( string $path ): bool {
		try {
			$src = new Imagick( $path );
			$src->setImageColorspace( Imagick::COLORSPACE_SRGB );
			// Cover exacto: escala manteniendo aspecto y recorta centrado.
			$src->cropThumbnailImage( $this->w, $this->h );
			$src->setImagePage( $this->w, $this->h, 0, 0 );
			$this->img->compositeImage( $src, Imagick::COMPOSITE_OVER, 0, 0 );
			$src->destroy();
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function draw_png( string $path, int $x, int $y, int $max_h ): bool {
		try {
			$logo = new Imagick( $path );
			$logo->scaleImage( 0, $max_h );
			$this->img->compositeImage( $logo, Imagick::COMPOSITE_OVER, $x, $y );
			$logo->destroy();
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function fill( string $hex ): void {
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( $this->rgba( $hex ) ) );
		$draw->rectangle( 0, 0, $this->w, $this->h );
		$this->img->drawImage( $draw );
		$draw->destroy();
	}

	public function fill_rect( int $x, int $y, int $w, int $h, string $hex, int $alpha = 255 ): void {
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( $this->rgba( $hex, $alpha ) ) );
		$draw->rectangle( $x, $y, $x + $w - 1, $y + $h - 1 );
		$this->img->drawImage( $draw );
		$draw->destroy();
	}

	public function stroke_rect( int $x, int $y, int $w, int $h, string $hex, int $thickness, int $alpha = 255 ): void {
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( 'transparent' ) );
		$draw->setStrokeColor( new ImagickPixel( $this->rgba( $hex, $alpha ) ) );
		$draw->setStrokeWidth( max( 1, $thickness ) );
		// Ajuste de medio grosor para que el filete quede dentro del rect.
		$off = max( 1, $thickness ) / 2;
		$draw->rectangle( $x + $off, $y + $off, $x + $w - 1 - $off, $y + $h - 1 - $off );
		$this->img->drawImage( $draw );
		$draw->destroy();
	}

	public function line( int $x1, int $y1, int $x2, int $y2, string $hex, int $thickness ): void {
		$draw = new ImagickDraw();
		$draw->setStrokeColor( new ImagickPixel( $this->rgba( $hex ) ) );
		$draw->setStrokeWidth( max( 1, $thickness ) );
		$draw->line( $x1, $y1, $x2, $y2 );
		$this->img->drawImage( $draw );
		$draw->destroy();
	}

	public function polygon( array $points, string $hex, int $alpha = 255 ): void {
		if ( count( $points ) < 3 ) {
			return;
		}
		$pts = array();
		foreach ( $points as $p ) {
			$pts[] = array(
				'x' => (float) $p[0],
				'y' => (float) $p[1],
			);
		}
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( $this->rgba( $hex, $alpha ) ) );
		$draw->polygon( $pts );
		$this->img->drawImage( $draw );
		$draw->destroy();
	}

	public function gradient_overlay( int $y0, int $y1, string $hex, int $alpha_top, int $alpha_bottom ): void {
		$y0   = max( 0, $y0 );
		$y1   = min( $this->h, $y1 );
		$span = max( 1, $y1 - $y0 );
		try {
			$grad = new Imagick();
			$grad->newPseudoImage( $this->w, $span, 'gradient:' . $this->rgba( $hex, $alpha_top ) . '-' . $this->rgba( $hex, $alpha_bottom ) );
			$this->img->compositeImage( $grad, Imagick::COMPOSITE_OVER, 0, $y0 );
			$grad->destroy();
		} catch ( Exception $e ) {
			$this->fill_rect( 0, $y0, $this->w, $span, $hex, $alpha_bottom );
		}
		if ( $y1 < $this->h ) {
			$this->fill_rect( 0, $y1, $this->w, $this->h - $y1, $hex, $alpha_bottom );
		}
	}

	public function vignette( float $strength ): void {
		$s = max( 0.0, min( 1.0, $strength ) );
		try {
			$v = new Imagick();
			$v->newPseudoImage( $this->w, $this->h, 'radial-gradient:' . $this->rgba( '#000000', 0 ) . '-' . $this->rgba( '#000000', (int) round( $s * 255 ) ) );
			$this->img->compositeImage( $v, Imagick::COMPOSITE_OVER, 0, 0 );
			$v->destroy();
		} catch ( Exception $e ) {
			return; // La viñeta es cosmética; si el pseudo-image no está, se omite.
		}
	}

	/**
	 * @return array{width:int,height:int,ink_top:int,ink_left:int}
	 */
	public function measure( string $text, string $font, int $size ): array {
		$draw = new ImagickDraw();
		$draw->setFont( $font );
		$draw->setFontSize( $size );
		$m = $this->img->queryFontMetrics( $draw, $text );
		$draw->destroy();

		if ( isset( $m['boundingBox'] ) && isset( $m['boundingBox']['x2'] ) ) {
			$bb = $m['boundingBox'];
			return array(
				'width'    => (int) round( $bb['x2'] - $bb['x1'] ),
				'height'   => (int) round( $bb['y2'] - $bb['y1'] ),
				'ink_top'  => (int) round( - $m['ascender'] ), // fallback si bbox raro.
				'ink_left' => (int) round( $bb['x1'] ),
			);
		}
		return array(
			'width'    => (int) round( $m['textWidth'] ),
			'height'   => (int) round( $m['textHeight'] ),
			'ink_top'  => (int) round( - $m['ascender'] ),
			'ink_left' => 0,
		);
	}

	/**
	 * Igual que measure pero devuelve el y de la tinta según boundingBox (más fiel).
	 */
	private function ink_top_real( string $text, string $font, int $size ): int {
		$draw = new ImagickDraw();
		$draw->setFont( $font );
		$draw->setFontSize( $size );
		$m = $this->img->queryFontMetrics( $draw, $text );
		$draw->destroy();
		if ( isset( $m['boundingBox']['y1'] ) ) {
			return (int) round( $m['boundingBox']['y1'] );
		}
		return (int) round( - $m['ascender'] );
	}

	public function text_line( int $x, int $y_top, string $text, string $font, int $size, string $hex, int $alpha = 255 ): void {
		$m       = $this->measure( $text, $font, $size );
		$ink_top = $this->ink_top_real( $text, $font, $size );
		$pen_x   = $x - $m['ink_left'];
		$base_y  = $y_top - $ink_top;
		$draw    = new ImagickDraw();
		$draw->setFont( $font );
		$draw->setFontSize( $size );
		$draw->setFillColor( new ImagickPixel( $this->rgba( $hex, $alpha ) ) );
		$this->img->annotateImage( $draw, $pen_x, $base_y, 0, $text );
		$draw->destroy();
	}

	public function text_gradient_line(
		int $x,
		int $y_top,
		string $text,
		string $font,
		int $size,
		string $hex_top,
		string $hex_bottom,
		string $outline_hex,
		int $outline_px,
		int $shadow_dx,
		int $shadow_dy,
		string $shadow_hex,
		int $shadow_alpha
	): void {
		$m = $this->measure( $text, $font, $size );
		if ( $m['width'] < 1 || $m['height'] < 1 ) {
			return;
		}
		$ink_top = $this->ink_top_real( $text, $font, $size );
		$pad     = $outline_px + max( abs( $shadow_dx ), abs( $shadow_dy ) ) + 6;
		$lw      = $m['width'] + 2 * $pad;
		$lh      = $m['height'] + 2 * $pad;
		$pen_x   = $pad - $m['ink_left'];
		$base_y  = $pad - $ink_top; // baseline dentro del tile.

		try {
			$out = new Imagick();
			$out->newImage( $lw, $lh, new ImagickPixel( 'transparent' ) );
			$out->setImageFormat( 'png' );

			// 1) Sombra.
			if ( 0 !== $shadow_dx || 0 !== $shadow_dy ) {
				$sd = new ImagickDraw();
				$sd->setFont( $font );
				$sd->setFontSize( $size );
				$sd->setFillColor( new ImagickPixel( $this->rgba( $shadow_hex, $shadow_alpha ) ) );
				$out->annotateImage( $sd, $pen_x + $shadow_dx, $base_y + $shadow_dy, 0, $text );
				$sd->destroy();
			}

			// 2) Contorno: texto grueso con stroke del color del contorno.
			if ( $outline_px > 0 ) {
				$od = new ImagickDraw();
				$od->setFont( $font );
				$od->setFontSize( $size );
				$od->setFillColor( new ImagickPixel( $this->rgba( $outline_hex ) ) );
				$od->setStrokeColor( new ImagickPixel( $this->rgba( $outline_hex ) ) );
				$od->setStrokeWidth( $outline_px * 2 );
				$out->annotateImage( $od, $pen_x, $base_y, 0, $text );
				$od->destroy();
			}

			// 3) Relleno con degradado a través de la máscara del texto.
			$grad = new Imagick();
			$grad->newImage( $lw, $lh, new ImagickPixel( 'transparent' ) );
			$core = new Imagick();
			$core->newPseudoImage( $lw, max( 1, $m['height'] ), 'gradient:' . $hex_top . '-' . $hex_bottom );
			$grad->compositeImage( $core, Imagick::COMPOSITE_OVER, 0, $pad );
			$core->destroy();

			$mask = new Imagick();
			$mask->newImage( $lw, $lh, new ImagickPixel( 'transparent' ) );
			$mask->setImageFormat( 'png' );
			$md = new ImagickDraw();
			$md->setFont( $font );
			$md->setFontSize( $size );
			$md->setFillColor( new ImagickPixel( 'white' ) );
			$mask->annotateImage( $md, $pen_x, $base_y, 0, $text );
			$md->destroy();

			$grad->compositeImage( $mask, Imagick::COMPOSITE_DSTIN, 0, 0 );
			$mask->destroy();

			$out->compositeImage( $grad, Imagick::COMPOSITE_OVER, 0, 0 );
			$grad->destroy();

			// Volcar sobre el lienzo.
			$this->img->compositeImage( $out, Imagick::COMPOSITE_OVER, $x - $pad, $y_top - $pad );
			$out->destroy();
		} catch ( Exception $e ) {
			// Fallback: texto plano si algo del pipeline de degradado falla.
			$this->text_line( $x, $y_top, $text, $font, $size, $hex_top );
		}
	}

	public function save_jpeg( string $path, int $quality ): bool {
		try {
			$flat = new Imagick();
			$flat->newImage( $this->w, $this->h, new ImagickPixel( 'black' ) );
			$flat->compositeImage( $this->img, Imagick::COMPOSITE_OVER, 0, 0 );
			$flat->setImageFormat( 'jpeg' );
			$flat->setImageCompression( Imagick::COMPRESSION_JPEG );
			$flat->setImageCompressionQuality( $quality );
			$ok = $flat->writeImage( $path );
			$flat->destroy();
			return (bool) $ok;
		} catch ( Exception $e ) {
			return false;
		}
	}

	public function destroy(): void {
		if ( isset( $this->img ) ) {
			$this->img->destroy();
		}
	}
}
