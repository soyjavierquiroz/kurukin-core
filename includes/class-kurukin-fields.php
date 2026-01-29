<?php
namespace Kurukin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fields {

    private $prefix = '_kurukin_';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta_boxes' ] );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'kurukin_config',
            'Configuración del Bot',
            [ $this, 'render_meta_box' ],
            'saas_instance',
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        // Obtener valores
        $instance_id = get_post_meta( $post->ID, $this->prefix . 'evolution_instance_id', true );
        $vertical    = get_post_meta( $post->ID, $this->prefix . 'business_vertical', true );
        $system_p    = get_post_meta( $post->ID, $this->prefix . 'system_prompt', true );
        
        // API Key Encriptada
        $enc_key     = get_post_meta( $post->ID, $this->prefix . 'openai_api_key', true );
        $api_key     = $enc_key ? $this->decrypt( $enc_key ) : '';

        wp_nonce_field( KURUKIN_NONCE_ACTION, 'kurukin_nonce' );
        ?>
        <div style="display:grid; gap:15px;">
            <div>
                <label><strong>ID Instancia (Evolution API):</strong></label>
                <input type="text" name="evolution_instance_id" value="<?php echo esc_attr($instance_id); ?>" class="widefat">
            </div>
            <div>
                <label><strong>Vertical de Negocio:</strong></label>
                <select name="business_vertical" class="widefat">
                    <option value="catalog" <?php selected($vertical, 'catalog'); ?>>Catálogo</option>
                    <option value="consultative" <?php selected($vertical, 'consultative'); ?>>Consultivo</option>
                    <option value="support" <?php selected($vertical, 'support'); ?>>Soporte</option>
                </select>
            </div>
            <div>
                <label><strong>System Prompt:</strong></label>
                <textarea name="system_prompt" class="widefat" rows="4"><?php echo esc_textarea($system_p); ?></textarea>
            </div>
            <div>
                <label><strong>OpenAI API Key:</strong></label>
                <input type="password" name="openai_api_key" value="<?php echo esc_attr($api_key); ?>" class="widefat">
            </div>
        </div>
        <?php
    }

    public function save_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['kurukin_nonce'] ) || ! wp_verify_nonce( $_POST['kurukin_nonce'], KURUKIN_NONCE_ACTION ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = ['evolution_instance_id', 'business_vertical', 'system_prompt'];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[$field] ) ) {
                update_post_meta( $post_id, $this->prefix . $field, sanitize_text_field( $_POST[$field] ) );
            }
        }

        if ( ! empty( $_POST['openai_api_key'] ) ) {
            update_post_meta( $post_id, $this->prefix . 'openai_api_key', $this->encrypt( sanitize_text_field( $_POST['openai_api_key'] ) ) );
        }
    }

    private function encrypt( $data ) {
        $method = "AES-256-CBC";
        $key    = defined('KURUKIN_ENCRYPTION_KEY') ? KURUKIN_ENCRYPTION_KEY : wp_salt('auth');
        $iv     = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
        return base64_encode( openssl_encrypt( $data, $method, $key, 0, $iv ) . '::' . $iv );
    }

    private function decrypt( $data ) {
        $method = "AES-256-CBC";
        $key    = defined('KURUKIN_ENCRYPTION_KEY') ? KURUKIN_ENCRYPTION_KEY : wp_salt('auth');
        $parts  = explode( '::', base64_decode( $data ), 2 );
        if( count($parts) < 2 ) return '';
        return openssl_decrypt( $parts[0], $method, $key, 0, $parts[1] );
    }
}