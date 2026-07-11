<?php
/**
 * Menú de administración y carga condicional de assets por pantalla.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Admin_Menu {

	public const SLUG          = 'vd-social-pipeline';
	public const SLUG_SETTINGS = 'vd-social-settings';
	public const SLUG_HISTORY  = 'vd-social-history';

	/** Capability mínima para la cola/historial. */
	public const CAP = 'edit_others_posts';

	/** @var array<string,string> Hook suffixes de las páginas del plugin. */
	private array $page_hooks = array();

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		$this->page_hooks['queue'] = add_menu_page(
			__( 'VD Social Pipeline', 'vd-social-pipeline' ),
			__( 'Social Pipeline', 'vd-social-pipeline' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_queue' ),
			'dashicons-share',
			26
		);

		add_submenu_page(
			self::SLUG,
			__( 'Cola de redes', 'vd-social-pipeline' ),
			__( 'Cola de redes', 'vd-social-pipeline' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_queue' )
		);

		$this->page_hooks['settings'] = add_submenu_page(
			self::SLUG,
			__( 'Ajustes', 'vd-social-pipeline' ),
			__( 'Ajustes', 'vd-social-pipeline' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_settings' )
		);

		$this->page_hooks['history'] = add_submenu_page(
			self::SLUG,
			__( 'Historial', 'vd-social-pipeline' ),
			__( 'Historial', 'vd-social-pipeline' ),
			self::CAP,
			self::SLUG_HISTORY,
			array( $this, 'render_history' )
		);
	}

	/**
	 * Encola assets SOLO en las páginas del plugin.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}
		// La pantalla de ajustes usa el selector de medios para el logo de placas.
		if ( isset( $this->page_hooks['settings'] ) && $hook_suffix === $this->page_hooks['settings'] ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'vd-social-admin', VD_SOCIAL_URL . 'admin/css/admin.css', array(), VD_SOCIAL_VERSION );
		wp_enqueue_script( 'vd-social-admin', VD_SOCIAL_URL . 'admin/js/admin.js', array(), VD_SOCIAL_VERSION, true );
		wp_localize_script(
			'vd-social-admin',
			'vdSocial',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vd_social_test' ),
				'testing' => __( 'Probando…', 'vd-social-pipeline' ),
			)
		);
	}

	public function render_queue(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		require VD_SOCIAL_DIR . 'admin/views/queue.php';
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require VD_SOCIAL_DIR . 'admin/views/settings.php';
	}

	public function render_history(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		require VD_SOCIAL_DIR . 'admin/views/history.php';
	}
}
