<?php
namespace Kurukin\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Fields {

    private $prefix = '_kurukin_';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta_boxes' ] );
        add_action( 'wp_ajax_kurukin_validate_credential', [ $this, 'ajax_validate_credential' ] );
        add_action( 'admin_footer', [ $this, 'print_admin_scripts' ] );
    }

    public function add_meta_boxes() {
        // 1. Cerebro
        add_meta_box( 'kurukin_config', '🧠 Cerebro IA (OpenAI)', [ $this, 'render_meta_box_general' ], 'saas_instance', 'normal', 'high' );
        // 2. Arquitectura
        add_meta_box( 'kurukin_architecture', '🏗️ Arquitectura & Enrutamiento', [ $this, 'render_meta_box_architecture' ], 'saas_instance', 'side', 'high' );
        // 3. Voz
        add_meta_box( 'kurukin_voice', '🎙️ Configuración de Voz (ElevenLabs)', [ $this, 'render_meta_box_voice' ], 'saas_instance', 'normal', 'default' );
        // 4. Contexto
        add_meta_box( 'kurukin_context', '🏢 Contexto del Negocio', [ $this, 'render_meta_box_context' ], 'saas_instance', 'normal', 'default' );
    }

    // --- RENDERIZADORES ---

    public function render_meta_box_general( $post ) {
        $instance_id = get_post_meta( $post->ID, $this->prefix . 'evolution_instance_id', true );
        $system_p    = get_post_meta( $post->ID, $this->prefix . 'system_prompt', true );
        $enc_key     = get_post_meta( $post->ID, $this->prefix . 'openai_api_key', true );
        $api_key     = $this->safe_decrypt( $enc_key );

        wp_nonce_field( \KURUKIN_NONCE_ACTION, 'kurukin_nonce' );
        ?>
        <div style="display:grid; gap:15px;">
            <div>
                <label><strong>ID Instancia (Evolution):</strong></label>
                <input type="text" name="evolution_instance_id" value="<?php echo esc_attr($instance_id); ?>" class="widefat" readonly style="background:#f0f0f1; color:#666;">
            </div>
            <div>
                <label><strong>System Prompt (Personalidad):</strong></label>
                <textarea name="system_prompt" class="widefat" rows="5"><?php echo esc_textarea($system_p); ?></textarea>
            </div>
            <div>
                <label><strong>OpenAI API Key:</strong></label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="password" name="openai_api_key" value="<?php echo esc_attr($api_key); ?>" class="widefat" style="flex:1;">
                    <button type="button" class="button kurukin-validate-btn" data-provider="openai" data-target="openai_api_key">🔍 Validar</button>
                </div>
                <div class="kurukin-msg-openai" style="margin-top:5px; font-weight:bold; font-size:12px;"></div>
            </div>
        </div>
        <?php
    }

    public function render_meta_box_architecture( $post ) {
        $vertical = get_post_meta( $post->ID, $this->prefix . 'business_vertical', true );
        $node     = get_post_meta( $post->ID, $this->prefix . 'cluster_node', true );
        if(!$node) $node = 'alpha-01';
        ?>
        <div style="display:grid; gap:10px;">
            <div>
                <label><strong>Cluster Node:</strong></label> 
                <input type="text" name="cluster_node" value="<?php echo esc_attr($node); ?>" class="widefat">
            </div>
            <div>
                <label><strong>Vertical:</strong></label> 
                <select name="business_vertical" class="widefat">
                    <option value="general" <?php selected($vertical, 'general'); ?>>General</option>
                    <option value="health" <?php selected($vertical, 'health'); ?>>Salud</option>
                    <option value="real_estate" <?php selected($vertical, 'real_estate'); ?>>Inmobiliaria</option>
                    <option value="education" <?php selected($vertical, 'education'); ?>>Educación</option>
                    <option value="ecommerce" <?php selected($vertical, 'ecommerce'); ?>>E-commerce</option>
                </select>
            </div>
        </div>
        <?php
    }

    public function render_meta_box_voice( $post ) {
        $enabled = get_post_meta( $post->ID, $this->prefix . 'voice_enabled', true );
        $voice_id = get_post_meta( $post->ID, $this->prefix . 'eleven_voice_id', true );
        $model_id = get_post_meta( $post->ID, $this->prefix . 'eleven_model_id', true );
        $api_key = $this->safe_decrypt( get_post_meta( $post->ID, $this->prefix . 'eleven_api_key', true ) );
        if(empty($model_id)) $model_id = 'eleven_multilingual_v2';
        ?>
        <div style="display:grid; gap:15px;">
            <label><input type="checkbox" name="voice_enabled" value="1" <?php checked($enabled, '1'); ?>> <strong>Habilitar Voz (TTS)</strong></label>
            <div>
                <label><strong>ElevenLabs API Key:</strong></label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="password" name="eleven_api_key" value="<?php echo esc_attr($api_key); ?>" class="widefat" style="flex:1;">
                    <button type="button" class="button kurukin-validate-btn" data-provider="elevenlabs" data-target="eleven_api_key">🔍 Validar</button>
                </div>
                <div class="kurukin-msg-elevenlabs" style="margin-top:5px; font-weight:bold; font-size:12px;"></div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div><label>Voice ID:</label><input type="text" name="eleven_voice_id" value="<?php echo esc_attr($voice_id); ?>" class="widefat"></div>
                <div><label>Model ID:</label><input type="text" name="eleven_model_id" value="<?php echo esc_attr($model_id); ?>" class="widefat"></div>
            </div>
        </div>
        <?php
    }

    public function render_meta_box_context( $post ) {
        $profile = get_post_meta( $post->ID, $this->prefix . 'context_profile', true );
        $services = get_post_meta( $post->ID, $this->prefix . 'context_services', true );
        $rules = get_post_meta( $post->ID, $this->prefix . 'context_rules', true );
        ?>
        <div style="display:grid; gap:10px;">
            <div><label><strong>Perfil:</strong></label><textarea name="context_profile" class="widefat" rows="3"><?php echo esc_textarea($profile); ?></textarea></div>
            <div><label><strong>Servicios:</strong></label><textarea name="context_services" class="widefat" rows="3"><?php echo esc_textarea($services); ?></textarea></div>
            <div><label><strong>Reglas:</strong></label><textarea name="context_rules" class="widefat" rows="3"><?php echo esc_textarea($rules); ?></textarea></div>
        </div>
        <?php
    }

    public function save_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['kurukin_nonce'] ) || ! wp_verify_nonce( $_POST['kurukin_nonce'], \KURUKIN_NONCE_ACTION ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Textos
        $fields = ['evolution_instance_id', 'system_prompt', 'cluster_node', 'business_vertical', 'eleven_voice_id', 'eleven_model_id', 'context_profile', 'context_services', 'context_rules'];
        foreach($fields as $f) {
            if(isset($_POST[$f])) update_post_meta($post_id, $this->prefix . $f, sanitize_text_field($_POST[$f]));
        }
        
        // Checkbox
        update_post_meta($post_id, $this->prefix . 'voice_enabled', isset($_POST['voice_enabled']) ? '1' : '0');

        // Secretos
        $secrets = ['openai_api_key', 'eleven_api_key'];
        foreach($secrets as $s) {
            if(!empty($_POST[$s])) update_post_meta($post_id, $this->prefix . $s, $this->encrypt(sanitize_text_field($_POST[$s])));
        }
    }

    // --- CRYPTO ---
    private function safe_decrypt( $data ) {
        if ( empty( $data ) ) return '';
        try {
            $key = defined('KURUKIN_ENCRYPTION_KEY') ? KURUKIN_ENCRYPTION_KEY : wp_salt('auth');
            $parts = explode( '::', base64_decode( $data ), 2 );
            if(count($parts) < 2) return '';
            return openssl_decrypt( $parts[0], "AES-256-CBC", $key, 0, $parts[1] );
        } catch ( \Throwable $e ) { return ''; }
    }

    private function encrypt( $data ) {
        $key = defined('KURUKIN_ENCRYPTION_KEY') ? KURUKIN_ENCRYPTION_KEY : wp_salt('auth');
        $iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( "AES-256-CBC" ) );
        return base64_encode( openssl_encrypt( $data, "AES-256-CBC", $key, 0, $iv ) . '::' . $iv );
    }

    // --- AJAX ---
    public function ajax_validate_credential() {
        check_ajax_referer( 'kurukin_validate_action', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permiso denegado' );
        
        $provider = sanitize_text_field( $_POST['provider'] );
        $api_key  = sanitize_text_field( $_POST['api_key'] );
        if(empty($api_key)) wp_send_json_error('Llave vacía');

        $url = ($provider === 'openai') ? 'https://api.openai.com/v1/models?limit=1' : 'https://api.elevenlabs.io/v1/user';
        $headers = ($provider === 'openai') ? ['Authorization' => 'Bearer ' . $api_key] : ['xi-api-key' => $api_key];

        $res = wp_remote_get($url, ['headers' => $headers, 'timeout' => 8]);
        
        if(is_wp_error($res)) wp_send_json_error('Error Red: '.$res->get_error_message());
        if(wp_remote_retrieve_response_code($res) !== 200) wp_send_json_error('Llave Inválida');
        
        wp_send_json_success(['message' => '✅ Conexión Exitosa']);
    }

    public function print_admin_scripts() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'saas_instance' ) return;
        ?>
        <script>
        jQuery(document).ready(function($){
            $('.kurukin-validate-btn').on('click', function(e){
                e.preventDefault();
                var btn = $(this), prov = btn.data('provider'), input = $('input[name="'+btn.data('target')+'"]');
                var msg = (prov==='openai') ? $('.kurukin-msg-openai') : $('.kurukin-msg-elevenlabs');
                
                if(!input.val()){ msg.css('color','red').text('❌ Vacío'); return; }
                btn.prop('disabled',true).text('⏳');
                
                $.post(ajaxurl, {
                    action: 'kurukin_validate_credential',
                    nonce: '<?php echo wp_create_nonce( "kurukin_validate_action" ); ?>',
                    provider: prov, api_key: input.val()
                }, function(res){
                    btn.prop('disabled',false).text('🔍 Validar');
                    msg.css('color', res.success ? 'green' : 'red').text(res.success ? res.data.message : '❌ ' + res.data);
                });
            });
        });
        </script>
        <?php
    }
}