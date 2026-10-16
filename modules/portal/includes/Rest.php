<?php
namespace MAD_Oficina\Portal;

use MAD_Oficina\Core\Agents\Characters;
use MAD_Oficina\Core\Agents\ClaudeClient;
use MAD_Oficina\Core\ClientContext;
use MAD_Oficina\Core\OfficeState;
use MAD_Oficina\Core\PortalAreas;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Portal;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Roles;

if ( ! defined('ABSPATH') ) exit;

/**
 * REST API for the office/portal: /wp-json/mad-oficina/v1/...
 * Cookie auth + X-WP-Nonce. The client is always resolved on the server.
 */
final class Rest {

    const NS = 'mad-oficina/v1';

    private $receptionist;

    public function __construct(Receptionist $receptionist){
        $this->receptionist = $receptionist;
    }

    public function register(){
        $client_arg = ['client' => ['type' => 'integer', 'required' => false, 'minimum' => 1]];

        register_rest_route(self::NS, '/me', [
            'methods' => 'GET', 'callback' => [$this, 'me'], 'permission_callback' => [$this, 'can_portal'],
        ]);
        // Gerencia only: what each agent waiting in the antechamber brings, and the answer from
        // the office (the same as «Aprobar tal cual» / «Descartar» in Chat; editing stays in the inbox).
        $manager = fn() => current_user_can(Roles::CAP_MANAGE) ? true : new \WP_Error('rest_forbidden', __('Solo para gerencia.', 'mad-oficina'), ['status' => rest_authorization_required_code()]);
        register_rest_route(self::NS, '/office/waiting', [
            'methods' => 'GET', 'callback' => fn() => rest_ensure_response(['characters' => self::waiting()]), 'permission_callback' => $manager,
        ]);
        register_rest_route(self::NS, '/office/approvals/(?P<id>\d+)', [
            'methods' => 'POST', 'permission_callback' => $manager,
            'args' => ['decision' => ['required' => true, 'type' => 'string', 'enum' => ['approve', 'reject']]],
            'callback' => function(\WP_REST_Request $r){
                $a = \MAD_Oficina\Core\Approvals::get((int) $r['id']);
                if ( ! $a ) return new \WP_Error('madof_not_found', __('Esa petición ya no existe.', 'mad-oficina'), ['status' => 404]);
                $approve = $r['decision'] === 'approve';
                $input = $approve ? (array) apply_filters('madoficina_approval_default_input_' . $a['action_type'], [], $a) : [];
                $res = \MAD_Oficina\Core\Approvals::resolve($a['id'], $r['decision'], $input, get_current_user_id(), 'portal');
                if ( is_wp_error($res) ) return $res;
                return rest_ensure_response(['message' => is_array($res) && ! empty($res['message']) ? $res['message'] : ($approve ? __('Aprobado.', 'mad-oficina') : __('Descartado.', 'mad-oficina')), 'characters' => self::waiting()]);
            },
        ]);

        register_rest_route(self::NS, '/office/state', [
            'methods' => 'GET', 'callback' => fn() => rest_ensure_response(['characters' => OfficeState::compute()]),
            'permission_callback' => [$this, 'can_portal'],
        ]);

        // Route = area (see PortalAreas): each person of the company only reads what is open for them.
        $sections = [
            'timeline'  => fn($id) => Portal::timeline($id),
            'meetings'  => fn($id) => Portal::meetings($id),
            'tasks'     => fn($id) => Portal::tasks($id),
            'ideas'     => fn($id) => Portal::ideas($id),
            'documents' => fn($id) => Portal::documents($id),
        ];
        foreach ( $sections as $route => $reader ){
            register_rest_route(self::NS, '/portal/' . $route, [
                'methods' => 'GET', 'args' => $client_arg, 'permission_callback' => [$this, 'can_portal'],
                'callback' => function(\WP_REST_Request $req) use ($reader, $route){
                    $client_id = $this->client_or_error($req);
                    if ( is_wp_error($client_id) ) return $client_id;
                    if ( ! PortalAreas::can($route) ) return PortalAreas::denied();
                    return rest_ensure_response($reader($client_id));
                },
            ]);
        }

        register_rest_route(self::NS, '/portal/chat', [
            [
                'methods' => 'GET', 'args' => $client_arg, 'permission_callback' => [$this, 'can_portal'],
                'callback' => [$this, 'chat_history'],
            ],
            [
                'methods' => 'POST', 'permission_callback' => [$this, 'can_portal'],
                'args' => $client_arg + ['message' => ['type' => 'string', 'required' => true, 'maxLength' => Receptionist::MAX_CHARS]],
                'callback' => [$this, 'chat_send'],
            ],
        ]);

        register_rest_route(self::NS, '/team/tasks', [
            'methods' => 'GET', 'callback' => [$this, 'team_tasks'],
            'permission_callback' => fn() => current_user_can(Roles::CAP_TEAM) ? true : new \WP_Error('rest_forbidden', __('Solo para el equipo.', 'mad-oficina'), ['status' => rest_authorization_required_code()]),
        ]);
    }

