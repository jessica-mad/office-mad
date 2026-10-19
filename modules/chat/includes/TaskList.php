<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Numbered task lists in Chat, and marking them done from there: with the «Hecha»
 * button on each line, or by answering in words («la 2 y la 5 hechas», «todas hechas»,
 * «deshacer la 3»). Words are parsed here, without AI: a chat message is data and can
 * only ever touch the tasks of the last list that same person was sent, and only
 * the ones they are allowed to close (Jessica: any; a team member: their own).
 */
final class TaskList {

    const OPTION = 'madoficina_chat_lists';
    const MAX = 25;
    const STATUSES_OPEN = ['open', 'in_progress'];

    /* ───────── the last list each person got ───────── */

    public static function remember($email, array $ids){
        $email = strtolower((string) $email);
        if ( $email === '' ) return;
        $all = get_option(self::OPTION, []);
        $all = is_array($all) ? $all : [];
        $all[$email] = ['ids' => array_values(array_map('intval', $ids)), 'at' => time()];
        update_option(self::OPTION, $all, false);
    }

    /** @return array{ids:int[], at:int}|null */
    public static function last($email){
        $all = get_option(self::OPTION, []);
        $l = is_array($all) ? ($all[strtolower((string) $email)] ?? null) : null;
        return is_array($l) && ! empty($l['ids']) ? $l : null;
    }

    /* ───────── reading what she writes ───────── */

    /**
     * @return array{action:?string, nums:int[]|string} action: done|undo|list|ask (done words
     *         without numbers) or null (not about tasks); nums: numbers or 'all'.
     */
    public static function parse($text){
        $t = strtolower(remove_accents(wp_strip_all_tags((string) $text)));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        $nums = [];
        $rest = preg_replace_callback('/\b(\d{1,3})\s*(?:-|a|al|hasta)\s*(\d{1,3})\b/', function($m) use (&$nums){
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            if ( $a <= $b && $b - $a < self::MAX * 2 ) $nums = array_merge($nums, range($a, $b));
            return ' ';
        }, $t);
        preg_match_all('/\b\d{1,3}\b/', $rest, $m);
        $nums = array_values(array_unique(array_filter(array_merge($nums, array_map('intval', $m[0])))));
        sort($nums);
        $all = (bool) preg_match('/\btod[ao]s\b/', $t);
        $target = $all ? 'all' : $nums;
        $has = $all || $nums;

        $undo = preg_match('/\b(deshacer|deshaz|desmarca\w*|reabre|reabrir|no esta hecha|no estan hechas|no estaba hecha|no estaban hechas)\b/', $t);
        $done = preg_match('/\b(hech[ao]s?|list[ao]s?|terminad[ao]s?|acabad[ao]s?|completad[ao]s?|finalizad[ao]s?|cerrad[ao]s?|marca\w*|done)\b/', $t);
        // Only numbers and connectors («2, 5», «la 2 y la 5»): an answer to the list.
        $bare = $nums && preg_match('/^(?:[\s,.;y&+e]|\d|\b(?:la|las|el|los|n|no|num|numero|numeros)\b)+$/', $t);

        // Only a short reply to the list («la 2 y la 5 hechas», «reabre la 3») acts by numbers. In a long
        // message, a pasted text or a voice note, numbers are dates, amounts, campaign figures… never tasks.
        $short = mb_strlen($t) <= 60 && count(explode(' ', $t)) <= 10;
        if ( $undo && $has && $short ) return ['action' => 'undo', 'nums' => $target];
        if ( $has && ($done || $bare) && $short ) return ['action' => 'done', 'nums' => $target];
        if ( $has && ! $short ) return ['action' => null, 'nums' => []]; // the assistant reads it
        // Only the bare request («tareas», «mis tareas», «¿qué tareas tengo?»); a sentence about tasks goes to the assistant.
        $bare_list = trim(preg_replace('/[^a-z ]+/', ' ', $t));
        if ( preg_match('/^(?:(?:dame|pasame|mandame|ensename|muestrame|ver|mis|las|que|cuales|son|tengo|hola)\s+)*(?:tareas|pendientes|lista)(?:\s+(?:abiertas|pendientes|tengo|de hoy|por favor))*$/', $bare_list) ) return ['action' => 'list', 'nums' => []];
        if ( $done ) return ['action' => 'ask', 'nums' => []];
        return ['action' => null, 'nums' => []];
    }

