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
    public function scope(){ return 'tareas (de Jessica, del equipo y de los clientes) con la reunión de la que salieron y lo que se acordó sobre ellas, ideas a futuro, línea de tiempo por cliente; puede marcar tareas hechas, reabrirlas, anularlas (duplicadas o que ya no aplican), cambiar fechas y crear tareas internas'; }
    public static function allowed(array $ctx){ return ! empty($ctx['is_manager']) || ! empty($ctx['member']); }
    public function routing(){
        return 'Todo lo que es TAREAS (qué hay pendiente, de quién, para cuándo, qué venció), aunque la tarea saliera de una reunión; marcar hechas, reabrir, cambiar fechas, apuntar una tarea nueva; las IDEAS a futuro. '
            . 'Ejemplos: «¿qué tengo esta semana?», «¿qué le toca a Liz?», «ya mandé el logo a LCS», «apúntame llamar a Rosa el lunes», «quita las tareas duplicadas de LCS», «¿qué ideas tenemos con MOM?».';
    }

    protected function tool_label($tool){
        return ['find_duplicates' => 'está buscando tareas repetidas', 'list_tasks' => 'está repasando las tareas', 'update_tasks' => 'está actualizando las tareas', 'create_task' => 'está apuntando la tarea', 'list_ideas' => 'está mirando el banco de ideas'][$tool] ?? '';
    }

    protected function instructions(){
        return "- Cambia tareas (hecha, reabrir, anular, fecha, crear) solo si la pregunta lo pide claramente; si hay duda entre varias, NO cambies nada y da las opciones con su id.\n"
            . "- Tras cambiar algo, di exactamente qué cambiaste (id, título, nuevo estado o fecha).\n"
            . "- Si te cuentan algo nuevo de un cliente (una llamada, un WhatsApp, «ahora quiere X en vez de Y»), busca la tarea relacionada y ACTUALÍZALA con revise (nuevo título y/o fecha y una nota de qué cambió), dala por hecha o anúlala; si se vuelve a hablar de ella y sigue sin hacer, still_pending (sube su prioridad). Crea una nueva solo si no hay ninguna relacionada.\n"
            . "- Para repartir tareas («esa es de Liz») usa assign; para una nueva, create_task con owner. Quien la recibe no se entera hasta que Jessica pulse «Avisar»: dilo.\n"
            . "- Si necesitas saber de dónde sale una tarea o qué se acordó sobre ella, usa meeting_context: tú misma lees las actas y das UNA respuesta completa (no hace falta preguntar a nadie más).\n"
            . "- Duplicadas: usa find_duplicates y revisa cada grupo; si de verdad son la misma tarea, deja la más completa (la que tenga fecha, descripción o la visible para el cliente; si empatan, la más antigua) y anula las demás con cancel. Si dos se parecen pero no son lo mismo, no las toques y dilo.\n"
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
            ['name' => 'find_duplicates', 'description' => 'Grupos de tareas abiertas que parecen la misma (mismo cliente y título igual o casi igual), con id, título, descripción, fecha, responsable, si el cliente la ve y de dónde salió.',
             'input_schema' => self::obj(['client' => ['type' => 'string']]), 'handler' => [$this, 'find_duplicates']],
            ['name' => 'update_tasks', 'description' => 'Marca tareas como hechas, las reabre, las anula (cancel: duplicadas o que ya no aplican; no se borran), cambia su fecha límite, las reescribe (revise) o las marca como que siguen pendientes (still_pending). Usa ids de list_tasks.',
             'input_schema' => self::obj([
                'done' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'cancel' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'revise' => ['type' => 'array', 'description' => 'Cambiar lo que pide la tarea: nuevo título y/o fecha, con una nota de qué cambió y por qué.', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'due_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'note' => ['type' => 'string'],
                ], 'required' => ['id', 'note']]],
                'still_pending' => ['type' => 'array', 'description' => 'Se ha vuelto a hablar de ellas y siguen sin hacer: sube su prioridad.', 'items' => ['type' => 'integer']],
                'assign' => ['type' => 'array', 'description' => 'Cambiar quién la hace: «to» = jessica, mad, cliente o el nombre de alguien del equipo (' . implode(', ', array_column(Team::all(true), 'name')) . ').', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'to' => ['type' => 'string'],
                ], 'required' => ['id', 'to']]],
                'reopen' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'due' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'date' => ['type' => 'string', 'description' => 'AAAA-MM-DD']], 'required' => ['id', 'date']]],
             ]), 'handler' => [$this, 'update_tasks']],
            ['name' => 'create_task', 'description' => 'Crea una tarea interna (el cliente no la ve).',
             'input_schema' => self::obj([
                'title' => ['type' => 'string'], 'description' => ['type' => 'string'],
                'client' => ['type' => 'string', 'description' => 'Código o nombre del cliente (opcional)'],
                'owner' => ['type' => 'string', 'description' => 'jessica, mad, cliente o el nombre de alguien del equipo (' . implode(', ', array_column(Team::all(true), 'name')) . ')'],
                'due_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
             ], ['title']), 'handler' => [$this, 'create_task']],
            ['name' => 'meeting_context', 'description' => 'El contexto de una tarea en las actas: la reunión o WhatsApp del que salió (fecha, resumen, acuerdos, próxima reunión) y la frase que la justifica. Úsalo tú misma en vez de pedírselo a Actas.',
             'input_schema' => self::obj(['task_id' => ['type' => 'integer'], 'client' => ['type' => 'string', 'description' => 'Sin task_id: las últimas reuniones de ese cliente']]),
             'handler' => [$this, 'meeting_context']],
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
        $sql = 'SELECT t.*, c.code AS client_code, c.name AS client_name, m.title AS meeting_title, m.starts_at AS meeting_at FROM ' . Schema::table('action_items') . ' t LEFT JOIN ' . Schema::table('clients') . ' c ON c.id = t.client_id LEFT JOIN ' . Schema::table('meetings') . ' m ON m.id = t.meeting_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY (t.due_date IS NULL), t.due_date, t.id LIMIT ' . self::limit($in['limit'] ?? 0, 25, 50);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        $team = [];
        foreach ( Team::all() as $m ) $team[(int) $m['id']] = $m['name'];
        return ['today' => current_time('Y-m-d'), 'tasks' => array_map(fn($t) => [
            'id' => (int) $t['id'], 'client' => $t['client_code'] ? $t['client_code'] . ' · ' . $t['client_name'] : null, 'title' => $t['title'],
            'description' => $t['description'] ? mb_substr($t['description'], 0, 300) : null,
            'owner' => $t['team_member_id'] ? ($team[(int) $t['team_member_id']] ?? 'equipo') : $t['owner'],
            'due' => $t['due_date'], 'status' => $t['status'], 'client_sees_it' => $t['visibility'] === 'client', 'priority' => $t['priority'],
            'from_meeting' => $t['meeting_title'] ? trim($t['meeting_title'] . ' ' . ($t['meeting_at'] ? get_date_from_gmt($t['meeting_at'], 'Y-m-d') : '')) : null, 'quote' => $t['evidence'] ?: null,
        ], $rows)];
    }

    public function update_tasks(array $in){
        $email = $this->ctx['email']; $manager = $this->ctx['manager_email'];
        $done = array_map('intval', (array) ($in['done'] ?? []));
        $reopen = array_map('intval', (array) ($in['reopen'] ?? []));
        $cancel = array_map('intval', (array) ($in['cancel'] ?? []));
        $due = [];
        foreach ( (array) ($in['due'] ?? []) as $d ) if ( ($date = self::date_arg($d['date'] ?? '')) && abs(strtotime($date) - time()) < 2 * YEAR_IN_SECONDS ) $due[(int) ($d['id'] ?? 0)] = $date;
        $rows = TaskList::rows(array_merge($done, $reopen, $cancel, array_keys($due)));
        // A new date on a task that is closed: an annulled one comes back (she wants it alive);
        // a done one is left alone and she is asked.
        $closed_done = [];
        foreach ( array_keys($due) as $id ) {
            $st = $rows[$id]['status'] ?? '';
            if ( $st === 'cancelled' && ! in_array($id, $reopen, true) ) $reopen[] = $id;
            if ( $st === 'done' && ! in_array($id, $reopen, true) ) { $closed_done[] = ['id' => $id, 'title' => $rows[$id]['title']]; unset($due[$id]); }
        }
        if ( ! $rows && empty($in['revise']) && empty($in['still_pending']) && empty($in['assign']) ) return new \WP_Error('madof_tasks', 'Ninguna de esas tareas existe.');
        $token = TaskList::snapshot(array_values($rows));
        $pick = fn($ids) => array_values(array_intersect_key($rows, array_flip($ids)));
        $a = TaskList::mark($pick($done), $email, $manager);
        $b = TaskList::reopen($pick($reopen), [], $email, $manager);
        $c = TaskList::set_due(array_map(fn($id) => ['task' => $rows[$id], 'date' => $due[$id]], array_keys(array_intersect_key($due, $rows))), $email, $manager);
        $x = TaskList::cancel($pick($cancel), $email, $manager);
        $changed = count($a['changed']) + count($b['changed']) + count($c['changed']) + count($x['changed']);
        if ( $changed ) $this->undo[] = $token;
        $t = fn($r) => ['id' => (int) $r['id'], 'title' => $r['title']];
        return [
            'marked_done' => array_map($t, $a['changed']), 'already_done' => array_map($t, $a['already']),
            'reopened' => array_map($t, $b['changed']), 'cancelled' => array_map($t, $x['changed']), 'new_dates' => array_map(fn($x) => $t($x['task']) + ['due' => $x['date']], $c['changed']),
            'not_allowed' => array_map($t, array_merge($a['denied'], $b['denied'], $c['denied'], $x['denied'])),
            'missing_ids' => array_values(array_diff(array_merge($done, $reopen, $cancel, array_keys($due)), array_keys($rows))),
        ] + array_filter(['done_not_changed' => $closed_done ? ['tasks' => $closed_done, 'note' => 'Están hechas: no les he cambiado la fecha. Pregúntale si quiere reabrirlas (reopen).'] : null]) + $this->revise($in) + $this->assign($in);
    }

    /** @var array<int,int[]> team member id => tasks just given to them (Jessica can send them with one click) */
    public $notify = [];

    /** «jessica», «cliente», «Liz»… → [owner, team member row|null] or null if unknown. */
    private static function assignee($to){
        $k = strtolower(remove_accents(trim((string) $to)));
        if ( in_array($k, ['jessica', 'yo', 'mi', 'gerencia'], true) ) return ['jessica', null];
        if ( in_array($k, ['mad', 'nosotros', 'equipo mad'], true) ) return ['mad', null];
        if ( in_array($k, ['cliente', 'client'], true) ) return ['cliente', null];
        foreach ( Team::all(true) as $m ) {
            if ( $k === strtolower($m['slug']) || $k === strtolower(remove_accents($m['name'])) || strpos(strtolower(remove_accents($m['name'])), $k) === 0 ) return [$m['slug'] === 'liz' ? 'liz' : 'mad', $m];
        }
        return null;
    }

    /** Reassign tasks (only Jessica). A task given to the team becomes visible to the team. */
    private function assign(array $in){
        global $wpdb;
        $list = (array) ($in['assign'] ?? []);
        if ( ! $list ) return [];
        if ( empty($this->ctx['is_manager']) ) return ['assign_not_allowed' => 'Solo Jessica reparte tareas.'];
        $rows = TaskList::rows(array_map(fn($a) => (int) ($a['id'] ?? 0), $list));
        if ( $rows ) $this->undo[] = \MAD_Oficina\Core\TaskChanges::snapshot(array_values($rows));
        $done = []; $unknown = [];
        foreach ( $list as $a ) {
            $t = $rows[(int) ($a['id'] ?? 0)] ?? null;
            $who = self::assignee($a['to'] ?? '');
            if ( ! $t || ! $who ) { $unknown[] = ['id' => (int) ($a['id'] ?? 0), 'to' => (string) ($a['to'] ?? '')]; continue; }
            [$owner, $member] = $who;
            if ( $t['status'] === 'done' ) { $unknown[] = ['id' => (int) $t['id'], 'to' => (string) ($a['to'] ?? ''), 'why' => 'está hecha: pregúntale si la reabre']; continue; }
            $fields = ['owner' => $owner, 'team_member_id' => $member ? (int) $member['id'] : null, 'updated_at' => Time::now()];
            if ( $t['status'] === 'cancelled' ) { $fields['status'] = 'open'; $fields['completed_at'] = null; } // giving it to someone brings it back
            if ( $member && $t['visibility'] === 'internal' ) $fields['visibility'] = 'team'; // so she sees it
            $wpdb->update(Schema::table('action_items'), $fields, ['id' => $t['id']]);
            if ( $member ) $this->notify[(int) $member['id']][] = (int) $t['id'];
            $done[] = ['id' => (int) $t['id'], 'title' => $t['title'], 'now_for' => $member ? $member['name'] : $owner];
        }
        return ['assigned' => $done, 'assign_unknown' => $unknown, 'note' => $this->notify ? 'Jessica tendrá un botón para avisar por Chat a quien le toca.' : null];
    }

    /** Revisions and «still pending», through TaskChanges (note in the task, priority up). */
    private function revise(array $in){
        $changes = [];
        foreach ( (array) ($in['revise'] ?? []) as $r ) $changes[] = ['task_id' => (int) ($r['id'] ?? 0), 'action' => 'change', 'title' => (string) ($r['title'] ?? ''), 'due_date' => $r['due_date'] ?? null, 'note' => (string) ($r['note'] ?? '')];
        foreach ( (array) ($in['still_pending'] ?? []) as $id ) $changes[] = ['task_id' => (int) $id, 'action' => 'repeat', 'note' => ''];
        if ( ! $changes ) return [];
        $rows = TaskList::rows(array_column($changes, 'task_id'));
        $by_client = []; $denied = [];
        foreach ( $changes as $c ) {
            $t = $rows[$c['task_id']] ?? null;
            if ( ! $t || ! TaskList::allowed($t, $this->ctx['email'], $this->ctx['manager_email']) ) { $denied[] = $c['task_id']; continue; }
            $by_client[(int) $t['client_id']][] = $c;
        }
        $done = [];
        $who = $this->ctx['member'] ? $this->ctx['member']['name'] : 'Jessica';
        foreach ( $by_client as $cid => $list ) {
            $r = \MAD_Oficina\Core\TaskChanges::apply($list, $cid ?: null, sprintf(__('lo que contó %s', 'mad-oficina'), $who));
            if ( $r['undo'] ) $this->undo[] = $r['undo'];
            foreach ( $r['applied'] as $a ) $done[] = \MAD_Oficina\Core\TaskChanges::describe($a);
        }
        return ['revised' => $done, 'revise_not_allowed' => $denied];
    }

    /** Open tasks grouped by client and normalised title (accents, case, punctuation, small words). */
    public function find_duplicates(array $in){
        $list = $this->list_tasks(['client' => $in['client'] ?? '', 'status' => 'open', 'limit' => 50]);
        if ( empty($list['tasks']) ) return ['groups' => [], 'note' => $list['note'] ?? 'No hay tareas abiertas.'];
        global $wpdb;
        $meta = [];
        foreach ( $wpdb->get_results('SELECT id, client_id, source, created_at FROM ' . Schema::table('action_items') . ' WHERE id IN (' . implode(',', array_map(fn($t) => (int) $t['id'], $list['tasks'])) . ')', ARRAY_A) as $r ) $meta[(int) $r['id']] = $r;
        $norm = function($s){
            $s = strtolower(remove_accents((string) $s));
            $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
            $words = array_diff(preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY), ['el', 'la', 'los', 'las', 'de', 'del', 'a', 'al', 'y', 'e', 'en', 'para', 'con', 'un', 'una', 'que', 'por']);
            sort($words);
            return implode(' ', $words);
        };
        $groups = [];
        foreach ( $list['tasks'] as $t ) {
            $m = $meta[$t['id']] ?? [];
            $groups[($m['client_id'] ?? 0) . '|' . $norm($t['title'])][] = $t + ['source' => $m['source'] ?? null, 'created' => substr((string) ($m['created_at'] ?? ''), 0, 10)];
        }
        // Near matches too: one normalised title contained in another, same client.
        $keys = array_keys($groups);
        foreach ( $keys as $i => $k1 ) foreach ( array_slice($keys, $i + 1) as $k2 ) {
            if ( ! isset($groups[$k1], $groups[$k2]) ) continue;
            [$c1, $t1] = explode('|', $k1, 2); [$c2, $t2] = explode('|', $k2, 2);
            if ( $c1 === $c2 && $t1 !== '' && $t2 !== '' && (strpos($t1, $t2) !== false || strpos($t2, $t1) !== false || similar_text($t1, $t2) / max(strlen($t1), strlen($t2)) > 0.85) ) {
                $groups[$k1] = array_merge($groups[$k1], $groups[$k2]); unset($groups[$k2]);
            }
        }
        return ['groups' => array_values(array_filter($groups, fn($g) => count($g) > 1))];
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
        [$owner, $m] = self::assignee($in['owner'] ?? 'jessica') ?: ['jessica', null];
        $member = $m ? (int) $m['id'] : null;
        if ( $this->member_id() ) { $owner = 'liz'; $member = $this->member_id(); }
        // Already open (the same message delivered twice, or she says it again): not a second one.
        if ( $same = \MAD_Oficina\Core\TaskChanges::existing($client, $title, ['open', 'in_progress']) ) {
            return ['already_open' => ['id' => (int) $same['id'], 'title' => $same['title'], 'due' => $same['due_date']], 'note' => 'Ya existe abierta: no la he creado otra vez. Si hay que cambiarle algo, usa update_tasks con ese id.'];
        }
        $closed = \MAD_Oficina\Core\TaskChanges::existing($client, $title, ['done', 'cancelled']);
        $wpdb->insert(Schema::table('action_items'), [
            'client_id' => $client, 'title' => $title, 'description' => sanitize_textarea_field((string) ($in['description'] ?? '')) ?: null,
            'owner' => $owner, 'team_member_id' => $member, 'due_date' => self::date_arg($in['due_date'] ?? ''),
            'status' => 'open', 'visibility' => $member ? 'team' : 'internal', 'source' => 'assistant', 'created_at' => Time::now(), 'updated_at' => Time::now(),
        ]);
        $id = (int) $wpdb->insert_id;
        if ( $member && ! $this->member_id() ) $this->notify[$member][] = $id;
        $this->undo[] = TaskList::snapshot([], [$id]);
        return array_filter(['note' => $closed ? sprintf('Ojo: había una igual %s (#%d). He creado una nueva porque lo ha pedido.', $closed['status'] === 'done' ? 'hecha' : 'anulada', $closed['id']) : null]) + ['created' => ['id' => $id, 'title' => $title, 'client' => self::client_label($client), 'owner' => $m['name'] ?? $owner, 'due' => self::date_arg($in['due_date'] ?? ''), 'client_sees_it' => false, 'team_sees_it' => (bool) $member]];
    }

    /** Read-only look at the minutes (no other agent involved): where a task came from, or a client's latest meetings. */
    public function meeting_context(array $in){
        global $wpdb;
        $mt = Schema::table('meetings');
        $read = function($m){
            $x = json_decode((string) $m['extraction'], true) ?: [];
            return ['date' => $m['starts_at'] ? get_date_from_gmt($m['starts_at'], 'Y-m-d') : null, 'type' => $m['source'] === 'whatsapp' ? 'whatsapp' : 'reunión', 'title' => $m['title'],
                'summary' => $m['summary'] ?: ($x['summary_internal'] ?? null), 'decisions' => $x['client_decisions'] ?? ($x['decisions'] ?? []), 'next_meeting' => $m['next_meeting']];
        };
        if ( ! empty($in['task_id']) ) {
            $t = $wpdb->get_row($wpdb->prepare('SELECT id, title, client_id, meeting_id, evidence, description, team_member_id FROM ' . Schema::table('action_items') . ' WHERE id = %d', (int) $in['task_id']), ARRAY_A);
            if ( ! $t || ($this->member_id() && (int) $t['team_member_id'] !== $this->member_id()) ) return new \WP_Error('madof_task', 'No existe esa tarea.');
            $m = $t['meeting_id'] ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$mt} WHERE id = %d", $t['meeting_id']), ARRAY_A) : null;
            return ['task' => $t['title'], 'quote' => $t['evidence'], 'history' => $t['description'] ? mb_substr($t['description'], 0, 800) : null, 'from' => $m ? $read($m) : 'no salió de una reunión'];
        }
        if ( $this->member_id() ) return new \WP_Error('madof_minutes', 'Las actas son de Jessica.');
        $ids = self::clients_matching($in['client'] ?? '');
        if ( ! $ids ) return ['meetings' => [], 'note' => 'Dime una tarea o un cliente.'];
        $rows = $wpdb->get_results("SELECT * FROM {$mt} WHERE client_id IN (" . implode(',', $ids) . ") AND status IN ('approved','archived') ORDER BY starts_at DESC LIMIT 3", ARRAY_A);
        return ['meetings' => array_map($read, $rows)];
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
