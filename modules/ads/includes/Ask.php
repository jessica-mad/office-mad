<?php
namespace MAD_Oficina\Ads;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Nuria's daily question: the changes nobody explained yet, by client, campaign and
 * day, each with a letter (A, B…) so the answer never mixes with the numbered task list
 * («la A porque convertía a 6 €; la B, prueba de 2 semanas»). Each change is asked once;
 * nothing new → no message. If someone else made the change, the question still goes to
 * Jessica.
 */
final class Ask {

    const LAST = 'madoficina_ads_ask_last';
    const DAY = 'madoficina_ads_ask_day';
    const DAYS_BACK = 14;
    const MAX = 12;

    public static function last(){
        $l = get_option(self::LAST, []);
        return is_array($l) && ! empty($l['items']) && strtotime($l['date']) > time() - 7 * DAY_IN_SECONDS ? $l : null;
    }

    /** @return array{groups:array[], text:string} what would be asked today */
    public static function compose($today){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ch.*, c.name AS campaign_name, cl.code AS client_code, cl.name AS client_name FROM ' . Changes::table() . ' ch'
            . ' LEFT JOIN ' . Schema::table('ad_campaigns') . ' c ON c.id = ch.campaign_id JOIN ' . Schema::table('clients') . ' cl ON cl.id = ch.client_id'
            . " WHERE ch.reason_at IS NULL AND ch.automatic = 0 AND ch.asked_at IS NULL AND ch.source <> 'manual' AND ch.changed_at >= %s ORDER BY cl.code, ch.changed_at, ch.id LIMIT 200",
            gmdate('Y-m-d', strtotime($today . ' -' . self::DAYS_BACK . ' days'))), ARRAY_A);
        $groups = [];
        foreach ( $rows as $r ) {
            $k = $r['client_id'] . '|' . ($r['campaign_id'] ?: $r['object_name']) . '|' . substr($r['changed_at'], 0, 10);
            $groups[$k]['client'] = $r['client_code'] . ' · ' . $r['client_name'];
            $groups[$k]['title'] = ($r['campaign_name'] ?: $r['object_name'] ?: __('Cuenta', 'mad-oficina')) . ' (' . (Accounts::PLATFORMS[$r['platform']] ?? $r['platform']) . ', ' . date_i18n('j/m', strtotime($r['changed_at'])) . ')';
            $groups[$k]['lines'][] = $r['summary'] . ( $r['actor'] && ! str_contains((string) $r['actor'], 'imad') ? ' · ' . sprintf(__('lo hizo %s', 'mad-oficina'), $r['actor']) : '' );
            $groups[$k]['ids'][] = (int) $r['id'];
        }
        $groups = array_slice(array_values($groups), 0, self::MAX);
        if ( ! $groups ) return ['groups' => [], 'text' => ''];
        $name = \MAD_Oficina\Core\Agents\Characters::get('analista')['name'] ?? 'Nuria';
        $text = '*' . sprintf(__('%s · cambios en campañas sin su porqué', 'mad-oficina'), $name) . "*\n";
        $client = '';
        foreach ( $groups as $i => $g ) {
            if ( $g['client'] !== $client ) { $client = $g['client']; $text .= "\n*" . $client . "*\n"; }
            $text .= chr(65 + $i) . '. ' . $g['title'] . ': ' . implode('; ', $g['lines']) . "\n";
        }
        $text .= "\n" . __('Cuéntame por qué (texto o audio), por ejemplo: «la A porque convertía a 6 € y sobraba presupuesto; la B, prueba de 2 semanas». Si de alguna no te acuerdas, dímelo y la dejo sin motivo.', 'mad-oficina')
            . "\n" . admin_url('admin.php?page=mad-oficina-campanas&tab=log&pending=1');
        return ['groups' => $groups, 'text' => $text];
    }

    /** Once a day from the hour in the settings (9 by default). @return bool sent */
    public static function run(array $settings, $now = null){
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(wp_timezone());
        $today = $local->format('Y-m-d');
        $hour = (int) ($settings['ask_hour'] ?? 9);
        if ( (int) $local->format('G') < $hour || get_option(self::DAY) === $today ) return false;
        update_option(self::DAY, $today, false);
        $c = self::compose($today);
        if ( ! $c['groups'] ) return false;
        $chat = \MAD_Oficina_Core::instance()->get_module('chat');
        if ( ! $chat ) return false;
        $r = $chat->messenger()->to_manager(['text' => $c['text']]);
        if ( is_wp_error($r) ) return false;
        global $wpdb;
        $items = [];
        foreach ( $c['groups'] as $i => $g ) {
            $items[chr(65 + $i)] = $g['ids'];
            $wpdb->query('UPDATE ' . Changes::table() . " SET asked_at = '" . esc_sql(Time::now()) . "' WHERE id IN (" . implode(',', array_map('intval', $g['ids'])) . ')');
        }
        update_option(self::LAST, ['date' => $today, 'items' => $items, 'text' => $c['text']], false);
        return true;
    }

    /** For the assistant: what Nuria asked lately, so «la A porque…» goes to her. */
    public static function context(){
        $l = self::last();
        if ( ! $l ) return '';
        return "\n\nMensaje que Nuria (Campañas) le mandó a Jessica el " . date_i18n('j/m', strtotime($l['date'])) . " (si contesta con letras, «la A porque…», es a esto: pásaselo a Nuria con ask_campanas tal cual):\n" . mb_substr($l['text'], 0, 1500);
    }
}