    /** Pending requests by character (the agent that waits for Jessica). */
    public static function waiting(){
        $types = \MAD_Oficina\Core\Approvals::types();
        $out = [];
        foreach ( \MAD_Oficina\Core\Approvals::pending(100) as $a ) {
            $slug = OfficeState::AGENT_TO_CHARACTER[$a['agent']] ?? null;
            if ( ! $slug || $a['status'] !== 'pending' || count($out[$slug] ?? []) >= 10 ) continue;
            $out[$slug][] = [
                'id' => (int) $a['id'], 'title' => $a['title'], 'summary' => (string) $a['summary'], 'type' => $types[$a['action_type']]['label'] ?? '',
                'external' => ! empty($types[$a['action_type']]['external']),
                'url' => admin_url('admin.php?page=mad-oficina-bandeja#approval-' . (int) $a['id']),
            ];
        }
        return (object) $out;
    }

    public function can_portal(){
        if ( ! is_user_logged_in() ) return new \WP_Error('rest_not_logged_in', __('Inicia sesión para entrar en la oficina.', 'mad-oficina'), ['status' => 401]);
        if ( ! current_user_can(Roles::CAP_PORTAL) ) return new \WP_Error('rest_forbidden', __('Tu usuario no tiene acceso a la oficina.', 'mad-oficina'), ['status' => 403]);
        return true;
    }

    /** @return int|\WP_Error */
    private function client_or_error(\WP_REST_Request $req){
        $client_id = ClientContext::resolve($req->get_param('client'));
        if ( $client_id && Clients::get($client_id) ) return $client_id;
        if ( current_user_can(Roles::CAP_MANAGE) ) {
            return new \WP_Error('madof_pick_client', __('Elige un cliente para ver su oficina.', 'mad-oficina'), ['status' => 400]);
        }
        return new \WP_Error('madof_no_client', __('Tu usuario no está vinculado a ningún cliente. Escribe a Jessica.', 'mad-oficina'), ['status' => 403]);
    }

    private function role(){
        if ( current_user_can(Roles::CAP_MANAGE) ) return 'manager';
        if ( current_user_can(Roles::CAP_TEAM) ) return 'team';
        return 'client';
    }

    public function me(\WP_REST_Request $req){
        $user = wp_get_current_user();
        $role = $this->role();
        $client = null;
        if ( $role === 'client' ) {
            $c = Clients::get((int) ClientContext::client_for_user($user->ID));
            if ( $c ) $client = ['id' => $c['id'], 'name' => $c['name'], 'code' => $c['code']];
        }

        $characters = Characters::public_data();

        $data = [
            'user'       => ['name' => $user->first_name ?: $user->display_name],
            'role'       => $role,
            'client'     => $client,
            'areas'      => PortalAreas::for_user($user->ID),
            'characters' => $characters,
            'rooms'      => Characters::ROOMS,
            'chat'       => [
                'enabled'   => ClaudeClient::has_key() && $role !== 'team',
                'remaining' => $client ? max(0, $this->receptionist->daily_limit() - $this->receptionist->used_today($client['id'])) : null,
            ],
            'links'      => [
                'logout'  => wp_logout_url(home_url('/')),
                'privacy' => get_privacy_policy_url() ?: null,
            ],
        ];
        if ( $role === 'manager' ) {
            $data['inbox'] = ['pending' => \MAD_Oficina\Core\Approvals::count_pending(), 'url' => admin_url('admin.php?page=mad-oficina-bandeja')];
            $data['clients'] = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'code' => $c['code']], Clients::all(['status' => 'active']));
        }
        return rest_ensure_response(apply_filters('madoficina_portal_me', $data, $user)); // other modules add their bits (e.g. the brief form)
    }

    public function chat_history(\WP_REST_Request $req){
        $client_id = $this->client_or_error($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $rows = $this->receptionist->history($client_id, get_current_user_id());
        return rest_ensure_response(array_map(fn($r) => [
            'from' => $r['author_type'] === 'client' ? 'user' : 'agent',
            'text' => $r['body'],
            'at'   => $r['created_at'],
        ], $rows));
    }

    public function chat_send(\WP_REST_Request $req){
        $client_id = $this->client_or_error($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $result = $this->receptionist->reply($client_id, get_current_user_id(), $req->get_param('message'));
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function team_tasks(){
        $member = Team::find_by_user(get_current_user_id());
        return rest_ensure_response($member ? Portal::team_tasks($member['id']) : []);
    }
}
