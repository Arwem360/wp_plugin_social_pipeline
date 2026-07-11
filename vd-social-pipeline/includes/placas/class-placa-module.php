<?php
/**
 * Orquestador del módulo Placas: metabox, generación en el job asíncrono,
 * acciones de admin (regenerar desde la cola, generar desde el editor) y assets.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Placa_Module {

	public const REGEN_ACTION = 'vd_social_placa_regen';
	public const AJAX_ACTION  = 'vd_social_placa_generate';
	private const REGEN_NONCE = 'vd_social_placa_regen';
	private const AJAX_NONCE  = 'vd_social_placa_ajax';

	private VD_Social_Placa_Metabox $metabox;

	public function __construct() {
		$this->metabox = new VD_Social_Placa_Metabox();
	}

	public function register_hooks(): void {
		$this->metabox->register_hooks();

		// Generación dentro del job asíncrono del pipeline (después de las variantes).
		add_action( VD_Social_Scheduler::HOOK_GENERATE, array( $this, 'on_generate' ), 20, 1 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_editor_box' ) );
			add_action( 'admin_post_' . self::REGEN_ACTION, array( $this, 'handle_regen' ) );
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_generate' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor' ) );
		}
	}

	/**
	 * Handler del job: genera las placas si aún no existen para esta nota.
	 */
	public function on_generate( int $post_id ): void {
		if ( ! VD_Social_Placa_Generator::can_render() ) {
			return;
		}
		if ( VD_Social_Placa_Storage::has_placas( $post_id ) ) {
			return; // Ya existen (evita regenerar en reintentos del pipeline).
		}
		( new VD_Social_Placa_Generator() )->generate( $post_id );
	}

	// --- Editor de la nota ---------------------------------------------------

	public function add_editor_box(): void {
		add_meta_box(
			'vd_social_placa_gen',
			__( 'Placas de Instagram', 'vd-social-pipeline' ),
			array( $this, 'render_editor_box' ),
			'post',
			'side',
			'default'
		);
	}

	public function render_editor_box( WP_Post $post ): void {
		$meta = VD_Social_Placa_Storage::read_meta( $post->ID );
		?>
		<div class="vd-placa-editor" data-post="<?php echo esc_attr( $post->ID ); ?>">
			<?php if ( ! VD_Social_Placa_Generator::can_render() ) : ?>
				<p class="description"><?php esc_html_e( 'No se puede generar: faltan las fuentes o no hay Imagick/GD en el servidor.', 'vd-social-pipeline' ); ?></p>
			<?php else : ?>
				<button type="button" class="button button-primary vd-placa-generate" style="width:100%;margin-bottom:10px;"><?php esc_html_e( 'Generar placas de Instagram', 'vd-social-pipeline' ); ?></button>
				<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Guardá la nota antes de generar para tomar el último título.', 'vd-social-pipeline' ); ?></p>
			<?php endif; ?>
			<div class="vd-placa-previews">
				<?php $this->render_previews( $meta ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function render_previews( array $meta ): void {
		if ( '' === (string) $meta['feed_url'] ) {
			return;
		}
		if ( $meta['noimage'] ) {
			echo '<p class="description">' . esc_html__( 'Generada sin imagen destacada (fondo de marca).', 'vd-social-pipeline' ) . '</p>';
		} elseif ( $meta['lowres'] ) {
			echo '<p class="description" style="color:#8a6d1b;">' . esc_html__( 'Imagen de origen de baja resolución.', 'vd-social-pipeline' ) . '</p>';
		}
		$cache = '?v=' . (int) $meta['generated'];
		?>
		<div class="vd-placa-thumbs">
			<div class="vd-placa-thumb">
				<img src="<?php echo esc_url( $meta['feed_url'] . $cache ); ?>" alt="" />
				<a class="button button-small" href="<?php echo esc_url( $meta['feed_url'] ); ?>" download><?php esc_html_e( 'Feed 4:5', 'vd-social-pipeline' ); ?></a>
			</div>
			<div class="vd-placa-thumb">
				<img src="<?php echo esc_url( $meta['story_url'] . $cache ); ?>" alt="" />
				<a class="button button-small" href="<?php echo esc_url( $meta['story_url'] ); ?>" download><?php esc_html_e( 'Historia 9:16', 'vd-social-pipeline' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function enqueue_editor( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'vd-social-admin', VD_SOCIAL_URL . 'admin/css/admin.css', array(), VD_SOCIAL_VERSION );
		wp_enqueue_script( 'vd-social-admin', VD_SOCIAL_URL . 'admin/js/admin.js', array(), VD_SOCIAL_VERSION, true );
		wp_localize_script(
			'vd-social-admin',
			'vdPlaca',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::AJAX_NONCE ),
				'action'     => self::AJAX_ACTION,
				'generating' => __( 'Generando…', 'vd-social-pipeline' ),
				'error'      => __( 'Error al generar', 'vd-social-pipeline' ),
			)
		);
	}

	/**
	 * AJAX desde el editor: genera y devuelve las URLs.
	 */
	public function ajax_generate(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'vd-social-pipeline' ) ) );
		}
		$result = ( new VD_Social_Placa_Generator() )->generate( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$cache = '?v=' . time();
		wp_send_json_success(
			array(
				'feed'    => $result['feed_url'] . $cache,
				'story'   => $result['story_url'] . $cache,
				'feedRaw' => $result['feed_url'],
				'storyRaw'=> $result['story_url'],
				'lowres'  => (bool) $result['lowres'],
				'noimage' => (bool) $result['noimage'],
			)
		);
	}

	// --- Regenerar desde la cola ---------------------------------------------

	public function handle_regen(): void {
		if ( ! current_user_can( VD_Social_Admin_Menu::CAP ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'vd-social-pipeline' ) );
		}
		check_admin_referer( self::REGEN_NONCE );
		$post_id = isset( $_POST['source_post'] ) ? absint( $_POST['source_post'] ) : 0;

		$notice = 'placa_regen';
		if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
			$result = ( new VD_Social_Placa_Generator() )->generate( $post_id );
			if ( is_wp_error( $result ) ) {
				$notice = 'placa_error';
			}
		} else {
			$notice = 'invalid';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => VD_Social_Admin_Menu::SLUG,
					'vd_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Markup reutilizable para la cola: miniaturas + descargar + regenerar.
	 *
	 * @param array<string,mixed> $meta
	 */
	public static function queue_ui( int $source_id, array $meta ): void {
		?>
		<div class="vd-placa-queue">
			<strong><?php esc_html_e( 'Placas', 'vd-social-pipeline' ); ?></strong>
			<?php if ( '' !== (string) $meta['feed_url'] ) : ?>
				<?php $cache = '?v=' . (int) $meta['generated']; ?>
				<div class="vd-placa-thumbs">
					<div class="vd-placa-thumb">
						<img src="<?php echo esc_url( $meta['feed_url'] . $cache ); ?>" alt="" />
						<a class="button button-small" href="<?php echo esc_url( $meta['feed_url'] ); ?>" download><?php esc_html_e( 'Descargar feed', 'vd-social-pipeline' ); ?></a>
					</div>
					<div class="vd-placa-thumb">
						<img src="<?php echo esc_url( $meta['story_url'] . $cache ); ?>" alt="" />
						<a class="button button-small" href="<?php echo esc_url( $meta['story_url'] ); ?>" download><?php esc_html_e( 'Descargar historia', 'vd-social-pipeline' ); ?></a>
					</div>
				</div>
				<?php if ( $meta['lowres'] ) : ?>
					<p class="description" style="color:#8a6d1b;"><?php esc_html_e( 'Imagen de origen de baja resolución.', 'vd-social-pipeline' ); ?></p>
				<?php elseif ( $meta['noimage'] ) : ?>
					<p class="description"><?php esc_html_e( 'Sin imagen destacada (fondo de marca).', 'vd-social-pipeline' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Todavía no se generaron placas para esta nota.', 'vd-social-pipeline' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REGEN_ACTION ); ?>" />
				<input type="hidden" name="source_post" value="<?php echo esc_attr( $source_id ); ?>" />
				<?php wp_nonce_field( self::REGEN_NONCE ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Regenerar placas', 'vd-social-pipeline' ); ?></button>
			</form>
		</div>
		<?php
	}
}
