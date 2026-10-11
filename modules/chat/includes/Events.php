<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * POST /wp-json/mad-oficina/v1/chat/events — interaction events from Google Chat
 * (classic Chat app, not a Workspace add-on). Every request carries a JWT signed
 * by Google; nothing is read before it is verified.
 *
 * Only Jessica can approve or reject from Chat; only the assigned team member
 * (or Jessica) can mark a task as done.
 */
final class Events {

    private $settings;
    private $save_settings;

    public function __construct(array $settings, callable $save_settings){
        $this->settings = $settings;
        $this->save_settings = $save_settings;
    }

    public function register(){
        register_rest_route('mad-oficina/v1', '/chat/events', [
            'methods' => 'POST', 'callback' => [$this, 'handle'], 'permission_callback' => [$this, 'verify'],
        ]);
    }

    /** @return true|\WP_Error */
    public function verify(\WP_REST_Request $req){
        $project = trim((string) ($this->settings['project_number'] ?? ''));
        if ( $project === '' ) return new \WP_Error('madof_chat_off', 'Chat app not configured', ['status' => 503]);
        $auth = (string) $req->get_header('authorization');
        if ( ! preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ) return new \WP_Error('madof_jwt', 'Missing token', ['status' => 401]);
        $claims = GoogleJwt::verify($m[1], $project, GoogleJwt::CHAT_ISSUER, GoogleJwt::chat_certs());
        return is_wp_error($claims) ? $claims : true;
    }

    private function manager_email(){
        return (new Messenger($this->settings, null))->manager_email();
    }

    public function handle(\WP_REST_Request $req){
        $e = $req->get_json_params() ?: [];
        if ( ! isset($e['type']) && isset($e['chat']) ) {
            return rest_ensure_response(['text' => 'MAD Oficina: configura la app de Chat sin la opción «Workspace add-on».']);
        }
        $email = strtolower($e['user']['email'] ?? '');
        $space = $e['space'] ?? [];
        $is_dm = in_array($space['type'] ?? $space['spaceType'] ?? '', ['DM', 'DIRECT_MESSAGE'], true) || ! empty($space['singleUserBotDm']);

        switch ( $e['type'] ?? '' ) {
            case 'ADDED_TO_SPACE':
                if ( $is_dm ) $this->remember_dm($email, $space['name'] ?? '');
                return $this->text($is_dm
                    ? __('¡Hola! Soy el asistente de MAD Oficina. Te escribiré aquí cuando haya algo que revisar.', 'mad-oficina')
                    : __('Hola, soy el asistente automático de MAD. Publicaré aquí los resúmenes que Jessica revise.', 'mad-oficina'));

            case 'REMOVED_FROM_SPACE':
                if ( $is_dm ) $this->forget_dm($space['name'] ?? '');
                return rest_ensure_response([]);

            case 'MESSAGE':
                if ( $is_dm ) $this->remember_dm($email, $space['name'] ?? '');
                return $this->text($this->answer($email, $is_dm));

            case 'CARD_CLICKED':
                $fn = $e['common']['invokedFunction'] ?? ($e['action']['actionMethodName'] ?? '');
                $params = $e['common']['parameters'] ?? [];
                foreach ( $e['action']['parameters'] ?? [] as $p ) $params[$p['key']] = $p['value'];
                return $this->click($fn, $params, $email);
        }
        return rest_ensure_response([]);
    }

    private function text($t){
        return rest_ensure_response(['text' => $t]);
    }

    private function update(array $message){
        return rest_ensure_response(['actionResponse' => ['type' => 'UPDATE_MESSAGE']] + $message);
    }

    private function remember_dm($email, $space){
        if ( ! $email || ! preg_match('#^spaces/[A-Za-z0-9_-]+$#', $space) ) return;
        if ( $email === $this->manager_email() ) {
            if ( ($this->settings['manager_dm'] ?? '') !== $space ) {
                $this->settings['manager_dm'] = $space;
                call_user_func($this->save_settings, $this->settings);
            }
            return;
        }
        foreach ( Team::all() as $m ){
            if ( strtolower((string) $m['workspace_email']) === $email && $m['chat_dm_space'] !== $space ) {
                Team::save(['name' => $m['name'], 'slug' => $m['slug'], 'wp_user_id' => $m['wp_user_id'], 'workspace_email' => $m['workspace_email'], 'chat_dm_space' => $space, 'skills' => $m['skills'], 'active' => $m['active']], $m['id']);
            }
        }
    }

