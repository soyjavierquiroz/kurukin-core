<?php
namespace Kurukin\Core\Services;

use WP_Query;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Tenant_Service {

    /**
     * Ensures saas_instance exists for a user and has routing meta assigned.
     * Returns post_id (saas_instance).
     */
    public static function ensure_user_instance( int $user_id ): int|WP_Error {
        if ( $user_id <= 0 ) {
            return new WP_Error( 'kurukin_invalid_user', 'Invalid user_id' );
        }

        // 1) Find existing instance (prefer the most recent if duplicates exist)
        $q = new WP_Query([
            'post_type'      => 'saas_instance',
            'post_status'    => [ 'publish', 'private' ],
            'author'         => $user_id,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        if ( ! empty( $q->posts ) ) {
            $post_id = (int) $q->posts[0];
            self::ensure_routing_meta( $post_id, $user_id );
            return $post_id;
        }

        // 2) Create it
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return new WP_Error( 'kurukin_user_not_found', 'User not found' );
        }

        $instance_name = sanitize_title( (string) $user->user_login );

        $post_id = wp_insert_post([
            'post_title'  => 'Bot - ' . $user->user_login,
            'post_status' => 'publish',
            'post_type'   => 'saas_instance',
            'post_author' => $user_id,
        ], true);

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Minimal critical meta
        update_post_meta( $post_id, '_kurukin_evolution_instance_id', $instance_name );

        // Business defaults
        $vertical = get_user_meta( $user_id, '_kurukin_vertical', true );
        $vertical = $vertical ? sanitize_title( (string) $vertical ) : 'general';
        update_post_meta( $post_id, '_kurukin_business_vertical', $vertical );

        // legacy/placeholder
        update_post_meta( $post_id, '_kurukin_cluster_node', 'alpha-01' );

        // Routing meta (stack assignment)
        self::ensure_routing_meta( (int) $post_id, $user_id );

        return (int) $post_id;
    }

    /**
     * Assign endpoint/apikey/n8n_base/router_id/webhook_event_type based on registry stacks + vertical.
     */
    public static function ensure_routing_meta( int $post_id, int $user_id ): void {
        $endpoint  = (string) get_post_meta( $post_id, '_kurukin_evolution_endpoint', true );
        $apikey    = (string) get_post_meta( $post_id, '_kurukin_evolution_apikey', true );

        // IMPORTANT: this meta stores ONLY the n8n base (NO /webhook/... suffix)
        $n8n_base  = (string) get_post_meta( $post_id, '_kurukin_n8n_webhook_url', true );

        $event     = (string) get_post_meta( $post_id, '_kurukin_evolution_webhook_event', true );
        $router_id = (string) get_post_meta( $post_id, '_kurukin_n8n_router_id', true );

        $has_routing = ( $endpoint !== '' && $apikey !== '' && $n8n_base !== '' );

        // Vertical always from tenant
        $vertical = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
        $vertical = $vertical ? sanitize_title( $vertical ) : 'general';

        // Pick stack (may be empty array if registry not configured)
        $stack = [];
        if ( class_exists( Infrastructure_Registry::class ) ) {
            $picked = Infrastructure_Registry::pick_stack_for_vertical( $vertical );
            $stack = is_array( $picked ) ? $picked : [];
        }

        // Defaults (global constants)
        $default_evo_url = defined( 'KURUKIN_EVOLUTION_URL' ) ? (string) KURUKIN_EVOLUTION_URL : '';
        $default_evo_key = defined( 'KURUKIN_EVOLUTION_GLOBAL_KEY' ) ? (string) KURUKIN_EVOLUTION_GLOBAL_KEY : '';
        $default_n8n     = defined( 'KURUKIN_N8N_WEBHOOK_BASE' ) ? (string) KURUKIN_N8N_WEBHOOK_BASE : '';

        // 1) If not pinned, assign endpoint/apikey/n8n_base and stack_id
        if ( ! $has_routing ) {
            $picked_endpoint = (string) ( $stack['evolution_endpoint'] ?? '' );
            $picked_apikey   = (string) ( $stack['evolution_apikey']   ?? '' );
            $picked_n8n_base = (string) ( $stack['n8n_webhook_base']   ?? '' );

            $endpoint = $endpoint !== '' ? $endpoint : ( $picked_endpoint !== '' ? $picked_endpoint : $default_evo_url );
            $apikey   = $apikey   !== '' ? $apikey   : ( $picked_apikey   !== '' ? $picked_apikey   : $default_evo_key );

            if ( $n8n_base === '' ) {
                $base = $picked_n8n_base !== '' ? $picked_n8n_base : $default_n8n;
                $base = trim( (string) $base );
                $base = $base !== '' ? untrailingslashit( $base ) : '';

                // Store base only (no /webhook/{...})
                $computed = $base;

                // Allow override via filter (advanced)
                $computed = apply_filters( 'kurukin_n8n_webhook_base', $computed, $user_id, $post_id, $stack );
                $computed = trim( (string) $computed );
                $computed = $computed !== '' ? untrailingslashit( $computed ) : '';

                // Safety: strip accidental webhook suffix if someone misconfigured
                $computed = preg_replace( '#/webhook(/.*)?$#', '', $computed );

                $n8n_base = (string) $computed;
            }

            if ( ! empty( $stack['stack_id'] ) ) {
                update_post_meta( $post_id, '_kurukin_stack_id', sanitize_text_field( (string) $stack['stack_id'] ) );
            }

            // Persist only if not empty
            if ( $endpoint !== '' ) {
                update_post_meta( $post_id, '_kurukin_evolution_endpoint', sanitize_text_field( trim( $endpoint ) ) );
            }
            if ( $apikey !== '' ) {
                update_post_meta( $post_id, '_kurukin_evolution_apikey', sanitize_text_field( $apikey ) );
            }
            if ( $n8n_base !== '' ) {
                update_post_meta( $post_id, '_kurukin_n8n_webhook_url', sanitize_text_field( $n8n_base ) );
            }
        } else {
            // Ensure base normalization even when pinned
            $n8n_base = trim( $n8n_base );
            $n8n_base = $n8n_base !== '' ? untrailingslashit( $n8n_base ) : '';
            $n8n_base = preg_replace( '#/webhook(/.*)?$#', '', $n8n_base );
            if ( $n8n_base !== '' ) {
                update_post_meta( $post_id, '_kurukin_n8n_webhook_url', sanitize_text_field( $n8n_base ) );
            }
        }

        // 2) Always ensure router_id exists (safe)
        $router_id = trim( (string) $router_id );
        if ( $router_id === '' ) {
            $router_id = (string) ( $stack['n8n_router_id'] ?? '' );
            $router_id = trim( $router_id );
            $router_id = preg_replace( '/[^a-fA-F0-9\-]/', '', $router_id );
            if ( $router_id !== '' ) {
                update_post_meta( $post_id, '_kurukin_n8n_router_id', sanitize_text_field( $router_id ) );
            }
        }

        // 3) Always ensure webhook_event_type exists (safe)
        $event = trim( (string) $event );
        if ( $event === '' ) {
            $default_event = class_exists( Infrastructure_Registry::class )
                ? (string) ( $stack['webhook_event_type'] ?? Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE )
                : 'MESSAGES_UPSERT';

            $event = trim( (string) $default_event );
            $event = preg_replace( '/[^A-Za-z0-9_\.\-]/', '', $event );
            if ( $event === '' ) {
                $event = class_exists( Infrastructure_Registry::class )
                    ? Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE
                    : 'MESSAGES_UPSERT';
            }

            update_post_meta( $post_id, '_kurukin_evolution_webhook_event', sanitize_text_field( $event ) );
        }
    }

    /**
     * ✅ Billing getter (simple, local mirror via post_meta)
     *
     * Reads:
     * - _kurukin_credits_balance (string numeric)
     *
     * Returns:
     * [
     *   'credits_balance' => float,
     *   'can_process'     => bool,
     *   'min_required'    => float
     * ]
     */
    public static function get_billing_for_post( int $post_id ): array {
        $raw = get_post_meta( $post_id, '_kurukin_credits_balance', true );
        $bal = is_numeric( $raw ) ? (float) $raw : 0.0;

        // Allow system to define minimum threshold (anti-zero edge)
        $min = defined( 'KURUKIN_MIN_CREDITS' ) ? (float) KURUKIN_MIN_CREDITS : 0.01;
        if ( $min < 0 ) $min = 0.0;

        return [
            'credits_balance' => round( $bal, 4 ),
            'can_process'     => ( $bal > $min ),
            'min_required'    => $min,
        ];
    }

    /**
     * Convenience: billing for current tenant (by user_id).
     */
    public static function get_billing_for_user( int $user_id ): array|WP_Error {
        $post_id = self::ensure_user_instance( $user_id );
        if ( is_wp_error( $post_id ) ) return $post_id;
        return self::get_billing_for_post( (int) $post_id );
    }

    /**
     * Returns tenant routing config consumed by Evolution_Service.
     */
    public static function get_tenant_config( int $user_id ): array|WP_Error {
        $post_id = self::ensure_user_instance( $user_id );
        if ( is_wp_error( $post_id ) ) return $post_id;

        // Auto-heal (ensures router/event/base exist)
        self::ensure_routing_meta( (int) $post_id, $user_id );

        $instance_id = (string) get_post_meta( $post_id, '_kurukin_evolution_instance_id', true );
        $endpoint    = (string) get_post_meta( $post_id, '_kurukin_evolution_endpoint', true );
        $apikey      = (string) get_post_meta( $post_id, '_kurukin_evolution_apikey', true );

        // base only
        $n8n_base    = (string) get_post_meta( $post_id, '_kurukin_n8n_webhook_url', true );
        $router_id   = (string) get_post_meta( $post_id, '_kurukin_n8n_router_id', true );

        $vertical    = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
        $event       = (string) get_post_meta( $post_id, '_kurukin_evolution_webhook_event', true );

        $vertical = $vertical ? sanitize_title( $vertical ) : 'general';
        $instance_id = sanitize_title( (string) $instance_id );

        $event = trim( (string) $event );
        if ( $event === '' ) {
            $event = class_exists( Infrastructure_Registry::class )
                ? Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE
                : 'MESSAGES_UPSERT';
        }

        $router_id = trim( (string) $router_id );
        $router_id = preg_replace( '/[^a-fA-F0-9\-]/', '', $router_id );

        $n8n_base = trim( (string) $n8n_base );
        $n8n_base = $n8n_base !== '' ? untrailingslashit( $n8n_base ) : '';
        $n8n_base = preg_replace( '#/webhook(/.*)?$#', '', $n8n_base );

        return [
            'post_id'            => (int) $post_id,
            'instance_id'        => $instance_id,
            'endpoint'           => (string) $endpoint,
            'apikey'             => (string) $apikey,
            'webhook_url'        => (string) $n8n_base,      // base only
            'vertical'           => (string) $vertical,
            'webhook_event_type' => (string) $event,
            'n8n_router_id'      => (string) $router_id,
        ];
    }
}
