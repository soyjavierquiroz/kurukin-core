<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_Error;

use Kurukin\Core\Services\Tenant_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Wallet_Controller extends WP_REST_Controller {

    protected $namespace = 'kurukin/v1';
    protected $resource  = 'wallet';

    public function __construct() {
        $this->register_routes();
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->resource, [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_wallet' ],
            'permission_callback' => [ $this, 'permissions_check' ],
        ]);
    }

    public function permissions_check( $request ) {
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        if ( ! current_user_can( 'read' ) ) {
            return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }

        if ( current_user_can( 'manage_options' ) ) return true;

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

    public function get_wallet() {
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        if ( ! class_exists( Tenant_Service::class ) ) {
            return new WP_Error( 'kurukin_internal_error', 'Tenant_Service not available', [ 'status' => 500 ] );
        }

        $billing = Tenant_Service::get_billing_state( $user_id );
        if ( is_wp_error( $billing ) ) return $billing;

        return [
            'credits_balance' => (float) ( $billing['credits_balance'] ?? 0.0 ),
            'can_process'     => (bool)  ( $billing['can_process'] ?? false ),
            'threshold'       => (float) ( $billing['threshold'] ?? 0.01 ),
        ];
    }
}
