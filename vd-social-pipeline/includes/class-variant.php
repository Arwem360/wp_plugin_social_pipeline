<?php
/**
 * Modelo/helper de una variante de posteo (post del CPT vd_social_post).
 *
 * El texto generado vive en post_content (editable en la cola). Todo lo demás
 * en post meta. El estado del flujo vive en meta, no en post_status.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Variant {

	// Redes soportadas.
	public const NET_X         = 'x';
	public const NET_FACEBOOK  = 'facebook';
	public const NET_INSTAGRAM = 'instagram';

	// Estados del flujo.
	public const STATUS_PENDING   = 'pendiente';
	public const STATUS_APPROVED  = 'aprobado';
	public const STATUS_PUBLISHED = 'publicado';
	public const STATUS_DISCARDED = 'descartado';
	public const STATUS_ERROR     = 'error';

	// Meta keys.
	public const M_SOURCE       = '_vd_social_source_post';
	public const M_NETWORK      = '_vd_social_network';
	public const M_STATUS       = '_vd_social_status';
	public const M_API_RESPONSE = '_vd_social_api_response';
	public const M_PUBLISHED_AT = '_vd_social_published_at';
	public const M_REMOTE_ID    = '_vd_social_remote_id';
	public const M_RETRIES      = '_vd_social_retries';
	public const M_ERROR        = '_vd_social_error';

	/**
	 * @return string[] Lista de redes válidas.
	 */
	public static function networks(): array {
		return array( self::NET_X, self::NET_FACEBOOK, self::NET_INSTAGRAM );
	}

	/**
	 * Etiqueta legible de una red.
	 */
	public static function network_label( string $network ): string {
		$labels = array(
			self::NET_X         => 'X (Twitter)',
			self::NET_FACEBOOK  => 'Facebook',
			self::NET_INSTAGRAM => 'Instagram',
		);
		return isset( $labels[ $network ] ) ? $labels[ $network ] : $network;
	}

	/**
	 * Crea una variante. Devuelve el ID nuevo o 0 si falló.
	 */
	public static function create( int $source_post, string $network, string $text, string $status = self::STATUS_PENDING ): int {
		$source = get_post( $source_post );
		$title  = $source ? $source->post_title : '';

		$id = wp_insert_post(
			array(
				'post_type'    => VD_Social_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sprintf( '[%s] %s', self::network_label( $network ), $title ),
				'post_content' => $text,
			),
			true
		);

		if ( is_wp_error( $id ) || 0 === (int) $id ) {
			return 0;
		}
		$id = (int) $id;

		update_post_meta( $id, self::M_SOURCE, $source_post );
		update_post_meta( $id, self::M_NETWORK, $network );
		update_post_meta( $id, self::M_STATUS, $status );
		update_post_meta( $id, self::M_RETRIES, 0 );

		return $id;
	}

	/**
	 * ¿Ya existe alguna variante para esta nota? (para evitar reprocesar).
	 */
	public static function exists_for_source( int $source_post ): bool {
		$q = new WP_Query(
			array(
				'post_type'      => VD_Social_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::M_SOURCE,
				'meta_value'     => $source_post,
			)
		);
		return $q->have_posts();
	}

	/**
	 * ¿Ya hay una variante "publicado" para esta nota y esta red? (anti-duplicado).
	 */
	public static function already_published( int $source_post, string $network, int $exclude_id = 0 ): bool {
		$q = new WP_Query(
			array(
				'post_type'      => VD_Social_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => $exclude_id ? array( $exclude_id ) : array(),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => self::M_SOURCE,
						'value' => $source_post,
					),
					array(
						'key'   => self::M_NETWORK,
						'value' => $network,
					),
					array(
						'key'   => self::M_STATUS,
						'value' => self::STATUS_PUBLISHED,
					),
				),
			)
		);
		return $q->have_posts();
	}

	public static function get_text( int $variant_id ): string {
		$post = get_post( $variant_id );
		return $post ? (string) $post->post_content : '';
	}

	public static function set_text( int $variant_id, string $text ): void {
		wp_update_post(
			array(
				'ID'           => $variant_id,
				'post_content' => $text,
			)
		);
	}

	public static function get_network( int $variant_id ): string {
		return (string) get_post_meta( $variant_id, self::M_NETWORK, true );
	}

	public static function get_source( int $variant_id ): int {
		return (int) get_post_meta( $variant_id, self::M_SOURCE, true );
	}

	public static function get_status( int $variant_id ): string {
		return (string) get_post_meta( $variant_id, self::M_STATUS, true );
	}

	public static function set_status( int $variant_id, string $status ): void {
		update_post_meta( $variant_id, self::M_STATUS, $status );
	}

	public static function get_retries( int $variant_id ): int {
		return (int) get_post_meta( $variant_id, self::M_RETRIES, true );
	}

	public static function bump_retries( int $variant_id ): int {
		$n = self::get_retries( $variant_id ) + 1;
		update_post_meta( $variant_id, self::M_RETRIES, $n );
		return $n;
	}

	/**
	 * Marca una variante como publicada con éxito.
	 */
	public static function mark_published( int $variant_id, string $remote_id, string $api_response ): void {
		update_post_meta( $variant_id, self::M_STATUS, self::STATUS_PUBLISHED );
		update_post_meta( $variant_id, self::M_REMOTE_ID, sanitize_text_field( $remote_id ) );
		update_post_meta( $variant_id, self::M_PUBLISHED_AT, time() );
		update_post_meta( $variant_id, self::M_API_RESPONSE, self::truncate( $api_response ) );
		delete_post_meta( $variant_id, self::M_ERROR );
	}

	/**
	 * Marca una variante en error con el mensaje de la API.
	 */
	public static function mark_error( int $variant_id, string $message ): void {
		update_post_meta( $variant_id, self::M_STATUS, self::STATUS_ERROR );
		update_post_meta( $variant_id, self::M_ERROR, self::truncate( $message ) );
	}

	private static function truncate( string $text, int $len = 500 ): string {
		$text = wp_strip_all_tags( $text );
		if ( strlen( $text ) > $len ) {
			return substr( $text, 0, $len );
		}
		return $text;
	}
}
