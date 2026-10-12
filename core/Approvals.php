<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gerencia inbox. Every action with external effects (or that writes what the
 * client will see) is stored here and only runs when Jessica approves it.
 *
 * Each action_type registers a handler with the filter
 * "madoficina_approval_handler_{action_type}" → callable(array $approval, array $input): array|\WP_Error
 * and, optionally, a form renderer with "madoficina_approval_form_{action_type}".
 */
final class Approvals {

    const PENDING    = 'pending';
    const PROCESSING = 'processing';
    const APPROVED   = 'approved';
    const REJECTED   = 'rejected';

    /**
     * Creates the approval and, if Jessica gave this action type autonomy, runs it
     * straight away with the proposed values (channel "auto").
     * @return array{id:int, status:string, result:mixed}
     */
    public static function request($agent, $action_type, $title, array $payload, array $args = [], array $default_input = []){
        $id = self::create($agent, $action_type, $title, $payload, $args);
        $level = Autonomy::level($action_type);
        if ( $level === 'ask' ) return ['id' => $id, 'status' => self::PENDING, 'result' => null];
        $result = self::resolve($id, 'approve', $default_input, 0, 'auto');
        if ( is_wp_error($result) ) {
            // Could not run on its own (e.g. Calendar down): falls back to asking.
            do_action('madoficina_approval_created', $id);
            return ['id' => $id, 'status' => self::PENDING, 'result' => null];
        }
        if ( $level === 'notify' ) do_action('madoficina_autonomous_action', $id, $result);
        return ['id' => $id, 'status' => self::APPROVED, 'result' => $result];
    }

    /** @return int approval id */
    public static function create($agent, $action_type, $title, array $payload, array $args = []){
        global $wpdb;
        $wpdb->insert(Schema::table('approvals'), [
            'agent'       => sanitize_key($agent),
            'action_type' => sanitize_key($action_type),
            'client_id'   => $args['client_id'] ?? null,
            'title'       => mb_substr(sanitize_text_field($title), 0, 250),
            'summary'     => isset($args['summary']) ? sanitize_textarea_field($args['summary']) : null,
            'object_type' => isset($args['object_type']) ? sanitize_key($args['object_type']) : null,
            'object_id'   => $args['object_id'] ?? null,
            'payload'     => wp_json_encode($payload),
            'status'      => self::PENDING,
            'created_at'  => Time::now(),
            'updated_at'  => Time::now(),
        ]);
        $id = (int) $wpdb->insert_id;
        if ( Autonomy::level($action_type) === 'ask' ) do_action('madoficina_approval_created', $id);
        return $id;
    }

