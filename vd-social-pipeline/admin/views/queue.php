<?php
/**
 * Vista: Cola de redes (aprobación de variantes).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$groups     = VD_Social_Queue::grouped_active();
$notice_map = array(
	'approved'      => __( 'Variante aprobada: se está publicando.', 'vd-social-pipeline' ),
	'published'     => __( 'Publicado correctamente.', 'vd-social-pipeline' ),
	'publish_error' => __( 'No se pudo publicar (ver Historial).', 'vd-social-pipeline' ),
	'discarded'     => __( 'Variante descartada.', 'vd-social-pipeline' ),
	'saved'       => __( 'Cambios guardados.', 'vd-social-pipeline' ),
	'invalid'     => __( 'Variante no válida.', 'vd-social-pipeline' ),
	'placa_regen' => __( 'Placas regeneradas.', 'vd-social-pipeline' ),
	'placa_error' => __( 'No se pudieron generar las placas (ver Historial).', 'vd-social-pipeline' ),
);
$notice = isset( $_GET['vd_notice'] ) ? sanitize_key( wp_unslash( $_GET['vd_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Badges de estado.
$status_class = array(
	VD_Social_Variant::STATUS_PENDING  => 'vd-badge vd-badge--pending',
	VD_Social_Variant::STATUS_APPROVED => 'vd-badge vd-badge--approved',
	VD_Social_Variant::STATUS_ERROR    => 'vd-badge vd-badge--error',
);
?>
<div class="wrap vd-social-wrap">
	<h1><?php esc_html_e( 'Cola de redes', 'vd-social-pipeline' ); ?></h1>

	<?php if ( '' !== $notice && isset( $notice_map[ $notice ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_map[ $notice ] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! VD_Social_Options::get( 'pipeline_enabled', false ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'El pipeline está desactivado: no se generan posteos nuevos.', 'vd-social-pipeline' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VD_Social_Admin_Menu::SLUG_SETTINGS ) ); ?>"><?php esc_html_e( 'Activarlo en Ajustes', 'vd-social-pipeline' ); ?></a>.
			</p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $groups ) ) : ?>
		<p><?php esc_html_e( 'No hay variantes pendientes.', 'vd-social-pipeline' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $groups as $source_id => $group ) : ?>
		<?php $source_post = $group['post']; ?>
		<div class="vd-group">
			<div class="vd-group__head">
				<?php if ( $source_post && has_post_thumbnail( $source_post ) ) : ?>
					<div class="vd-group__thumb"><?php echo get_the_post_thumbnail( $source_post, array( 80, 80 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
				<div class="vd-group__title">
					<h2>
						<?php echo esc_html( $source_post ? get_the_title( $source_post ) : sprintf( __( 'Nota #%d', 'vd-social-pipeline' ), $source_id ) ); ?>
					</h2>
					<?php if ( $source_post ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $source_id ) ); ?>"><?php esc_html_e( 'Editar nota', 'vd-social-pipeline' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<?php foreach ( $group['variants'] as $variant ) : ?>
				<?php
				$vid     = (int) $variant->ID;
				$network = VD_Social_Variant::get_network( $vid );
				$status  = VD_Social_Variant::get_status( $vid );
				$text    = (string) $variant->post_content;
				$error   = (string) get_post_meta( $vid, VD_Social_Variant::M_ERROR, true );
				$is_x    = ( VD_Social_Variant::NET_X === $network );
				$badge   = isset( $status_class[ $status ] ) ? $status_class[ $status ] : 'vd-badge';
				?>
				<form class="vd-variant" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( VD_Social_Queue::ACTION ); ?>" />
					<input type="hidden" name="variant_id" value="<?php echo esc_attr( $vid ); ?>" />
					<?php wp_nonce_field( VD_Social_Queue::NONCE ); ?>

					<div class="vd-variant__head">
						<strong><?php echo esc_html( VD_Social_Variant::network_label( $network ) ); ?></strong>
						<span class="<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span>
					</div>

					<?php if ( VD_Social_Variant::STATUS_ERROR === $status && '' !== $error ) : ?>
						<p class="vd-variant__error"><?php echo esc_html( $error ); ?></p>
					<?php endif; ?>

					<textarea
						class="vd-variant__text large-text"
						name="variant_text"
						rows="4"
						data-network="<?php echo esc_attr( $network ); ?>"
						<?php echo $is_x ? 'data-x-limit="280"' : ''; ?>
					><?php echo esc_textarea( $text ); ?></textarea>

					<div class="vd-variant__meta">
						<?php if ( $is_x ) : ?>
							<span class="vd-counter" aria-live="polite"></span>
							<span class="description"><?php esc_html_e( 'Máx. 280. El link se agrega al publicar y cuenta ~23.', 'vd-social-pipeline' ); ?></span>
						<?php elseif ( VD_Social_Variant::NET_INSTAGRAM === $network ) : ?>
							<span class="description"><?php esc_html_e( 'Se publica con la imagen destacada. El link no va en el caption.', 'vd-social-pipeline' ); ?></span>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'El link se agrega al final al publicar.', 'vd-social-pipeline' ); ?></span>
						<?php endif; ?>
					</div>

					<div class="vd-variant__actions">
						<button type="submit" name="do" value="approve" class="button button-primary"><?php esc_html_e( 'Aprobar y publicar', 'vd-social-pipeline' ); ?></button>
						<button type="submit" name="do" value="discard" class="button"><?php esc_html_e( 'Descartar', 'vd-social-pipeline' ); ?></button>
					</div>
				</form>
				<?php if ( VD_Social_Variant::NET_INSTAGRAM === $network ) : ?>
					<?php VD_Social_Placa_Module::queue_ui( (int) $source_id, VD_Social_Placa_Storage::read_meta( (int) $source_id ) ); ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
