<?php
namespace Kurukin\Core\Integrations;

use Kurukin\Core\Services\Credits_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Integración MemberPress -> Créditos Kurukin.
 *
 * Hook principal elegido:
 * - mepr-event-transaction-completed (evento explícito de transacción completada).
 *
 * Hooks de compatibilidad:
 * - mepr-txn-status-complete
 * - mepr-transaction-status-complete
 * - mepr-transaction-completed
 *
 * Fallback estricto:
 * - save_post_mepr_transaction (solo procesa si status final es "complete").
 *
 * Option key (schema):
 * - kurukin_mp_credit_rules: array<rule>
 *   rule = {
 *     product_id:int,
 *     label:string,
 *     base_credits:string(6 decimales),
 *     bonus_percent:string(6 decimales),
 *     enabled:bool
 *   }
 *
 * Meta keys de idempotencia en la transacción:
 * - _kurukin_credits_applied
 * - _kurukin_credits_amount
 * - _kurukin_credits_rule
 * - _kurukin_credits_applied_at
 */
class MemberPress_Credits_Integration {

	const OPTION_KEY = 'kurukin_mp_credit_rules';

	const TX_META_APPLIED    = '_kurukin_credits_applied';
	const TX_META_AMOUNT     = '_kurukin_credits_amount';
	const TX_META_RULE       = '_kurukin_credits_rule';
	const TX_META_APPLIED_AT = '_kurukin_credits_applied_at';
	const TX_META_LOCK       = '_kurukin_credits_applying_lock';

