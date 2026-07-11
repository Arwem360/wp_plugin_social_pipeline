<?php
/**
 * Vista: Ajustes del pipeline.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opts = VD_Social_Options::all();
$key  = VD_Social_Options::OPTION_KEY;

/**
 * Render de un campo de secreto: si está definido por constante se bloquea y no
 * se imprime el valor; si hay valor guardado se muestra un placeholder.
 *
 * @param string $field Clave de la option.
 * @param string $label Etiqueta.
 */
$render_secret = static function ( string $field, string $label ) use ( $key, $opts ): void {
	$is_const = VD_Social_Credentials::is_constant( $field );
	$has_val  = '' !== (string) ( $opts[ $field ] ?? '' );
	?>
	<tr>
		<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<?php if ( $is_const ) : ?>
				<input type="text" class="regular-text" value="<?php esc_attr_e( 'Definido por constante', 'vd-social-pipeline' ); ?>" disabled />
				<p class="description"><code><?php echo esc_html( VD_Social_Credentials::constant_name( $field ) ); ?></code></p>
			<?php else : ?>
				<input type="password" id="<?php echo esc_attr( $field ); ?>" class="regular-text" autocomplete="new-password"
					name="<?php echo esc_attr( $key . '[' . $field . ']' ); ?>"
					placeholder="<?php echo $has_val ? esc_attr__( '•••••• (guardado — dejar vacío para conservar)', 'vd-social-pipeline' ) : ''; ?>" />
			<?php endif; ?>
		</td>
	</tr>
	<?php
};

/**
 * Botón "Probar conexión" para un servicio.
 */
$test_button = static function ( string $service ): void {
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Conexión', 'vd-social-pipeline' ); ?></th>
		<td>
			<button type="button" class="button vd-test" data-service="<?php echo esc_attr( $service ); ?>"><?php esc_html_e( 'Probar conexión', 'vd-social-pipeline' ); ?></button>
			<span class="vd-test-result" data-for="<?php echo esc_attr( $service ); ?>"></span>
		</td>
	</tr>
	<?php
};

