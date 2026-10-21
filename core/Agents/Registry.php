<?php
namespace MAD_Oficina\Core\Agents;

use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Every place the office talks to Claude, as one list Jessica edits in «Personajes y agentes»:
 * which character it is (its portrait), the model and effort it uses, her own extra
 * instructions (added after the fixed ones, which keep the safety rules), and what it spends.
 *
 * Model, effort and extra instructions saved here win over the old per-module settings,
 * which stay as the fallback so nothing changes until she saves something.
 */
final class Registry {

    const OPTION = 'madoficina_agents';
    const MODELS = ['claude-opus-5-5' => 'Opus 5.5', 'claude-sonnet-5-5' => 'Sonnet 5.5', 'claude-haiku-5-5' => 'Haiku 5.5'];
    const EFFORTS = ['low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto'];

    /**
     * key => character (portrait; «nora» = the Chat bot), where (area), what it does, default
     * model and effort, how it shows in the log [agent, event|null], and the module it needs.
     * @return array<string,array>
     */
    public static function defs(){
        return [
            'asistente'       => ['character' => 'nora',          'area' => 'Chat',            'does' => 'Te atiende en Google Chat: entiende lo que pides (texto o audio), pregunta a las áreas y te contesta. Recordatorios y memoria.', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'log' => ['asistente', null], 'module' => 'chat'],
            'finanzas'        => ['character' => 'contable',      'area' => 'Chat · Finanzas', 'does' => 'Responde a Nora sobre facturas, cobros, gastos y banco, y hace los cambios que pides (cobrada, cuadrar banco, registrar facturas).', 'model' => 'claude-opus-5-5', 'effort' => 'low', 'log' => ['agent_finanzas', null], 'module' => 'chat'],
            'proyectos'       => ['character' => 'coordinadora',  'area' => 'Chat · Proyectos', 'does' => 'Responde a Nora sobre tareas e ideas: las busca, crea, cierra, cambia de fecha o de persona.', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'log' => ['agent_proyectos', null], 'module' => 'chat'],
            'actas'           => ['character' => 'secretaria',    'area' => 'Chat · Actas',    'does' => 'Responde a Nora sobre lo que se habló en reuniones y WhatsApp, y los temas pendientes.', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'log' => ['agent_actas', null], 'module' => 'chat'],
            'campanas'        => ['character' => 'analista',      'area' => 'Chat · Campañas', 'does' => 'Responde a Nora sobre campañas y la bitácora de cambios: apunta el porqué, cambios hechos a mano e imágenes.', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'log' => ['agent_campanas', null], 'module' => 'ads'],
            'secretaria'      => ['character' => 'secretaria',    'area' => 'Reuniones',       'does' => 'Lee la transcripción o el WhatsApp y prepara el borrador del acta: resumen, acuerdos, tareas, ideas y temas pendientes.', 'model' => 'claude-sonnet-5-5', 'effort' => null, 'log' => ['secretary', 'extract'], 'module' => 'meetings'],
            'revision'        => ['character' => 'coordinadora',  'area' => 'Seguimiento',     'does' => 'Revisa las tareas abiertas de cada cliente tras cada reunión: duplicadas, hechas, cambios. Lo que cierra tareas siempre te lo pregunta.', 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'log' => ['coordinator', 'task_review'], 'module' => 'followup'],
            'lector_facturas' => ['character' => 'contable',      'area' => 'Gastos',          'does' => 'Lee cada factura de proveedor (PDF o foto): proveedor, NIF, fecha, número, base, IVA y total.', 'model' => 'claude-opus-5-5', 'effort' => null, 'log' => ['accountant', 'read_invoice'], 'module' => 'suppliers'],
            'recepcionista'   => ['character' => 'recepcionista', 'area' => 'Portal',          'does' => 'Atiende a los clientes en el chat de la oficina, solo con sus datos visibles.', 'model' => 'claude-haiku-5-5', 'effort' => null, 'log' => ['receptionist', null], 'module' => 'portal'],
            'visitantes'      => ['character' => 'recepcionista', 'area' => 'Web',             'does' => 'Atiende a quien visita la web sin cuenta: qué hace MAD, primera reunión, brief.', 'model' => 'claude-haiku-5-5', 'effort' => null, 'log' => ['visitor_receptionist', null], 'module' => 'visitors'],
            'agenda'          => ['character' => 'recepcionista', 'area' => 'Agenda',          'does' => 'Convierte «me va bien el jueves por la tarde» en franjas concretas para cruzarlas con tu calendario.', 'model' => 'claude-haiku-5-5', 'effort' => null, 'log' => ['scheduler', 'interpret'], 'module' => 'scheduling'],
        ];
    }

    private static function saved($key){
        $all = get_option(self::OPTION, []);
        return is_array($all[$key] ?? null) ? $all[$key] : [];
    }

    /** The model for this agent: what Jessica chose here, else the module's old setting, else the default. */
    public static function model($key, $fallback = ''){
        $m = self::saved($key)['model'] ?? '';
        if ( ! isset(self::MODELS[$m]) ) $m = isset(self::MODELS[$fallback]) ? $fallback : (self::defs()[$key]['model'] ?? 'claude-sonnet-5-5');
        return (string) apply_filters('madoficina_agent_model', $m, $key);
    }

