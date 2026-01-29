<?php
namespace Kurukin\Core\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MemberPress_Integration {

    public function __construct() {
        // Ejecutar cuando se crea un usuario (Registro WP o Compra MemberPress)
        add_action( 'user_register', [ $this, 'auto_create_bot_instance' ], 10, 1 );
    }

    /**
     * Crea automáticamente el CPT 'saas_instance' para el nuevo usuario
     */
    public function auto_create_bot_instance( $user_id ) {
        
        $user_info = get_userdata( $user_id );
        $username  = $user_info->user_login;

        // Sanitizamos el username para que sea seguro como ID de Evolution (sin espacios, minúsculas)
        $instance_id = sanitize_title( $username );

        // Verificar si ya existe para no duplicar
        $existing = new \WP_Query([
            'post_type'  => 'saas_instance',
            'author'     => $user_id,
            'fields'     => 'ids'
        ]);

        if ( $existing->have_posts() ) {
            return; 
        }

        // Crear la Instancia
        $post_data = [
            'post_title'   => 'Bot de ' . $username,
            'post_status'  => 'publish', // Publicado pero privado por CPT settings
            'post_type'    => 'saas_instance',
            'post_author'  => $user_id
        ];

        $post_id = wp_insert_post( $post_data );

        if ( ! is_wp_error( $post_id ) ) {
            // Guardar el ID de Evolution (que ahora es el username limpio)
            update_post_meta( $post_id, '_kurukin_evolution_instance_id', $instance_id );
            
            // Valores por defecto
            update_post_meta( $post_id, '_kurukin_business_vertical', 'default' );
            update_post_meta( $post_id, '_kurukin_system_prompt', 'Eres un asistente útil.' );
        }
    }
}