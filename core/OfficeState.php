<?php
namespace MAD_Oficina\Core;

use MAD_Oficina\Core\Agents\Characters;

if ( ! defined('ABSPATH') ) exit;

/**
 * What each character is doing, derived from the approvals and tasks tables.
 * Only states are exposed (no titles, no counts), so it is safe for clients.
 *
 * States: idle, caminando (client-side only), trabajando, esperando_gerencia.
 */
final class OfficeState {

    const IDLE    = 'idle';
    const WORKING = 'trabajando';
    const WAITING = 'esperando_gerencia';

    /** Which character speaks for each agent slug used in tasks/approvals. */
    const AGENT_TO_CHARACTER = [
        'receptionist' => 'recepcionista',
        'secretary'    => 'secretaria',
        'coordinator'  => 'coordinadora',
        'archivist'    => 'archivera',
        'accountant'   => 'contable',
        'analyst'      => 'analista',
        'scheduler'    => 'recepcionista',
    ];

    /** @return array<string,string> character slug => state */
    public static function compute(){
        global $wpdb;
        $states = array_fill_keys(array_keys(Characters::defaults()), self::IDLE);
        $states['jessica'] = self::WORKING;

        $working = $wpdb->get_col("SELECT DISTINCT agent FROM " . Schema::table('tasks') . " WHERE status = 'running'");
        foreach ( $working as $agent ){
            $slug = self::AGENT_TO_CHARACTER[$agent] ?? null;
            if ( $slug ) $states[$slug] = self::WORKING;
        }

        if ( $wpdb->get_var("SELECT 1 FROM " . Schema::table('meetings') . " WHERE status = 'processing' LIMIT 1") ) {
            $states['secretaria'] = self::WORKING;
        }

        $waiting = $wpdb->get_col("SELECT DISTINCT agent FROM " . Schema::table('approvals') . " WHERE status = 'pending'");
        foreach ( $waiting as $agent ){
            $slug = self::AGENT_TO_CHARACTER[$agent] ?? null;
            if ( $slug ) $states[$slug] = self::WAITING;
        }

        $liz_busy = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . Schema::table('action_items') . " WHERE owner = 'liz' AND status IN ('open','in_progress')"
        );
        if ( $liz_busy ) $states['liz'] = self::WORKING;

        return $states;
    }
}