    /* ───────── tasks ───────── */

    /** @param int[] $ids @return array[] keyed by id, in the given order */
    public static function rows(array $ids){
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ( ! $ids ) return [];
        $in = implode(',', $ids);
        $rows = $wpdb->get_results('SELECT t.*, c.code AS client_code FROM ' . Schema::table('action_items') . ' t LEFT JOIN ' . Schema::table('clients') . " c ON c.id = t.client_id WHERE t.id IN ({$in})", ARRAY_A);
        $by = [];
        foreach ( $rows as $r ) $by[(int) $r['id']] = $r;
        $out = [];
        foreach ( $ids as $id ) if ( isset($by[$id]) ) $out[$id] = $by[$id];
        return $out;
    }

    /** Open tasks of this person, as the «tareas» answer. @return int[] */
    public static function open_ids($member_id = null){
        global $wpdb;
        $where = $member_id ? $wpdb->prepare('team_member_id = %d', $member_id) : "owner IN ('jessica','mad') AND team_member_id IS NULL";
        return array_map('intval', $wpdb->get_col('SELECT id FROM ' . Schema::table('action_items') . " WHERE status IN ('open','in_progress') AND {$where} ORDER BY (due_date IS NULL), due_date, id LIMIT " . self::MAX));
    }

    public static function allowed(array $task, $email, $manager_email){
        $email = strtolower((string) $email);
        if ( $email === '' ) return false;
        if ( $email === strtolower((string) $manager_email) ) return true;
        $member = $task['team_member_id'] ? Team::get((int) $task['team_member_id']) : null;
        return $member && strtolower((string) $member['workspace_email']) === $email;
    }

    /** @return array{changed:array[], already:array[], denied:array[]} */
    public static function mark(array $tasks, $email, $manager_email){
        global $wpdb;
        $out = ['changed' => [], 'already' => [], 'denied' => []];
        foreach ( $tasks as $t ) {
            if ( ! self::allowed($t, $email, $manager_email) ) { $out['denied'][] = $t; continue; }
            if ( ! in_array($t['status'], self::STATUSES_OPEN, true) ) { $out['already'][] = $t; continue; }
            $wpdb->update(Schema::table('action_items'), ['status' => 'done', 'completed_at' => Time::now(), 'updated_at' => Time::now()], ['id' => $t['id']]);
            $out['changed'][] = $t;
        }
        if ( $out['changed'] ) self::log('chat_task_done', $out['changed'], $email);
        return $out;
    }

    /** Duplicates or tasks that no longer apply: cancelled, not deleted (they leave lists and the portal). */
    public static function cancel(array $tasks, $email, $manager_email){
        global $wpdb;
        $out = ['changed' => [], 'already' => [], 'denied' => []];
        foreach ( $tasks as $t ) {
            if ( ! self::allowed($t, $email, $manager_email) ) { $out['denied'][] = $t; continue; }
            if ( $t['status'] === 'cancelled' ) { $out['already'][] = $t; continue; }
            $wpdb->update(Schema::table('action_items'), ['status' => 'cancelled', 'updated_at' => Time::now()], ['id' => $t['id']]);
            $out['changed'][] = $t;
        }
        if ( $out['changed'] ) self::log('chat_task_cancelled', $out['changed'], $email);
        return $out;
    }

    /** Back to how it was (open / in progress). @param array<int,string> $prev id => status */
    public static function reopen(array $tasks, array $prev, $email, $manager_email){
        global $wpdb;
        $out = ['changed' => [], 'already' => [], 'denied' => []];
        foreach ( $tasks as $t ) {
            if ( ! self::allowed($t, $email, $manager_email) ) { $out['denied'][] = $t; continue; }
            if ( $t['status'] !== 'done' ) { $out['already'][] = $t; continue; }
            $status = in_array($prev[(int) $t['id']] ?? '', self::STATUSES_OPEN, true) ? $prev[(int) $t['id']] : 'open';
            $wpdb->update(Schema::table('action_items'), ['status' => $status, 'completed_at' => null, 'updated_at' => Time::now()], ['id' => $t['id']]);
            $out['changed'][] = $t;
        }
        if ( $out['changed'] ) self::log('chat_task_reopened', $out['changed'], $email);
        return $out;
    }

