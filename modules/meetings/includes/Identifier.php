<?php
namespace MAD_Oficina\Meetings;

use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;

/**
 * Works out which client a meeting belongs to, in this order of strength:
 *   1. the title: "RF x MAD", "Olofane X MAD" → client code or name before "x MAD";
 *   2. attendee emails known as contacts (work or personal);
 *   3. attendee email domains, contact names/aliases in the title ("Meeting MAD (Ana Pérez)").
 * When it is not clear, it returns no client and the draft asks Jessica.
 */
final class Identifier {

    const MIN_SCORE = 4;
    const MARGIN    = 2;

    /**
     * @param string $title
     * @param array  $attendees [['email' => ..., 'name' => ...], ...]
     * @param string $own_domain emails from this domain (MAD) are ignored
     * @return array{client_id:?int, confidence:string, reasons:array, candidates:array, unknown:array}
     */
    public static function identify($title, array $attendees, $own_domain = ''){
        $title = trim((string) $title);
        $subject = self::subject($title);
        $title_norm = ' ' . self::norm($title) . ' ';
        $own_domain = strtolower($own_domain);

        $scores = [];
        $add = function($client_id, $points, $reason) use (&$scores){
            $scores[$client_id] ??= ['score' => 0, 'reasons' => []];
            $scores[$client_id]['score'] += $points;
            $scores[$client_id]['reasons'][] = $reason;
        };

        $clients = array_filter(Clients::all(), fn($c) => $c['status'] !== 'archived');
        foreach ( $clients as $c ){
            $code_norm = self::norm($c['code']);
            $name_norm = self::norm($c['name']);
            if ( $subject !== '' ) {
                $s = self::norm($subject);
                if ( $s === $code_norm ) $add($c['id'], 6, sprintf('El título empieza por su código (%s)', $c['code']));
                elseif ( $s !== '' && ($s === $name_norm || (strlen($s) >= 4 && (str_contains($name_norm, $s) || str_contains($s, $name_norm)))) ) $add($c['id'], 5, 'El título lleva su nombre');
            } elseif ( preg_match('/(^|[^A-Za-z0-9])' . preg_quote($c['code'], '/') . '([^A-Za-z0-9]|$)/', $title) ) {
                $add($c['id'], 5, sprintf('Su código %s aparece en el título', $c['code']));
            }
            foreach ( Clients::contacts($c['id']) as $contact ){
                foreach ( array_merge([$contact['name']], $contact['aliases']) as $alias ){
                    $a = self::norm($alias);
                    if ( strlen($a) >= 3 && str_contains($title_norm, ' ' . $a . ' ') ) {
                        $add($c['id'], 3, sprintf('%s aparece en el título', $alias));
                        break;
                    }
                }
            }
        }

        $unknown = [];
        foreach ( self::clean_attendees($attendees) as $att ){
            $email = $att['email'];
            if ( $own_domain && str_ends_with($email, '@' . $own_domain) ) continue;
            $hit = Clients::find_by_email($email);
            if ( $hit ) { $add($hit['client_id'], 4, sprintf('Invitado conocido: %s', $email)); continue; }
            $unknown[] = $att;
            $by_domain = Clients::find_by_domain($email);
            if ( $by_domain ) $add($by_domain['id'], 3, sprintf('Dominio de %s', $email));
        }

        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        $ids = array_keys($scores);
        $best = $ids[0] ?? null;
        $best_score = $best ? $scores[$best]['score'] : 0;
        $second = isset($ids[1]) ? $scores[$ids[1]]['score'] : 0;

        $client_id = ($best && $best_score >= self::MIN_SCORE && $best_score - $second >= self::MARGIN) ? (int) $best : null;
        return [
            'client_id'  => $client_id,
            'confidence' => $client_id ? ($best_score >= 6 ? 'high' : 'medium') : 'none',
            'reasons'    => $client_id ? $scores[$best]['reasons'] : [],
            'candidates' => array_map(fn($id) => ['client_id' => (int) $id, 'score' => $scores[$id]['score'], 'reasons' => $scores[$id]['reasons']], array_slice($ids, 0, 3)),
            'unknown'    => $unknown,
        ];
    }

    /** "RF x MAD" → "RF"; "MAD x Olofane" → "Olofane"; anything else → "". */
    public static function subject($title){
        $title = preg_replace('/\s*\(recurring\)\s*$/i', '', (string) $title);
        if ( preg_match('/^\s*(.+?)\s+x\s+MAD\b/iu', $title, $m) ) return trim($m[1]);
        if ( preg_match('/^\s*MAD\s+x\s+(.+?)\s*$/iu', $title, $m) ) return trim($m[1]);
        return '';
    }

    /** Lowercase, no accents, words separated by single spaces. */
    public static function norm($text){
        $text = strtolower(remove_accents((string) $text));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Valid, unique, lowercased attendees. */
    public static function clean_attendees(array $attendees){
        $out = [];
        foreach ( $attendees as $a ){
            $email = strtolower(sanitize_email(is_array($a) ? ($a['email'] ?? '') : $a));
            if ( ! $email || isset($out[$email]) ) continue;
            $out[$email] = ['email' => $email, 'name' => sanitize_text_field(is_array($a) ? ($a['name'] ?? '') : '')];
        }
        return array_values($out);
    }

    /** Attendees written as Markdown mailto links in Gemini notes: [Name](mailto:email). */
    public static function attendees_from_notes($text){
        $out = [];
        if ( preg_match_all('/\[([^\]\n]{1,80})\]\(mailto:([^)\s]+)\)/u', (string) $text, $m, PREG_SET_ORDER) ) {
            foreach ( $m as $hit ) $out[] = ['email' => $hit[2], 'name' => trim($hit[1], " *")];
        }
        return self::clean_attendees($out);
    }
}
