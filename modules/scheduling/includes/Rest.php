<?php
namespace MAD_Oficina\Scheduling;

use MAD_Oficina\Core\ClientContext;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Roles;

if ( ! defined('ABSPATH') ) exit;

/**
 * Portal routes for "Pedir una reunión". The client always comes from the
 * logged-in user; only client users can send requests (a manager previewing
 * an office only sees the slots).
 */
final class Rest {

    const NS = 'mad-oficina/v1';

    private $requests;

    public function __construct(Requests $requests){
        $this->requests = $requests;
    }

    public function register(){
        $client_arg = ['client' => ['type' => 'integer', 'required' => false, 'minimum' => 1]];
        register_rest_route(self::NS, '/portal/meeting-requests', [
            [
                'methods' => 'GET', 'args' => $client_arg, 'permission_callback' => [$this, 'can_portal'],
                'callback' => [$this, 'index'],
            ],
            [
                'methods' => 'POST', 'permission_callback' => [$this, 'can_request'],
                'args' => [
                    'start' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                    'topic' => ['type' => 'string', 'required' => false, 'maxLength' => 500],
                ],
                'callback' => [$this, 'create'],
            ],
        ]);
        register_rest_route(self::NS, '/portal/meeting-requests/interpret', [
            'methods' => 'POST', 'permission_callback' => [$this, 'can_request'],
            'args' => ['text' => ['type' => 'string', 'required' => true, 'maxLength' => 300]],
            'callback' => [$this, 'interpret'],
        ]);
    }

    public function can_portal(){
        if ( ! is_user_logged_in() ) return new \WP_Error('rest_not_logged_in', __('Inicia sesión para entrar en la oficina.', 'mad-oficina'), ['status' => 401]);
        if ( ! current_user_can(Roles::CAP_PORTAL) ) return new \WP_Error('rest_forbidden', __('Tu usuario no tiene acceso a la oficina.', 'mad-oficina'), ['status' => 403]);
        return true;
    }

    public function can_request(){
        $ok = $this->can_portal();
        if ( is_wp_error($ok) ) return $ok;
        if ( ! ClientContext::client_for_user(get_current_user_id()) ) {
            return new \WP_Error('rest_forbidden', __('Solo los clientes pueden pedir reunión desde aquí. Desde gerencia, créala en Calendar.', 'mad-oficina'), ['status' => 403]);
        }
        return true;
    }

    /** @return int|\WP_Error */
    private function client(\WP_REST_Request $req){
        $id = ClientContext::resolve($req->get_param('client'));
        if ( $id && Clients::get($id) ) return $id;
        return new \WP_Error('madof_no_client', __('Elige un cliente.', 'mad-oficina'), ['status' => 400]);
    }

    public function index(\WP_REST_Request $req){
        $client_id = $this->client($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $out = [
            'request'  => $this->requests->status_for_client($client_id),
            'can_book' => (bool) ClientContext::client_for_user(get_current_user_id()),
            'slots'    => [],
        ];
        if ( $out['request'] && in_array($out['request']['status'], ['pending', 'processing'], true) ) return rest_ensure_response($out);
        if ( ! Calendar::is_ready() ) {
            $out['error'] = __('La agenda aún no está conectada. Escríbele a Jessica y te propone fecha.', 'mad-oficina');
            return rest_ensure_response($out);
        }
        $slots = $this->requests->free_slots();
        if ( is_wp_error($slots) ) {
            $out['error'] = __('Ahora mismo no puedo consultar la agenda. Inténtalo en un rato.', 'mad-oficina');
            return rest_ensure_response($out);
        }
        $out['slots'] = $this->requests->present(Slots::offer($slots, 5, wp_timezone()));
        return rest_ensure_response($out);
    }

    public function create(\WP_REST_Request $req){
        $client_id = $this->client($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $r = $this->requests->request($client_id, wp_get_current_user(), (int) $req->get_param('start'), (string) $req->get_param('topic'));
        return is_wp_error($r) ? $r : rest_ensure_response($r + ['request_status' => $this->requests->status_for_client($client_id)]);
    }

    public function interpret(\WP_REST_Request $req){
        $client_id = $this->client($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $slots = $this->requests->interpret($client_id, (string) $req->get_param('text'));
        return is_wp_error($slots) ? $slots : rest_ensure_response(['slots' => $slots]);
    }
}
