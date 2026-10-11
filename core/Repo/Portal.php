<?php
namespace MAD_Oficina\Core\Repo;

use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Read model for the client portal. Every method takes a client_id that the
 * caller resolved server-side (ClientContext) and returns ONLY rows with
 * visibility = 'client'. Internal ids of team members, notes and margins are
 * never part of the output.
 */
final class Portal {

    const AREAS = [
        'web'        => 'Web',
        'ads'        => 'Anuncios',
        'diseno'     => 'Diseño',
        'contenidos' => 'Contenidos',
        'seo'        => 'SEO',
        'redes'      => 'Redes sociales',
        'video'      => 'Vídeo',
        'otros'      => 'Otros',
    ];

    const OWNERS = [
        'cliente' => 'Vosotros',
        'jessica' => 'Jessica',
        'liz'     => 'Liz',
        'mad'     => 'MAD',
    ];

    private static function visible(){
        return "visibility = '" . Schema::VISIBILITY_CLIENT . "'";
    }

    public static function timeline($client_id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, area, title, description, status, starts_at, ends_at FROM ' . Schema::table('processes')
            . ' WHERE client_id = %d AND ' . self::visible() . ' ORDER BY sort ASC, starts_at ASC',
            $client_id
        ), ARRAY_A);
        $areas = [];
        foreach ( $rows as $r ){
            $key = isset(self::AREAS[$r['area']]) ? $r['area'] : 'otros';
            $areas[$key] ??= ['area' => $key, 'label' => self::AREAS[$key], 'items' => []];
            $areas[$key]['items'][] = [
                'id'          => (int) $r['id'],
                'title'       => $r['title'],
                'description' => (string) $r['description'],
                'status'      => $r['status'],
                'starts_at'   => $r['starts_at'],
                'ends_at'     => $r['ends_at'],
            ];
        }
        return array_values($areas);
    }

    public static function meetings($client_id, $limit = 30){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, starts_at, summary, extraction, next_meeting, recording_file_id FROM ' . Schema::table('meetings')
            . " WHERE client_id = %d AND " . self::visible() . " AND status IN ('approved','archived') ORDER BY starts_at DESC LIMIT %d",
            $client_id, $limit
        ), ARRAY_A);
        return array_map(function($r){
            $extraction = $r['extraction'] ? (json_decode($r['extraction'], true) ?: []) : [];
            return [
                'id'           => (int) $r['id'],
                'title'        => $r['title'],
                'starts_at'    => $r['starts_at'],
                'summary'      => (string) $r['summary'],
                'agreements'   => array_values(array_filter(array_map('strval', (array) ($extraction['client_decisions'] ?? [])))),
                'next_meeting' => (string) $r['next_meeting'],
                'recording'    => $r['recording_file_id'] ? 'https://drive.google.com/file/d/' . rawurlencode($r['recording_file_id']) . '/view' : null,
            ];
        }, $rows);
    }

    /** Open tasks plus those closed in the last 30 days, split into "yours" and "ours". */
    public static function tasks($client_id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, description, owner, due_date, priority, status, completed_at FROM ' . Schema::table('action_items')
            . " WHERE client_id = %d AND " . self::visible()
            . " AND status <> 'cancelled' AND (status <> 'done' OR completed_at >= %s)"
            . ' ORDER BY (due_date IS NULL), due_date ASC, id ASC',
            $client_id, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)
        ), ARRAY_A);
        $out = ['client' => [], 'mad' => []];
        foreach ( $rows as $r ){
            $item = [
                'id'          => (int) $r['id'],
                'title'       => $r['title'],
                'description' => (string) $r['description'],
                'owner'       => self::OWNERS[$r['owner']] ?? 'MAD',
                'due_date'    => $r['due_date'],
                'priority'    => $r['priority'],
                'status'      => $r['status'],
                'overdue'     => $r['due_date'] && $r['status'] !== 'done' && $r['due_date'] < current_time('Y-m-d'),
            ];
            $out[$r['owner'] === 'cliente' ? 'client' : 'mad'][] = $item;
        }
        return $out;
    }

    public static function ideas($client_id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, description, resurface_at, status FROM ' . Schema::table('ideas')
            . " WHERE client_id = %d AND " . self::visible() . " AND status NOT IN ('discarded','converted') ORDER BY (resurface_at IS NULL), resurface_at ASC",
            $client_id
        ), ARRAY_A);
        return array_map(fn($r) => [
            'id'           => (int) $r['id'],
            'title'        => $r['title'],
            'description'  => (string) $r['description'],
            'resurface_at' => $r['resurface_at'],
        ], $rows);
    }

    public static function documents($client_id){
        $client = Clients::get($client_id);
        $folder = $client['drive_folder_id'] ?? '';
        if ( ! $folder ) return ['folder' => null];
        return ['folder' => [
            'url'   => 'https://drive.google.com/drive/folders/' . rawurlencode($folder),
            'embed' => 'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folder) . '#list',
        ]];
    }

    /** Tasks assigned to a team member (Liz) across clients, team- or client-visible. */
    public static function team_tasks($team_member_id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT a.id, a.title, a.description, a.due_date, a.priority, a.status, c.name AS client_name, c.code AS client_code FROM ' . Schema::table('action_items') . ' a'
            . ' LEFT JOIN ' . Schema::table('clients') . ' c ON c.id = a.client_id'
            . " WHERE a.team_member_id = %d AND a.visibility IN ('team','client') AND a.status NOT IN ('done','cancelled')"
            . ' ORDER BY (a.due_date IS NULL), a.due_date ASC',
            $team_member_id
        ), ARRAY_A);
        return array_map(fn($r) => [
            'id'          => (int) $r['id'],
            'title'       => $r['title'],
            'description' => (string) $r['description'],
            'due_date'    => $r['due_date'],
            'priority'    => $r['priority'],
            'status'      => $r['status'],
            'client'      => $r['client_code'] ? ['name' => $r['client_name'], 'code' => $r['client_code']] : null,
        ], $rows);
    }
}
