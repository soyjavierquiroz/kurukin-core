<?php
namespace Kurukin\Core\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Evolution_Service {

    private int $timeout = 15;
    private int $retries = 2;

    public function connect_and_get_qr( int $user_id ): array|WP_Error {
        $cfg = Tenant_Service::get_tenant_config( $user_id );
        if ( is_wp_error( $cfg ) ) return $cfg;

        if ( empty( $cfg['endpoint'] ) || empty( $cfg['apikey'] ) || empty( $cfg['instance_id'] ) ) {
            return new WP_Error( 'kurukin_missing_routing', 'Tenant routing config missing (endpoint/apikey/instance_id)' );
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

            if ( (int) ( $res['code'] ?? 0 ) === 404 ) {
                $ok = $this->ensure_instance_exists( $cfg, true );
                if ( is_wp_error( $ok ) ) return $ok;

                $hook = $this->set_webhook( $cfg );
                if ( is_wp_error( $hook ) ) return $hook;
            }

            sleep( 1 );
        }

        return new WP_Error( 'kurukin_qr_timeout', 'Timeout esperando QR' );
    }

    public function get_connection_state( int $user_id ): array|WP_Error {
        $cfg = Tenant_Service::get_tenant_config( $user_id );
        if ( is_wp_error( $cfg ) ) return $cfg;

        $res = $this->request( 'GET', "instance/connectionState/{$cfg['instance_id']}", null, $cfg );
        if ( is_wp_error( $res ) ) {
            return [ 'state' => 'network_error', 'message' => $res->get_error_message() ];
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

        $this->request( 'DELETE', "instance/delete/{$cfg['instance_id']}", null, $cfg );
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

        return new WP_Error( 'kurukin_create_failed', "Create instance failed ({$code}): {$msg}" );
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
     *
     * Hardening:
     * - If tenant meta mistakenly includes "/webhook/...", strip to base.
     */
    private function set_webhook( array $cfg ): true|WP_Error {
        $base = isset( $cfg['webhook_url'] ) ? trim( (string) $cfg['webhook_url'] ) : '';
        if ( $base === '' ) {
            return new WP_Error( 'kurukin_missing_webhook', 'Missing n8n webhook base for tenant' );
        }

        // Remove trailing slash
        $base = untrailingslashit( $base );

        // 🔥 Hardening: if base contains "/webhook/", strip it (fixes old tenants)
        $pos = stripos( $base, '/webhook/' );
        if ( $pos !== false ) {
            $base = rtrim( substr( $base, 0, $pos ), '/' );
        }

        $router_id = isset( $cfg['n8n_router_id'] ) ? trim( (string) $cfg['n8n_router_id'] ) : '';
        $router_id = preg_replace( '/[^a-fA-F0-9\-]/', '', $router_id );

        if ( $router_id === '' ) {
            return new WP_Error( 'config_error', 'Stack n8n_router_id is missing' );
        }

        $vertical = isset( $cfg['vertical'] ) ? sanitize_title( (string) $cfg['vertical'] ) : 'general';
        if ( $vertical === '' ) $vertical = 'general';

        $instance_id = isset( $cfg['instance_id'] ) ? sanitize_title( (string) $cfg['instance_id'] ) : '';
        if ( $instance_id === '' ) {
            return new WP_Error( 'config_error', 'Tenant instance_id is missing' );
        }

        $event_type = isset( $cfg['webhook_event_type'] ) ? trim( (string) $cfg['webhook_event_type'] ) : '';
        if ( $event_type === '' ) $event_type = Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE;
        $event_type = preg_replace( '/[^A-Za-z0-9_\.\-]/', '', $event_type );
        if ( $event_type === '' ) $event_type = Infrastructure_Registry::DEFAULT_WEBHOOK_EVENT_TYPE;

        $final_url = $base . '/webhook/' . $router_id . '/' . $vertical . '/' . $instance_id;

        $payload = [
            'webhook' => [
                'enabled'         => true,
                'url'             => $final_url,
                'webhookByEvents' => false,
                'events'          => [ $event_type ],
                'webhookBase64'   => true,
            ],
        ];

        $res = $this->request( 'POST', "webhook/set/{$cfg['instance_id']}", $payload, $cfg );
        if ( is_wp_error( $res ) ) return $res;

        $code = (int) ( $res['code'] ?? 0 );
        if ( $code >= 200 && $code < 300 ) return true;

        $msg = $this->extract_message( $res['data'] ?? [] );
        return new WP_Error( 'kurukin_webhook_failed', "Webhook set failed ({$code}): {$msg}" );
    }

    private function request( string $method, string $path, ?array $body, array $cfg ): array|WP_Error {
        $base = untrailingslashit( (string) $cfg['endpoint'] );
        $url  = $base . '/' . ltrim( $path, '/' );

        $args = [
            'method'    => strtoupper( $method ),
            'headers'   => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'apikey'       => (string) $cfg['apikey'],
            ],
            'timeout'   => $this->timeout,
            'sslverify' => false,
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $attempt = 0;

        do {
            $attempt++;
            $res = wp_remote_request( $url, $args );

            if ( is_wp_error( $res ) ) {
                if ( $attempt <= $this->retries && $this->is_timeout_error( $res ) ) {
                    continue;
                }
                return $res;
            }

            $code = (int) wp_remote_retrieve_response_code( $res );
            $raw  = (string) wp_remote_retrieve_body( $res );
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) $data = [];

            return [
                'code' => $code,
                'raw'  => $raw,
                'data' => $data,
                'url'  => $url,
            ];

        } while ( $attempt <= $this->retries );

        return new WP_Error( 'kurukin_request_failed', 'Request failed after retries' );
    }

    private function is_timeout_error( WP_Error $err ): bool {
        $msg = $err->get_error_message();
        if ( stripos( $msg, 'cURL error 28' ) !== false ) return true;
        if ( stripos( $msg, 'timed out' ) !== false ) return true;
        return false;
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
}