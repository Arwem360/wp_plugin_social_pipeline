<?php
/**
 * Orquestador principal: bootstrap mínimo y enrutado por contexto.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Plugin {

	private static ?VD_Social_Plugin $instance = null;

	public static function instance(): VD_Social_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Arranque: registra lo que debe correr en cualquier contexto (incluido
	 * WP-Cron / Action Scheduler, que corren fuera del admin) y difiere la UI.
	 */
	public function boot(): void {
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'vd-social-pipeline', false, dirname( VD_SOCIAL_BASENAME ) . '/languages' );
			}
		);

		// CPT de variantes: en todo contexto (el publicador de background lo usa).
		( new VD_Social_CPT() )->register_hooks();

		// Trigger del pipeline: engancha la publicación de notas. En todo contexto
		// porque una nota puede publicarse por REST, wp-cli o programada.
		( new VD_Social_Pipeline() )->register_hooks();

		// Handlers de los jobs de background (generación y publicación).
		( new VD_Social_Generator() )->register_hooks();
		( new VD_Social_Publish_Manager() )->register_hooks();

		// Módulo Placas (metabox, generación de imágenes, UI). Corre también en
		// background, por eso se registra en todo contexto.
		( new VD_Social_Placa_Module() )->register_hooks();

		if ( is_admin() ) {
			$this->boot_admin();
		}
	}

	private function boot_admin(): void {
		( new VD_Social_Admin_Menu() )->register_hooks();
		( new VD_Social_Settings() )->register_hooks();
		( new VD_Social_Queue() )->register_hooks();
		( new VD_Social_Connection_Test() )->register_hooks();
	}
}
