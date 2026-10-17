<?php
namespace MAD_Oficina\Ads;

if ( ! defined('ABSPATH') ) exit;

/**
 * Google Ads API (REST), read-only: only googleAds:search queries are ever sent.
 * Jessica's OAuth connection (Conexiones) + the MCC's developer token; every request
 * goes through the MCC (login-customer-id).
 *
 * The change history (change_event) only reaches 30 days back, so it is read every day
 * and kept in ad_changes.
 */
final class GoogleAds {

    const SCOPE = 'https://www.googleapis.com/auth/adwords';
    const DEFAULT_VERSION = 'v22';

    /** Who made it: these are Google's own, never asked about. */
    const AUTOMATIC = ['GOOGLE_ADS_RECOMMENDATIONS', 'GOOGLE_ADS_RECOMMENDATIONS_SUBSCRIPTION', 'GOOGLE_ADS_AUTOMATED_RULE', 'INTERNAL_TOOL', 'SEARCH_ADS_360_SYNC'];
    const SOURCES = [
        'GOOGLE_ADS_WEB_CLIENT' => 'web', 'GOOGLE_ADS_EDITOR' => 'editor', 'GOOGLE_ADS_MOBILE_APP' => 'app', 'GOOGLE_ADS_API' => 'api',
        'GOOGLE_ADS_SCRIPTS' => 'script', 'GOOGLE_ADS_BULK_UPLOAD' => 'bulk', 'GOOGLE_ADS_AUTOMATED_RULE' => 'rule',
        'GOOGLE_ADS_RECOMMENDATIONS' => 'recommendation', 'GOOGLE_ADS_RECOMMENDATIONS_SUBSCRIPTION' => 'recommendation',
    ];

    private $s;

    public function __construct(array $settings){ $this->s = $settings; }

    public static function digits($id){ return preg_replace('/\D/', '', (string) $id); }

    private function oauth(){
        $google = \MAD_Oficina_Core::instance()->get_module('google');
        return $google ? $google->oauth() : null;
    }

    public function token(){
        $enc = (string) ($this->s['google_dev_token'] ?? '');
        return $enc !== '' ? (string) \MAD_Oficina\Core\Crypto::decrypt($enc) : '';
    }

    public function configured(){ return $this->token() !== '' && self::digits($this->s['google_mcc'] ?? '') !== ''; }

    public function ready(){
        $o = $this->oauth();
        return $this->configured() && $o && $o->is_connected() && ! in_array(self::SCOPE, $o->missing_scopes(), true);
    }

    /** @return array[]|\WP_Error all rows of a GAQL query (pages followed). */
    public function search($customer_id, $query){
        $o = $this->oauth();
        if ( ! $o ) return new \WP_Error('madof_gads', __('Activa el módulo Conexiones y conecta Google.', 'mad-oficina'));
        if ( ! $this->configured() ) return new \WP_Error('madof_gads', __('Falta el developer token o el ID de tu cuenta de administrador (Campañas → Conexión).', 'mad-oficina'));
        $token = $o->access_token();
        if ( is_wp_error($token) ) return $token;
        $version = preg_replace('/[^a-z0-9]/', '', (string) ($this->s['google_version'] ?? '')) ?: self::DEFAULT_VERSION;
        $url = 'https://googleads.googleapis.com/' . $version . '/customers/' . self::digits($customer_id) . '/googleAds:search';
        $rows = []; $page = '';
        for ( $i = 0; $i < 20; $i++ ) {
            $body = ['query' => $query] + ($page !== '' ? ['pageToken' => $page] : []);
            $res = wp_remote_post($url, ['timeout' => 40, 'body' => wp_json_encode($body), 'headers' => [
                'Authorization' => 'Bearer ' . $token, 'developer-token' => $this->token(),
                'login-customer-id' => self::digits($this->s['google_mcc'] ?? ''), 'Content-Type' => 'application/json',
            ]]);
            if ( is_wp_error($res) ) return $res;
            $code = wp_remote_retrieve_response_code($res);
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if ( $code >= 300 ) {
                $err = $data[0]['error'] ?? $data['error'] ?? [];
                $detail = $err['details'][0]['errors'][0]['message'] ?? ($err['message'] ?? '');
                if ( $code === 404 ) $detail = sprintf(__('la versión %s de la API ya no existe: súbela en Campañas → Conexión', 'mad-oficina'), $version);
                elseif ( $known = self::explain($err) ) $detail = $known;
                return new \WP_Error('madof_gads_http', sprintf(__('Google Ads respondió %1$d: %2$s', 'mad-oficina'), $code, $detail), ['status' => $code]);
            }
            $rows = array_merge($rows, $data['results'] ?? []);
            $page = (string) ($data['nextPageToken'] ?? '');
            if ( $page === '' ) break;
        }
        return $rows;
    }

