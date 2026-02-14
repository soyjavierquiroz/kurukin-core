<?php
namespace Kurukin\Core\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Evolution_Service {

    private int $timeout = 15;
    private int $retries = 2;

    // Retryable HTTP codes (common in swarm/traefik transient failures)
    private array $retry_http_codes = [ 408, 425, 429, 500, 502, 503, 504 ];

    public function connect_and_get_qr( int $user_id ): array|WP_Error {
        $cfg = Tenant_Service::get_tenant_config( $user_id );
        if ( is_wp_error( $cfg ) ) return $cfg;

        if ( empty( $cfg['endpoint'] ) || empty( $cfg['instance_id'] ) ) {
            return new WP_Error(
                'kurukin_missing_routing',
                'Configuración incompleta para conectar con Evolution.',
                [
                    'status'          => 500,
                    'upstream_status' => 0,
                    'hint'            => 'Verifica endpoint, instance_id y API key de Evolution.',
                ]
            );
        }

        $ok = $this->ensure_instance_exists( $cfg );
        if ( is_wp_error( $ok ) ) return $ok;

        $hook = $this->set_webhook( $cfg );
        if ( is_wp_error( $hook ) ) return $hook;

        for ( $i = 0; $i < 5; $i++ ) {
            $res = $this->request( 'GET', "instance/connect/{$cfg['instance_id']}", null, $cfg );
            if ( is_wp_error( $res ) ) return $res;

            $data = $res['data'] ?? [];

            if ( isset( $data['base64'] ) && ! empty( $data['base64'] ) ) {
                return [
                    'base64' => $data['base64'],
                    'code'   => $data['code'] ?? null,
                ];
            }

            // If instance not found, auto-heal (create + webhook)
            if ( (int) ( $res['code'] ?? 0 ) === 404 ) {
                $this->log_notice('connect_qr: instance missing, recreating', $cfg, $res);
                $ok = $this->ensure_instance_exists( $cfg, true );
                if ( is_wp_error( $ok ) ) return $ok;

                $hook = $this->set_webhook( $cfg );
                if ( is_wp_error( $hook ) ) return $hook;
            }

            sleep( 1 );
        }

        return new WP_Error(
            'kurukin_qr_timeout',
            'No se pudo obtener el QR a tiempo.',
            [
                'status'          => 504,
                'upstream_status' => 504,
                'hint'            => 'Intenta nuevamente en unos segundos.',
            ]
        );
    }

    public function get_connection_state( int $user_id ): array|WP_Error {
        $cfg = Tenant_Service::get_tenant_config( $user_id );
        if ( is_wp_error( $cfg ) ) return $cfg;

        $res = $this->request( 'GET', "instance/connectionState/{$cfg['instance_id']}", null, $cfg );
        if ( is_wp_error( $res ) ) {
            // Let controller decide how to present to UI
            return $res;
        }

        $data = $res['data'] ?? [];
        if ( ! isset( $data['instance'] ) ) {
            return [ 'state' => 'close' ];
        }

        return [
            'state' => $data['instance']['state'] ?? 'close',
        ];
    }

    public function reset_instance( int $user_id ): array|WP_Error {
        $cfg = Tenant_Service::get_tenant_config( $user_id );
        if ( is_wp_error( $cfg ) ) return $cfg;

        // Best-effort delete (ignore failure)
        $del = $this->request( 'DELETE', "instance/delete/{$cfg['instance_id']}", null, $cfg );
        if ( is_wp_error( $del ) ) {
            $this->log_notice('reset_instance: delete failed (continuing)', $cfg, [ 'code' => 0, 'url' => '', 'data' => [] ]);
        }

        return $this->connect_and_get_qr( $user_id );
    }

    private function ensure_instance_exists( array $cfg, bool $force_create = false ): true|WP_Error {
        $exists = $this->instance_exists( $cfg );

        if ( $exists === true ) return true;

        if ( is_wp_error( $exists ) && ! $force_create ) {
            return $exists;
        }

        return $this->create_instance( $cfg );
    }

