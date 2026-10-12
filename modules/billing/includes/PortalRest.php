<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\ClientContext;
use MAD_Oficina\Core\PortalAreas;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Roles;
use MAD_Oficina\Core\Schema;

if ( ! defined('ABSPATH') ) exit;

/**
 * «Mis facturas» in the client's office: issued invoices of the client resolved
 * on the server, only for people who have the «billing» area. Drafts, internal
 * notes and other clients never leave.
 */
final class PortalRest {

    const NS = 'mad-oficina/v1';

    private $issuer;

    /** @param callable $issuer returns the issuer settings (read live) */
    public function __construct(callable $issuer){
        $this->issuer = $issuer;
    }

    public function register(){
        $client_arg = ['client' => ['type' => 'integer', 'required' => false, 'minimum' => 1]];
        register_rest_route(self::NS, '/portal/invoices', [
            'methods' => 'GET', 'args' => $client_arg, 'permission_callback' => [$this, 'can'],
            'callback' => [$this, 'list'],
        ]);
        register_rest_route(self::NS, '/portal/invoices/(?P<id>\d+)/pdf', [
            'methods' => 'GET', 'args' => $client_arg, 'permission_callback' => [$this, 'can'],
            'callback' => [$this, 'pdf'],
        ]);
    }

    public function can(){
        if ( ! is_user_logged_in() ) return new \WP_Error('rest_not_logged_in', __('Inicia sesión para entrar en la oficina.', 'mad-oficina'), ['status' => 401]);
        if ( ! current_user_can(Roles::CAP_PORTAL) ) return new \WP_Error('rest_forbidden', __('Tu usuario no tiene acceso a la oficina.', 'mad-oficina'), ['status' => 403]);
        return PortalAreas::can('billing') ? true : PortalAreas::denied();
    }

    /** @return int|\WP_Error */
    private function client(\WP_REST_Request $req){
        $id = ClientContext::resolve($req->get_param('client'));
        if ( $id && Clients::get($id) ) return $id;
        return new \WP_Error('madof_no_client', __('Elige un cliente para ver su oficina.', 'mad-oficina'), ['status' => 400]);
    }

    /** Issued invoices of a client, newest first, as the client may see them. */
    public static function for_client($client_id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Invoices::table() . " WHERE client_id = %d AND status IN ('issued','sent') AND (number IS NOT NULL OR series = 'AR') ORDER BY issue_date DESC, id DESC LIMIT 200",
            $client_id
        ), ARRAY_A);
        $lines = [];
        if ( $rows ) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            foreach ( $wpdb->get_results('SELECT invoice_id, description FROM ' . Schema::table('invoice_lines') . " WHERE invoice_id IN ({$ids}) ORDER BY position", ARRAY_A) as $l ) {
                $lines[(int) $l['invoice_id']][] = $l['description'];
            }
        }
        return array_map(fn($r) => [
            'id'        => (int) $r['id'],
            'number'    => $r['number'],
            'argentina' => $r['series'] === 'AR',
            'rectifies' => $r['series'] === 'R',
            'date'      => $r['issue_date'],
            'legal_name'=> $r['client_name'],
            'concept'   => wp_trim_words(implode(' · ', $lines[(int) $r['id']] ?? []), 24),
            'base'      => (float) $r['base'],
            'total'     => (float) $r['total'],
            'currency'  => $r['currency'],
            'paid'      => (bool) $r['paid'],
            'pdf'       => $r['series'] !== 'AR', // the Argentine ones are issued on AFIP's platform
        ], $rows);
    }

    public function list(\WP_REST_Request $req){
        $client_id = $this->client($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $list = self::for_client($client_id);
        $pending = array_filter($list, fn($i) => ! $i['paid'] && ! $i['argentina']);
        return rest_ensure_response([
            'invoices' => $list,
            'pending'  => ['count' => count($pending), 'total' => round(array_sum(array_column($pending, 'total')), 2)],
        ]);
    }

    public function pdf(\WP_REST_Request $req){
        $client_id = $this->client($req);
        if ( is_wp_error($client_id) ) return $client_id;
        $inv = Invoices::get((int) $req['id']);
        // Same answer whether it does not exist or belongs to someone else.
        if ( ! $inv || (int) $inv['client_id'] !== (int) $client_id || ! in_array($inv['status'], ['issued', 'sent'], true) || ! $inv['number'] || $inv['series'] === 'AR' ) {
            return new \WP_Error('madof_not_found', __('No encuentro esa factura.', 'mad-oficina'), ['status' => 404]);
        }
        $pdf = InvoicePdf::render($inv, call_user_func($this->issuer));
        if ( is_wp_error($pdf) ) return $pdf;
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode(InvoicePdf::filename($inv)) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf; // binary PDF
        exit;
    }
}
