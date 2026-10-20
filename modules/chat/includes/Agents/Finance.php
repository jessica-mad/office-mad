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
    public function scope(){ return 'facturas emitidas y borradores (con su PDF), presupuestos, cobros y vencimientos, gastos y facturas de proveedores, movimientos del banco y si cuadran, totales por periodo; puede marcar facturas cobradas, cuadrar el banco, corregir gastos y registrar facturas de proveedores que esperan'; }
    public function routing(){
        return 'Todo lo que es DINERO: facturas a clientes (la última, una concreta, su PDF, si está cobrada o vencida), presupuestos, cuánto se ha facturado o gastado en un periodo, gastos y facturas de proveedores. '
            . 'Ejemplos: «envíame la última factura de LCS», «¿qué facturas están sin cobrar?», «¿cuánto le facturamos a MOM este año?», «¿cuánto gastamos en software en octubre?», «¿le mandamos presupuesto a X?», «marca como cobrada la de LCS», «esos cargos de Revolut son traspasos míos», «registra la factura de Canva que está esperando».';
    }

    public static function allowed(array $ctx){ return ! empty($ctx['is_manager']) && FinanceAccess::can((int) ($ctx['user_id'] ?? 0)); }

    protected function tool_label($tool){
        return ['find_invoices' => 'está buscando en las facturas', 'invoice_detail' => 'está abriendo la factura', 'find_quotes' => 'está mirando los presupuestos',
            'find_expenses' => 'está revisando los gastos', 'bank_movements' => 'está mirando el banco', 'totals' => 'está sumando', 'set_invoice_paid' => 'está apuntando el cobro', 'bank_link' => 'está cuadrando el banco', 'bank_settle' => 'está cuadrando el banco', 'bank_unlink' => 'está deshaciendo el vínculo', 'update_expense' => 'está corrigiendo el gasto', 'register_supplier_invoice' => 'está registrando la factura', 'register_attached_invoices' => 'está leyendo las facturas que has mandado'][$tool] ?? '';
    }

    protected function instructions(){
        return "- Puedes CAMBIAR datos de Finanzas solo si la pregunta lo pide claramente: marcar una factura cobrada o no, vincular o desvincular movimientos del banco, marcar movimientos como sin factura o traspaso (y recordarlo), cambiar la categoría o el cliente repercutible de un gasto, registrar una factura de proveedor que espera en la bandeja. Busca primero (para tener los ids) y di exactamente qué cambiaste. Si hay duda entre varias, no cambies nada y da las opciones.\n"
            . "- No emites ni envías facturas, no borras nada y no envías nada a nadie. Si te piden enviar algo a un cliente, di que se prepara desde Facturación y se aprueba en la bandeja.\n"
            . "- Los totales son datos de la oficina, no una liquidación de impuestos: no calcules liquidaciones como definitivas.\n"
            . ( empty($this->ctx['files']) ? '' : sprintf("- Este mensaje trae %d archivo(s) de Jessica. Si te pide registrarlos (facturas o tickets de proveedores), usa register_attached_invoices: se leen como los que sube a Gastos y, como los manda ella, quedan aprobados. Di de cada uno proveedor, fecha, total y si quedó registrado o qué falta.\n", count((array) $this->ctx['files'])) )
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
            ['name' => 'bank_movements', 'description' => 'Movimientos del banco (de los extractos subidos): fecha, concepto, importe (+ entra, - sale), si cuadran con una factura, gasto o impuesto, o si están sin cuadrar.',
             'input_schema' => self::obj(['query' => ['type' => 'string', 'description' => 'Palabras del concepto'], 'status' => ['type' => 'string', 'enum' => ['unmatched', 'matched', 'ignored', 'all']], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'limit' => ['type' => 'integer']]),
             'handler' => [$this, 'bank_movements']],
            ['name' => 'set_invoice_paid', 'description' => 'Marca una factura emitida como cobrada (con la fecha del cobro) o como no cobrada.',
             'input_schema' => self::obj(['invoice_id' => ['type' => 'integer'], 'paid' => ['type' => 'boolean'], 'date' => ['type' => 'string', 'description' => 'AAAA-MM-DD; hoy si no se dice']], ['invoice_id', 'paid']),
             'handler' => [$this, 'set_invoice_paid']],
            ['name' => 'bank_link', 'description' => 'Vincula un movimiento del banco con una o varias facturas a clientes (type invoice), facturas de proveedores (expense) o impuestos (tax). Un cobro completo marca la factura cobrada.',
             'input_schema' => self::obj(['movement_id' => ['type' => 'integer'], 'type' => ['type' => 'string', 'enum' => ['invoice', 'expense', 'tax']], 'ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], ['movement_id', 'type', 'ids']),
             'handler' => [$this, 'bank_link']],
            ['name' => 'bank_settle', 'description' => 'Marca movimientos del banco como que no llevan factura: own (traspaso entre sus cuentas), no_invoice (comisión, etc.) o personal. remember: texto del concepto para hacerlo solo en adelante.',
             'input_schema' => self::obj(['movement_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'kind' => ['type' => 'string', 'enum' => ['own', 'no_invoice', 'personal']], 'note' => ['type' => 'string'], 'remember' => ['type' => 'string']], ['movement_ids', 'kind']),
             'handler' => [$this, 'bank_settle']],
            ['name' => 'bank_unlink', 'description' => 'Deja un movimiento del banco otra vez sin cuadrar (deshace su vínculo).',
             'input_schema' => self::obj(['movement_id' => ['type' => 'integer']], ['movement_id']), 'handler' => [$this, 'bank_unlink']],
            ['name' => 'update_expense', 'description' => 'Cambia la categoría de un gasto o el cliente al que se repercute (client = código del cliente, o "none" para quitarlo).',
             'input_schema' => self::obj(['expense_id' => ['type' => 'integer'], 'category' => ['type' => 'string', 'enum' => array_keys(\MAD_Oficina\Suppliers\Suppliers::CATEGORIES)], 'client' => ['type' => 'string']], ['expense_id']),
             'handler' => [$this, 'update_expense']],
            ['name' => 'pending_supplier_invoices', 'description' => 'Facturas de proveedores que esperan en la bandeja (proveedor nuevo o «¿es tuya?»), con su id.',
             'input_schema' => self::obj([]), 'handler' => [$this, 'pending_supplier_invoices']],
            ['name' => 'register_supplier_invoice', 'description' => 'Registra como gasto una factura de proveedor que espera en la bandeja (la da por buena; si el proveedor es nuevo, lo da de alta con lo leído).',
             'input_schema' => self::obj(['expense_id' => ['type' => 'integer']], ['expense_id']), 'handler' => [$this, 'register_supplier_invoice']],
            ['name' => 'register_attached_invoices', 'description' => 'Registra como gastos las facturas o tickets de proveedores que Jessica ha mandado con este mensaje (PDF o foto): los lee como la subida manual de Gastos y los aprueba (si el proveedor es nuevo, lo da de alta con lo leído).',
             'input_schema' => self::obj([]), 'handler' => [$this, 'register_attached_invoices']],
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
        $note = null;
        if ( ! $rows && ! empty($in['client']) && ($ids = self::clients_matching($in['client'])) && count($ids) === 1 ) {
            // The office client exists but its fiscal name is not joined to it yet: look by that name and say so.
            $c = \MAD_Oficina\Core\Repo\Clients::get($ids[0]);
            $where = array_map(fn($w) => $w === 'i.client_id IN (' . $ids[0] . ')' ? 'i.client_name LIKE %s' : $w, $where);
            array_unshift($args, '%' . $wpdb->esc_like((string) $c['name']) . '%');
            $sql = 'SELECT i.* FROM ' . Schema::table('invoices') . ' i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.issue_date DESC, i.seq DESC, i.id DESC LIMIT ' . self::limit($in['limit'] ?? 0);
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            $note = sprintf('%1$s no tiene datos fiscales unidos en Facturación, así que sus facturas no cuelgan de ese cliente. %2$s Para arreglarlo: Facturación → Clientes → «Unir» el nombre fiscal con %1$s.', self::client_label($ids[0]),
                $rows ? sprintf('Las he encontrado por el nombre «%s».', $c['name']) : sprintf('Tampoco hay facturas a nombre de «%s».', $c['name']));
        }
        return array_filter(['note' => $note]) + ['invoices' => array_map(fn($r) => [
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

    public function bank_movements(array $in){
        global $wpdb;
        $where = ['1=1']; $args = [];
        $st = $in['status'] ?? 'all';
        if ( $st !== 'all' ) { $where[] = 'status = %s'; $args[] = $st; }
        if ( ! empty($in['query']) ) { $where[] = 'concept LIKE %s'; $args[] = '%' . $wpdb->esc_like((string) $in['query']) . '%'; }
        if ( $d = self::date_arg($in['from'] ?? '') ) { $where[] = 'date >= %s'; $args[] = $d; }
        if ( $d = self::date_arg($in['to'] ?? '') ) { $where[] = 'date <= %s'; $args[] = $d; }
        $sql = 'SELECT id, date, concept, amount, status, match_type, note FROM ' . Schema::table('bank_movements') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY date DESC LIMIT ' . self::limit($in['limit'] ?? 0, 20, 60);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        $covered = $wpdb->get_row('SELECT MIN(date) a, MAX(date) b FROM ' . Schema::table('bank_movements'), ARRAY_A);
        foreach ( $rows as &$r ) $r['id'] = (int) ($r['id'] ?? 0);
        return ['statements_cover' => [$covered['a'] ?? null, $covered['b'] ?? null], 'page' => admin_url('admin.php?page=mad-oficina-facturacion&tab=bank'),
            'movements' => array_map(fn($r) => ['id' => (int) $r['id'], 'date' => $r['date'], 'concept' => $r['concept'], 'amount' => (float) $r['amount'],
                'status' => ['matched' => 'cuadra', 'unmatched' => 'sin cuadrar', 'ignored' => 'sin factura (resuelto)'][$r['status']] ?? $r['status'], 'with' => $r['note']], $rows)];
    }

    /* ───────── changes (only when asked; all with «Deshacer» except registering) ───────── */

    private function keep(array $rows){
        $this->undo[] = \MAD_Oficina\Billing\FinanceUndo::snapshot($rows);
    }

    public function set_invoice_paid(array $in){
        global $wpdb;
        $inv = \MAD_Oficina\Billing\Invoices::get((int) ($in['invoice_id'] ?? 0));
        if ( ! $inv || ! in_array($inv['status'], ['issued', 'sent'], true) ) return new \WP_Error('madof_inv', 'No existe esa factura emitida.');
        $paid = ! empty($in['paid']);
        $date = self::date_arg($in['date'] ?? '') ?: current_time('Y-m-d');
        $this->keep(['invoices' => [$inv['id']]]);
        $wpdb->update(\MAD_Oficina\Billing\Invoices::table(), ['paid' => $paid ? 1 : 0, 'paid_at' => $paid ? $date . ' 12:00:00' : null, 'updated_at' => \MAD_Oficina\Core\Time::now()], ['id' => $inv['id']]);
        \MAD_Oficina\Billing\Issuing::queue_libro($inv['id']);
        return ['done' => sprintf('%s marcada como %s', $inv['number'] ?: '#' . $inv['id'], $paid ? 'cobrada el ' . $date : 'no cobrada')];
    }

    public function bank_link(array $in){
        global $wpdb;
        $mid = (int) ($in['movement_id'] ?? 0);
        $ids = array_map('intval', (array) ($in['ids'] ?? []));
        $this->keep(['bank_movements' => [$mid], 'invoices' => ($in['type'] ?? '') === 'invoice' ? $ids : []]);
        $r = \MAD_Oficina\Billing\Bank::link($mid, sanitize_key($in['type'] ?? ''), $ids);
        if ( is_wp_error($r) ) { array_pop($this->undo); return $r; }
        return ['done' => 'Vinculado', 'note' => $wpdb->get_var($wpdb->prepare('SELECT note FROM ' . Schema::table('bank_movements') . ' WHERE id = %d', $mid))];
    }

    public function bank_settle(array $in){
        $ids = array_map('intval', (array) ($in['movement_ids'] ?? []));
        $this->keep(['bank_movements' => $ids]);
        $n = \MAD_Oficina\Billing\Bank::ignore_many($ids, sanitize_key($in['kind'] ?? ''), (string) ($in['note'] ?? ''), (string) ($in['remember'] ?? ''));
        return ['done' => $n . ' movimientos resueltos' . (! empty($in['remember']) ? ' (y lo recordaré para los que contengan «' . sanitize_text_field($in['remember']) . '»; esa regla se quita en Finanzas → Banco)' : '')];
    }

    public function bank_unlink(array $in){
        $mid = (int) ($in['movement_id'] ?? 0);
        $m = \MAD_Oficina\Billing\Bank::get($mid);
        if ( ! $m ) return new \WP_Error('madof_bank', 'No existe ese movimiento.');
        $this->keep(['bank_movements' => [$mid], 'invoices' => $m['match_type'] === 'invoice' ? explode(',', (string) $m['match_ids']) : []]);
        \MAD_Oficina\Billing\Bank::unlink($mid);
        return ['done' => 'Movimiento otra vez sin cuadrar'];
    }

    public function update_expense(array $in){
        global $wpdb;
        $e = \MAD_Oficina\Suppliers\Expenses::get((int) ($in['expense_id'] ?? 0));
        if ( ! $e ) return new \WP_Error('madof_expense', 'No existe ese gasto.');
        $fields = [];
        if ( ! empty($in['category']) && isset(\MAD_Oficina\Suppliers\Suppliers::CATEGORIES[$in['category']]) ) $fields['category'] = $in['category'];
        if ( isset($in['client']) && $in['client'] !== '' ) {
            if ( $in['client'] === 'none' ) $fields['client_id'] = null;
            else { $c = self::clients_matching($in['client']); if ( count($c) !== 1 ) return new \WP_Error('madof_client', $c ? 'Varios clientes coinciden: ¿cuál?' : 'Ningún cliente coincide.'); $fields['client_id'] = $c[0]; }
        }
        if ( ! $fields ) return new \WP_Error('madof_expense', 'No hay nada que cambiar.');
        $this->keep(['expenses' => [$e['id']]]);
        $wpdb->update(Schema::table('expenses'), $fields + ['updated_at' => \MAD_Oficina\Core\Time::now()], ['id' => $e['id']]);
        return ['done' => 'Gasto actualizado', 'category' => $fields['category'] ?? $e['category'], 'for_client' => array_key_exists('client_id', $fields) ? self::client_label($fields['client_id']) : self::client_label($e['client_id'])];
    }

    /** The invoices Jessica sent in Chat: read like a manual upload in Gastos and approved, since she sent them. */
    public function register_attached_invoices(array $in){
        $files = (array) ($this->ctx['files'] ?? []);
        if ( ! $files ) return new \WP_Error('madof_files', 'Este mensaje no trae archivos.');
        $suppliers = \MAD_Oficina_Core::instance()->get_module('suppliers');
        if ( ! $suppliers ) return new \WP_Error('madof_files', 'El módulo de Gastos está apagado.');
        $intake = $suppliers->intake();
        $labels = ['registered' => 'registrada', 'review' => 'por revisar', 'queued' => 'en cola para leer', 'not_mine' => 'no está a tu nombre', 'ignored' => 'descartada (no parece una factura)', 'payment' => 'justificante de pago', 'tax' => 'impuesto'];
        $out = [];
        foreach ( $files as $f ) {
            if ( function_exists('set_time_limit') ) @set_time_limit(150);
            $r = $intake->receive((string) $f['bytes'], ['source' => 'upload', 'filename' => $f['name'], 'subject' => 'Enviada por Chat']);
            if ( is_wp_error($r) ) { $out[] = ['file' => $f['name'], 'error' => $r->get_error_message()]; continue; }
            if ( $r['reason'] === 'seen' ) { $out[] = ['file' => $f['name'], 'already' => 'Ya estaba en Gastos.'] + $this->expense_row($r['expense_id'], $labels); continue; }
            // She sent it herself: its pending question («¿proveedor nuevo?», «¿es tuya?») is approved as read.
            if ( \MAD_Oficina\Suppliers\Intake::pending_approval($r['expense_id']) ) {
                $prev = get_current_user_id();
                wp_set_current_user((int) ($this->ctx['user_id'] ?? 0)); // approved as Jessica, as in the inbox
                $a = $intake->bulk_approve([$r['expense_id']]);
                wp_set_current_user($prev);
                if ( $a['errors'] ) $out[] = ['file' => $f['name'], 'approve_error' => implode(' · ', $a['errors'])] + $this->expense_row($r['expense_id'], $labels);
                else $out[] = ['file' => $f['name']] + $this->expense_row($r['expense_id'], $labels);
                continue;
            }
            $out[] = ['file' => $f['name']] + $this->expense_row($r['expense_id'], $labels);
        }
        return ['invoices' => $out];
    }

    private function expense_row($id, array $labels){
        $e = \MAD_Oficina\Suppliers\Expenses::get((int) $id);
        if ( ! $e ) return ['status' => 'no consta'];
        return ['expense_id' => (int) $e['id'], 'status' => $labels[$e['status']] ?? $e['status'], 'supplier' => $e['supplier_name'], 'date' => $e['issue_date'], 'number' => $e['number'],
            'total' => $e['total'] !== null ? (float) $e['total'] : null, 'currency' => $e['currency'], 'category' => $e['category'], 'notes' => $e['notes'] ?: null,
            'link' => admin_url('admin.php?page=mad-oficina-gastos&expense=' . (int) $e['id'])];
    }

    private static function supplier_approvals(){
        return array_values(array_filter(\MAD_Oficina\Core\Approvals::pending(200), fn($a) => in_array($a['action_type'], ['supplier_new', 'expense_review'], true)));
    }

    public function pending_supplier_invoices(array $in){
        $out = [];
        foreach ( self::supplier_approvals() as $a ) {
            $e = \MAD_Oficina\Suppliers\Expenses::get((int) ($a['payload']['expense_id'] ?? 0));
            if ( $e ) $out[] = ['expense_id' => (int) $e['id'], 'why' => $a['action_type'] === 'supplier_new' ? 'proveedor nuevo' : 'no dice a nombre de quién está', 'supplier' => $e['supplier_name'], 'date' => $e['issue_date'], 'number' => $e['number'], 'total' => (float) $e['total'], 'currency' => $e['currency']];
        }
        return ['pending' => $out, 'inbox' => admin_url('admin.php?page=mad-oficina-bandeja')];
    }

    public function register_supplier_invoice(array $in){
        $id = (int) ($in['expense_id'] ?? 0);
        foreach ( self::supplier_approvals() as $a ) {
            if ( (int) ($a['payload']['expense_id'] ?? 0) !== $id ) continue;
            $input = apply_filters('madoficina_approval_default_input_' . $a['action_type'], [], $a);
            $r = \MAD_Oficina\Core\Approvals::resolve($a['id'], 'approve', $input, (int) ($this->ctx['user_id'] ?? 0), 'chat');
            return is_wp_error($r) ? $r : ['done' => $r['message'] ?? 'Registrada', 'note' => 'Esto no tiene «Deshacer»: si era un error, corrígelo en Gastos.'];
        }
        return new \WP_Error('madof_expense', 'Esa factura no está esperando en la bandeja.');
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
