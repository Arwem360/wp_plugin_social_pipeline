<?php
/**
 * Módulo Partidos: sincroniza fixtures desde la API de estadísticas hacia el CPT
 * del tema, cada 30 minutos. Self-contained: opción, ajustes y submenú propios,
 * para no tocar el pipeline de redes.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Fixtures_Module {

	public const HOOK          = 'vd_fixtures_sync';
	public const OPTION_KEY    = 'vd_fixtures_options';
	public const SETTINGS_GROUP = 'vd_fixtures_group';
	public const PAGE_SLUG     = 'vd-fixtures';
	public const INTERVAL_NAME = 'vd_fixtures_30min';
	public const SYNC_ACTION   = 'vd_fixtures_sync_now';

	/**
	 * Defaults de configuración.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'      => true,
			'api_base'     => 'https://vermouth-deportivo.com.ar/statsapi/api',
			'season'       => '',                               // Vacío = temporada en curso (plan pago).
			'leagues'      => array( 128, 129, 13, 11, 130 ),   // Liga Prof., Primera Nac., Libertadores, Sudamericana, Copa Arg.
			'last_run'     => 0,
			'last_summary' => '',
		);
	}

	/**
	 * Configuración efectiva (defaults + guardado).
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge( self::defaults(), $stored );
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_interval' ) );
		add_action( self::HOOK, array( $this, 'run_sync' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync_now' ) );
		}
	}

	// --- Programación (WP-Cron) ---------------------------------------------

	/**
	 * Agrega el intervalo de 30 min a los schedules de WP-Cron.
	 *
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL_NAME ] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 30 minutos (Partidos)', 'vd-social-pipeline' ),
		);
		return $schedules;
	}

	/**
	 * Asegura que el evento recurrente exista (o lo limpia si está desactivado).
	 * Es idempotente y barato: wp_next_scheduled lee el cron array ya cargado.
	 */
	public function ensure_schedule(): void {
		$enabled = (bool) self::config()['enabled'];

		if ( ! $enabled ) {
			$this->clear_schedule();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL_NAME, self::HOOK );
		}
	}

	public function clear_schedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Handler del cron: corre la sync y guarda el resultado para la UI.
	 */
	public function run_sync(): void {
		$summary = ( new VD_Social_Fixtures_Sync() )->run();
		$this->save_runtime( $summary );
	}

	/**
	 * @param array<string,int> $summary
	 */
	private function save_runtime( array $summary ): void {
		$stored                 = get_option( self::OPTION_KEY, array() );
		$stored                 = is_array( $stored ) ? $stored : array();
		$stored['last_run']     = time();
		$stored['last_summary'] = wp_json_encode( $summary );
		update_option( self::OPTION_KEY, $stored );
	}

	// --- Ajustes (Settings API) ---------------------------------------------

	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::config();

		$clean['enabled']  = ! empty( $input['enabled'] );
		$clean['api_base'] = isset( $input['api_base'] ) ? esc_url_raw( trim( (string) $input['api_base'] ) ) : $clean['api_base'];
		$clean['season']   = isset( $input['season'] ) ? preg_replace( '/[^0-9]/', '', (string) $input['season'] ) : $clean['season'];

		if ( isset( $input['leagues'] ) ) {
			$raw             = is_array( $input['leagues'] ) ? implode( ',', $input['leagues'] ) : (string) $input['leagues'];
			$ids             = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ) );
			$clean['leagues'] = array_values( array_unique( $ids ) );
		}

		// Campos de runtime (los escribe save_runtime() vía update_option, que
		// también pasa por este saneado): preservarlos si vienen en el input, si
		// no conservar el valor previo. Sin esto, el guardado del cron/botón
		// perdería la fecha y el resumen de la última sincronización.
		$clean['last_run']     = isset( $input['last_run'] ) ? (int) $input['last_run'] : $clean['last_run'];
		$clean['last_summary'] = isset( $input['last_summary'] ) ? (string) $input['last_summary'] : $clean['last_summary'];

		// Reprograma según el nuevo estado del toggle.
		if ( $clean['enabled'] ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL_NAME, self::HOOK );
			}
		} else {
			$this->clear_schedule();
		}

		return $clean;
	}

	// --- UI ------------------------------------------------------------------

	public function add_page(): void {
		add_submenu_page(
			VD_Social_Admin_Menu::SLUG,
			__( 'Partidos', 'vd-social-pipeline' ),
			__( 'Partidos', 'vd-social-pipeline' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config  = self::config();
		$summary = '' !== (string) $config['last_summary'] ? json_decode( (string) $config['last_summary'], true ) : array();
		$next    = wp_next_scheduled( self::HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sincronización de Partidos', 'vd-social-pipeline' ); ?></h1>

			<?php if ( isset( $_GET['vd_synced'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sincronización ejecutada.', 'vd-social-pipeline' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat" style="max-width:640px;margin-bottom:20px">
				<tbody>
					<tr>
						<th style="width:220px"><?php esc_html_e( 'Última sincronización', 'vd-social-pipeline' ); ?></th>
						<td>
							<?php
							$last = (int) $config['last_run'];
							echo $last ? esc_html( wp_date( 'd/m/Y H:i', $last ) ) : esc_html__( 'Nunca', 'vd-social-pipeline' );
							if ( is_array( $summary ) && $summary ) {
								echo ' — ' . esc_html(
									sprintf(
										/* translators: sync counters. */
										__( '%1$d nuevos, %2$d actualizados, %3$d errores', 'vd-social-pipeline' ),
										(int) ( $summary['created'] ?? 0 ),
										(int) ( $summary['updated'] ?? 0 ),
										(int) ( $summary['errors'] ?? 0 )
									)
								);
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Próxima ejecución', 'vd-social-pipeline' ); ?></th>
						<td><?php echo $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : esc_html__( 'No programada', 'vd-social-pipeline' ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SYNC_ACTION ); ?>" />
				<?php wp_nonce_field( self::SYNC_ACTION ); ?>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Sincronizar ahora', 'vd-social-pipeline' ); ?></button>
				<span class="description" style="margin-left:8px"><?php esc_html_e( 'Trae los partidos de hoy y mañana de inmediato.', 'vd-social-pipeline' ); ?></span>
			</form>

			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sincronización activa', 'vd-social-pipeline' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( (bool) $config['enabled'] ); ?> />
								<?php esc_html_e( 'Traer partidos automáticamente cada 30 minutos', 'vd-social-pipeline' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vd_fx_base"><?php esc_html_e( 'URL de la API', 'vd-social-pipeline' ); ?></label></th>
						<td>
							<input type="url" id="vd_fx_base" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base]" value="<?php echo esc_attr( (string) $config['api_base'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Base del servicio, sin barra final. Ej: https://tu-dominio.com/statsapi/api', 'vd-social-pipeline' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vd_fx_leagues"><?php esc_html_e( 'Ligas a sincronizar', 'vd-social-pipeline' ); ?></label></th>
						<td>
							<input type="text" id="vd_fx_leagues" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[leagues]" value="<?php echo esc_attr( implode( ', ', (array) $config['leagues'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'IDs de liga de API-Football, separados por coma. Ej: 128 (Liga Prof.), 129 (Primera Nac.), 13 (Libertadores), 11 (Sudamericana), 130 (Copa Arg.).', 'vd-social-pipeline' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vd_fx_season"><?php esc_html_e( 'Temporada', 'vd-social-pipeline' ); ?></label></th>
						<td>
							<input type="text" id="vd_fx_season" class="small-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[season]" value="<?php echo esc_attr( (string) $config['season'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Dejar vacío para usar la temporada en curso (recomendado con plan pago).', 'vd-social-pipeline' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Botón "Sincronizar ahora": corre la sync y vuelve con un aviso.
	 */
	public function handle_sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'vd-social-pipeline' ) );
		}
		check_admin_referer( self::SYNC_ACTION );

		$this->run_sync();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'vd_synced' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
