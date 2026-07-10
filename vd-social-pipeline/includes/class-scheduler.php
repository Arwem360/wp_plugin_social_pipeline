<?php
/**
 * Encolado de jobs asíncronos. Usa Action Scheduler si está disponible; si no,
 * cae a wp_schedule_single_event.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Scheduler {

	public const HOOK_GENERATE = 'vd_social_generate';
	public const HOOK_PUBLISH  = 'vd_social_publish';
	public const GROUP         = 'vd-social-pipeline';

	/**
	 * ¿Está disponible Action Scheduler?
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Encola la generación de variantes para una nota (asíncrono).
	 *
	 * @param int $delay Retardo en segundos (para backoff ante 429 de Gemini).
	 */
	public static function enqueue_generate( int $post_id, int $delay = 0 ): void {
		self::enqueue( self::HOOK_GENERATE, array( $post_id ), $delay );
	}

	/**
	 * Encola la publicación de una variante, opcionalmente con retardo (segundos).
	 */
	public static function enqueue_publish( int $variant_id, int $delay = 0 ): void {
		self::enqueue( self::HOOK_PUBLISH, array( $variant_id ), $delay );
	}

	/**
	 * @param array<int,mixed> $args
	 */
	private static function enqueue( string $hook, array $args, int $delay ): void {
		if ( self::has_action_scheduler() ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			} else {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}

		// Fallback: WP-Cron. Un mínimo delay evita la deduplicación de eventos
		// idénticos que hace wp_schedule_single_event dentro de la misma ventana.
		$timestamp = time() + max( 1, $delay );
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}
}
