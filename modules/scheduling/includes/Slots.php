<?php
namespace MAD_Oficina\Scheduling;

if ( ! defined('ABSPATH') ) exit;

/**
 * Free-slot engine. Pure function of the rules, the busy intervals (read live
 * from Calendar) and "now", so it can be tested without Google.
 */
final class Slots {

    const DEFAULT_RULES = [
        'hours'        => [1 => '10:00-14:00, 16:00-18:00', 2 => '10:00-14:00, 16:00-18:00', 3 => '10:00-14:00, 16:00-18:00', 4 => '10:00-14:00, 16:00-18:00', 5 => '10:00-14:00, 16:00-18:00', 6 => '', 7 => ''],
        'duration'     => 45,
        'buffer'       => 15,
        'min_notice'   => 24,
        'max_per_day'  => 3,
        'horizon_days' => 14,
        'blocked'      => [],
        'step'         => 30,
    ];

    public static function rules(array $saved){
        $r = array_merge(self::DEFAULT_RULES, array_intersect_key($saved, self::DEFAULT_RULES));
        $r['hours'] = array_replace(self::DEFAULT_RULES['hours'], (array) $r['hours']);
        return $r;
    }

    /** "10:00-14:00, 16:00-18:00" → [[600, 840], [960, 1080]] (minutes from midnight). */
    public static function windows($text){
        $out = [];
        foreach ( preg_split('/[,;]+/', (string) $text) as $part ) {
            if ( preg_match('/(\d{1,2}):?(\d{2})?\s*-\s*(\d{1,2}):?(\d{2})?/', $part, $m) ) {
                $a = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
                $b = (int) $m[3] * 60 + (int) ($m[4] ?? 0);
                if ( $b > $a ) $out[] = [$a, $b];
            }
        }
        return $out;
    }

    /**
     * @param array $busy [[start_ts, end_ts], ...]
     * @return array<int,array{start:int,end:int}>
     */
    public static function compute(array $rules, array $busy, $now, \DateTimeZone $tz){
        $rules = self::rules($rules);
        $dur = (int) $rules['duration'] * 60;
        $buf = (int) $rules['buffer'] * 60;
        $earliest = $now + (int) $rules['min_notice'] * 3600;
        $out = [];

        $day = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        for ( $i = 0; $i <= (int) $rules['horizon_days']; $i++, $day = $day->modify('+1 day') ) {
            if ( in_array($day->format('Y-m-d'), (array) $rules['blocked'], true) ) continue;
            $windows = self::windows($rules['hours'][(int) $day->format('N')] ?? '');
            if ( ! $windows ) continue;

            // Days already full: busy blocks inside working hours count as meetings.
            $taken = 0;
            foreach ( $busy as [$bs, $be] ) {
                foreach ( $windows as [$a, $b] ) {
                    if ( $bs < $day->getTimestamp() + $b * 60 && $be > $day->getTimestamp() + $a * 60 ) { $taken++; break; }
                }
            }
            if ( $taken >= (int) $rules['max_per_day'] ) continue;

            foreach ( $windows as [$a, $b] ) {
                for ( $m = $a; $m + $rules['duration'] <= $b; $m += (int) $rules['step'] ) {
                    // Wall-clock time in the site timezone (handles DST changes).
                    $start = $day->setTime(intdiv($m, 60), $m % 60)->getTimestamp();
                    $end = $start + $dur;
                    if ( $start < $earliest ) continue;
                    foreach ( $busy as [$bs, $be] ) {
                        if ( $bs < $end + $buf && $be > $start - $buf ) continue 2;
                    }
                    $out[] = ['start' => $start, 'end' => $end];
                }
            }
        }
        return $out;
    }

    /**
     * A short, varied offer: the first slots of different days, alternating
     * morning and afternoon, at most two per day.
     */
    public static function offer(array $slots, $n, \DateTimeZone $tz){
        $by_day = [];
        foreach ( $slots as $s ) {
            $d = (new \DateTimeImmutable('@' . $s['start']))->setTimezone($tz);
            $by_day[$d->format('Y-m-d')][(int) $d->format('H') < 14 ? 'am' : 'pm'][] = $s;
        }
        $out = [];
        $used = [];
        $prefer = 'am';
        // Round 1: one slot per day, alternating morning/afternoon between days.
        foreach ( $by_day as $day => $halves ) {
            $half = ! empty($halves[$prefer]) ? $prefer : ($prefer === 'am' ? 'pm' : 'am');
            $out[] = $halves[$half][0];
            $used[$day] = $half;
            $prefer = $prefer === 'am' ? 'pm' : 'am';
            if ( count($out) >= $n ) break;
        }
        // Round 2: if still short, the other half of the same days.
        foreach ( $by_day as $day => $halves ) {
            if ( count($out) >= $n ) break;
            $other = ($used[$day] ?? 'am') === 'am' ? 'pm' : 'am';
            if ( ! empty($halves[$other]) ) $out[] = $halves[$other][0];
        }
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
        return array_slice($out, 0, $n);
    }

    /** "martes 13 de octubre · 10:00", in Spanish whatever the site language is. */
    public static function label($ts, \DateTimeZone $tz){
        $d = (new \DateTimeImmutable('@' . (int) $ts))->setTimezone($tz);
        $days = [1 => __('lunes', 'mad-oficina'), __('martes', 'mad-oficina'), __('miércoles', 'mad-oficina'), __('jueves', 'mad-oficina'), __('viernes', 'mad-oficina'), __('sábado', 'mad-oficina'), __('domingo', 'mad-oficina')];
        $months = [1 => __('enero', 'mad-oficina'), __('febrero', 'mad-oficina'), __('marzo', 'mad-oficina'), __('abril', 'mad-oficina'), __('mayo', 'mad-oficina'), __('junio', 'mad-oficina'), __('julio', 'mad-oficina'), __('agosto', 'mad-oficina'), __('septiembre', 'mad-oficina'), __('octubre', 'mad-oficina'), __('noviembre', 'mad-oficina'), __('diciembre', 'mad-oficina')];
        return sprintf(__('%1$s %2$d de %3$s · %4$s', 'mad-oficina'), $days[(int) $d->format('N')], (int) $d->format('j'), $months[(int) $d->format('n')], $d->format('H:i'));
    }
}
