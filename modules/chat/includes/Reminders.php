<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * «Recuérdame … a las 17:00»: an alarm for the person who asks (Jessica or someone of the
 * team), never for clients. It may hang from one of their open tasks, found by its sense
 * («cambiar los precios de los profesionales de Olofane»), not by its number. At its time
 * a card arrives in their Chat DM: Hecho (closes the task) · +1 h · Mañana · Convertir en tarea.
 */
final class Reminders {

    const HOOK = 'madoficina_chat_reminders';
    const SNOOZE = ['hour' => HOUR_IN_SECONDS, 'tomorrow' => DAY_IN_SECONDS];

    public static function table(){ return Schema::table('reminders'); }

    /** @param string $when local «AAAA-MM-DD HH:MM» @return array|\WP_Error the reminder */
    public static function add($email, ?array $member, $text, $when, $task_id = 0){
        global $wpdb;
        $text = mb_substr(trim(sanitize_text_field((string) $text)), 0, 250);
        if ( $text === '' ) return new \WP_Error('madof_reminder', 'Falta qué recordar.');
        $at = self::to_utc($when);
        if ( ! $at ) return new \WP_Error('madof_reminder', 'No entiendo la hora: usa AAAA-MM-DD HH:MM.');
        if ( strtotime($at . ' UTC') < time() - 60 ) return new \WP_Error('madof_reminder', 'Esa hora ya ha pasado: pregúntale si es otro día.');
        $task = $task_id ? self::task($task_id, $member) : null;
        if ( $task_id && ! $task ) return new \WP_Error('madof_reminder', 'Esa tarea no existe o no es suya.');
        $wpdb->insert(self::table(), [
            'email' => strtolower((string) $email), 'team_member_id' => $member ? (int) $member['id'] : null, 'text' => $text,
            'task_id' => $task ? (int) $task['id'] : null, 'remind_at' => $at, 'status' => 'pending', 'created_at' => Time::now(), 'updated_at' => Time::now(),
        ]);
        return self::get((int) $wpdb->insert_id);
    }

    /** Hour until which the night still belongs to the day before («mañana» = the morning about to come). */
    const NIGHT_UNTIL = 5;