    private function instance_exists( array $cfg ): bool|WP_Error {
        $res = $this->request( 'GET', "instance/connectionState/{$cfg['instance_id']}", null, $cfg );
        if ( is_wp_error( $res ) ) return $res;

        $code = (int) ( $res['code'] ?? 0 );
        $data = $res['data'] ?? [];

        if ( $code >= 200 && $code < 300 && isset( $data['instance'] ) ) return true;
        if ( $code === 404 ) return false;
        if ( ! isset( $data['instance'] ) ) return false;

        return true;
    }

    private function create_instance( array $cfg ): true|WP_Error {
        $payload = [
            'instanceName' => $cfg['instance_id'],
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
        ];

        $res = $this->request( 'POST', 'instance/create', $payload, $cfg );
        if ( is_wp_error( $res ) ) return $res;

        $code = (int) ( $res['code'] ?? 0 );
        $data = $res['data'] ?? [];

        if ( $code === 200 || $code === 201 ) return true;
        if ( $code === 409 ) return true;

        $msg = $this->extract_message( $data );
        if ( $this->looks_like_name_in_use( $msg ) ) return true;

        if ( isset( $data['response']['message'] ) && ( is_array( $data['response']['message'] ) || is_object( $data['response']['message'] ) ) ) {
            $joined = $this->flatten_any_to_string( $data['response']['message'] );
            if ( $this->looks_like_name_in_use( $joined ) ) return true;
        }

        $this->log_notice('create_instance failed', $cfg, $res);

        return new WP_Error(
            'kurukin_create_failed',
            "No se pudo crear la instancia en Evolution ({$code}).",
            [
                'status'               => 502,
                'upstream_status'      => $code,
                'hint'                 => $this->hint_for_upstream_status( $code ),
                'upstream_message'     => $msg,
                'upstream_body_preview'=> $this->preview_text( (string) ( $res['raw'] ?? '' ) ),
            ]
        );
    }

    private function looks_like_name_in_use( string $msg ): bool {
        $m = strtolower( $msg );
        if ( $m === '' ) return false;
        if ( strpos( $m, 'already exists' ) !== false ) return true;
        if ( strpos( $m, 'already' ) !== false && strpos( $m, 'exist' ) !== false ) return true;
        if ( strpos( $m, 'name' ) !== false && strpos( $m, 'in use' ) !== false ) return true;
        if ( strpos( $m, 'is already in use' ) !== false ) return true;
        return false;
    }

    /**
     * ✅ WEBHOOK URL PRO:
     * {n8n_base}/webhook/{router_id}/{vertical}/{instance_id}
     */
    private function build_n8n_webhook_url( array $cfg ): string|WP_Error {
        $base = isset( $cfg['webhook_url'] ) ? trim( (string) $cfg['webhook_url'] ) : '';
        if ( $base === '' ) {
            return new WP_Error( 'kurukin_missing_webhook', 'Missing n8n webhook base for tenant' );
        }

        $base = untrailingslashit( $base );

        // Hardening: if base contains "/webhook/", strip it (fixes old tenants)
        $pos = stripos( $base, '/webhook/' );
        if ( $pos !== false ) {
            $base = rtrim( substr( $base, 0, $pos ), '/' );
        }

        $router_id = isset( $cfg['n8n_router_id'] ) ? trim( (string) $cfg['n8n_router_id'] ) : '';
        $router_id = preg_replace( '/[^a-fA-F0-9\-]/', '', $router_id );
        if ( $router_id === '' ) {
            return new WP_Error( 'kurukin_config_error', 'Stack n8n_router_id is missing' );
        }

        $vertical = isset( $cfg['vertical'] ) ? sanitize_title( (string) $cfg['vertical'] ) : 'general';
        if ( $vertical === '' ) $vertical = 'general';

        $instance_id = isset( $cfg['instance_id'] ) ? sanitize_title( (string) $cfg['instance_id'] ) : '';
        if ( $instance_id === '' ) {
            return new WP_Error( 'kurukin_config_error', 'Tenant instance_id is missing' );
        }

        return $base . '/webhook/' . $router_id . '/' . $vertical . '/' . $instance_id;
    }

