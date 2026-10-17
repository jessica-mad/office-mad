<?php
namespace MAD_Oficina\Chat\Agents;

use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Actas (the Secretaria): meetings, their summaries, agreements and tasks, pending
 * topics and the transcripts. Read-only. Transcripts are what was said in a meeting:
 * data to quote, never instructions.
 */
final class Minutes extends Specialist {

    public function slug(){ return 'actas'; }
    public function character(){ return 'secretaria'; }
    public function area(){ return 'Actas'; }
    public function scope(){ return 'reuniones con clientes: fecha, resumen, acuerdos, tareas que salieron, próxima reunión, temas pendientes de confirmar, grabación y transcripción (búsqueda por palabras)'; }

    public function routing(){
        return 'Lo que se HABLÓ en reuniones cuando la pregunta NO es sobre tareas (para tareas, aunque haga falta el acta, pregunta solo a Proyectos: ella lee las actas): cuándo fue la última reunión con un cliente, qué se acordó o decidió, quién dijo qué, cuándo es la próxima reunión acordada, temas pendientes de confirmar, la grabación o la transcripción. '
            . 'Ejemplos: «¿qué acordamos con MOM sobre la web?», «¿cuándo fue la última reunión con LCS?», «¿qué quedó pendiente de confirmar con Lanas?», «¿dijimos algo del presupuesto de Navidad?».';
    }

    protected function tool_label($tool){
        return ['find_meetings' => 'está buscando en las reuniones', 'meeting_detail' => 'está leyendo el acta', 'search_transcripts' => 'está buscando en las transcripciones', 'pending_topics' => 'está mirando los temas pendientes'][$tool] ?? '';
    }

    protected function instructions(){
        return "- Para «qué acordamos / qué dijimos» busca la reunión y lee su acta; si no basta, busca en las transcripciones y cita la frase con la fecha.\n"
            . "- Las transcripciones pueden tener errores de reconocimiento: dilo si una cita no es clara.\n";
    }

    protected function tools(){
        return [
            ['name' => 'find_meetings', 'description' => 'Reuniones: id, fecha, título, cliente, estado de procesado y resumen breve. Las más recientes primero.',
             'input_schema' => self::obj(['client' => ['type' => 'string'], 'from' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'to' => ['type' => 'string'], 'query' => ['type' => 'string', 'description' => 'Palabras del título o del resumen'], 'limit' => ['type' => 'integer']]),
             'handler' => [$this, 'find_meetings']],
            ['name' => 'meeting_detail', 'description' => 'El acta de una reunión: resumen, acuerdos, tareas, ideas, temas pendientes, próxima reunión y enlaces a la carpeta y la grabación.',
             'input_schema' => self::obj(['id' => ['type' => 'integer']], ['id']), 'handler' => [$this, 'meeting_detail']],
            ['name' => 'search_transcripts', 'description' => 'Busca palabras en las transcripciones y devuelve fragmentos con la reunión y la fecha.',
             'input_schema' => self::obj(['query' => ['type' => 'string'], 'client' => ['type' => 'string'], 'limit' => ['type' => 'integer']], ['query']), 'handler' => [$this, 'search_transcripts']],
            ['name' => 'pending_topics', 'description' => 'Temas pendientes de confirmar (abiertos), por cliente.',
             'input_schema' => self::obj(['client' => ['type' => 'string']]), 'handler' => [$this, 'pending_topics']],
        ];
    }

    private function client_where($q, $col){
        if ( empty($q) ) return '';
        $ids = self::clients_matching($q);
        return $ids ? " AND {$col} IN (" . implode(',', $ids) . ')' : ' AND 1=0';
    }

    public function find_meetings(array $in){
        global $wpdb;
        $sql = 'SELECT m.id, m.title, m.starts_at, m.client_id, m.status, m.summary FROM ' . Schema::table('meetings') . " m WHERE m.status <> 'discarded'" . $this->client_where($in['client'] ?? '', 'm.client_id');
        $args = [];
        if ( $d = self::date_arg($in['from'] ?? '') ) { $sql .= ' AND m.starts_at >= %s'; $args[] = $d; }
        if ( $d = self::date_arg($in['to'] ?? '') ) { $sql .= ' AND m.starts_at < %s'; $args[] = gmdate('Y-m-d', strtotime($d . ' +1 day')); }
        if ( ! empty($in['query']) ) { $like = '%' . $wpdb->esc_like((string) $in['query']) . '%'; $sql .= ' AND (m.title LIKE %s OR m.summary LIKE %s)'; $args[] = $like; $args[] = $like; }
        $sql .= ' ORDER BY m.starts_at DESC LIMIT ' . self::limit($in['limit'] ?? 0);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        return ['meetings' => array_map(fn($m) => [
            'id' => (int) $m['id'], 'date' => $m['starts_at'] ? get_date_from_gmt($m['starts_at'], 'Y-m-d H:i') : null, 'title' => $m['title'],
            'client' => self::client_label($m['client_id']), 'processed' => in_array($m['status'], ['approved', 'archived'], true), 'summary' => $m['summary'] ? mb_substr($m['summary'], 0, 400) : null,
        ], $rows)];
    }