    /** New due dates. @param array[] $changes [['task' => row, 'date' => 'Y-m-d'], …] @return array{changed:array[], denied:array[]} */
    public static function set_due(array $changes, $email, $manager_email){
        global $wpdb;
        $out = ['changed' => [], 'denied' => []];
        foreach ( $changes as $c ) {
            $t = $c['task'];
            if ( ! self::allowed($t, $email, $manager_email) ) { $out['denied'][] = $t; continue; }
            $wpdb->update(Schema::table('action_items'), ['due_date' => $c['date'], 'updated_at' => Time::now()], ['id' => $t['id']]);
            $out['changed'][] = $c;
        }
        if ( $out['changed'] ) self::log('chat_task_due', array_column($out['changed'], 'task'), $email);
        return $out;
    }

    /** Keeps how these tasks were (status, due date) so «Deshacer» can put them back. @return string token */
    public static function snapshot(array $tasks, array $created_ids = []){
        $token = wp_generate_password(16, false);
        $data = [];
        foreach ( $tasks as $t ) $data[(int) $t['id']] = ['status' => $t['status'], 'due_date' => $t['due_date'], 'completed_at' => $t['completed_at'] ?? null];
        foreach ( $created_ids as $id ) $data[(int) $id] = ['status' => 'cancelled', 'due_date' => null, 'completed_at' => null, 'created' => true]; // undo = cancel it
        set_transient('madof_task_undo_' . $token, $data, 7 * DAY_IN_SECONDS);
        return $token;
    }

    /** @return array{changed:array[], denied:array[]}|\WP_Error */
    public static function restore($token, $email, $manager_email){
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        $data = $token !== '' ? get_transient('madof_task_undo_' . $token) : false;
        if ( ! is_array($data) ) return new \WP_Error('madof_undo', __('Ya no puedo deshacerlo (ha pasado mucho tiempo).', 'mad-oficina'));
        $out = ['changed' => [], 'denied' => []];
        foreach ( self::rows(array_keys($data)) as $id => $t ) {
            if ( ! self::allowed($t, $email, $manager_email) ) { $out['denied'][] = $t; continue; }
            $was = $data[$id];
            $fields = [
                'status' => in_array($was['status'], ['open', 'in_progress', 'done', 'cancelled'], true) ? $was['status'] : 'open',
                'due_date' => ! empty($was['created']) ? $t['due_date'] : ($was['due_date'] ?: null),
                'completed_at' => $was['completed_at'] ?: null, 'updated_at' => Time::now(),
            ];
            if ( array_key_exists('title', $was) ) { $fields['title'] = $was['title']; $fields['description'] = $was['description']; } // edits from TaskChanges
            if ( ! empty($was['priority']) ) $fields['priority'] = $was['priority'];
            if ( ! empty($was['owner']) ) { $fields['owner'] = $was['owner']; $fields['team_member_id'] = $was['team_member_id'] ?: null; $fields['visibility'] = $was['visibility'] ?: 'internal'; } // a reassignment
            $wpdb->update(Schema::table('action_items'), $fields, ['id' => $id]);
            $out['changed'][] = $t;
        }
        if ( $out['changed'] ) delete_transient('madof_task_undo_' . $token); // someone else's click leaves it usable
        if ( $out['changed'] ) self::log('chat_task_undo', $out['changed'], $email);
        return $out;
    }

    private static function log($event, array $tasks, $email){
        global $wpdb;
        $wpdb->insert(Schema::table('agent_log'), [
            'agent' => 'coordinator', 'event' => $event, 'client_id' => null,
            'data' => wp_json_encode(['tasks' => array_map(fn($t) => (int) $t['id'], $tasks), 'by' => $email, 'channel' => 'chat']), 'created_at' => Time::now(),
        ]);
    }

    /* ───────── messages ───────── */

    private static function when(array $t, $today){
        $due = $t['due_date'];
        if ( ! $due ) return __('sin fecha', 'mad-oficina');
        return $due < $today ? sprintf(__('vencida el %s', 'mad-oficina'), mysql2date('j M', $due)) : ($due === $today ? __('hoy', 'mad-oficina') : mysql2date('D j M', $due));
    }

