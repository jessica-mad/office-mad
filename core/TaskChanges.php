<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Changes to tasks that already exist, decided from a new contact with the client
 * (an approved meeting or WhatsApp, Valle's review, something Jessica tells Nora):
 * done, cancel (duplicates, no longer needed), change (title, due date) or repeat (it
 * came up again still undone: its priority goes up a level), each with a note in the
 * task of why. Only open tasks of that client; every batch can be undone.
 */
final class TaskChanges {

    const ACTIONS = ['done', 'cancel', 'change', 'repeat'];
    const PRIORITY_UP = ['low' => 'normal', 'normal' => 'high', 'high' => 'high'];

    /** Keeps how the tasks were, for «Deshacer» (same format the Chat undo restores). @return string token */
    public static function snapshot(array $rows){
        $token = wp_generate_password(16, false);
        $data = [];
        foreach ( $rows as $t ) $data[(int) $t['id']] = ['status' => $t['status'], 'due_date' => $t['due_date'], 'completed_at' => $t['completed_at'] ?? null, 'title' => $t['title'], 'description' => $t['description'], 'priority' => $t['priority'] ?? 'normal',
            'owner' => $t['owner'] ?? null, 'team_member_id' => $t['team_member_id'] ?? null, 'visibility' => $t['visibility'] ?? null];
        set_transient('madof_task_undo_' . $token, $data, 14 * DAY_IN_SECONDS);
        return $token;
    }

    /**
     * @param array[] $changes [['task_id', 'action' => done|cancel|change, 'title' => '', 'due_date' => null|'Y-m-d', 'note' => '', 'evidence' => '']]
     * @param string  $source  «reunión «X» del 17/10», «Valle (revisión)»… written in the note
     * @return array{applied:array[], skipped:array[], undo:?string}
     */
    public static function apply(array $changes, $client_id, $source){
        global $wpdb;
        $table = Schema::table('action_items');
        $ids = array_values(array_unique(array_filter(array_map(fn($c) => (int) ($c['task_id'] ?? 0), $changes))));
        $rows = [];
        if ( $ids ) foreach ( $wpdb->get_results('SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $r ) $rows[(int) $r['id']] = $r;
        $applied = []; $skipped = []; $touched = [];
        foreach ( $changes as $c ) {
            $id = (int) ($c['task_id'] ?? 0);
            $action = $c['action'] ?? '';
            $t = $rows[$id] ?? null;
            if ( ! $t || (int) $t['client_id'] !== (int) $client_id || ! in_array($t['status'], ['open', 'in_progress'], true) || ! in_array($action, self::ACTIONS, true) ) {
                $skipped[] = $c + ['why' => ! $t ? 'no existe' : ((int) $t['client_id'] !== (int) $client_id ? 'es de otro cliente' : 'ya no está abierta')];
                continue;
            }
            if ( ! isset($touched[$id]) ) $touched[$id] = $t;
            $note = trim(sanitize_text_field((string) ($c['note'] ?? '')));
            $line = '[' . current_time('d/m') . ' · ' . $source . '] ' . ($note ?: ['done' => 'Hecha.', 'cancel' => 'Anulada.', 'change' => 'Actualizada.', 'repeat' => 'Sale otra vez sin hacer.'][$action]);
            $fields = ['updated_at' => Time::now(), 'description' => trim((string) $t['description'] . "\n" . $line)];
            if ( $action === 'done' ) { $fields['status'] = 'done'; $fields['completed_at'] = Time::now(); }
            if ( $action === 'cancel' ) $fields['status'] = 'cancelled';
            if ( $action === 'repeat' ) $fields['priority'] = self::PRIORITY_UP[$t['priority']] ?? 'high';
            if ( $action === 'change' ) {
                $title = mb_substr(trim(sanitize_text_field((string) ($c['title'] ?? ''))), 0, 250);
                if ( $title !== '' ) $fields['title'] = $title;
                $due = (string) ($c['due_date'] ?? '');
                if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) && strtotime($due) ) $fields['due_date'] = $due;
            }
            $wpdb->update($table, $fields, ['id' => $id]);
            $rows[$id] = array_merge($t, $fields);
            $applied[] = ['task_id' => $id, 'action' => $action, 'before' => $t['title'], 'title' => $fields['title'] ?? $t['title'], 'due_date' => $fields['due_date'] ?? null, 'note' => $note, 'client_visible' => $t['visibility'] === 'client', 'priority' => $fields['priority'] ?? $t['priority']];
        }
        $undo = $touched ? self::snapshot(array_values($touched)) : null;
        if ( $applied ) {
            $wpdb->insert(Schema::table('agent_log'), ['agent' => 'coordinator', 'event' => 'tasks_updated', 'client_id' => $client_id,
                'data' => wp_json_encode(['source' => $source, 'applied' => $applied]), 'created_at' => Time::now()]);
        }
        return ['applied' => $applied, 'skipped' => $skipped, 'undo' => $undo];
    }

    /** One line per change, for Chat. */
    public static function describe(array $a){
        switch ( $a['action'] ) {
            case 'done': return sprintf(__('Hecha: %s', 'mad-oficina'), $a['title']);
            case 'cancel': return sprintf(__('Anulada: %s', 'mad-oficina'), $a['title']);
            case 'repeat': return sprintf(__('Sale otra vez sin hacer: %1$s (prioridad %2$s)', 'mad-oficina'), $a['title'], ['low' => __('baja', 'mad-oficina'), 'normal' => __('normal', 'mad-oficina'), 'high' => __('alta', 'mad-oficina')][$a['priority']] ?? $a['priority']);
            default:
                $bits = [];
                if ( $a['title'] !== $a['before'] ) $bits[] = '«' . $a['before'] . '» → «' . $a['title'] . '»';
                if ( $a['due_date'] ) $bits[] = sprintf(__('nueva fecha %s', 'mad-oficina'), mysql2date('j/m', $a['due_date']));
                return sprintf(__('Actualizada: %s', 'mad-oficina'), $a['title']) . ($bits ? ' (' . implode(', ', $bits) . ')' : '') . ($a['note'] ? ' — ' . $a['note'] : '');
        }
    }
}