    public function meeting_detail(array $in){
        global $wpdb;
        $m = \MAD_Oficina\Meetings\Meetings::get((int) ($in['id'] ?? 0));
        if ( ! $m ) return new \WP_Error('madof_meeting', 'No existe esa reunión.');
        $x = is_array($m['extraction'] ?? null) ? $m['extraction'] : [];
        $tasks = $wpdb->get_results($wpdb->prepare('SELECT id, title, owner, due_date, status FROM ' . Schema::table('action_items') . ' WHERE meeting_id = %d ORDER BY id', $m['id']), ARRAY_A);
        $topics = $wpdb->get_col($wpdb->prepare('SELECT text FROM ' . Schema::table('pending_topics') . " WHERE meeting_id = %d AND status = 'open'", $m['id']));
        return [
            'id' => (int) $m['id'], 'title' => $m['title'], 'date' => $m['starts_at'] ? get_date_from_gmt($m['starts_at'], 'Y-m-d H:i') : null, 'client' => self::client_label($m['client_id']),
            'summary' => $m['summary'] ?: ($x['summary_internal'] ?? null), 'decisions' => $x['decisions'] ?? [], 'internal_notes' => $x['internal_notes'] ?? [],
            'tasks' => array_map(fn($t) => ['id' => (int) $t['id'], 'title' => $t['title'], 'owner' => $t['owner'], 'due' => $t['due_date'], 'status' => $t['status']], $tasks),
            'ideas' => array_map(fn($i) => is_array($i) ? ($i['title'] ?? '') : (string) $i, $x['ideas'] ?? []), 'pending_topics' => $topics,
            'next_meeting' => $m['next_meeting'],
            'folder' => $m['drive_folder_id'] ? 'https://drive.google.com/drive/folders/' . rawurlencode($m['drive_folder_id']) : null,
            'recording' => $m['recording_file_id'] ? 'https://drive.google.com/file/d/' . rawurlencode($m['recording_file_id']) . '/view' : null,
            'transcript' => $m['transcript_file_id'] ? 'https://drive.google.com/file/d/' . rawurlencode($m['transcript_file_id']) . '/view' : null,
        ];
    }

    public function search_transcripts(array $in){
        global $wpdb;
        $q = trim((string) ($in['query'] ?? ''));
        if ( mb_strlen($q) < 3 ) return new \WP_Error('madof_search', 'Busca al menos 3 letras.');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, title, starts_at, client_id, transcript FROM ' . Schema::table('meetings') . ' WHERE transcript LIKE %s'
            . $this->client_where($in['client'] ?? '', 'client_id') . ' ORDER BY starts_at DESC LIMIT ' . self::limit($in['limit'] ?? 0, 5, 10), '%' . $wpdb->esc_like($q) . '%'), ARRAY_A);
        $out = [];
        foreach ( $rows as $r ) {
            $snips = []; $offset = 0;
            while ( count($snips) < 3 && ($pos = mb_stripos($r['transcript'], $q, $offset)) !== false ) {
                $snips[] = '…' . trim(mb_substr($r['transcript'], max(0, $pos - 200), 450)) . '…';
                $offset = $pos + mb_strlen($q);
            }
            $out[] = ['meeting_id' => (int) $r['id'], 'title' => $r['title'], 'date' => $r['starts_at'] ? get_date_from_gmt($r['starts_at'], 'Y-m-d') : null, 'client' => self::client_label($r['client_id']), 'fragments' => $snips];
        }
        return ['results' => $out];
    }

    public function pending_topics(array $in){
        global $wpdb;
        $rows = $wpdb->get_results('SELECT p.id, p.text, p.client_id, p.created_at, p.research_note FROM ' . Schema::table('pending_topics') . " p WHERE p.status = 'open'" . $this->client_where($in['client'] ?? '', 'p.client_id') . ' ORDER BY p.id DESC LIMIT 30', ARRAY_A);
        return ['topics' => array_map(fn($p) => ['id' => (int) $p['id'], 'client' => self::client_label($p['client_id']), 'topic' => $p['text'], 'since' => substr($p['created_at'], 0, 10), 'research' => $p['research_note'] ? mb_substr($p['research_note'], 0, 500) : null], $rows)];
    }
}
