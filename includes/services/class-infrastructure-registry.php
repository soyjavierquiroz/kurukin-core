<?php
namespace Kurukin\Core\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Source of truth for global infra stacks.
 * Stored in wp_options: kurukin_infra_stacks
 *
 * Stack example:
 * [
 *   'stack_id' => 'evo-alpha-01',
 *   'evolution_endpoint' => 'http://evolution_api_v2:8080',
 *   'evolution_apikey' => 'xxxx',
 *   'n8n_webhook_base' => 'http://n8n2:5678',
 *   'active' => true,
 *   'capacity' => 1000,
 *   'supported_verticals' => ['dentistas','general'],
 *   'webhook_event_type' => 'MESSAGES_UPSERT' // or legacy 'messages.upsert'
 * ]
 */
class Infrastructure_Registry {

    const OPTION_STACKS = 'kurukin_infra_stacks';
    const OPTION_RR_PTR = 'kurukin_infra_rr_pointer'; // per-vertical pointer map

    /**
     * Default webhook event if stack doesn't specify.
     * Recommended baseline for Evolution v2.3.7+.
     */
    const DEFAULT_WEBHOOK_EVENT_TYPE = 'MESSAGES_UPSERT';

    /**
     * Get stacks from wp_options, supporting:
     * - array (native)
     * - JSON string (common when set via wp-cli option update)
     * - serialized string
     */
    public static function get_stacks(): array {
        $raw = get_option( self::OPTION_STACKS, [] );

        // If already array
        if ( is_array( $raw ) ) {
            return self::normalize_stacks( $raw );
        }

        // If string: try JSON then unserialize
        if ( is_string( $raw ) ) {
            $trim = trim( $raw );

            // JSON?
            if ( $trim !== '' && ( $trim[0] === '[' || $trim[0] === '{' || $trim[0] === '"' ) ) {
                $decoded = json_decode( $trim, true );

                // Sometimes wp-cli stores JSON array as JSON-string inside JSON output,
                // but get_option returns the raw string like: "[{...}]"
                if ( is_array( $decoded ) ) {
                    return self::normalize_stacks( $decoded );
                }

                // If it was a JSON-encoded string (quoted), decode twice
                if ( is_string( $decoded ) ) {
                    $decoded2 = json_decode( $decoded, true );
                    if ( is_array( $decoded2 ) ) {
                        return self::normalize_stacks( $decoded2 );
                    }
                }
            }

            // Serialized?
            $maybe = @unserialize( $raw );
            if ( $maybe !== false || $raw === 'b:0;' ) {
                if ( is_array( $maybe ) ) {
                    return self::normalize_stacks( $maybe );
                }
            }
        }

        return [];
    }

    public static function set_stacks( array $stacks ): bool {
        $stacks = self::normalize_stacks( $stacks );
        return update_option( self::OPTION_STACKS, array_values( $stacks ), false );
    }

    public static function get_supported_verticals(): array {
        $stacks = self::get_active_stacks();
        $verticals = [];

        foreach ( $stacks as $s ) {
            if ( empty( $s['supported_verticals'] ) || ! is_array( $s['supported_verticals'] ) ) {
                continue;
            }
            foreach ( $s['supported_verticals'] as $v ) {
                $v = sanitize_title( (string) $v );
                if ( $v !== '' ) $verticals[ $v ] = true;
            }
        }

        // Always include a safe fallback
        $verticals['general'] = true;

        $out = array_keys( $verticals );
        sort( $out );
        return $out;
    }

    public static function get_active_stacks(): array {
        $stacks = self::get_stacks();
        return array_values( array_filter( $stacks, static function( $s ) {
            return is_array( $s ) && ! empty( $s['active'] );
        }));
    }

