<?php
namespace MAD_Oficina\Chat\Agents;

use MAD_Oficina\Core\FinanceAccess;
use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * Finanzas (the Contable): invoices, quotes, collections, expenses and totals. Read-only:
 * it finds and reports; sending anything to anyone goes through the inbox.
 * Only for whoever sees Finanzas (FinanceAccess), never for the team.
 */
final class Finance extends Specialist {

    public function slug(){ return 'finanzas'; }
    public function character(){ return 'contable'; }
    public function area(){ return 'Finanzas'; }
    public function scope(){ return 'facturas emitidas y borradores (con su PDF), presupuestos, cobros y vencimientos, gastos y facturas de proveedores, totales por periodo'; }
    public function routing(){
        return 'Todo lo que es DINERO: facturas a clientes (la última, una concreta, su PDF, si está cobrada o vencida), presupuestos, cuánto se ha facturado o gastado en un periodo, gastos y facturas de proveedores. '
            . 'Ejemplos: «envíame la última factura de LCS», «¿qué facturas están sin cobrar?», «¿cuánto le facturamos a MOM este año?», «¿cuánto gastamos en software en octubre?», «¿le mandamos presupuesto a X?».';
    }

    public static function allowed(array $ctx){ return ! empty($ctx['is_manager']) && FinanceAccess::can((int) ($ctx['user_id'] ?? 0)); }

    protected function tool_label($tool){
        return ['find_invoices' => 'está buscando en las facturas', 'invoice_detail' => 'está abriendo la factura', 'find_quotes' => 'está mirando los presupuestos',
            'find_expenses' => 'está revisando los gastos', 'totals' => 'está sumando'][$tool] ?? '';
    }

    protected function instructions(){
        return "- Eres de solo lectura: no emites, no envías, no cambias nada. Si te piden enviar algo a un cliente, di que se prepara desde Facturación y se aprueba en la bandeja.\n"
            . "- Los totales son datos de la oficina, no una liquidación de impuestos: no calcules liquidaciones como definitivas.\n"
            . "- Para «la última factura» ordena por fecha de emisión; da número, fecha, total, si está cobrada y el enlace al PDF.\n";
    }

