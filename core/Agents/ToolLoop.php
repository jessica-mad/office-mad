<?php
namespace MAD_Oficina\Core\Agents;

if ( ! defined('ABSPATH') ) exit;

/**
 * A Claude conversation with tools, run on the server: Claude asks for a tool, we run
 * our own PHP handler (which decides what is allowed), hand back the result, and so on
 * until Claude answers. Tool results are data for the model, never instructions.
 */
final class ToolLoop {

    /**
     * @param array $a model, system, messages, tools ([name, description, input_schema, handler]),
     *                 agent (log slug), effort, max_turns, on_tool (callable(name, input))
     * @return array{text:string, turns:int}|\WP_Error
     */
    public static function run(array $a){
        $tools = [];
        $handlers = [];
        foreach ( $a['tools'] ?? [] as $t ) {
            $handlers[$t['name']] = $t['handler'];
            $tools[] = ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['input_schema']];
        }
        $messages = $a['messages'];
        $max = (int) ($a['max_turns'] ?? 6);
        for ( $turn = 1; $turn <= $max; $turn++ ) {
            $body = [
                'model'         => $a['model'],
                'max_tokens'    => (int) ($a['max_tokens'] ?? 8000),
                'system'        => $a['system'],
                'messages'      => $messages,
                'output_config' => ['effort' => $a['effort'] ?? 'low'],
            ];
            if ( $tools ) $body['tools'] = $tools;
            $res = ClaudeClient::create($body, ['agent' => $a['agent'] ?? 'agent', 'event' => 'turn'], ['timeout' => 90]);
            if ( is_wp_error($res) ) return $res;
            if ( ($res['stop_reason'] ?? '') !== 'tool_use' ) return ['text' => ClaudeClient::text($res), 'turns' => $turn];

            $messages[] = ClaudeClient::assistant_turn($res['content']); // thinking blocks included
            $results = [];
            foreach ( $res['content'] as $block ) {
                if ( ($block['type'] ?? '') !== 'tool_use' ) continue;
                $name = (string) $block['name'];
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                if ( ! empty($a['on_tool']) ) call_user_func($a['on_tool'], $name, $input);
                try {
                    $out = isset($handlers[$name]) ? call_user_func($handlers[$name], $input) : new \WP_Error('madof_tool', 'Unknown tool');
                } catch ( \Throwable $e ) {
                    $out = new \WP_Error('madof_tool', $e->getMessage());
                }
                $results[] = is_wp_error($out)
                    ? ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => $out->get_error_message()]
                    : ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => is_string($out) ? $out : wp_json_encode($out, JSON_UNESCAPED_UNICODE)];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        return new \WP_Error('madof_loop', __('Me he liado buscando y no he terminado. ¿Me lo preguntas de otra forma?', 'mad-oficina'));
    }
}
