<?php
namespace MAD_Oficina\Followup;

use MAD_Oficina\Chat\Cards;
use MAD_Oficina\Chat\TaskList;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * The Coordinadora: reminders, ideas that come back, the brief before each
 * client meeting and the Monday summary. Everything goes to Jessica and Liz by
 * Chat; anything for a client goes through the inbox (client_reminder).
 */
final class Coordinator {

    const AGENT  = 'coordinator';
    const ACTION = 'client_reminder';
    const SENT   = 'madoficina_followup_sent';

    const DEFAULTS = [
        'daily_hour'       => 9,
        'reminders'        => true,
        'days_ahead'       => 2,
        'client_reminders' => true,
        'client_every'     => 7,
        'ideas'            => true,
        'briefs'           => true,
        'weekly'           => true,
    ];

    private $settings;

    public function __construct(array $settings){
        $this->settings = array_merge(self::DEFAULTS, $settings);
    }

    public function setting($key){ return $this->settings[$key] ?? null; }

    /** @return \MAD_Oficina\Chat\Messenger|null */
    private function messenger(){
        $chat = \MAD_Oficina_Core::instance()->get_module('chat');
        return $chat ? $chat->messenger() : null;
    }

    private function portal_url(){
        $chat = \MAD_Oficina_Core::instance()->get_module('chat');
        return $chat ? $chat->portal_url() : home_url('/oficina/');
    }

    private function log($event, array $data, $client_id = null){
        global $wpdb;
        $wpdb->insert(Schema::table('agent_log'), [
            'agent' => self::AGENT, 'event' => $event, 'client_id' => $client_id,
            'data' => wp_json_encode($data), 'created_at' => Time::now(),
        ]);
    }

    /* ───────── "already done" bookkeeping ───────── */

    private function sent(){ return (array) get_option(self::SENT, []); }

    private function was_sent($key){ return isset($this->sent()[$key]); }

    private function mark_sent($key){
        $sent = $this->sent();
        $sent[$key] = time();
        $sent = array_filter($sent, fn($t) => $t > time() - 30 * DAY_IN_SECONDS);
        update_option(self::SENT, $sent, false);
    }

    /* ───────── scheduler: called every hour ───────── */

    /** @return array what ran */
    public function run($now = null){
        $now = $now ?? time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(wp_timezone());
        $today = $local->format('Y-m-d');
        $ran = [];
        if ( (int) $local->format('G') >= (int) $this->settings['daily_hour'] && ! $this->was_sent('daily:' . $today) ) {
            $this->mark_sent('daily:' . $today); // first, so a slow run is never repeated
            if ( $this->settings['reminders'] ) $ran['reminders'] = $this->team_reminders($today);
            if ( $this->settings['client_reminders'] ) $ran['client_reminders'] = $this->client_reminders($today);
            if ( $this->settings['ideas'] ) $ran['ideas'] = $this->surface_ideas($today);
        }
        if ( $local->format('N') === '1' && ! $this->was_sent('review:' . $today) ) {
            $this->mark_sent('review:' . $today);
            Reconciler::enqueue_all(); // every Monday, all clients
        }
        if ( Reconciler::pending() ) $ran['review'] = (new Reconciler())->run_queue();
        if ( $this->settings['weekly'] && $local->format('N') === '1' && $local->format('H:i') >= '08:45' && ! $this->was_sent('weekly:' . $today) ) {
            $this->mark_sent('weekly:' . $today);
            $ran['weekly'] = $this->weekly_summary($today);
        }
        if ( $this->settings['briefs'] && ! get_transient('madof_briefs_checked') ) {
            set_transient('madof_briefs_checked', 1, 50 * MINUTE_IN_SECONDS); // Calendar once an hour is enough
            $ran['briefs'] = $this->briefs($now);
        }
        return $ran;
    }

    /* ───────── tasks ───────── */

    private function open_tasks($where, array $args){
        global $wpdb;
        $sql = 'SELECT t.*, c.code AS client_code, c.name AS client_name FROM ' . Schema::table('action_items') . ' t LEFT JOIN ' . Schema::table('clients') . " c ON c.id = t.client_id WHERE t.status IN ('open','in_progress') AND {$where} ORDER BY t.due_date ASC, t.id ASC";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
    }