$categories = get_categories( array( 'hide_empty' => false ) );
$excluded   = is_array( $opts['excluded_categories'] ) ? array_map( 'intval', $opts['excluded_categories'] ) : array();
?>
<div class="wrap vd-social-wrap">
	<h1><?php esc_html_e( 'VD Social Pipeline — Ajustes', 'vd-social-pipeline' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( VD_Social_Settings::GROUP ); ?>

		<h2><?php esc_html_e( 'Pipeline', 'vd-social-pipeline' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Activar pipeline', 'vd-social-pipeline' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $key . '[pipeline_enabled]' ); ?>" value="1" <?php checked( ! empty( $opts['pipeline_enabled'] ) ); ?> />
						<?php esc_html_e( 'Generar posteos automáticamente al publicar una nota.', 'vd-social-pipeline' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Categorías excluidas', 'vd-social-pipeline' ); ?></th>
				<td>
					<?php if ( empty( $categories ) ) : ?>
						<p class="description"><?php esc_html_e( 'No hay categorías.', 'vd-social-pipeline' ); ?></p>
					<?php else : ?>
						<fieldset class="vd-cats">
							<?php foreach ( $categories as $cat ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $key . '[excluded_categories][]' ); ?>" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( (int) $cat->term_id, $excluded, true ) ); ?> />
									<?php echo esc_html( $cat->name ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Gemini (Google AI)', 'vd-social-pipeline' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php $render_secret( 'gemini_api_key', __( 'API key de Gemini', 'vd-social-pipeline' ) ); ?>
			<tr>
				<th scope="row"><label for="gemini_model"><?php esc_html_e( 'Modelo', 'vd-social-pipeline' ); ?></label></th>
				<td>
					<input type="text" id="gemini_model" class="regular-text"
						name="<?php echo esc_attr( $key . '[gemini_model]' ); ?>"
						value="<?php echo esc_attr( $opts['gemini_model'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Google da de baja versiones seguido. Ver README para actualizar.', 'vd-social-pipeline' ); ?></p>
				</td>
			</tr>
			<?php $test_button( 'gemini' ); ?>
		</table>

		<h2><?php esc_html_e( 'Auto-publicación por red', 'vd-social-pipeline' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Publicar sin revisión', 'vd-social-pipeline' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $key . '[auto_x]' ); ?>" value="1" <?php checked( ! empty( $opts['auto_x'] ) ); ?> /> X (Twitter)</label><br />
					<label><input type="checkbox" name="<?php echo esc_attr( $key . '[auto_facebook]' ); ?>" value="1" <?php checked( ! empty( $opts['auto_facebook'] ) ); ?> /> Facebook</label><br />
					<label><input type="checkbox" name="<?php echo esc_attr( $key . '[auto_instagram]' ); ?>" value="1" <?php checked( ! empty( $opts['auto_instagram'] ) ); ?> /> Instagram</label>
					<p class="description"><?php esc_html_e( 'Con el toggle activo, esa red se publica sin pasar por la cola.', 'vd-social-pipeline' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'X (Twitter) — OAuth 1.0a', 'vd-social-pipeline' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$render_secret( 'x_consumer_key', __( 'Consumer Key', 'vd-social-pipeline' ) );
			$render_secret( 'x_consumer_secret', __( 'Consumer Secret', 'vd-social-pipeline' ) );
			$render_secret( 'x_access_token', __( 'Access Token', 'vd-social-pipeline' ) );
			$render_secret( 'x_access_token_secret', __( 'Access Token Secret', 'vd-social-pipeline' ) );
			$test_button( 'x' );
			?>
		</table>

		<h2><?php esc_html_e( 'Meta — Facebook e Instagram', 'vd-social-pipeline' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="meta_page_id"><?php esc_html_e( 'Page ID (Facebook)', 'vd-social-pipeline' ); ?></label></th>
				<td>
					<?php if ( VD_Social_Credentials::is_constant( 'meta_page_id' ) ) : ?>
						<input type="text" class="regular-text" value="<?php esc_attr_e( 'Definido por constante', 'vd-social-pipeline' ); ?>" disabled />
					<?php else : ?>
						<input type="text" id="meta_page_id" class="regular-text" name="<?php echo esc_attr( $key . '[meta_page_id]' ); ?>" value="<?php echo esc_attr( $opts['meta_page_id'] ); ?>" />
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="meta_ig_user_id"><?php esc_html_e( 'IG User ID (Instagram)', 'vd-social-pipeline' ); ?></label></th>
				<td>
					<?php if ( VD_Social_Credentials::is_constant( 'meta_ig_user_id' ) ) : ?>
						<input type="text" class="regular-text" value="<?php esc_attr_e( 'Definido por constante', 'vd-social-pipeline' ); ?>" disabled />
					<?php else : ?>
						<input type="text" id="meta_ig_user_id" class="regular-text" name="<?php echo esc_attr( $key . '[meta_ig_user_id]' ); ?>" value="<?php echo esc_attr( $opts['meta_ig_user_id'] ); ?>" />
					<?php endif; ?>
				</td>
			</tr>
			<?php $render_secret( 'meta_access_token', __( 'Access Token (larga duración)', 'vd-social-pipeline' ) ); ?>
			<tr>
				<th scope="row"><label for="meta_graph_version"><?php esc_html_e( 'Versión Graph API', 'vd-social-pipeline' ); ?></label></th>
				<td>
					<input type="text" id="meta_graph_version" class="small-text" name="<?php echo esc_attr( $key . '[meta_graph_version]' ); ?>" value="<?php echo esc_attr( $opts['meta_graph_version'] ); ?>" />
				</td>
			</tr>
			<?php
			$test_button( 'facebook' );
			$test_button( 'instagram' );
			?>
		</table>

		<h2><?php esc_html_e( 'Placas de Instagram', 'vd-social-pipeline' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Logo (PNG)', 'vd-social-pipeline' ); ?></th>
				<td>
					<?php
					$logo_id  = (int) $opts['placa_logo_id'];
					$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
					?>
					<input type="hidden" id="placa_logo_id" name="<?php echo esc_attr( $key . '[placa_logo_id]' ); ?>" value="<?php echo esc_attr( $logo_id ); ?>" />
					<div class="vd-logo-preview" style="margin-bottom:8px;">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:80px;background:#222;padding:6px;border-radius:4px;" />
						<?php endif; ?>
					</div>
					<button type="button" class="button vd-logo-select"><?php esc_html_e( 'Elegir logo', 'vd-social-pipeline' ); ?></button>
					<button type="button" class="button vd-logo-remove" <?php echo $logo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Quitar', 'vd-social-pipeline' ); ?></button>
					<p class="description"><?php esc_html_e( 'Opcional. Si no cargás logo, se usa el wordmark tipográfico VERMOUTH / DEPORTIVO.', 'vd-social-pipeline' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="placa_accent"><?php esc_html_e( 'Color de acento', 'vd-social-pipeline' ); ?></label></th>
				<td>
					<input type="text" id="placa_accent" class="small-text" name="<?php echo esc_attr( $key . '[placa_accent]' ); ?>" value="<?php echo esc_attr( $opts['placa_accent'] ); ?>" />
					<input type="color" class="vd-accent-sync" value="<?php echo esc_attr( $opts['placa_accent'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="placa_handle_domain"><?php esc_html_e( 'Handle · dominio (franja inferior)', 'vd-social-pipeline' ); ?></label></th>
				<td>
					<input type="text" id="placa_handle_domain" class="regular-text" name="<?php echo esc_attr( $key . '[placa_handle_domain]' ); ?>" value="<?php echo esc_attr( $opts['placa_handle_domain'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fecha', 'vd-social-pipeline' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $key . '[placa_show_date]' ); ?>" value="1" <?php checked( ! empty( $opts['placa_show_date'] ) ); ?> /> <?php esc_html_e( 'Mostrar la fecha en la placa', 'vd-social-pipeline' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Categoría', 'vd-social-pipeline' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $key . '[placa_show_category]' ); ?>" value="1" <?php checked( ! empty( $opts['placa_show_category'] ) ); ?> /> <?php esc_html_e( 'Mostrar la cinta de categoría en la placa', 'vd-social-pipeline' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Publicación en Instagram', 'vd-social-pipeline' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $key . '[placa_use_as_ig_image]' ); ?>" value="1" <?php checked( ! empty( $opts['placa_use_as_ig_image'] ) ); ?> /> <?php esc_html_e( 'Usar la placa (feed 1080×1350) como imagen al publicar en Instagram', 'vd-social-pipeline' ); ?></label>
					<p class="description"><?php esc_html_e( 'Solo tiene efecto cuando las credenciales de Meta están cargadas. Con el toggle apagado, IG usa la imagen destacada como siempre.', 'vd-social-pipeline' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
