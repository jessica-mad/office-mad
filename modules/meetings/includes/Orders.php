<?php
namespace MAD_Oficina\Meetings;

use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Work the Apps Script ("el cartero") picks up: only orders created after an
 * approval. Stored in the tasks table with agent = 'apps_script'.
 */
final class Orders {

    const AGENT = 'apps_script';

    /** Any other module's order for the cartero (e.g. billing: archive and send an invoice). */
    public static function queue($type, array $payload, $client_id = null){
        global $wpdb;
        $wpdb->insert(Schema::table('tasks'), [
            'agent' => self::AGENT, 'type' => sanitize_key($type), 'client_id' => $client_id,
            'payload' => wp_json_encode($payload), 'status' => 'queued',
            'created_at' => Time::now(), 'updated_at' => Time::now(),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function queue_archive(array $meeting, array $client, $share_recording, $share_notes){
        global $wpdb;
        $emails = [];
        foreach ( Clients::contacts($client['id']) as $c ){
            if ( ! $c['receives_summaries'] ) continue;
            $work = array_values(array_filter($c['emails'], fn($e) => $e['kind'] === 'work'));
            $pick = $work[0]['email'] ?? ($c['emails'][0]['email'] ?? null);
            if ( $pick ) $emails[] = $pick;
        }
        $wpdb->insert(Schema::table('tasks'), [
            'agent'     => self::AGENT,
            'type'      => 'meeting_archive',
            'client_id' => $client['id'],
            'payload'   => wp_json_encode([
                'meeting_id'        => $meeting['id'],
                'source_key'        => $meeting['source_key'],
                'title'             => $meeting['title'],
                'date'              => Meetings::local_date($meeting),
                'drive_folder_id'   => $meeting['drive_folder_id'],
                'recording_file_id' => $meeting['recording_file_id'],
                'notes_file_ids'    => array_values(array_filter([$meeting['gemini_notes_id'], $meeting['transcript_file_id']])),
                'client'            => ['code' => $client['code'], 'name' => $client['name'], 'drive_folder_id' => $client['drive_folder_id'] ?: null],
                'share_recording'   => $share_recording ? $emails : [],
                'share_notes'       => $share_notes ? $emails : [],
                'sheet_row'         => $meeting['sheet_row'],
                'summary'           => wp_trim_words((string) $meeting['summary'], 60),
            ]),
            'status'     => 'queued',
            'created_at' => Time::now(),
            'updated_at' => Time::now(),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function pending(){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, payload, attempts FROM ' . Schema::table('tasks') . " WHERE agent = %s AND status = 'queued' AND attempts < 5 ORDER BY id ASC LIMIT 20",
            self::AGENT
        ), ARRAY_A);
        if ( $rows ) {
            $wpdb->query('UPDATE ' . Schema::table('tasks') . ' SET attempts = attempts + 1 WHERE id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ')');
        }
        return array_map(fn($r) => ['id' => (int) $r['id'], 'type' => $r['type'], 'payload' => json_decode($r['payload'], true)], $rows);
    }

    /** @return true|\WP_Error */
    public static function complete($id, $ok, array $result){
        global $wpdb;
        $task = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('tasks') . ' WHERE id = %d AND agent = %s', $id, self::AGENT), ARRAY_A);
        if ( ! $task ) return new \WP_Error('madof_order', 'Unknown order', ['status' => 404]);
        if ( $task['status'] !== 'queued' ) return true; // idempotent

        $wpdb->update(Schema::table('tasks'), [
            'status' => $ok ? 'done' : 'failed', 'result' => wp_json_encode($result), 'updated_at' => Time::now(),
        ], ['id' => $id]);

        if ( $ok && $task['type'] === 'meeting_archive' ) {
            $payload = json_decode($task['payload'], true);
            $fields = ['status' => 'archived', 'shared_with' => array_values(array_unique(array_merge($payload['share_recording'] ?? [], $payload['share_notes'] ?? [])))];
            if ( ! empty($result['folder_id']) ) $fields['drive_folder_id'] = preg_replace('/[^A-Za-z0-9_\-]/', '', $result['folder_id']);
            Meetings::update((int) $payload['meeting_id'], $fields);
        }
        do_action('madoficina_order_done', $task['type'], json_decode($task['payload'], true) ?: [], $ok, $result);
        return true;
    }
}
