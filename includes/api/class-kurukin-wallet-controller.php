<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_Error;

use Kurukin\Core\Services\Tenant_Service;
use Kurukin\Core\Services\Credits_Service;

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

		// ✅ Fuente oficial: usermeta, con precisión 6 decimales (string)
		$balance_str = null;
		if ( class_exists( Credits_Service::class ) ) {
			$balance_str = Credits_Service::get_balance_decimal( $user_id ); // "149.979517"
		} else {
			// Fallback defensivo (no ideal, pero evita romper)
			$raw = get_user_meta( $user_id, '_kurukin_credits_balance', true );
			$balance_str = is_string( $raw ) ? $raw : (string) $raw;
		}

		$min_required = isset( $billing['min_required'] ) ? $billing['min_required'] : 1.0;
		$min_str = class_exists( Credits_Service::class ) ? Credits_Service::normalize_decimal_6( $min_required ) : sprintf('%.6F', (float)$min_required);

		$payload = [
			// ✅ string para no perder micro-saldos en JSON/float
			'credits_balance' => (string) $balance_str,

			'can_process'     => (bool)  ( $billing['can_process'] ?? false ),

			// ✅ también en string por consistencia
			'min_required'    => (string) $min_str,

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
