<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Controller extends WP_REST_Controller {

    protected $namespace = 'kurukin/v1';
    protected $resource  = 'config';

    public function __construct() {
        $this->register_routes();
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->resource, [
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'get_config' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args' => [
                    'instance_id' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ],
        ] );
    }

    public function check_permission( WP_REST_Request $request ) {
        $secret   = $request->get_header( 'x_kurukin_secret' ) ?: $request->get_header( 'x-kurukin-secret' );
        $expected = defined( 'KURUKIN_API_SECRET' ) ? (string) KURUKIN_API_SECRET : '';

        if ( $expected === '' ) {
            return new WP_Error( '500', 'Server Secret Missing', [ 'status' => 500 ] );
        }

        return hash_equals( $expected, (string) $secret )
            ? true
            : new WP_Error( '403', 'Forbidden', [ 'status' => 403 ] );
    }

    public function get_config( WP_REST_Request $request ) {
        // 0) instance_id (hardening: only allow a-z0-9_-)
        $instance_id = strtolower( trim( (string) $request->get_param( 'instance_id' ) ) );
        $instance_id = preg_replace( '/[^a-z0-9_\-]/', '', $instance_id );

        if ( $instance_id === '' ) {
            return new WP_Error( '400', 'instance_id required', [ 'status' => 400 ] );
        }

        // 1) Encontrar el tenant (saas_instance) dueño de ese instance_id
        $query = new WP_Query([
            'post_type'      => 'saas_instance',
            'post_status'    => 'publish',
            'meta_key'       => '_kurukin_evolution_instance_id',
            'meta_value'     => $instance_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if ( empty( $query->posts ) ) {
            return new WP_Error( '404', 'Instance Not Found', [ 'status' => 404 ] );
        }

        $post_id   = (int) $query->posts[0];
        $author_id = (int) get_post_field( 'post_author', $post_id );

        // 2) MemberPress Check (si existe)
        if ( class_exists( 'MeprUser' ) ) {
            $mepr_user = new \MeprUser( $author_id );
            if ( ! $mepr_user->is_active() ) {
                return new WP_Error( '402', 'Payment Required', [ 'status' => 402 ] );
            }
        }

        // 3) Recuperar datos tenant
        $prefix   = '_kurukin_';
        $vertical = (string) get_post_meta( $post_id, $prefix . 'business_vertical', true );
        $node     = (string) get_post_meta( $post_id, $prefix . 'cluster_node', true );
        $prompt   = (string) get_post_meta( $post_id, $prefix . 'system_prompt', true );

        $vertical = $vertical !== '' ? sanitize_title( $vertical ) : 'general';
        $node     = $node !== '' ? sanitize_text_field( $node ) : 'alpha-01';

        // Voz
        $voice_enabled = get_post_meta( $post_id, $prefix . 'voice_enabled', true );
        $voice_id      = (string) get_post_meta( $post_id, $prefix . 'eleven_voice_id', true );
        $voice_model   = (string) get_post_meta( $post_id, $prefix . 'eleven_model_id', true );

        // Contexto
        $ctx_profile  = (string) get_post_meta( $post_id, $prefix . 'context_profile', true );
        $ctx_services = (string) get_post_meta( $post_id, $prefix . 'context_services', true );
        $ctx_rules    = (string) get_post_meta( $post_id, $prefix . 'context_rules', true );

        // Keys (de negocio)
        $openai_key = $this->decrypt( get_post_meta( $post_id, $prefix . 'openai_api_key', true ) );
        $eleven_key = $this->decrypt( get_post_meta( $post_id, $prefix . 'eleven_api_key', true ) );

        // 4) ✅ Infra Multi-tenant: Evolution connection (PRIORIDAD: META DEL TENANT)
        $tenant_evo_endpoint = (string) get_post_meta( $post_id, '_kurukin_evolution_endpoint', true );
        $tenant_evo_apikey   = (string) get_post_meta( $post_id, '_kurukin_evolution_apikey', true );

        $fallback_evo_endpoint = defined( 'KURUKIN_EVOLUTION_URL' ) ? (string) KURUKIN_EVOLUTION_URL : '';
        $fallback_evo_apikey   = defined( 'KURUKIN_EVOLUTION_GLOBAL_KEY' ) ? (string) KURUKIN_EVOLUTION_GLOBAL_KEY : '';

        $evo_endpoint = $tenant_evo_endpoint !== '' ? $tenant_evo_endpoint : $fallback_evo_endpoint;
        $evo_apikey   = $tenant_evo_apikey   !== '' ? $tenant_evo_apikey   : $fallback_evo_apikey;

        // Normalización mínima del endpoint (no forzar https; puede ser red interna http)
        $evo_endpoint = trim( $evo_endpoint );

        // 5) Build Business Data
        $business_data = [];
        if ( $ctx_profile !== '' )  { $business_data[] = [ 'category' => 'COMPANY_PROFILE', 'content' => $ctx_profile ]; }
        if ( $ctx_services !== '' ) { $business_data[] = [ 'category' => 'SERVICES_LIST',   'content' => $ctx_services ]; }
        if ( $ctx_rules !== '' )    { $business_data[] = [ 'category' => 'RULES',           'content' => $ctx_rules ]; }

        // 6) ✅ Respuesta final: incluye evolution_connection
        $payload = [
            'status'     => 'success',
            'instance_id' => $instance_id,

            'router_logic' => [
                'workflow_mode' => $vertical,
                'cluster_node'  => $node,
                'version'       => '1.3',
            ],

            'ai_brain' => [
                'provider'      => 'openai',
                'api_key'       => $openai_key,
                'model'         => 'gpt-4o',
                'system_prompt' => $prompt,
            ],

            'voice_config' => [
                'provider' => 'elevenlabs',
                'enabled'  => (bool) $voice_enabled,
                'api_key'  => $eleven_key,
                'voice_id' => $voice_id,
                'model_id' => $voice_model,
            ],

            'business_data' => $business_data,

            // --- ✅ NUEVO BLOQUE MULTI-TENANT PARA N8N ---
            'evolution_connection' => [
                'endpoint' => $evo_endpoint,
                'apikey'   => $evo_apikey,
            ],
        ];

        $resp = rest_ensure_response( $payload );

        // (Opcional PRO) evita caching si te preocupa que proxies cacheen apikey
        // $resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

        return $resp;
    }

    private function decrypt( $data ): string {
        if ( empty( $data ) ) return '';

        $key = defined( 'KURUKIN_ENCRYPTION_KEY' ) ? (string) KURUKIN_ENCRYPTION_KEY : (string) wp_salt( 'auth' );

        $decoded = base64_decode( (string) $data, true );
        if ( $decoded === false ) return '';

        $parts = explode( '::', $decoded, 2 );
        if ( count( $parts ) < 2 ) return '';

        $plain = openssl_decrypt( $parts[0], 'AES-256-CBC', $key, 0, $parts[1] );
        if ( $plain === false ) return '';

        return (string) $plain;
    }
}
