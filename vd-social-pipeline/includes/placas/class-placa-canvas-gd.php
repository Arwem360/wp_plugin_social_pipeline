<?php
/**
 * Motor de dibujo GD (fallback cuando no hay Imagick).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Canvas_GD extends VD_Social_Placa_Canvas {

	/** @var resource|\GdImage */
	private $img;

	public function __construct( int $w, int $h ) {
		$this->w   = $w;
		$this->h   = $h;
		$this->img = imagecreatetruecolor( $w, $h );
		imagealphablending( $this->img, false );
		imagesavealpha( $this->img, true );
		$black = imagecolorallocate( $this->img, 0, 0, 0 );
		imagefilledrectangle( $this->img, 0, 0, $w, $h, $black );
		imagealphablending( $this->img, true );
	}

	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' );
	}

	/**
	 * Alpha 0..255 (255 opaco) → alpha GD 0..127 (0 opaco).
	 */
	private function gd_alpha( int $alpha ): int {
		$alpha = max( 0, min( 255, $alpha ) );
		return (int) round( ( 255 - $alpha ) / 255 * 127 );
	}

	/**
	 * @return int Color GD asignado.
	 */
	private function color( string $hex, int $alpha = 255 ) {
		list( $r, $g, $b ) = self::hex2rgb( $hex );
		return imagecolorallocatealpha( $this->img, $r, $g, $b, $this->gd_alpha( $alpha ) );
	}

	public function draw_cover( string $path ): bool {
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return false;
		}
		$src = @imagecreatefromstring( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $src ) {
			return false;
		}
		$sw = imagesx( $src );
		$sh = imagesy( $src );
		if ( $sw < 1 || $sh < 1 ) {
			imagedestroy( $src );
			return false;
		}
		// Cover: escalar por el mayor factor y recortar centrado.
		$scale = max( $this->w / $sw, $this->h / $sh );
		$nw    = (int) ceil( $sw * $scale );
		$nh    = (int) ceil( $sh * $scale );
		$dx    = (int) round( ( $this->w - $nw ) / 2 );
		$dy    = (int) round( ( $this->h - $nh ) / 2 );
		imagealphablending( $this->img, false );
		imagecopyresampled( $this->img, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh );
		imagealphablending( $this->img, true );
		imagedestroy( $src );
		return true;
	}

	public function draw_png( string $path, int $x, int $y, int $max_h ): bool {
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return false;
		}
		$src = @imagecreatefromstring( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $src ) {
			return false;
		}
		$sw = imagesx( $src );
		$sh = imagesy( $src );
		if ( $sh < 1 ) {
			imagedestroy( $src );
			return false;
		}
		$scale = $max_h / $sh;
		$nw    = max( 1, (int) round( $sw * $scale ) );
		$nh    = max( 1, (int) round( $sh * $scale ) );
		$tmp   = imagecreatetruecolor( $nw, $nh );
		imagealphablending( $tmp, false );
		imagesavealpha( $tmp, true );
		imagefilledrectangle( $tmp, 0, 0, $nw, $nh, imagecolorallocatealpha( $tmp, 0, 0, 0, 127 ) );
		imagecopyresampled( $tmp, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh );
		imagealphablending( $this->img, true );
		imagecopy( $this->img, $tmp, $x, $y, 0, 0, $nw, $nh );
		imagedestroy( $tmp );
		imagedestroy( $src );
		return true;
	}

	public function fill( string $hex ): void {
		imagealphablending( $this->img, false );
		imagefilledrectangle( $this->img, 0, 0, $this->w, $this->h, $this->color( $hex ) );
		imagealphablending( $this->img, true );
	}

	public function fill_rect( int $x, int $y, int $w, int $h, string $hex, int $alpha = 255 ): void {
		imagefilledrectangle( $this->img, $x, $y, $x + $w - 1, $y + $h - 1, $this->color( $hex, $alpha ) );
	}

	public function stroke_rect( int $x, int $y, int $w, int $h, string $hex, int $thickness, int $alpha = 255 ): void {
		$c = $this->color( $hex, $alpha );
		$t = max( 1, $thickness );
		// Cuatro barras para grosor exacto.
		imagefilledrectangle( $this->img, $x, $y, $x + $w - 1, $y + $t - 1, $c ); // top
		imagefilledrectangle( $this->img, $x, $y + $h - $t, $x + $w - 1, $y + $h - 1, $c ); // bottom
		imagefilledrectangle( $this->img, $x, $y, $x + $t - 1, $y + $h - 1, $c ); // left
		imagefilledrectangle( $this->img, $x + $w - $t, $y, $x + $w - 1, $y + $h - 1, $c ); // right
	}

	public function line( int $x1, int $y1, int $x2, int $y2, string $hex, int $thickness ): void {
		imagesetthickness( $this->img, max( 1, $thickness ) );
		imageline( $this->img, $x1, $y1, $x2, $y2, $this->color( $hex ) );
		imagesetthickness( $this->img, 1 );
	}

	public function polygon( array $points, string $hex, int $alpha = 255 ): void {
		$flat = array();
		foreach ( $points as $p ) {
			$flat[] = (int) $p[0];
			$flat[] = (int) $p[1];
		}
		$n = (int) ( count( $flat ) / 2 );
		if ( $n < 3 ) {
			return;
		}
		imagefilledpolygon( $this->img, $flat, $n, $this->color( $hex, $alpha ) );
	}

	public function gradient_overlay( int $y0, int $y1, string $hex, int $alpha_top, int $alpha_bottom ): void {
		list( $r, $g, $b ) = self::hex2rgb( $hex );
		$y0 = max( 0, $y0 );
		$y1 = min( $this->h, $y1 );
		$span = max( 1, $y1 - $y0 );
		for ( $y = $y0; $y < $y1; $y++ ) {
			$t = ( $y - $y0 ) / $span;
			$a = (int) round( $alpha_top + ( $alpha_bottom - $alpha_top ) * $t );
			$c = imagecolorallocatealpha( $this->img, $r, $g, $b, $this->gd_alpha( $a ) );
			imagefilledrectangle( $this->img, 0, $y, $this->w, $y, $c );
		}
		if ( $y1 < $this->h ) {
			$c = imagecolorallocatealpha( $this->img, $r, $g, $b, $this->gd_alpha( $alpha_bottom ) );
			imagefilledrectangle( $this->img, 0, $y1, $this->w, $this->h, $c );
		}
	}

	public function vignette( float $strength ): void {
		$cx     = $this->w / 2;
		$cy     = $this->h / 2;
		$maxd   = sqrt( $cx * $cx + $cy * $cy );
		$step   = 4;
		$str    = max( 0.0, min( 1.0, $strength ) );
		for ( $y = 0; $y < $this->h; $y += $step ) {
			for ( $x = 0; $x < $this->w; $x += $step ) {
				$d  = sqrt( ( $x - $cx ) * ( $x - $cx ) + ( $y - $cy ) * ( $y - $cy ) ) / $maxd;
				$dd = $d * $d; // curva suave hacia las esquinas.
				$a  = (int) round( $str * 255 * $dd );
				if ( $a <= 3 ) {
					continue;
				}
				$c = imagecolorallocatealpha( $this->img, 0, 0, 0, $this->gd_alpha( $a ) );
				imagefilledrectangle( $this->img, $x, $y, $x + $step - 1, $y + $step - 1, $c );
			}
		}
	}

	/**
	 * @return array{width:int,height:int,ink_top:int,ink_left:int}
	 */
	public function measure( string $text, string $font, int $size ): array {
		$bbox = imagettfbbox( $size, 0, $font, $text );
		if ( false === $bbox ) {
			return array( 'width' => 0, 'height' => 0, 'ink_top' => 0, 'ink_left' => 0 );
		}
		$left   = min( $bbox[0], $bbox[6] );
		$right  = max( $bbox[2], $bbox[4] );
		$top    = min( $bbox[5], $bbox[7] ); // negativo (arriba de la base).
		$bottom = max( $bbox[1], $bbox[3] );
		return array(
			'width'    => (int) round( $right - $left ),
			'height'   => (int) round( $bottom - $top ),
			'ink_top'  => (int) round( $top ),
			'ink_left' => (int) round( $left ),
		);
	}

	private function baseline_pen( int $x, int $y_top, array $m ): array {
		return array( $x - $m['ink_left'], $y_top - $m['ink_top'] );
	}

	public function text_line( int $x, int $y_top, string $text, string $font, int $size, string $hex, int $alpha = 255 ): void {
		$m = $this->measure( $text, $font, $size );
		list( $px, $py ) = $this->baseline_pen( $x, $y_top, $m );
		imagettftext( $this->img, $size, 0, $px, $py, $this->color( $hex, $alpha ), $font, $text );
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
		list( $px, $py ) = $this->baseline_pen( $x, $y_top, $m );

		// 1) Sombra (directa sobre el lienzo).
		if ( 0 !== $shadow_dx || 0 !== $shadow_dy ) {
			list( $sr, $sg, $sb ) = self::hex2rgb( $shadow_hex );
			$sc                   = imagecolorallocatealpha( $this->img, $sr, $sg, $sb, $this->gd_alpha( $shadow_alpha ) );
			imagettftext( $this->img, $size, 0, $px + $shadow_dx, $py + $shadow_dy, $sc, $font, $text );
		}

		// 2) Contorno (disco de radio outline_px).
		if ( $outline_px > 0 ) {
			list( $or, $og, $ob ) = self::hex2rgb( $outline_hex );
			$oc                   = imagecolorallocate( $this->img, $or, $og, $ob );
			$r2                   = $outline_px * $outline_px;
			for ( $ox = -$outline_px; $ox <= $outline_px; $ox++ ) {
				for ( $oy = -$outline_px; $oy <= $outline_px; $oy++ ) {
					if ( ( 0 === $ox && 0 === $oy ) || ( $ox * $ox + $oy * $oy ) > $r2 ) {
						continue;
					}
					imagettftext( $this->img, $size, 0, $px + $ox, $py + $oy, $oc, $font, $text );
				}
			}
		}

		// 3) Base sólida garantizada (color superior del degradado).
		list( $tr, $tg, $tb ) = self::hex2rgb( $hex_top );
		$base                 = imagecolorallocate( $this->img, $tr, $tg, $tb );
		imagettftext( $this->img, $size, 0, $px, $py, $base, $font, $text );

		// 4) Mejora: degradado vertical a través de la máscara del texto,
		// pintado directo sobre el lienzo (encima de la base).
		$pad  = 4;
		$lw   = $m['width'] + 2 * $pad;
		$lh   = $m['height'] + 2 * $pad;
		$mpx  = $pad - $m['ink_left'];
		$mpy  = $pad - $m['ink_top'];
		$mask = imagecreatetruecolor( $lw, $lh );
		imagealphablending( $mask, false );
		imagesavealpha( $mask, true );
		imagefilledrectangle( $mask, 0, 0, $lw, $lh, imagecolorallocatealpha( $mask, 0, 0, 0, 127 ) );
		imagealphablending( $mask, true );
		imagettftext( $mask, $size, 0, $mpx, $mpy, imagecolorallocate( $mask, 255, 255, 255 ), $font, $text );

		list( $br, $bg, $bb ) = self::hex2rgb( $hex_bottom );
		$ink_h                = max( 1, $m['height'] );
		for ( $yy = $pad; $yy < $pad + $m['height']; $yy++ ) {
			$cy = $y_top - $pad + $yy;
			if ( $cy < 0 || $cy >= $this->h ) {
				continue;
			}
			$t   = ( $yy - $pad ) / $ink_h;
			$rr  = (int) round( $tr + ( $br - $tr ) * $t );
			$gg  = (int) round( $tg + ( $bg - $tg ) * $t );
			$bbc = (int) round( $tb + ( $bb - $tb ) * $t );
			for ( $xx = 0; $xx < $lw; $xx++ ) {
				$ma = ( imagecolorat( $mask, $xx, $yy ) >> 24 ) & 0x7F;
				if ( $ma >= 120 ) {
					continue;
				}
				$cx = $x - $pad + $xx;
				if ( $cx < 0 || $cx >= $this->w ) {
					continue;
				}
				$c = imagecolorallocatealpha( $this->img, $rr, $gg, $bbc, $ma );
				imagesetpixel( $this->img, $cx, $cy, $c );
			}
		}
		imagedestroy( $mask );
	}

	public function save_jpeg( string $path, int $quality ): bool {
		$flat = imagecreatetruecolor( $this->w, $this->h );
		$bg   = imagecolorallocate( $flat, 0, 0, 0 );
		imagefilledrectangle( $flat, 0, 0, $this->w, $this->h, $bg );
		imagecopy( $flat, $this->img, 0, 0, 0, 0, $this->w, $this->h );
		$ok = imagejpeg( $flat, $path, $quality );
		imagedestroy( $flat );
		return (bool) $ok;
	}

	public function destroy(): void {
		if ( $this->img ) {
			imagedestroy( $this->img );
		}
	}
}
