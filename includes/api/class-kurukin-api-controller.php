<?php
namespace Kurukin\Core\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_Error;
use WP_Query;

use Kurukin\Core\Services\Tenant_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Controller extends WP_REST_Controller {

    protected $namespace = 'kurukin/v1';
    protected $resource  = 'config';

    // Simple rate limit (per IP + instance_id)
    private int $rate_limit_window_seconds = 60;  // 1 min
    private int $rate_limit_max_requests   = 60;  // 60 req/min

    public function __construct() {
        $this->register_routes();
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->resource, [
            [
                'methods'  => 'GET',
                'callback' => [ $this, 'get_config' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args' => [
                    'instance_id' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ],
        ] );
    }

    public function check_permission( WP_REST_Request $request ) {
        $expected = defined( 'KURUKIN_API_SECRET' ) ? (string) KURUKIN_API_SECRET : '';

        if ( $expected === '' ) {
            error_log('[Kurukin] /config denied: KURUKIN_API_SECRET missing');
            return new WP_Error( 'kurukin_secret_missing', 'Server Secret Missing', [ 'status' => 500 ] );
        }

        $secret = $request->get_header( 'x_kurukin_secret' );
        if ( ! $secret ) {
            $secret = $request->get_header( 'x-kurukin-secret' );
        }
        $secret = (string) $secret;

        if ( $secret === '' ) {
            error_log('[Kurukin] /config denied: missing secret header');
            return new WP_Error( 'kurukin_unauthorized', 'Missing x-kurukin-secret', [ 'status' => 401 ] );
        }

        if ( ! hash_equals( $expected, $secret ) ) {
            error_log('[Kurukin] /config denied: bad secret header');
            return new WP_Error( 'kurukin_forbidden', 'Forbidden', [ 'status' => 403 ] );
        }

        // Rate limit AFTER secret check
        $instance_id = strtolower( trim( (string) $request->get_param( 'instance_id' ) ) );
        $instance_id = preg_replace( '/[^a-z0-9_\-]/', '', $instance_id );

        $ip = $this->get_request_ip();
        $rl = $this->rate_limit_allow( $ip, $instance_id );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        return true;
    }

    public function get_config( WP_REST_Request $request ) {
        // 0) instance_id (hardening)
        $instance_id = strtolower( trim( (string) $request->get_param( 'instance_id' ) ) );
        $instance_id = preg_replace( '/[^a-z0-9_\-]/', '', $instance_id );

        if ( $instance_id === '' ) {
            return new WP_Error( 'kurukin_bad_request', 'instance_id required', [ 'status' => 400 ] );
        }

        // 1) Find tenant (saas_instance) by evolution_instance_id
        $query = new WP_Query([
            'post_type'      => 'saas_instance',
            'post_status'    => [ 'publish', 'private' ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_kurukin_evolution_instance_id',
                    'value'   => $instance_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if ( empty( $query->posts ) ) {
            return new WP_Error( 'kurukin_not_found', 'Instance Not Found', [ 'status' => 404 ] );
        }

        $post_id   = (int) $query->posts[0];
        $author_id = (int) get_post_field( 'post_author', $post_id );

        // 2) MemberPress check (if present)
        if ( class_exists( 'MeprUser' ) ) {
            $mepr_user = new \MeprUser( $author_id );
            if ( ! $mepr_user->is_active() ) {
                return new WP_Error( 'kurukin_payment_required', 'Payment Required', [ 'status' => 402 ] );
            }
        }

        // 3) Tenant data
        $prefix = '_kurukin_';

        // Business vertical
        $vertical = (string) get_post_meta( $post_id, '_kurukin_business_vertical', true );
        if ( $vertical === '' ) {
            $vertical = (string) get_post_meta( $post_id, $prefix . 'business_vertical', true ); // legacy fallback
        }
        $vertical = $vertical !== '' ? sanitize_title( $vertical ) : 'general';

        // Node (optional)
        $node = (string) get_post_meta( $post_id, $prefix . 'cluster_node', true );
        $node = $node !== '' ? sanitize_text_field( $node ) : '';

        // Prompt (ok to expose; no secrets)
        $prompt = (string) get_post_meta( $post_id, $prefix . 'system_prompt', true );

        // Voice (ElevenLabs remains optional BYOK)
        $voice_enabled = get_post_meta( $post_id, $prefix . 'voice_enabled', true );
        $voice_id      = (string) get_post_meta( $post_id, $prefix . 'eleven_voice_id', true );
        $voice_model   = (string) get_post_meta( $post_id, $prefix . 'eleven_model_id', true );

        // BYOK flag
        $byok_enabled = (bool) get_post_meta( $post_id, $prefix . 'eleven_byok_enabled', true );

        // Only decrypt if BYOK enabled (avoid leaking / unnecessary decrypt)
        $eleven_key = null;
        $has_byok_key = false;
        if ( $byok_enabled ) {
            $raw_enc = get_post_meta( $post_id, $prefix . 'eleven_api_key', true );
            $dec = $this->decrypt( $raw_enc );
            $dec = trim( (string) $dec );
            if ( $dec !== '' ) {
                $eleven_key = $dec;
                $has_byok_key = true;
            } else {
                $eleven_key = null;
                $has_byok_key = false;
            }
        }

        // Context
        $ctx_profile  = (string) get_post_meta( $post_id, $prefix . 'context_profile', true );
        $ctx_services = (string) get_post_meta( $post_id, $prefix . 'context_services', true );
        $ctx_rules    = (string) get_post_meta( $post_id, $prefix . 'context_rules', true );

        // 4) Evolution connection (priority: tenant meta)
        $tenant_evo_endpoint = (string) get_post_meta( $post_id, '_kurukin_evolution_endpoint', true );
        $tenant_evo_apikey   = (string) get_post_meta( $post_id, '_kurukin_evolution_apikey', true );

        $fallback_evo_endpoint = defined( 'KURUKIN_EVOLUTION_URL' ) ? (string) KURUKIN_EVOLUTION_URL : '';
        $fallback_evo_apikey   = defined( 'KURUKIN_EVOLUTION_GLOBAL_KEY' ) ? (string) KURUKIN_EVOLUTION_GLOBAL_KEY : '';

        $evo_endpoint = $tenant_evo_endpoint !== '' ? $tenant_evo_endpoint : $fallback_evo_endpoint;
        $evo_apikey   = $tenant_evo_apikey   !== '' ? $tenant_evo_apikey   : $fallback_evo_apikey;

        $evo_endpoint = trim( (string) $evo_endpoint );

        // 5) Business data array
        $business_data = [];
        if ( $ctx_profile !== '' )  { $business_data[] = [ 'category' => 'COMPANY_PROFILE', 'content' => $ctx_profile ]; }
        if ( $ctx_services !== '' ) { $business_data[] = [ 'category' => 'SERVICES_LIST',   'content' => $ctx_services ]; }
        if ( $ctx_rules !== '' )    { $business_data[] = [ 'category' => 'RULES',           'content' => $ctx_rules ]; }

        // 6) Billing (credits model) - include min_required for contract stability
        $billing = class_exists( Tenant_Service::class )
            ? Tenant_Service::get_billing_state_by_post_id( $post_id )
            : [ 'credits_balance' => 0.0, 'can_process' => false, 'min_required' => 1.0 ];

        // 7) Response payload (NO OpenAI key anymore)
        $payload = [
            'status'       => 'success',
            'schema_version' => '2.0.0',

            'instance_id'  => $instance_id,
            'business_vertical' => $vertical,

            'router_logic' => [
                'workflow_mode' => $vertical,
                'cluster_node'  => $node,
                'version'       => '2.0',
            ],

            // Centralized AI (no BYOK secrets here)
            'ai_brain' => [
                'provider'      => 'kurukin',
                'model'         => 'gpt-4o',
                'system_prompt' => $prompt,
            ],

            // Voice: BYOK optional exception
            'voice' => [
                'provider'      => 'elevenlabs',
                'enabled'       => (bool) $voice_enabled,

                // Policy flags (important for n8n)
                'byok_enabled'  => (bool) $byok_enabled,
                'has_byok_key'  => (bool) $has_byok_key,

                // Only send api_key when BYOK enabled AND key exists
                'api_key'       => $has_byok_key ? $eleven_key : null,

                // These can still be present; if byok is off, n8n should use admin/global provider config
                'voice_id'      => $voice_id,
                'model_id'      => $voice_model,
            ],

            'business_data' => $business_data,

            'evolution_connection' => [
                'endpoint' => $evo_endpoint,
                'apikey'   => $evo_apikey,
            ],

            // REQUIRED by new contract
            'billing' => [
                'credits_balance' => (float) ( $billing['credits_balance'] ?? 0.0 ),
                'can_process'     => (bool) ( $billing['can_process'] ?? false ),
                'min_required'    => (float) ( $billing['min_required'] ?? 1.0 ),
            ],
        ];

        $resp = rest_ensure_response( $payload );

        // Avoid caching (sensitive)
        $resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $resp->header( 'Pragma', 'no-cache' );
        $resp->header( 'Expires', '0' );

        return $resp;
    }

    private function decrypt( $data ): string {
        if ( empty( $data ) ) return '';

        $key = defined( 'KURUKIN_ENCRYPTION_KEY' ) ? (string) KURUKIN_ENCRYPTION_KEY : (string) wp_salt( 'auth' );

        $decoded = base64_decode( (string) $data, true );
        if ( $decoded === false ) return '';

        $parts = explode( '::', $decoded, 2 );
        if ( count( $parts ) < 2 ) return '';

        $plain = openssl_decrypt( $parts[0], 'AES-256-CBC', $key, 0, $parts[1] );
        if ( $plain === false ) return '';

        return (string) $plain;
    }

    // ---------------------------------------------------------------------
    // Rate limit helpers
    // ---------------------------------------------------------------------

    private function get_request_ip(): string {
        $xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
        if ( $xff !== '' ) {
            $parts = explode( ',', $xff );
            $ip = trim( (string) $parts[0] );
            if ( $ip !== '' ) return $ip;
        }

        $rip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return $rip !== '' ? $rip : 'unknown';
    }

    private function rate_limit_allow( string $ip, string $instance_id ) {
        $ip = $ip !== '' ? $ip : 'unknown';
        $instance_id = $instance_id !== '' ? $instance_id : 'unknown';

        if ( defined( 'KURUKIN_CONFIG_RL_MAX' ) ) {
            $v = (int) KURUKIN_CONFIG_RL_MAX;
            if ( $v > 0 ) $this->rate_limit_max_requests = $v;
        }
        if ( defined( 'KURUKIN_CONFIG_RL_WINDOW' ) ) {
            $v = (int) KURUKIN_CONFIG_RL_WINDOW;
            if ( $v > 0 ) $this->rate_limit_window_seconds = $v;
        }

        $key = 'kurukin_rl_cfg_' . md5( $ip . '|' . $instance_id );
        $data = get_transient( $key );

        $now = time();
        if ( ! is_array( $data ) ) {
            $data = [ 'count' => 0, 'start' => $now ];
        }

        $start = isset( $data['start'] ) ? (int) $data['start'] : $now;
        $count = isset( $data['count'] ) ? (int) $data['count'] : 0;

        if ( ( $now - $start ) >= $this->rate_limit_window_seconds ) {
            $start = $now;
            $count = 0;
        }

        $count++;

        $data['start'] = $start;
        $data['count'] = $count;

        set_transient( $key, $data, $this->rate_limit_window_seconds + 5 );

        if ( $count > $this->rate_limit_max_requests ) {
            $retry_after = max( 1, $this->rate_limit_window_seconds - ( $now - $start ) );
            error_log('[Kurukin] /config rate limited: ip=' . $ip . ' instance_id=' . $instance_id . ' count=' . $count);

            return new WP_Error(
                'kurukin_rate_limited',
                'Too Many Requests',
                [ 'status' => 429, 'retry_after' => $retry_after ]
            );
        }

        return true;
    }
}
