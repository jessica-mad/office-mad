<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * The change log («bitácora»): what changed in each campaign, when, who did it and why.
 * The platforms say what; the why is Jessica's (asked by Chat, told to Nora, or typed here).
 * Reasons are internal unless she marks one for the client.
 */
final class Changes {

    const BEFORE_DAYS = 7;

    const OBJECTS = [
        'campaign' => 'Campaña', 'campaign_budget' => 'Presupuesto', 'ad_group' => 'Grupo de anuncios', 'adset' => 'Conjunto de anuncios',
        'ad_group_ad' => 'Anuncio', 'ad' => 'Anuncio', 'ad_group_criterion' => 'Segmentación del grupo', 'campaign_criterion' => 'Segmentación de la campaña',
        'asset' => 'Recurso', 'asset_group' => 'Grupo de recursos', 'campaign_asset' => 'Recurso de la campaña', 'ad_group_asset' => 'Recurso del grupo',
        'ad_group_bid_modifier' => 'Ajuste de puja', 'feed' => 'Feed', 'account' => 'Cuenta',
    ];

    const FIELDS = [
        'amount_micros' => 'presupuesto diario', 'amountmicros' => 'presupuesto diario', 'budget' => 'presupuesto', 'status' => 'estado', 'name' => 'nombre',
        'bidding' => 'estrategia de puja', 'bidding_strategy_type' => 'estrategia de puja', 'end_date' => 'fecha de fin',
        'target_cpa_micros' => 'CPA objetivo', 'maximize_conversions.target_cpa_micros' => 'CPA objetivo', 'target_cpa.target_cpa_micros' => 'CPA objetivo',
        'target_roas' => 'ROAS objetivo', 'maximize_conversion_value.target_roas' => 'ROAS objetivo', 'target_roas.target_roas' => 'ROAS objetivo',
        'cpc_bid_micros' => 'puja CPC', 'final_urls' => 'URL final', 'keyword.text' => 'palabra clave', 'negative' => 'negativa',
        'create' => 'creado', 'remove' => 'eliminado',
    ];

    const STATUSES = ['enabled' => 'activa', 'active' => 'activa', 'paused' => 'en pausa', 'removed' => 'eliminada', 'archived' => 'archivada', 'deleted' => 'eliminada'];

    public static function table(){ return Schema::table('ad_changes'); }

