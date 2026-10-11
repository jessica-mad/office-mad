<?php
namespace MAD_Oficina\Core\Agents;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Minimal Claude Messages API client over wp_remote_post (no SDK: the plugin
 * has no runtime dependencies). Every call is logged to agent_log with tokens
 * and cost.
 */
final class ClaudeClient {

    const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const VERSION  = '2023-06-01';

    /** USD per million tokens [input, output] for prompts ≤ 100K tokens. */
    const PRICES = [
        'claude-haiku-5-5'  => [0.10, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-opus-5-5'   => [4.00, 20.00],
    ];

    public static function has_key(){
        return defined('MAD_OFICINA_ANTHROPIC_KEY') && MAD_OFICINA_ANTHROPIC_KEY;
    }

    /**
     * @param array $body  Messages API request body (model, max_tokens, system, messages, tools…)
     * @param array $ctx   ['agent' => slug, 'client_id' => int|null, 'event' => string]
     * @return array|\WP_Error decoded response
     */
    public static function create(array $body, array $ctx){
        if ( ! self::has_key() ) {
            return new \WP_Error('madof_no_key', __('Falta la clave de Claude (MAD_OFICINA_ANTHROPIC_KEY).', 'mad-oficina'));
        }

        $res = wp_remote_post(self::ENDPOINT, [
            'timeout' => 60,
            'headers' => [
                'content-type'      => 'application/json',
                'x-api-key'         => MAD_OFICINA_ANTHROPIC_KEY,
                'anthropic-version' => self::VERSION,
            ],
            'body' => wp_json_encode($body),
        ]);
        if ( is_wp_error($res) ) {
            self::log($ctx, $body['model'] ?? '', 0, 0, ['error' => $res->get_error_message()]);
            return $res;
        }

        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ( $code !== 200 || ! is_array($data) ) {
            $message = is_array($data) ? ($data['error']['message'] ?? '') : '';
            self::log($ctx, $body['model'] ?? '', 0, 0, ['error' => "HTTP {$code} {$message}"]);
            return new \WP_Error('madof_claude_http', sprintf('Claude HTTP %d: %s', $code, $message), ['status' => $code]);
        }

        $usage = $data['usage'] ?? [];
        $in  = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        self::log($ctx, $data['model'] ?? ($body['model'] ?? ''), $in, $out, ['stop_reason' => $data['stop_reason'] ?? null]);
        return $data;
    }

    public static function cost($model, $in, $out){
        [$pin, $pout] = self::PRICES[$model] ?? [0, 0];
        return round(($in * $pin + $out * $pout) / 1000000, 5);
    }

    /** Concatenated text blocks of a response (thinking/tool blocks skipped). */
    public static function text(array $response){
        $out = '';
        foreach ( $response['content'] ?? [] as $block ){
            if ( ($block['type'] ?? '') === 'text' ) $out .= $block['text'];
        }
        return trim($out);
    }

    private static function log(array $ctx, $model, $in, $out, array $data){
        global $wpdb;
        $wpdb->insert(Schema::table('agent_log'), [
            'agent'         => sanitize_key($ctx['agent'] ?? 'unknown'),
            'event'         => sanitize_key($ctx['event'] ?? 'messages'),
            'client_id'     => $ctx['client_id'] ?? null,
            'model'         => $model,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'cost_usd'      => self::cost($model, $in, $out),
            'data'          => wp_json_encode($data),
            'created_at'    => Time::now(),
        ]);
    }
}
