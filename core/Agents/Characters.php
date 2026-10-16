<?php
namespace MAD_Oficina\Core\Agents;

if ( ! defined('ABSPATH') ) exit;

/**
 * The office cast. Names and roles are editable in settings; slugs, rooms and
 * sprites are fixed. All characters are original (no existing IP).
 * Liz and Jessica are real people, not AI.
 */
final class Characters {

    const OPTION = 'madoficina_characters';

    const ROOMS = [
        'recepcion'      => 'Recepción',
        'reuniones'      => 'Sala de reuniones',
        'proyectos'      => 'Sala de proyectos',
        'estudio'        => 'Estudio creativo',
        'archivo'        => 'Archivo',
        'administracion' => 'Administración',
        'campanas'       => 'Sala de campañas',
        'gerencia'       => 'Despacho de gerencia',
    ];

    /** @return array<string,array> */
    public static function defaults(){
        return [
            'recepcionista' => ['name' => 'Rita',   'role' => 'Recepcionista',       'room' => 'recepcion',      'ai' => true,  'greeting' => '¡Hola, {nombre}! ¿Qué necesitas hoy?', 'greeting_alt' => '¡Hola! ¿En qué te ayudamos?'],
            'secretaria'    => ['name' => 'Olvido', 'role' => 'Secretaria de actas', 'room' => 'reuniones',      'ai' => true,  'greeting' => 'Aquí tienes todo lo que hablamos.'],
            'coordinadora'  => ['name' => 'Valle',  'role' => 'Coordinadora',        'room' => 'proyectos',      'ai' => true,  'greeting' => 'Esto es lo que tenemos en marcha.'],
            'liz'           => ['name' => 'Liz',    'role' => 'Diseño y vídeo',      'room' => 'estudio',        'ai' => false, 'greeting' => 'Mira lo que estoy diseñando para ti.'],
            'archivera'     => ['name' => 'Berta',  'role' => 'Archivera',           'room' => 'archivo',        'ai' => true,  'greeting' => 'Tus documentos, bien ordenados.'],
            'contable'      => ['name' => 'Amparo', 'role' => 'Contable',            'room' => 'administracion', 'ai' => true,  'greeting' => 'Facturas y presupuestos, al día.'],
            'analista'      => ['name' => 'Nuria',  'role' => 'Analista de campañas','room' => 'campanas',       'ai' => true,  'greeting' => 'Muy pronto te enseño tus campañas.'],
            'jessica'       => ['name' => 'Jessica','role' => 'Gerencia',            'room' => 'gerencia',       'ai' => false, 'greeting' => 'Está trabajando.', 'greeting_alt' => '¿Qué revisamos hoy?'],
        ];
    }

    /**
     * The second greeting: what the receptionist says to visitors without a user, and
     * what Jessica's character says to Jessica herself (the first one is for clients).
     */
    const ALT_GREETING = ['recepcionista' => 'Para visitantes', 'jessica' => 'Para ti'];

    /** Defaults merged with the names, roles and greetings saved in settings. {nombre} = the person's first name. */
    public static function all(){
        $saved = get_option(self::OPTION, []);
        $out = [];
        foreach ( self::defaults() as $slug => $c ){
            $s = is_array($saved[$slug] ?? null) ? $saved[$slug] : [];
            $c['name'] = ($s['name'] ?? '') !== '' ? $s['name'] : $c['name'];
            $c['role'] = ($s['role'] ?? '') !== '' ? $s['role'] : $c['role'];
            foreach ( ['greeting', 'greeting_alt'] as $k ) if ( isset($c[$k]) || isset(self::ALT_GREETING[$slug]) ) $c[$k] = ($s[$k] ?? '') !== '' ? $s[$k] : ($c[$k] ?? '');
            $c['slug'] = $slug;
            $out[$slug] = $c;
        }
        return $out;
    }

    /** What the portal receives about each character. */
    public static function public_data(){
        $out = [];
        foreach ( self::all() as $slug => $c ) {
            $out[$slug] = ['name' => $c['name'], 'role' => $c['role'], 'room' => $c['room'], 'ai' => $c['ai'], 'greeting' => $c['greeting'] ?? '', 'greeting_alt' => $c['greeting_alt'] ?? ''];
        }
        return $out;
    }

    public static function get($slug){
        return self::all()[$slug] ?? null;
    }

    public static function save(array $input){
        $clean = [];
        foreach ( array_keys(self::defaults()) as $slug ){
            $clean[$slug] = [
                'name' => sanitize_text_field($input[$slug]['name'] ?? ''),
                'role' => sanitize_text_field($input[$slug]['role'] ?? ''),
                'greeting' => mb_substr(sanitize_text_field($input[$slug]['greeting'] ?? ''), 0, 140),
                'greeting_alt' => mb_substr(sanitize_text_field($input[$slug]['greeting_alt'] ?? ''), 0, 140),
            ];
        }
        update_option(self::OPTION, $clean, false);
    }
}