    protected function tools(){
        return [
            ['name' => 'find_invoices', 'description' => 'Facturas a clientes: número, fecha, cliente, base, IVA, total, cobrada, vencimiento, estado y enlace al PDF. Las más recientes primero.',
             'input_schema' => self::obj([
                'client' => ['type' => 'string', 'description' => 'Código o nombre del cliente'],
                'status' => ['type' => 'string', 'enum' => ['issued', 'draft', 'all'], 'description' => 'issued (emitidas) por defecto'],
                'paid' => ['type' => 'boolean'], 'from' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'to' => ['type' => 'string'],
                'number' => ['type' => 'string'], 'limit' => ['type' => 'integer'],
             ]), 'handler' => [$this, 'find_invoices']],
            ['name' => 'invoice_detail', 'description' => 'Una factura con sus líneas, fechas de envío y cobro y el enlace al PDF.',
             'input_schema' => self::obj(['id' => ['type' => 'integer']], ['id']), 'handler' => [$this, 'invoice_detail']],
            ['name' => 'find_quotes', 'description' => 'Presupuestos: número, título, cliente o prospecto, estado, importe, fechas.',
             'input_schema' => self::obj(['client' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'accepted', 'rejected', 'expired', 'all']], 'limit' => ['type' => 'integer']]),
             'handler' => [$this, 'find_quotes']],
            ['name' => 'find_expenses', 'description' => 'Gastos y facturas de proveedores: fecha, proveedor, concepto, categoría, base, IVA, total, cliente repercutible, enlace en Drive.',
             'input_schema' => self::obj(['supplier' => ['type' => 'string'], 'category' => ['type' => 'string'], 'client' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'limit' => ['type' => 'integer']]),
             'handler' => [$this, 'find_expenses']],
            ['name' => 'totals', 'description' => 'Sumas de un periodo: facturado (base, IVA, IRPF, total; cobrado y pendiente), por cliente si se pide, y gastos por categoría.',
             'input_schema' => self::obj(['from' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'to' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'client' => ['type' => 'string']], ['from', 'to']),
             'handler' => [$this, 'totals']],
        ];
    }

    private static function link(array $inv){
        return $inv['pdf_url'] ?: admin_url('admin.php?page=mad-oficina-facturacion&invoice=' . (int) $inv['id']);
    }

    private function client_filter($q, $col, array &$where){
        if ( empty($q) ) return true;
        $ids = self::clients_matching($q);
        if ( ! $ids ) return false;
        $where[] = "{$col} IN (" . implode(',', $ids) . ')';
        return true;
    }

    public function find_invoices(array $in){
        global $wpdb;
        $where = ["i.status <> 'cancelled'"]; $args = [];
        $st = $in['status'] ?? 'issued';
        if ( $st === 'issued' ) $where[] = 'i.number IS NOT NULL';
        elseif ( $st === 'draft' ) $where[] = "i.status = 'draft'";
        if ( ! $this->client_filter($in['client'] ?? '', 'i.client_id', $where) ) {
            $like = '%' . $wpdb->esc_like((string) $in['client']) . '%';
            $where[] = 'i.client_name LIKE %s'; $args[] = $like; // legal name on the invoice
        }
        if ( isset($in['paid']) ) $where[] = 'i.paid = ' . ($in['paid'] ? 1 : 0);
        if ( $d = self::date_arg($in['from'] ?? '') ) { $where[] = 'i.issue_date >= %s'; $args[] = $d; }
        if ( $d = self::date_arg($in['to'] ?? '') ) { $where[] = 'i.issue_date <= %s'; $args[] = $d; }
        if ( ! empty($in['number']) ) { $where[] = 'i.number LIKE %s'; $args[] = '%' . $wpdb->esc_like((string) $in['number']) . '%'; }
        $sql = 'SELECT i.* FROM ' . Schema::table('invoices') . ' i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.issue_date DESC, i.seq DESC, i.id DESC LIMIT ' . self::limit($in['limit'] ?? 0);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        return ['invoices' => array_map(fn($r) => [
            'id' => (int) $r['id'], 'number' => $r['number'] ?: 'borrador', 'series' => $r['series'], 'date' => $r['issue_date'], 'client' => $r['client_name'],
            'base' => (float) $r['base'], 'vat' => (float) $r['vat'], 'irpf' => (float) $r['irpf'], 'total' => (float) $r['total'], 'currency' => $r['currency'],
            'paid' => (bool) $r['paid'], 'paid_at' => $r['paid_at'] ? substr($r['paid_at'], 0, 10) : null, 'due' => $r['due_date'],
            'sent' => (bool) $r['sent_at'], 'pdf' => self::link($r),
        ], $rows)];
    }

    public function invoice_detail(array $in){
        $inv = \MAD_Oficina\Billing\Invoices::get((int) ($in['id'] ?? 0));
        if ( ! $inv ) return new \WP_Error('madof_inv', 'No existe esa factura.');
        return [
            'id' => (int) $inv['id'], 'number' => $inv['number'] ?: 'borrador', 'date' => $inv['issue_date'], 'due' => $inv['due_date'], 'client' => $inv['client_name'],
            'tax_id' => $inv['client_tax_id'], 'email' => $inv['client_email'],
            'lines' => array_map(fn($l) => ['concept' => $l['description'], 'quantity' => (float) $l['quantity'], 'amount' => (float) $l['amount']], $inv['lines']),
            'base' => (float) $inv['base'], 'vat_rate' => (float) $inv['vat_rate'], 'vat' => (float) $inv['vat'], 'irpf' => (float) $inv['irpf'], 'total' => (float) $inv['total'], 'currency' => $inv['currency'],
            'sent_at' => $inv['sent_at'], 'paid' => (bool) $inv['paid'], 'paid_at' => $inv['paid_at'], 'pdf' => self::link($inv),
        ];
    }

    public function find_quotes(array $in){
        global $wpdb;
        $where = ['1=1'];
        $st = $in['status'] ?? 'all';
        if ( $st !== 'all' ) $where[] = $wpdb->prepare('q.status = %s', $st);
        if ( ! $this->client_filter($in['client'] ?? '', 'q.client_id', $where) ) $where[] = $wpdb->prepare('q.prospect_name LIKE %s', '%' . $wpdb->esc_like((string) $in['client']) . '%');
        $rows = $wpdb->get_results('SELECT q.*, (SELECT SUM(amount) FROM ' . Schema::table('quote_lines') . ' l WHERE l.quote_id = q.id) AS amount FROM ' . Schema::table('quotes') . ' q WHERE '
            . implode(' AND ', $where) . ' ORDER BY q.created_at DESC LIMIT ' . self::limit($in['limit'] ?? 0), ARRAY_A);
        return ['quotes' => array_map(fn($q) => [
            'id' => (int) $q['id'], 'number' => $q['number'], 'title' => $q['title'], 'for' => $q['prospect_name'], 'status' => $q['status'], 'amount' => (float) $q['amount'],
            'sent_on' => $q['sent_on'], 'valid_until' => $q['valid_until'], 'link' => admin_url('admin.php?page=mad-oficina-facturacion&tab=quotes&quote=' . (int) $q['id']),
        ], $rows)];
    }

    public function find_expenses(array $in){
        global $wpdb;
        $where = ["e.status = 'registered'"]; $args = [];
        if ( ! empty($in['supplier']) ) { $where[] = 'e.supplier_name LIKE %s'; $args[] = '%' . $wpdb->esc_like((string) $in['supplier']) . '%'; }
        if ( ! empty($in['category']) ) { $where[] = 'e.category = %s'; $args[] = sanitize_key($in['category']); }
        if ( ! empty($in['client']) && ! $this->client_filter($in['client'], 'e.client_id', $where) ) return ['expenses' => [], 'note' => 'No hay ningún cliente que coincida.'];
        if ( $d = self::date_arg($in['from'] ?? '') ) { $where[] = 'e.issue_date >= %s'; $args[] = $d; }
        if ( $d = self::date_arg($in['to'] ?? '') ) { $where[] = 'e.issue_date <= %s'; $args[] = $d; }
        $sql = 'SELECT e.* FROM ' . Schema::table('expenses') . ' e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.issue_date DESC, e.id DESC LIMIT ' . self::limit($in['limit'] ?? 0, 15, 50);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        return ['expenses' => array_map(fn($e) => [
            'id' => (int) $e['id'], 'date' => $e['issue_date'], 'supplier' => $e['supplier_name'], 'number' => $e['number'], 'concept' => $e['concept'], 'category' => $e['category'],
            'base' => (float) $e['base'], 'vat' => (float) $e['vat'], 'total' => (float) $e['total'], 'currency' => $e['currency'],
            'for_client' => self::client_label($e['client_id']), 'drive' => $e['drive_url'],
        ], $rows)];
    }

    public function totals(array $in){
        global $wpdb;
        $from = self::date_arg($in['from'] ?? ''); $to = self::date_arg($in['to'] ?? '');
        if ( ! $from || ! $to ) return new \WP_Error('madof_totals', 'Faltan las fechas (AAAA-MM-DD).');
        $where = ['number IS NOT NULL', "status <> 'cancelled'", $wpdb->prepare('issue_date BETWEEN %s AND %s', $from, $to)];
        if ( ! empty($in['client']) && ! $this->client_filter($in['client'], 'client_id', $where) ) return ['note' => 'No hay ningún cliente que coincida.'];
        $inv = $wpdb->get_results('SELECT series, currency, COUNT(*) n, SUM(base) base, SUM(vat) vat, SUM(irpf) irpf, SUM(total) total, SUM(CASE WHEN paid = 1 THEN total ELSE 0 END) paid FROM '
            . Schema::table('invoices') . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY series, currency', ARRAY_A);
        $exp = empty($in['client']) ? $wpdb->get_results($wpdb->prepare('SELECT category, currency, COUNT(*) n, SUM(base) base, SUM(vat) vat, SUM(total) total FROM ' . Schema::table('expenses')
            . " WHERE status = 'registered' AND issue_date BETWEEN %s AND %s GROUP BY category, currency ORDER BY total DESC", $from, $to), ARRAY_A) : [];
        $f = fn($rows) => array_map(fn($r) => array_map(fn($v) => is_numeric($v) ? round((float) $v, 2) : $v, $r), $rows);
        return ['period' => [$from, $to], 'invoiced' => $f($inv), 'expenses_by_category' => $f($exp), 'note' => 'Datos de la oficina. No es una liquidación de impuestos.'];
    }
}