    /** Google's error codes, said in plain words (what she has to do). */
    public static function explain(array $err){
        $codes = [];
        foreach ( $err['details'] ?? [] as $d ) {
            foreach ( $d['errors'] ?? [] as $e ) foreach ( (array) ($e['errorCode'] ?? []) as $c ) $codes[] = (string) $c;
            if ( ! empty($d['reason']) ) $codes[] = (string) $d['reason'];
        }
        $codes[] = (string) ($err['status'] ?? '');
        $all = implode(' ', $codes) . ' ' . ($err['message'] ?? '');
        if ( str_contains($all, 'DEVELOPER_TOKEN_NOT_APPROVED') || stripos($all, 'only approved for use with test accounts') !== false )
            return __('tu developer token sigue en «acceso de prueba» y no puede leer cuentas reales. Hasta que Google apruebe el acceso básico no aparecerá nada.', 'mad-oficina');
        if ( str_contains($all, 'DEVELOPER_TOKEN_PROHIBITED') || str_contains($all, 'DEVELOPER_TOKEN_INVALID') || str_contains($all, 'INVALID_DEVELOPER_TOKEN') )
            return __('el developer token no es válido o no corresponde a este proyecto de Google Cloud. Cópialo de nuevo desde el Centro de API de tu MCC.', 'mad-oficina');
        if ( str_contains($all, 'SERVICE_DISABLED') || stripos($all, 'has not been used in project') !== false )
            return __('la «Google Ads API» no está habilitada en el proyecto de Google Cloud del plugin: console.cloud.google.com → APIs y servicios → Biblioteca → Google Ads API → Habilitar.', 'mad-oficina');
        if ( str_contains($all, 'USER_PERMISSION_DENIED') )
            return __('la cuenta de Google conectada en Conexiones no tiene acceso a esa MCC, o el ID de la MCC no es el correcto.', 'mad-oficina');
        if ( str_contains($all, 'CUSTOMER_NOT_ENABLED') ) return __('esa cuenta de Google Ads está cancelada o sin activar.', 'mad-oficina');
        if ( str_contains($all, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') || str_contains($all, 'insufficient authentication scopes') )
            return __('falta el permiso de Google Ads: reconecta Google en Conexiones.', 'mad-oficina');
        return '';
    }

    /** What is missing before it can read, in plain words ('' = ready). */
    public function missing(){
        if ( $this->token() === '' ) return __('Falta el developer token (pestaña Conexión).', 'mad-oficina');
        if ( self::digits($this->s['google_mcc'] ?? '') === '' ) return __('Falta el ID de tu cuenta de administrador (MCC) en la pestaña Conexión.', 'mad-oficina');
        $o = $this->oauth();
        if ( ! $o || ! $o->is_connected() ) return __('Google no está conectado: ve a MAD Oficina → Conexiones y conéctalo.', 'mad-oficina');
        if ( in_array(self::SCOPE, $o->missing_scopes(), true) ) return __('Falta el permiso de Google Ads: en MAD Oficina → Conexiones pulsa «Reconectar» y acepta el permiso nuevo.', 'mad-oficina');
        return '';
    }

    /** The client accounts under the MCC. @return array[]|\WP_Error [external_id, name, currency, status] */
    public function accounts(){
        $rows = $this->search($this->s['google_mcc'] ?? '', 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.status, customer_client.manager FROM customer_client WHERE customer_client.manager = false');
        if ( is_wp_error($rows) ) return $rows;
        return array_map(fn($r) => [
            'external_id' => (string) ($r['customerClient']['id'] ?? ''), 'name' => (string) ($r['customerClient']['descriptiveName'] ?? ''),
            'currency' => (string) ($r['customerClient']['currencyCode'] ?? 'EUR'), 'status' => strtolower((string) ($r['customerClient']['status'] ?? 'enabled')) === 'enabled' ? 'active' : 'inactive',
        ], $rows);
    }

    /** @return array[]|\WP_Error */
    public function campaigns($customer_id){
        $rows = $this->search($customer_id, "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign.campaign_budget, campaign_budget.amount_micros FROM campaign WHERE campaign.status != 'REMOVED'");
        if ( is_wp_error($rows) ) return $rows;
        return array_map(fn($r) => [
            'external_id' => (string) ($r['campaign']['id'] ?? ''), 'name' => (string) ($r['campaign']['name'] ?? ''),
            'status' => strtolower((string) ($r['campaign']['status'] ?? '')), 'objective' => strtolower((string) ($r['campaign']['advertisingChannelType'] ?? '')),
            'bidding' => strtolower((string) ($r['campaign']['biddingStrategyType'] ?? '')),
            'budget' => isset($r['campaignBudget']['amountMicros']) ? round((float) $r['campaignBudget']['amountMicros'] / 1e6, 2) : null, 'budget_period' => 'day',
            'budget_ref' => (string) ($r['campaign']['campaignBudget'] ?? ''),
        ], $rows);
    }

    /** @return array[]|\WP_Error [campaign external_id, date, impressions, clicks, spend, conversions, conversion_value] */
    public function metrics($customer_id, $from, $to){
        $rows = $this->search($customer_id, sprintf("SELECT campaign.id, segments.date, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '%s' AND '%s'", $from, $to));
        if ( is_wp_error($rows) ) return $rows;
        return array_map(fn($r) => [
            'campaign' => (string) ($r['campaign']['id'] ?? ''), 'date' => (string) ($r['segments']['date'] ?? ''),
            'impressions' => (int) ($r['metrics']['impressions'] ?? 0), 'clicks' => (int) ($r['metrics']['clicks'] ?? 0),
            'spend' => round((float) ($r['metrics']['costMicros'] ?? 0) / 1e6, 2), 'conversions' => (float) ($r['metrics']['conversions'] ?? 0),
            'conversion_value' => (float) ($r['metrics']['conversionsValue'] ?? 0),
        ], $rows);
    }

    /**
     * The change history between two dates (at most 30 days back), one row per changed field.
     * @return array[]|\WP_Error see Changes::record()
     */
    public function changes($customer_id, $from, $to){
        $rows = $this->search($customer_id, sprintf("SELECT change_event.resource_name, change_event.change_date_time, change_event.change_resource_type, change_event.change_resource_name, change_event.client_type, change_event.user_email, change_event.resource_change_operation, change_event.changed_fields, change_event.old_resource, change_event.new_resource, change_event.campaign FROM change_event WHERE change_event.change_date_time >= '%s 00:00:00' AND change_event.change_date_time <= '%s 23:59:59' ORDER BY change_event.change_date_time LIMIT 10000", $from, $to));
        if ( is_wp_error($rows) ) return $rows;
        $out = [];
        foreach ( $rows as $r ) $out = array_merge($out, self::explode_event($r['changeEvent'] ?? []));
        return $out;
    }

    /** One change event (several fields) → one row per field, with the values before and after. */
    public static function explode_event(array $ev){
        $type = strtolower((string) ($ev['changeResourceType'] ?? ''));
        $client = (string) ($ev['clientType'] ?? '');
        $old = is_array($ev['oldResource'] ?? null) ? (reset($ev['oldResource']) ?: []) : [];
        $new = is_array($ev['newResource'] ?? null) ? (reset($ev['newResource']) ?: []) : [];
        $op = strtolower((string) ($ev['resourceChangeOperation'] ?? 'update'));
        $fields = array_filter(array_map('trim', explode(',', (string) ($ev['changedFields'] ?? ''))));
        if ( $op !== 'update' || ! $fields ) $fields = [''];
        $campaign = (string) ($ev['campaign'] ?? '');
        if ( $campaign === '' && $type === 'campaign' ) $campaign = (string) ($ev['changeResourceName'] ?? '');
        $base = [
            'external_ref' => (string) ($ev['resourceName'] ?? ''),
            'changed_at' => self::when((string) ($ev['changeDateTime'] ?? '')),
            'actor' => (string) ($ev['userEmail'] ?? ''), 'source' => self::SOURCES[$client] ?? strtolower($client),
            'automatic' => in_array($client, self::AUTOMATIC, true),
            'object_type' => $type, 'object_name' => (string) ($new['name'] ?? $old['name'] ?? ''), 'object_ref' => (string) ($ev['changeResourceName'] ?? ''),
            'campaign_ext' => $campaign ? (string) substr(strrchr($campaign, '/'), 1) : '',
            'budget_ref' => $type === 'campaign_budget' ? (string) ($ev['changeResourceName'] ?? '') : '',
            'operation' => $op,
        ];
        $out = [];
        foreach ( $fields as $f ) {
            $path = array_filter(explode('.', $f));
            $out[] = $base + ['field' => $f !== '' ? $f : $op, 'old_value' => $f !== '' ? self::value(self::dig($old, $path), $f) : null, 'new_value' => $f !== '' ? self::value(self::dig($new, $path), $f) : null];
        }
        return $out;
    }

    private static function dig($a, array $path){
        foreach ( $path as $k ) {
            if ( ! is_array($a) ) return null;
            $a = $a[$k] ?? ($a[lcfirst(str_replace('_', '', ucwords($k, '_')))] ?? null); // the mask may come snake_case or camelCase
        }
        return $a;
    }

    private static function value($v, $field){
        if ( $v === null ) return null;
        if ( is_array($v) ) return mb_substr(wp_json_encode($v, JSON_UNESCAPED_UNICODE), 0, 480);
        if ( preg_match('/micros$/i', $field) && is_numeric($v) ) return (string) round((float) $v / 1e6, 2);
        return mb_substr((string) $v, 0, 480);
    }

    /** change_date_time comes in the account's time zone, "2026-10-14 09:31:02.123456". Stored as is (local). */
    private static function when($s){ return substr($s, 0, 19) ?: current_time('mysql'); }
}
