<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use WP_Query;

use Kurukin\Core\Services\Tenant_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings_Controller extends WP_REST_Controller {

	protected $namespace = 'kurukin/v1';
	protected $resource  = 'settings';
	private $prefix      = '_kurukin_';

	public function __construct() {
		$this->register_routes();
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->resource, [
			'methods'  => 'GET',
			'callback' => [ $this, 'get_settings' ],
			'permission_callback' => [ $this, 'permissions_check' ]
		]);

		register_rest_route( $this->namespace, '/' . $this->resource, [
			'methods'  => 'POST',
			'callback' => [ $this, 'update_settings' ],
			'permission_callback' => [ $this, 'permissions_check' ]
		]);

		register_rest_route( $this->namespace, '/validate-credential', [
			'methods'  => 'POST',
			'callback' => [ $this, 'validate_credential' ],
			'permission_callback' => [ $this, 'permissions_check' ]
		]);
	}

	public function permissions_check() {
		return is_user_logged_in();
	}

	public function get_settings( $request ) {
		$post_id = $this->ensure_user_instance();
		if ( is_wp_error( $post_id ) ) return $post_id;

		$prompt        = (string) get_post_meta( $post_id, $this->prefix . 'system_prompt', true );
		$voice_enabled = get_post_meta( $post_id, $this->prefix . 'voice_enabled', true );
		$voice_id      = (string) get_post_meta( $post_id, $this->prefix . 'eleven_voice_id', true );

		$ctx_profile   = (string) get_post_meta( $post_id, $this->prefix . 'context_profile', true );
		$ctx_services  = (string) get_post_meta( $post_id, $this->prefix . 'context_services', true );
		$ctx_rules     = (string) get_post_meta( $post_id, $this->prefix . 'context_rules', true );

		$vertical      = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
		$vertical      = $vertical !== '' ? sanitize_title( $vertical ) : 'general';

		$openai_key_raw = get_post_meta( $post_id, $this->prefix . 'openai_api_key', true );
		$eleven_key_raw = get_post_meta( $post_id, $this->prefix . 'eleven_api_key', true );

		return [
			'brain' => [
				'system_prompt'  => $prompt,
				'openai_api_key' => $this->obfuscate_key( $openai_key_raw ),
			],
			'voice' => [
				'enabled'        => (bool) $voice_enabled,
				'voice_id'       => $voice_id,
				'eleven_api_key' => $this->obfuscate_key( $eleven_key_raw ),
			],
			'business' => [
				'profile'  => $ctx_profile,
				'services' => $ctx_services,
				'rules'    => $ctx_rules,
				'vertical' => $vertical,
			]
		];
	}

	public function update_settings( $request ) {
		$post_id = $this->ensure_user_instance();
		if ( is_wp_error( $post_id ) ) return $post_id;

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) $params = [];

		// Brain
		if ( isset( $params['brain'] ) && is_array( $params['brain'] ) ) {
			$brain = $params['brain'];

			if ( isset( $brain['system_prompt'] ) ) {
				update_post_meta(
					$post_id,
					$this->prefix . 'system_prompt',
					sanitize_textarea_field( (string) $brain['system_prompt'] )
				);
			}

			if ( isset( $brain['openai_api_key'] ) ) {
				$this->update_secure_key( $post_id, 'openai_api_key', (string) $brain['openai_api_key'] );
			}
		}

		// Voice
		if ( isset( $params['voice'] ) && is_array( $params['voice'] ) ) {
			$voice = $params['voice'];

			if ( array_key_exists( 'enabled', $voice ) ) {
				update_post_meta( $post_id, $this->prefix . 'voice_enabled', ! empty( $voice['enabled'] ) ? '1' : '0' );
			}

			if ( isset( $voice['voice_id'] ) ) {
				update_post_meta( $post_id, $this->prefix . 'eleven_voice_id', sanitize_text_field( (string) $voice['voice_id'] ) );
			}

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

			// Vertical (routing)
			if ( isset( $biz['vertical'] ) ) {
				$vertical = sanitize_title( (string) $biz['vertical'] );
				if ( $vertical === '' ) $vertical = 'general';

				update_post_meta( $post_id, '_kurukin_business_vertical', $vertical );

				// Auto-heal infra assignment using Tenant_Service (removes dependency on non-existent helper)
				$user_id = (int) get_current_user_id();
				if ( $user_id > 0 && class_exists( Tenant_Service::class ) ) {
					Tenant_Service::ensure_routing_meta( (int) $post_id, $user_id );
				}
			}
		}

		return [ 'success' => true, 'message' => 'Configuración guardada correctamente.' ];
	}

	public function validate_credential( $request ) {
		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : [];
		$provider = isset( $params['provider'] ) ? (string) $params['provider'] : '';
		$key      = isset( $params['key'] ) ? (string) $params['key'] : '';

		if ( strpos( $key, '****' ) !== false ) {
			return new WP_Error( 'cant_validate', 'No se puede validar una llave oculta.', [ 'status' => 400 ] );
		}

		if ( $provider === 'openai' ) {
			$res = wp_remote_get( 'https://api.openai.com/v1/models?limit=1', [
				'headers' => [ 'Authorization' => "Bearer $key" ],
				'timeout' => 5,
			] );

			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'network', $res->get_error_message(), [ 'status' => 500 ] );
			}

			if ( wp_remote_retrieve_response_code( $res ) === 200 ) {
				return [ 'valid' => true, 'message' => 'Conexión OpenAI Exitosa.' ];
			}

			return new WP_Error( 'invalid', 'Llave OpenAI Incorrecta', [ 'status' => 400 ] );
		}

		if ( $provider === 'elevenlabs' ) {
			$res = wp_remote_get( 'https://api.elevenlabs.io/v1/user', [
				'headers' => [ 'xi-api-key' => $key ],
				'timeout' => 5,
			] );

			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'network', $res->get_error_message(), [ 'status' => 500 ] );
			}

			if ( wp_remote_retrieve_response_code( $res ) === 200 ) {
				$body  = json_decode( wp_remote_retrieve_body( $res ), true );
				$limit = (int) ( $body['subscription']['character_limit'] ?? 0 );
				$used  = (int) ( $body['subscription']['character_count'] ?? 0 );
				$chars = max( 0, $limit - $used );

				return [ 'valid' => true, 'message' => 'Conectado. Saldo: ' . number_format( $chars ) . ' chars.' ];
			}

			return new WP_Error( 'invalid', 'Llave ElevenLabs Incorrecta', [ 'status' => 400 ] );
		}

		return new WP_Error( 'unknown', 'Proveedor desconocido', [ 'status' => 400 ] );
	}

	/**
	 * Ensures current user has a saas_instance.
	 * Delegates auto-heal infra routing to Tenant_Service (single source of truth).
	 */
	private function ensure_user_instance() {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
		}

		if ( ! class_exists( Tenant_Service::class ) ) {
			return new WP_Error( 'kurukin_internal_error', 'Tenant_Service not available', [ 'status' => 500 ] );
		}

		$post_id = Tenant_Service::ensure_user_instance( $user_id );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return (int) $post_id;
	}

	private function obfuscate_key( $encrypted_val ) {
		if ( empty( $encrypted_val ) ) return '';
		return 'sk-****' . substr( md5( (string) $encrypted_val ), 0, 4 );
	}

	private function update_secure_key( $post_id, $meta_key, $new_val ) {
		if ( strpos( $new_val, '****' ) !== false ) return;
		if ( $new_val === '' ) return;

		$key = defined( 'KURUKIN_ENCRYPTION_KEY' ) ? KURUKIN_ENCRYPTION_KEY : wp_salt( 'auth' );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'AES-256-CBC' ) );
		$enc = base64_encode( openssl_encrypt( $new_val, 'AES-256-CBC', $key, 0, $iv ) . '::' . $iv );

		update_post_meta( $post_id, $this->prefix . $meta_key, $enc );
	}
}
