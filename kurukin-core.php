<?php
/**
 * Plugin Name: Kurukin Core (SaaS Engine)
 * Description: Núcleo de automatización para Kurukin IA.
 * Version: 2.1.0
 * Author: Kurukin Team
 * Text Domain: kurukin-core
 */

namespace Kurukin\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// --- 1. CONSTANTES ---
define( 'KURUKIN_CORE_VERSION', '2.1.0' );
define( 'KURUKIN_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'KURUKIN_CORE_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'KURUKIN_NONCE_ACTION' ) ) {
    define( 'KURUKIN_NONCE_ACTION', 'kurukin_save_data_action' );
}

// --- 2. CARGA DE MÓDULOS ---
$files = [
    // Servicios Base (NUEVO: Logger y Evolution Service)
    'includes/services/class-kurukin-logger.php',
    'includes/services/class-evolution-service.php',
    
    // Lógica existente
    'includes/class-kurukin-fields.php',
    'includes/services/class-kurukin-bridge.php',
    'includes/integrations/class-kurukin-memberpress.php'
];

foreach ( $files as $file ) {
    if ( file_exists( KURUKIN_CORE_PATH . $file ) ) require_once KURUKIN_CORE_PATH . $file;
}

class Plugin {

    public function __construct() {
        if ( class_exists( 'Kurukin\Core\Fields' ) ) new Fields();
        if ( class_exists( 'Kurukin\Core\Integrations\MemberPress_Integration' ) ) new Integrations\MemberPress_Integration();
        
        add_action( 'init', [ $this, 'register_saas_instance_cpt' ] );
        add_action( 'rest_api_init', [ $this, 'init_api' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        // Mantenemos el shortcode por si acaso, pero el Theme debería usar el ID directo
        add_shortcode( 'kurukin_connect', [ $this, 'render_frontend_app' ] );
    }

    public function init_api() {
        // Carga de Controladores
        $controllers = [
            'includes/api/class-kurukin-api-controller.php' => 'Kurukin\Core\API\Controller',
            'includes/api/class-kurukin-connection-controller.php' => 'Kurukin\Core\API\Connection_Controller',
            'includes/api/class-kurukin-settings-controller.php' => 'Kurukin\Core\API\Settings_Controller'
        ];

        foreach ($controllers as $file => $class) {
            if ( file_exists( KURUKIN_CORE_PATH . $file ) ) {
                require_once KURUKIN_CORE_PATH . $file;
                if ( class_exists( $class ) ) new $class();
            }
        }
    }

    public function register_assets() {
        // --- CACHE BUSTING AUTOMÁTICO ---
        $js_url  = KURUKIN_CORE_URL . 'assets/js/connection-app.js';
        $js_path = KURUKIN_CORE_PATH . 'assets/js/connection-app.js';
        
        $version = file_exists( $js_path ) ? filemtime( $js_path ) : KURUKIN_CORE_VERSION;

        // Registramos el JS principal con la versión dinámica
        wp_register_script( 'kurukin-app-js', $js_url, [ 'wp-element', 'wp-i18n' ], $version, true );

        // Inyección condicional: Si estamos en la página /app o usamos el shortcode
        global $post;
        if ( (is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'kurukin_connect' ) || $post->post_name === 'app' )) ) {
            $this->enqueue_app_logic();
        }
    }

    // Helper público para que el Theme pueda llamarlo manualmente si es necesario
    public function enqueue_app_logic() {
        if ( ! is_user_logged_in() ) return;

        wp_enqueue_script( 'kurukin-app-js' );
        wp_localize_script( 'kurukin-app-js', 'kurukinSettings', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'user'  => wp_get_current_user()->user_login
        ]);
    }

    public function render_frontend_app( $atts ) {
        if ( ! is_user_logged_in() ) return '<div class="p-4 bg-red-900/20 text-red-500 rounded border border-red-900">Inicia sesión.</div>';
        
        // Forzamos la carga si se usa shortcode
        $this->enqueue_app_logic();

        return '<div id="kurukin-app-root"></div>';
    }

    public function register_saas_instance_cpt() {
        register_post_type( 'saas_instance', [
            'labels' => ['name' => 'Instancias Bot', 'singular_name' => 'Instancia'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
    }
}
new Plugin();