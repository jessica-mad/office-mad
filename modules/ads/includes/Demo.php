<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Invented accounts, campaigns, metrics and changes for the demo clients, to try the
 * log before the real connections exist. Their ids start with «demo-».
 */
final class Demo {

    public static function seed(){
        self::purge();
        $today = current_time('Y-m-d');
        $n = 0;
        foreach ( \MAD_Oficina\Core\Repo\Clients::all(['is_demo' => true]) as $i => $client ) {
            foreach ( ['google', 'meta'] as $p ) {
                $acc_id = Accounts::upsert($p, ['external_id' => 'demo-' . $p . '-' . $client['code'], 'name' => $client['name'] . ' · ' . Accounts::PLATFORMS[$p], 'currency' => 'EUR']);
                Accounts::link($acc_id, (int) $client['id']);
                $camps = $p === 'google'
                    ? [['external_id' => 'demo-g1-' . $i, 'name' => 'Búsqueda · Marca', 'status' => 'enabled', 'objective' => 'search', 'bidding' => 'maximize_conversions', 'budget' => 15, 'budget_period' => 'day'],
                       ['external_id' => 'demo-g2-' . $i, 'name' => 'Búsqueda · Temporada', 'status' => 'enabled', 'objective' => 'search', 'bidding' => 'target_cpa', 'budget' => 30, 'budget_period' => 'day']]
                    : [['external_id' => 'demo-m1-' . $i, 'name' => 'Leads · Otoño', 'status' => 'active', 'objective' => 'outcome_leads', 'bidding' => 'lowest_cost_without_cap', 'budget' => 20, 'budget_period' => 'day']];
                Accounts::save_campaigns($acc_id, $camps);
                $metrics = [];
                foreach ( $camps as $k => $c ) {
                    for ( $d = 40; $d >= 1; $d-- ) {
                        $date = gmdate('Y-m-d', strtotime($today . " -{$d} days"));
                        $boost = $d < 12 ? 1.4 : 1.0;
                        $metrics[] = ['campaign' => $c['external_id'], 'date' => $date, 'impressions' => (int) (900 * $boost) + $d * 7, 'clicks' => (int) (40 * $boost) + $d % 5,
                            'spend' => round($c['budget'] * $boost * (0.85 + ($d % 3) / 10), 2), 'conversions' => round((2 + $k) * $boost + ($d % 4) / 2, 1), 'conversion_value' => 0];
                    }
                }
                Accounts::save_metrics($acc_id, $metrics);
                $acc = Accounts::get($acc_id);
                $at = fn($days, $h) => gmdate('Y-m-d', strtotime($today . " -{$days} days")) . ' ' . $h;
                $rows = $p === 'google' ? [
                    ['external_ref' => 'demo-1', 'campaign_ext' => $camps[1]['external_id'], 'changed_at' => $at(12, '10:14:00'), 'actor' => 'jessica@imad.pro', 'source' => 'web', 'object_type' => 'campaign_budget', 'object_name' => $camps[1]['name'], 'field' => 'amount_micros', 'old_value' => '20', 'new_value' => '30'],
                    ['external_ref' => 'demo-2', 'campaign_ext' => $camps[1]['external_id'], 'changed_at' => $at(12, '10:16:00'), 'actor' => 'jessica@imad.pro', 'source' => 'web', 'object_type' => 'campaign', 'object_name' => $camps[1]['name'], 'field' => 'target_cpa.target_cpa_micros', 'old_value' => '12', 'new_value' => '9'],
                    ['external_ref' => 'demo-3', 'campaign_ext' => $camps[0]['external_id'], 'changed_at' => $at(3, '17:40:00'), 'actor' => 'jessica@imad.pro', 'source' => 'editor', 'object_type' => 'ad_group_criterion', 'object_name' => 'gratis', 'field' => 'create', 'old_value' => null, 'new_value' => null],
                    ['external_ref' => 'demo-4', 'campaign_ext' => $camps[0]['external_id'], 'changed_at' => $at(2, '04:00:00'), 'actor' => '', 'source' => 'recommendation', 'automatic' => true, 'object_type' => 'ad_group_ad', 'object_name' => 'Anuncio adaptable', 'field' => 'create', 'old_value' => null, 'new_value' => null],
                ] : [
                    ['external_ref' => 'demo-5', 'campaign_ext' => $camps[0]['external_id'], 'changed_at' => $at(9, '12:05:00'), 'actor' => 'Jessica', 'source' => 'web', 'object_type' => 'campaign', 'object_name' => $camps[0]['name'], 'field' => 'Presupuesto de campaña actualizado', 'old_value' => '15', 'new_value' => '20'],
                    ['external_ref' => 'demo-6', 'campaign_ext' => $camps[0]['external_id'], 'changed_at' => $at(1, '09:00:00'), 'actor' => '', 'source' => 'web', 'automatic' => true, 'object_type' => 'ad', 'object_name' => 'Carrusel otoño', 'field' => 'Anuncio aprobado', 'old_value' => null, 'new_value' => null],
                ];
                $n += Changes::record($acc, $rows);
            }
        }
        Changes::fill_after($today);
        // One with its why, to see how it looks.
        global $wpdb;
        $first = (int) $wpdb->get_var('SELECT ch.id FROM ' . Changes::table() . ' ch JOIN ' . Schema::table('ad_accounts') . " a ON a.id = ch.account_id WHERE a.external_id LIKE 'demo-%' AND ch.field = 'amount_micros' ORDER BY ch.id LIMIT 1");
        if ( $first ) Changes::set_reason([$first], 'Temporada alta: convertía a 7 € y se quedaba sin presupuesto a mediodía.', 'chat');
        return $n;
    }

    public static function purge(){
        global $wpdb;
        $ids = $wpdb->get_col('SELECT id FROM ' . Schema::table('ad_accounts') . " WHERE external_id LIKE 'demo-%'");
        if ( ! $ids ) return;
        $in = implode(',', array_map('intval', $ids));
        $wpdb->query('DELETE FROM ' . Schema::table('ad_metrics_daily') . ' WHERE campaign_id IN (SELECT id FROM ' . Schema::table('ad_campaigns') . " WHERE account_id IN ({$in}))");
        $wpdb->query('DELETE FROM ' . Schema::table('ad_changes') . " WHERE account_id IN ({$in})");
        $wpdb->query('DELETE FROM ' . Schema::table('ad_campaigns') . " WHERE account_id IN ({$in})");
        $wpdb->query('DELETE FROM ' . Schema::table('ad_accounts') . " WHERE id IN ({$in})");
    }
}