    /**
     * Rows from a platform (GoogleAds::changes, Meta::changes or the daily comparison).
     * Already known ones are skipped. @return int new
     */
    public static function record(array $account, array $rows){
        global $wpdb;
        $n = 0;
        foreach ( $rows as $r ) {
            $hash = sha1($account['platform'] . '|' . $account['id'] . '|' . ($r['external_ref'] ?? '') . '|' . ($r['field'] ?? '') . '|' . ($r['changed_at'] ?? ''));
            if ( $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE hash = %s', $hash)) ) continue;
            $campaign = $r['campaign_id'] ?? Accounts::campaign_id($account['id'], (string) ($r['campaign_ext'] ?? ''), (string) ($r['budget_ref'] ?? ''));
            $c = $campaign ? Accounts::campaign($campaign) : null;
            $row = [
                'hash' => $hash, 'platform' => $account['platform'], 'account_id' => (int) $account['id'], 'client_id' => $account['client_id'] ?: null,
                'campaign_id' => $campaign ?: null, 'changed_at' => $r['changed_at'], 'actor' => mb_substr((string) ($r['actor'] ?? ''), 0, 190),
                'source' => mb_substr((string) ($r['source'] ?? ''), 0, 30), 'automatic' => ! empty($r['automatic']) ? 1 : 0,
                'object_type' => mb_substr((string) ($r['object_type'] ?? ''), 0, 40), 'object_name' => mb_substr((string) ($r['object_name'] ?: ($c['name'] ?? '')), 0, 255),
                'field' => mb_substr((string) ($r['field'] ?? ''), 0, 80), 'old_value' => $r['old_value'] ?? null, 'new_value' => $r['new_value'] ?? null,
                'created_at' => Time::now(), 'updated_at' => Time::now(),
            ];
            $row['summary'] = mb_substr(self::describe($row, $c), 0, 500);
            $day = substr($row['changed_at'], 0, 10);
            $row['metrics_before'] = wp_json_encode(Accounts::window($campaign, $account['id'], gmdate('Y-m-d', strtotime($day . ' -' . self::BEFORE_DAYS . ' days')), gmdate('Y-m-d', strtotime($day . ' -1 day'))));
            // Said beforehand (to Nora, or in a meeting): the reason waits for its change.
            if ( ! $row['automatic'] && ( $pre = Reasons::waiting_for($row, $c) ) ) {
                $row += ['reason' => $pre['reason'], 'reason_source' => $pre['source'], 'reason_by' => $pre['by'] ?: null, 'reason_at' => Time::now()];
            }
            if ( $wpdb->insert(self::table(), $row) ) $n++;
        }
        return $n;
    }

    /**
     * A change she made and tells (Nora or the log form): it goes in the log with its why
     * (source «manual»), linked to the client's campaign when its name is found.
     * @return int|\WP_Error id
     */
    public static function add_manual($client_id, $platform, $campaign, $what, $reason, $date, $user_id = 0, $source = 'chat'){
        global $wpdb;
        $what = trim(sanitize_text_field($what));
        if ( ! $client_id || $what === '' ) return new \WP_Error('madof_ads', __('Falta el cliente o qué cambió.', 'mad-oficina'));
        $platform = in_array($platform, ['google', 'meta'], true) ? $platform : 'other';
        $campaign = trim(sanitize_text_field($campaign));
        $match = null;
        if ( $campaign !== '' ) {
            $words = array_filter(explode(' ', strtolower(remove_accents($campaign))), fn($w) => strlen($w) >= 3);
            foreach ( Accounts::campaigns_of((int) $client_id) as $c ) {
                if ( $platform !== 'other' && $c['platform'] !== $platform ) continue;
                $n = strtolower(remove_accents($c['name']));
                if ( $words && ! array_filter($words, fn($w) => strpos($n, $w) === false) ) { $match = $c; break; }
            }
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? $date : current_time('Y-m-d');
        $now = Time::now();
        $row = [
            'hash' => sha1('manual|' . $client_id . '|' . wp_generate_password(12, false)), 'platform' => $match['platform'] ?? $platform,
            'account_id' => (int) ($match['account_id'] ?? 0), 'client_id' => (int) $client_id, 'campaign_id' => $match ? (int) $match['id'] : null,
            'changed_at' => $date . ' ' . ( $date === current_time('Y-m-d') ? current_time('H:i:s') : '12:00:00' ), 'actor' => $user_id ? (string) get_the_author_meta('display_name', $user_id) : '',
            'source' => 'manual', 'automatic' => 0, 'object_type' => 'campaign', 'object_name' => mb_substr($match['name'] ?? $campaign, 0, 255),
            'field' => 'manual', 'summary' => mb_substr(( ($match['name'] ?? $campaign) !== '' ? sprintf(__('Campaña «%s»: ', 'mad-oficina'), $match['name'] ?? $campaign) : '' ) . $what, 0, 500),
            'created_at' => $now, 'updated_at' => $now,
        ];
        if ( $match ) $row['metrics_before'] = wp_json_encode(Accounts::window((int) $match['id'], (int) $match['account_id'], gmdate('Y-m-d', strtotime($date . ' -' . self::BEFORE_DAYS . ' days')), gmdate('Y-m-d', strtotime($date . ' -1 day'))));
        if ( ! $wpdb->insert(self::table(), $row) ) return new \WP_Error('madof_ads', __('No he podido apuntarlo.', 'mad-oficina'));
        $id = (int) $wpdb->insert_id;
        if ( trim((string) $reason) !== '' ) self::set_reason([$id], $reason, $source, $user_id);
        return $id;
    }

    /** «Presupuesto de "Rebajas": 20 → 35 €/día» */
    public static function describe(array $row, ?array $campaign = null){
        $obj = __(self::OBJECTS[$row['object_type']] ?? ucfirst(str_replace('_', ' ', $row['object_type'] ?: 'cambio')), 'mad-oficina');
        $name = $row['object_name'] !== '' ? ' «' . $row['object_name'] . '»' : '';
        if ( $campaign && $row['object_type'] !== 'campaign' && $campaign['name'] !== $row['object_name'] ) $name .= ' (' . sprintf(__('campaña «%s»', 'mad-oficina'), $campaign['name']) . ')';
        $field = strtolower($row['field']);
        $label = __(self::FIELDS[$field] ?? self::FIELDS[preg_replace('/^.*\./', '', $field)] ?? str_replace('_', ' ', $row['field']), 'mad-oficina');
        $fmt = function($v) use ($field){
            if ( $v === null || $v === '' ) return '—';
            if ( $field === 'status' ) return __(self::STATUSES[strtolower($v)] ?? strtolower($v), 'mad-oficina');
            if ( is_numeric($v) && preg_match('/budget|micros|amount|cpa|cpc/', $field) ) return self::money($v);
            return (string) $v;
        };
        if ( in_array($field, ['create', 'remove'], true) ) return trim($obj . $name . ': ' . $label);
        if ( $row['old_value'] === null && $row['new_value'] === null ) return trim($obj . $name . ': ' . $label);
        return trim($obj . $name . ': ' . $label . ' ' . $fmt($row['old_value']) . ' → ' . $fmt($row['new_value']));
    }

    public static function get($id){
        global $wpdb;
        return self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A));
    }

