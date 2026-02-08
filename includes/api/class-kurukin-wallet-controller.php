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

	/**
	 * Normaliza un decimal a escala fija con redondeo HALF_UP,
	 * evitando floats (crítico para micro-saldos).
	 *
	 * - Entrada puede ser string/float/int, pero se procesa como string.
	 * - Salida SIEMPRE string con exactamente $scale decimales.
	 */
	private function format_decimal_string( $value, $scale = 6 ) {
		$s = trim( (string) $value );
		if ( $s === '' ) {
			return '0.' . str_repeat( '0', (int) $scale );
		}

		// Signo
		$neg = false;
		if ( isset( $s[0] ) && $s[0] === '-' ) {
			$neg = true;
			$s = substr( $s, 1 );
		}

		// Normaliza separador decimal
		$s = str_replace( ',', '.', $s );

		// Limpia caracteres (deja dígitos y punto)
		$s = preg_replace( '/[^0-9.]/', '', $s );
		if ( $s === '' || $s === '.' ) {
			return '0.' . str_repeat( '0', (int) $scale );
		}

		// Split entero/decimal
		$parts = explode( '.', $s, 2 );
		$int   = isset( $parts[0] ) ? $parts[0] : '0';
		$dec   = isset( $parts[1] ) ? $parts[1] : '';

		$int = ltrim( $int, '0' );
		$int = ( $int === '' ) ? '0' : $int;

		$scale = (int) $scale;
		if ( $scale < 0 ) $scale = 0;

		// Asegura al menos scale+1 para redondeo mirando el siguiente dígito
		$dec = str_pad( $dec, $scale + 1, '0', STR_PAD_RIGHT );

		$round_digit = (int) $dec[ $scale ];     // dígito #(scale+1)
		$dec_main    = substr( $dec, 0, $scale );// primeros scale dígitos

		// Redondeo HALF_UP
		if ( $round_digit >= 5 && $scale > 0 ) {
			$carry   = 1;
			$dec_arr = str_split( $dec_main );

			for ( $i = $scale - 1; $i >= 0; $i-- ) {
				$d = (int) $dec_arr[ $i ] + $carry;
				if ( $d >= 10 ) {
					$dec_arr[ $i ] = '0';
					$carry = 1;
				} else {
					$dec_arr[ $i ] = (string) $d;
					$carry = 0;
					break;
				}
			}

			$dec_main = implode( '', $dec_arr );

			// Si quedó carry, suma 1 al entero (string)
			if ( $carry === 1 ) {
				$int_arr = str_split( $int );
				$carry2 = 1;

				for ( $j = count( $int_arr ) - 1; $j >= 0; $j-- ) {
					$d = (int) $int_arr[ $j ] + $carry2;
					if ( $d >= 10 ) {
						$int_arr[ $j ] = '0';
						$carry2 = 1;
					} else {
						$int_arr[ $j ] = (string) $d;
						$carry2 = 0;
						break;
					}
				}

				if ( $carry2 === 1 ) {
					array_unshift( $int_arr, '1' );
				}

				$int = implode( '', $int_arr );
			}
		}

		// Caso scale=0 (sin decimales): HALF_UP mirando round_digit
		if ( $scale === 0 ) {
			if ( $round_digit >= 5 ) {
				$int_arr = str_split( $int );
				$carry = 1;
				for ( $j = count( $int_arr ) - 1; $j >= 0; $j-- ) {
					$d = (int) $int_arr[ $j ] + $carry;
					if ( $d >= 10 ) {
						$int_arr[ $j ] = '0';
						$carry = 1;
					} else {
						$int_arr[ $j ] = (string) $d;
						$carry = 0;
						break;
					}
				}
				if ( $carry === 1 ) array_unshift( $int_arr, '1' );
				$int = implode( '', $int_arr );
			}
			$out = $int;
			return $neg ? ( '-' . $out ) : $out;
		}

		$out = $int . '.' . str_pad( $dec_main, $scale, '0', STR_PAD_RIGHT );
		return $neg ? ( '-' . $out ) : $out;
	}

	private function safe_numeric_string( $value ) {
		// Devuelve un string "limpio" para el balance, sin forzar float.
		if ( $value === null ) return '0';
		$s = trim( (string) $value );
		if ( $s === '' ) return '0';
		$s = str_replace( ',', '.', $s );
		$s = preg_replace( '/[^0-9.\-]/', '', $s );
		if ( $s === '' || $s === '-' || $s === '.' ) return '0';
		return $s;
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

		// --- Precision-safe balance ---
		$raw_balance     = $this->safe_numeric_string( $billing['credits_balance'] ?? '0' );
		$balance_precise = $this->format_decimal_string( $raw_balance, 6 ); // STRING con 6 decimales

		// min_required sigue siendo “fiat-like”; lo dejamos numérico para UI/logic
		$min_required = isset( $billing['min_required'] ) ? (float) $billing['min_required'] : 1.0;

		$payload = [
			// IMPORTANTE: string para no perder micro-saldos en transporte JSON
			'credits_balance' => $balance_precise,

			'can_process'     => (bool)  ( $billing['can_process'] ?? false ),
			'min_required'    => (float) $min_required,
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
