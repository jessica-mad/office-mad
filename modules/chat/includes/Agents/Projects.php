<?php
namespace MAD_Oficina\Chat\Agents;

use MAD_Oficina\Chat\TaskList;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Proyectos (the Coordinadora): tasks, ideas and each client's timeline. Can mark tasks
 * done, reopen them, move dates and create internal tasks — all with «Deshacer».
 * A team member only ever sees and touches their own tasks.
 */
final class Projects extends Specialist {

    public function slug(){ return 'proyectos'; }
    public function character(){ return 'coordinadora'; }
    public function area(){ return 'Proyectos'; }
    public function scope(){ return 'tareas (de Jessica, del equipo y de los clientes), ideas a futuro, línea de tiempo por cliente; puede marcar tareas hechas, reabrirlas, cambiar fechas y crear tareas internas'; }
    public static function allowed(array $ctx){ return ! empty($ctx['is_manager']) || ! empty($ctx['member']); }

    protected function tool_label($tool){
        return ['list_tasks' => 'está repasando las tareas', 'update_tasks' => 'está actualizando las tareas', 'create_task' => 'está apuntando la tarea', 'list_ideas' => 'está mirando el banco de ideas'][$tool] ?? '';
    }

    protected function instructions(){
        return "- Cambia tareas (hecha, reabrir, fecha, crear) solo si la pregunta lo pide claramente; si hay duda entre varias, NO cambies nada y da las opciones con su id.\n"
            . "- Tras cambiar algo, di exactamente qué cambiaste (id, título, nuevo estado o fecha).\n"
            . ($this->ctx['member'] ? "- Solo trabajas con las tareas de {$this->ctx['member']['name']}.\n" : '');
    }

    private function member_id(){ return $this->ctx['member'] ? (int) $this->ctx['member']['id'] : 0; }

