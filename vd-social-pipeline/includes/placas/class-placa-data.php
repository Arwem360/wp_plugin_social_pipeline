<?php
/**
 * Recolecta y normaliza todos los datos de una nota para renderizar su placa.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Data {

	/**
	 * @return array<string,mixed>
	 */
	public static function build( int $post_id ): array {
		$post = get_post( $post_id );

		$accent = (string) VD_Social_Options::get( 'placa_accent', '#E8590C' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
			$accent = '#E8590C';
		}

		$logo_path = '';
		$logo_id   = (int) VD_Social_Options::get( 'placa_logo_id', 0 );
		if ( $logo_id > 0 ) {
			$file = get_attached_file( $logo_id );
			if ( $file && is_readable( $file ) ) {
				$logo_path = $file;
			}
		}

		$show_date = (bool) VD_Social_Options::get( 'placa_show_date', true );

		return array(
			'post_id'       => $post_id,
			'title'         => $post ? wp_strip_all_tags( get_the_title( $post ) ) : '',
			'category'      => self::primary_category( $post_id ),
			'show_category' => (bool) VD_Social_Options::get( 'placa_show_category', false ),
			'date'          => ( $show_date && $post ) ? self::format_date( $post ) : '',
			'show_date'     => $show_date,
			'image_path'    => self::featured_path( $post_id ),
			'image_width'   => self::featured_width( $post_id ),
			'score'         => (string) get_post_meta( $post_id, VD_Social_Placa_Metabox::M_SCORE, true ),
			'score_sub'     => (string) get_post_meta( $post_id, VD_Social_Placa_Metabox::M_SCORE_SUB, true ),
			'accent'        => $accent,
			'accent_dark'   => self::darken( $accent, 0.60 ),
			'accent_light'  => self::lighten( $accent, 0.42 ),
			'handle_domain' => (string) VD_Social_Options::get( 'placa_handle_domain', '@vdeportivo · vermouth-deportivo.com.ar' ),
			'logo_path'     => $logo_path,
		);
	}

	private static function primary_category( int $post_id ): string {
		$terms = get_the_terms( $post_id, 'category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		$first = reset( $terms );
		return $first ? self::upper( $first->name ) : '';
	}

	private static function format_date( WP_Post $post ): string {
		// "10 de julio de 2026" (según locale del sitio).
		return date_i18n( 'j \d\e F \d\e Y', get_post_time( 'U', false, $post ) );
	}

	private static function featured_path( int $post_id ): string {
		$thumb = get_post_thumbnail_id( $post_id );
		if ( ! $thumb ) {
			return '';
		}
		$file = get_attached_file( $thumb );
		return ( $file && is_readable( $file ) ) ? $file : '';
	}

	private static function featured_width( int $post_id ): int {
		$thumb = get_post_thumbnail_id( $post_id );
		if ( ! $thumb ) {
			return 0;
		}
		$meta = wp_get_attachment_metadata( $thumb );
		return isset( $meta['width'] ) ? (int) $meta['width'] : 0;
	}

	public static function upper( string $text ): string {
		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $text, 'UTF-8' );
		}
		return strtoupper( $text );
	}

	private static function darken( string $hex, float $factor ): string {
		list( $r, $g, $b ) = VD_Social_Placa_Canvas::hex2rgb( $hex );
		return sprintf( '#%02x%02x%02x', (int) round( $r * $factor ), (int) round( $g * $factor ), (int) round( $b * $factor ) );
	}

	private static function lighten( string $hex, float $amount ): string {
		list( $r, $g, $b ) = VD_Social_Placa_Canvas::hex2rgb( $hex );
		$r = (int) round( $r + ( 255 - $r ) * $amount );
		$g = (int) round( $g + ( 255 - $g ) * $amount );
		$b = (int) round( $b + ( 255 - $b ) * $amount );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}
}
