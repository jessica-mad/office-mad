<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * How much freedom each kind of action has (CLAUDE.md §2):
 *   ask    – siempre preguntar (default): goes to the inbox and waits.
 *   notify – avisar y hacer: runs at once, Jessica gets a Chat message.
 *   silent – hacer en silencio: runs at once, only logged.
 */
final class Autonomy {

    const OPTION = 'madoficina_autonomy';
    const LEVELS = ['ask', 'notify', 'silent'];

    /** Action types that may ever run without asking. Everything else is always "ask". */
    const ADJUSTABLE = [
        'meeting_request' => 'Crear reuniones que piden los clientes desde el portal',
        'client_reminder' => 'Enviar recordatorios de tareas a clientes',
    ];

    public static function level($action_type){
        if ( ! isset(self::ADJUSTABLE[$action_type]) ) return 'ask';
        $saved = get_option(self::OPTION, []);
        return in_array($saved[$action_type] ?? '', self::LEVELS, true) ? $saved[$action_type] : 'ask';
    }

    public static function save(array $input){
        $clean = [];
        foreach ( array_keys(self::ADJUSTABLE) as $type ) {
            $clean[$type] = in_array($input[$type] ?? '', self::LEVELS, true) ? $input[$type] : 'ask';
        }
        update_option(self::OPTION, $clean, false);
    }
}
