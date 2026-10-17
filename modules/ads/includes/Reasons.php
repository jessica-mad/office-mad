<?php
namespace MAD_Oficina\Ads;

if ( ! defined('ABSPATH') ) exit;

/**
 * Reasons said before the change shows up («en LCS voy a subir el presupuesto de
 * rebajas porque convierte bien»): they wait up to a week for their change and join
 * every change of that campaign on the day it appears.
 */
final class Reasons {

    const OPTION = 'madoficina_ads_reasons';
    const DAYS = 7;

    private static function all(){ $r = get_option(self::OPTION, []); return is_array($r) ? $r : []; }

    /** @param string $hint words of the campaign (empty: any change of that client) */
    public static function add($client_id, $hint, $reason, $source = 'chat', $user_id = 0, $now = null){
        $list = array_values(array_filter(self::all(), fn($r) => strtotime($r['said_at']) > ($now ?? time()) - self::DAYS * DAY_IN_SECONDS));
        $list[] = ['client_id' => (int) $client_id, 'hint' => sanitize_text_field($hint), 'reason' => sanitize_textarea_field($reason), 'source' => $source, 'by' => (int) $user_id,
            'said_at' => wp_date('Y-m-d H:i:s', $now ?? time())];
        update_option(self::OPTION, array_slice($list, -100), false);
    }

    /** @return array{reason:string, source:string, by:int}|null */
    public static function waiting_for(array $change, ?array $campaign){
        if ( ! $change['client_id'] ) return null;
        $when = strtotime($change['changed_at']);
        $text = self::norm(($campaign['name'] ?? '') . ' ' . $change['object_name']);
        foreach ( array_reverse(self::all()) as $r ) {
            if ( (int) $r['client_id'] !== (int) $change['client_id'] ) continue;
            $said = strtotime($r['said_at']);
            if ( $when < $said - DAY_IN_SECONDS || $when > $said + self::DAYS * DAY_IN_SECONDS ) continue;
            $words = array_filter(explode(' ', self::norm($r['hint'])), fn($w) => strlen($w) >= 4);
            if ( $words && ! array_filter($words, fn($w) => strpos(' ' . $text . ' ', ' ' . $w) !== false) ) continue;
            return ['reason' => $r['reason'], 'source' => $r['source'], 'by' => (int) $r['by']];
        }
        return null;
    }

    private static function norm($s){ return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(remove_accents((string) $s))))); }
}