    private function forget_dm($space){
        if ( ($this->settings['manager_dm'] ?? '') === $space ) {
            $this->settings['manager_dm'] = '';
            call_user_func($this->save_settings, $this->settings);
        }
    }

    private function answer($email, $is_dm){
        if ( ! $is_dm ) return __('Soy el asistente automático de MAD. Para cualquier cosa, escribid a Jessica.', 'mad-oficina');
        if ( $email === $this->manager_email() ) {
            $pending = Approvals::pending(5);
            if ( ! $pending ) return __('No tienes nada pendiente en la bandeja. Todo en orden.', 'mad-oficina');
            $lines = array_map(fn($a) => '• ' . $a['title'], $pending);
            return sprintf(_n('Tienes %d petición pendiente:', 'Tienes %d peticiones pendientes:', count($pending), 'mad-oficina'), Approvals::count_pending())
                . "\n" . implode("\n", $lines) . "\n\n" . admin_url('admin.php?page=mad-oficina-bandeja');
        }
        $member = $this->member_by_email($email);
        if ( $member ) {
            global $wpdb;
            $titles = $wpdb->get_col($wpdb->prepare('SELECT title FROM ' . Schema::table('action_items') . " WHERE team_member_id = %d AND status IN ('open','in_progress') ORDER BY (due_date IS NULL), due_date LIMIT 10", $member['id']));
            return $titles ? __('Tus tareas abiertas:', 'mad-oficina') . "\n• " . implode("\n• ", $titles) : __('No tienes tareas abiertas. ¡Bien!', 'mad-oficina');
        }
        return __('Hola, soy el asistente automático de MAD Oficina.', 'mad-oficina');
    }

    private function member_by_email($email){
        foreach ( Team::all(true) as $m ) if ( strtolower((string) $m['workspace_email']) === $email ) return $m;
        return null;
    }

    private function click($fn, array $params, $email){
        if ( in_array($fn, ['approve', 'reject'], true) ) {
            if ( $email !== $this->manager_email() ) return $this->text(__('Solo Jessica puede resolver peticiones.', 'mad-oficina'));
            $approval = Approvals::get((int) ($params['approval_id'] ?? 0));
            if ( ! $approval ) return $this->text(__('Esa petición ya no existe.', 'mad-oficina'));
            $input = $fn === 'approve' ? apply_filters('madoficina_approval_default_input_' . $approval['action_type'], [], $approval) : [];
            $user = get_user_by('email', $email);
            $result = Approvals::resolve($approval['id'], $fn, $input, $user ? $user->ID : 0, 'chat');
            if ( is_wp_error($result) ) return $this->update(Cards::resolved($approval, $result->get_error_message()));
            return $this->update(Cards::resolved($approval, $fn === 'approve' ? ($result['message'] ?? __('Aprobada.', 'mad-oficina')) : __('Descartada.', 'mad-oficina')));
        }

        if ( $fn === 'task_done' ) {
            global $wpdb;
            $task = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('action_items') . ' WHERE id = %d', (int) ($params['task_id'] ?? 0)), ARRAY_A);
            if ( ! $task ) return $this->text(__('Esa tarea ya no existe.', 'mad-oficina'));
            $member = $task['team_member_id'] ? Team::get((int) $task['team_member_id']) : null;
            $allowed = $email === $this->manager_email() || ($member && strtolower((string) $member['workspace_email']) === $email);
            if ( ! $allowed ) return $this->text(__('Esta tarea no es tuya.', 'mad-oficina'));
            $wpdb->update(Schema::table('action_items'), ['status' => 'done', 'completed_at' => Time::now(), 'updated_at' => Time::now()], ['id' => $task['id']]);
            return $this->update(Cards::task_done($task));
        }
        return $this->text(__('No entiendo esa acción.', 'mad-oficina'));
    }
}