    /**
     * Hardening:
     * - If tenant meta mistakenly includes "/webhook/...", strip to base.
     * - Send webhookBase64 in both camelCase + snake_case.
     */
    private function set_webhook( array $cfg ): true|WP_Error {
        $final_url = $this->build_n8n_webhook_url( $cfg );
        if ( is_wp_error( $final_url ) ) return $final_url;

        $event_type = isset( $cfg['webhook_event_type'] ) ? trim( (string) $cfg['webhook_event_type'] ) : '';
        if ( $event_type === '' ) $event_type = Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE;
        $event_type = preg_replace( '/[^A-Za-z0-9_\.\-]/', '', $event_type );
        if ( $event_type === '' ) $event_type = Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE;

        $payload = [
            'webhook' => [
                'enabled'         => true,
                'url'             => (string) $final_url,
                'webhookByEvents' => false,
                'events'          => [ $event_type ],

                // Evolution builds differ: send both to guarantee
                'webhookBase64'   => true,
                'webhook_base64'  => true,
            ],
        ];

        $res = $this->request( 'POST', "webhook/set/{$cfg['instance_id']}", $payload, $cfg );
        if ( is_wp_error( $res ) ) return $res;

        $code = (int) ( $res['code'] ?? 0 );
        if ( $code >= 200 && $code < 300 ) return true;

        $msg = $this->extract_message( $res['data'] ?? [] );
        $this->log_notice('set_webhook failed', $cfg, $res);

        return new WP_Error(
            'kurukin_webhook_failed',
            "No se pudo configurar el webhook de Evolution ({$code}).",
            [
                'status'               => 502,
                'upstream_status'      => $code,
                'hint'                 => $this->hint_for_upstream_status( $code ),
                'upstream_message'     => $msg,
                'upstream_body_preview'=> $this->preview_text( (string) ( $res['raw'] ?? '' ) ),
            ]
        );
    }

