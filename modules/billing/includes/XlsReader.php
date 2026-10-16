<?php
namespace MAD_Oficina\Billing;

if ( ! defined('ABSPATH') ) exit;

/**
 * Old Excel (.xls, BIFF8 inside an OLE2 compound file), the format many banks still
 * export. Reads the cells of the first worksheet as values: shared strings, labels,
 * numbers (dates come as Excel serial numbers) and cached formula results.
 * Read-only and self-contained: no external library.
 */
final class XlsReader {

    private $data;
    private $sector;
    private $fat = [];

    /** @return array<array<int,string|float>>|\WP_Error rows of cells */
    public static function rows($bytes){
        $r = new self();
        $wb = $r->workbook($bytes);
        return is_wp_error($wb) ? $wb : self::sheet($wb);
    }

    /* ───────── OLE2 compound file → «Workbook» stream ───────── */

    private function u16($s, $o){ return unpack('v', substr($s, $o, 2))[1]; }
    private function u32($s, $o){ return unpack('V', substr($s, $o, 4))[1]; }

    private function workbook($bytes){
        if ( substr($bytes, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ) return new \WP_Error('madof_xls', 'Not an OLE2 file');
        $this->data = $bytes;
        $this->sector = 1 << $this->u16($bytes, 0x1E);
        $mini = 1 << $this->u16($bytes, 0x20);
        $dir_start = $this->u32($bytes, 0x30);
        $cutoff = $this->u32($bytes, 0x38);
        $minifat_start = $this->u32($bytes, 0x3C);
        $difat_start = $this->u32($bytes, 0x44);
        $difat_count = $this->u32($bytes, 0x48);

        $difat = [];
        for ( $i = 0; $i < 109; $i++ ) { $s = $this->u32($bytes, 0x4C + $i * 4); if ( $s < 0xFFFFFFFA ) $difat[] = $s; }
        $per = $this->sector / 4 - 1;
        for ( $n = 0, $s = $difat_start; $n < $difat_count && $s < 0xFFFFFFFA; $n++ ) {
            $sec = $this->sec($s);
            for ( $i = 0; $i < $per; $i++ ) { $v = $this->u32($sec, $i * 4); if ( $v < 0xFFFFFFFA ) $difat[] = $v; }
            $s = $this->u32($sec, $per * 4);
        }
        foreach ( $difat as $s ) { $sec = $this->sec($s); for ( $i = 0; $i < $this->sector / 4; $i++ ) $this->fat[] = $this->u32($sec, $i * 4); }

        $dir = $this->chain($dir_start);
        $root = null; $book = null;
        for ( $o = 0; $o + 128 <= strlen($dir); $o += 128 ) {
            $len = $this->u16($dir, $o + 0x40);
            $name = $len > 2 ? mb_convert_encoding(substr($dir, $o, $len - 2), 'UTF-8', 'UTF-16LE') : '';
            $entry = ['type' => ord($dir[$o + 0x42]), 'start' => $this->u32($dir, $o + 0x74), 'size' => $this->u32($dir, $o + 0x78)];
            if ( $entry['type'] === 5 ) $root = $entry;
            if ( in_array($name, ['Workbook', 'Book'], true) ) $book = $entry;
        }
        if ( ! $book ) return new \WP_Error('madof_xls', __('El Excel no tiene hoja de cálculo que pueda leer.', 'mad-oficina'));
        if ( $book['size'] >= $cutoff ) return substr($this->chain($book['start']), 0, $book['size']);
        // Small workbooks live in the mini stream.
        $ministream = $root ? $this->chain($root['start']) : '';
        $minifat = [];
        $mf = $this->chain($minifat_start);
        for ( $i = 0; $i < strlen($mf) / 4; $i++ ) $minifat[] = $this->u32($mf, $i * 4);
        $out = ''; $s = $book['start']; $guard = 0;
        while ( $s < 0xFFFFFFFA && $guard++ < 100000 ) { $out .= substr($ministream, $s * $mini, $mini); $s = $minifat[$s] ?? 0xFFFFFFFE; }
        return substr($out, 0, $book['size']);
    }

    private function sec($n){ return substr($this->data, 512 + $n * $this->sector, $this->sector); }

    private function chain($start){
        $out = ''; $s = $start; $guard = 0;
        while ( $s < 0xFFFFFFFA && $guard++ < 200000 ) { $out .= $this->sec($s); $s = $this->fat[$s] ?? 0xFFFFFFFE; }
        return $out;
    }

    /* ───────── BIFF8 records → cells of the first worksheet ───────── */

    private static function sheet($wb){
        $u16 = fn($s, $o) => unpack('v', substr($s, $o, 2))[1];
        $u32 = fn($s, $o) => unpack('V', substr($s, $o, 4))[1];
        $len = strlen($wb);
        $records = []; // [type, data]
        for ( $o = 0; $o + 4 <= $len; ) {
            $type = $u16($wb, $o); $size = $u16($wb, $o + 2);
            $records[] = [$type, substr($wb, $o + 4, $size), $o];
            $o += 4 + $size;
        }
        // Shared strings (SST + its CONTINUE records).
        $sst = [];
        foreach ( $records as $i => [$type, $data] ) {
            if ( $type !== 0x00FC ) continue;
            $parts = [substr($data, 8)];
            for ( $j = $i + 1; isset($records[$j]) && $records[$j][0] === 0x003C; $j++ ) $parts[] = $records[$j][1];
            $sst = self::sst($parts, $u32($data, 4));
            break;
        }
        // The first worksheet: from the BOF its BOUNDSHEET points at.
        $sheet_at = null;
        foreach ( $records as [$type, $data] ) if ( $type === 0x0085 && ord($data[5] ?? "\0") === 0 ) { $sheet_at = $u32($data, 0); break; }
        $rows = []; $in = $sheet_at === null; $pending = null;
        foreach ( $records as [$type, $data, $pos] ) {
            if ( ! $in ) { if ( $pos === $sheet_at ) $in = true; else continue; }
            if ( $type === 0x000A && $pos > (int) $sheet_at ) break; // EOF of that sheet
            $put = function($r, $c, $v) use (&$rows){ $rows[$r][$c] = $v; };
            switch ( $type ) {
                case 0x00FD: $put($u16($data, 0), $u16($data, 2), $sst[$u32($data, 6)] ?? ''); break;                     // LABELSST
                case 0x0203: $put($u16($data, 0), $u16($data, 2), unpack('e', substr($data, 6, 8))[1]); break;            // NUMBER
                case 0x027E: $put($u16($data, 0), $u16($data, 2), self::rk($u32($data, 6))); break;                       // RK
                case 0x00BD:                                                                                               // MULRK
                    $r = $u16($data, 0); $c = $u16($data, 2); $n = (strlen($data) - 6) / 6;
                    for ( $k = 0; $k < $n; $k++ ) $put($r, $c + $k, self::rk($u32($data, 4 + $k * 6 + 2)));
                    break;
                case 0x0204: $put($u16($data, 0), $u16($data, 2), self::xlstring(substr($data, 6), true)); break;          // LABEL
                case 0x0006:                                                                                               // FORMULA
                    if ( substr($data, 12, 2) === "\xFF\xFF" ) { $pending = [$u16($data, 0), $u16($data, 2)]; break; }
                    $put($u16($data, 0), $u16($data, 2), unpack('e', substr($data, 6, 8))[1]);
                    break;
                case 0x0207: if ( $pending ) { $put($pending[0], $pending[1], self::xlstring($data, true)); $pending = null; } break; // STRING
            }
        }
        if ( ! $rows ) return [];
        ksort($rows);
        $out = [];
        foreach ( $rows as $r ) {
            $max = max(array_keys($r));
            $line = array_fill(0, $max + 1, '');
            foreach ( $r as $c => $v ) $line[$c] = is_string($v) ? trim($v) : $v;
            $out[] = $line;
        }
        return $out;
    }

    private static function rk($rk){
        if ( $rk & 0x02 ) { $v = $rk >> 2; if ( $v & 0x20000000 ) $v -= 0x40000000; }
        else $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        return ($rk & 0x01) ? $v / 100 : (float) $v;
    }

    /** A BIFF8 unicode string with a 16-bit length (LABEL, STRING). */
    private static function xlstring($s, $long){
        $cch = unpack('v', substr($s, 0, 2))[1];
        $flags = ord($s[2] ?? "\0");
        $o = 3;
        if ( $flags & 0x08 ) $o += 2;
        if ( $flags & 0x04 ) $o += 4;
        return ($flags & 0x01) ? mb_convert_encoding(substr($s, $o, $cch * 2), 'UTF-8', 'UTF-16LE') : mb_convert_encoding(substr($s, $o, $cch), 'UTF-8', 'Windows-1252');
    }

    /** The shared string table, which may break across CONTINUE records (even mid-string). */
    private static function sst(array $parts, $count){
        $p = 0; $buf = $parts[0]; $o = 0; $out = [];
        $need = function($n) use (&$buf, &$o, &$p, $parts){
            while ( strlen($buf) - $o < $n && isset($parts[$p + 1]) ) { $buf = substr($buf, $o) . $parts[++$p]; $o = 0; }
        };
        for ( $i = 0; $i < $count; $i++ ) {
            $need(3);
            if ( strlen($buf) - $o < 3 ) break;
            $cch = unpack('v', substr($buf, $o, 2))[1]; $flags = ord($buf[$o + 2]); $o += 3;
            $runs = 0; $ext = 0;
            if ( $flags & 0x08 ) { $need(2); $runs = unpack('v', substr($buf, $o, 2))[1]; $o += 2; }
            if ( $flags & 0x04 ) { $need(4); $ext = unpack('V', substr($buf, $o, 4))[1]; $o += 4; }
            $str = ''; $left = $cch; $wide = (bool) ($flags & 0x01);
            while ( $left > 0 ) {
                $avail = strlen($buf) - $o;
                if ( $avail <= 0 ) {
                    if ( ! isset($parts[$p + 1]) ) break;
                    $buf = $parts[++$p]; $o = 0;
                    $wide = (bool) (ord($buf[0]) & 0x01); $o = 1; // a CONTINUE restarts with the width flag
                    continue;
                }
                $take = min($left, $wide ? intdiv($avail, 2) : $avail);
                if ( $take <= 0 ) { $buf = substr($buf, $o) . ($parts[++$p] ?? ''); $o = 0; continue; }
                $chunk = substr($buf, $o, $wide ? $take * 2 : $take);
                $str .= $wide ? mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($chunk, 'UTF-8', 'Windows-1252');
                $o += $wide ? $take * 2 : $take; $left -= $take;
                if ( $left > 0 && $o >= strlen($buf) && isset($parts[$p + 1]) ) { $buf = $parts[++$p]; $wide = (bool) (ord($buf[0]) & 0x01); $o = 1; }
            }
            $skip = $runs * 4 + $ext;
            while ( $skip > 0 ) {
                $avail = strlen($buf) - $o;
                if ( $avail >= $skip ) { $o += $skip; $skip = 0; }
                else { $skip -= $avail; if ( ! isset($parts[$p + 1]) ) break; $buf = $parts[++$p]; $o = 0; }
            }
            $out[] = $str;
        }
        return $out;
    }
}
