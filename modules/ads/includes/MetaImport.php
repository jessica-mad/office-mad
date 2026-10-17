<?php
namespace MAD_Oficina\Ads;

if ( ! defined('ABSPATH') ) exit;

/**
 * Meta without the API: the reports exported from Ads Manager (CSV or Excel, in Spanish
 * or English), one row per campaign and day. They fill the same tables as the API, so
 * the client's campaigns, metrics and the log work the same; a budget or status that
 * differs from the previous import is logged as a change (seen on import).
 */
final class MetaImport {

    /** Column → the names Ads Manager gives it (es / en), normalised. */
    const COLUMNS = [
        'campaign'    => ['nombre de la campana', 'campaign name'],
        'campaign_id' => ['identificador de la campana', 'id de la campana', 'campaign id'],
        'account'     => ['nombre de la cuenta', 'account name'],
        'account_id'  => ['identificador de la cuenta', 'id de la cuenta', 'account id'],
        'day'         => ['dia', 'day', 'fecha'],
        'start'       => ['inicio del informe', 'reporting starts'],
        'end'         => ['fin del informe', 'reporting ends'],
        'spend'       => ['importe gastado', 'amount spent'],
        'impressions' => ['impresiones', 'impressions'],
        'link_clicks' => ['clics en el enlace', 'link clicks'],
        'clicks'      => ['clics todos', 'clicks all', 'clics', 'clicks'],
        'results'     => ['resultados', 'results'],
        'objective'   => ['indicador de resultado', 'result indicator', 'objetivo', 'objective'],
        'status'      => ['entrega de la campana', 'estado de entrega de la campana', 'campaign delivery', 'entrega', 'delivery'],
        'budget'      => ['presupuesto de la campana', 'campaign budget', 'presupuesto', 'budget'],
        'value'       => ['valor de conversion de compras', 'purchases conversion value', 'valor de conversion', 'conversion value'],
    ];

