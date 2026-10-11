<?php
namespace MAD_Oficina\Meetings;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Meetings table access.
 *
 * Statuses: received → processing → extracted → approved → archived
 *           (+ no_transcript, error, discarded)
 */
final class Meetings {

    const MAX_TRANSCRIPT = 600000; // characters (~150k tokens)

    public static function get($id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('meetings') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function find_by_source_key($key){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('meetings') . ' WHERE source_key = %s', $key), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function latest($limit = 50){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('meetings') . ' ORDER BY COALESCE(starts_at, created_at) DESC LIMIT %d', $limit), ARRAY_A);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * Creates a meeting, or updates it when the same source_key arrives again
     * (Apps Script retries, or a transcript that shows up after the recording).
     * @return int meeting id
     */
    public static function upsert(array $data){
        global $wpdb;
        $row = [
            'title'              => mb_substr(sanitize_text_field($data['title'] ?? '') ?: __('Reunión sin título', 'mad-oficina'), 0, 250),
            'source'             => in_array($data['source'] ?? '', ['apps_script', 'manual'], true) ? $data['source'] : 'manual',
            'starts_at'          => self::to_utc($data['starts_at'] ?? null),
            'ends_at'            => self::to_utc($data['ends_at'] ?? null),
            'calendar_event_id'  => self::id_or_null($data['calendar_event_id'] ?? null),
            'attendees'          => wp_json_encode(Identifier::clean_attendees((array) ($data['attendees'] ?? []))),
            'recording_file_id'  => self::id_or_null($data['recording_file_id'] ?? null),
            'transcript_file_id' => self::id_or_null($data['transcript_file_id'] ?? null),
            'gemini_notes_id'    => self::id_or_null($data['gemini_notes_id'] ?? null),
            'drive_folder_id'    => self::id_or_null($data['drive_folder_id'] ?? null),
            'sheet_row'          => isset($data['sheet_row']) ? (int) $data['sheet_row'] : null,
            'transcript'         => mb_substr(wp_check_invalid_utf8((string) ($data['transcript'] ?? '')), 0, self::MAX_TRANSCRIPT),
            'updated_at'         => Time::now(),
        ];
        if ( ! empty($data['client_id']) ) $row['client_id'] = (int) $data['client_id'];

        $key = isset($data['source_key']) ? mb_substr(sanitize_text_field($data['source_key']), 0, 190) : '';
        $existing = $key ? self::find_by_source_key($key) : null;
        if ( $existing ) {
            // Never overwrite a meeting Jessica already approved; only fill in missing pieces.
            if ( in_array($existing['status'], ['approved', 'archived'], true) ) {
                $row = array_intersect_key($row, array_flip(['updated_at', 'sheet_row']));
            } elseif ( $row['transcript'] === '' ) {
                unset($row['transcript']);
            }
            $wpdb->update(Schema::table('meetings'), $row, ['id' => $existing['id']]);
            return $existing['id'];
        }

        $row['source_key'] = $key ?: null;
        $row['status'] = 'received';
        $row['created_at'] = Time::now();
        $wpdb->insert(Schema::table('meetings'), $row);
        return (int) $wpdb->insert_id;
    }

    public static function update($id, array $fields){
        global $wpdb;
        foreach ( ['extraction', 'shared_with'] as $json ){
            if ( isset($fields[$json]) && is_array($fields[$json]) ) $fields[$json] = wp_json_encode($fields[$json]);
        }
        $fields['updated_at'] = Time::now();
        $wpdb->update(Schema::table('meetings'), $fields, ['id' => (int) $id]);
    }

    public static function to_utc($value){
        if ( ! $value ) return null;
        try {
            $dt = new \DateTimeImmutable((string) $value, wp_timezone());
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /** Local date (site timezone) of the meeting, Y-m-d. */
    public static function local_date($meeting){
        $utc = $meeting['starts_at'] ?: $meeting['created_at'];
        return get_date_from_gmt($utc, 'Y-m-d');
    }

    private static function id_or_null($v){
        $v = preg_replace('/[^A-Za-z0-9_\-@.]/', '', (string) $v);
        return $v !== '' ? $v : null;
    }

    private static function hydrate($row){
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] ? (int) $row['client_id'] : null;
        $row['attendees'] = $row['attendees'] ? (json_decode($row['attendees'], true) ?: []) : [];
        $row['extraction'] = $row['extraction'] ? (json_decode($row['extraction'], true) ?: []) : [];
        $row['shared_with'] = $row['shared_with'] ? (json_decode($row['shared_with'], true) ?: []) : [];
        return $row;
    }
}
