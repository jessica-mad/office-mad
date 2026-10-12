<?php
namespace MAD_Oficina\Billing;

if ( ! defined('ABSPATH') ) exit;

/** Amounts as they appear in Spanish sheets: «€1.500,00», «-€22,50», «300 €», «731.79». */
final class Money {

    /** @return float|null */
    public static function parse($value){
        $v = trim(str_replace(["\u{00A0}", ' ', '€', 'EUR'], '', (string) $value));
        if ( $v === '' || $v === '-' ) return null;
        $neg = false;
        if ( $v[0] === '-' || $v[0] === '−' ) { $neg = true; $v = ltrim($v, '-−'); }
        if ( str_contains($v, ',') ) {
            $v = str_replace('.', '', $v);       // thousands
            $v = str_replace(',', '.', $v);      // decimals
        } elseif ( substr_count($v, '.') > 1 ) {
            $v = str_replace('.', '', $v);
        }
        if ( ! is_numeric($v) ) return null;
        $n = round((float) $v, 2);
        return $neg ? -$n : $n;
    }

    public static function round($n){
        return round((float) $n + 0.0, 2, PHP_ROUND_HALF_UP);
    }

    public static function format($n, $symbol = true){
        $s = number_format((float) $n, 2, ',', '.');
        return $symbol ? $s . ' €' : $s;
    }
}
