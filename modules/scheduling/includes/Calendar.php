<?php
namespace MAD_Oficina\Scheduling;

if ( ! defined('ABSPATH') ) exit;

/**
 * Google Calendar with Jessica's OAuth connection (Conexiones). Availability is
 * always read live (freeBusy), never cached.
 */
final class Calendar {

    const BASE = 'https://www.googleapis.com/calendar/v3/';
    const SCOPES = [
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];

    /** @return \MAD_Oficina\Google\OAuth|null */
    private static function oauth(){
        $google = \MAD_Oficina_Core::instance()->get_module('google');
        return $google ? $google->oauth() : null;
    }

    public static function is_ready(){
        $o = self::oauth();
        return $o && $o->is_connected() && ! array_intersect(self::SCOPES, $o->missing_scopes());
    }

    /** @return array|\WP_Error */
    private static function request($method, $path, ?array $body = null){
        $o = self::oauth();
        if ( ! $o ) return new \WP_Error('madof_cal', __('Activa el módulo Conexiones y conecta Google.', 'mad-oficina'));
        $token = $o->access_token();
        if ( is_wp_error($token) ) return $token;
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8']];
        if ( $body !== null ) $args['body'] = wp_json_encode($body);
        $res = wp_remote_request(self::BASE . $path, $args);
        if ( is_wp_error($res) ) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ( $code >= 300 ) {
            return new \WP_Error('madof_cal_http', sprintf(__('Google Calendar respondió %1$d: %2$s', 'mad-oficina'), $code, $data['error']['message'] ?? ''), ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }

    /** @return array<int,array{0:int,1:int}>|\WP_Error busy intervals as timestamps */
    public static function busy($from_ts, $to_ts, array $calendars){
        $calendars = $calendars ?: ['primary'];
        $res = self::request('POST', 'freeBusy', [
            'timeMin' => gmdate('c', $from_ts),
            'timeMax' => gmdate('c', $to_ts),
            'items'   => array_map(fn($id) => ['id' => $id], $calendars),
        ]);
        if ( is_wp_error($res) ) return $res;
        $out = [];
        foreach ( $res['calendars'] ?? [] as $id => $cal ) {
            if ( ! empty($cal['errors']) ) return new \WP_Error('madof_cal', sprintf(__('No puedo leer el calendario %s.', 'mad-oficina'), $id));
            foreach ( $cal['busy'] ?? [] as $b ) $out[] = [strtotime($b['start']), strtotime($b['end'])];
        }
        return $out;
    }

    /** @return array|\WP_Error [['id','summary','primary']] */
    public static function calendars(){
        $res = self::request('GET', 'users/me/calendarList?minAccessRole=freeBusyReader');
        if ( is_wp_error($res) ) return $res;
        return array_map(fn($c) => ['id' => $c['id'], 'summary' => $c['summaryOverride'] ?? ($c['summary'] ?? $c['id']), 'primary' => ! empty($c['primary'])], $res['items'] ?? []);
    }

    /** Events of the primary calendar between two timestamps. @return array|\WP_Error */
    public static function events($from_ts, $to_ts){
        $q = http_build_query(['timeMin' => gmdate('c', $from_ts), 'timeMax' => gmdate('c', $to_ts), 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 100]);
        $res = self::request('GET', 'calendars/primary/events?' . $q);
        return is_wp_error($res) ? $res : ($res['items'] ?? []);
    }

    /**
     * Creates an event with a Google Meet link and sends Google's invitations.
     * @return array|\WP_Error the created event
     */
    public static function create_meeting($summary, $description, $start_ts, $end_ts, array $attendees){
        $tz = wp_timezone_string();
        return self::request('POST', 'calendars/primary/events?conferenceDataVersion=1&sendUpdates=all', [
            'summary'        => $summary,
            'description'    => $description,
            'start'          => ['dateTime' => wp_date('c', $start_ts), 'timeZone' => $tz],
            'end'            => ['dateTime' => wp_date('c', $end_ts), 'timeZone' => $tz],
            'attendees'      => array_map(fn($e) => ['email' => $e], array_values(array_unique($attendees))),
            'conferenceData' => ['createRequest' => ['requestId' => wp_generate_uuid4(), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
            'reminders'      => ['useDefault' => true],
        ]);
    }
}