	const LOG_PREFIX = '[KurukinCredits][MemberPress]';

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'bootstrap' ], 30 );
	}

	public function bootstrap(): void {
		self::ensure_default_rules();

		if ( ! class_exists( 'MeprOptions' ) ) {
			return;
		}

		add_action( 'mepr-event-transaction-completed', [ $this, 'on_event_transaction_completed' ], 10, 1 );
		add_action( 'mepr-txn-status-complete', [ $this, 'on_transaction_completed' ], 10, 1 );
		add_action( 'mepr-transaction-status-complete', [ $this, 'on_transaction_completed' ], 10, 1 );
		add_action( 'mepr-transaction-completed', [ $this, 'on_transaction_completed' ], 10, 1 );

		// Fallback: cubre instalaciones donde solo se persiste el CPT.
		add_action( 'save_post_mepr_transaction', [ $this, 'on_save_post_mepr_transaction' ], 20, 3 );
	}

	public static function ensure_default_rules(): void {
		$current = get_option( self::OPTION_KEY, null );
		if ( null !== $current && false !== $current ) {
			return;
		}

		update_option( self::OPTION_KEY, self::default_rules(), false );
	}

	public static function default_rules(): array {
		return [
			[
				'product_id'    => 12,
				'label'         => 'Lite Mensual',
				'base_credits'  => '10.000000',
				'bonus_percent' => '0.000000',
				'enabled'       => true,
			],
			[
				'product_id'    => 20,
				'label'         => 'Lite Trimestral',
				'base_credits'  => '30.000000',
				'bonus_percent' => '10.000000',
				'enabled'       => true,
			],
			[
				'product_id'    => 21,
				'label'         => 'Lite Semestral',
				'base_credits'  => '60.000000',
				'bonus_percent' => '20.000000',
				'enabled'       => true,
			],
			[
				'product_id'    => 22,
				'label'         => 'Lite Anual',
				'base_credits'  => '120.000000',
				'bonus_percent' => '30.000000',
				'enabled'       => true,
			],
		];
	}

	public static function get_rules(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return self::sanitize_rules( $raw );
	}

	public static function sanitize_rules( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			if ( $product_id <= 0 ) {
				continue;
			}

			$base  = self::sanitize_decimal_non_negative( $row['base_credits'] ?? '0' );
			$bonus = self::sanitize_decimal_non_negative( $row['bonus_percent'] ?? '0' );
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

			$normalized[ $product_id ] = [
				'product_id'    => $product_id,
				'label'         => $label,
				'base_credits'  => $base,
				'bonus_percent' => $bonus,
				'enabled'       => ! empty( $row['enabled'] ),
			];
		}

		return array_values( $normalized );
	}

	public function on_event_transaction_completed( $event ): void {
		try {
			if ( ! is_object( $event ) || ! method_exists( $event, 'get_data' ) ) {
				return;
			}

			$this->maybe_apply_credits( $event->get_data(), 'mepr-event-transaction-completed' );
		} catch ( \Throwable $e ) {
			$this->debug( 'Error en on_event_transaction_completed', [ 'error' => $e->getMessage() ] );
		}
	}

	public function on_transaction_completed( $txn ): void {
		try {
			$this->maybe_apply_credits( $txn, current_filter() ?: 'memberpress_txn_complete' );
		} catch ( \Throwable $e ) {
			$this->debug( 'Error en on_transaction_completed', [ 'error' => $e->getMessage() ] );
		}
	}

	public function on_save_post_mepr_transaction( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $update ) {
			return;
		}

		try {
			$txn = $this->load_transaction_by_id( $post_id );
			$this->maybe_apply_credits( $txn, 'save_post_mepr_transaction', $post_id );
		} catch ( \Throwable $e ) {
			$this->debug( 'Error en on_save_post_mepr_transaction', [
				'post_id' => $post_id,
				'error'   => $e->getMessage(),
			] );
		}
	}

	private function maybe_apply_credits( $txn, string $hook, int $fallback_tx_post_id = 0 ): void {
		$ctx = $this->extract_tx_context( $txn, $fallback_tx_post_id );

		if ( $ctx['tx_post_id'] <= 0 ) {
			$this->debug( 'No se pudo resolver tx_post_id. Se omite por seguridad.', [ 'hook' => $hook ] );
			return;
		}

		if ( ! $this->is_transaction_complete( $ctx, $hook ) ) {
			return;
		}

		if ( $ctx['user_id'] <= 0 || $ctx['product_id'] <= 0 ) {
			$this->debug( 'Transacción incompleta para créditos', [
				'hook'       => $hook,
				'tx_post_id' => $ctx['tx_post_id'],
				'user_id'    => $ctx['user_id'],
				'product_id' => $ctx['product_id'],
			] );
			return;
		}

		if ( $this->is_transaction_already_applied( $ctx['tx_post_id'] ) ) {
			return;
		}

		$rule = $this->find_rule_for_product( $ctx['product_id'] );
		if ( ! $rule || empty( $rule['enabled'] ) ) {
			return;
		}

		$amount = $this->calculate_amount( (string) $rule['base_credits'], (string) $rule['bonus_percent'] );
		if ( (float) $amount <= 0.0 ) {
			$this->debug( 'Monto <= 0. Se omite.', [
				'hook'       => $hook,
				'tx_post_id' => $ctx['tx_post_id'],
				'product_id' => $ctx['product_id'],
				'amount'     => $amount,
			] );
			return;
		}

		// Bloqueo liviano por transacción para evitar doble aplicación en hooks concurrentes.
		if ( ! add_post_meta( $ctx['tx_post_id'], self::TX_META_LOCK, (string) time(), true ) ) {
			return;
		}

		try {
			if ( $this->is_transaction_already_applied( $ctx['tx_post_id'] ) ) {
				return;
			}

			$reason = $ctx['subscription_id'] > 0 ? 'memberpress_renewal' : 'memberpress_purchase';
			$result = $this->apply_credits_to_user( $ctx, $amount, $reason );

			if ( is_wp_error( $result ) ) {
				$this->debug( 'No se pudo aplicar créditos', [
					'hook'       => $hook,
					'tx_post_id' => $ctx['tx_post_id'],
					'error'      => $result->get_error_message(),
				] );
				return;
			}

			$rule_snapshot = [
				'product_id'    => (int) $rule['product_id'],
				'label'         => (string) $rule['label'],
				'base_credits'  => (string) $rule['base_credits'],
				'bonus_percent' => (string) $rule['bonus_percent'],
				'enabled'       => ! empty( $rule['enabled'] ),
				'computed'      => $amount,
			];

			update_post_meta( $ctx['tx_post_id'], self::TX_META_APPLIED, '1' );
			update_post_meta( $ctx['tx_post_id'], self::TX_META_AMOUNT, $amount );
			update_post_meta(
				$ctx['tx_post_id'],
				self::TX_META_RULE,
				(string) wp_json_encode( $rule_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			);
			update_post_meta( $ctx['tx_post_id'], self::TX_META_APPLIED_AT, (string) time() );

			$this->debug( 'Créditos aplicados', [
				'hook'            => $hook,
				'tx_post_id'      => $ctx['tx_post_id'],
				'user_id'         => $ctx['user_id'],
				'product_id'      => $ctx['product_id'],
				'subscription_id' => $ctx['subscription_id'],
				'amount'          => $amount,
				'reason'          => $reason,
			] );
		} finally {
			delete_post_meta( $ctx['tx_post_id'], self::TX_META_LOCK );
		}
	}

	private function apply_credits_to_user( array $ctx, string $amount, string $reason ) {
		$user_id = (int) $ctx['user_id'];
		$tx_id   = (string) $ctx['transaction_id'];

		if ( ! class_exists( Credits_Service::class ) ) {
			return new \WP_Error( 'credits_service_missing', 'Credits_Service no está disponible.' );
		}

		if ( method_exists( Credits_Service::class, 'add_credits' ) ) {
			$context = [
				'source'          => 'memberpress',
				'ref'             => $tx_id !== '' ? 'mepr_txn:' . $tx_id : 'mepr_txn_post:' . $ctx['tx_post_id'],
				'tx_post_id'      => (int) $ctx['tx_post_id'],
				'txn_id'          => $tx_id,
				'product_id'      => (int) $ctx['product_id'],
				'subscription_id' => (int) $ctx['subscription_id'],
			];
			return Credits_Service::add_credits( $user_id, $amount, $reason, $context );
		}

		$prev = Credits_Service::get_balance_decimal( $user_id );
		$new  = Credits_Service::decimal_add( $prev, $amount );
		update_user_meta( $user_id, Credits_Service::META_BALANCE, $new );

		return [
			'user_id'        => $user_id,
			'amount'         => $amount,
			'balance_before' => $prev,
			'balance_after'  => $new,
		];
	}

	private function extract_tx_context( $txn, int $fallback_tx_post_id = 0 ): array {
		$tx_post_id = (int) $this->read_txn_value( $txn, [ 'id' ] );
		if ( $tx_post_id <= 0 ) {
			$tx_post_id = $fallback_tx_post_id;
		}

		$transaction_id = $this->read_txn_value( $txn, [ 'trans_num', 'transaction_id', 'id' ] );
		$status         = strtolower( trim( (string) $this->read_txn_value( $txn, [ 'status', 'txn_status' ] ) ) );
		$post_status    = $tx_post_id > 0 ? (string) get_post_status( $tx_post_id ) : '';

		if ( $status === '' && $tx_post_id > 0 ) {
			$status = strtolower( (string) get_post_meta( $tx_post_id, 'status', true ) );
		}

		return [
			'tx_post_id'      => (int) $tx_post_id,
			'transaction_id'  => is_scalar( $transaction_id ) ? (string) $transaction_id : '',
			'user_id'         => (int) $this->read_txn_value( $txn, [ 'user_id' ] ),
			'product_id'      => (int) $this->read_txn_value( $txn, [ 'product_id', 'membership_id' ] ),
			'subscription_id' => (int) $this->read_txn_value( $txn, [ 'subscription_id' ] ),
			'status'          => $status,
			'post_status'     => strtolower( $post_status ),
		];
	}

	private function is_transaction_complete( array $ctx, string $hook ): bool {
		$status = strtolower( (string) $ctx['status'] );
		if ( in_array( $status, [ 'complete', 'completed' ], true ) ) {
			return true;
		}

		// Si el hook en sí representa "transacción completada", aceptamos status vacío.
		$complete_hooks = [
			'mepr-event-transaction-completed',
			'mepr-txn-status-complete',
			'mepr-transaction-status-complete',
			'mepr-transaction-completed',
		];

		if ( $status === '' && in_array( $hook, $complete_hooks, true ) ) {
			return true;
		}

		return false;
	}

	private function is_transaction_already_applied( int $tx_post_id ): bool {
		return '1' === (string) get_post_meta( $tx_post_id, self::TX_META_APPLIED, true );
	}

	private function find_rule_for_product( int $product_id ): ?array {
		$rules = self::get_rules();
		foreach ( $rules as $rule ) {
			if ( (int) ( $rule['product_id'] ?? 0 ) === $product_id ) {
				return $rule;
			}
		}
		return null;
	}

	private function calculate_amount( string $base_credits, string $bonus_percent ): string {
		$base  = Credits_Service::normalize_decimal_6( $base_credits );
		$bonus = Credits_Service::normalize_decimal_6( $bonus_percent );

		if ( function_exists( 'bcmul' ) && function_exists( 'bcadd' ) && function_exists( 'bcdiv' ) ) {
			$factor = bcadd( '1', bcdiv( $bonus, '100', 12 ), 12 );
			$raw    = bcmul( $base, $factor, 12 );
			return Credits_Service::normalize_decimal_6( round( (float) $raw, 6 ) );
		}

		$amount = (float) $base * ( 1 + ( (float) $bonus / 100 ) );
		return Credits_Service::normalize_decimal_6( round( $amount, 6 ) );
	}

	private static function sanitize_decimal_non_negative( $value ): string {
		$n = Credits_Service::normalize_decimal_6( $value );
		if ( (float) $n < 0 ) {
			return '0.000000';
		}
		return $n;
	}

	private function load_transaction_by_id( int $tx_post_id ) {
		if ( class_exists( '\MeprTransaction' ) ) {
			try {
				return new \MeprTransaction( $tx_post_id );
			} catch ( \Throwable $e ) {
				// fallback abajo
			}
		}

		return [
			'id'              => $tx_post_id,
			'user_id'         => $this->read_meta_candidates( $tx_post_id, [ 'user_id', '_user_id', '_mepr_user_id' ] ),
			'product_id'      => $this->read_meta_candidates( $tx_post_id, [ 'product_id', '_product_id', '_mepr_product_id' ] ),
			'subscription_id' => $this->read_meta_candidates( $tx_post_id, [ 'subscription_id', '_subscription_id', '_mepr_subscription_id' ] ),
			'status'          => $this->read_meta_candidates( $tx_post_id, [ 'status', '_status', 'txn_status' ] ),
			'trans_num'       => $this->read_meta_candidates( $tx_post_id, [ 'trans_num', '_trans_num', 'transaction_id' ] ),
		];
	}

	private function read_meta_candidates( int $post_id, array $keys ) {
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( $value !== '' && $value !== null ) {
				return $value;
			}
		}
		return null;
	}

	private function read_txn_value( $txn, array $keys ) {
		foreach ( $keys as $key ) {
			if ( is_array( $txn ) && array_key_exists( $key, $txn ) ) {
				return $txn[ $key ];
			}

			if ( is_object( $txn ) && isset( $txn->{$key} ) ) {
				return $txn->{$key};
			}

			if ( is_object( $txn ) && method_exists( $txn, $key ) ) {
				try {
					return $txn->{$key}();
				} catch ( \Throwable $e ) {
					// seguimos con otras llaves
				}
			}
		}

		return null;
	}

	private function debug( string $message, array $context = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$line = self::LOG_PREFIX . ' ' . $message;
			if ( ! empty( $context ) ) {
				$line .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			error_log( $line );
		}
	}
}
