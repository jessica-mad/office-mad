<?php
namespace MAD_Oficina\Suppliers;

use MAD_Oficina\Core\Time;
use MAD_Oficina\Meetings\Orders;

if ( ! defined('ABSPATH') ) exit;

/**
 * The same supplier invoice received twice: same supplier, amount, date and invoice number.
 *  · The very same document (one file, or the same email attachment): the extra one is
 *    discarded as «Repetida», nothing else to do.
 *  · Two different documents: only the oldest counts; both files go together to
 *    «Duplicados/<fecha proveedor importe nº>/» in the archive, and Jessica decides on the
 *    Duplicados tab which one stays (it goes back to its quarter) or that they are not duplicates.
 * Nothing is deleted from Drive.
 */
final class Duplicates {

    const OPTION = 'madoficina_expense_duplicates';
    const STATUSES = ['registered', 'review'];

    private $root;

    /** @param string $root the archive folder (Intake::archive_root()) */
    public function __construct($root = ''){ $this->root = (string) $root; }

    /** Groups already handled: key => {ids, keep, prev: {id: status}, state: open|done|dismissed, identical, at}. */
    public static function groups(){
        $g = get_option(self::OPTION, []);
        return is_array($g) ? $g : [];
    }

    private static function store(array $g){ update_option(self::OPTION, $g, false); }

    public static function open(){
        return array_filter(self::groups(), fn($g) => $g['state'] === 'open');
    }

    private static function norm($s){
        return strtolower(preg_replace('/[^a-z0-9]/i', '', remove_accents((string) $s)));
    }

    /** Same supplier · same date · same amount · same invoice number (no number on both counts as the same). */
    public static function key(array $e){
        $who = $e['supplier_id'] ? 's' . (int) $e['supplier_id'] : ( $e['supplier_tax_id'] ? 't' . self::norm($e['supplier_tax_id']) : 'n' . self::norm($e['supplier_name']) );
        if ( $who === 'n' || ! $e['issue_date'] || $e['total'] === null ) return null;
        return md5($who . '|' . $e['issue_date'] . '|' . number_format((float) $e['total'], 2, '.', '') . '|' . self::norm($e['number']));
    }

    /** The very same document: one file in Drive, the same uploaded file, or the same attachment of the same email. */
    public static function same_document(array $a, array $b){
        foreach ( ['file_hash', 'drive_file_id', 'source_ref'] as $k ) if ( ! empty($a[$k]) && $a[$k] === $b[$k] ) return true;
        return ! empty($a['gmail_message_id']) && $a['gmail_message_id'] === $b['gmail_message_id'] && (string) $a['filename'] === (string) $b['filename'];
    }

    /** «2025-03-01 Hosting Nube 49,90 Nº HN-77» */
    public static function folder_name(array $e){
        $name = trim(preg_replace('/[^A-Za-z0-9 ]+/', ' ', remove_accents((string) ($e['supplier_name'] ?: 'Proveedor'))));
        return trim(sprintf('%s %s %s%s', $e['issue_date'], mb_substr($name, 0, 40), number_format((float) $e['total'], 2, ',', ''), $e['number'] ? ' Nº ' . preg_replace('/[^A-Za-z0-9\-]+/', '-', $e['number']) : ''));
    }

