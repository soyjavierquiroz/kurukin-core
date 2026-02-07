<?php
/**
 * Plugin Name: Kurukin Core (SaaS Engine)
 * Description: Núcleo de automatización para Kurukin IA.
 * Version: 2.8.0
 * Author: Kurukin Team
 * Text Domain: kurukin-core
 */

namespace Kurukin\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'KURUKIN_CORE_VERSION', '2.8.0' );
define( 'KURUKIN_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'KURUKIN_CORE_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'KURUKIN_NONCE_ACTION' ) ) {
	define( 'KURUKIN_NONCE_ACTION', 'kurukin_save_data_action' );
}

$files = [
	'includes/services/class-infrastructure-registry.php',
	'includes/services/class-tenant-service.php',

	'includes/services/class-kurukin-logger.php',
	'includes/services/class-evolution-service.php',

	'includes/class-kurukin-fields.php',
	'includes/services/class-kurukin-bridge.php',
	'includes/integrations/class-kurukin-memberpress.php',
];

foreach ( $files as $file ) {
	$path = KURUKIN_CORE_PATH . $file;
	if ( file_exists( $path ) ) require_once $path;
}

class Plugin {

	public function __construct() {
		add_action( 'init', [ $this, 'register_saas_instance_cpt' ] );
		add_action( 'rest_api_init', [ $this, 'init_api' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_shortcode( 'kurukin_connect', [ $this, 'render_frontend_app' ] );

		if ( class_exists( '\Kurukin\Core\Fields' ) ) new Fields();
		if ( class_exists( '\Kurukin\Core\Integrations\MemberPress_Integration' ) ) new Integrations\MemberPress_Integration();
	}

	public function init_api() {
		$controllers = [
			'includes/api/class-kurukin-api-controller.php'            => 'Kurukin\Core\API\Controller',
			'includes/api/class-kurukin-connection-controller.php'     => 'Kurukin\Core\API\Connection_Controller',
			'includes/api/class-kurukin-settings-controller.php'       => 'Kurukin\Core\API\Settings_Controller',
			'includes/api/class-kurukin-wallet-controller.php'         => 'Kurukin\Core\API\Wallet_Controller',

			// ✅ ADMIN credits endpoint (Hotmart/QR/N8N)
			'includes/api/class-kurukin-admin-credits-controller.php'  => 'Kurukin\Core\API\Admin_Credits_Controller',
		];

		foreach ( $controllers as $file => $class ) {
			$path = KURUKIN_CORE_PATH . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
				if ( class_exists( $class ) ) new $class();
			}
		}
	}

	public function register_assets() {
		$js_url  = KURUKIN_CORE_URL . 'assets/js/connection-app.js';
		$js_path = KURUKIN_CORE_PATH . 'assets/js/connection-app.js';
		$js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : KURUKIN_CORE_VERSION;

		wp_register_script(
			'kurukin-app-js',
			$js_url,
			[ 'wp-element', 'wp-i18n' ],
			$js_ver,
			true
		);

		$css_url  = KURUKIN_CORE_URL . 'assets/css/connection-app.css';
		$css_path = KURUKIN_CORE_PATH . 'assets/css/connection-app.css';
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : KURUKIN_CORE_VERSION;

		if ( file_exists( $css_path ) ) {
			wp_register_style( 'kurukin-app-css', $css_url, [], $css_ver );
		}

		global $post;

		if (
			is_a( $post, 'WP_Post' ) &&
			(
				has_shortcode( $post->post_content, 'kurukin_connect' ) ||
				$post->post_name === 'app'
			)
		) {
			$this->enqueue_app_logic();
		}
	}

	public function enqueue_app_logic() {
		if ( ! is_user_logged_in() ) return;

		if ( wp_style_is( 'kurukin-app-css', 'registered' ) ) wp_enqueue_style( 'kurukin-app-css' );

		wp_enqueue_script( 'kurukin-app-js' );

		wp_localize_script(
			'kurukin-app-js',
			'kurukinSettings',
			[
				'root'    => esc_url_raw( rest_url() ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'user'    => wp_get_current_user()->user_login,
				'version' => KURUKIN_CORE_VERSION,
			]
		);
	}

	public function render_frontend_app( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="p-4 bg-red-900/20 text-red-500 rounded border border-red-900">Inicia sesión.</div>';
		}

		$this->enqueue_app_logic();
		return '<div id="kurukin-app-root"></div>';
	}

	public function register_saas_instance_cpt() {
		register_post_type(
			'saas_instance',
			[
				'labels' => [
					'name'          => 'Instancias Bot',
					'singular_name' => 'Instancia',
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'author' ],
			]
		);
	}
}

new Plugin();
