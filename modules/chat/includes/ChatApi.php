<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * Google Chat REST API with the bot's credentials (app authentication).
 */
final class ChatApi {

    const BASE = 'https://chat.googleapis.com/v1/';

    private $account;

    public function __construct(ServiceAccount $account){
        $this->account = $account;
    }

    /** @return array|\WP_Error created message */
    public function create_message($space, array $message){
        if ( ! preg_match('#^spaces/[A-Za-z0-9_-]+$#', (string) $space) ) {
            return new \WP_Error('madof_chat_space', __('Espacio de Chat no válido.', 'mad-oficina'));
        }
        return $this->request('POST', $space . '/messages', $message);
    }

    /** Spaces the bot is a member of. @return array|\WP_Error */
    public function list_spaces(){
        $out = [];
        $token = '';
        for ( $i = 0; $i < 5; $i++ ) {
            $res = $this->request('GET', 'spaces?pageSize=100' . ($token ? '&pageToken=' . rawurlencode($token) : ''));
            if ( is_wp_error($res) ) return $res;
            foreach ( $res['spaces'] ?? [] as $s ) {
                $out[] = [
                    'name'     => $s['name'],
                    'title'    => $s['displayName'] ?? '',
                    'type'     => $s['spaceType'] ?? ($s['type'] ?? ''),
                    'external' => ! empty($s['externalUserAllowed']),
                ];
            }
            $token = $res['nextPageToken'] ?? '';
            if ( ! $token ) break;
        }
        return $out;
    }

    private function request($method, $path, ?array $body = null){
        $token = $this->account->access_token();
        if ( is_wp_error($token) ) return $token;
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8'],
        ];
        if ( $body !== null ) $args['body'] = wp_json_encode($body);
        $res = wp_remote_request(self::BASE . $path, $args);
        if ( is_wp_error($res) ) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ( $code >= 300 ) {
            return new \WP_Error('madof_chat_http', sprintf(__('Google Chat respondió %1$d: %2$s', 'mad-oficina'), $code, $data['error']['message'] ?? ''), ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }
}
