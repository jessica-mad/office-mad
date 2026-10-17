<?php
namespace MAD_Oficina\Ads;

if ( ! defined('ABSPATH') ) exit;

/**
 * Meta Marketing API (Graph), read-only: GET requests only, with the token of a system
 * user of her Business Manager (ads_read) or her own long-lived one. Ad accounts shared
 * with MAD as a partner are read as long as they are assigned to that user.
 *
 * The activity log is less detailed than Google's (a budget change may come without its
 * values): Sync fills the gaps comparing with the previous day.
 */
final class Meta {

    const DEFAULT_VERSION = 'v24.0';
    /** What counts as a conversion, first found wins (Meta repeats the same one under several names). */
    const CONVERSIONS = ['purchase', 'offsite_conversion.fb_pixel_purchase', 'lead', 'onsite_conversion.lead_grouped', 'complete_registration', 'onsite_conversion.messaging_conversation_started_7d', 'contact'];
    /** Activity Meta does by itself (reviews, delivery, billing): never asked about. */
    const AUTOMATIC = '/review|first_delivery|billing|charge|funding|spend_limit_reached|ad_account_reset|disapprov/';

    private $s;

    public function __construct(array $settings){ $this->s = $settings; }

    public function token(){
        $enc = (string) ($this->s['meta_token'] ?? '');
        return $enc !== '' ? (string) \MAD_Oficina\Core\Crypto::decrypt($enc) : '';
    }

    public function ready(){ return $this->token() !== ''; }

    public function missing(){ return $this->ready() ? '' : __('Falta el token de Meta (pestaña Conexión). Mientras tanto, sube los informes en «Informes de Meta».', 'mad-oficina'); }

    /** @return array[]|\WP_Error every item of an edge (pages followed). */
    public function get($path, array $params = []){
        if ( ! $this->ready() ) return new \WP_Error('madof_meta', __('Falta el token de Meta (Campañas → Conexión).', 'mad-oficina'));
        $version = preg_replace('/[^v0-9.]/', '', (string) ($this->s['meta_version'] ?? '')) ?: self::DEFAULT_VERSION;
        $url = add_query_arg(array_map('rawurlencode', $params + ['limit' => 200]), 'https://graph.facebook.com/' . $version . '/' . ltrim($path, '/'));
        $out = [];
        for ( $i = 0; $i < 30 && $url; $i++ ) {
            $res = wp_remote_get($url, ['timeout' => 40, 'headers' => ['Authorization' => 'Bearer ' . $this->token()]]);
            if ( is_wp_error($res) ) return $res;
            $code = wp_remote_retrieve_response_code($res);
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if ( $code >= 300 || isset($data['error']) ) {
                $msg = $data['error']['message'] ?? '';
                if ( (int) ($data['error']['code'] ?? 0) === 190 ) $msg = __('el token ha caducado o no es válido: pega uno nuevo en Campañas → Conexión', 'mad-oficina');
                return new \WP_Error('madof_meta_http', sprintf(__('Meta respondió %1$d: %2$s', 'mad-oficina'), $code, $msg), ['status' => $code]);
            }
            $out = array_merge($out, $data['data'] ?? []);
            $url = (string) ($data['paging']['next'] ?? '');
        }
        return $out;
    }

    /** @return array[]|\WP_Error */
    public function accounts(){
        $rows = $this->get('me/adaccounts', ['fields' => 'account_id,name,currency,account_status,business_name']);
        if ( is_wp_error($rows) ) return $rows;
        return array_map(fn($r) => [
            'external_id' => (string) ($r['account_id'] ?? ltrim((string) ($r['id'] ?? ''), 'act_')),
            'name' => (string) ($r['name'] ?? '') . ( ! empty($r['business_name']) && stripos((string) $r['name'], (string) $r['business_name']) === false ? ' · ' . $r['business_name'] : '' ),
            'currency' => (string) ($r['currency'] ?? 'EUR'), 'status' => (int) ($r['account_status'] ?? 1) === 1 ? 'active' : 'inactive',
        ], $rows);
    }

    /** @return array[]|\WP_Error */
    public function campaigns($account){
        $rows = $this->get('act_' . $account . '/campaigns', ['fields' => 'id,name,status,effective_status,objective,bid_strategy,daily_budget,lifetime_budget,start_time,stop_time']);
        if ( is_wp_error($rows) ) return $rows;
        return array_map(function($r){
            $daily = isset($r['daily_budget']) && (float) $r['daily_budget'] > 0;
            $budget = $daily ? $r['daily_budget'] : ($r['lifetime_budget'] ?? null);
            return [
                'external_id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? ''), 'status' => strtolower((string) ($r['status'] ?? '')), // as she set it (effective_status moves by itself)
                'objective' => strtolower((string) ($r['objective'] ?? '')), 'bidding' => strtolower((string) ($r['bid_strategy'] ?? '')),
                'budget' => $budget !== null && (float) $budget > 0 ? round((float) $budget / 100, 2) : null, 'budget_period' => $daily ? 'day' : 'total', 'budget_ref' => '',
                'start_date' => ! empty($r['start_time']) ? substr($r['start_time'], 0, 10) : null, 'end_date' => ! empty($r['stop_time']) ? substr($r['stop_time'], 0, 10) : null,
            ];
        }, $rows);
    }