    /**
     * Smart Round-Robin:
     * 1) Filter by vertical supported
     * 2) If none, try 'general'
     * 3) Round-robin among candidates
     */
    public static function pick_stack_for_vertical( string $vertical ): ?array {
        $vertical = sanitize_title( $vertical );
        if ( $vertical === '' ) $vertical = 'general';

        $active = self::get_active_stacks();
        if ( empty( $active ) ) return null;

        $candidates = self::filter_by_vertical( $active, $vertical );

        if ( empty( $candidates ) && $vertical !== 'general' ) {
            $candidates = self::filter_by_vertical( $active, 'general' );
        }

        if ( empty( $candidates ) ) {
            // last resort: any active stack
            $candidates = $active;
        }

        $ptr_map = get_option( self::OPTION_RR_PTR, [] );
        $ptr_map = is_array( $ptr_map ) ? $ptr_map : [];

        $idx = isset( $ptr_map[ $vertical ] ) ? (int) $ptr_map[ $vertical ] : 0;
        $idx = $idx % max( 1, count( $candidates ) );

        $picked = $candidates[ $idx ] ?? $candidates[0];

        $ptr_map[ $vertical ] = $idx + 1;
        update_option( self::OPTION_RR_PTR, $ptr_map, false );

        return $picked;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function filter_by_vertical( array $stacks, string $vertical ): array {
        $vertical = sanitize_title( $vertical );

        return array_values( array_filter( $stacks, static function( $s ) use ( $vertical ) {
            $list = $s['supported_verticals'] ?? [];
            if ( ! is_array( $list ) ) return false;
            $list = array_map( 'sanitize_title', $list );
            return in_array( $vertical, $list, true );
        }));
    }

    private static function normalize_stacks( array $stacks ): array {
        $out = [];

        foreach ( $stacks as $s ) {
            if ( ! is_array( $s ) ) continue;

            $norm = self::normalize_stack( $s );
            if ( empty( $norm['stack_id'] ) ) continue;

            $out[] = $norm;
        }

        return $out;
    }

    /**
     * Validates and normalizes one stack record.
     * Ensures webhook_event_type exists and is a non-empty string.
     */
    private static function normalize_stack( array $s ): array {
        $stack_id = isset( $s['stack_id'] ) ? sanitize_text_field( (string) $s['stack_id'] ) : '';
        $active   = ! empty( $s['active'] );

        // endpoints/keys
        $evo_endpoint = isset( $s['evolution_endpoint'] ) ? (string) $s['evolution_endpoint'] : '';
        $evo_apikey   = isset( $s['evolution_apikey'] ) ? sanitize_text_field( (string) $s['evolution_apikey'] ) : '';
        $n8n_base     = isset( $s['n8n_webhook_base'] ) ? (string) $s['n8n_webhook_base'] : '';

        // supported verticals
        $supported = $s['supported_verticals'] ?? [];
        if ( ! is_array( $supported ) ) $supported = [];
        $supported = array_values( array_filter( array_map( static function( $v ) {
            $v = sanitize_title( (string) $v );
            return $v !== '' ? $v : null;
        }, $supported ) ) );

        if ( empty( $supported ) ) {
            $supported = [ 'general' ];
        } elseif ( ! in_array( 'general', $supported, true ) ) {
            // always allow general fallback
            $supported[] = 'general';
        }

        // webhook_event_type (explicit per stack)
        $event = '';
        if ( isset( $s['webhook_event_type'] ) ) {
            $event = trim( (string) $s['webhook_event_type'] );
        }
        if ( $event === '' ) {
            $event = self::DEFAULT_WEBHOOK_EVENT_TYPE;
        }

        // small sanitization: keep dots/underscores/hyphens and letters/numbers
        // (allows both "MESSAGES_UPSERT" and "messages.upsert")
        $event = preg_replace( '/[^A-Za-z0-9_\.\-]/', '', $event );
        if ( $event === '' ) {
            $event = self::DEFAULT_WEBHOOK_EVENT_TYPE;
        }

        $norm = [
            'stack_id'            => $stack_id,
            'active'              => $active,
            'evolution_endpoint'  => $evo_endpoint,
            'evolution_apikey'    => $evo_apikey,
            'n8n_webhook_base'    => $n8n_base,
            'supported_verticals' => $supported,
            'webhook_event_type'  => $event,
        ];

        // pass-through optional fields safely
        if ( isset( $s['capacity'] ) ) {
            $norm['capacity'] = (int) $s['capacity'];
        }

        return $norm;
    }
}
