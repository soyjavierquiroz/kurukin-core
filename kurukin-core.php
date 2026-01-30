<?php
/**
 * Plugin Name: Kurukin Core (SaaS Engine)
 * Description: Núcleo de automatización para Kurukin IA.
 * Version: 1.8.0
 * Author: Kurukin Team
 * Text Domain: kurukin-core
 */

namespace Kurukin\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// --- 1. DEFINICIÓN DE CONSTANTES (CRÍTICO: SIEMPRE ARRIBA) ---
define( 'KURUKIN_CORE_VERSION', '1.8.0' );
define( 'KURUKIN_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'KURUKIN_CORE_URL', plugin_dir_url( __FILE__ ) );
// Definimos esto globalmente para evitar conflictos de namespace
if ( ! defined( 'KURUKIN_NONCE_ACTION' ) ) {
    define( 'KURUKIN_NONCE_ACTION', 'kurukin_save_data_action' );
}

// --- 2. CARGA DE MÓDULOS ---
$files = [
    'includes/class-kurukin-fields.php',
    'includes/services/class-kurukin-bridge.php',
    'includes/integrations/class-kurukin-memberpress.php'
];

foreach ( $files as $file ) {
    if ( file_exists( KURUKIN_CORE_PATH . $file ) ) {
        require_once KURUKIN_CORE_PATH . $file;
    }
}

class Plugin {

    public function __construct() {
        // Inicializar UI Admin
        if ( class_exists( 'Kurukin\Core\Fields' ) ) new Fields();
        if ( class_exists( 'Kurukin\Core\Integrations\MemberPress_Integration' ) ) new Integrations\MemberPress_Integration();
        
        // Hooks
        add_action( 'init', [ $this, 'register_saas_instance_cpt' ] );
        add_action( 'rest_api_init', [ $this, 'init_api' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_shortcode( 'kurukin_connect', [ $this, 'render_frontend_app' ] );
    }

    public function init_api() {
        // Carga de Controladores API
        if ( file_exists( KURUKIN_CORE_PATH . 'includes/api/class-kurukin-api-controller.php' ) ) {
            require_once KURUKIN_CORE_PATH . 'includes/api/class-kurukin-api-controller.php';
            if ( class_exists( 'Kurukin\Core\API\Controller' ) ) new \Kurukin\Core\API\Controller();
        }
        if ( file_exists( KURUKIN_CORE_PATH . 'includes/api/class-kurukin-connection-controller.php' ) ) {
            require_once KURUKIN_CORE_PATH . 'includes/api/class-kurukin-connection-controller.php';
            if ( class_exists( 'Kurukin\Core\API\Connection_Controller' ) ) new \Kurukin\Core\API\Connection_Controller();
        }
    }

    public function register_assets() {
        wp_register_style( 'kurukin-app-css', KURUKIN_CORE_URL . 'assets/css/connection-app.css', [], '1.0.3' );
        wp_register_script( 'kurukin-app-js', KURUKIN_CORE_URL . 'assets/js/connection-app.js', [ 'wp-element' ], '1.0.3', true );
    }

    public function render_frontend_app( $atts ) {
        if ( ! is_user_logged_in() ) return '<div class="k-alert">Inicia sesión para configurar tu Bot.</div>';

        wp_enqueue_style( 'kurukin-app-css' );
        wp_enqueue_script( 'kurukin-app-js' );

        wp_localize_script( 'kurukin-app-js', 'kurukinSettings', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'user'  => wp_get_current_user()->user_login
        ]);

        return '<div id="kurukin-connection-app"></div>';
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