<?php
/**
 * Desinstalación: limpia opciones, meta, log y variantes creadas por el plugin.
 *
 * @package VD_Social
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Opción de configuración.
delete_option( 'vd_social_options' );

// Variantes (CPT vd_social_post) y su meta.
$variant_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'vd_social_post' )
);
if ( ! empty( $variant_ids ) ) {
	foreach ( $variant_ids as $vid ) {
		wp_delete_post( (int) $vid, true );
	}
}

// Meta dejada en las notas origen.
foreach ( array( '_vd_social_processed', '_vd_social_gen_attempts' ) as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Tabla de log.
$table = $wpdb->prefix . 'vd_social_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Transients residuales (por si se agregan a futuro con este prefijo).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_vd\_social\_%' OR option_name LIKE '\_transient\_timeout\_vd\_social\_%'"
);