    /**
     * Looks for new duplicates among registered and to-review expenses and handles them.
     * @return array{identical:int, grouped:int}
     */
    public function scan(){
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Expenses::table() . " WHERE status IN ('registered','review') AND issue_date IS NOT NULL AND total IS NOT NULL ORDER BY id", ARRAY_A);
        $by = [];
        foreach ( $rows as $r ) {
            $e = Expenses::get((int) $r['id']);
            if ( $e && ($k = self::key($e)) ) $by[$k][] = $e;
        }
        $groups = self::groups();
        $out = ['identical' => 0, 'grouped' => 0];
        foreach ( $by as $k => $list ) {
            if ( count($list) < 2 ) continue;
            $known = $groups[$k] ?? null;
            if ( $known && $known['state'] === 'dismissed' && ! array_diff(array_column($list, 'id'), $known['ids']) ) continue; // «no son duplicadas»
            $keep = $known && in_array($known['keep'], array_column($list, 'id'), true) ? self::find($list, $known['keep']) : self::pick($list);
            $g = $known ?: ['ids' => [], 'keep' => (int) $keep['id'], 'prev' => [], 'state' => 'done', 'identical' => true, 'at' => Time::now(), 'folder' => self::folder_name($keep)];
            foreach ( $list as $e ) {
                $g['ids'] = array_values(array_unique(array_merge($g['ids'], [(int) $e['id']])));
                if ( (int) $e['id'] === (int) $g['keep'] ) continue;
                $g['prev'][(int) $e['id']] = $e['status'];
                if ( self::same_document($keep, $e) ) {
                    Expenses::update($e['id'], ['status' => 'ignored', 'reason' => 'duplicate', 'notes' => trim(sprintf(__('Repetida: el mismo documento que el gasto #%d.', 'mad-oficina'), $keep['id']) . "\n" . (string) $e['notes'])]);
                    $out['identical']++;
                    continue;
                }
                // Two documents of the same invoice: only one counts, both side by side for her to see.
                Expenses::update($e['id'], ['status' => 'ignored', 'reason' => 'duplicate', 'notes' => trim(sprintf(__('¿Duplicada? Mismo proveedor, importe, fecha y número que el gasto #%d, con otro documento. Están juntas en Duplicados.', 'mad-oficina'), $keep['id']) . "\n" . (string) $e['notes'])]);
                $this->move($e, ['Duplicados', $g['folder']]);
                $g['identical'] = false;
                $g['state'] = 'open';
                $out['grouped']++;
            }
            if ( $g['state'] === 'open' && ! $known ) $this->move($keep, ['Duplicados', $g['folder']]);
            $groups[$k] = $g;
        }
        self::store($groups);
        return $out;
    }

    private static function find(array $list, $id){
        foreach ( $list as $e ) if ( (int) $e['id'] === (int) $id ) return $e;
        return $list[0];
    }

    /** The one that counts while she decides: the registered one first, then the oldest. */
    private static function pick(array $list){
        usort($list, fn($a, $b) => [$a['status'] !== 'registered', (int) $a['id']] <=> [$b['status'] !== 'registered', (int) $b['id']]);
        return $list[0];
    }

    /** «Quedarme con esta»: it counts and goes back to its quarter; the others stay in Duplicados as «Repetida». */
    public function keep($key, $id, Intake $intake){
        $groups = self::groups();
        $g = $groups[$key] ?? null;
        if ( ! $g || ! in_array((int) $id, $g['ids'], true) ) return new \WP_Error('madof_dup', __('Ese grupo de duplicados ya no está.', 'mad-oficina'));
        foreach ( $g['ids'] as $x ) {
            $e = Expenses::get($x);
            if ( ! $e ) continue;
            if ( (int) $x === (int) $id ) {
                if ( $e['status'] === 'ignored' ) Expenses::update($x, ['status' => ($g['prev'][$x] ?? 'registered') === 'review' ? 'review' : 'registered', 'reason' => null]);
                $intake->queue_archive($x);
            } elseif ( $e['status'] !== 'ignored' ) {
                Expenses::update($x, ['status' => 'ignored', 'reason' => 'duplicate']);
                $g['prev'][$x] = $e['status'];
            }
        }
        $g['keep'] = (int) $id;
        $g['state'] = 'done';
        $groups[$key] = $g;
        self::store($groups);
        return true;
    }

    /** «No son duplicadas»: every one counts again, back to its place, and this group is not asked again. */
    public function dismiss($key, Intake $intake){
        $groups = self::groups();
        $g = $groups[$key] ?? null;
        if ( ! $g ) return new \WP_Error('madof_dup', __('Ese grupo de duplicados ya no está.', 'mad-oficina'));
        foreach ( $g['ids'] as $x ) {
            $e = Expenses::get($x);
            if ( ! $e ) continue;
            if ( $e['status'] === 'ignored' && $e['reason'] === 'duplicate' ) Expenses::update($x, ['status' => ($g['prev'][$x] ?? 'registered') === 'review' ? 'review' : 'registered', 'reason' => null]);
            $intake->queue_archive($x);
        }
        $g['state'] = 'dismissed';
        $groups[$key] = $g;
        self::store($groups);
        return true;
    }

    /** The cartero moves (or first archives) the file into that folder of the archive. */
    private function move(array $e, array $folder){
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . \MAD_Oficina\Core\Schema::table('tasks') . " WHERE agent = %s AND status = 'queued' AND type IN ('expense_archive','expense_move') AND payload LIKE %s",
            Orders::AGENT, $wpdb->esc_like('{"expense_id":' . (int) $e['id'] . ',') . '%'));
        $payload = ['expense_id' => (int) $e['id'], 'folder' => $folder, 'name' => Expenses::filename(['status' => 'registered'] + $e), 'root' => $this->root];
        if ( $e['drive_file_id'] ) return Orders::queue('expense_move', $payload + ['file_id' => $e['drive_file_id']]);
        if ( Uploads::exists((int) $e['id']) ) return Orders::queue('expense_archive', $payload);
        return 0;
    }
}
