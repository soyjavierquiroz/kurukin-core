<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Admin_Credits_Controller extends WP_REST_Controller {

	// NO typed properties (PHP 7.2 safe)
	protected $namespace = 'kurukin/v1';
	protected $resource  = 'admin/credits/set';

	public function __construct() {
		$this->register_routes();
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->resource, array(
			'methods'  => 'POST',
			'callback' => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );
	}

	/**
	 * Permisos:
	 * - Admin logged-in (manage_options) OR
	 * - Secret header x-kurukin-secret == KURUKIN_API_SECRET (server-to-server)
	 */
	public function permissions_check( WP_REST_Request $request ) {

		// Admin logged-in
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$expected = defined( 'KURUKIN_API_SECRET' ) ? (string) KURUKIN_API_SECRET : '';
		if ( $expected === '' ) {
			return new WP_Error( 'kurukin_secret_missing', 'Server secret missing', array( 'status' => 500 ) );
		}

		$secret = $request->get_header( 'x_kurukin_secret' );
		if ( ! $secret ) {
			$secret = $request->get_header( 'x-kurukin-secret' );
		}
		$secret = (string) $secret;

		if ( $secret === '' ) {
			return new WP_Error( 'kurukin_unauthorized', 'Missing x-kurukin-secret', array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $expected, $secret ) ) {
			return new WP_Error( 'kurukin_forbidden', 'Forbidden', array( 'status' => 403 ) );
		}

		return true;
	}

	public function handle( WP_REST_Request $request ) {

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$user_id    = isset( $params['user_id'] ) ? (int) $params['user_id'] : 0;
		$user_login = isset( $params['user_login'] ) ? sanitize_user( (string) $params['user_login'], true ) : '';

		$amount = isset( $params['amount'] ) ? $params['amount'] : null;
		$amount = $this->to_float( $amount );

		$mode = isset( $params['mode'] ) ? sanitize_key( (string) $params['mode'] ) : 'set';
		if ( $mode !== 'set' && $mode !== 'add' ) $mode = 'set';

		$txid = isset( $params['transaction_id'] ) ? sanitize_text_field( (string) $params['transaction_id'] ) : '';
		$note = isset( $params['note'] ) ? sanitize_text_field( (string) $params['note'] ) : '';

		if ( $amount < 0 ) {
			return new WP_Error( 'kurukin_bad_request', 'amount must be >= 0', array( 'status' => 400 ) );
		}

		$user = null;
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
		} elseif ( $user_login !== '' ) {
			$user = get_user_by( 'login', $user_login );
		}

		if ( ! $user ) {
			return new WP_Error( 'kurukin_not_found', 'User not found (user_id or user_login required)', array( 'status' => 404 ) );
		}

		$user_id = (int) $user->ID;

		// -----------------------------
		// Idempotency (optional)
		// -----------------------------
		if ( $txid !== '' ) {
			$tx_key  = '_kurukin_credits_tx_' . substr( hash( 'sha256', $txid ), 0, 24 );
			$already = get_user_meta( $user_id, $tx_key, true );
			if ( $already !== '' ) {
				$current = $this->to_float( get_user_meta( $user_id, '_kurukin_credits_balance', true ) );
				return array(
					'success' => true,
					'message' => 'Transaction already applied (idempotent).',
					'user_id' => $user_id,
					'user_login' => $user->user_login,
					'mode' => $mode,
					'amount' => (float) $amount,
					'previous_balance' => null,
					'new_balance' => (float) $current,
					'transaction_id' => $txid,
				);
			}
		}

		$prev = $this->to_float( get_user_meta( $user_id, '_kurukin_credits_balance', true ) );
		$new  = ( $mode === 'add' ) ? ( $prev + $amount ) : $amount;

		update_user_meta( $user_id, '_kurukin_credits_balance', (string) $new );
		clean_user_cache( $user_id );

		if ( $txid !== '' ) {
			$tx_key = '_kurukin_credits_tx_' . substr( hash( 'sha256', $txid ), 0, 24 );
			update_user_meta( $user_id, $tx_key, time() );
		}

		if ( $note !== '' ) {
			update_user_meta( $user_id, '_kurukin_credits_last_note', $note );
		}
		update_user_meta( $user_id, '_kurukin_credits_last_update_ts', time() );

		return array(
			'success' => true,
			'message' => 'Credits updated.',
			'user_id' => $user_id,
			'user_login' => $user->user_login,
			'mode' => $mode,
			'amount' => (float) $amount,
			'previous_balance' => (float) $prev,
			'new_balance' => (float) $new,
			'transaction_id' => $txid !== '' ? $txid : null,
		);
	}

	private function to_float( $v ) {
		if ( is_float( $v ) || is_int( $v ) ) return (float) $v;

		if ( is_string( $v ) ) {
			$s = trim( $v );
			if ( $s === '' ) return 0.0;
			$s = str_replace( ',', '.', $s );
			$s = preg_replace( '/[^0-9\.\-]/', '', $s );
			if ( $s === '' || $s === '-' || $s === '.' ) return 0.0;
			return (float) $s;
		}

		return 0.0;
	}
}
