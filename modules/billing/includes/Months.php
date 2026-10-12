<?php
namespace MAD_Oficina\Billing;

if ( ! defined('ABSPATH') ) exit;

/** Months as «YYYY-MM» strings (they sort and compare as text). */
final class Months {

    const NAMES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public static function valid($m){
        return is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : null;
    }

    public static function current(){
        return current_time('Y-m');
    }

    public static function of($date){
        return substr((string) $date, 0, 7);
    }

    public static function add($m, $n){
        [$y, $mo] = array_map('intval', explode('-', $m));
        $i = $y * 12 + ($mo - 1) + (int) $n;
        return sprintf('%04d-%02d', intdiv($i, 12), $i % 12 + 1);
    }

    /** Months from $a to $b, both included. */
    public static function diff($a, $b){
        [$y1, $m1] = array_map('intval', explode('-', $a));
        [$y2, $m2] = array_map('intval', explode('-', $b));
        return ($y2 * 12 + $m2) - ($y1 * 12 + $m1);
    }

    /** @return string[] */
    public static function range($from, $to){
        $out = [];
        for ( $m = $from; $m <= $to; $m = self::add($m, 1) ) $out[] = $m;
        return $out;
    }

    /** «nov 2026» (Spanish, whatever the site language). */
    public static function label($m, $year = true){
        if ( ! self::valid($m) ) return '';
        return self::NAMES[(int) substr($m, 5, 2) - 1] . ($year ? ' ' . substr($m, 0, 4) : '');
    }
}