    /**
     * The numbered card. Rows keep their number when one is ticked (the card is
     * re-drawn from the same ids), so «la 3» means the same before and after.
     */
    public static function card($title, array $ids, $today = null, $intro = ''){
        $today = $today ?? current_time('Y-m-d');
        $ids = array_slice(array_values(array_map('intval', $ids)), 0, self::MAX);
        $rows = self::rows($ids);
        $csv = implode(',', $ids);
        $widgets = [];
        if ( $intro !== '' ) $widgets[] = ['textParagraph' => ['text' => esc_html($intro)]];
        $n = 0;
        foreach ( $ids as $id ) {
            $n++;
            if ( ! isset($rows[$id]) ) continue;
            $t = $rows[$id];
            $done = $t['status'] === 'done';
            $label = $n . ' · ' . ($t['client_code'] ? $t['client_code'] . ' · ' : '') . ($done ? __('HECHA', 'mad-oficina') : self::when($t, $today));
            $widgets[] = ['decoratedText' => [
                'topLabel' => $label,
                'text'     => $done ? '<s>' . esc_html($t['title']) . '</s>' : esc_html($t['title']),
                'wrapText' => true,
                'button'   => $done
                    ? Cards::button(__('Deshacer', 'mad-oficina'), 'task_list_undo', ['task_id' => $id, 'ids' => $csv, 'title' => $title])
                    : Cards::button(__('Hecha', 'mad-oficina'), 'task_list_done', ['task_id' => $id, 'ids' => $csv, 'title' => $title, 'prev' => $t['status']]),
            ]];
        }
        $widgets[] = ['textParagraph' => ['text' => esc_html__('Pulsa «Hecha» o contéstame con los números: «la 2 y la 5 hechas», «todas hechas», «deshacer la 3».', 'mad-oficina')]];
        return Cards::card('tasks-' . substr(md5($csv), 0, 10), $title, sprintf(_n('%d tarea', '%d tareas', count($ids), 'mad-oficina'), count($ids)), $widgets);
    }

    /** The answer after marking (or reopening) by words, with «Deshacer». */
    public static function result_card(array $r, $undo, array $missing = []){
        $lines = [];
        foreach ( $r['changed'] as $t ) $lines[] = '• ' . esc_html(($t['client_code'] ? $t['client_code'] . ' · ' : '') . $t['title']);
        $notes = [];
        if ( $r['already'] ) $notes[] = esc_html(sprintf($undo ? __('Ya estaban abiertas: %s', 'mad-oficina') : __('Ya estaban hechas: %s', 'mad-oficina'), implode(', ', array_map(fn($t) => $t['title'], $r['already']))));
        if ( $r['denied'] ) $notes[] = esc_html(sprintf(__('No son tuyas, no las toco: %s', 'mad-oficina'), implode(', ', array_map(fn($t) => $t['title'], $r['denied']))));
        if ( $missing ) $notes[] = esc_html(sprintf(_n('No hay número %s en tu última lista.', 'No hay números %s en tu última lista.', count($missing), 'mad-oficina'), implode(', ', $missing)));
        $widgets = [['textParagraph' => ['text' => ($lines ? implode('<br>', $lines) : esc_html__('No he cambiado nada.', 'mad-oficina')) . ($notes ? '<br><br>' . implode('<br>', $notes) : '')]]];
        if ( $r['changed'] && ! $undo ) {
            $prev = implode(',', array_map(fn($t) => (int) $t['id'] . ':' . $t['status'], $r['changed']));
            $widgets[] = ['buttonList' => ['buttons' => [Cards::button(__('Deshacer', 'mad-oficina'), 'task_undo', ['prev' => $prev])]]];
        }
        $title = $undo ? __('Vueltas a abrir', 'mad-oficina') : __('Marcadas como hechas', 'mad-oficina');
        return Cards::card('tasks-result-' . wp_generate_password(6, false), $title, sprintf(_n('%d tarea', '%d tareas', count($r['changed']), 'mad-oficina'), count($r['changed'])), $widgets);
    }

    /** «12:open,15:in_progress» → [12 => 'open', 15 => 'in_progress'] */
    public static function parse_prev($s){
        $out = [];
        foreach ( explode(',', (string) $s) as $pair ) {
            [$id, $st] = array_pad(explode(':', $pair, 2), 2, 'open');
            if ( (int) $id > 0 ) $out[(int) $id] = in_array($st, self::STATUSES_OPEN, true) ? $st : 'open';
        }
        return array_slice($out, 0, self::MAX, true);
    }

    /** «3,8,12» → [3, 8, 12] */
    public static function parse_ids($s){
        return array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $s)))), 0, self::MAX);
    }
}
