<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_Error;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Cargamos la clase con FQN en runtime (evita fatal si no está cargada por include)
use Kurukin\Core\Services\Evolution_Service;
use Kurukin\Core\Services\Tenant_Service;

class Connection_Controller extends WP_REST_Controller {

    protected $namespace = 'kurukin/v1';
    protected $resource  = 'connection';

    private ?Evolution_Service $evolution = null;

    public function __construct() {
        // Evita fatal: solo instanciamos si la clase existe
        if ( class_exists( Evolution_Service::class ) ) {
            $this->evolution = new Evolution_Service();
        }
        $this->register_routes();
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->resource . '/status', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'permissions_check' ],
        ]);

        register_rest_route( $this->namespace, '/' . $this->resource . '/qr', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_qr_smart' ],
            'permission_callback' => [ $this, 'permissions_check' ],
        ]);

        register_rest_route( $this->namespace, '/' . $this->resource . '/reset', [
            'methods'  => 'POST',
            'callback' => [ $this, 'reset_instance' ],
            'permission_callback' => [ $this, 'permissions_check' ],
        ]);
    }

    /**
     * Permission policy (multi-tenant):
     * - must be logged in
     * - must have basic capability "read"
     * - must own the saas_instance OR be admin (manage_options)
     */
    public function permissions_check( $request ) {
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        // Basic capability gate (keeps compatibility with subscriber-based SaaS)
        if ( ! current_user_can( 'read' ) ) {
            error_log('[Kurukin] Connection permissions denied (no-read): user_id=' . $user_id);
            return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }

        // Admin override
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Ensure tenant exists and verify ownership
        if ( ! class_exists( Tenant_Service::class ) ) {
            error_log('[Kurukin] Connection permissions denied (Tenant_Service missing): user_id=' . $user_id);
            return new WP_Error( 'kurukin_internal_error', 'Tenant_Service not available', [ 'status' => 500 ] );
        }

        $post_id = Tenant_Service::ensure_user_instance( $user_id );
        if ( is_wp_error( $post_id ) ) {
            error_log('[Kurukin] Connection permissions denied (ensure_user_instance error): user_id=' . $user_id . ' code=' . $post_id->get_error_code());
            return $post_id;
        }

        $post_id    = (int) $post_id;
        $author_id  = (int) get_post_field( 'post_author', $post_id );

        if ( $author_id !== $user_id ) {
            error_log('[Kurukin] Connection permissions denied (not-owner): user_id=' . $user_id . ' post_id=' . $post_id . ' author_id=' . $author_id);
            return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }

        return true;
    }

    public function get_status() {
        try {
            $this->assert_service_ready();

            $user_id = (int) get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
            }

            $res = $this->evolution->get_connection_state( $user_id );

            // ✅ IMPORTANT: map WP_Error to a stable UI response
            if ( is_wp_error( $res ) ) {
                $mapped = $this->map_service_error( $res, 'status' );
                $data   = $mapped->get_error_data();

                // Keep response stable for frontend Badge/status handling
                return [
                    'state'   => 'network_error',
                    'message' => (string) $mapped->get_error_message(),
                    'code'    => (string) $mapped->get_error_code(),
                    'upstream_status' => (int) ( $data['upstream_status'] ?? 0 ),
                    'hint'    => (string) ( $data['hint'] ?? '' ),
                ];
            }

            // Respuesta estable
            return is_array( $res ) ? $res : [ 'state' => 'unknown' ];

        } catch ( Throwable $e ) {
            return new WP_Error( 'kurukin_internal_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function get_qr_smart() {
        try {
            $this->assert_service_ready();

            $user_id = (int) get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
            }

            $res = $this->evolution->connect_and_get_qr( $user_id );
            if ( is_wp_error( $res ) ) {
                return $this->map_service_error( $res, 'qr' );
            }

            // Respuesta estable: base64, code
            return is_array( $res ) ? $res : [ 'base64' => null, 'message' => 'Unexpected response' ];

        } catch ( Throwable $e ) {
            return new WP_Error( 'kurukin_internal_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function reset_instance() {
        try {
            $this->assert_service_ready();

            $user_id = (int) get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
            }

            $res = $this->evolution->reset_instance( $user_id );
            if ( is_wp_error( $res ) ) {
                return $this->map_service_error( $res, 'reset' );
            }

            return is_array( $res ) ? $res : [ 'ok' => true ];

        } catch ( Throwable $e ) {
            return new WP_Error( 'kurukin_internal_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    // ---------------------------------------------------------------------
    // Guardrails
    // ---------------------------------------------------------------------

    private function assert_service_ready(): void {
        if ( ! $this->evolution instanceof Evolution_Service ) {
            throw new \RuntimeException( 'Evolution_Service not available (autoload/include failed).' );
        }
    }

    private function map_service_error( WP_Error $error, string $operation ): WP_Error {
        $original_code = (string) $error->get_error_code();
        $original_msg  = (string) $error->get_error_message();
        $data          = $error->get_error_data();
        $data          = is_array( $data ) ? $data : [];

        $upstream_status = isset( $data['upstream_status'] ) ? (int) $data['upstream_status'] : 0;
        if ( $upstream_status <= 0 && preg_match( '/\((\d{3})\)/', $original_msg, $m ) ) {
            $upstream_status = (int) $m[1];
        }

        $hint = isset( $data['hint'] ) ? (string) $data['hint'] : '';
        $status = isset( $data['status'] ) ? (int) $data['status'] : 500;
        $public_code = $original_code !== '' ? $original_code : 'kurukin_connection_error';
        $public_message = $original_msg !== '' ? $original_msg : 'Error de conexión con Evolution.';

        if ( $upstream_status === 401 || $original_code === 'kurukin_upstream_unauthorized' ) {
            $public_code = 'kurukin_upstream_unauthorized';
            $public_message = 'No autorizado con Evolution, revisa key/host.';
            $hint = $hint !== '' ? $hint : 'Revisa KURUKIN_EVOLUTION_GLOBAL_KEY o la API key asignada al tenant.';
            $status = 502;
        } elseif ( $original_code === 'kurukin_missing_routing' ) {
            $public_message = 'Configuración incompleta para conectar con Evolution.';
            $hint = $hint !== '' ? $hint : 'Valida endpoint, instance_id y API key.';
            $status = 500;
        } elseif ( $original_code === 'kurukin_qr_timeout' ) {
            $public_message = 'No se pudo obtener el QR a tiempo. Intenta nuevamente.';
            $hint = $hint !== '' ? $hint : 'La instancia puede estar iniciando en Evolution.';
            $status = 504;
        } elseif ( $operation === 'qr' ) {
            $public_message = 'No se pudo obtener QR en este momento.';
            if ( $hint === '' ) {
                $hint = 'Revisa conectividad y credenciales de Evolution.';
            }
            if ( $status < 400 ) {
                $status = 502;
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[Kurukin][Connection] Error ' . $operation . ' | ' .
                wp_json_encode(
                    [
                        'code'            => $public_code,
                        'message'         => $public_message,
                        'upstream_status' => $upstream_status,
                        'hint'            => $hint,
                        'original_code'   => $original_code,
                        'original_message'=> $original_msg,
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        return new WP_Error(
            $public_code,
            $public_message,
            [
                'status'          => $status,
                'upstream_status' => $upstream_status,
                'hint'            => $hint,
                'original_code'   => $original_code,
            ]
        );
    }
}
