<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_Error;

use Kurukin\Core\Services\Tenant_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings_Controller extends WP_REST_Controller {

	protected $namespace = 'kurukin/v1';
	protected $resource  = 'settings';
	private   $prefix    = '_kurukin_';

	// Validation security
	private int $validation_ttl_seconds = 600; // 10 min

	public function __construct() {
		$this->register_routes();
	}

	public function register_routes() {

		register_rest_route( $this->namespace, '/' . $this->resource, [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => [ $this, 'permissions_check' ],
		]);

		register_rest_route( $this->namespace, '/' . $this->resource, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'update_settings' ],
			'permission_callback' => [ $this, 'permissions_check' ],
		]);

		// ✅ Canonical route
		register_rest_route( $this->namespace, '/validate-credential', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'validate_credential' ],
			'permission_callback' => [ $this, 'permissions_check' ],
		]);

		// ✅ Retrocompat
		register_rest_route( $this->namespace, '/' . $this->resource . '/validate-credential', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'validate_credential' ],
			'permission_callback' => [ $this, 'permissions_check' ],
		]);
	}

	/**
	 * Permission policy (multi-tenant):
	 * - logged in
	 * - capability read
	 * - must own saas_instance OR admin
	 */
	public function permissions_check( $request ) {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
		}

		if ( ! current_user_can( 'read' ) ) {
			error_log('[Kurukin] Settings permissions denied (no-read): user_id=' . $user_id);
			return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
		}

		// Admin override
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! class_exists( Tenant_Service::class ) ) {
			return new WP_Error( 'kurukin_internal_error', 'Tenant_Service not available', [ 'status' => 500 ] );
		}

		$post_id = Tenant_Service::ensure_user_instance( $user_id );
		if ( is_wp_error( $post_id ) ) return $post_id;

		$post_id   = (int) $post_id;
		$author_id = (int) get_post_field( 'post_author', $post_id );

		if ( $author_id !== $user_id ) {
			return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
		}

		return true;
	}

	public function get_settings( $request ) {
		$post_id = $this->ensure_user_instance();
		if ( is_wp_error( $post_id ) ) return $post_id;

		$prompt         = (string) get_post_meta( $post_id, $this->prefix . 'system_prompt', true );

		// Voice / ElevenLabs (optional BYOK)
		$voice_enabled  = get_post_meta( $post_id, $this->prefix . 'voice_enabled', true );
		$voice_id       = (string) get_post_meta( $post_id, $this->prefix . 'eleven_voice_id', true );
		$eleven_key_raw = get_post_meta( $post_id, $this->prefix . 'eleven_api_key', true );
		$byok_enabled   = get_post_meta( $post_id, $this->prefix . 'eleven_byok_enabled', true );

		// Context
		$ctx_profile    = (string) get_post_meta( $post_id, $this->prefix . 'context_profile', true );
		$ctx_services   = (string) get_post_meta( $post_id, $this->prefix . 'context_services', true );
		$ctx_rules      = (string) get_post_meta( $post_id, $this->prefix . 'context_rules', true );

		// Routing vertical
		$vertical = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
		$vertical = $vertical !== '' ? sanitize_title( $vertical ) : 'general';

		// Validation state (only ElevenLabs)
		$validation_eleven = $this->get_validation_state( (int) $post_id, 'elevenlabs' );

		$byok_bool = ! empty( $byok_enabled );

		return [
			'brain' => [
				// ✅ BYOK OpenAI removed (centralized model)
				'system_prompt' => $prompt,
			],
			'voice' => [
				'enabled'        => (bool) $voice_enabled,
				'byok_enabled'   => $byok_bool,
				'voice_id'       => $voice_id,

				// If BYOK disabled, still return obfuscated if exists (so UI can show "saved" without leaking)
				'eleven_api_key' => $this->obfuscate_key( $eleven_key_raw ),
			],
			'business' => [
				'profile'  => $ctx_profile,
				'services' => $ctx_services,
				'rules'    => $ctx_rules,
				'vertical' => $vertical,
			],
			'validation' => [
				'elevenlabs'  => $validation_eleven,
				'ttl_seconds' => (int) $this->validation_ttl_seconds,
			],
		];
	}

	public function update_settings( $request ) {
		$post_id = $this->ensure_user_instance();
		if ( is_wp_error( $post_id ) ) return $post_id;

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) $params = [];

		// Brain (OpenAI BYOK removed)
		if ( isset( $params['brain'] ) && is_array( $params['brain'] ) ) {
			$brain = $params['brain'];

			if ( isset( $brain['system_prompt'] ) ) {
				update_post_meta(
					$post_id,
					$this->prefix . 'system_prompt',
					sanitize_textarea_field( (string) $brain['system_prompt'] )
				);
			}
		}

		// Voice
		$voice_byok = (bool) get_post_meta( $post_id, $this->prefix . 'eleven_byok_enabled', true );

		if ( isset( $params['voice'] ) && is_array( $params['voice'] ) ) {
			$voice = $params['voice'];

			if ( array_key_exists( 'enabled', $voice ) ) {
				update_post_meta( $post_id, $this->prefix . 'voice_enabled', ! empty( $voice['enabled'] ) ? '1' : '0' );
			}

			if ( array_key_exists( 'byok_enabled', $voice ) ) {
				$voice_byok = ! empty( $voice['byok_enabled'] );
				update_post_meta( $post_id, $this->prefix . 'eleven_byok_enabled', $voice_byok ? '1' : '0' );
			}

			// Enforce validate-before-save ONLY when BYOK enabled AND key being changed
			$maybe_eleven = $voice['eleven_api_key'] ?? null;
			if ( $voice_byok ) {
				$err = $this->enforce_key_validation_before_save( (int) $post_id, 'elevenlabs', $maybe_eleven );
				if ( is_wp_error( $err ) ) return $err;
			}

			if ( isset( $voice['voice_id'] ) ) {
				update_post_meta( $post_id, $this->prefix . 'eleven_voice_id', sanitize_text_field( (string) $voice['voice_id'] ) );
			}

			// Only write key if provided. If BYOK disabled, we still allow storing (user may toggle later),
			// but n8n/engine should ignore it unless byok_enabled=true.
			if ( isset( $voice['eleven_api_key'] ) ) {
				$this->update_secure_key( $post_id, 'eleven_api_key', (string) $voice['eleven_api_key'] );
			}
		}

		// Business
		if ( isset( $params['business'] ) && is_array( $params['business'] ) ) {
			$biz = $params['business'];

			$fields = [
				'profile'  => 'context_profile',
				'services' => 'context_services',
				'rules'    => 'context_rules',
			];

			foreach ( $fields as $key => $meta ) {
				if ( isset( $biz[ $key ] ) ) {
					update_post_meta(
						$post_id,
						$this->prefix . $meta,
						sanitize_textarea_field( (string) $biz[ $key ] )
					);
				}
			}

			if ( isset( $biz['vertical'] ) ) {
				$vertical = sanitize_title( (string) $biz['vertical'] );
				if ( $vertical === '' ) $vertical = 'general';

				update_post_meta( $post_id, '_kurukin_business_vertical', $vertical );

				$user_id = (int) get_current_user_id();
				if ( $user_id > 0 && class_exists( Tenant_Service::class ) ) {
					Tenant_Service::ensure_routing_meta( (int) $post_id, $user_id );
				}
			}
		}

		return [ 'success' => true, 'message' => 'Configuración guardada correctamente.' ];
	}

	/**
	 * Only ElevenLabs is supported here (BYOK optional).
	 */
	public function validate_credential( $request ) {
		$post_id = $this->ensure_user_instance();
		if ( is_wp_error( $post_id ) ) return $post_id;

		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : [];
		$provider = isset( $params['provider'] ) ? (string) $params['provider'] : '';
		$key      = isset( $params['key'] ) ? (string) $params['key'] : '';

		$provider = sanitize_key( $provider );

		if ( $provider !== 'elevenlabs' ) {
			return new WP_Error( 'unknown', 'Proveedor desconocido', [ 'status' => 400 ] );
		}

		if ( strpos( $key, '****' ) !== false ) {
			return new WP_Error( 'cant_validate', 'No se puede validar una llave oculta. Pega la llave completa.', [ 'status' => 400 ] );
		}

		$key = trim( $key );
		if ( $key === '' || strlen( $key ) < 8 ) {
			return new WP_Error( 'invalid_input', 'Llave inválida (muy corta).', [ 'status' => 400 ] );
		}

		$res = wp_remote_get( 'https://api.elevenlabs.io/v1/user', [
			'headers' => [ 'xi-api-key' => $key ],
			'timeout' => 8,
		] );

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'network', $res->get_error_message(), [ 'status' => 500 ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( $code === 200 ) {
			$body  = json_decode( wp_remote_retrieve_body( $res ), true );
			$limit = (int) ( $body['subscription']['character_limit'] ?? 0 );
			$used  = (int) ( $body['subscription']['character_count'] ?? 0 );
			$chars = max( 0, $limit - $used );

			$this->mark_key_validated( (int) $post_id, 'elevenlabs', $key );

			return [ 'valid' => true, 'message' => 'Conectado. Saldo: ' . number_format( $chars ) . ' chars.' ];
		}

		if ( $code === 401 || $code === 403 ) {
			return new WP_Error( 'invalid', 'Llave ElevenLabs Incorrecta', [ 'status' => 400 ] );
		}

		return new WP_Error( 'elevenlabs_error', 'ElevenLabs respondió con error (HTTP ' . $code . ').', [ 'status' => 500 ] );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function ensure_user_instance() {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
		}

		if ( ! class_exists( Tenant_Service::class ) ) {
			return new WP_Error( 'kurukin_internal_error', 'Tenant_Service not available', [ 'status' => 500 ] );
		}

		$post_id = Tenant_Service::ensure_user_instance( $user_id );
		if ( is_wp_error( $post_id ) ) return $post_id;

		return (int) $post_id;
	}

	/**
	 * Obfuscate stored encrypted value.
	 * Keep "****" substring so frontend can detect masked values.
	 */
	private function obfuscate_key( $encrypted_val ) {
		if ( empty( $encrypted_val ) ) return '';
		return '****' . substr( md5( (string) $encrypted_val ), 0, 6 );
	}

	private function update_secure_key( $post_id, $meta_key, $new_val ) {
		// Never overwrite with masked value
		if ( strpos( $new_val, '****' ) !== false ) return;
		if ( $new_val === '' ) return;

		$key = defined( 'KURUKIN_ENCRYPTION_KEY' ) ? KURUKIN_ENCRYPTION_KEY : wp_salt( 'auth' );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'AES-256-CBC' ) );
		$enc = base64_encode( openssl_encrypt( $new_val, 'AES-256-CBC', $key, 0, $iv ) . '::' . $iv );

		update_post_meta( $post_id, $this->prefix . $meta_key, $enc );
	}

	// =========================================================================
	// ✅ Validate-before-save enforcement (ElevenLabs only)
	// =========================================================================

	private function enforce_key_validation_before_save( int $post_id, string $provider, $maybe_key ) {
		// If key is not being changed, do nothing
		if ( $maybe_key === null ) return true;

		$key = (string) $maybe_key;

		// Masked key means "unchanged"
		if ( strpos( $key, '****' ) !== false ) return true;

		$key = trim( $key );
		if ( $key === '' ) {
			// empty means "user cleared it" - allow
			return true;
		}

		$hash  = $this->hash_key( $key );
		$state = get_post_meta( $post_id, $this->validation_meta_key( $provider ), true );
		$state = is_array( $state ) ? $state : [];

		$ts   = isset( $state['ts'] ) ? (int) $state['ts'] : 0;
		$hval = isset( $state['hash'] ) ? (string) $state['hash'] : '';

		$now = time();
		$expired = ( $ts <= 0 || ( $now - $ts ) > $this->validation_ttl_seconds );

		if ( $expired || $hval === '' || ! hash_equals( $hval, $hash ) ) {
			return new WP_Error(
				'kurukin_key_not_validated',
				sprintf(
					'Debes validar la llave (%s) antes de guardar. (La validación expira en %d min).',
					$provider,
					(int) floor( $this->validation_ttl_seconds / 60 )
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	private function mark_key_validated( int $post_id, string $provider, string $key ) {
		$state = [
			'hash' => $this->hash_key( $key ),
			'ts'   => time(),
		];
		update_post_meta( $post_id, $this->validation_meta_key( $provider ), $state );
	}

	private function validation_meta_key( string $provider ) : string {
		$provider = sanitize_key( $provider );
		return $this->prefix . 'validated_' . $provider;
	}

	private function hash_key( string $key ) : string {
		// hash only; do not store raw key
		return hash( 'sha256', $key );
	}

	/**
	 * Return UI-friendly validation state without exposing hash.
	 * status: idle | valid | expired
	 */
	private function get_validation_state( int $post_id, string $provider ) : array {
		$state = get_post_meta( $post_id, $this->validation_meta_key( $provider ), true );
		$state = is_array( $state ) ? $state : [];

		$ts = isset( $state['ts'] ) ? (int) $state['ts'] : 0;
		if ( $ts <= 0 ) {
			return [
				'status'     => 'idle',
				'ts'         => null,
				'expires_in' => null,
			];
		}

		$now = time();
		$age = max( 0, $now - $ts );
		$expires_in = max( 0, (int) $this->validation_ttl_seconds - $age );

		$status = ( $expires_in > 0 ) ? 'valid' : 'expired';

		return [
			'status'     => $status,
			'ts'         => $ts,
			'expires_in' => $expires_in,
		];
	}
}
