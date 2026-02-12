<?php
/**
 * Kurukin - MemberPress Transaction Dump (debug)
 * Objetivo: descubrir qué hooks se disparan y qué campos trae la transacción
 *
 * IMPORTANTE:
 * - Solo loguea si WP_DEBUG_LOG está activo.
 * - Solo loguea si la constante KURUKIN_MEPR_DUMP está en true
 *   (para no ensuciar producción por accidente).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Toggle de seguridad (actívalo en wp-config si quieres)
// define('KURUKIN_MEPR_DUMP', true);

if ( ! defined( 'KURUKIN_MEPR_DUMP' ) || ! KURUKIN_MEPR_DUMP ) {
	return;
}

if ( ! function_exists( 'error_log' ) ) {
	return;
}

function kurukin_mepr_log( $msg, $ctx = null ) {
	$line = '[KURUKIN][MEPR] ' . $msg;
	if ( $ctx !== null ) {
		$line .= ' | ' . wp_json_encode( $ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	error_log( $line );
}

/**
 * Intento "safe" de extraer campos típicos de MemberPress:
 * - id / trans_num
 * - user_id
 * - product_id (membership)
 * - subscription_id
 */
function kurukin_mepr_extract_tx_fields( $tx ) {
	$out = array(
		'type' => is_object( $tx ) ? get_class( $tx ) : gettype( $tx ),
	);

	// Maneja tanto array como objeto
	$get = function( $k ) use ( $tx ) {
		if ( is_array( $tx ) ) return isset( $tx[$k] ) ? $tx[$k] : null;
		if ( is_object( $tx ) ) return isset( $tx->$k ) ? $tx->$k : null;
		return null;
	};

	// Campos comunes (varían por versión)
	$out['id']              = $get('id');
	$out['trans_num']       = $get('trans_num');
	$out['status']          = $get('status');
	$out['user_id']         = $get('user_id');
	$out['product_id']      = $get('product_id');      // membership/product
	$out['subscription_id'] = $get('subscription_id'); // si aplica
	$out['created_at']      = $get('created_at');
	$out['expires_at']      = $get('expires_at');
	$out['amount']          = $get('amount');
	$out['total']           = $get('total');

	return $out;
}

/**
 * Dump controlado (sin volcar objetos enormes completos)
 */
function kurukin_mepr_dump_tx( $hook_name, $tx ) {
	$fields = kurukin_mepr_extract_tx_fields( $tx );

	kurukin_mepr_log('HOOK FIRED: ' . $hook_name, $fields);

	// Si es objeto, intenta to_array() si existe
	if ( is_object( $tx ) && method_exists( $tx, 'to_array' ) ) {
		$arr = $tx->to_array();
		// recorta keys para no explotar el log
		$keys = array_slice( array_keys( $arr ), 0, 80 );
		$mini = array();
		foreach ( $keys as $k ) { $mini[$k] = $arr[$k]; }
		kurukin_mepr_log('TX to_array() keys sample', array('keys' => $keys, 'sample' => $mini));
	}
}

// ------------------------------------------------------------
// Registro de hooks posibles (varían por versión/config)
// ------------------------------------------------------------
$hooks = array(
	'mepr-event-transaction-completed',
	'mepr-event-transaction-created',
	'mepr-event-transaction-updated',
	'mepr-event-transaction-expired',
	'mepr-event-transaction-refunded',

	'mepr-event-subscription-created',
	'mepr-event-subscription-resumed',
	'mepr-event-subscription-paused',
	'mepr-event-subscription-stopped',
	'mepr-event-subscription-expired',
	'mepr-event-subscription-renewed',

	// Algunos sitios usan otros nombres/eventos:
	'mepr-transaction-status-complete',
	'mepr-transaction-completed',
);

foreach ( $hooks as $h ) {
	add_action( $h, function( $tx = null ) use ( $h ) {
		kurukin_mepr_dump_tx( $h, $tx );
	}, 10, 1 );
}

kurukin_mepr_log('mepr-dump-tx.php loaded (KURUKIN_MEPR_DUMP=true). Waiting for hooks...');