    protected function tools(){
        $owners = ['jessica', 'mad', 'liz', 'equipo', 'cliente'];
        return [
            ['name' => 'list_tasks', 'description' => 'Busca tareas. Devuelve id, cliente, título, responsable, fecha límite, estado y si el cliente la ve.',
             'input_schema' => self::obj([
                'client' => ['type' => 'string', 'description' => 'Código o nombre del cliente'],
                'owner' => ['type' => 'string', 'enum' => $owners],
                'status' => ['type' => 'string', 'enum' => ['open', 'done', 'all'], 'description' => 'open por defecto'],
                'due_before' => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
                'query' => ['type' => 'string', 'description' => 'Palabras del título o la descripción'],
                'limit' => ['type' => 'integer'],
             ]), 'handler' => [$this, 'list_tasks']],
            ['name' => 'update_tasks', 'description' => 'Marca tareas como hechas, las reabre o cambia su fecha límite. Usa ids de list_tasks.',
             'input_schema' => self::obj([
                'done' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'reopen' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'due' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'date' => ['type' => 'string', 'description' => 'AAAA-MM-DD']], 'required' => ['id', 'date']]],
             ]), 'handler' => [$this, 'update_tasks']],
            ['name' => 'create_task', 'description' => 'Crea una tarea interna (el cliente no la ve).',
             'input_schema' => self::obj([
                'title' => ['type' => 'string'], 'description' => ['type' => 'string'],
                'client' => ['type' => 'string', 'description' => 'Código o nombre del cliente (opcional)'],
                'owner' => ['type' => 'string', 'enum' => ['jessica', 'mad', 'liz', 'cliente']],
                'due_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
             ], ['title']), 'handler' => [$this, 'create_task']],
            ['name' => 'list_ideas', 'description' => 'Ideas a futuro: título, cliente, cuándo vuelven, estado.',
             'input_schema' => self::obj(['client' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['dormant', 'surfaced', 'converted', 'discarded', 'all']]]),
             'handler' => [$this, 'list_ideas']],
        ];
    }

    public function list_tasks(array $in){
        global $wpdb;
        $where = ['1=1']; $args = [];
        $status = $in['status'] ?? 'open';
        if ( $status === 'open' ) $where[] = "t.status IN ('open','in_progress')";
        elseif ( $status === 'done' ) $where[] = "t.status = 'done'";
        else $where[] = "t.status <> 'cancelled'";
        if ( $this->member_id() ) { $where[] = 't.team_member_id = %d'; $args[] = $this->member_id(); }
        if ( ! empty($in['client']) ) {
            $ids = self::clients_matching($in['client']);
            if ( ! $ids ) return ['tasks' => [], 'note' => 'No hay ningún cliente que coincida con «' . $in['client'] . '».'];
            $where[] = 't.client_id IN (' . implode(',', $ids) . ')';
        }
        $owner = $in['owner'] ?? '';
        if ( $owner === 'cliente' ) $where[] = "t.owner = 'cliente'";
        elseif ( $owner === 'jessica' || $owner === 'mad' ) $where[] = "t.owner IN ('jessica','mad') AND t.team_member_id IS NULL";
        elseif ( $owner === 'liz' || $owner === 'equipo' ) $where[] = 't.team_member_id IS NOT NULL';
        if ( $d = self::date_arg($in['due_before'] ?? '') ) { $where[] = 't.due_date <= %s'; $args[] = $d; }
        if ( ! empty($in['query']) ) { $like = '%' . $wpdb->esc_like((string) $in['query']) . '%'; $where[] = '(t.title LIKE %s OR t.description LIKE %s)'; $args[] = $like; $args[] = $like; }
        $sql = 'SELECT t.*, c.code AS client_code, c.name AS client_name FROM ' . Schema::table('action_items') . ' t LEFT JOIN ' . Schema::table('clients') . ' c ON c.id = t.client_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY (t.due_date IS NULL), t.due_date, t.id LIMIT ' . self::limit($in['limit'] ?? 0, 25, 50);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        $team = [];
        foreach ( Team::all() as $m ) $team[(int) $m['id']] = $m['name'];
        return ['today' => current_time('Y-m-d'), 'tasks' => array_map(fn($t) => [
            'id' => (int) $t['id'], 'client' => $t['client_code'] ? $t['client_code'] . ' · ' . $t['client_name'] : null, 'title' => $t['title'],
            'description' => $t['description'] ? mb_substr($t['description'], 0, 300) : null,
            'owner' => $t['team_member_id'] ? ($team[(int) $t['team_member_id']] ?? 'equipo') : $t['owner'],
            'due' => $t['due_date'], 'status' => $t['status'], 'client_sees_it' => $t['visibility'] === 'client', 'priority' => $t['priority'],
        ], $rows)];
    }

    public function update_tasks(array $in){
        $email = $this->ctx['email']; $manager = $this->ctx['manager_email'];
        $done = array_map('intval', (array) ($in['done'] ?? []));
        $reopen = array_map('intval', (array) ($in['reopen'] ?? []));
        $due = [];
        foreach ( (array) ($in['due'] ?? []) as $d ) if ( ($date = self::date_arg($d['date'] ?? '')) && abs(strtotime($date) - time()) < 2 * YEAR_IN_SECONDS ) $due[(int) ($d['id'] ?? 0)] = $date;
        $rows = TaskList::rows(array_merge($done, $reopen, array_keys($due)));
        if ( ! $rows ) return new \WP_Error('madof_tasks', 'Ninguna de esas tareas existe.');
        $token = TaskList::snapshot(array_values($rows));
        $pick = fn($ids) => array_values(array_intersect_key($rows, array_flip($ids)));
        $a = TaskList::mark($pick($done), $email, $manager);
        $b = TaskList::reopen($pick($reopen), [], $email, $manager);
        $c = TaskList::set_due(array_map(fn($id) => ['task' => $rows[$id], 'date' => $due[$id]], array_keys(array_intersect_key($due, $rows))), $email, $manager);
        $changed = count($a['changed']) + count($b['changed']) + count($c['changed']);
        if ( $changed ) $this->undo[] = $token;
        $t = fn($r) => ['id' => (int) $r['id'], 'title' => $r['title']];
        return [
            'marked_done' => array_map($t, $a['changed']), 'already_done' => array_map($t, $a['already']),
            'reopened' => array_map($t, $b['changed']), 'new_dates' => array_map(fn($x) => $t($x['task']) + ['due' => $x['date']], $c['changed']),
            'not_allowed' => array_map($t, array_merge($a['denied'], $b['denied'], $c['denied'])),
            'missing_ids' => array_values(array_diff(array_merge($done, $reopen, array_keys($due)), array_keys($rows))),
        ];
    }

    public function create_task(array $in){
        global $wpdb;
        $title = mb_substr(sanitize_text_field((string) ($in['title'] ?? '')), 0, 250);
        if ( $title === '' ) return new \WP_Error('madof_task', 'Falta el título.');
        $client = null;
        if ( ! empty($in['client']) ) {
            $ids = self::clients_matching($in['client']);
            if ( count($ids) !== 1 ) return new \WP_Error('madof_task_client', $ids ? 'Hay varios clientes que coinciden; pregunta cuál.' : 'No hay ningún cliente que coincida.');
            $client = $ids[0];
        }
        $owner = in_array($in['owner'] ?? '', ['jessica', 'mad', 'liz', 'cliente'], true) ? $in['owner'] : 'jessica';
        $member = null;
        if ( $this->member_id() ) { $owner = 'liz'; $member = $this->member_id(); }
        elseif ( $owner === 'liz' ) { foreach ( Team::all(true) as $m ) if ( $m['slug'] === 'liz' || ! $member ) $member = (int) $m['id']; }
        $wpdb->insert(Schema::table('action_items'), [
            'client_id' => $client, 'title' => $title, 'description' => sanitize_textarea_field((string) ($in['description'] ?? '')) ?: null,
            'owner' => $owner === 'liz' ? 'liz' : $owner, 'team_member_id' => $member, 'due_date' => self::date_arg($in['due_date'] ?? ''),
            'status' => 'open', 'visibility' => 'internal', 'source' => 'assistant', 'created_at' => Time::now(), 'updated_at' => Time::now(),
        ]);
        $id = (int) $wpdb->insert_id;
        $this->undo[] = TaskList::snapshot([], [$id]);
        return ['created' => ['id' => $id, 'title' => $title, 'client' => self::client_label($client), 'owner' => $owner, 'due' => self::date_arg($in['due_date'] ?? ''), 'client_sees_it' => false]];
    }

    public function list_ideas(array $in){
        global $wpdb;
        if ( $this->member_id() ) return new \WP_Error('madof_ideas', 'Las ideas son de Jessica.');
        $where = ['1=1']; $args = [];
        $st = $in['status'] ?? 'all';
        if ( $st !== 'all' ) { $where[] = 'i.status = %s'; $args[] = $st; }
        if ( ! empty($in['client']) ) { $ids = self::clients_matching($in['client']); if ( ! $ids ) return ['ideas' => []]; $where[] = 'i.client_id IN (' . implode(',', $ids) . ')'; }
        $sql = 'SELECT i.*, c.code FROM ' . Schema::table('ideas') . ' i LEFT JOIN ' . Schema::table('clients') . ' c ON c.id = i.client_id WHERE ' . implode(' AND ', $where) . ' ORDER BY i.resurface_at LIMIT 30';
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        return ['ideas' => array_map(fn($i) => ['id' => (int) $i['id'], 'client' => $i['code'], 'title' => $i['title'], 'description' => $i['description'] ? mb_substr($i['description'], 0, 300) : null, 'comes_back' => $i['resurface_at'], 'status' => $i['status'], 'since' => substr($i['created_at'], 0, 10)], $rows)];
    }
}
