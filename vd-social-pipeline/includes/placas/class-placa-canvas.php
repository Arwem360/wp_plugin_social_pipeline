<?php
/**
 * Capa de dibujo abstracta. Dos implementaciones: Imagick (preferida) y GD.
 *
 * Toda la geometría del layout se calcula una sola vez (VD_Social_Placa_Renderer)
 * y se dibuja a través de estas primitivas, para no duplicar la lógica.
 *
 * Convenciones:
 * - Colores en hex "#RRGGBB".
 * - Alpha en 0..255 (255 = opaco).
 * - El texto se posiciona por su bounding box de tinta real (ink top-left),
 *   no por la línea base nominal (regla "centrado por bbox real" de la spec).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class VD_Social_Placa_Canvas {

	protected int $w;
	protected int $h;

	public function width(): int {
		return $this->w;
	}

	public function height(): int {
		return $this->h;
	}

	/**
	 * Convierte "#RRGGBB" a [r,g,b].
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	public static function hex2rgb( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	// --- Primitivas que cada motor implementa ---------------------------------

	abstract public function draw_cover( string $path ): bool;

	/**
	 * Dibuja un PNG (con alpha) escalado a una altura máxima, en (x, y).
	 */
	abstract public function draw_png( string $path, int $x, int $y, int $max_h ): bool;

	abstract public function fill( string $hex ): void;

	abstract public function fill_rect( int $x, int $y, int $w, int $h, string $hex, int $alpha = 255 ): void;

	abstract public function stroke_rect( int $x, int $y, int $w, int $h, string $hex, int $thickness, int $alpha = 255 ): void;

	abstract public function line( int $x1, int $y1, int $x2, int $y2, string $hex, int $thickness ): void;

	/**
	 * @param array<int,array{0:int,1:int}> $points
	 */
	abstract public function polygon( array $points, string $hex, int $alpha = 255 ): void;

	/**
	 * Gradiente vertical de un color sólido: alpha va de $alpha_top (en $y0) a
	 * $alpha_bottom (en $y1); debajo de $y1 queda sólido a $alpha_bottom.
	 */
	abstract public function gradient_overlay( int $y0, int $y1, string $hex, int $alpha_top, int $alpha_bottom ): void;

	abstract public function vignette( float $strength ): void;

	/**
	 * Métricas de tinta reales del texto.
	 *
	 * @return array{width:int,height:int,ink_top:int,ink_left:int}
	 */
	abstract public function measure( string $text, string $font, int $size ): array;

	/**
	 * Métricas VERTICALES de tinta reales (alto y desplazamiento respecto de la
	 * base), medidas de forma fiable para centrar texto en cajas/cintas/franja.
	 *
	 * @return array{height:int,ink_top:int}
	 */
	abstract public function vmetrics( string $text, string $font, int $size ): array;

	abstract public function text_line( int $x, int $y_top, string $text, string $font, int $size, string $hex, int $alpha = 255 ): void;

	/**
	 * Centra un texto (por bbox real) dentro de una caja.
	 */
	public function text_centered( string $text, string $font, int $size, int $bx, int $by, int $bw, int $bh, string $hex ): void {
		$adv = $this->measure( $text, $font, $size )['width'];
		$vm  = $this->vmetrics( $text, $font, $size );
		$x   = $bx + (int) round( ( $bw - $adv ) / 2 );
		$y   = $by + (int) round( ( $bh - $vm['height'] ) / 2 );
		$this->text_line( $x, $y, $text, $font, $size, $hex );
	}

	/**
	 * Texto con relleno degradado vertical + contorno + sombra (título y marcador).
	 * Se posiciona por ink top-left en ($x, $y_top).
	 */
	abstract public function text_gradient_line(
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
	): void;

	abstract public function save_jpeg( string $path, int $quality ): bool;

	abstract public function destroy(): void;
}
