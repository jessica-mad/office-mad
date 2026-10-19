<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * Looks inside an m4a/mp4 voice note (which codec it carries) and, when it is Opus,
 * rewraps it as Ogg Opus — same sound, no decoding — because Speech-to-Text takes
 * Ogg Opus but not Opus in an mp4 box. Pure PHP: the server needs no ffmpeg.
 */
final class Mp4Audio {

    const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'moof', 'traf', 'mvex', 'edts'];

    private $b;
    private $boxes = [];

    public function __construct($bytes){
        $this->b = (string) $bytes;
        $this->walk(0, strlen($this->b), '');
    }

    /** Every box as [path, start, header size, size]. */
    private function walk($o, $end, $path){
        while ( $o + 8 <= $end ) {
            $size = unpack('N', substr($this->b, $o, 4))[1];
            $type = substr($this->b, $o + 4, 4);
            $hs = 8;
            if ( $size === 1 ) { $size = (int) unpack('J', substr($this->b, $o + 8, 8))[1]; $hs = 16; }
            elseif ( $size === 0 ) $size = $end - $o;
            if ( $size < $hs || $o + $size > $end ) break;
            $p = $path . '/' . $type;
            $this->boxes[] = ['path' => $p, 'start' => $o, 'hs' => $hs, 'size' => $size];
            if ( in_array($type, self::CONTAINERS, true) ) $this->walk($o + $hs, $o + $size, $p);
            $o += $size;
        }
    }

    private function find($suffix, $all = false){
        $out = array_values(array_filter($this->boxes, fn($x) => str_ends_with($x['path'], $suffix)));
        return $all ? $out : ($out[0] ?? null);
    }

    private function body(array $box){ return substr($this->b, $box['start'] + $box['hs'], $box['size'] - $box['hs']); }

    /** «Opus», «mp4a», «alac»… ('' if it is not an mp4). */
    public function codec(){
        $stsd = $this->find('/stbl/stsd');
        if ( ! $stsd ) return '';
        return rtrim(substr($this->body($stsd), 12, 4)); // 4 version/flags, 4 count, 4 entry size, then the type
    }

    public function fragmented(){ return (bool) $this->find('/moof'); }

    /** Whether the index («moov») comes after the sound («mdat»): fine for a player, not for a reader in chunks. */
    public function index_at_end(){
        $top = array_values(array_filter($this->boxes, fn($x) => substr_count($x['path'], '/') === 1));
        $types = array_map(fn($x) => substr($x['path'], 1), $top);
        $moov = array_search('moov', $types, true); $mdat = array_search('mdat', $types, true);
        return $moov !== false && $mdat !== false && $moov > $mdat;
    }

    /**
     * The same file with the index first («faststart»): boxes reordered and the chunk
     * offsets in stco/co64 moved by as much as their data moved. Not a byte of sound changes.
     * @return string|null null if it already was (or it is not an mp4)
     */
    public function faststart(){
        if ( ! $this->index_at_end() ) return null;
        $top = array_values(array_filter($this->boxes, fn($x) => substr_count($x['path'], '/') === 1));
        $moov = array_values(array_filter($top, fn($x) => $x['path'] === '/moov'))[0];
        $firstData = min(array_map(fn($x) => $x['start'], array_filter($top, fn($x) => $x['path'] === '/mdat')));
        $before = array_values(array_filter($top, fn($x) => $x['start'] < $firstData && $x['path'] !== '/moov'));
        $after = array_values(array_filter($top, fn($x) => $x['start'] >= $firstData && $x['path'] !== '/moov'));
        $order = array_merge($before, [$moov], $after);
        $new = []; $pos = 0;
        foreach ( $order as $x ) { $new[$x['start']] = $pos; $pos += $x['size']; }
        $shift = function($off) use ($top, $new){
            foreach ( $top as $x ) if ( $off >= $x['start'] && $off < $x['start'] + $x['size'] ) return $off - $x['start'] + $new[$x['start']];
            return $off;
        };
        $mv = substr($this->b, $moov['start'], $moov['size']);
        foreach ( $this->boxes as $box ) {
            $wide = str_ends_with($box['path'], '/stbl/co64');
            if ( ! $wide && ! str_ends_with($box['path'], '/stbl/stco') ) continue;
            if ( ! str_starts_with($box['path'], '/moov/') ) continue;
            $rel = $box['start'] - $moov['start'] + $box['hs'];
            $n = unpack('N', substr($mv, $rel + 4, 4))[1];
            for ( $i = 0; $i < $n; $i++ ) {
                $at = $rel + 8 + ($wide ? 8 : 4) * $i;
                $old = $wide ? (int) unpack('J', substr($mv, $at, 8))[1] : unpack('N', substr($mv, $at, 4))[1];
                $mv = substr_replace($mv, $wide ? pack('J', $shift($old)) : pack('N', $shift($old)), $at, $wide ? 8 : 4);
            }
        }
        $out = '';
        foreach ( $order as $x ) $out .= $x['path'] === '/moov' ? $mv : substr($this->b, $x['start'], $x['size']);
        return $out;
    }

    /** The AAC flavour (LC, HE-AAC, ELD…) and its sample rate, from the decoder config in «esds». */
    public function aac_profile(){
        $i = strpos($this->b, 'esds');
        if ( $i === false ) return '';
        $d = substr($this->b, $i + 8, 120); // after version/flags
        $p = strpos($d, "\x05"); // DecoderSpecificInfo tag
        while ( $p !== false ) {
            $q = $p + 1; $len = 0;
            for ( $k = 0; $k < 4; $k++ ) { $c = ord($d[$q++] ?? "\0"); $len = ($len << 7) | ($c & 0x7F); if ( ! ($c & 0x80) ) break; }
            if ( $len >= 2 && $len < 64 ) {
                $bits = (ord($d[$q]) << 8) | ord($d[$q + 1]);
                $aot = $bits >> 11; $sr = ($bits >> 7) & 0x0F; $ch = ($bits >> 3) & 0x0F;
                if ( $aot === 31 ) return 'AAC extendido';
                $names = [1 => 'AAC Main', 2 => 'AAC-LC', 5 => 'HE-AAC', 23 => 'AAC-LD', 29 => 'HE-AACv2', 39 => 'AAC-ELD'];
                $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
                return ($names[$aot] ?? 'AAC tipo ' . $aot) . (isset($rates[$sr]) ? ' ' . $rates[$sr] . ' Hz' : '') . ($ch ? ', ' . $ch . ' canal' . ($ch > 1 ? 'es' : '') : '');
            }
            $p = strpos($d, "\x05", $p + 1);
        }
        return '';
    }

    /** @return string[] the audio frames, in order */
    private function samples(){
        $out = [];
        if ( $this->fragmented() ) {
            $trex = $this->find('/mvex/trex');
            $dsize = $trex ? unpack('N', substr($this->body($trex), 16, 4))[1] : 0;
            foreach ( $this->find('/moof', true) as $moof ) {
                foreach ( array_filter($this->boxes, fn($x) => $x['start'] > $moof['start'] && $x['start'] < $moof['start'] + $moof['size']) as $box ) {
                    if ( str_ends_with($box['path'], '/traf/tfhd') ) {
                        $t = $this->body($box); $flags = unpack('N', substr($t, 0, 4))[1] & 0xFFFFFF; $p = 8;
                        $base = $moof['start'];
                        if ( $flags & 0x01 ) { $base = (int) unpack('J', substr($t, $p, 8))[1]; $p += 8; }
                        if ( $flags & 0x02 ) $p += 4;
                        if ( $flags & 0x08 ) $p += 4;
                        if ( $flags & 0x10 ) { $dsize = unpack('N', substr($t, $p, 4))[1]; $p += 4; }
                    } elseif ( str_ends_with($box['path'], '/traf/trun') ) {
                        $t = $this->body($box); $flags = unpack('N', substr($t, 0, 4))[1] & 0xFFFFFF;
                        $n = unpack('N', substr($t, 4, 4))[1]; $p = 8;
                        $off = $base ?? $moof['start'];
                        if ( $flags & 0x01 ) { $off += unpack('l', pack('L', unpack('N', substr($t, $p, 4))[1]))[1]; $p += 4; }
                        if ( $flags & 0x04 ) $p += 4;
                        for ( $i = 0; $i < $n; $i++ ) {
                            if ( $flags & 0x100 ) $p += 4;
                            $size = $dsize;
                            if ( $flags & 0x200 ) { $size = unpack('N', substr($t, $p, 4))[1]; $p += 4; }
                            if ( $flags & 0x400 ) $p += 4;
                            if ( $flags & 0x800 ) $p += 4;
                            $out[] = substr($this->b, $off, $size);
                            $off += $size;
                        }
                    }
                }
            }
            return $out;
        }
        $stsz = $this->find('/stbl/stsz'); $stsc = $this->find('/stbl/stsc'); $stco = $this->find('/stbl/stco') ?: $this->find('/stbl/co64');
        if ( ! $stsz || ! $stsc || ! $stco ) return [];
        $z = $this->body($stsz); $uniform = unpack('N', substr($z, 4, 4))[1]; $count = unpack('N', substr($z, 8, 4))[1];
        $sizes = [];
        for ( $i = 0; $i < $count; $i++ ) $sizes[] = $uniform ?: unpack('N', substr($z, 12 + 4 * $i, 4))[1];
        $c = $this->body($stco); $wide = str_ends_with($stco['path'], 'co64'); $chunks = unpack('N', substr($c, 4, 4))[1];
        $offsets = [];
        for ( $i = 0; $i < $chunks; $i++ ) $offsets[] = $wide ? (int) unpack('J', substr($c, 8 + 8 * $i, 8))[1] : unpack('N', substr($c, 8 + 4 * $i, 4))[1];
        $s = $this->body($stsc); $runs = unpack('N', substr($s, 4, 4))[1]; $table = [];
        for ( $i = 0; $i < $runs; $i++ ) $table[] = unpack('Nfirst/Nper/Ndesc', substr($s, 8 + 12 * $i, 12));
        $k = 0;
        foreach ( $offsets as $ci => $off ) {
            $per = 0;
            foreach ( $table as $row ) if ( $ci + 1 >= $row['first'] ) $per = $row['per'];
            for ( $j = 0; $j < $per && $k < count($sizes); $j++, $k++ ) { $out[] = substr($this->b, $off, $sizes[$k]); $off += $sizes[$k]; }
        }
        return $out;
    }

    /** Media timescale and length, from «mdhd». @return array{scale:int, units:int}|null */
    private function media_time(){
        $mdhd = $this->find('/mdia/mdhd');
        if ( ! $mdhd ) return null;
        $m = $this->body($mdhd);
        return ord($m[0]) === 1
            ? ['scale' => unpack('N', substr($m, 20, 4))[1], 'units' => (int) unpack('J', substr($m, 24, 8))[1]]
            : ['scale' => unpack('N', substr($m, 12, 4))[1], 'units' => unpack('N', substr($m, 16, 4))[1]];
    }

    /** Seconds of audio (0 if unknown). */
    public function duration(){
        $t = $this->media_time();
        return $t && $t['scale'] ? $t['units'] / $t['scale'] : 0.0;
    }

    /** Duration of each sample in media units, from «stts». @return int[] */
    private function deltas(){
        $stts = $this->find('/stbl/stts');
        if ( ! $stts ) return [];
        $t = $this->body($stts); $n = unpack('N', substr($t, 4, 4))[1]; $out = [];
        for ( $i = 0; $i < $n; $i++ ) { $e = unpack('Ncount/Ndelta', substr($t, 8 + 8 * $i, 8)); for ( $k = 0; $k < $e['count']; $k++ ) $out[] = $e['delta']; }
        return $out;
    }

    private static function box($type, $payload){ return pack('N', 8 + strlen($payload)) . $type . $payload; }

    /** A box with its duration field rewritten (mvhd, tkhd, mdhd; versions 0 and 1). */
    private function with_duration($suffix, $units){
        $box = $this->find($suffix);
        $b = $this->body($box); $v1 = ord($b[0]) === 1;
        $at = ['mvhd' => $v1 ? 24 : 16, 'tkhd' => $v1 ? 28 : 20, 'mdhd' => $v1 ? 24 : 16][substr($suffix, -4)];
        $b = substr_replace($b, $v1 ? pack('J', $units) : pack('N', $units), $at, $v1 ? 8 : 4);
        return self::box(substr($suffix, -4), $b);
    }

    /**
     * The audio cut into pieces of at most $seconds, each one a complete m4a of its own
     * (same frames, nothing re-encoded), for recognisers that take one minute at a time.
     * @return string[] (just the original if it is short, or it can't be cut)
     */
    public function split($seconds){
        $t = $this->media_time();
        if ( ! $t || ! $t['scale'] || $this->fragmented() || $this->duration() <= $seconds ) return [$this->b];
        $frames = $this->samples(); $deltas = $this->deltas();
        if ( ! $frames || count($deltas) !== count($frames) ) return [$this->b];
        $mvhd = $this->find('/moov/mvhd'); $mv = $this->body($mvhd);
        $movie_scale = ord($mv[0]) === 1 ? unpack('N', substr($mv, 20, 4))[1] : unpack('N', substr($mv, 12, 4))[1];
        $keep = fn($suffix) => ($x = $this->find($suffix)) ? substr($this->b, $x['start'], $x['size']) : '';
        $ftyp = $keep('/ftyp');
        // Equal pieces (3 × 59 s rather than 50 + 50 + 50 + 27), never longer than asked.
        $total = array_sum($deltas);
        $limit = (int) min($seconds * $t['scale'], ceil($total / ceil($total / ($seconds * $t['scale']))) + max($deltas)); // + one frame: no crumbs left over
        $pieces = []; $start = 0; $n = count($frames);
        while ( $start < $n ) {
            $units = 0; $end = $start;
            while ( $end < $n && $units + $deltas[$end] <= $limit ) $units += $deltas[$end++];
            if ( $end === $start ) $end++;
            $data = implode('', array_slice($frames, $start, $end - $start));
            $count = $end - $start;
            $stts = ''; $runs = 0; $prev = null; $run = 0;
            foreach ( array_slice($deltas, $start, $count) as $d ) {
                if ( $d === $prev ) { $run++; continue; }
                if ( $prev !== null ) { $stts .= pack('NN', $run, $prev); $runs++; }
                $prev = $d; $run = 1;
            }
            $stts .= pack('NN', $run, $prev); $runs++;
            $sizes = implode('', array_map(fn($f) => pack('N', strlen($f)), array_slice($frames, $start, $count)));
            $build = function($chunk_offset) use ($keep, $stts, $runs, $count, $sizes, $units, $t, $movie_scale){
                $stbl = self::box('stbl', $keep('/stbl/stsd')
                    . self::box('stts', "\0\0\0\0" . pack('N', $runs) . $stts)
                    . self::box('stsc', "\0\0\0\0" . pack('NNNN', 1, 1, $count, 1))
                    . self::box('stsz', "\0\0\0\0" . pack('NN', 0, $count) . $sizes)
                    . self::box('stco', "\0\0\0\0" . pack('NN', 1, $chunk_offset)));
                $minf = self::box('minf', $keep('/minf/smhd') . $keep('/minf/dinf') . $stbl);
                $mdia = self::box('mdia', $this->with_duration('/mdia/mdhd', $units) . $keep('/mdia/hdlr') . $minf);
                $trak = self::box('trak', $this->with_duration('/trak/tkhd', (int) round($units / $t['scale'] * $movie_scale)) . $mdia);
                return self::box('moov', $this->with_duration('/moov/mvhd', (int) round($units / $t['scale'] * $movie_scale)) . $trak);
            };
            $moov = $build(0);
            $moov = $build(strlen($ftyp) + strlen($moov) + 8); // the sound starts right after ftyp, moov and mdat's header
            $pieces[] = $ftyp . $moov . self::box('mdat', $data);
            $start = $end;
        }
        return $pieces;
    }

    /** @return string|null the same Opus audio as an Ogg Opus file */
    public function opus_to_ogg(){
        if ( $this->codec() !== 'Opus' ) return null;
        $dops = $this->find('/dOps');
        $stsd = $this->find('/stbl/stsd');
        if ( ! $dops && $stsd ) {
            $raw = $this->body($stsd); $i = strpos($raw, 'dOps'); // inside the Opus sample entry
            $d = $i !== false ? substr($raw, $i + 4, unpack('N', substr($raw, $i - 4, 4))[1] - 8) : '';
        } else $d = $dops ? $this->body($dops) : '';
        if ( strlen($d) < 11 ) return null;
        $channels = ord($d[1]); $preskip = unpack('n', substr($d, 2, 2))[1]; $rate = unpack('N', substr($d, 4, 4))[1];
        $gain = unpack('n', substr($d, 8, 2))[1]; $family = ord($d[10]);
        $head = 'OpusHead' . chr(1) . chr($channels) . pack('v', $preskip) . pack('V', $rate) . pack('v', $gain) . chr($family) . ($family ? substr($d, 11) : '');
        $tags = 'OpusTags' . pack('V', 11) . 'MAD Oficina' . pack('V', 0);
        $frames = $this->samples();
        if ( ! $frames ) return null;
        $serial = mt_rand(1, 0x7FFFFFFF);
        $ogg = self::page($head, 0, $serial, 0, 0x02) . self::page($tags, 0, $serial, 1, 0);
        $granule = 0; $seq = 2; $last = count($frames) - 1;
        foreach ( $frames as $i => $f ) {
            $granule += self::opus_samples($f);
            $ogg .= self::page($f, $granule, $serial, $seq++, $i === $last ? 0x04 : 0);
        }
        return $ogg;
    }

    /** 48 kHz samples in one Opus packet (RFC 6716, TOC byte and frame count). */
    public static function opus_samples($packet){
        if ( $packet === '' ) return 0;
        $toc = ord($packet[0]); $config = $toc >> 3;
        if ( $config < 12 ) $per = [480, 960, 1920, 2880][$config & 3];
        elseif ( $config < 16 ) $per = [480, 960][$config & 1];
        else $per = [120, 240, 480, 960][$config & 3];
        $code = $toc & 3;
        $frames = $code === 0 ? 1 : ($code === 3 ? (strlen($packet) > 1 ? ord($packet[1]) & 0x3F : 1) : 2);
        return $per * $frames;
    }

    private static function page($packet, $granule, $serial, $seq, $type){
        $lacing = '';
        $len = strlen($packet);
        for ( $n = $len; $n >= 255; $n -= 255 ) $lacing .= chr(255);
        $lacing .= chr($n);
        $page = 'OggS' . chr(0) . chr($type) . pack('P', $granule) . pack('V', $serial) . pack('V', $seq) . pack('V', 0) . chr(strlen($lacing)) . $lacing . $packet;
        return substr($page, 0, 22) . pack('V', self::crc($page)) . substr($page, 26);
    }

    /** Ogg's CRC-32 (polynomial 0x04C11DB7, not reflected). */
    private static function crc($data){
        static $t = null;
        if ( $t === null ) {
            $t = [];
            for ( $i = 0; $i < 256; $i++ ) {
                $r = $i << 24;
                for ( $j = 0; $j < 8; $j++ ) $r = ($r & 0x80000000) ? (($r << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($r << 1) & 0xFFFFFFFF;
                $t[$i] = $r;
            }
        }
        $crc = 0;
        for ( $i = 0, $n = strlen($data); $i < $n; $i++ ) $crc = (($crc << 8) & 0xFFFFFFFF) ^ $t[(($crc >> 24) ^ ord($data[$i])) & 0xFF];
        return $crc;
    }
}