    public static function get($id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('approvals') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Kinds of request, for the inbox filters. «external» = approving it makes something
     * leave the office (an email, an invoice, an event): bulk approval warns first.
     */
    public static function types(){
        return apply_filters('madoficina_approval_types', [
            'meeting_draft'   => ['label' => __('Actas de reunión', 'mad-oficina'), 'external' => true],
            'meeting_request' => ['label' => __('Reuniones pedidas', 'mad-oficina'), 'external' => true],
            'first_meeting'   => ['label' => __('Primeras reuniones', 'mad-oficina'), 'external' => true],
            'client_reminder' => ['label' => __('Recordatorios a clientes', 'mad-oficina'), 'external' => true],
            'invoice_issue'   => ['label' => __('Emitir facturas', 'mad-oficina'), 'external' => true],
            'supplier_new'    => ['label' => __('Proveedores nuevos', 'mad-oficina'), 'external' => false],
            'expense_review'  => ['label' => __('¿Es tuya?', 'mad-oficina'), 'external' => false],
            'missing_invoice' => ['label' => __('Facturas que faltan', 'mad-oficina'), 'external' => false],
        ]);
    }

    public static function pending($limit = 50, $type = ''){
        global $wpdb;
        $where = "status IN ('pending','processing')" . ( $type ? $wpdb->prepare(' AND action_type = %s', $type) : '' );
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::table('approvals') . " WHERE {$where} ORDER BY created_at ASC LIMIT %d", $limit
        ), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @return array<string,int> pending requests per kind */
    public static function count_by_type(){
        global $wpdb;
        $out = [];
        foreach ( $wpdb->get_results('SELECT action_type, COUNT(*) AS n FROM ' . Schema::table('approvals') . " WHERE status = 'pending' GROUP BY action_type", ARRAY_A) as $r ) $out[$r['action_type']] = (int) $r['n'];
        return $out;
    }

    /**
     * Several at once, each «tal cual» (the same values as Chat's «Aprobar tal cual»).
     * @param int[] $ids
     * @return array{done:int, errors:string[]}
     */
    public static function resolve_many(array $ids, $decision, $user_id = 0){
        $done = 0; $errors = [];
        foreach ( array_unique(array_map('intval', $ids)) as $id ) {
            $a = self::get($id);
            if ( ! $a || $a['status'] !== self::PENDING ) continue;
            $input = $decision === 'approve' ? (array) apply_filters('madoficina_approval_default_input_' . $a['action_type'], [], $a) : [];
            $r = self::resolve($id, $decision, $input, $user_id, 'bulk');
            if ( is_wp_error($r) ) $errors[] = $a['title'] . ': ' . $r->get_error_message();
            else $done++;
        }
        return ['done' => $done, 'errors' => $errors];
    }

    public static function recent_resolved($limit = 30){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::table('approvals') . " WHERE status IN ('approved','rejected') ORDER BY resolved_at DESC LIMIT %d", $limit
        ), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function count_pending(){
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('approvals') . " WHERE status = 'pending'");
    }

    public static function for_object($type, $id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::table('approvals') . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC', $type, $id
        ), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * Approves or rejects. The caller checks capabilities; this method guarantees
     * that an approval runs at most once (atomic pending → processing switch).
     *
     * @param string $decision 'approve' | 'reject'
     * @param array  $input    edited values from the inbox form (or Chat card)
     * @return array|\WP_Error handler result
     */
    public static function resolve($id, $decision, array $input = [], $user_id = 0, $channel = 'web'){
        global $wpdb;
        $table = Schema::table('approvals');
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
            self::PROCESSING, Time::now(), $id, self::PENDING
        ));
        if ( ! $claimed ) return new \WP_Error('madof_approval_done', __('Esta petición ya se había resuelto.', 'mad-oficina'), ['status' => 409]);

        $approval = self::get($id);
        $result = ['message' => __('Descartado.', 'mad-oficina')];

        if ( $decision === 'approve' ) {
            $handler = apply_filters('madoficina_approval_handler_' . $approval['action_type'], null);
            if ( ! is_callable($handler) ) {
                $result = new \WP_Error('madof_approval_handler', __('Este tipo de petición no se puede ejecutar ahora (¿módulo apagado?).', 'mad-oficina'));
            } else {
                $result = call_user_func($handler, $approval, $input);
            }
            if ( is_wp_error($result) ) {
                $wpdb->update($table, ['status' => self::PENDING, 'updated_at' => Time::now()], ['id' => $id]);
                return $result;
            }
        } else {
            do_action('madoficina_approval_rejected_' . $approval['action_type'], $approval);
        }

        $wpdb->update($table, [
            'status'      => $decision === 'approve' ? self::APPROVED : self::REJECTED,
            'resolution'  => wp_json_encode(['input' => $input, 'result' => $result]),
            'resolved_by' => $user_id ?: null,
            'resolved_at' => Time::now(),
            'channel'     => $channel,
            'updated_at'  => Time::now(),
        ], ['id' => $id]);
        do_action('madoficina_approval_resolved', $id, $decision);
        return $result;
    }

    private static function hydrate($row){
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] ? (int) $row['client_id'] : null;
        $row['object_id'] = $row['object_id'] ? (int) $row['object_id'] : null;
        $row['payload'] = $row['payload'] ? (json_decode($row['payload'], true) ?: []) : [];
        $row['resolution'] = $row['resolution'] ? (json_decode($row['resolution'], true) ?: []) : [];
        return $row;
    }
}