    /**
     * Between midnight and 5:00, a reminder set for tomorrow is most likely meant for this
     * coming morning. @return string|null the time («09:00») if that is the case
     */
    public static function night_tomorrow($when){
        if ( (int) wp_date('G') >= self::NIGHT_UNTIL ) return null;
        if ( ! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2})$/', trim((string) $when), $m) ) return null;
        return $m[1] === wp_date('Y-m-d', time() + DAY_IN_SECONDS) ? $m[2] : null;
    }

    public static function get($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id), ARRAY_A) ?: null;
    }

    /** What is waiting for this person, soonest first. */
    public static function pending($email){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE email = %s AND status = 'pending' ORDER BY remind_at LIMIT 30", strtolower((string) $email)), ARRAY_A);
    }

    public static function cancel($email, $id){
        global $wpdb;
        return (bool) $wpdb->update(self::table(), ['status' => 'cancelled', 'updated_at' => Time::now()], ['id' => (int) $id, 'email' => strtolower((string) $email), 'status' => 'pending']);
    }

    /** For her (Nora's tool output): local time, the task it hangs from. */
    public static function describe(array $r){
        $t = $r['task_id'] ? self::task((int) $r['task_id'], null) : null;
        return ['id' => (int) $r['id'], 'when' => get_date_from_gmt($r['remind_at'], 'Y-m-d H:i'), 'text' => $r['text'],
            'task' => $t ? ['id' => (int) $t['id'], 'title' => $t['title'], 'client' => $t['code'] ?: null, 'status' => $t['status']] : null];
    }

    /* ───────── the task, by its sense ───────── */

    /** An open task of this person (Liz: hers; Jessica: any), with its client's code and name. */
    private static function task($id, ?array $member){
        global $wpdb;
        $t = $wpdb->get_row($wpdb->prepare('SELECT a.*, c.code, c.name AS client_name FROM ' . Schema::table('action_items') . ' a LEFT JOIN ' . Schema::table('clients') . ' c ON c.id = a.client_id WHERE a.id = %d', (int) $id), ARRAY_A);
        if ( ! $t ) return null;
        if ( $member && (int) $t['team_member_id'] !== (int) $member['id'] ) return null;
        return $t;
    }

    private static function words($s){
        $s = strtolower(remove_accents((string) $s));
        $stop = ['tengo', 'tiene', 'tarea', 'hacer', 'recuerdame', 'recordar', 'cambiar', 'para', 'sobre', 'esta', 'este', 'esos', 'esas', 'estos', 'estas', 'como', 'cuando', 'donde', 'luego', 'todos', 'todas', 'tambien', 'manana'];
        $out = [];
        foreach ( preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $w ) if ( strlen($w) >= 4 && ! in_array($w, $stop, true) ) $out[] = rtrim($w, 's');
        return array_values(array_unique($out));
    }

    /**
     * Open tasks that match what she said, best first. The client (code or a word of its
     * name) counts double: «los precios de Olofane» is about Olofane's tasks.
     * @return array[] id, title, client, due, score
     */
    public static function find_task($hint, ?array $member, $limit = 3){
        global $wpdb;
        $words = self::words($hint);
        if ( ! $words ) return [];
        $where = "a.status IN ('open','in_progress')" . ($member ? $wpdb->prepare(' AND a.team_member_id = %d', (int) $member['id']) : " AND a.owner <> 'cliente'");
        $rows = $wpdb->get_results('SELECT a.id, a.title, a.description, a.due_date, c.code, c.name AS client_name FROM ' . Schema::table('action_items') . ' a LEFT JOIN ' . Schema::table('clients') . " c ON c.id = a.client_id WHERE {$where} ORDER BY a.id DESC LIMIT 400", ARRAY_A);
        $codes = array_map('strtolower', preg_split('/[^A-Za-z0-9]+/', (string) $hint, -1, PREG_SPLIT_NO_EMPTY));
        $scored = [];
        foreach ( $rows as $t ) {
            $client = self::words($t['client_name']);
            $text = implode(' ', self::words($t['title'] . ' ' . $t['description']));
            $score = 0;
            if ( $t['code'] && in_array(strtolower($t['code']), $codes, true) ) $score += 2;
            foreach ( $words as $w ) {
                if ( in_array($w, $client, true) ) $score += 2;
                elseif ( preg_match('/\b' . preg_quote($w, '/') . '/', $text) ) $score += 1;
            }
            if ( $score >= 2 ) $scored[] = ['id' => (int) $t['id'], 'title' => $t['title'], 'client' => $t['code'] ?: null, 'due' => $t['due_date'], 'score' => $score];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['id'] <=> $a['id']);
        return array_slice($scored, 0, $limit);
    }

    /* ───────── at its time ───────── */

    /** Sends what is due (each reminder once, even if two runs overlap). @return int sent */
    public static function send_due(Messenger $messenger, $portal_url = ''){
        global $wpdb;
        $n = 0;
        foreach ( $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE status = 'pending' AND remind_at <= %s ORDER BY remind_at LIMIT 20", Time::now()), ARRAY_A) as $r ) {
            if ( ! $wpdb->update(self::table(), ['status' => 'sending', 'updated_at' => Time::now()], ['id' => (int) $r['id'], 'status' => 'pending']) ) continue;
            $member = $r['team_member_id'] ? Team::get((int) $r['team_member_id']) : null;
            $card = self::card($r);
            $res = $member ? $messenger->to_team_member($member, $card) : $messenger->to_manager($card);
            // Not delivered (no DM yet, Google down): tried again on the next run, for a day.
            $failed = is_wp_error($res) && strtotime($r['remind_at'] . ' UTC') > time() - DAY_IN_SECONDS;
            $wpdb->update(self::table(), ['status' => $failed ? 'pending' : 'sent', 'sent_at' => $failed ? null : Time::now(), 'updated_at' => Time::now()], ['id' => (int) $r['id']]);
            if ( ! $failed ) $n++;
        }
        return $n;
    }

    public static function card(array $r, $note = ''){
        $t = $r['task_id'] ? self::task((int) $r['task_id'], null) : null;
        $lines = [];
        if ( $t ) {
            $lines[] = '<b>' . esc_html(sprintf(__('Tarea #%d', 'mad-oficina'), $t['id'])) . '</b> ' . esc_html(($t['code'] ? $t['code'] . ' · ' : '') . $t['title']);
            if ( $t['due_date'] ) $lines[] = esc_html(sprintf(__('Fecha límite: %s', 'mad-oficina'), mysql2date('j M', $t['due_date'])));
            if ( in_array($t['status'], ['done', 'cancelled'], true) ) $lines[] = esc_html__('(Ya está cerrada.)', 'mad-oficina');
        }
        if ( $note !== '' ) $lines[] = '<i>' . esc_html($note) . '</i>';
        $buttons = [Cards::button(__('Hecho', 'mad-oficina'), 'reminder_done', ['id' => $r['id']]), Cards::button(__('+1 hora', 'mad-oficina'), 'reminder_snooze', ['id' => $r['id'], 'by' => 'hour']),
            Cards::button(__('Mañana', 'mad-oficina'), 'reminder_snooze', ['id' => $r['id'], 'by' => 'tomorrow'])];
        if ( ! $t ) $buttons[] = Cards::button(__('Convertir en tarea', 'mad-oficina'), 'reminder_task', ['id' => $r['id']]);
        return Cards::card('reminder-' . $r['id'] . '-' . substr(md5($note . $r['status']), 0, 4), __('Recordatorio', 'mad-oficina'), $r['text'], array_filter([
            $lines ? ['textParagraph' => ['text' => implode('<br>', $lines)]] : null,
            ['buttonList' => ['buttons' => $buttons]],
        ]));
    }

    /** The card's buttons. Only the person it was for. @return array|string|null */
    public static function chat_action($out, $fn, array $params, $email, $is_manager){
        if ( ! in_array($fn, ['reminder_done', 'reminder_snooze', 'reminder_task'], true) ) return $out;
        global $wpdb;
        $r = self::get((int) ($params['id'] ?? 0));
        if ( ! $r || $r['email'] !== strtolower((string) $email) ) return __('Ese recordatorio no es tuyo.', 'mad-oficina');
        $now = Time::now();
        if ( $fn === 'reminder_snooze' ) {
            $at = gmdate('Y-m-d H:i:s', time() + (self::SNOOZE[$params['by'] ?? ''] ?? HOUR_IN_SECONDS));
            $wpdb->update(self::table(), ['status' => 'pending', 'remind_at' => $at, 'sent_at' => null, 'updated_at' => $now], ['id' => (int) $r['id']]);
            return self::card(self::get($r['id']), sprintf(__('Te lo vuelvo a recordar %s.', 'mad-oficina'), get_date_from_gmt($at, ($params['by'] ?? '') === 'tomorrow' ? '\m\a\ñ\a\n\a \a \l\a\s H:i' : '\a \l\a\s H:i')));
        }
        if ( $fn === 'reminder_task' ) {
            $member = $r['team_member_id'] ? Team::get((int) $r['team_member_id']) : null;
            $wpdb->insert(Schema::table('action_items'), ['title' => $r['text'], 'owner' => $member ? 'liz' : 'jessica', 'team_member_id' => $member ? (int) $member['id'] : null,
                'status' => 'open', 'visibility' => $member ? 'team' : 'internal', 'source' => 'chat', 'created_at' => $now, 'updated_at' => $now]);
            $wpdb->update(self::table(), ['task_id' => (int) $wpdb->insert_id, 'status' => 'done', 'updated_at' => $now], ['id' => (int) $r['id']]);
            return self::card(self::get($r['id']), __('Ya es una tarea (sin cliente ni fecha: cámbialo en Tareas si hace falta).', 'mad-oficina'));
        }
        $wpdb->update(self::table(), ['status' => 'done', 'updated_at' => $now], ['id' => (int) $r['id']]);
        $note = __('Hecho.', 'mad-oficina');
        $t = $r['task_id'] ? self::task((int) $r['task_id'], null) : null;
        if ( $t && in_array($t['status'], ['open', 'in_progress'], true) ) {
            $rows = TaskList::rows([(int) $t['id']]);
            $m = TaskList::mark(array_values($rows), $email, $is_manager ? $email : '');
            $note = $m['denied'] ? __('Hecho (la tarea no es tuya: sigue abierta).', 'mad-oficina') : sprintf(__('Hecho: la tarea #%d queda cerrada.', 'mad-oficina'), $t['id']);
        }
        return self::card(self::get($r['id']), $note);
    }

    private static function to_utc($local){
        $local = trim((string) $local);
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}$/', $local) ) return null;
        try {
            return (new \DateTimeImmutable(str_replace('T', ' ', $local), wp_timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch ( \Exception $e ) { return null; }
    }
}