    /** @return array[]|\WP_Error */
    public function metrics($account, $from, $to){
        $rows = $this->get('act_' . $account . '/insights', ['level' => 'campaign', 'time_increment' => 1, 'time_range' => wp_json_encode(['since' => $from, 'until' => $to]),
            'fields' => 'campaign_id,date_start,impressions,clicks,spend,actions,action_values']);
        if ( is_wp_error($rows) ) return $rows;
        return array_map(fn($r) => [
            'campaign' => (string) ($r['campaign_id'] ?? ''), 'date' => (string) ($r['date_start'] ?? ''),
            'impressions' => (int) ($r['impressions'] ?? 0), 'clicks' => (int) ($r['clicks'] ?? 0), 'spend' => (float) ($r['spend'] ?? 0),
            'conversions' => self::conversion($r['actions'] ?? []), 'conversion_value' => self::conversion($r['action_values'] ?? []),
        ], $rows);
    }

    public static function conversion(array $actions){
        $by = array_column($actions, 'value', 'action_type');
        foreach ( self::CONVERSIONS as $t ) if ( isset($by[$t]) ) return (float) $by[$t];
        return 0.0;
    }

    /** The account's activity log, one row per change. @return array[]|\WP_Error see Changes::record() */
    public function changes($account, $from, $to){
        $since = strtotime($from . ' 00:00:00 ' . wp_timezone_string()); $until = strtotime($to . ' 23:59:59 ' . wp_timezone_string());
        $rows = $this->get('act_' . $account . '/activities', ['since' => $since, 'until' => $until,
            'fields' => 'event_time,event_type,translated_event_type,actor_id,actor_name,object_id,object_name,object_type,extra_data,application_name']);
        if ( is_wp_error($rows) ) return $rows;
        return array_map([self::class, 'from_activity'], $rows);
    }

    public static function from_activity(array $r){
        $extra = is_string($r['extra_data'] ?? null) ? json_decode($r['extra_data'], true) : ($r['extra_data'] ?? null);
        $type = (string) ($r['event_type'] ?? '');
        [$old, $new] = self::values(is_array($extra) ? $extra : []);
        $obj = strtolower((string) ($r['object_type'] ?? ''));
        return [
            'external_ref' => 'meta:' . ($r['object_id'] ?? '') . ':' . ($r['event_time'] ?? '') . ':' . $type,
            'changed_at' => wp_date('Y-m-d H:i:s', strtotime((string) ($r['event_time'] ?? 'now'))),
            'actor' => (string) ($r['actor_name'] ?? ''), 'source' => ! empty($r['application_name']) ? strtolower((string) $r['application_name']) : 'web',
            'automatic' => (string) ($r['actor_name'] ?? '') === '' || (bool) preg_match(self::AUTOMATIC, $type),
            'object_type' => $obj, 'object_name' => (string) ($r['object_name'] ?? ''), 'object_ref' => (string) ($r['object_id'] ?? ''),
            'campaign_ext' => $obj === 'campaign' ? (string) ($r['object_id'] ?? '') : '', 'budget_ref' => '',
            'operation' => 'update', 'field' => (string) ($r['translated_event_type'] ?? $type), 'old_value' => $old, 'new_value' => $new,
        ];
    }

    /** extra_data comes in several shapes: {old_value, new_value}, {old_value:{…}, new_value:{…}}, {currency, …}. */
    private static function values(array $x){
        $fmt = function($v){
            if ( is_array($v) ) {
                if ( isset($v['new_value']) && count($v) <= 3 ) $v = $v['new_value'];
                if ( is_array($v) ) return mb_substr(wp_json_encode($v, JSON_UNESCAPED_UNICODE), 0, 480);
            }
            return $v === null || $v === '' ? null : mb_substr((string) $v, 0, 480);
        };
        if ( array_key_exists('old_value', $x) || array_key_exists('new_value', $x) ) {
            $old = $fmt($x['old_value'] ?? null); $new = $fmt($x['new_value'] ?? null);
            // Budgets come in cents.
            if ( isset($x['type']) && str_contains((string) $x['type'], 'budget') ) {
                $old = is_numeric($old) ? (string) round($old / 100, 2) : $old; $new = is_numeric($new) ? (string) round($new / 100, 2) : $new;
            }
            return [$old, $new];
        }
        return [null, null];
    }
}