    private static function norm($s){
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(remove_accents((string) $s)))));
    }

    /** Header row → [field => column index]. Currency in brackets («Importe gastado (EUR)») is ignored. */
    public static function columns(array $header){
        $map = [];
        $names = array_map(fn($h) => self::norm(preg_replace('/\(.*?\)/', '', (string) $h)), $header);
        foreach ( self::COLUMNS as $field => $aliases ) {
            foreach ( $aliases as $alias ) {
                foreach ( $names as $i => $n ) {
                    if ( $n === $alias && ! in_array($i, $map, true) ) { $map[$field] = $i; continue 3; }
                }
            }
            // Looser: the header starts with the alias («Presupuesto del conjunto de anuncios» is not the campaign's, so only exact for budget).
            if ( $field === 'budget' ) continue;
            foreach ( $aliases as $alias ) {
                foreach ( $names as $i => $n ) {
                    if ( str_starts_with($n, $alias) && ! in_array($i, $map, true) ) { $map[$field] = $i; continue 3; }
                }
            }
        }
        return $map;
    }

    /**
     * @param int $client_id where the rows go when the file doesn't say the account
     * @return array{campaigns:int, days:int, changes:int, from:string, to:string, accounts:string[]}|\WP_Error
     */
    public static function import($path, $client_id, $today = null){
        if ( ! class_exists('MAD_Oficina\\Billing\\BankStatement') ) return new \WP_Error('madof_meta_import', __('Activa el módulo Finanzas: su lector de Excel es el que abre los informes.', 'mad-oficina'));
        $grid = \MAD_Oficina\Billing\BankStatement::grid($path);
        if ( is_wp_error($grid) ) return $grid;
        // The header is the first row with a campaign name and a spend column (some exports start with a title).
        $map = []; $start = null;
        foreach ( array_slice($grid, 0, 15, true) as $i => $row ) {
            $m = self::columns($row);
            if ( isset($m['campaign'], $m['spend']) ) { $map = $m; $start = $i + 1; break; }
        }
        if ( ! $map ) return new \WP_Error('madof_meta_import', __('No encuentro las columnas «Nombre de la campaña» e «Importe gastado». Exporta el informe a nivel de campaña desde el Administrador de anuncios.', 'mad-oficina'));
        if ( ! isset($map['day']) && ! isset($map['start']) ) return new \WP_Error('madof_meta_import', __('Al informe le falta el día. En el Administrador de anuncios: Desglose → Por tiempo → Día, y vuelve a exportarlo.', 'mad-oficina'));

        $cell = fn($row, $f) => isset($map[$f]) ? ($row[$map[$f]] ?? '') : '';
        $num = fn($v) => is_float($v) || is_int($v) ? (float) $v : (float) (\MAD_Oficina\Billing\BankStatement::amount($v) ?? 0);
        $accounts = []; $by_account = [];
        foreach ( array_slice($grid, $start) as $row ) {
            $name = trim((string) $cell($row, 'campaign'));
            if ( $name === '' || preg_match('/^(total|resultados de|results from)/i', $name) ) continue; // summary rows
            $day = \MAD_Oficina\Billing\BankStatement::date($cell($row, 'day'));
            if ( ! $day ) {
                $a = \MAD_Oficina\Billing\BankStatement::date($cell($row, 'start')); $b = \MAD_Oficina\Billing\BankStatement::date($cell($row, 'end'));
                if ( ! $a || $a !== $b ) return new \WP_Error('madof_meta_import', __('El informe suma varios días en cada fila. En el Administrador de anuncios: Desglose → Por tiempo → Día, y vuelve a exportarlo.', 'mad-oficina'));
                $day = $a;
            }
            $acc_ext = preg_replace('/\D/', '', (string) $cell($row, 'account_id'));
            $key = $acc_ext !== '' ? $acc_ext : 'import';
            if ( ! isset($accounts[$key]) ) $accounts[$key] = trim((string) $cell($row, 'account'));
            $cid = preg_replace('/\D/', '', (string) $cell($row, 'campaign_id'));
            $ext = $cid !== '' ? $cid : 'name:' . substr(sha1(self::norm($name)), 0, 16);
            $budget = trim((string) $cell($row, 'budget'));
            $by_account[$key]['campaigns'][$ext] = [
                'external_id' => $ext, 'name' => $name,
                'status' => self::status((string) $cell($row, 'status')), 'objective' => self::norm($cell($row, 'objective')),
                'budget' => $budget !== '' && preg_match('/\d/', $budget) ? round($num($budget), 2) : null, 'budget_period' => 'day',
            ] + ($by_account[$key]['campaigns'][$ext] ?? []);
            $clicks = $cell($row, 'link_clicks') !== '' ? $cell($row, 'link_clicks') : $cell($row, 'clicks');
            $by_account[$key]['metrics'][] = ['campaign' => $ext, 'date' => $day, 'impressions' => (int) $num($cell($row, 'impressions')), 'clicks' => (int) $num($clicks),
                'spend' => $num($cell($row, 'spend')), 'conversions' => $num($cell($row, 'results')), 'conversion_value' => $num($cell($row, 'value'))];
        }
        if ( ! $by_account ) return new \WP_Error('madof_meta_import', __('El informe no tiene filas de campañas.', 'mad-oficina'));

        $out = ['campaigns' => 0, 'days' => 0, 'changes' => 0, 'from' => '', 'to' => '', 'accounts' => []];
        foreach ( $by_account as $key => $data ) {
            $acc_id = self::account($key, $accounts[$key], (int) $client_id);
            if ( is_wp_error($acc_id) ) return $acc_id;
            $acc = Accounts::get($acc_id);
            // Budget/status are only compared when the file has them (otherwise null would read as «removed»).
            $rows = array_map(fn($c) => array_filter($c, fn($v, $k) => ! in_array($k, ['budget', 'status', 'objective'], true) || ( $v !== null && $v !== '' ), ARRAY_FILTER_USE_BOTH), array_values($data['campaigns']));
            $diffs = Accounts::save_campaigns($acc_id, $rows);
            $out['days'] += Accounts::save_metrics($acc_id, $data['metrics']);
            $out['campaigns'] += count($rows);
            $dates = array_column($data['metrics'], 'date'); sort($dates);
            $out['from'] = $out['from'] === '' ? $dates[0] : min($out['from'], $dates[0]);
            $out['to'] = max($out['to'], end($dates));
            $out['changes'] += Changes::record($acc, array_map(fn($d) => [
                'external_ref' => 'import:' . $d['campaign_id'] . ':' . $d['field'] . ':' . ($today ?: current_time('Y-m-d')), 'campaign_id' => $d['campaign_id'],
                'changed_at' => end($dates) . ' 00:00:00', 'actor' => '', 'source' => 'import', 'automatic' => false, 'object_type' => 'campaign', 'object_name' => $d['name'],
                'field' => $d['field'], 'old_value' => $d['old'] === null ? null : (string) $d['old'], 'new_value' => $d['new'] === null ? null : (string) $d['new'],
            ], $diffs));
            global $wpdb;
            $wpdb->update(\MAD_Oficina\Core\Schema::table('ad_accounts'), ['last_sync' => \MAD_Oficina\Core\Time::now(), 'last_error' => null], ['id' => $acc_id]);
            $out['accounts'][] = $acc['name'];
        }
        Changes::fill_after($today);
        return $out;
    }

    /** The account the file names (linked to the client if it wasn't), or the client's «imported» one. */
    private static function account($ext, $name, $client_id){
        if ( $ext === 'import' ) {
            if ( ! $client_id ) return new \WP_Error('madof_meta_import', __('El informe no trae el identificador de la cuenta: elige de qué cliente es.', 'mad-oficina'));
            $c = \MAD_Oficina\Core\Repo\Clients::get($client_id);
            $id = Accounts::upsert('meta', ['external_id' => 'import-' . $client_id, 'name' => ($name ?: ($c['name'] ?? '')) . ' · ' . __('importada', 'mad-oficina'), 'currency' => 'EUR']);
            Accounts::link($id, $client_id);
            return $id;
        }
        $id = Accounts::upsert('meta', ['external_id' => $ext, 'name' => $name ?: $ext, 'currency' => 'EUR']);
        $acc = Accounts::get($id);
        if ( ! $acc['client_id'] ) {
            $guess = $client_id ?: Accounts::suggest($name);
            if ( ! $guess ) return new \WP_Error('madof_meta_import', sprintf(__('No sé de qué cliente es la cuenta «%s»: elígelo al subir el informe.', 'mad-oficina'), $name ?: $ext));
            Accounts::link($id, $guess);
        }
        return $id;
    }

    private static function status($s){
        $n = self::norm($s);
        if ( $n === '' ) return '';
        if ( preg_match('/^(activ|active|en circulacion)/', $n) ) return 'active';
        if ( preg_match('/(desactivad|inactive|paused|pausad|off)/', $n) ) return 'paused';
        if ( preg_match('/(finaliz|completed|ended)/', $n) ) return 'completed';
        if ( preg_match('/(archiv)/', $n) ) return 'archived';
        if ( preg_match('/(elimin|deleted)/', $n) ) return 'deleted';
        return mb_substr($n, 0, 30);
    }
}
