<?php
namespace Kurukin\Core\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Logger {

    /**
     * Escribe un mensaje en el log del sistema.
     * * @param string $message Mensaje principal.
     * @param array  $context Datos adicionales (array, objeto, excepción).
     * @param string $level   Nivel de severidad: 'info', 'error', 'debug', 'warning'.
     */
    public static function log( $message, $context = [], $level = 'info' ) {
        // 1. Definir directorio seguro en Uploads (persiste tras actualizaciones del plugin)
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/kurukin-logs';

        // 2. Crear directorio si no existe
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        // 3. PROTECCIÓN CRÍTICA: Crear .htaccess para bloquear acceso web
        $htaccess_file = $log_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            file_put_contents( $htaccess_file, "Order Deny,Allow\nDeny from all" );
        }

        // 4. Formatear la entrada del log
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $level     = strtoupper( $level );
        $context_str = ! empty( $context ) ? ' | DATA: ' . json_encode( $context, JSON_UNESCAPED_UNICODE ) : '';
        
        $log_entry = "[{$timestamp}] [{$level}] {$message}{$context_str}" . PHP_EOL;

        // 5. Escribir en archivo rotativo diario (ej: kurukin-2026-02-01.log)
        $date_suffix = current_time( 'Y-m-d' );
        $log_file    = $log_dir . '/kurukin-' . $date_suffix . '.log';

        // Append al archivo
        error_log( $log_entry, 3, $log_file );
    }
}