    public static function effort($key, $fallback = 'low'){
        $e = self::saved($key)['effort'] ?? '';
        if ( ! isset(self::EFFORTS[$e]) ) $e = self::defs()[$key]['effort'] ?? $fallback;
        return (string) apply_filters('madoficina_agent_effort', $e ?: $fallback, $key);
    }

    /** Her own instructions for this agent («» if none), added after the fixed ones. */
    public static function extra($key, $fallback = ''){
        $s = self::saved($key);
        // Once saved here (even empty) this is the one; before, the module's old setting.
        return array_key_exists('extra', $s) ? trim((string) $s['extra']) : trim((string) $fallback);
    }

    /** While true, prompts are built without her instructions (to show the fixed part alone). */
    private static $base_only = false;

    /** The fixed prompt with her instructions at the end. */
    public static function with_extra($key, $prompt, $fallback = ''){
        $x = self::$base_only ? '' : self::extra($key, $fallback);
        return $x === '' ? $prompt : $prompt . "\n\nIndicaciones de Jessica para ti (no anulan las reglas de arriba):\n" . $x;
    }

    /**
     * The fixed instructions of an agent as they are today (date, areas, clients… included),
     * without her own ones. «» if its module is off.
     */
    public static function base_prompt($key){
        $opt = fn($m) => (array) get_option(\MAD_Oficina_Core::option_key($m), []);
        $classes = ['finanzas' => 'MAD_Oficina\\Chat\\Agents\\Finance', 'proyectos' => 'MAD_Oficina\\Chat\\Agents\\Projects', 'actas' => 'MAD_Oficina\\Chat\\Agents\\Minutes', 'campanas' => 'MAD_Oficina\\Ads\\Agent'];
        $core = \MAD_Oficina_Core::instance();
        if ( ! $core->get_module(self::defs()[$key]['module'] ?? '') ) return '';
        $chat = $opt('chat');
        $email = strtolower((string) ($chat['manager_email'] ?? wp_get_current_user()->user_email));
        $ctx = ['email' => $email, 'is_manager' => true, 'member' => null, 'manager_email' => $email, 'user_id' => get_current_user_id(), 'images' => [], 'files' => []];
        self::$base_only = true;
        try {
            switch ( $key ) {
                case 'asistente': return (new \MAD_Oficina\Chat\Orchestrator($chat, $ctx, null))->system_prompt();
                case 'secretaria': return (new \MAD_Oficina\Meetings\Secretary($opt('meetings')))->system_prompt();
                case 'revision': return \MAD_Oficina\Followup\Reconciler::system_prompt();
                case 'lector_facturas': return \MAD_Oficina\Suppliers\Reader::system_prompt();
                case 'recepcionista': return (new \MAD_Oficina\Portal\Receptionist($opt('portal')))->system_prompt(0);
                case 'visitantes': return (new \MAD_Oficina\Visitors\VisitorChat($opt('visitors')))->system_prompt();
                case 'agenda': return \MAD_Oficina\Scheduling\Requests::system_prompt();
                default: return isset($classes[$key]) && class_exists($classes[$key]) ? (new $classes[$key]($ctx))->system_prompt() : '';
            }
        } catch ( \Throwable $e ) {
            return '(' . $e->getMessage() . ')';
        } finally {
            self::$base_only = false;
        }
    }

    public static function save(array $input){
        $out = [];
        foreach ( array_keys(self::defs()) as $key ) {
            $in = (array) ($input[$key] ?? []);
            $out[$key] = [
                'model' => isset(self::MODELS[$in['model'] ?? '']) ? $in['model'] : '',
                'effort' => isset(self::EFFORTS[$in['effort'] ?? '']) ? $in['effort'] : '',
                'extra' => mb_substr(sanitize_textarea_field((string) ($in['extra'] ?? '')), 0, 4000),
            ];
        }
        update_option(self::OPTION, $out, false);
    }

    /**
     * What each agent spent in the last $days days, from the log.
     * @return array<string,array{calls:int,input:int,output:int,cache_read:int,usd:float}>
     */
    public static function usage($days = 30){
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $t = Schema::table('agent_log');
        $out = [];
        foreach ( self::defs() as $key => $d ) {
            [$agent, $event] = $d['log'];
            $sql = "SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens),0) AS input, COALESCE(SUM(output_tokens),0) AS output, COALESCE(SUM(cost_usd),0) AS usd FROM {$t} WHERE agent = %s AND created_at >= %s AND (input_tokens > 0 OR output_tokens > 0)" . ($event ? ' AND event = %s' : '');
            $row = $wpdb->get_row($event ? $wpdb->prepare($sql, $agent, $since, $event) : $wpdb->prepare($sql, $agent, $since), ARRAY_A);
            $read = 0;
            foreach ( $wpdb->get_col($event ? $wpdb->prepare("SELECT data FROM {$t} WHERE agent = %s AND created_at >= %s AND event = %s AND data LIKE %s", $agent, $since, $event, '%cache_read%') : $wpdb->prepare("SELECT data FROM {$t} WHERE agent = %s AND created_at >= %s AND data LIKE %s", $agent, $since, '%cache_read%')) as $j ) {
                $read += (int) ((json_decode((string) $j, true) ?: [])['cache_read'] ?? 0);
            }
            $out[$key] = ['calls' => (int) $row['calls'], 'input' => (int) $row['input'], 'output' => (int) $row['output'], 'cache_read' => $read, 'usd' => (float) $row['usd']];
        }
        return $out;
    }
}
