<?php
namespace MAD_Oficina\Core\Repo;

use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Clients, their WP users, contacts and contact emails.
 */
final class Clients {

    const STATUSES = ['active', 'paused', 'archived'];
    const EMAIL_KINDS = ['work', 'personal'];

    /** Tables holding per-client content; used when erasing a client (GDPR). */
    const CONTENT_TABLES = ['meetings', 'action_items', 'pending_topics', 'ideas', 'processes', 'tasks', 'approvals', 'agent_log', 'messages', 'leads'];

    /* ===== Clients ===== */

    public static function normalize_code($code){
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    public static function all($args = []){
        global $wpdb;
        $where = '1=1';
        if ( ! empty($args['status']) ) $where .= $wpdb->prepare(' AND status = %s', $args['status']);
        if ( isset($args['is_demo']) ) $where .= $wpdb->prepare(' AND is_demo = %d', $args['is_demo'] ? 1 : 0);
        return array_map([self::class, 'hydrate'], $wpdb->get_results('SELECT * FROM ' . Schema::table('clients') . " WHERE {$where} ORDER BY name ASC", ARRAY_A));
    }

    public static function get($id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('clients') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    public static function find_by_code($code){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('clients') . ' WHERE code = %s', self::normalize_code($code)), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /** @return array|null client whose domain list contains the email's domain */
    public static function find_by_domain($email_or_domain){
        $domain = strtolower(trim(strpos($email_or_domain, '@') !== false ? substr(strrchr($email_or_domain, '@'), 1) : $email_or_domain));
        if ( ! $domain ) return null;
        foreach ( self::all() as $client ){
            if ( in_array($domain, $client['domains'], true) ) return $client;
        }
        return null;
    }

    /**
     * @param array $data name, code, status, domains (array|string), drive_folder_id, notes, is_demo
     * @return int|\WP_Error client id
     */
    public static function save($data, $id = 0){
        global $wpdb;

        $name = sanitize_text_field($data['name'] ?? '');
        $code = self::normalize_code($data['code'] ?? '');
        if ( $name === '' ) return new \WP_Error('madof_client_name', __('El nombre del cliente es obligatorio.', 'mad-oficina'));
        if ( strlen($code) < 2 || strlen($code) > 12 ) return new \WP_Error('madof_client_code', __('El código debe tener entre 2 y 12 letras o números (p. ej. MOM).', 'mad-oficina'));

        $existing = self::find_by_code($code);
        if ( $existing && (int) $existing['id'] !== (int) $id ) {
            return new \WP_Error('madof_client_code_taken', sprintf(__('El código %1$s ya lo usa %2$s.', 'mad-oficina'), $code, $existing['name']));
        }

        $row = [
            'name'            => $name,
            'code'            => $code,
            'status'          => in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'active',
            'domains'         => wp_json_encode(self::parse_domains($data['domains'] ?? [])),
            'drive_folder_id' => self::parse_drive_id($data['drive_folder_id'] ?? ''),
            'notes'           => sanitize_textarea_field($data['notes'] ?? ''),
            'updated_at'      => Time::now(),
        ];

        if ( $id ) {
            $wpdb->update(Schema::table('clients'), $row, ['id' => $id]);
            return (int) $id;
        }

        $row['is_demo']    = empty($data['is_demo']) ? 0 : 1;
        $row['created_at'] = Time::now();
        $wpdb->insert(Schema::table('clients'), $row);
        return (int) $wpdb->insert_id;
    }

    /**
     * Erases a client and everything attached to it (GDPR "right to erasure").
     * WordPress users are unlinked, not deleted.
     */
    public static function delete($id){
        global $wpdb;
        $id = (int) $id;
        // Invoices must be kept by law: they are unlinked, never erased with the client.
        foreach ( ['invoices', 'billing_profiles', 'billing_items', 'quotes', 'suppliers', 'expenses'] as $table ) {
            $wpdb->update(Schema::table($table), ['client_id' => null], ['client_id' => $id]);
        }
        foreach ( array_merge(self::CONTENT_TABLES, ['contact_emails', 'client_contacts', 'client_users']) as $table ){
            $wpdb->delete(Schema::table($table), ['client_id' => $id]);
        }
        $wpdb->delete(Schema::table('clients'), ['id' => $id]);
    }

    public static function parse_domains($domains){
        if ( is_string($domains) ) $domains = preg_split('/[\s,;]+/', $domains);
        $out = [];
        foreach ( (array) $domains as $d ){
            $d = strtolower(trim((string) $d));
            $d = preg_replace('#^(https?://|.*@)#', '', $d);
            $d = ltrim(preg_replace('#[/@].*$#', '', $d), '.');
            if ( $d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) ) $out[] = $d;
        }
        return array_values(array_unique($out));
    }

    /** Accepts a folder URL or a raw ID. */
    public static function parse_drive_id($value){
        $value = trim((string) $value);
        if ( preg_match('#/folders/([A-Za-z0-9_-]+)#', $value, $m) ) return $m[1];
        if ( preg_match('#[?&]id=([A-Za-z0-9_-]+)#', $value, $m) ) return $m[1];
        return preg_match('/^[A-Za-z0-9_-]{10,}$/', $value) ? $value : '';
    }

    private static function hydrate($row){
        foreach ( ['domains', 'chat_targets', 'ads_accounts', 'billing'] as $json ){
            $row[$json] = $row[$json] ? (json_decode($row[$json], true) ?: []) : [];
        }
        $row['id'] = (int) $row['id'];
        $row['is_demo'] = (int) $row['is_demo'];
        return $row;
    }

    /* ===== WP users ===== */

    /** @return true|\WP_Error */
    public static function link_user($client_id, $user_id){
        global $wpdb;
        $current = \MAD_Oficina\Core\ClientContext::client_for_user($user_id);
        if ( $current && $current !== (int) $client_id ) {
            return new \WP_Error('madof_user_linked', __('Este usuario ya pertenece a otro cliente.', 'mad-oficina'));
        }
        if ( ! $current ) {
            $wpdb->insert(Schema::table('client_users'), ['client_id' => $client_id, 'wp_user_id' => $user_id, 'created_at' => Time::now()]);
        }
        return true;
    }

    public static function unlink_user($client_id, $user_id){
        global $wpdb;
        $wpdb->delete(Schema::table('client_users'), ['client_id' => $client_id, 'wp_user_id' => $user_id]);
    }

    /** @return \WP_User[] */
    public static function users($client_id){
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT wp_user_id FROM ' . Schema::table('client_users') . ' WHERE client_id = %d', $client_id));
        return $ids ? get_users(['include' => array_map('intval', $ids)]) : [];
    }

    /* ===== Contacts & emails ===== */

    public static function contacts($client_id){
        global $wpdb;
        $contacts = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('client_contacts') . ' WHERE client_id = %d ORDER BY name ASC', $client_id), ARRAY_A);
        foreach ( $contacts as &$c ){
            $c['id'] = (int) $c['id'];
            $c['aliases'] = $c['aliases'] ? (json_decode($c['aliases'], true) ?: []) : [];
            $c['emails'] = $wpdb->get_results($wpdb->prepare('SELECT email, kind FROM ' . Schema::table('contact_emails') . ' WHERE contact_id = %d ORDER BY kind, email', $c['id']), ARRAY_A);
        }
        return $contacts;
    }

    /**
     * @param array $data   name, role, aliases (array|string), receives_summaries
     * @param array $emails [['email'=>..., 'kind'=>'work'|'personal'], ...]
     * @return int|\WP_Error contact id
     */
    public static function save_contact($client_id, $data, $emails, $contact_id = 0){
        global $wpdb;
        $name = sanitize_text_field($data['name'] ?? '');
        if ( $name === '' ) return new \WP_Error('madof_contact_name', __('El contacto necesita un nombre.', 'mad-oficina'));

        $clean_emails = [];
        foreach ( $emails as $e ){
            $email = strtolower(sanitize_email($e['email'] ?? ''));
            if ( ! $email ) continue;
            $owner = self::find_by_email($email);
            if ( $owner && (int) $owner['contact_id'] !== (int) $contact_id ) {
                return new \WP_Error('madof_email_taken', sprintf(__('El email %s ya está asignado a otro contacto.', 'mad-oficina'), $email));
            }
            $clean_emails[$email] = in_array($e['kind'] ?? '', self::EMAIL_KINDS, true) ? $e['kind'] : 'work';
        }

        $aliases = $data['aliases'] ?? [];
        if ( is_string($aliases) ) $aliases = preg_split('/\s*,\s*/', $aliases);
        $aliases = array_values(array_filter(array_map('sanitize_text_field', (array) $aliases)));

        $row = [
            'client_id'          => (int) $client_id,
            'name'               => $name,
            'role'               => sanitize_text_field($data['role'] ?? ''),
            'aliases'            => wp_json_encode($aliases),
            'receives_summaries' => empty($data['receives_summaries']) ? 0 : 1,
            'updated_at'         => Time::now(),
        ];
        if ( $contact_id ) {
            $wpdb->update(Schema::table('client_contacts'), $row, ['id' => $contact_id, 'client_id' => $client_id]);
        } else {
            $row['created_at'] = Time::now();
            $wpdb->insert(Schema::table('client_contacts'), $row);
            $contact_id = (int) $wpdb->insert_id;
        }

        $wpdb->delete(Schema::table('contact_emails'), ['contact_id' => $contact_id]);
        foreach ( $clean_emails as $email => $kind ){
            self::add_email($client_id, $contact_id, $email, $kind, 'manual');
        }
        return (int) $contact_id;
    }

    /** Adds one email to a contact (also used when an agent "learns" a new address after confirmation). */
    public static function add_email($client_id, $contact_id, $email, $kind = 'work', $learned_from = 'manual'){
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ( ! $email || self::find_by_email($email) ) return false;
        return (bool) $wpdb->insert(Schema::table('contact_emails'), [
            'client_id'    => (int) $client_id,
            'contact_id'   => (int) $contact_id,
            'email'        => $email,
            'kind'         => in_array($kind, self::EMAIL_KINDS, true) ? $kind : 'work',
            'learned_from' => sanitize_key($learned_from),
            'created_at'   => Time::now(),
        ]);
    }

    public static function delete_contact($client_id, $contact_id){
        global $wpdb;
        $wpdb->delete(Schema::table('contact_emails'), ['contact_id' => $contact_id, 'client_id' => $client_id]);
        $wpdb->delete(Schema::table('client_contacts'), ['id' => $contact_id, 'client_id' => $client_id]);
    }

    /** @return array{client_id:int,contact_id:int,email:string,kind:string}|null */
    public static function find_by_email($email){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT client_id, contact_id, email, kind FROM ' . Schema::table('contact_emails') . ' WHERE email = %s',
            strtolower(trim((string) $email))
        ), ARRAY_A);
        if ( ! $row ) return null;
        $row['client_id'] = (int) $row['client_id'];
        $row['contact_id'] = (int) $row['contact_id'];
        return $row;
    }
}
