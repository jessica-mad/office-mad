<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Ad accounts (linked to a client), their campaigns and daily metrics, as last read.
 */
final class Accounts {

    const PLATFORMS = ['google' => 'Google Ads', 'meta' => 'Meta'];
    /** Compared every day: a difference nobody explained becomes a change in the log. */
    const WATCHED = ['name', 'status', 'budget', 'bidding', 'end_date'];

    public static function t($name){ return Schema::table($name); }

    /** Found on the platform: created, or its name/currency/status refreshed. @return int id */
    public static function upsert($platform, array $a){
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t('ad_accounts') . ' WHERE platform = %s AND external_id = %s', $platform, $a['external_id']));
        $row = ['name' => mb_substr((string) $a['name'], 0, 190), 'currency' => strtoupper(substr((string) ($a['currency'] ?: 'EUR'), 0, 3)), 'status' => $a['status'] ?? 'active', 'updated_at' => Time::now()];
        if ( $id ) { $wpdb->update(self::t('ad_accounts'), $row, ['id' => $id]); return $id; }
        $wpdb->insert(self::t('ad_accounts'), $row + ['platform' => $platform, 'external_id' => $a['external_id'], 'created_at' => Time::now()]);
        return (int) $wpdb->insert_id;
    }

    public static function get($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('ad_accounts') . ' WHERE id = %d', $id), ARRAY_A) ?: null;
    }

    /** @param array{client_id?:int, linked?:bool, platform?:string} $f */
    public static function all(array $f = []){
        global $wpdb;
        $w = ['1=1'];
        if ( isset($f['client_id']) ) $w[] = $wpdb->prepare('client_id = %d', $f['client_id']);
        if ( isset($f['linked']) ) $w[] = $f['linked'] ? 'client_id IS NOT NULL' : 'client_id IS NULL';
        if ( ! empty($f['platform']) ) $w[] = $wpdb->prepare('platform = %s', $f['platform']);
        return $wpdb->get_results('SELECT * FROM ' . self::t('ad_accounts') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY (client_id IS NULL), name', ARRAY_A);
    }

    public static function link($id, $client_id){
        global $wpdb;
        $wpdb->update(self::t('ad_accounts'), ['client_id' => $client_id ?: null, 'updated_at' => Time::now()], ['id' => (int) $id]);
        $wpdb->update(self::t('ad_changes'), ['client_id' => $client_id ?: null], ['account_id' => (int) $id]);
    }

    /** The client whose code or main name word the account's name contains, if only one does. */
    public static function suggest($name){
        $norm = fn($x) => ' ' . trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(remove_accents((string) $x))))) . ' ';
        $n = $norm($name);
        $hits = [];
        foreach ( \MAD_Oficina\Core\Repo\Clients::all() as $c ) {
            $words = array_values(array_filter(explode(' ', trim($norm($c['name']))), fn($w) => strlen($w) >= 4 && ! in_array($w, ['cliente', 'empresa', 'grupo'], true)));
            $code = strtolower((string) $c['code']);
            if ( ($code !== '' && strpos($n, ' ' . $code . ' ') !== false) || ($words && strpos($n, ' ' . $words[0] . ' ') !== false) ) $hits[] = (int) $c['id'];
        }
        $hits = array_values(array_unique($hits));
        return count($hits) === 1 ? $hits[0] : null;
    }

    /* ───────── campaigns ───────── */

    /**
     * Saves what the platform says now. @return array[] what changed since the last read
     * [campaign_id, field, old, new] (nothing on the first read).
     */
    public static function save_campaigns($account_id, array $rows){
        global $wpdb;
        $diffs = [];
        foreach ( $rows as $c ) {
            if ( empty($c['external_id']) ) continue;
            $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('ad_campaigns') . ' WHERE account_id = %d AND external_id = %s', $account_id, $c['external_id']), ARRAY_A);
            $row = array_intersect_key($c, array_flip(['name', 'status', 'objective', 'bidding', 'budget', 'budget_period', 'budget_ref', 'start_date', 'end_date'])) + ['updated_at' => Time::now()];
            $row['name'] = mb_substr((string) $row['name'], 0, 255);
            if ( ! $old ) {
                $wpdb->insert(self::t('ad_campaigns'), $row + ['account_id' => $account_id, 'external_id' => $c['external_id'], 'created_at' => Time::now()]);
                continue;
            }
            foreach ( self::WATCHED as $f ) {
                if ( ! array_key_exists($f, $row) ) continue;
                $a = $old[$f]; $b = $row[$f];
                $same = $f === 'budget' ? ( $a === null && $b === null ) || ( $a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005 ) : (string) $a === (string) $b;
                if ( ! $same ) $diffs[] = ['campaign_id' => (int) $old['id'], 'field' => $f, 'old' => $a, 'new' => $b, 'name' => $row['name']];
            }
            $wpdb->update(self::t('ad_campaigns'), $row, ['id' => (int) $old['id']]);
        }
        return $diffs;
    }

    public static function campaign($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT c.*, a.platform, a.client_id, a.currency FROM ' . self::t('ad_campaigns') . ' c JOIN ' . self::t('ad_accounts') . ' a ON a.id = c.account_id WHERE c.id = %d', $id), ARRAY_A) ?: null;
    }

    /** By the platform's id (or, for a Google budget, the budget it uses). */
    public static function campaign_id($account_id, $external_id, $budget_ref = ''){
        global $wpdb;
        if ( $external_id !== '' ) return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t('ad_campaigns') . ' WHERE account_id = %d AND external_id = %s', $account_id, $external_id)) ?: null;
        if ( $budget_ref !== '' ) return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::t('ad_campaigns') . ' WHERE account_id = %d AND budget_ref = %s ORDER BY id LIMIT 1', $account_id, $budget_ref)) ?: null;
        return null;
    }

    /** A client's campaigns with their last 30 days. */
    public static function campaigns_of($client_id, $today = null){
        global $wpdb;
        $today = $today ?: current_time('Y-m-d');
        $from = gmdate('Y-m-d', strtotime($today . ' -30 days'));
        return $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, a.platform, a.currency, a.name AS account_name, SUM(m.impressions) impressions, SUM(m.clicks) clicks, SUM(m.spend) spend, SUM(m.conversions) conversions, SUM(m.conversion_value) conversion_value'
            . ' FROM ' . self::t('ad_campaigns') . ' c JOIN ' . self::t('ad_accounts') . ' a ON a.id = c.account_id'
            . ' LEFT JOIN ' . self::t('ad_metrics_daily') . ' m ON m.campaign_id = c.id AND m.date BETWEEN %s AND %s'
            . ' WHERE a.client_id = %d GROUP BY c.id ORDER BY (c.status IN (\'enabled\',\'active\')) DESC, spend DESC, c.name', $from, $today, $client_id), ARRAY_A);
    }

    public static function save_notes($campaign_id, $notes){
        global $wpdb;
        $wpdb->update(self::t('ad_campaigns'), ['notes' => $notes !== '' ? $notes : null, 'updated_at' => Time::now()], ['id' => (int) $campaign_id]);
    }

    /* ───────── metrics ───────── */

    /** @return int days saved */
    public static function save_metrics($account_id, array $rows){
        global $wpdb;
        $n = 0; $ids = [];
        foreach ( $rows as $r ) {
            if ( ! isset($ids[$r['campaign']]) ) $ids[$r['campaign']] = self::campaign_id($account_id, (string) $r['campaign']);
            if ( ! $ids[$r['campaign']] || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date']) ) continue;
            $wpdb->replace(self::t('ad_metrics_daily'), ['campaign_id' => $ids[$r['campaign']], 'date' => $r['date'], 'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks'],
                'spend' => round((float) $r['spend'], 2), 'conversions' => round((float) $r['conversions'], 2), 'conversion_value' => round((float) $r['conversion_value'], 2)]);
            $n++;
        }
        return $n;
    }

    /**
     * Totals of a campaign (or of a whole account) between two dates, with the ratios.
     * @return array{days:int, spend:float, clicks:int, impressions:int, conversions:float, value:float, cpa:?float, ctr:?float, cpc:?float}
     */
    public static function window($campaign_id, $account_id, $from, $to){
        global $wpdb;
        $where = $campaign_id ? $wpdb->prepare('m.campaign_id = %d', $campaign_id)
            : $wpdb->prepare('m.campaign_id IN (SELECT id FROM ' . self::t('ad_campaigns') . ' WHERE account_id = %d)', $account_id);
        $r = $wpdb->get_row($wpdb->prepare('SELECT COUNT(DISTINCT m.date) days, SUM(m.spend) spend, SUM(m.clicks) clicks, SUM(m.impressions) impressions, SUM(m.conversions) conversions, SUM(m.conversion_value) value FROM '
            . self::t('ad_metrics_daily') . " m WHERE {$where} AND m.date BETWEEN %s AND %s", $from, $to), ARRAY_A);
        $w = ['days' => (int) $r['days'], 'spend' => round((float) $r['spend'], 2), 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'conversions' => round((float) $r['conversions'], 2), 'value' => round((float) $r['value'], 2)];
        $w['cpa'] = $w['conversions'] > 0 ? round($w['spend'] / $w['conversions'], 2) : null;
        $w['ctr'] = $w['impressions'] > 0 ? round($w['clicks'] / $w['impressions'] * 100, 2) : null;
        $w['cpc'] = $w['clicks'] > 0 ? round($w['spend'] / $w['clicks'], 2) : null;
        return $w;
    }
}
