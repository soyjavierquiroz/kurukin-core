<?php
namespace Kurukin\Core\Services;

use Kurukin\Core\Services\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Evolution_Service {

    private $api_url;
    private $api_key;

    public function __construct() {
        // Obtener configuración desde constantes (definidas en wp-config.php o fallback)
        $this->api_url = defined( 'KURUKIN_EVOLUTION_URL' ) ? KURUKIN_EVOLUTION_URL : '';
        $this->api_key = defined( 'KURUKIN_EVOLUTION_GLOBAL_KEY' ) ? KURUKIN_EVOLUTION_GLOBAL_KEY : '';
    }

    /**
     * Verifica si la configuración global es válida.
     */
    public function is_configured() {
        return ! empty( $this->api_url ) && ! empty( $this->api_key );
    }

    /**
     * Crea una instancia en Evolution API v2.
     */
    public function create_instance( $instance_name ) {
        return $this->request( '/instance/create', 'POST', [
            'instanceName' => $instance_name,
            'qrcode'       => true,
            'integration'  => 'WHATSAPP-BAILEYS'
        ]);
    }

    /**
     * Obtiene el QR (base64) para conectar.
     */
    public function connect_instance( $instance_name ) {
        return $this->request( "/instance/connect/{$instance_name}", 'GET' );
    }

    /**
     * Consulta el estado de conexión (open, close, connecting).
     */
    public function get_connection_state( $instance_name ) {
        return $this->request( "/instance/connectionState/{$instance_name}", 'GET' );
    }

    /**
     * Elimina una instancia (Hard Reset).
     */
    public function delete_instance( $instance_name ) {
        return $this->request( "/instance/logout/{$instance_name}", 'DELETE' );
    }

    /**
     * Método centralizado para peticiones HTTP con Logging y Manejo de Errores.
     */
    private function request( $endpoint, $method = 'GET', $body = null ) {
        if ( ! $this->is_configured() ) {
            Logger::log( 'Evolution API no configurada en wp-config.php', [], 'error' );
            return new \WP_Error( 'config_error', 'Evolution API URL/KEY missing' );
        }

        $url = untrailingslashit( $this->api_url ) . $endpoint;
        
        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'apikey'       => $this->api_key
            ],
            'timeout' => 15
        ];

        if ( $body ) {
            $args['body'] = json_encode( $body );
        }

        // Ejecutar petición
        $response = wp_remote_request( $url, $args );

        // Manejo de errores de conexión (Red, DNS, Timeout)
        if ( is_wp_error( $response ) ) {
            Logger::log( 'Error de conexión HTTP con Evolution', [
                'endpoint' => $endpoint,
                'error'    => $response->get_error_message()
            ], 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Loguear errores de la API (4xx, 5xx)
        if ( $code >= 400 ) {
            Logger::log( 'Error respuesta Evolution API', [
                'endpoint' => $endpoint,
                'code'     => $code,
                'body'     => $data
            ], 'warning' );
            
            // Retornamos el error formateado para que el Controller lo entienda
            return new \WP_Error( 'api_error', $data['message'] ?? 'Error desconocido en Evolution API', ['status' => $code] );
        }

        return $data;
    }
}