    private static function line(array $t, $today){
        $due = $t['due_date'];
        $when = $due < $today ? sprintf(__('vencida el %s', 'mad-oficina'), mysql2date('j M', $due)) : ($due === $today ? __('hoy', 'mad-oficina') : mysql2date('D j M', $due));
        return '• ' . ($t['client_code'] ? $t['client_code'] . ' · ' : '') . $t['title'] . ' — ' . $when;
    }

    /** Due soon or overdue → one message for Jessica and one for each team member. */
    public function team_reminders($today){
        $limit = gmdate('Y-m-d', strtotime($today . ' +' . (int) $this->settings['days_ahead'] . ' days'));
        $messenger = $this->messenger();
        if ( ! $messenger ) return 0;
        $sent = 0;

        $mine = $this->open_tasks("t.owner IN ('jessica','mad') AND t.team_member_id IS NULL AND t.due_date IS NOT NULL AND t.due_date <= %s", [$limit]);
        if ( $mine ) {
            // A numbered card: she ticks lines or answers «la 2 y la 5 hechas».
            $ids = array_map(fn($t) => (int) $t['id'], array_slice($mine, 0, TaskList::MAX));
            $r = $messenger->to_manager(['text' => __('Tus tareas para estos días:', 'mad-oficina')] + TaskList::card(__('Tus tareas para estos días', 'mad-oficina'), $ids, $today));
            if ( is_wp_error($r) ) $this->log('reminder_error', ['to' => 'jessica', 'error' => $r->get_error_message()]);
            else { TaskList::remember($messenger->manager_email(), $ids); $sent++; }
        }

        foreach ( Team::all(true) as $member ) {
            $tasks = $this->open_tasks('t.team_member_id = %d AND t.due_date IS NOT NULL AND t.due_date <= %s', [$member['id'], $limit]);
            if ( ! $tasks ) continue;
            $ids = array_map(fn($t) => (int) $t['id'], array_slice($tasks, 0, TaskList::MAX));
            $text = sprintf(__('Hola, %s. Tus tareas para estos días:', 'mad-oficina'), $member['name']);
            $card = TaskList::card(__('Tus tareas para estos días', 'mad-oficina'), $ids, $today, sprintf(__('Las tienes todas en la oficina: %s', 'mad-oficina'), $this->portal_url()));
            $r = $messenger->to_team_member($member, ['text' => $text] + $card);
            if ( is_wp_error($r) ) $this->log('reminder_error', ['to' => $member['slug'], 'error' => $r->get_error_message()]);
            else { TaskList::remember($member['workspace_email'], $ids); $sent++; }
        }
        return $sent;
    }

