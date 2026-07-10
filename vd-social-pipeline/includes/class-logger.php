<?php
/**
 * Log rotativo simple en tabla propia. Nunca guarda credenciales.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Logger {

	/** Cantidad máxima de filas a conservar (rotación). */
	private const MAX_ROWS = 1000;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vd_social_log';
	}

	/**
	 * Crea la tabla (llamado en activación).
	 */
	public static function install_table(): void {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			network varchar(20) NOT NULL DEFAULT '',
			source_post bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			result varchar(20) NOT NULL DEFAULT '',
			response_code varchar(20) NOT NULL DEFAULT '',
			message text NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY source_post (source_post)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Registra una línea de log.
	 *
	 * @param string $result   'ok' | 'error' | 'info'
	 */
	public static function log( string $network, int $source_post, int $variant_id, string $result, string $response_code, string $message ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'    => current_time( 'mysql' ),
				'network'       => substr( $network, 0, 20 ),
				'source_post'   => $source_post,
				'variant_id'    => $variant_id,
				'result'        => substr( $result, 0, 20 ),
				'response_code' => substr( $response_code, 0, 20 ),
				'message'       => self::redact( $message ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		self::maybe_rotate();
	}

	/**
	 * Devuelve las últimas filas para la pantalla Historial.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 200 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rotación: si supera el máximo, borra las filas más viejas.
	 */
	private static function maybe_rotate(): void {
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		if ( $count <= self::MAX_ROWS ) {
			return;
		}
		$cutoff = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS )
		);
		if ( $cutoff > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Redacta posibles tokens del mensaje antes de guardarlo.
	 */
	private static function redact( string $message ): string {
		$message = wp_strip_all_tags( $message );
		// Ocultar valores de access_token=... si aparecieran en una URL de error.
		$message = preg_replace( '/(access_token=)[^&\s]+/i', '$1[REDACTED]', $message );
		if ( strlen( $message ) > 1000 ) {
			$message = substr( $message, 0, 1000 );
		}
		return (string) $message;
	}
}
