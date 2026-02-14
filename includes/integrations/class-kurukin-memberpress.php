<?php
namespace Kurukin\Core\Integrations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Kurukin ↔ MemberPress Integration
 *
 * Objetivo:
 * - Leer vertical desde el custom field de MemberPress (mepr_kurukin_vertical).
 * - Guardar vertical en:
 *    - user_meta: _kurukin_vertical (rápido)
 *    - saas_instance meta: _kurukin_business_vertical (fuente de verdad tenant)
 * - Disparar provisioning:
 *    - Post-Checkout (transaction complete) para paid/trial
 *    - Fallback por primer login (wp_login) para casos edge/admin-created
 */
class MemberPress_Integration {

    /**
     * Field key exacto en MemberPress (wp_options -> custom_fields -> field_key)
     * Confirmado por ti: mepr_kurukin_vertical
     */
    private const MP_FIELD_KEY = 'mepr_kurukin_vertical';

    /**
     * Meta keys Kurukin
     */
    private const KURUKIN_USERMETA_VERTICAL  = '_kurukin_vertical';
    private const KURUKIN_INSTANCE_META_VERT = '_kurukin_business_vertical';
    private const KURUKIN_CREDITS_BALANCE    = '_kurukin_credits_balance';
    private const TX_META_PREFIX             = '_kurukin_mp_initial_credit_tx_';
    private const SUB_META_PREFIX            = '_kurukin_mp_initial_credit_sub_';
    private const LOG_PREFIX                 = '[kurukin-core][memberpress]';

    public function __construct() {
        // Esperar a que plugins estén cargados antes de tocar MemberPress.
        add_action( 'plugins_loaded', [ $this, 'bootstrap' ], 20 );

        // Fallback: normaliza perfil al crear usuario (MemberPress crea con email en algunos flujos).
        add_action( 'user_register', [ $this, 'handle_user_register' ], 20, 2 );

        // Fallback: primer login
        add_action( 'wp_login', [ $this, 'handle_wp_login' ], 20, 2 );
    }

