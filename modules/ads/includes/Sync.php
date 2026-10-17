<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Nuria's daily read (read-only), a few accounts per tick so no request runs long:
 *  · campaigns (and what changed in them since yesterday),
 *  · metrics of the last days (conversions arrive late, so 3 days are read again),
 *  · the change history since the last read (Google keeps 30 days, Meta a bit more).
 * Only accounts linked to a client are read.
 */
final class Sync {

    const FIRST_DAYS = 29;
    const METRICS_FIRST = 37;
    const METRICS_AGAIN = 3;
    const PER_TICK = 4;
    const START_HOUR = 6;

    private $s;

    public function __construct(array $settings){ $this->s = $settings; }

    public function google(){ return new GoogleAds($this->s); }
    public function meta(){ return new Meta($this->s); }

    private function client($platform){ return $platform === 'google' ? $this->google() : $this->meta(); }

    /** On every tick: the linked accounts not read today. @return int accounts read */
    public function run($now = null){
        global $wpdb;
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(wp_timezone());
        if ( (int) $local->format('G') < self::START_HOUR ) return 0;
        $today = $local->format('Y-m-d');
        $due = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('ad_accounts')
            . " WHERE client_id IS NOT NULL AND tracking = 1 AND status = 'active' AND external_id NOT LIKE 'import-%%' AND external_id NOT LIKE 'demo-%%' AND (last_sync IS NULL OR last_sync < %s) ORDER BY last_sync IS NOT NULL, last_sync LIMIT %d", $today . ' 00:00:00', self::PER_TICK), ARRAY_A);
        $n = 0;
        foreach ( $due as $a ) {
            if ( ! $this->ready($a['platform']) ) continue;
            $this->account($a, $today);
            $n++;
        }
        if ( $n ) Changes::fill_after($today);
        return $n;
    }

    public function ready($platform){
        return $platform === 'google' ? $this->google()->ready() : $this->meta()->ready();
    }

    /** Finds the accounts on both platforms (new ones wait to be linked). @return array{found:int, errors:string[]} */
    public function discover(){
        $found = 0; $errors = [];
        foreach ( ['google', 'meta'] as $p ) {
            if ( ! $this->ready($p) ) continue;
            $list = $this->client($p)->accounts();
            if ( is_wp_error($list) ) { $errors[] = Accounts::PLATFORMS[$p] . ': ' . $list->get_error_message(); continue; }
            foreach ( $list as $a ) {
                if ( $a['external_id'] === '' ) continue;
                $id = Accounts::upsert($p, $a);
                $acc = Accounts::get($id);
                if ( ! $acc['client_id'] && ( $c = Accounts::suggest($a['name']) ) ) Accounts::link($id, $c);
                $found++;
            }
        }
        return ['found' => $found, 'errors' => $errors];
    }

    /** One account, now. @return array{campaigns:int, days:int, changes:int}|\WP_Error */
    public function account(array $a, $today = null){
        global $wpdb;
        $today = $today ?: current_time('Y-m-d');
        $api = $this->client($a['platform']);
        $fail = function($e) use ($wpdb, $a){
            $wpdb->update(Schema::table('ad_accounts'), ['last_sync' => Time::now(), 'last_error' => mb_substr($e->get_error_message(), 0, 255)], ['id' => (int) $a['id']]);
            $this->log('sync_error', $a, ['error' => $e->get_error_message()]);
            return $e;
        };

        $campaigns = $api->campaigns($a['external_id']);
        if ( is_wp_error($campaigns) ) return $fail($campaigns);
        $diffs = Accounts::save_campaigns((int) $a['id'], $campaigns);

        $first = ! $a['last_sync'];
        $from = gmdate('Y-m-d', strtotime($today . ' -' . ($first ? self::METRICS_FIRST : self::METRICS_AGAIN) . ' days'));
        $metrics = $api->metrics($a['external_id'], $from, gmdate('Y-m-d', strtotime($today . ' -1 day')));
        if ( is_wp_error($metrics) ) return $fail($metrics);
        $days = Accounts::save_metrics((int) $a['id'], $metrics);

        $since = $first ? gmdate('Y-m-d', strtotime($today . ' -' . self::FIRST_DAYS . ' days'))
            : max(gmdate('Y-m-d', strtotime(substr($a['last_sync'], 0, 10) . ' -1 day')), gmdate('Y-m-d', strtotime($today . ' -' . self::FIRST_DAYS . ' days')));
        $history = $api->changes($a['external_id'], $since, $today);
        if ( is_wp_error($history) ) return $fail($history);
        $new = Changes::record($a, $history);
        $new += Changes::record($a, $this->unexplained($a, $diffs, $history, $today));

        $wpdb->update(Schema::table('ad_accounts'), ['last_sync' => Time::now(), 'last_error' => null], ['id' => (int) $a['id']]);
        $this->log('synced', $a, ['campaigns' => count($campaigns), 'days' => $days, 'changes' => $new]);
        return ['campaigns' => count($campaigns), 'days' => $days, 'changes' => $new];
    }

    /**
     * Differences with yesterday's read that the history doesn't explain (Meta's log
     * without values, or a change older than the history): they become changes too.
     */
    private function unexplained(array $a, array $diffs, array $history, $today){
        global $wpdb;
        $out = [];
        foreach ( $diffs as $d ) {
            $explained = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Changes::table() . ' WHERE campaign_id = %d AND automatic = 0 AND changed_at >= %s LIMIT 1',
                $d['campaign_id'], gmdate('Y-m-d', strtotime($today . ' -2 days'))));
            if ( $explained ) {
                // The log said «budget updated» without numbers: now we know them.
                $wpdb->query($wpdb->prepare('UPDATE ' . Changes::table() . ' SET old_value = %s, new_value = %s WHERE id = %d AND old_value IS NULL AND new_value IS NULL',
                    (string) $d['old'], (string) $d['new'], $explained));
                continue;
            }
            $out[] = ['external_ref' => 'diff:' . $d['campaign_id'] . ':' . $d['field'] . ':' . $today, 'campaign_id' => $d['campaign_id'],
                'changed_at' => $today . ' 00:00:00', 'actor' => '', 'source' => 'sync', 'automatic' => false,
                'object_type' => 'campaign', 'object_name' => $d['name'], 'field' => $d['field'], 'old_value' => $d['old'] === null ? null : (string) $d['old'], 'new_value' => $d['new'] === null ? null : (string) $d['new']];
        }
        return $out;
    }

    private function log($event, array $a, array $data){
        global $wpdb;
        $wpdb->insert(Schema::table('agent_log'), ['agent' => 'analista', 'event' => $event, 'client_id' => $a['client_id'] ?: null,
            'data' => wp_json_encode(['account' => $a['name'], 'platform' => $a['platform']] + $data), 'created_at' => Time::now()]);
    }
}