    /** Client tasks due soon → a reminder proposal in the inbox (at most one per client every N days). */
    public function client_reminders($today){
        global $wpdb;
        $limit = gmdate('Y-m-d', strtotime($today . ' +' . (int) $this->settings['days_ahead'] . ' days'));
        $oldest = gmdate('Y-m-d', strtotime($today . ' -30 days'));
        $tasks = $this->open_tasks("t.owner = 'cliente' AND t.visibility = 'client' AND t.client_id IS NOT NULL AND t.due_date BETWEEN %s AND %s", [$oldest, $limit]);
        $by_client = [];
        foreach ( $tasks as $t ) $by_client[(int) $t['client_id']][] = $t;

        $created = 0;
        $since = gmdate('Y-m-d H:i:s', time() - (int) $this->settings['client_every'] * DAY_IN_SECONDS);
        foreach ( $by_client as $client_id => $list ) {
            $recent = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('approvals') . ' WHERE action_type = %s AND client_id = %d AND created_at > %s LIMIT 1', self::ACTION, $client_id, $since));
            if ( $recent ) continue;
            $client = Clients::get($client_id);
            if ( ! $client || $client['status'] === 'archived' ) continue;
            Approvals::request(self::AGENT, self::ACTION,
                sprintf(__('Recordar tareas a %s', 'mad-oficina'), $client['name']),
                ['client_id' => $client_id, 'task_ids' => array_map(fn($t) => (int) $t['id'], $list), 'text' => $this->client_text($client, $list, $today)],
                ['client_id' => $client_id, 'summary' => implode("\n", array_map(fn($t) => self::line($t, $today), $list))],
                ['auto' => 1]
            );
            $created++;
        }
        return $created;
    }

    private function client_text(array $client, array $tasks, $today){
        $lines = array_map(function($t) use ($today){
            return '• ' . $t['title'] . ($t['due_date'] < $today
                ? ' (' . sprintf(__('era para el %s', 'mad-oficina'), mysql2date('j M', $t['due_date'])) . ')'
                : ' (' . sprintf(__('para el %s', 'mad-oficina'), mysql2date('j M', $t['due_date'])) . ')');
        }, $tasks);
        return sprintf(__('Hola, equipo de %s:', 'mad-oficina'), $client['name']) . "\n\n"
            . __('Os recordamos lo que quedó pendiente de vuestra parte:', 'mad-oficina') . "\n" . implode("\n", $lines) . "\n\n"
            . __('Si ya está hecho o necesitáis ayuda, contádnoslo por aquí.', 'mad-oficina') . "\n"
            . sprintf(__('Lo tenéis todo en vuestra oficina: %s', 'mad-oficina'), $this->portal_url());
    }

    /** @return array|\WP_Error */
    public function approve_client_reminder(array $approval, array $input){
        $p = $approval['payload'];
        $client = Clients::get((int) $p['client_id']);
        if ( ! $client ) return new \WP_Error('madof_rem_client', __('El cliente ya no existe.', 'mad-oficina'));
        $messenger = $this->messenger();
        if ( ! $messenger ) return new \WP_Error('madof_rem_chat', __('Activa el módulo de Chat para enviar recordatorios.', 'mad-oficina'));
        $text = trim(sanitize_textarea_field($input['text'] ?? '')) ?: $p['text'];
        $signature = ! empty($input['auto']) ? Cards::SIGNATURE_AUTO : Cards::SIGNATURE;
        $r = $messenger->to_client($client, 'reminders', $text . "\n\n_" . __($signature, 'mad-oficina') . '_', sprintf(__('Recordatorio de tareas · %s', 'mad-oficina'), $client['name']));
        if ( is_wp_error($r) ) return $r;
        return ['message' => sprintf(__('Recordatorio enviado a %s.', 'mad-oficina'), $client['name'])];
    }

    public static function render_client_reminder_form($html, array $approval){
        ob_start(); ?>
        <label><?php esc_html_e('Mensaje para el cliente', 'mad-oficina'); ?>
            <textarea name="input[text]" rows="8"><?php echo esc_textarea($approval['payload']['text'] ?? ''); ?></textarea></label>
        <p class="description"><?php esc_html_e('Sale por el destino de «Recordatorios» del cliente, firmado como mensaje automático.', 'mad-oficina'); ?></p>
        <?php
        return ob_get_clean();
    }

    /* ───────── ideas ───────── */

    public function surface_ideas($today){
        global $wpdb;
        $table = Schema::table('ideas');
        $ideas = $wpdb->get_results($wpdb->prepare(
            'SELECT i.*, c.code AS client_code, c.name AS client_name FROM ' . $table . ' i LEFT JOIN ' . Schema::table('clients') . " c ON c.id = i.client_id WHERE i.status = 'dormant' AND i.resurface_at IS NOT NULL AND i.resurface_at <= %s ORDER BY i.resurface_at LIMIT 10",
            $today
        ), ARRAY_A);
        $messenger = $this->messenger();
        if ( ! $messenger ) return 0;
        $sent = 0;
        foreach ( $ideas as $idea ) {
            $r = $messenger->to_manager(self::idea_card($idea));
            if ( is_wp_error($r) ) { $this->log('idea_error', ['idea' => (int) $idea['id'], 'error' => $r->get_error_message()]); break; }
            $wpdb->update($table, ['status' => 'surfaced', 'updated_at' => Time::now()], ['id' => $idea['id']]);
            $sent++;
        }
        return $sent;
    }

    public static function idea_card(array $idea){
        $ago = human_time_diff(strtotime($idea['created_at']), time());
        $text = sprintf(__('Hace %1$s hablamos de esto con %2$s. ¿La retomamos?', 'mad-oficina'), $ago, $idea['client_name'] ?: __('un cliente', 'mad-oficina'));
        if ( $idea['description'] ) $text .= '<br><br>' . esc_html($idea['description']);
        return Cards::card('idea-' . $idea['id'], ($idea['client_code'] ? $idea['client_code'] . ' · ' : '') . __('Idea que vuelve', 'mad-oficina'), $idea['title'], [
            ['textParagraph' => ['text' => $text]],
            ['buttonList' => ['buttons' => [
                Cards::button(__('Convertir en tarea', 'mad-oficina'), 'idea_task', ['idea_id' => $idea['id']]),
                Cards::button(__('Dentro de un mes', 'mad-oficina'), 'idea_postpone', ['idea_id' => $idea['id']]),
                Cards::button(__('Descartar', 'mad-oficina'), 'idea_discard', ['idea_id' => $idea['id']]),
            ]]],
        ]);
    }

    /** Idea buttons from Chat. Only Jessica. @return array|string|null */
    public static function chat_action($out, $fn, array $params, $email, $is_manager){
        if ( ! in_array($fn, ['idea_task', 'idea_postpone', 'idea_discard'], true) ) return $out;
        if ( ! $is_manager ) return __('Solo Jessica puede decidir sobre las ideas.', 'mad-oficina');
        $r = self::resolve_idea((int) ($params['idea_id'] ?? 0), substr($fn, 5));
        if ( is_wp_error($r) ) return $r->get_error_message();
        return Cards::card('idea-' . $r['idea']['id'], __('Idea', 'mad-oficina'), $r['idea']['title'], [
            ['decoratedText' => ['topLabel' => __('Hecho', 'mad-oficina'), 'text' => esc_html($r['message']), 'wrapText' => true]],
        ]);
    }

    /** @param string $decision task|postpone|discard @return array|\WP_Error */
    public static function resolve_idea($idea_id, $decision){
        global $wpdb;
        $table = Schema::table('ideas');
        $idea = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $idea_id), ARRAY_A);
        if ( ! $idea ) return new \WP_Error('madof_idea', __('Esa idea ya no existe.', 'mad-oficina'));
        if ( in_array($idea['status'], ['converted', 'discarded'], true) ) return new \WP_Error('madof_idea_done', __('Esa idea ya estaba resuelta.', 'mad-oficina'));

        switch ( $decision ) {
            case 'task':
                $wpdb->insert(Schema::table('action_items'), [
                    'client_id' => $idea['client_id'], 'meeting_id' => $idea['meeting_id'], 'title' => $idea['title'], 'description' => $idea['description'],
                    'owner' => 'jessica', 'status' => 'open', 'visibility' => 'internal', 'source' => 'idea',
                    'created_at' => Time::now(), 'updated_at' => Time::now(),
                ]);
                $wpdb->update($table, ['status' => 'converted', 'updated_at' => Time::now()], ['id' => $idea_id]);
                $msg = __('Convertida en tarea tuya (interna). Ponle fecha en Tareas.', 'mad-oficina');
                break;
            case 'postpone':
                $date = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +1 month'));
                $wpdb->update($table, ['status' => 'dormant', 'resurface_at' => $date, 'updated_at' => Time::now()], ['id' => $idea_id]);
                $msg = sprintf(__('Te la vuelvo a traer el %s.', 'mad-oficina'), mysql2date('j M Y', $date));
                break;
            case 'discard':
                $wpdb->update($table, ['status' => 'discarded', 'updated_at' => Time::now()], ['id' => $idea_id]);
                $msg = __('Descartada.', 'mad-oficina');
                break;
            default:
                return new \WP_Error('madof_idea', 'Unknown decision');
        }
        return ['idea' => $idea, 'message' => $msg];
    }

    /* ───────── brief before client meetings ───────── */

    /** Client events in the next 24 h get one brief each. */
    public function briefs($now){
        if ( ! class_exists('MAD_Oficina\\Scheduling\\Calendar') || ! class_exists('MAD_Oficina\\Meetings\\Identifier') ) return 0;
        if ( ! \MAD_Oficina\Scheduling\Calendar::is_ready() ) return 0;
        $events = \MAD_Oficina\Scheduling\Calendar::events($now, $now + DAY_IN_SECONDS);
        if ( is_wp_error($events) ) { $this->log('brief_error', ['error' => $events->get_error_message()]); return 0; }
        $messenger = $this->messenger();
        if ( ! $messenger ) return 0;
        $google = get_option(\MAD_Oficina_Core::option_key('google'), []);
        $own = strtolower($google['workspace_domain'] ?? 'imad.pro');

        $sent = 0;
        foreach ( $events as $e ) {
            if ( empty($e['id']) || empty($e['start']['dateTime']) || ($e['status'] ?? '') === 'cancelled' ) continue;
            $key = 'brief:' . $e['id'] . ':' . substr($e['start']['dateTime'], 0, 10);
            if ( $this->was_sent($key) ) continue;
            $attendees = array_map(fn($a) => ['email' => $a['email'] ?? '', 'name' => $a['displayName'] ?? ''], $e['attendees'] ?? []);
            $id = \MAD_Oficina\Meetings\Identifier::identify($e['summary'] ?? '', $attendees, $own);
            if ( ! $id['client_id'] || $id['confidence'] === 'low' ) continue;
            $client = Clients::get($id['client_id']);
            if ( ! $client ) continue;
            $r = $messenger->to_manager(['text' => $this->brief_text($client, $e)]);
            if ( is_wp_error($r) ) { $this->log('brief_error', ['event' => $e['id'], 'error' => $r->get_error_message()], $client['id']); continue; }
            $this->mark_sent($key);
            $this->log('brief_sent', ['event' => $e['id']], $client['id']);
            $sent++;
        }
        return $sent;
    }

    public function brief_text(array $client, array $event){
        global $wpdb;
        $cid = (int) $client['id'];
        $start = strtotime($event['start']['dateTime']);
        $today = current_time('Y-m-d');
        $tasks = $this->open_tasks('t.client_id = %d', [$cid]);
        $groups = ['cliente' => [], 'mad' => [], 'team' => []];
        foreach ( $tasks as $t ) {
            $line = '• ' . $t['title'] . ($t['due_date'] ? ' (' . mysql2date('j M', $t['due_date']) . ($t['due_date'] < $today ? ', ' . __('vencida', 'mad-oficina') : '') . ')' : '');
            $groups[$t['owner'] === 'cliente' ? 'cliente' : ($t['team_member_id'] ? 'team' : 'mad')][] = $line;
        }
        $topics = $wpdb->get_col($wpdb->prepare('SELECT text FROM ' . Schema::table('pending_topics') . " WHERE client_id = %d AND status = 'open' ORDER BY id", $cid));
        $ideas = $wpdb->get_col($wpdb->prepare('SELECT title FROM ' . Schema::table('ideas') . " WHERE client_id = %d AND (status = 'surfaced' OR (status = 'dormant' AND resurface_at <= %s)) ORDER BY resurface_at", $cid, wp_date('Y-m-d', $start + 7 * DAY_IN_SECONDS)));
        $last = $wpdb->get_row($wpdb->prepare('SELECT title, starts_at FROM ' . Schema::table('meetings') . " WHERE client_id = %d AND status IN ('approved','archived') ORDER BY starts_at DESC LIMIT 1", $cid), ARRAY_A);

        $out = '*' . sprintf(__('Ficha para tu reunión con %1$s · %2$s', 'mad-oficina'), $client['name'], wp_date('l j \d\e F, H:i', $start)) . "*\n";
        if ( $last ) $out .= sprintf(__('Última reunión: %1$s (%2$s)', 'mad-oficina'), $last['title'], mysql2date('j M', $last['starts_at'])) . "\n";
        $section = function($title, array $lines) use (&$out){
            if ( $lines ) $out .= "\n*" . $title . "*\n" . implode("\n", array_slice($lines, 0, 12)) . "\n";
        };
        $section(__('Le toca al cliente', 'mad-oficina'), $groups['cliente']);
        $section(__('Te toca a ti', 'mad-oficina'), $groups['mad']);
        $section(__('Le toca al equipo', 'mad-oficina'), $groups['team']);
        $section(__('Temas pendientes de confirmar', 'mad-oficina'), array_map(fn($t) => '• ' . $t, $topics));
        $section(__('Ideas que toca retomar', 'mad-oficina'), array_map(fn($t) => '• ' . $t, $ideas));
        if ( ! $tasks && ! $topics && ! $ideas ) $out .= "\n" . __('No hay nada abierto con este cliente.', 'mad-oficina') . "\n";
        $out .= "\n" . admin_url('admin.php?page=mad-oficina-clientes&client=' . $cid);
        return $out;
    }

    /* ───────── Monday summary ───────── */

    public function weekly_summary($today){
        global $wpdb;
        $messenger = $this->messenger();
        if ( ! $messenger ) return 0;
        $end = gmdate('Y-m-d', strtotime($today . ' +6 days'));
        $out = '*' . __('Tu semana en MAD Oficina', 'mad-oficina') . "*\n";

        if ( class_exists('MAD_Oficina\\Scheduling\\Calendar') && \MAD_Oficina\Scheduling\Calendar::is_ready() ) {
            $from = (new \DateTimeImmutable($today, wp_timezone()))->getTimestamp();
            $events = \MAD_Oficina\Scheduling\Calendar::events($from, $from + 7 * DAY_IN_SECONDS);
            if ( ! is_wp_error($events) ) {
                $lines = [];
                foreach ( $events as $e ) {
                    if ( empty($e['start']['dateTime']) || empty($e['attendees']) ) continue; // only meetings with guests
                    $lines[] = '• ' . wp_date('D j, H:i', strtotime($e['start']['dateTime'])) . ' · ' . ($e['summary'] ?? '');
                }
                $out .= "\n*" . __('Reuniones', 'mad-oficina') . "*\n" . ($lines ? implode("\n", array_slice($lines, 0, 15)) : __('Ninguna con invitados.', 'mad-oficina')) . "\n";
            }
        }

        $due = $this->open_tasks('t.due_date IS NOT NULL AND t.due_date <= %s', [$end]);
        $groups = [];
        foreach ( $due as $t ) {
            $who = $t['owner'] === 'cliente' ? __('Clientes', 'mad-oficina') : ($t['team_member_id'] ? (Team::get((int) $t['team_member_id'])['name'] ?? __('Equipo', 'mad-oficina')) : __('Tú', 'mad-oficina'));
            $groups[$who][] = self::line($t, $today);
        }
        $out .= "\n*" . __('Entregas de la semana (y vencidas)', 'mad-oficina') . "*\n";
        if ( ! $groups ) $out .= __('Nada con fecha esta semana.', 'mad-oficina') . "\n";
        foreach ( $groups as $who => $lines ) $out .= '_' . $who . "_\n" . implode("\n", array_slice($lines, 0, 10)) . "\n";

        $ideas = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('ideas') . " WHERE status = 'dormant' AND resurface_at BETWEEN %s AND %s", $today, $end));
        if ( $ideas ) $out .= "\n" . sprintf(_n('Esta semana vuelve %d idea.', 'Esta semana vuelven %d ideas.', $ideas, 'mad-oficina'), $ideas) . "\n";
        $pending = Approvals::count_pending();
        if ( $pending ) $out .= "\n" . sprintf(_n('Tienes %d cosa en la bandeja.', 'Tienes %d cosas en la bandeja.', $pending, 'mad-oficina'), $pending) . ' ' . admin_url('admin.php?page=mad-oficina-bandeja') . "\n";
        $out .= "\n_" . __('Escríbeme «tareas» para ver las tuyas numeradas y marcarlas.', 'mad-oficina') . '_';

        $r = $messenger->to_manager(['text' => $out]);
        if ( is_wp_error($r) ) { $this->log('weekly_error', ['error' => $r->get_error_message()]); return 0; }
        return 1;
    }
}
