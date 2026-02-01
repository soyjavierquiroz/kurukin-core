<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings_Controller extends WP_REST_Controller {

    protected $namespace = 'kurukin/v1';
    protected $resource  = 'settings';
    private $prefix      = '_kurukin_';

    public function __construct() {
        $this->register_routes();
    }

    public function register_routes() {
        // GET Settings
        register_rest_route( $this->namespace, '/' . $this->resource, [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_settings' ],
            'permission_callback' => [ $this, 'permissions_check' ]
        ]);

        // POST Settings
        register_rest_route( $this->namespace, '/' . $this->resource, [
            'methods'  => 'POST',
            'callback' => [ $this, 'update_settings' ],
            'permission_callback' => [ $this, 'permissions_check' ]
        ]);

        // Validate Credential
        register_rest_route( $this->namespace, '/validate-credential', [
            'methods'  => 'POST',
            'callback' => [ $this, 'validate_credential' ],
            'permission_callback' => [ $this, 'permissions_check' ]
        ]);
    }

    public function permissions_check() {
        return is_user_logged_in();
    }

    // --- 1. GET SETTINGS (CON AUTO-HEALING) ---
    public function get_settings( $request ) {
        // CAMBIO: Usamos ensure_user_instance en lugar de solo get
        $post_id = $this->ensure_user_instance();
        
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Recuperar valores
        $prompt        = get_post_meta( $post_id, $this->prefix . 'system_prompt', true );
        $voice_enabled = get_post_meta( $post_id, $this->prefix . 'voice_enabled', true );
        $voice_id      = get_post_meta( $post_id, $this->prefix . 'eleven_voice_id', true );
        
        $ctx_profile   = get_post_meta( $post_id, $this->prefix . 'context_profile', true );
        $ctx_services  = get_post_meta( $post_id, $this->prefix . 'context_services', true );
        $ctx_rules     = get_post_meta( $post_id, $this->prefix . 'context_rules', true );

        // Manejo de Keys (Ofuscación)
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
            ]
        ];
    }

    // --- 2. UPDATE SETTINGS (CON AUTO-HEALING) ---
    public function update_settings( $request ) {
        $post_id = $this->ensure_user_instance();
        
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $params = $request->get_json_params();

        // Brain
        if ( isset( $params['brain'] ) ) {
            $brain = $params['brain'];
            if ( isset( $brain['system_prompt'] ) ) {
                update_post_meta( $post_id, $this->prefix . 'system_prompt', sanitize_textarea_field( $brain['system_prompt'] ) );
            }
            if ( isset( $brain['openai_api_key'] ) ) {
                $this->update_secure_key( $post_id, 'openai_api_key', $brain['openai_api_key'] );
            }
        }

        // Voice
        if ( isset( $params['voice'] ) ) {
            $voice = $params['voice'];
            if ( isset( $voice['enabled'] ) ) {
                update_post_meta( $post_id, $this->prefix . 'voice_enabled', $voice['enabled'] ? '1' : '0' );
            }
            if ( isset( $voice['voice_id'] ) ) {
                update_post_meta( $post_id, $this->prefix . 'eleven_voice_id', sanitize_text_field( $voice['voice_id'] ) );
            }
            if ( isset( $voice['eleven_api_key'] ) ) {
                $this->update_secure_key( $post_id, 'eleven_api_key', $voice['eleven_api_key'] );
            }
        }

        // Business
        if ( isset( $params['business'] ) ) {
            $biz = $params['business'];
            $fields = ['profile' => 'context_profile', 'services' => 'context_services', 'rules' => 'context_rules'];
            foreach ( $fields as $key => $meta ) {
                if ( isset( $biz[$key] ) ) {
                    update_post_meta( $post_id, $this->prefix . $meta, sanitize_textarea_field( $biz[$key] ) );
                }
            }
        }

        return [ 'success' => true, 'message' => 'Configuración guardada correctamente.' ];
    }

    // --- 3. VALIDATE CREDENTIAL ---
    public function validate_credential( $request ) {
        $params = $request->get_json_params();
        $provider = isset($params['provider']) ? $params['provider'] : '';
        $key      = isset($params['key']) ? $params['key'] : '';

        if ( strpos( $key, '****' ) !== false ) {
            return new WP_Error( 'cant_validate', 'No se puede validar una llave oculta.', ['status'=>400] );
        }

        if ( $provider === 'openai' ) {
            $res = wp_remote_get( 'https://api.openai.com/v1/models?limit=1', [
                'headers' => [ 'Authorization' => "Bearer $key" ], 'timeout' => 5
            ]);
            if ( is_wp_error( $res ) ) return new WP_Error( 'network', $res->get_error_message(), ['status'=>500] );
            if ( wp_remote_retrieve_response_code( $res ) === 200 ) return [ 'valid' => true, 'message' => 'Conexión OpenAI Exitosa.' ];
            return new WP_Error( 'invalid', 'Llave OpenAI Incorrecta', ['status'=>400] );
        }

        if ( $provider === 'elevenlabs' ) {
            $res = wp_remote_get( 'https://api.elevenlabs.io/v1/user', [
                'headers' => [ 'xi-api-key' => $key ], 'timeout' => 5
            ]);
            if ( is_wp_error( $res ) ) return new WP_Error( 'network', $res->get_error_message(), ['status'=>500] );
            if ( wp_remote_retrieve_response_code( $res ) === 200 ) {
                $body = json_decode( wp_remote_retrieve_body($res), true );
                $chars = $body['subscription']['character_limit'] - $body['subscription']['character_count'];
                return [ 'valid' => true, 'message' => "Conectado. Saldo: " . number_format($chars) . " chars." ];
            }
            return new WP_Error( 'invalid', 'Llave ElevenLabs Incorrecta', ['status'=>400] );
        }

        return new WP_Error( 'unknown', 'Proveedor desconocido', ['status'=>400] );
    }

    // --- HELPER: AUTO-HEALING DB ---
    private function ensure_user_instance() {
        $user_id = get_current_user_id();
        
        // 1. Buscar si existe
        $q = new WP_Query([ 
            'post_type' => 'saas_instance', 
            'author' => $user_id, 
            'posts_per_page' => 1, 
            'fields' => 'ids' 
        ]);
        
        if ( ! empty( $q->posts ) ) {
            return $q->posts[0];
        }

        // 2. Si no existe, CREARLO AHORA MISMO
        $user = wp_get_current_user();
        $instance_name = sanitize_title( $user->user_login );

        $post_data = [
            'post_title'   => 'Bot - ' . $user->user_login,
            'post_status'  => 'publish',
            'post_type'    => 'saas_instance',
            'post_author'  => $user_id
        ];

        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // 3. Inicializar Metadata Crítica
        // Vital para que Evolution y N8N encuentren este post por el ID de instancia
        update_post_meta( $post_id, '_kurukin_evolution_instance_id', $instance_name );
        update_post_meta( $post_id, '_kurukin_cluster_node', 'alpha-01' );
        update_post_meta( $post_id, '_kurukin_business_vertical', 'general' );

        return $post_id;
    }

    private function obfuscate_key( $encrypted_val ) {
        if ( empty( $encrypted_val ) ) return '';
        return 'sk-****' . substr( md5($encrypted_val), 0, 4 ); 
    }

    private function update_secure_key( $post_id, $meta_key, $new_val ) {
        if ( strpos( $new_val, '****' ) !== false ) return;
        if ( empty( $new_val ) ) return; 

        $key = defined('KURUKIN_ENCRYPTION_KEY') ? KURUKIN_ENCRYPTION_KEY : wp_salt('auth');
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( "AES-256-CBC" ) );
        $enc = base64_encode( openssl_encrypt( $new_val, "AES-256-CBC", $key, 0, $iv ) . '::' . $iv );

        update_post_meta( $post_id, $this->prefix . $meta_key, $enc );
    }
}