<?php
/**
 * Plugin Name: Kurukin Core (SaaS Engine)
 * Description: Núcleo de automatización para Kurukin IA. Integrado con MemberPress.
 * Version: 1.3.0
 * Author: Kurukin Team
 * Text Domain: kurukin-core
 */

namespace Kurukin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KURUKIN_CORE_VERSION', '1.3.0' );
define( 'KURUKIN_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'KURUKIN_NONCE_ACTION', 'kurukin_save_data_action' );

// 1. Carga de Módulos Estáticos
require_once KURUKIN_CORE_PATH . 'includes/class-kurukin-fields.php';
require_once KURUKIN_CORE_PATH . 'includes/services/class-kurukin-bridge.php';
require_once KURUKIN_CORE_PATH . 'includes/integrations/class-kurukin-memberpress.php'; // <-- NUEVO

class Plugin {

    public function __construct() {
        // Inicializar
        new Fields();
        
        // Inicializar Integración MemberPress
        new Integrations\MemberPress_Integration();
        
        add_action( 'init', [ $this, 'register_saas_instance_cpt' ] );
        add_action( 'rest_api_init', [ $this, 'init_api' ] );
    }

    public function init_api() {
        if ( file_exists( KURUKIN_CORE_PATH . 'includes/api/class-kurukin-api-controller.php' ) ) {
            require_once KURUKIN_CORE_PATH . 'includes/api/class-kurukin-api-controller.php';
            if ( class_exists( 'Kurukin\Core\API\Controller' ) ) {
                new \Kurukin\Core\API\Controller();
            }
        }
    }

    public function register_saas_instance_cpt() {
        register_post_type( 'saas_instance', [
            'labels'       => ['name' => 'Instancias Bot', 'singular_name' => 'Instancia'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-whatsapp',
            'supports'     => [ 'title', 'author' ],
            'rewrite'      => [ 'slug' => 'saas-instance' ],
            // Importante: Permitimos que el autor vea SU propia instancia
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);
    }
}

new Plugin();