    /**
     * @param array{client_id?:int, campaign_id?:int, platform?:string, from?:string, to?:string, pending?:bool, automatic?:bool, q?:string} $f
     */
    public static function find(array $f = [], $limit = 200){
        global $wpdb;
        $w = ['1=1'];
        if ( ! empty($f['client_id']) ) $w[] = $wpdb->prepare('ch.client_id = %d', $f['client_id']);
        if ( ! empty($f['campaign_id']) ) $w[] = $wpdb->prepare('ch.campaign_id = %d', $f['campaign_id']);
        if ( ! empty($f['platform']) ) $w[] = $wpdb->prepare('ch.platform = %s', $f['platform']);
        if ( ! empty($f['from']) ) $w[] = $wpdb->prepare('ch.changed_at >= %s', $f['from'] . ' 00:00:00');
        if ( ! empty($f['to']) ) $w[] = $wpdb->prepare('ch.changed_at <= %s', $f['to'] . ' 23:59:59');
        if ( ! empty($f['pending']) ) $w[] = 'ch.reason_at IS NULL AND ch.automatic = 0';
        if ( isset($f['automatic']) ) $w[] = $f['automatic'] ? 'ch.automatic = 1' : 'ch.automatic = 0';
        if ( ! empty($f['linked']) ) $w[] = 'ch.client_id IS NOT NULL';
        if ( ! empty($f['q']) ) { $like = '%' . $wpdb->esc_like($f['q']) . '%'; $w[] = $wpdb->prepare('(ch.summary LIKE %s OR ch.reason LIKE %s)', $like, $like); }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ch.*, c.name AS campaign_name, cl.code AS client_code, cl.name AS client_name FROM ' . self::table() . ' ch'
            . ' LEFT JOIN ' . Schema::table('ad_campaigns') . ' c ON c.id = ch.campaign_id LEFT JOIN ' . Schema::table('clients') . ' cl ON cl.id = ch.client_id'
            . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY ch.changed_at DESC, ch.id DESC LIMIT %d', $limit), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** Several changes, one reason (a group answered at once). @return int */
    public static function set_reason(array $ids, $reason, $source = 'manual', $user_id = 0, $visibility = null){
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ( ! $ids ) return 0;
        $reason = trim(sanitize_textarea_field((string) $reason));
        $set = $reason === ''
            ? ['reason' => null, 'reason_source' => null, 'reason_by' => null, 'reason_at' => null]
            : ['reason' => $reason, 'reason_source' => sanitize_key($source), 'reason_by' => $user_id ?: null, 'reason_at' => Time::now()];
        if ( $visibility !== null ) $set['visibility'] = $visibility === 'client' ? 'client' : 'internal';
        $set['updated_at'] = Time::now();
        $n = 0;
        foreach ( $ids as $id ) $n += (int) $wpdb->update(self::table(), $set, ['id' => $id]);
        return $n;
    }

    /** A week after each change, the 7 days that followed. @return int filled */
    public static function fill_after($today = null){
        global $wpdb;
        $today = $today ?: current_time('Y-m-d');
        $limit = gmdate('Y-m-d', strtotime($today . ' -' . (self::BEFORE_DAYS + 1) . ' days')) . ' 23:59:59';
        $n = 0;
        foreach ( $wpdb->get_results($wpdb->prepare('SELECT id, campaign_id, account_id, changed_at FROM ' . self::table() . ' WHERE metrics_after IS NULL AND automatic = 0 AND changed_at <= %s ORDER BY changed_at DESC LIMIT 300', $limit), ARRAY_A) as $c ) {
            $day = substr($c['changed_at'], 0, 10);
            $after = Accounts::window((int) $c['campaign_id'], (int) $c['account_id'], gmdate('Y-m-d', strtotime($day . ' +1 day')), gmdate('Y-m-d', strtotime($day . ' +' . self::BEFORE_DAYS . ' days')));
            $wpdb->update(self::table(), ['metrics_after' => wp_json_encode($after)], ['id' => (int) $c['id']]);
            $n++;
        }
        return $n;
    }

    /** «CPA 9,10 € → 11,40 €» with what moved, for the log and the reports. */
    public static function effect(array $c){
        $b = $c['metrics_before']; $a = $c['metrics_after'];
        if ( ! $b || ! $a || ! $b['days'] || ! $a['days'] ) return '';
        $money = fn($v) => $v === null ? '—' : self::money($v);
        $parts = [];
        $parts[] = sprintf(__('gasto/día %1$s → %2$s', 'mad-oficina'), $money($b['spend'] / $b['days']), $money($a['spend'] / $a['days']));
        if ( $b['conversions'] || $a['conversions'] ) $parts[] = sprintf(__('conv. %1$s → %2$s', 'mad-oficina'), self::num($b['conversions']), self::num($a['conversions']));
        if ( $b['cpa'] !== null || $a['cpa'] !== null ) $parts[] = sprintf(__('CPA %1$s → %2$s', 'mad-oficina'), $money($b['cpa']), $money($a['cpa']));
        if ( $b['ctr'] !== null && $a['ctr'] !== null ) $parts[] = sprintf(__('CTR %1$s%% → %2$s%%', 'mad-oficina'), self::num($b['ctr']), self::num($a['ctr']));
        return implode(' · ', $parts);
    }

    public static function money($v){ return number_format((float) $v, 2, ',', '.') . ' €'; }

    private static function num($v){ return number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 2, ',', '.'); }

    public static function counts(){
        global $wpdb;
        return [
            'pending' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE reason_at IS NULL AND automatic = 0 AND client_id IS NOT NULL'),
            'total' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table()),
        ];
    }

    private static function hydrate($r){
        if ( ! $r ) return null;
        foreach ( ['id', 'account_id', 'client_id', 'campaign_id', 'automatic', 'reason_by'] as $k ) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        foreach ( ['metrics_before', 'metrics_after'] as $k ) $r[$k] = $r[$k] ? (json_decode($r[$k], true) ?: null) : null;
        return $r;
    }
}
