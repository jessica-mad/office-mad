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

    const FALLBACK_MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5'];

    public static function has_key(){
        return defined('MAD_OFICINA_ANTHROPIC_KEY') && MAD_OFICINA_ANTHROPIC_KEY;
    }

    /**
     * @param array $body  Messages API request body (model, max_tokens, system, messages, tools…)
     * @param array $ctx   ['agent' => slug, 'client_id' => int|null, 'event' => string]
     * @param array $opts  ['timeout' => seconds]
     * @return array|\WP_Error decoded response
     */
    /**
     * The model's turn, sent back exactly as it came, except that a tool called with no
     * arguments decodes to [] and must go back as {} (else: 400 «Input should be an object»).
     */
    public static function assistant_turn(array $content){
        return ['role' => 'assistant', 'content' => array_map(fn($b) => ($b['type'] ?? '') === 'tool_use' && empty($b['input']) ? ['input' => new \stdClass()] + $b : $b, $content)];
    }

    public static function create(array $body, array $ctx, array $opts = []){
        if ( ! self::has_key() ) {
            return new \WP_Error('madof_no_key', __('Falta la clave de Claude (MAD_OFICINA_ANTHROPIC_KEY).', 'mad-oficina'));
        }

        $headers = [
            'content-type'      => 'application/json',
            'x-api-key'         => MAD_OFICINA_ANTHROPIC_KEY,
            'anthropic-version' => self::VERSION,
        ];
        // Safety classifiers can decline a request (stop_reason "refusal"). Sonnet 5.5 and Opus 5.5
        // support a server-side retry on Anthropic's recommended fallback model; Haiku 5.5 does not.
        if ( in_array($body['model'] ?? '', self::FALLBACK_MODELS, true) && ! isset($body['fallbacks']) ) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks'] = 'default';
        }

        $res = wp_remote_post(self::ENDPOINT, [
            'timeout' => (int) ($opts['timeout'] ?? 60),
            'headers' => $headers,
            'body'    => wp_json_encode($body),
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

        if ( ($data['stop_reason'] ?? '') === 'refusal' ) {
            self::log($ctx, $data['model'] ?? ($body['model'] ?? ''), 0, 0, ['refusal' => $data['stop_details'] ?? null]);
            return new \WP_Error('madof_claude_refusal', __('Claude ha rechazado procesar este contenido.', 'mad-oficina'), ['status' => 422, 'details' => $data['stop_details'] ?? null]);
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

    /** Parses the JSON text block of a structured-output response. @return array|\WP_Error */
    public static function json(array $response){
        $data = json_decode(self::text($response), true);
        if ( ! is_array($data) ) {
            return new \WP_Error('madof_claude_json', ($response['stop_reason'] ?? '') === 'max_tokens'
                ? __('La respuesta de Claude se cortó (demasiado larga).', 'mad-oficina')
                : __('La respuesta de Claude no es un JSON válido.', 'mad-oficina'));
        }
        return $data;
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
