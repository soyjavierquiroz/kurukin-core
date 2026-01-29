<?php
namespace Kurukin\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bridge {

    public static function trigger_n8n( $path, $payload, $tenant_id ) {
        $base = defined( 'KURUKIN_INTERNAL_N8N_HOST' ) ? KURUKIN_INTERNAL_N8N_HOST : 'http://n8n:5678';
        $url  = rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );

        $payload['client_id'] = $tenant_id;
        $payload['source']    = 'kurukin_wp';

        $args = [
            'body'    => json_encode( $payload ),
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . ( defined('KURUKIN_SERVICE_TOKEN') ? KURUKIN_SERVICE_TOKEN : '' ),
                'X-Tenant-ID'   => $tenant_id
            ],
            'blocking'  => true,
            'timeout'   => 5,
            'sslverify' => false
        ];

        $res = wp_remote_post( $url, $args );
        
        if ( is_wp_error( $res ) ) {
            error_log( "N8N Error: " . $res->get_error_message() );
            return $res;
        }

        return json_decode( wp_remote_retrieve_body( $res ), true );
    }
}