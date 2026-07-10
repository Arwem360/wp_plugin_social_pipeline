<?php
/**
 * Generador: en background llama a Gemini y crea las variantes por red.
 *
 * @package VD_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VD_Social_Generator {

	/** Meta en la nota origen: intentos de generación (para no loopear en 429). */
	private const M_ATTEMPTS = '_vd_social_gen_attempts';

	/** Máximo de reintentos diferidos ante 429/errores de Gemini. */
	private const MAX_ATTEMPTS = 3;

	public function register_hooks(): void {
		add_action( VD_Social_Scheduler::HOOK_GENERATE, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Handler del job. Genera y persiste las variantes de una nota.
	 */
	public function run( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return;
		}
		// Anti-duplicado: si ya se crearon las variantes, no repetir.
		if ( VD_Social_Variant::exists_for_source( $post_id ) ) {
			return;
		}

		$input  = $this->build_input( $post );
		$client = new VD_Social_Gemini_Client();

		// Primer intento + 1 reintento inline ante JSON inválido.
		$data = $client->generate( $input['system'], $input['user'] );
		if ( is_wp_error( $data ) && 'rate_limit' === $data->get_error_code() ) {
			$this->handle_rate_limit( $post_id );
			return;
		}
		if ( is_wp_error( $data ) || ! $this->is_valid( $data ) ) {
			$data = $client->generate( $input['system'], $input['user'] ); // reintento 1 vez.
		}

		if ( is_wp_error( $data ) && 'rate_limit' === $data->get_error_code() ) {
			$this->handle_rate_limit( $post_id );
			return;
		}

		if ( is_wp_error( $data ) || ! $this->is_valid( $data ) ) {
			$msg = is_wp_error( $data ) ? $data->get_error_message() : __( 'JSON inválido o incompleto tras el reintento.', 'vd-social-pipeline' );
			$this->create_error_variants( $post_id, $msg );
			return;
		}

		$this->create_variants( $post_id, $data );
	}

	/**
	 * Backoff diferido ante 429. Si se agotan los intentos, crea variantes en error.
	 */
	private function handle_rate_limit( int $post_id ): void {
		$attempts = (int) get_post_meta( $post_id, self::M_ATTEMPTS, true ) + 1;
		update_post_meta( $post_id, self::M_ATTEMPTS, $attempts );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			VD_Social_Logger::log( 'gemini', $post_id, 0, 'error', '429', 'Cuota de Gemini agotada tras varios intentos.' );
			$this->create_error_variants( $post_id, __( 'Cuota de Gemini agotada (429).', 'vd-social-pipeline' ) );
			return;
		}

		// Backoff creciente: 5, 10, 15 min...
		$delay = 300 * $attempts;
		VD_Social_Logger::log( 'gemini', $post_id, 0, 'info', '429', sprintf( 'Rate limit; reintento en %d s.', $delay ) );
		VD_Social_Scheduler::enqueue_generate( $post_id, $delay );
	}

	/**
	 * Crea las 3 variantes a partir de la respuesta válida de Gemini.
	 *
	 * @param array<string,mixed> $data
	 */
	private function create_variants( int $post_id, array $data ): void {
		$texts = array(
			VD_Social_Variant::NET_X         => isset( $data['x']['text'] ) ? (string) $data['x']['text'] : '',
			VD_Social_Variant::NET_FACEBOOK  => isset( $data['facebook']['text'] ) ? (string) $data['facebook']['text'] : '',
			VD_Social_Variant::NET_INSTAGRAM => isset( $data['instagram']['caption'] ) ? (string) $data['instagram']['caption'] : '',
		);

		foreach ( $texts as $network => $text ) {
			$text        = trim( wp_strip_all_tags( $text ) );
			$auto        = (bool) VD_Social_Options::get( 'auto_' . $network, false );
			$variant_id  = VD_Social_Variant::create( $post_id, $network, $text, VD_Social_Variant::STATUS_PENDING );
			if ( 0 === $variant_id ) {
				continue;
			}

			VD_Social_Logger::log( $network, $post_id, $variant_id, 'ok', '', 'Variante generada.' );

			// Modo automático por red: aprobar y publicar sin pasar por la cola.
			if ( $auto && '' !== $text ) {
				VD_Social_Variant::set_status( $variant_id, VD_Social_Variant::STATUS_APPROVED );
				VD_Social_Scheduler::enqueue_publish( $variant_id );
			}
		}
	}

	/**
	 * Crea variantes vacías en estado "error" para que un humano las escriba.
	 */
	private function create_error_variants( int $post_id, string $message ): void {
		foreach ( VD_Social_Variant::networks() as $network ) {
			$variant_id = VD_Social_Variant::create( $post_id, $network, '', VD_Social_Variant::STATUS_ERROR );
			if ( $variant_id > 0 ) {
				VD_Social_Variant::mark_error( $variant_id, $message );
				VD_Social_Logger::log( $network, $post_id, $variant_id, 'error', '', $message );
			}
		}
	}

	/**
	 * Valida que el JSON traiga los tres campos esperados.
	 *
	 * @param mixed $data
	 */
	private function is_valid( $data ): bool {
		return is_array( $data )
			&& isset( $data['x']['text'] )
			&& isset( $data['facebook']['text'] )
			&& isset( $data['instagram']['caption'] );
	}

	/**
	 * Arma la instrucción de sistema (estilo) y el contenido de usuario (la nota).
	 *
	 * @return array{system:string,user:string}
	 */
	private function build_input( WP_Post $post ): array {
		$title    = get_the_title( $post );
		$author   = get_the_author_meta( 'display_name', (int) $post->post_author );
		$category = $this->primary_category_name( $post );
		$excerpt  = $this->excerpt( $post );

		$url_x  = VD_Social_UTM::url( $post->ID, VD_Social_Variant::NET_X );
		$url_fb = VD_Social_UTM::url( $post->ID, VD_Social_Variant::NET_FACEBOOK );

		$system = $this->style_instruction();

		$user = sprintf(
			"TÍTULO: %s\n\nAUTOR: %s\n\nCATEGORÍA: %s\n\nEXTRACTO / PRIMEROS PÁRRAFOS:\n%s\n\n" .
			"CONTEXTO DE LINKS (NO escribas la URL vos; el sistema la agrega al final en X y Facebook):\n" .
			"- URL para X: %s\n- URL para Facebook: %s\n",
			$title,
			$author,
			$category,
			$excerpt,
			$url_x,
			$url_fb
		);

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Lineamientos de estilo de Vermouth Deportivo (systemInstruction).
	 */
	private function style_instruction(): string {
		return implode(
			"\n",
			array(
				'Sos el community manager de Vermouth Deportivo, un portal de noticias deportivas argentino.',
				'Escribís en español rioplatense, tono canchero pero informativo. Nunca clickbait engañoso. Nunca inventes datos que no estén en la nota.',
				'Devolvés SOLO el JSON con los campos pedidos (x.text, facebook.text, instagram.caption). No agregues nada fuera del JSON.',
				'',
				'REGLAS POR RED:',
				'X (campo x.text): máximo 230 caracteres (el sistema agrega el link al final, reservá ~30 caracteres). No incluyas ninguna URL. Gancho + un dato concreto. 0 a 2 hashtags SOLO si son naturales (ej: #Ascenso, #Mundial2026).',
				'Facebook (campo facebook.text): 2 a 4 oraciones, más contexto que X. No incluyas la URL (el sistema la agrega al final). Sin hashtags o como máximo 1.',
				'Instagram (campo instagram.caption): caption de 3 a 6 líneas. La primera línea es un gancho fuerte. En IG no hay link clickeable: NO incluyas ninguna URL. Cerrá con "Nota completa en el link de la bio" y luego 3 a 5 hashtags relevantes.',
				'',
				'PROHIBIDO en todas: más de 2 emojis por posteo, MAYÚSCULAS SOSTENIDAS, y la frase "no te lo pierdas".',
			)
		);
	}

	private function primary_category_name( WP_Post $post ): string {
		$terms = get_the_terms( $post->ID, 'category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		$first = reset( $terms );
		return $first ? $first->name : '';
	}

	/**
	 * Extracto: usa el manual si existe; si no, los primeros ~600 caracteres del cuerpo.
	 */
	private function excerpt( WP_Post $post ): string {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return trim( wp_strip_all_tags( $post->post_excerpt ) );
		}
		$content = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );
		if ( strlen( $content ) > 600 ) {
			$content = substr( $content, 0, 600 );
		}
		return $content;
	}
}
