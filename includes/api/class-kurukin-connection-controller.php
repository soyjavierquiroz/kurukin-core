<?php
namespace Kurukin\Core\API;
use WP_REST_Controller; use WP_Error; use Throwable;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Connection_Controller extends WP_REST_Controller {
    protected $namespace = 'kurukin/v1';
    protected $resource  = 'connection';

    public function __construct() { $this->register_routes(); }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->resource . '/status', [ 'methods' => 'GET', 'callback' => [ $this, 'get_status' ], 'permission_callback' => '__return_true' ]);
        register_rest_route( $this->namespace, '/' . $this->resource . '/qr', [ 'methods' => 'GET', 'callback' => [ $this, 'get_qr_smart' ], 'permission_callback' => '__return_true' ]);
        register_rest_route( $this->namespace, '/' . $this->resource . '/reset', [ 'methods' => 'POST', 'callback' => [ $this, 'reset_instance' ], 'permission_callback' => '__return_true' ]);
    }

    public function get_status() {
        try {
            if ( ! is_user_logged_in() ) return new WP_Error( '401', 'Login', ['status'=>401] );
            $c = $this->cfg();
            $res = $this->req('GET', "instance/connectionState/{$c['i']}", null, $c);
            
            // Si hay error de red o no existe la instancia
            if(is_wp_error($res)) return ['state'=>'network_error','message'=>$res->get_error_message()];
            if(!isset($res['instance'])) return ['state'=>'close'];
            
            return ['state'=>$res['instance']['state']];
        } catch(Throwable $e) { return ['state'=>'error','message'=>$e->getMessage()]; }
    }

    public function get_qr_smart() {
        try {
            if ( ! is_user_logged_in() ) return new WP_Error( '401', 'Login', ['status'=>401] );
            $c = $this->cfg();
            
            // 1. Intentar pedir QR
            $res = $this->req('GET', "instance/connect/{$c['i']}", null, $c);
            
            // 2. Si no existe (404), CREAR
            if(isset($res['status']) && $res['status'] == 404) {
                $this->req('POST', 'instance/create', [
                    'instanceName' => $c['i'], 
                    'integration' => 'WHATSAPP-BAILEYS', 
                    'qrcode' => true
                ], $c);
                
                // Esperar 1 segundo para inicialización
                sleep(1);
            }

            // 3. BUCLE DE REINTENTO (La clave del éxito)
            // Intentamos 5 veces obtener el QR (esperando 1s entre intentos)
            // Porque Evolution tarda en generar la imagen base64
            for ($k = 0; $k < 5; $k++) {
                $res = $this->req('GET', "instance/connect/{$c['i']}", null, $c);
                
                // Si tenemos QR, rompemos el bucle y lo devolvemos
                if ( isset($res['base64']) && !empty($res['base64']) ) {
                    return ['base64' => $res['base64'], 'code' => isset($res['code'])?$res['code']:null];
                }
                
                // Si no, esperamos 1 segundo y reintentamos
                sleep(1);
            }

            // Si llegamos aquí, Evolution no generó el QR a tiempo
            return ['base64' => null, 'message' => 'Timeout esperando QR'];

        } catch(Throwable $e) { return new WP_Error('500',$e->getMessage(),['status'=>500]); }
    }

    public function reset_instance() {
        $c = $this->cfg();
        $this->req('DELETE', "instance/delete/{$c['i']}", null, $c);
        return $this->get_qr_smart();
    }

    private function cfg() {
        $u = wp_get_current_user();
        return [
            'i' => sanitize_title($u->user_login),
            'u' => 'http://evolution_api_v2:8080',
            'k' => 'cdfedf0ae18a2b08cdd180823fad884d'
        ];
    }

    private function req($m, $ep, $b, $c) {
        $url = trailingslashit($c['u']) . $ep;
        $args = ['method'=>$m, 'headers'=>['Content-Type'=>'application/json','apikey'=>$c['k']], 'timeout'=>15, 'sslverify'=>false];
        if($b) $args['body'] = json_encode($b);
        $res = wp_remote_request($url, $args);
        if(is_wp_error($res)) return $res;
        return json_decode(wp_remote_retrieve_body($res), true) ?: [];
    }
}