<?php
namespace Kurukin\Core\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Credits_Service {

	const META_BALANCE     = '_kurukin_credits_balance';
	const META_GRACE_UNTIL = '_kurukin_credits_grace_until';
	const CRON_HOOK        = 'kurukin_daily_credits_expiry';
	const LOG_TABLE_SUFFIX = 'kurukin_credit_logs';

	/**
	 * Boot hooks (MemberPress + Cron).
	 */
	public static function boot() {
		// MemberPress: transaction completed (alta/renovación).
		// Big Boss indicó: mepr-event-transaction-completed (o similar).
		add_action( 'mepr-event-transaction-completed', [ __CLASS__, 'on_mepr_transaction_completed' ], 10, 1 );

		// MemberPress: cancel/expire (marcar ventana de gracia 1 año).
		// Cubrimos varios posibles eventos (no estorba si alguno no existe).
		add_action( 'mepr-event-subscription-stopped', [ __CLASS__, 'on_mepr_subscription_inactive' ], 10, 1 );
		add_action( 'mepr-event-subscription-expired', [ __CLASS__, 'on_mepr_subscription_inactive' ], 10, 1 );
		add_action( 'mepr-event-subscription-cancelled', [ __CLASS__, 'on_mepr_subscription_inactive' ], 10, 1 );

		// Cron
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_expire_balances' ] );
	}

	/**
	 * Install DB table for logs.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::log_table();
		$charset = $wpdb->get_charset_collate();

		// amount/balance_* as DECIMAL(18,6) for micro-saldos.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			type VARCHAR(32) NOT NULL DEFAULT '',
			amount DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			balance_before DECIMAL(18,6) NULL,
			balance_after DECIMAL(18,6) NULL,
			source VARCHAR(64) NOT NULL DEFAULT '',
			ref VARCHAR(191) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY type (type),
			KEY ref (ref)
		) {$charset};";

		dbDelta( $sql );
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// daily at ~03:17 server time (random-ish to avoid thundering herd)
			$ts = strtotime( 'tomorrow 03:17:00' );
			if ( ! $ts ) $ts = time() + DAY_IN_SECONDS;
			wp_schedule_event( $ts, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * MemberPress: Completed transaction -> add base credits.
	 *
	 * Expected: credit +10.000000 for membership (alta/renovación).
	 * We guard with idempotency using transaction id.
	 */
	public static function on_mepr_transaction_completed( $txn ) {
		// Be defensive: MemberPress objects vary by version.
		if ( ! is_object( $txn ) ) return;

		$user_id = 0;
		$txn_id  = '';

		// Common properties in MeprTransaction
		if ( isset( $txn->user_id ) ) $user_id = (int) $txn->user_id;
		if ( isset( $txn->id ) ) $txn_id = (string) $txn->id;

		if ( $user_id <= 0 ) return;

		// Determine if this is a membership/renewal transaction.
		// Heuristic: subscription_id present OR product_id present.
		$is_membership = false;
		if ( isset( $txn->subscription_id ) && (int) $txn->subscription_id > 0 ) $is_membership = true;
		if ( isset( $txn->product_id ) && (int) $txn->product_id > 0 ) $is_membership = true;

		// Optional: allow explicit filter to restrict which products count for base +10.
		$is_membership = (bool) apply_filters( 'kurukin_is_membership_transaction', $is_membership, $txn, $user_id );

		if ( ! $is_membership ) return;

		$ref = $txn_id !== '' ? 'mepr_txn:' . $txn_id : '';
		$amount = '10.000000';

		// Idempotency: avoid double-crediting same transaction.
		if ( $ref !== '' ) {
			$tx_key = '_kurukin_credits_tx_' . substr( hash( 'sha256', $ref ), 0, 24 );
			$already = get_user_meta( $user_id, $tx_key, true );
			if ( $already !== '' ) {
				return;
			}
		}

		$prev = self::get_balance_decimal( $user_id );
		$new  = self::decimal_add( $prev, $amount );

		update_user_meta( $user_id, self::META_BALANCE, $new );

		// If user renewed (active again), clear grace window (they recovered).
		delete_user_meta( $user_id, self::META_GRACE_UNTIL );

		if ( $ref !== '' ) {
			$tx_key = '_kurukin_credits_tx_' . substr( hash( 'sha256', $ref ), 0, 24 );
			update_user_meta( $user_id, $tx_key, time() );
		}

		$user = get_user_by( 'id', $user_id );
		$user_login = $user ? (string) $user->user_login : '';

		self::log_credit_movement( [
			'user_id'        => $user_id,
			'user_login'     => $user_login,
			'type'           => 'credit_base',
			'amount'         => $amount,
			'currency'       => 'USD',
			'balance_before' => $prev,
			'balance_after'  => $new,
			'source'         => 'memberpress',
			'ref'            => $ref !== '' ? $ref : null,
			'note'           => 'Base membership credit (+10) on transaction completed',
		] );

		clean_user_cache( $user_id );
	}

