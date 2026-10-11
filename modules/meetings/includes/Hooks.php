<?php
namespace MAD_Oficina\Meetings;

use MAD_Oficina\Core\Crypto;

if ( ! defined('ABSPATH') ) exit;

/**
 * Signed endpoints for the Apps Script:
 *   POST /hooks/meetings          a meeting (metadata + notes/transcript text)
 *   GET  /hooks/orders            approved work to do in Drive/Sheets
 *   POST /hooks/orders/{id}/done  result of an order
 *
 * Signature: hex HMAC-SHA256 over "{timestamp}\n{METHOD}\n{route}\n{body}" with the
 * shared secret, sent as X-Mad-Timestamp / X-Mad-Signature. Requests older than
 * 5 minutes or replayed are rejected. The script can only hand over data and
 * report results; it can never trigger anything that needs approval.
 */
final class Hooks {

    const NS = 'mad-oficina/v1';
    const MAX_SKEW = 300;

    private $secret_cb;
    private $on_meeting;

    /** @param callable $secret_cb returns the plain secret or '' */
    public function __construct(callable $secret_cb, callable $on_meeting){
        $this->secret_cb = $secret_cb;
        $this->on_meeting = $on_meeting;
    }

    public function register(){
        register_rest_route(self::NS, '/hooks/meetings', [
            'methods' => 'POST', 'callback' => [$this, 'receive_meeting'], 'permission_callback' => [$this, 'verify'],
        ]);
        register_rest_route(self::NS, '/hooks/orders', [
            'methods' => 'GET', 'callback' => fn() => rest_ensure_response(['orders' => Orders::pending()]), 'permission_callback' => [$this, 'verify'],
        ]);
        register_rest_route(self::NS, '/hooks/orders/(?P<id>\d+)/done', [
            'methods' => 'POST', 'callback' => [$this, 'order_done'], 'permission_callback' => [$this, 'verify'],
        ]);
    }

    public static function sign($secret, $timestamp, $method, $route, $body){
        return hash_hmac('sha256', $timestamp . "\n" . strtoupper($method) . "\n" . $route . "\n" . $body, $secret);
    }

    /** @return true|\WP_Error */
    public function verify(\WP_REST_Request $req){
        $secret = call_user_func($this->secret_cb);
        if ( ! $secret ) return new \WP_Error('madof_hook_off', 'Hooks not configured', ['status' => 503]);

        $ts  = (string) $req->get_header('x_mad_timestamp');
        $sig = strtolower((string) $req->get_header('x_mad_signature'));
        if ( ! ctype_digit($ts) || abs(time() - (int) $ts) > self::MAX_SKEW ) {
            return new \WP_Error('madof_hook_time', 'Stale or missing timestamp', ['status' => 401]);
        }
        $expected = self::sign($secret, $ts, $req->get_method(), $req->get_route(), $req->get_body());
        if ( ! $sig || ! hash_equals($expected, $sig) ) {
            return new \WP_Error('madof_hook_sig', 'Bad signature', ['status' => 401]);
        }
        $replay_key = 'madof_hook_' . substr($sig, 0, 40);
        if ( get_transient($replay_key) ) return new \WP_Error('madof_hook_replay', 'Replayed request', ['status' => 409]);
        set_transient($replay_key, 1, self::MAX_SKEW * 2);
        return true;
    }

    public function receive_meeting(\WP_REST_Request $req){
        $p = $req->get_json_params();
        if ( ! is_array($p) || empty($p['source_key']) ) {
            return new \WP_Error('madof_hook_payload', 'source_key is required', ['status' => 400]);
        }
        $id = Meetings::upsert([
            'source'             => 'apps_script',
            'source_key'         => $p['source_key'],
            'title'              => $p['title'] ?? '',
            'starts_at'          => $p['starts_at'] ?? null,
            'ends_at'            => $p['ends_at'] ?? null,
            'calendar_event_id'  => $p['calendar_event_id'] ?? null,
            'attendees'          => (array) ($p['attendees'] ?? []),
            'recording_file_id'  => $p['recording_file_id'] ?? null,
            'transcript_file_id' => $p['transcript_file_id'] ?? null,
            'gemini_notes_id'    => $p['gemini_notes_id'] ?? null,
            'drive_folder_id'    => $p['drive_folder_id'] ?? null,
            'sheet_row'          => $p['sheet_row'] ?? null,
            'transcript'         => (string) ($p['notes_text'] ?? ''),
        ]);
        $meeting = Meetings::get($id);
        if ( in_array($meeting['status'], ['received', 'no_transcript', 'error'], true) && trim((string) $meeting['transcript']) !== '' ) {
            call_user_func($this->on_meeting, $id);
        }
        return rest_ensure_response(['meeting_id' => $id, 'status' => Meetings::get($id)['status']]);
    }

    public function order_done(\WP_REST_Request $req){
        $p = $req->get_json_params() ?: [];
        $r = Orders::complete((int) $req['id'], ! empty($p['ok']), (array) ($p['result'] ?? []) + ['error' => $p['error'] ?? null]);
        return is_wp_error($r) ? $r : rest_ensure_response(['ok' => true]);
    }

    /** Plain secret from settings (stored encrypted). */
    public static function secret_from(array $settings){
        return Crypto::decrypt($settings['hook_secret'] ?? '') ?? '';
    }
}
