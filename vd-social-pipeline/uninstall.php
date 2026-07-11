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
$source_meta = array(
	'_vd_social_processed',
	'_vd_social_gen_attempts',
	'_vd_social_score',
	'_vd_social_score_sub',
	'_vd_social_placa_feed_path',
	'_vd_social_placa_feed_url',
	'_vd_social_placa_story_path',
	'_vd_social_placa_story_url',
	'_vd_social_placa_lowres',
	'_vd_social_placa_noimage',
	'_vd_social_placa_engine',
	'_vd_social_placa_generated_at',
);
foreach ( $source_meta as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Archivos de placas generados en uploads/vd-placas/.
$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$placas_dir = trailingslashit( $uploads['basedir'] ) . 'vd-placas';
	if ( is_dir( $placas_dir ) ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $placas_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			} else {
				@unlink( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
		@rmdir( $placas_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
}

// Tabla de log.
$table = $wpdb->prefix . 'vd_social_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Transients residuales (por si se agregan a futuro con este prefijo).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_vd\_social\_%' OR option_name LIKE '\_transient\_timeout\_vd\_social\_%'"
);
