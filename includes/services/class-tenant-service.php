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

        // 1) Find existing instance
        $q = new WP_Query([
            'post_type'      => 'saas_instance',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
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

        $instance_name = sanitize_title( $user->user_login );

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
     *
     * Pinning rule:
     * - If endpoint/apikey/n8n_base already exist -> keep them (tenant pinned)
     * - BUT: if webhook_event_type missing, we still set it
     * - BUT: if n8n_router_id missing, we still set it
     */
    public static function ensure_routing_meta( int $post_id, int $user_id ): void {
        $endpoint   = (string) get_post_meta( $post_id, '_kurukin_evolution_endpoint', true );
        $apikey     = (string) get_post_meta( $post_id, '_kurukin_evolution_apikey', true );

        // IMPORTANT: this meta now stores ONLY the n8n base (NO /webhook/... suffix)
        $n8n_base   = (string) get_post_meta( $post_id, '_kurukin_n8n_webhook_url', true );

        $event      = (string) get_post_meta( $post_id, '_kurukin_evolution_webhook_event', true );
        $router_id  = (string) get_post_meta( $post_id, '_kurukin_n8n_router_id', true );

        $has_routing = ( $endpoint !== '' && $apikey !== '' && $n8n_base !== '' );

        // Vertical always from tenant
        $vertical = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
        $vertical = $vertical ? sanitize_title( $vertical ) : 'general';

        // Pick stack
        $stack = Infrastructure_Registry::pick_stack_for_vertical( $vertical );

        // Defaults (global constants)
        $default_evo_url = defined( 'KURUKIN_EVOLUTION_URL' ) ? (string) KURUKIN_EVOLUTION_URL : '';
        $default_evo_key = defined( 'KURUKIN_EVOLUTION_GLOBAL_KEY' ) ? (string) KURUKIN_EVOLUTION_GLOBAL_KEY : '';

        // 1) If not pinned, assign endpoint/apikey/n8n_base and stack_id
        if ( ! $has_routing ) {
            $endpoint = $endpoint !== '' ? $endpoint : ( (string) ( $stack['evolution_endpoint'] ?? $default_evo_url ) );
            $apikey   = $apikey   !== '' ? $apikey   : ( (string) ( $stack['evolution_apikey']   ?? $default_evo_key ) );

            if ( $n8n_base === '' ) {
                $base = (string) ( $stack['n8n_webhook_base'] ?? ( defined( 'KURUKIN_N8N_WEBHOOK_BASE' ) ? (string) KURUKIN_N8N_WEBHOOK_BASE : '' ) );
                $base = $base ? untrailingslashit( $base ) : '';

                // Store base only (no /webhook/{vertical})
                $computed = $base;

                // Allow override via filter (advanced)
                $computed = apply_filters( 'kurukin_n8n_webhook_base', $computed, $user_id, $post_id, $stack );
                $n8n_base = (string) $computed;
            }

            if ( ! empty( $stack['stack_id'] ) ) {
                update_post_meta( $post_id, '_kurukin_stack_id', sanitize_text_field( (string) $stack['stack_id'] ) );
            }

            if ( $endpoint !== '' ) update_post_meta( $post_id, '_kurukin_evolution_endpoint', esc_url_raw( $endpoint ) );
            if ( $apikey   !== '' ) update_post_meta( $post_id, '_kurukin_evolution_apikey', sanitize_text_field( $apikey ) );
            if ( $n8n_base !== '' ) update_post_meta( $post_id, '_kurukin_n8n_webhook_url', esc_url_raw( $n8n_base ) );
        }

        // 2) Always ensure router_id exists (safe)
        if ( $router_id === '' ) {
            $router_id = (string) ( $stack['n8n_router_id'] ?? '' );
            $router_id = trim( $router_id );
            // UUID-ish sanitization
            $router_id = preg_replace( '/[^a-fA-F0-9\-]/', '', $router_id );
            if ( $router_id !== '' ) {
                update_post_meta( $post_id, '_kurukin_n8n_router_id', sanitize_text_field( $router_id ) );
            }
        }

        // 3) Always ensure webhook_event_type exists (safe)
        if ( $event === '' ) {
            $event = (string) ( $stack['webhook_event_type'] ?? Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE );
            $event = trim( $event );
            $event = preg_replace( '/[^A-Za-z0-9_\.\-]/', '', $event );
            if ( $event === '' ) {
                $event = Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE;
            }

            update_post_meta( $post_id, '_kurukin_evolution_webhook_event', sanitize_text_field( $event ) );
        }
    }

    /**
     * Returns tenant routing config consumed by Evolution_Service.
     */
    public static function get_tenant_config( int $user_id ): array|WP_Error {
        $post_id = self::ensure_user_instance( $user_id );
        if ( is_wp_error( $post_id ) ) return $post_id;

        $instance_id = (string) get_post_meta( $post_id, '_kurukin_evolution_instance_id', true );
        $endpoint    = (string) get_post_meta( $post_id, '_kurukin_evolution_endpoint', true );
        $apikey      = (string) get_post_meta( $post_id, '_kurukin_evolution_apikey', true );

        // base only
        $n8n_base    = (string) get_post_meta( $post_id, '_kurukin_n8n_webhook_url', true );
        $router_id   = (string) get_post_meta( $post_id, '_kurukin_n8n_router_id', true );

        $vertical    = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
        $event       = (string) get_post_meta( $post_id, '_kurukin_evolution_webhook_event', true );

        $vertical = $vertical ? sanitize_title( $vertical ) : 'general';
        $instance_id = sanitize_title( $instance_id );

        $event = trim( $event );
        if ( $event === '' ) {
            $event = Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE;
        }

        $router_id = trim( $router_id );

        return [
            'post_id'            => (int) $post_id,
            'instance_id'        => $instance_id,
            'endpoint'           => $endpoint,
            'apikey'             => $apikey,
            'webhook_url'        => $n8n_base,      // base only
            'vertical'           => $vertical,
            'webhook_event_type' => $event,
            'n8n_router_id'      => $router_id,
        ];
    }
}