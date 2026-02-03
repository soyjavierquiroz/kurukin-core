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

    public function __construct() {
        // Esperar a que plugins estén cargados antes de tocar MemberPress.
        add_action( 'plugins_loaded', [ $this, 'bootstrap' ], 20 );

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
                return;
            }

            $txn = $event->get_data();
            $this->provision_from_txn( $txn, 'mepr-event-transaction-completed' );
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: handle_transaction_completed_event error', [
                'error' => $e->getMessage(),
            ] );
        }
    }

    public function handle_transaction_status_complete( $txn ): void {
        try {
            $this->provision_from_txn( $txn, 'mepr-txn-status-complete' );
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: handle_transaction_status_complete error', [
                'error' => $e->getMessage(),
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
        } catch ( \Throwable $e ) {
            $this->log( 'memberpress: handle_wp_login error', [
                'error' => $e->getMessage(),
            ] );
        }
    }

    // -------------------------------------------------------------------------
    // Core logic
    // -------------------------------------------------------------------------

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
}