    private function request( string $method, string $path, ?array $body, array $cfg ): array|WP_Error {
        $base = untrailingslashit( (string) $cfg['endpoint'] );
        $url  = $base . '/' . ltrim( $path, '/' );

        $auth_candidates = $this->resolve_auth_candidates( $cfg );
        if ( empty( $auth_candidates ) ) {
            return new WP_Error(
                'kurukin_missing_routing',
                'No hay API key disponible para Evolution.',
                [
                    'status'          => 500,
                    'upstream_status' => 0,
                    'hint'            => 'Configura la API key del tenant o KURUKIN_EVOLUTION_GLOBAL_KEY.',
                ]
            );
        }

        $body_json = ! empty( $body ) ? wp_json_encode( $body ) : null;

        foreach ( $auth_candidates as $index => $auth ) {
            $attempt = 0;

            do {
                $attempt++;
                $headers = $this->build_auth_headers( (string) $auth['key'] );
                $args = [
                    'method'    => strtoupper( $method ),
                    'headers'   => $headers,
                    'timeout'   => $this->timeout,
                    'sslverify' => false,
                ];

                if ( null !== $body_json ) {
                    $args['body'] = $body_json;
                }

                $this->debug_http( 'request', [
                    'method'      => strtoupper( $method ),
                    'url'         => $url,
                    'auth_source' => (string) $auth['source'],
                    'headers'     => $this->masked_headers( $headers ),
                    'attempt'     => $attempt,
                ] );

                $res = wp_remote_request( $url, $args );

                if ( is_wp_error( $res ) ) {
                    if ( $attempt <= $this->retries && $this->is_timeout_error( $res ) ) {
                        continue;
                    }

                    $this->log_notice( 'request wp_error', $cfg, [ 'code' => 0, 'url' => $url, 'data' => [] ] );
                    $this->debug_http( 'request_wp_error', [
                        'method'      => strtoupper( $method ),
                        'url'         => $url,
                        'auth_source' => (string) $auth['source'],
                        'error'       => $res->get_error_message(),
                    ] );

                    return new WP_Error(
                        'kurukin_upstream_request_failed',
                        'No se pudo conectar con Evolution.',
                        [
                            'status'          => 502,
                            'upstream_status' => 0,
                            'hint'            => 'Revisa conectividad hacia Evolution y la URL configurada.',
                            'upstream_message'=> $res->get_error_message(),
                        ]
                    );
                }

                $code = (int) wp_remote_retrieve_response_code( $res );
                $raw  = (string) wp_remote_retrieve_body( $res );
                $data = json_decode( $raw, true );
                if ( ! is_array( $data ) ) {
                    $data = [];
                }

                $this->debug_http( 'response', [
                    'method'       => strtoupper( $method ),
                    'url'          => $url,
                    'status'       => $code,
                    'auth_source'  => (string) $auth['source'],
                    'body_preview' => $this->preview_text( $raw ),
                ] );

                // 401 con key tenant: intenta con key global como fallback.
                if ( $code === 401 && $index < ( count( $auth_candidates ) - 1 ) ) {
                    $this->log_notice( 'request unauthorized, trying fallback auth', $cfg, [ 'code' => $code, 'url' => $url, 'data' => [] ] );
                    break;
                }

                // Retry some transient HTTP responses
                if ( $attempt <= $this->retries && in_array( $code, $this->retry_http_codes, true ) ) {
                    $this->log_notice( 'request retryable http=' . $code, $cfg, [ 'code' => $code, 'url' => $url, 'data' => [] ] );
                    usleep( 250000 ); // 250ms
                    continue;
                }

                if ( $code === 401 ) {
                    return new WP_Error(
                        'kurukin_upstream_unauthorized',
                        'No autorizado con Evolution.',
                        [
                            'status'               => 502,
                            'upstream_status'      => 401,
                            'hint'                 => 'Revisa la API key y el host de Evolution configurados.',
                            'upstream_body_preview'=> $this->preview_text( $raw ),
                        ]
                    );
                }

                return [
                    'code'       => $code,
                    'raw'        => $raw,
                    'data'       => $data,
                    'url'        => $url,
                    'auth_source'=> (string) $auth['source'],
                ];

            } while ( $attempt <= $this->retries );
        }

        return new WP_Error(
            'kurukin_request_failed',
            'No se pudo completar la solicitud a Evolution.',
            [
                'status'          => 502,
                'upstream_status' => 0,
                'hint'            => 'Intenta nuevamente. Si persiste, revisa logs de integración.',
            ]
        );
    }

    private function is_timeout_error( WP_Error $err ): bool {
        $msg = $err->get_error_message();
        if ( stripos( $msg, 'cURL error 28' ) !== false ) return true;
        if ( stripos( $msg, 'timed out' ) !== false ) return true;
        return false;
    }

    private function resolve_auth_candidates( array $cfg ): array {
        $tenant_key = trim( (string) ( $cfg['apikey'] ?? '' ) );
        $global_key = defined( 'KURUKIN_EVOLUTION_GLOBAL_KEY' ) ? trim( (string) KURUKIN_EVOLUTION_GLOBAL_KEY ) : '';

        $candidates = [];
        if ( $tenant_key !== '' ) {
            $candidates[] = [ 'source' => 'tenant', 'key' => $tenant_key ];
        }
        if ( $global_key !== '' && $global_key !== $tenant_key ) {
            $candidates[] = [ 'source' => 'global', 'key' => $global_key ];
        }

        return $candidates;
    }

    private function build_auth_headers( string $apikey ): array {
        return [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'apikey'        => $apikey,
            'x-api-key'     => $apikey,
            'Authorization' => 'Bearer ' . $apikey,
        ];
    }

    private function mask_secret( string $value ): string {
        $value = trim( $value );
        $len   = strlen( $value );
        if ( $len <= 8 ) {
            return str_repeat( '*', $len );
        }

        return substr( $value, 0, 4 ) . str_repeat( '*', max( 4, $len - 7 ) ) . substr( $value, -3 );
    }

