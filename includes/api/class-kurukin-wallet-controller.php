<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Request;
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

	public function permissions_check( WP_REST_Request $request ) {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
		}

		return true;
	}

	public function get_wallet( WP_REST_Request $request ) {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'kurukin_unauthorized', 'Login required', [ 'status' => 401 ] );
		}

		if ( ! class_exists( Tenant_Service::class ) ) {
			return new WP_Error( 'kurukin_internal_error', 'Tenant_Service not available', [ 'status' => 500 ] );
		}

		$billing = Tenant_Service::get_billing_for_user( $user_id );
		if ( is_wp_error( $billing ) ) return $billing;

		$user = wp_get_current_user();
		$user_login = ( $user && ! empty( $user->user_login ) ) ? (string) $user->user_login : '';

		$payload = [
			'credits_balance' => (float) ( $billing['credits_balance'] ?? 0.0 ),
			'can_process'     => (bool)  ( $billing['can_process'] ?? false ),
			'min_required'    => (float) ( $billing['min_required'] ?? 1.0 ),
			'source'          => (string) ( $billing['source'] ?? 'usermeta' ),

			// debug útil
			'user_id'         => (int) $user_id,
			'user_login'      => sanitize_title( $user_login ),
		];

		$resp = rest_ensure_response( $payload );
		$resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$resp->header( 'Pragma', 'no-cache' );
		$resp->header( 'Expires', '0' );

		return $resp;
	}
}
