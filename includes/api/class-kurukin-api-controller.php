<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Controller extends WP_REST_Controller {

    private $namespace = 'kurukin/v1';
    private $resource  = 'config';

    public function __construct() {
        // En el hook rest_api_init, registramos directo
        $this->register_routes();
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->resource, [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_config' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'instance_id' => [
                        'required' => true,
                        // Ahora el ID es el username, así que validamos string simple
                        'validate_callback' => function($param) { return is_string($param); }
                    ]
                ]
            ]
        ]);
    }

    public function check_permission( WP_REST_Request $request ) {
        $secret = $request->get_header( 'x_kurukin_secret' );
        if ( empty( $secret ) ) $secret = $request->get_header( 'x-kurukin-secret' );

        $expected = defined( 'KURUKIN_API_SECRET' ) ? KURUKIN_API_SECRET : '';

        if ( empty( $expected ) ) return new WP_Error( '500', 'Server Secret Missing', [ 'status' => 500 ] );
        
        return hash_equals( (string)$expected, (string)$secret ) ? true : new WP_Error( '403', 'Forbidden', [ 'status' => 403 ] );
    }

    public function get_config( WP_REST_Request $request ) {
        // El instance_id que nos manda N8N/Evolution es el username (ej: javierquiroz)
        $target_username = sanitize_title( $request->get_param( 'instance_id' ) );

        // 1. Buscamos el post vinculado a ese username (vía meta)
        $query = new WP_Query([
            'post_type'      => 'saas_instance',
            'meta_key'       => '_kurukin_evolution_instance_id',
            'meta_value'     => $target_username,
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ]);

        if ( empty( $query->posts ) ) {
            return new WP_Error( '404', 'Instance Not Found', [ 'status' => 404 ] );
        }

        $post_id = $query->posts[0];
        $author_id = get_post_field( 'post_author', $post_id );

        // 2. 🛡️ MEMBERPRESS GATEKEEPER 🛡️
        // Verificamos si MemberPress está activo y si el usuario paga
        if ( class_exists( 'MeprUser' ) ) {
            $mepr_user = new \MeprUser( $author_id );
            
            // is_active() verifica si tiene CUALQUIER suscripción activa
            if ( ! $mepr_user->is_active() ) {
                return new WP_Error( '402', 'Payment Required: Subscription Inactive', [ 'status' => 402 ] );
            }
        }

        // 3. Si pagó, entregamos los secretos
        $vertical = get_post_meta( $post_id, '_kurukin_business_vertical', true );
        $prompt   = get_post_meta( $post_id, '_kurukin_system_prompt', true );
        $enc_key  = get_post_meta( $post_id, '_kurukin_openai_api_key', true );
        
        // Decrypt
        $api_key = '';
        if ( ! empty( $enc_key ) ) {
            $method = "AES-256-CBC";
            $key    = defined('KURUKIN_ENCRYPTION_KEY') ? KURUKIN_ENCRYPTION_KEY : wp_salt('auth');
            $parts  = explode( '::', base64_decode( $enc_key ), 2 );
            if ( count( $parts ) >= 2 ) {
                $api_key = openssl_decrypt( $parts[0], $method, $key, 0, $parts[1] );
            }
        }

        return rest_ensure_response([
            "status" => "success",
            "router_logic" => [
                "workflow_mode" => $vertical ?: "default",
                "version"       => "1.0",
                "plan_status"   => "active" // Flag útil para el frontend
            ],
            "ai_brain" => [
                "provider"      => "openai",
                "api_key"       => $api_key,
                "model"         => "gpt-4o",
                "system_prompt" => $prompt
            ],
            "business_data" => []
        ]);
    }
}