    private function masked_headers( array $headers ): array {
        $safe = $headers;
        foreach ( [ 'apikey', 'x-api-key', 'Authorization' ] as $key ) {
            if ( ! isset( $safe[ $key ] ) ) {
                continue;
            }
            $raw = (string) $safe[ $key ];
            if ( stripos( $raw, 'Bearer ' ) === 0 ) {
                $token = trim( substr( $raw, 7 ) );
                $safe[ $key ] = 'Bearer ' . $this->mask_secret( $token );
            } else {
                $safe[ $key ] = $this->mask_secret( $raw );
            }
        }

        return $safe;
    }

    private function preview_text( string $text, int $limit = 280 ): string {
        $clean = preg_replace( '/\s+/', ' ', trim( $text ) );
        $clean = (string) $clean;
        if ( strlen( $clean ) > $limit ) {
            return substr( $clean, 0, $limit ) . '...';
        }
        return $clean;
    }

    private function hint_for_upstream_status( int $status ): string {
        if ( $status === 401 ) {
            return 'No autorizado con Evolution, revisa key/host.';
        }
        if ( $status === 404 ) {
            return 'Endpoint de Evolution no encontrado. Revisa la URL configurada.';
        }
        if ( $status >= 500 ) {
            return 'Evolution devolvió error interno. Intenta de nuevo en unos minutos.';
        }
        return 'Revisa configuración de Evolution (URL/API key) e inténtalo nuevamente.';
    }

    private function debug_http( string $message, array $context = [] ): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }

        error_log(
            '[Kurukin][Evolution][Debug] ' . $message .
            ( ! empty( $context ) ? ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '' )
        );
    }

    private function flatten_any_to_string( mixed $v ): string {
        if ( is_string( $v ) ) return $v;
        if ( is_numeric( $v ) ) return (string) $v;
        if ( is_bool( $v ) ) return $v ? 'true' : 'false';

        if ( is_array( $v ) ) {
            $parts = [];
            foreach ( $v as $item ) {
                $s = $this->flatten_any_to_string( $item );
                if ( $s !== '' ) $parts[] = $s;
            }
            if ( ! empty( $parts ) ) return implode( ' ', $parts );
            return wp_json_encode( $v );
        }

        if ( is_object( $v ) ) {
            return wp_json_encode( $v );
        }

        return '';
    }

    private function extract_message( array $data ): string {
        if ( array_key_exists( 'message', $data ) ) {
            $m = $this->flatten_any_to_string( $data['message'] );
            return $m !== '' ? $m : wp_json_encode( $data['message'] );
        }

        if ( isset( $data['response'] ) && is_array( $data['response'] ) ) {
            if ( array_key_exists( 'message', $data['response'] ) ) {
                $m = $this->flatten_any_to_string( $data['response']['message'] );
                return $m !== '' ? $m : wp_json_encode( $data['response']['message'] );
            }
            return wp_json_encode( $data['response'] );
        }

        if ( array_key_exists( 'error', $data ) ) {
            $m = $this->flatten_any_to_string( $data['error'] );
            return $m !== '' ? $m : wp_json_encode( $data['error'] );
        }

        if ( isset( $data['errors'] ) ) {
            return wp_json_encode( $data['errors'] );
        }

        return 'Unknown error';
    }

    /**
     * Minimal SRE log helper (never logs apikey or full payload).
     */
    private function log_notice( string $msg, array $cfg, array $res = [] ): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }

        $instance = isset( $cfg['instance_id'] ) ? (string) $cfg['instance_id'] : '';
        $endpoint = isset( $cfg['endpoint'] ) ? (string) $cfg['endpoint'] : '';
        $code     = isset( $res['code'] ) ? (int) $res['code'] : 0;
        $url      = isset( $res['url'] ) ? (string) $res['url'] : '';

        // keep it short & safe
        error_log(
            '[Kurukin][Evolution] ' . $msg .
            ' instance=' . $instance .
            ' code=' . $code .
            ' endpoint=' . $endpoint .
            ( $url !== '' ? ' url=' . $url : '' )
        );
    }
}
