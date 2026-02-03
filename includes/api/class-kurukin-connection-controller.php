<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_Error;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Cargamos la clase con FQN en runtime (evita fatal si no está cargada por include)
use Kurukin\Core\Services\Evolution_Service;

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

    public function permissions_check() {
        if ( is_user_logged_in() ) return true;
        return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
    }

    public function get_status() {
        try {
            $this->assert_service_ready();

            $user_id = get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
            }

            // Se espera que Evolution_Service devuelva array o WP_Error
            $res = $this->evolution->get_connection_state( $user_id );

            // Si viene WP_Error, lo devolvemos tal cual (REST lo serializa)
            if ( is_wp_error( $res ) ) return $res;

            // Respuesta estable
            return is_array( $res ) ? $res : [ 'state' => 'unknown' ];

        } catch ( Throwable $e ) {
            return new WP_Error( 'kurukin_internal_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function get_qr_smart() {
        try {
            $this->assert_service_ready();

            $user_id = get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
            }

            $res = $this->evolution->connect_and_get_qr( $user_id );
            if ( is_wp_error( $res ) ) return $res;

            // Respuesta estable: base64, code
            return is_array( $res ) ? $res : [ 'base64' => null, 'message' => 'Unexpected response' ];

        } catch ( Throwable $e ) {
            return new WP_Error( 'kurukin_internal_error', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    public function reset_instance() {
        try {
            $this->assert_service_ready();

            $user_id = get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
            }

            $res = $this->evolution->reset_instance( $user_id );
            if ( is_wp_error( $res ) ) return $res;

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
}
