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
            'recepcionista' => ['name' => 'Rita',   'role' => 'Recepcionista',       'room' => 'recepcion',      'ai' => true],
            'secretaria'    => ['name' => 'Olvido', 'role' => 'Secretaria de actas', 'room' => 'reuniones',      'ai' => true],
            'coordinadora'  => ['name' => 'Valle',  'role' => 'Coordinadora',        'room' => 'proyectos',      'ai' => true],
            'liz'           => ['name' => 'Liz',    'role' => 'Diseño y vídeo',      'room' => 'estudio',        'ai' => false],
            'archivera'     => ['name' => 'Berta',  'role' => 'Archivera',           'room' => 'archivo',        'ai' => true],
            'contable'      => ['name' => 'Amparo', 'role' => 'Contable',            'room' => 'administracion', 'ai' => true],
            'analista'      => ['name' => 'Nuria',  'role' => 'Analista de campañas','room' => 'campanas',       'ai' => true],
            'jessica'       => ['name' => 'Jessica','role' => 'Gerencia',            'room' => 'gerencia',       'ai' => false],
        ];
    }

    /** Defaults merged with the names/roles saved in settings. */
    public static function all(){
        $saved = get_option(self::OPTION, []);
        $out = [];
        foreach ( self::defaults() as $slug => $c ){
            $s = is_array($saved[$slug] ?? null) ? $saved[$slug] : [];
            $c['name'] = ($s['name'] ?? '') !== '' ? $s['name'] : $c['name'];
            $c['role'] = ($s['role'] ?? '') !== '' ? $s['role'] : $c['role'];
            $c['slug'] = $slug;
            $out[$slug] = $c;
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
            ];
        }
        update_option(self::OPTION, $clean, false);
    }
}
