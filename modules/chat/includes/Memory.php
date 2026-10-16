<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * The assistant's memory, per person: the recent conversation (to follow the thread:
 * «¿y la anterior?») and the things she asked it to remember («a LCS llámalo Luz Clara»).
 * Facts are only ever written from that person's own messages, and can be seen and
 * deleted in Chat → ajustes.
 */
final class Memory {

    const HISTORY = 'madoficina_assistant_history';
    const FACTS = 'madoficina_assistant_facts';
    const TURNS = 12;
    const HOURS = 72;
    const MAX_FACTS = 40;

    private static function load($option){ $v = get_option($option, []); return is_array($v) ? $v : []; }

    /** @return array<array{role:string,text:string,at:int}> oldest first, starting with the person */
    public static function history($email){
        $h = array_values(array_filter(self::load(self::HISTORY)[strtolower($email)] ?? [], fn($t) => $t['at'] > time() - self::HOURS * HOUR_IN_SECONDS));
        while ( $h && $h[0]['role'] !== 'user' ) array_shift($h);
        return $h;
    }

    public static function add($email, $user_text, $assistant_text){
        $all = self::load(self::HISTORY);
        $h = self::history($email);
        $h[] = ['role' => 'user', 'text' => mb_substr((string) $user_text, 0, 2000), 'at' => time()];
        $h[] = ['role' => 'assistant', 'text' => mb_substr((string) $assistant_text, 0, 3000), 'at' => time()];
        $all[strtolower($email)] = array_slice($h, -self::TURNS * 2);
        update_option(self::HISTORY, $all, false);
    }

    public static function clear_history($email){
        $all = self::load(self::HISTORY);
        unset($all[strtolower($email)]);
        update_option(self::HISTORY, $all, false);
    }

    /** @return array<array{id:string,text:string,at:int}> */
    public static function facts($email){
        return array_values(self::load(self::FACTS)[strtolower($email)] ?? []);
    }

    /** @return array|\WP_Error the saved fact */
    public static function remember($email, $text){
        $text = mb_substr(trim(sanitize_text_field((string) $text)), 0, 300);
        if ( $text === '' ) return new \WP_Error('madof_memory', 'Nada que recordar.');
        $all = self::load(self::FACTS);
        $facts = self::facts($email);
        if ( count($facts) >= self::MAX_FACTS ) return new \WP_Error('madof_memory_full', 'La memoria está llena: borra alguna cosa en Chat → ajustes.');
        $fact = ['id' => substr(md5($text . microtime()), 0, 6), 'text' => $text, 'at' => time()];
        $facts[] = $fact;
        $all[strtolower($email)] = $facts;
        update_option(self::FACTS, $all, false);
        return $fact;
    }

    public static function forget($email, $id){
        $all = self::load(self::FACTS);
        $before = self::facts($email);
        $after = array_values(array_filter($before, fn($f) => $f['id'] !== (string) $id));
        $all[strtolower($email)] = $after;
        update_option(self::FACTS, $all, false);
        return count($after) < count($before);
    }
}