    public function bootstrap(): void {
        // MemberPress no está instalado/activo.
        if ( ! class_exists( 'MeprOptions' ) ) {
            return;
        }

        /**
         * Hook moderno: transacción completada (incluye muchos flujos de paid/trial)
         * Recibe $event con get_data() -> MeprTransaction.
         */
        add_action( 'mepr-event-transaction-completed', [ $this, 'handle_transaction_completed_event' ], 10, 1 );

        /**
         * Hook legacy: estado de transacción "complete".
         * Recibe directamente $txn (MeprTransaction).
         */
        add_action( 'mepr-txn-status-complete', [ $this, 'handle_transaction_status_complete' ], 10, 1 );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public function handle_transaction_completed_event( $event ): void {
        try {
            if ( ! is_object( $event ) || ! method_exists( $event, 'get_data' ) ) {
                $this->debug( 'hook skip: invalid event payload', [
                    'hook' => 'mepr-event-transaction-completed',
                ] );
                return;
            }

            $txn = $event->get_data();
            $this->handle_transaction( $txn, 'mepr-event-transaction-completed' );
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: handle_transaction_completed_event error', [
                'error' => $e->getMessage(),
            ] );
        }
    }

    public function handle_transaction_status_complete( $txn ): void {
        try {
            $this->handle_transaction( $txn, 'mepr-txn-status-complete' );
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: handle_transaction_status_complete error', [
                'error' => $e->getMessage(),
            ] );
        }
    }

    public function handle_user_register( int $user_id, array $userdata ): void {
        try {
            $this->normalize_user_profile( $user_id, 'user_register' );
        } catch ( \Throwable $e ) {
            $this->debug( 'hook error: user_register', [
                'user_id' => $user_id,
                'error'   => $e->getMessage(),
            ] );
        }
    }

    /**
     * Fallback: provisioning al primer login (cubre casos donde el usuario se crea por admin,
     * o flows raros donde no disparó el hook de transacción).
     */
    public function handle_wp_login( string $user_login, $user ): void {
        try {
            if ( ! $user || ! is_object( $user ) || empty( $user->ID ) ) {
                return;
            }

            $user_id = (int) $user->ID;

            // Evitar reprovision constante: si ya existe vertical en user_meta, igual forzamos tenant meta
            // (es barato y nos asegura consistencia)
            $vertical = $this->resolve_vertical( $user_id );
            $post_id  = $this->ensure_saas_instance_for_user( $user_id );

            if ( $post_id > 0 ) {
                update_post_meta( $post_id, self::KURUKIN_INSTANCE_META_VERT, $vertical );
            }

            $current = (string) get_user_meta( $user_id, self::KURUKIN_USERMETA_VERTICAL, true );
            if ( $current === '' || $current !== $vertical ) {
                update_user_meta( $user_id, self::KURUKIN_USERMETA_VERTICAL, $vertical );
            }

            $this->log( 'memberpress: provisioned on wp_login', [
                'user_id'  => $user_id,
                'vertical' => $vertical,
                'post_id'  => $post_id,
            ] );

            $this->normalize_user_profile( $user_id, 'wp_login' );
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: handle_wp_login error', [
                'error' => $e->getMessage(),
            ] );
        }
    }

    // -------------------------------------------------------------------------
    // Core logic
    // -------------------------------------------------------------------------

    private function handle_transaction( $txn, string $hook ): void {
        $ctx = $this->extract_txn_context( $txn );

        $this->debug( 'hook fired', [
            'hook'           => $hook,
            'user_id'        => $ctx['user_id'],
            'transaction_id' => $ctx['transaction_id'],
            'membership_id'  => $ctx['membership_id'],
            'subscription_id'=> $ctx['subscription_id'],
        ] );

        if ( $ctx['user_id'] <= 0 ) {
            $this->debug( 'skip: missing user_id', [
                'hook' => $hook,
            ] );
            return;
        }

        $this->normalize_user_profile( $ctx['user_id'], $hook );
        $this->provision_from_txn( $txn, $hook );
        // Créditos MemberPress ahora se gestionan en MemberPress_Credits_Integration
        // con reglas configurables e idempotencia por transacción.
    }

    private function provision_from_txn( $txn, string $source ): void {
        $user_id = $this->extract_user_id_from_txn( $txn );
        if ( $user_id <= 0 ) {
            $this->log( 'memberpress: txn missing user_id', [
                'source'   => $source,
                'txn_type' => is_object( $txn ) ? get_class( $txn ) : gettype( $txn ),
            ] );
            return;
        }

        // 1) Resolver vertical (MemberPress meta -> default -> general)
        $vertical = $this->resolve_vertical( $user_id );

        // 2) Asegurar tenant y persistir vertical como fuente de verdad
        $post_id = $this->ensure_saas_instance_for_user( $user_id );
        if ( $post_id > 0 ) {
            update_post_meta( $post_id, self::KURUKIN_INSTANCE_META_VERT, $vertical );
        }

        // 3) Guardar en user_meta rápido
        $current = (string) get_user_meta( $user_id, self::KURUKIN_USERMETA_VERTICAL, true );
        if ( $current === '' || $current !== $vertical ) {
            update_user_meta( $user_id, self::KURUKIN_USERMETA_VERTICAL, $vertical );
        }

        $this->log( 'memberpress: vertical provisioned', [
            'source'   => $source,
            'user_id'  => $user_id,
            'vertical' => $vertical,
            'post_id'  => $post_id,
        ] );
    }

    /**
     * Normaliza display_name y nickname para usuarios nuevos creados desde checkout/registro.
     * Solo pisa si el valor actual está vacío o parece placeholder de email/username.
     */
    private function normalize_user_profile( int $user_id, string $hook ): void {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            $this->debug( 'skip profile normalize: user not found', [
                'hook'    => $hook,
                'user_id' => $user_id,
            ] );
            return;
        }

        $email_local = $this->email_local_part( (string) $user->user_email );
        $first_name  = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
        $last_name   = trim( (string) get_user_meta( $user_id, 'last_name', true ) );

        $full = trim( $first_name . ' ' . $last_name );
        if ( $full === '' ) {
            $full = $first_name !== '' ? $first_name : $last_name;
        }
        if ( $full === '' ) {
            $full = $email_local !== '' ? $email_local : (string) $user->user_login;
        }

        $should_update_display = $this->is_placeholder_identity_value(
            (string) $user->display_name,
            (string) $user->user_email,
            (string) $user->user_login
        );

        $nickname = (string) get_user_meta( $user_id, 'nickname', true );
        $should_update_nickname = $this->is_placeholder_identity_value(
            $nickname,
            (string) $user->user_email,
            (string) $user->user_login
        );

        $updates = [ 'ID' => $user_id ];
        $has_updates = false;

        if ( $should_update_display ) {
            $updates['display_name'] = $full;
            $slug = sanitize_title( $full );
            if ( $slug !== '' ) {
                $updates['user_nicename'] = $slug;
            }
            $has_updates = true;
        }

        if ( $should_update_nickname ) {
            update_user_meta( $user_id, 'nickname', $full );
        }

        if ( $has_updates ) {
            wp_update_user( $updates );
        }

        $this->debug( 'profile normalized', [
            'hook'            => $hook,
            'user_id'         => $user_id,
            'display_updated' => $has_updates,
            'nickname_updated'=> $should_update_nickname,
            'resolved_name'   => $full,
        ] );
    }

    private function assign_initial_credits( array $ctx, string $hook ): void {
        $user_id        = (int) $ctx['user_id'];
        $transaction_id = (string) $ctx['transaction_id'];
        $subscription_id= (string) $ctx['subscription_id'];
        $membership_id  = (int) $ctx['membership_id'];

        if ( $membership_id <= 0 ) {
            $this->debug( 'skip credits: membership_id missing', [
                'hook'           => $hook,
                'user_id'        => $user_id,
                'transaction_id' => $transaction_id,
            ] );
            return;
        }

        $map = $this->get_credits_map();
        if ( ! isset( $map[ $membership_id ] ) ) {
            $this->debug( 'skip credits: membership not mapped', [
                'hook'           => $hook,
                'user_id'        => $user_id,
                'transaction_id' => $transaction_id,
                'membership_id'  => $membership_id,
            ] );
            return;
        }

        $amount = $this->normalize_decimal_6( $map[ $membership_id ] );
        if ( (float) $amount <= 0.0 ) {
            $this->debug( 'skip credits: mapped amount <= 0', [
                'hook'           => $hook,
                'user_id'        => $user_id,
                'transaction_id' => $transaction_id,
                'membership_id'  => $membership_id,
                'amount'         => $amount,
            ] );
            return;
        }

        $markers = $this->idempotency_markers( $transaction_id, $subscription_id );
        if ( empty( $markers ) ) {
            $this->debug( 'skip credits: missing tx/sub id for idempotency', [
                'hook'           => $hook,
                'user_id'        => $user_id,
                'transaction_id' => $transaction_id,
                'subscription_id'=> $subscription_id,
            ] );
            return;
        }

        foreach ( $markers as $marker ) {
            if ( get_user_meta( $user_id, $marker, true ) !== '' ) {
                $this->debug( 'skip credits: already granted', [
                    'hook'           => $hook,
                    'user_id'        => $user_id,
                    'transaction_id' => $transaction_id,
                    'membership_id'  => $membership_id,
                    'marker'         => $marker,
                ] );
                return;
            }
        }

        $prev = $this->get_balance_decimal( $user_id );
        $new  = $this->decimal_add( $prev, $amount );

        update_user_meta( $user_id, self::KURUKIN_CREDITS_BALANCE, $new );

        foreach ( $markers as $marker ) {
            update_user_meta( $user_id, $marker, time() );
        }

        $ref = $transaction_id !== '' ? 'mepr_txn:' . $transaction_id : 'mepr_sub:' . $subscription_id;
        $user = get_user_by( 'id', $user_id );
        $login = $user ? (string) $user->user_login : '';

        if ( class_exists( '\Kurukin\Core\Services\Credits_Service' ) ) {
            \Kurukin\Core\Services\Credits_Service::log_credit_movement( [
                'user_id'        => $user_id,
                'user_login'     => $login,
                'type'           => 'memberpress_initial_credit',
                'amount'         => $amount,
                'currency'       => 'USD',
                'balance_before' => $prev,
                'balance_after'  => $new,
                'source'         => 'memberpress',
                'ref'            => $ref,
                'note'           => 'memberpress_initial_credit',
            ] );
        }

        $this->debug( 'credits assigned', [
            'hook'           => $hook,
            'user_id'        => $user_id,
            'transaction_id' => $transaction_id,
            'membership_id'  => $membership_id,
            'amount'         => $amount,
            'balance_before' => $prev,
            'balance_after'  => $new,
        ] );
    }

    private function get_credits_map(): array {
        $map = [
            // membership_id => initial_credits
            // 123 => '10.000000',
            // 124 => '100.000000',
        ];

        $map = apply_filters( 'kurukin_mp_credits_map', $map );
        if ( ! is_array( $map ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $map as $membership_id => $credits ) {
            $membership_id = (int) $membership_id;
            if ( $membership_id <= 0 ) {
                continue;
            }
            $normalized[ $membership_id ] = $this->normalize_decimal_6( $credits );
        }

        return $normalized;
    }

    /**
     * Orden de prioridad:
     * 1) Kurukin user_meta (_kurukin_vertical)
     * 2) MemberPress user_meta (mepr_kurukin_vertical)
     * 3) Compat: key sin prefijo (kurukin_vertical)
     * 4) Default del campo desde options (default_value)
     * 5) general
     */
    private function resolve_vertical( int $user_id ): string {
        // 1) Kurukin ya guardó
        $v = (string) get_user_meta( $user_id, self::KURUKIN_USERMETA_VERTICAL, true );
        if ( $v !== '' ) {
            return $this->sanitize_vertical( $v );
        }

        // 2) MemberPress meta (key exacta)
        $v = (string) get_user_meta( $user_id, self::MP_FIELD_KEY, true );
        if ( $v !== '' ) {
            return $this->sanitize_vertical( $v );
        }

        // 3) Compat sin prefijo "mepr_"
        $alt_key = preg_replace( '/^mepr_/', '', self::MP_FIELD_KEY );
        $v = (string) get_user_meta( $user_id, $alt_key, true );
        if ( $v !== '' ) {
            return $this->sanitize_vertical( $v );
        }

        // 4) Default del campo desde mepr_options
        $default = $this->get_memberpress_field_default_value();
        if ( $default !== '' ) {
            return $this->sanitize_vertical( $default );
        }

        return 'general';
    }

    /**
     * Lee default_value del custom field desde la opción mepr_options.
     * Tu evidencia: wp_options guarda custom_fields serializado.
     */
    private function get_memberpress_field_default_value(): string {
        try {
            $opt = get_option( 'mepr_options' );
            if ( empty( $opt ) ) {
                return '';
            }

            // Si viene serializado como string, intentamos unserialize
            if ( is_string( $opt ) ) {
                $maybe = @unserialize( $opt );
                if ( $maybe !== false || $opt === 'b:0;' ) {
                    $opt = $maybe;
                }
            }

            if ( ! is_array( $opt ) ) {
                return '';
            }

            $fields = $opt['custom_fields'] ?? null;
            if ( ! is_array( $fields ) ) {
                return '';
            }

            foreach ( $fields as $f ) {
                if ( ! is_array( $f ) ) {
                    continue;
                }

                if ( ( $f['field_key'] ?? '' ) === self::MP_FIELD_KEY ) {
                    return (string) ( $f['default_value'] ?? '' );
                }
            }
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: get_memberpress_field_default_value error', [
                'error' => $e->getMessage(),
            ] );
        }

        return '';
    }

    private function extract_user_id_from_txn( $txn ): int {
        if ( is_object( $txn ) ) {
            if ( isset( $txn->user_id ) ) {
                return (int) $txn->user_id;
            }
            if ( method_exists( $txn, 'get_user_id' ) ) {
                return (int) $txn->get_user_id();
            }
        }
        return 0;
    }

    private function extract_txn_context( $txn ): array {
        $ctx = [
            'user_id'        => 0,
            'transaction_id' => '',
            'subscription_id'=> '',
            'membership_id'  => 0,
        ];

        if ( ! is_object( $txn ) && ! is_array( $txn ) ) {
            return $ctx;
        }

        $ctx['user_id'] = (int) $this->read_txn_value( $txn, [ 'user_id' ] );

        $transaction_id = $this->read_txn_value( $txn, [ 'id', 'trans_num', 'transaction_id' ] );
        $ctx['transaction_id'] = is_scalar( $transaction_id ) ? (string) $transaction_id : '';

        $subscription_id = $this->read_txn_value( $txn, [ 'subscription_id' ] );
        $ctx['subscription_id'] = is_scalar( $subscription_id ) ? (string) $subscription_id : '';

        $membership_id = $this->read_txn_value( $txn, [ 'product_id', 'membership_id' ] );
        $ctx['membership_id'] = (int) $membership_id;

        return $ctx;
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
                return $txn->{$key}();
            }
        }

        return null;
    }

    private function email_local_part( string $email ): string {
        $at = strpos( $email, '@' );
        if ( $at === false ) {
            return sanitize_text_field( $email );
        }
        return sanitize_text_field( substr( $email, 0, $at ) );
    }

    private function is_placeholder_identity_value( string $value, string $email, string $login ): bool {
        $value = trim( $value );
        if ( $value === '' ) {
            return true;
        }

        $email_local = $this->email_local_part( $email );
        $candidates = [
            strtolower( $email ),
            strtolower( $login ),
            strtolower( $email_local ),
        ];

        return in_array( strtolower( $value ), $candidates, true );
    }

    private function idempotency_markers( string $transaction_id, string $subscription_id ): array {
        $markers = [];

        if ( $transaction_id !== '' ) {
            $markers[] = self::TX_META_PREFIX . substr( hash( 'sha256', $transaction_id ), 0, 24 );
        }

        if ( $subscription_id !== '' ) {
            $markers[] = self::SUB_META_PREFIX . substr( hash( 'sha256', $subscription_id ), 0, 24 );
        }

        return $markers;
    }

    private function normalize_decimal_6( $value ): string {
        if ( class_exists( '\Kurukin\Core\Services\Credits_Service' ) ) {
            return \Kurukin\Core\Services\Credits_Service::normalize_decimal_6( $value );
        }

        if ( is_string( $value ) ) {
            $value = str_replace( ',', '.', trim( $value ) );
            $value = preg_replace( '/[^0-9\.\-]/', '', $value );
        }

        return sprintf( '%.6F', (float) $value );
    }

    private function get_balance_decimal( int $user_id ): string {
        if ( class_exists( '\Kurukin\Core\Services\Credits_Service' ) ) {
            return \Kurukin\Core\Services\Credits_Service::get_balance_decimal( $user_id );
        }

        $raw = get_user_meta( $user_id, self::KURUKIN_CREDITS_BALANCE, true );
        return $this->normalize_decimal_6( $raw );
    }

    private function decimal_add( $a, $b ): string {
        if ( class_exists( '\Kurukin\Core\Services\Credits_Service' ) ) {
            return \Kurukin\Core\Services\Credits_Service::decimal_add( $a, $b );
        }

        return sprintf( '%.6F', ( (float) $a + (float) $b ) );
    }

    private function ensure_saas_instance_for_user( int $user_id ): int {
        // Si existe Tenant_Service, lo usamos como fuente de verdad
        $tenant_class = 'Kurukin\\Core\\Services\\Tenant_Service';

        if ( class_exists( $tenant_class ) ) {
            $tenant = new $tenant_class();

            if ( method_exists( $tenant, 'ensure_user_instance' ) ) {
                $post_id = $tenant->ensure_user_instance( $user_id );
                return is_numeric( $post_id ) ? (int) $post_id : 0;
            }

            if ( method_exists( $tenant, 'ensure' ) ) {
                $post_id = $tenant->ensure( $user_id );
                return is_numeric( $post_id ) ? (int) $post_id : 0;
            }
        }

        // Fallback minimal: buscar/crear saas_instance por author
        $q = new \WP_Query([
            'post_type'      => 'saas_instance',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if ( ! empty( $q->posts ) ) {
            return (int) $q->posts[0];
        }

        $user  = get_user_by( 'id', $user_id );
        $login = $user ? $user->user_login : 'user-' . $user_id;

        $post_id = wp_insert_post([
            'post_title'  => 'Bot - ' . $login,
            'post_status' => 'publish',
            'post_type'   => 'saas_instance',
            'post_author' => $user_id,
        ]);

        return is_wp_error( $post_id ) ? 0 : (int) $post_id;
    }

    private function sanitize_vertical( string $vertical ): string {
        $vertical = strtolower( trim( $vertical ) );
        $vertical = preg_replace( '/[^a-z0-9_\-]/', '', $vertical );
        return $vertical !== '' ? $vertical : 'general';
    }

    private function log( string $message, array $context = [] ): void {
        $logger_class = 'Kurukin\\Core\\Services\\Logger';
        if ( class_exists( $logger_class ) && method_exists( $logger_class, 'log' ) ) {
            $logger_class::log( $message, $context );
        }
    }

    private function debug( string $message, array $context = [] ): void {
        $line = self::LOG_PREFIX . ' ' . $message;
        if ( ! empty( $context ) ) {
            $line .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }
        error_log( $line );
    }
}
