<?php
/**
 * Vista: Historial (log del pipeline).
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = VD_Social_Logger::recent( 200 );
?>
<div class="wrap vd-social-wrap">
	<h1><?php esc_html_e( 'Historial', 'vd-social-pipeline' ); ?></h1>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Fecha', 'vd-social-pipeline' ); ?></th>
				<th><?php esc_html_e( 'Red', 'vd-social-pipeline' ); ?></th>
				<th><?php esc_html_e( 'Nota', 'vd-social-pipeline' ); ?></th>
				<th><?php esc_html_e( 'Resultado', 'vd-social-pipeline' ); ?></th>
				<th><?php esc_html_e( 'Código', 'vd-social-pipeline' ); ?></th>
				<th><?php esc_html_e( 'Mensaje', 'vd-social-pipeline' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Sin registros todavía.', 'vd-social-pipeline' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php $source = (int) $row['source_post']; ?>
				<tr>
					<td><?php echo esc_html( $row['created_at'] ); ?></td>
					<td><?php echo esc_html( VD_Social_Variant::network_label( (string) $row['network'] ) ); ?></td>
					<td>
						<?php if ( $source > 0 && get_post( $source ) ) : ?>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $source ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $source > 0 ? '#' . $source : '—' ); ?>
						<?php endif; ?>
					</td>
					<td>
						<span class="vd-badge vd-badge--<?php echo 'ok' === $row['result'] ? 'approved' : ( 'error' === $row['result'] ? 'error' : 'pending' ); ?>">
							<?php echo esc_html( $row['result'] ); ?>
						</span>
					</td>
					<td><?php echo esc_html( (string) $row['response_code'] ); ?></td>
					<td><?php echo esc_html( (string) $row['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
