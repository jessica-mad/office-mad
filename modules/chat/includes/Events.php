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
                // A voice message, or words that need understanding: answered a moment later
                // (Chat only waits 30 s), by the assistant, as a new message.
                if ( $is_dm && ($email === $this->manager_email() || $this->member_by_email($email)) ) {
                    $voice = Transcriber::voice_of((array) ($e['message'] ?? []));
                    $text = (string) ($e['message']['argumentText'] ?? $e['message']['text'] ?? '');
                    if ( $voice || $this->needs_understanding($email, $text) ) {
                        Assistant::defer($email, $voice ? '' : $text, $voice);
                        return rest_ensure_response(new \stdClass());
                    }
                }
                $out = $this->answer($email, $is_dm, (string) ($e['message']['argumentText'] ?? $e['message']['text'] ?? ''));
                return is_array($out) ? rest_ensure_response($out) : $this->text($out);

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

    /** @return string|array a plain reply or a message with a card */
    private function answer($email, $is_dm, $text = ''){
        if ( ! $is_dm ) return __('Soy el asistente automático de MAD. Para cualquier cosa, escribid a Jessica.', 'mad-oficina');
        $is_manager = $email !== '' && $email === $this->manager_email();
        $member = $is_manager ? null : $this->member_by_email($email);
        if ( $is_manager || $member ) {
            $tasks = $this->tasks_answer($email, $text, $member);
            if ( $tasks !== null ) return $tasks;
        }
        if ( $is_manager ) {
            $pending = Approvals::pending(5);
            if ( ! $pending ) return __('No tienes nada pendiente en la bandeja. Todo en orden.', 'mad-oficina');
            $lines = array_map(fn($a) => '• ' . $a['title'], $pending);
            return sprintf(_n('Tienes %d petición pendiente:', 'Tienes %d peticiones pendientes:', count($pending), 'mad-oficina'), Approvals::count_pending())
                . "\n" . implode("\n", $lines) . "\n\n" . admin_url('admin.php?page=mad-oficina-bandeja')
                . "\n\n" . __('Escríbeme «tareas» para ver las tuyas y marcarlas.', 'mad-oficina');
        }
        if ( $member ) return $this->task_list_message($email, $member);
        return __('Hola, soy el asistente automático de MAD Oficina.', 'mad-oficina');
    }

    /**
     * «tareas», «la 2 y la 5 hechas», «todas hechas», «deshacer la 3». The numbers refer
     * to the last list this same person was sent. @return string|array|null null = not about tasks
     */
    private function tasks_answer($email, $text, ?array $member){
        $p = TaskList::parse($text);
        if ( $p['action'] === null ) return null;
        if ( $p['action'] === 'list' ) return $this->task_list_message($email, $member);
        $last = TaskList::last($email);
        if ( $p['action'] === 'ask' || ! $last ) {
            return $last
                ? __('¿Cuáles? Dime los números de la lista: «la 2 y la 5 hechas» o «todas hechas».', 'mad-oficina')
                : __('No tengo ninguna lista tuya reciente. Escríbeme «tareas» y te la paso numerada.', 'mad-oficina');
        }
        $ids = $last['ids'];
        $missing = [];
        if ( $p['nums'] !== 'all' ) {
            $pick = [];
            foreach ( $p['nums'] as $n ) isset($ids[$n - 1]) ? $pick[] = $ids[$n - 1] : $missing[] = $n;
            $ids = $pick;
        }
        $rows = array_values(TaskList::rows($ids));
        if ( $p['action'] === 'undo' ) {
            $r = TaskList::reopen($rows, array_fill_keys($ids, 'open'), $email, $this->manager_email());
            return TaskList::result_card($r, true, $missing);
        }
        if ( $p['nums'] === 'all' ) $rows = array_values(array_filter($rows, fn($t) => in_array($t['status'], TaskList::STATUSES_OPEN, true)));
        return TaskList::result_card(TaskList::mark($rows, $email, $this->manager_email()), false, $missing);
    }

    /** Free words (not «la 2 hecha» nor «tareas») go to the assistant when Claude is configured. */
    private function needs_understanding($email, $text){
        if ( trim($text) === '' || ! \MAD_Oficina\Core\Agents\ClaudeClient::has_key() ) return false;
        $p = TaskList::parse($text);
        if ( $p['action'] === 'list' && mb_strlen(trim($text)) < 40 ) return false; // «tareas»; a real question goes to the assistant
        if ( in_array($p['action'], ['done', 'undo'], true) && TaskList::last($email) ) return false;
        return true;
    }

    /** Open tasks as a numbered card (and remembered, for answers by number). */
    private function task_list_message($email, ?array $member){
        $ids = TaskList::open_ids($member ? (int) $member['id'] : null);
        if ( ! $ids ) return __('No tienes tareas abiertas. ¡Bien!', 'mad-oficina');
        TaskList::remember($email, $ids);
        return TaskList::card(__('Tus tareas abiertas', 'mad-oficina'), $ids);
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
            if ( ! TaskList::allowed($task, $email, $this->manager_email()) ) return $this->text(__('Esta tarea no es tuya.', 'mad-oficina'));
            $wpdb->update(Schema::table('action_items'), ['status' => 'done', 'completed_at' => Time::now(), 'updated_at' => Time::now()], ['id' => $task['id']]);
            return $this->update(Cards::task_done($task));
        }

        // A line of a numbered list: tick or untick it and re-draw the same list.
        if ( in_array($fn, ['task_list_done', 'task_list_undo'], true) ) {
            $id = (int) ($params['task_id'] ?? 0);
            $ids = TaskList::parse_ids($params['ids'] ?? '');
            if ( ! in_array($id, $ids, true) ) $ids[] = $id;
            $rows = array_values(TaskList::rows([$id]));
            if ( ! $rows ) return $this->text(__('Esa tarea ya no existe.', 'mad-oficina'));
            $r = $fn === 'task_list_done'
                ? TaskList::mark($rows, $email, $this->manager_email())
                : TaskList::reopen($rows, [$id => (string) ($params['prev'] ?? 'open')], $email, $this->manager_email());
            if ( $r['denied'] ) return $this->text(__('Esta tarea no es tuya.', 'mad-oficina'));
            $title = sanitize_text_field((string) ($params['title'] ?? '')) ?: __('Tus tareas', 'mad-oficina');
            return $this->update(TaskList::card($title, $ids));
        }

        // «Deshacer» after marking by words.
        if ( $fn === 'task_undo' && ! empty($params['token']) ) {
            $r = ['changed' => [], 'denied' => []];
            foreach ( array_slice(explode(',', (string) $params['token']), 0, 10) as $token ) {
                if ( strpos($token, 'f:') === 0 ) {
                    // Finanzas (what Amparo changed): only Jessica, and only if Finanzas is hers.
                    $uid = $email === $this->manager_email() ? Assistant::manager_user_id($email) : 0;
                    if ( ! $uid || ! class_exists('MAD_Oficina\\Billing\\FinanceUndo') || ! \MAD_Oficina\Core\FinanceAccess::can($uid) ) return $this->text(__('Eso solo lo puede deshacer Jessica.', 'mad-oficina'));
                    $n = \MAD_Oficina\Billing\FinanceUndo::restore($token);
                    if ( is_wp_error($n) ) return $this->text($n->get_error_message());
                    $r['changed'][] = ['title' => sprintf(__('Finanzas: %d cambios deshechos', 'mad-oficina'), $n)];
                    continue;
                }
                $one = TaskList::restore($token, $email, $this->manager_email());
                if ( is_wp_error($one) ) return $this->text($one->get_error_message());
                $r['changed'] = array_merge($r['changed'], $one['changed']); $r['denied'] = array_merge($r['denied'], $one['denied']);
            }
            if ( $r['denied'] && ! $r['changed'] ) return $this->text(__('Esta tarea no es tuya.', 'mad-oficina'));
            return $this->update(Cards::card('undo-' . substr(md5((string) $params['token']), 0, 8), __('Deshecho', 'mad-oficina'), '', [
                ['textParagraph' => ['text' => esc_html__('Lo he dejado como estaba:', 'mad-oficina') . '<br>' . implode('<br>', array_map(fn($t) => '• ' . esc_html($t['title']), $r['changed']))]],
            ]));
        }
        if ( $fn === 'task_undo' ) {
            $prev = TaskList::parse_prev($params['prev'] ?? '');
            $r = TaskList::reopen(array_values(TaskList::rows(array_keys($prev))), $prev, $email, $this->manager_email());
            return $this->update(TaskList::result_card($r, true));
        }
        // Other modules' buttons (e.g. ideas from the Coordinadora). They return a
        // message array (updates the card) or a string (plain reply).
        $is_manager = $email !== '' && $email === $this->manager_email();
        $out = apply_filters('madoficina_chat_action', null, $fn, $params, $email, $is_manager);
        if ( is_array($out) ) return $this->update($out);
        if ( is_string($out) ) return $this->text($out);
        return $this->text(__('No entiendo esa acción.', 'mad-oficina'));
    }
}