	/**
	 * MemberPress: subscription inactive/cancelled/expired -> set grace_until = now + 365 days.
	 */
	public static function on_mepr_subscription_inactive( $sub ) {
		$user_id = 0;

		if ( is_object( $sub ) ) {
			if ( isset( $sub->user_id ) ) $user_id = (int) $sub->user_id;
			if ( $user_id <= 0 && isset( $sub->user ) && is_object( $sub->user ) && isset( $sub->user->ID ) ) {
				$user_id = (int) $sub->user->ID;
			}
		}

		if ( $user_id <= 0 ) return;

		$grace_until = time() + ( 365 * DAY_IN_SECONDS );
		update_user_meta( $user_id, self::META_GRACE_UNTIL, (string) $grace_until );

		$user = get_user_by( 'id', $user_id );
		$user_login = $user ? (string) $user->user_login : '';

		self::log_credit_movement( [
			'user_id'        => $user_id,
			'user_login'     => $user_login,
			'type'           => 'grace_start',
			'amount'         => '0.000000',
			'currency'       => 'USD',
			'balance_before' => null,
			'balance_after'  => null,
			'source'         => 'memberpress',
			'ref'            => null,
			'note'           => 'Membership inactive: grace window started (1 year)',
		] );
	}

	/**
	 * Daily cron: if grace_until passed AND user still not active => set balance to 0.000000.
	 */
	public static function cron_expire_balances() {
		// Query usermeta for grace_until < now
		global $wpdb;

		$now = time();

		$meta_table = $wpdb->usermeta;
		$users_table = $wpdb->users;

		// Find candidates: grace_until exists and < now
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT um.user_id, um.meta_value AS grace_until
				 FROM {$meta_table} um
				 WHERE um.meta_key = %s
				   AND CAST(um.meta_value AS UNSIGNED) > 0
				   AND CAST(um.meta_value AS UNSIGNED) < %d
				 LIMIT 5000",
				self::META_GRACE_UNTIL,
				$now
			)
		);

		if ( ! $rows || ! is_array( $rows ) ) return;

		foreach ( $rows as $r ) {
			$user_id = isset( $r->user_id ) ? (int) $r->user_id : 0;
			if ( $user_id <= 0 ) continue;

			// If user is active again, clear grace and skip.
			if ( self::is_memberpress_active( $user_id ) ) {
				delete_user_meta( $user_id, self::META_GRACE_UNTIL );
				continue;
			}

			$prev = self::get_balance_decimal( $user_id );
			$new  = '0.000000';

			if ( $prev !== $new ) {
				update_user_meta( $user_id, self::META_BALANCE, $new );
				clean_user_cache( $user_id );
			}

			delete_user_meta( $user_id, self::META_GRACE_UNTIL );

			$user = get_user_by( 'id', $user_id );
			$user_login = $user ? (string) $user->user_login : '';

			self::log_credit_movement( [
				'user_id'        => $user_id,
				'user_login'     => $user_login,
				'type'           => 'credit_expired',
				'amount'         => $prev, // amount expired
				'currency'       => 'USD',
				'balance_before' => $prev,
				'balance_after'  => $new,
				'source'         => 'cron',
				'ref'            => null,
				'note'           => 'Grace window ended: credits expired (set to 0)',
			] );
		}
	}

	/**
	 * MemberPress "active" check.
	 * If MemberPress not installed/loaded, treat as not active.
	 */
	private static function is_memberpress_active( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) return false;

		// MemberPress often exposes MeprUser class.
		if ( class_exists( '\MeprUser' ) ) {
			try {
				$mu = new \MeprUser( $user_id );
				// Heuristic: active_product_subscriptions() returns array of active subs.
				if ( method_exists( $mu, 'active_product_subscriptions' ) ) {
					$subs = $mu->active_product_subscriptions();
					return is_array( $subs ) && count( $subs ) > 0;
				}
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Read balance as DECIMAL string with 6 decimals.
	 */
	public static function get_balance_decimal( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_BALANCE, true );
		return self::normalize_decimal_6( $raw );
	}

	/**
	 * Normalize any input into "X.YYYYYY" string.
	 */
	public static function normalize_decimal_6( $v ) {
		if ( is_string( $v ) ) {
			$s = trim( $v );
			if ( $s === '' ) return '0.000000';
			$s = str_replace( ',', '.', $s );
			$s = preg_replace( '/[^0-9\.\-]/', '', $s );
			if ( $s === '' || $s === '-' || $s === '.' ) return '0.000000';
			return self::format_6( (float) $s );
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return self::format_6( (float) $v );
		}
		return '0.000000';
	}

	private static function format_6( $f ) {
		// Always 6 decimals, dot separator.
		// (sprintf uses locale-independent dot for %F)
		return sprintf( '%.6F', (float) $f );
	}

	public static function decimal_add( $a, $b ) {
		$fa = (float) self::normalize_decimal_6( $a );
		$fb = (float) self::normalize_decimal_6( $b );
		return self::format_6( $fa + $fb );
	}

	/**
	 * Insert a row in wp_kurukin_credit_logs.
	 */
	public static function log_credit_movement( array $data ) {
		global $wpdb;

		$table = self::log_table();

		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		if ( $user_id <= 0 ) return;

		$user_login = isset( $data['user_login'] ) ? (string) $data['user_login'] : '';
		$type = isset( $data['type'] ) ? (string) $data['type'] : '';
		$amount = isset( $data['amount'] ) ? self::normalize_decimal_6( $data['amount'] ) : '0.000000';
		$currency = isset( $data['currency'] ) ? (string) $data['currency'] : 'USD';
		$bb = array_key_exists( 'balance_before', $data ) && $data['balance_before'] !== null ? self::normalize_decimal_6( $data['balance_before'] ) : null;
		$ba = array_key_exists( 'balance_after', $data ) && $data['balance_after'] !== null ? self::normalize_decimal_6( $data['balance_after'] ) : null;
		$source = isset( $data['source'] ) ? (string) $data['source'] : '';
		$ref = isset( $data['ref'] ) ? (string) $data['ref'] : null;
		$note = isset( $data['note'] ) ? (string) $data['note'] : null;

		$wpdb->insert(
			$table,
			[
				'user_id'        => $user_id,
				'user_login'     => $user_login,
				'type'           => $type,
				'amount'         => $amount,
				'currency'       => $currency,
				'balance_before' => $bb,
				'balance_after'  => $ba,
				'source'         => $source,
				'ref'            => $ref,
				'note'           => $note,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			],
			[
				'%d','%s','%s','%s','%s',
				$bb === null ? null : '%s',
				$ba === null ? null : '%s',
				'%s',
				$ref === null ? null : '%s',
				$note === null ? null : '%s',
				'%s'
			]
		);
	}

	private static function log_table() {
		global $wpdb;
		return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
	}
}
