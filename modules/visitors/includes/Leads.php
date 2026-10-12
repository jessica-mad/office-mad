<?php
namespace MAD_Oficina\Visitors;

use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Leads: briefs and first-meeting requests from visitors, and «Nuevo encargo»
 * from clients. A visitor's lead only counts once the email is confirmed.
 */
final class Leads {

    const STATUSES = ['new' => 'Nuevo', 'contacted' => 'Contactado', 'client' => 'Cliente', 'discarded' => 'Descartado'];

    const SERVICES = ['web' => 'Web o tienda online', 'ads' => 'Google Ads / Meta Ads', 'design' => 'Diseño y marca', 'content' => 'Contenidos y redes', 'video' => 'Vídeo', 'other' => 'Otra cosa'];
    const TIMELINES = ['asap' => 'Lo antes posible', '1-3m' => 'En 1–3 meses', '3m+' => 'Más adelante', 'unknown' => 'Aún no lo sé'];
    const BUDGETS = ['' => 'Prefiero no decirlo', '<1k' => 'Menos de 1.000 €', '1-3k' => '1.000–3.000 €', '3-6k' => '3.000–6.000 €', '6k+' => 'Más de 6.000 €', 'monthly' => 'Cuota mensual'];

    /**
     * Validates and normalises a brief.
     * @return array|\WP_Error
     */
    public static function clean_brief(array $p, $needs_contact = true){
        $out = [
            'name'     => mb_substr(sanitize_text_field($p['name'] ?? ''), 0, 120),
            'email'    => sanitize_email($p['email'] ?? ''),
            'company'  => mb_substr(sanitize_text_field($p['company'] ?? ''), 0, 160),
            'website'  => mb_substr(esc_url_raw(self::with_scheme($p['website'] ?? '')), 0, 250),
            'services' => array_values(array_intersect(array_keys(self::SERVICES), (array) ($p['services'] ?? []))),
            'message'  => mb_substr(sanitize_textarea_field($p['message'] ?? ''), 0, 4000),
            'timeline' => array_key_exists((string) ($p['timeline'] ?? ''), self::TIMELINES) ? (string) $p['timeline'] : 'unknown',
            'budget'   => array_key_exists((string) ($p['budget'] ?? ''), self::BUDGETS) ? (string) ($p['budget'] ?? '') : '',
        ];
        if ( $needs_contact ) {
            if ( $out['name'] === '' ) return new \WP_Error('madof_brief', __('Dinos tu nombre.', 'mad-oficina'), ['status' => 400]);
            if ( ! is_email($out['email']) ) return new \WP_Error('madof_brief', __('Revisa el email: lo necesitamos para responderte.', 'mad-oficina'), ['status' => 400]);
        }
        if ( mb_strlen(trim($out['message'])) < 10 ) return new \WP_Error('madof_brief', __('Cuéntanos un poco más del proyecto.', 'mad-oficina'), ['status' => 400]);
        return $out;
    }

    private static function with_scheme($url){
        $url = trim((string) $url);
        return ($url !== '' && ! preg_match('#^https?://#i', $url)) ? 'https://' . $url : $url;
    }

    /** @return int lead id */
    public static function create(array $data){
        global $wpdb;
        $now = Time::now();
        $wpdb->insert(Schema::table('leads'), [
            'client_id'   => $data['client_id'] ?? null,
            'name'        => $data['name'],
            'email'       => strtolower($data['email']),
            'company'     => ($data['company'] ?? '') ?: null,
            'website'     => ($data['website'] ?? '') ?: null,
            'services'    => wp_json_encode($data['services'] ?? []),
            'message'     => $data['message'] ?? null,
            'timeline'    => $data['timeline'] ?? null,
            'budget'      => $data['budget'] ?? null,
            'source'      => $data['source'] ?? 'brief',
            'status'      => 'new',
            'verify_hash' => $data['verify_hash'] ?? null,
            'verified_at' => $data['verified_at'] ?? null,
            'consent_at'  => $data['consent_at'] ?? null,
            'ip_hash'     => $data['ip_hash'] ?? null,
            'meeting_start' => $data['meeting_start'] ?? null,
            'meeting_end'   => $data['meeting_end'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get($id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('leads') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::hydrate($row) : null;
    }

    /** @param string $status '' = all confirmed, 'unverified', or one of STATUSES */
    public static function all($status = '', $limit = 200){
        global $wpdb;
        $t = Schema::table('leads');
        if ( $status === 'unverified' ) {
            $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE verified_at IS NULL ORDER BY id DESC LIMIT %d", $limit);
        } elseif ( isset(self::STATUSES[$status]) ) {
            $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE verified_at IS NOT NULL AND status = %s ORDER BY id DESC LIMIT %d", $status, $limit);
        } else {
            $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE verified_at IS NOT NULL ORDER BY id DESC LIMIT %d", $limit);
        }
        return array_map([self::class, 'hydrate'], $wpdb->get_results($sql, ARRAY_A));
    }

    public static function count_new(){
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('leads') . " WHERE status = 'new' AND verified_at IS NOT NULL");
    }

    /** New random token for the confirmation link. @return string plain token (only its hash is stored) */
    public static function new_token(){
        return bin2hex(random_bytes(20));
    }

    /** @return array|\WP_Error the verified lead */
    public static function verify($id, $token){
        global $wpdb;
        $lead = self::get($id);
        if ( ! $lead || ! $lead['verify_hash'] || ! hash_equals($lead['verify_hash'], hash('sha256', (string) $token)) ) {
            return new \WP_Error('madof_verify', __('El enlace no es válido o ya se ha usado.', 'mad-oficina'));
        }
        if ( strtotime($lead['created_at'] . ' UTC') < time() - 3 * DAY_IN_SECONDS ) {
            return new \WP_Error('madof_verify', __('El enlace ha caducado. Vuelve a enviarnos el formulario, por favor.', 'mad-oficina'));
        }
        $wpdb->update(Schema::table('leads'), ['verified_at' => Time::now(), 'verify_hash' => null, 'updated_at' => Time::now()], ['id' => $id]);
        return self::get($id);
    }

    public static function update($id, array $fields){
        global $wpdb;
        $fields['updated_at'] = Time::now();
        return $wpdb->update(Schema::table('leads'), $fields, ['id' => $id]);
    }

    public static function delete($id){
        global $wpdb;
        return $wpdb->delete(Schema::table('leads'), ['id' => $id]);
    }

    /** RGPD: unconfirmed leads are deleted after a few days. */
    public static function purge_unverified($days = 7){
        global $wpdb;
        return $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('leads') . ' WHERE verified_at IS NULL AND created_at < %s', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
    }

    /**
     * Creates the client file from a lead: name, a free short code, the email
     * domain (unless it is a webmail) and the person as a contact.
     * @return int|\WP_Error client id
     */
    public static function convert_to_client($id){
        $lead = self::get($id);
        if ( ! $lead ) return new \WP_Error('madof_lead', __('Ese lead ya no existe.', 'mad-oficina'));
        if ( $lead['client_id'] ) return (int) $lead['client_id'];
        $name = $lead['company'] ?: $lead['name'];
        $domain = strtolower(substr(strrchr($lead['email'], '@'), 1));
        $webmail = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'live.com', 'yahoo.com', 'yahoo.es', 'icloud.com', 'me.com', 'protonmail.com', 'proton.me'];
        $client_id = Clients::save([
            'name'    => $name,
            'code'    => self::free_code($name),
            'domains' => in_array($domain, $webmail, true) ? '' : $domain,
            'status'  => 'active',
        ]);
        if ( is_wp_error($client_id) ) return $client_id;
        Clients::save_contact($client_id, ['name' => $lead['name'], 'receives_summaries' => 1], [['email' => $lead['email']]]);
        self::update($id, ['client_id' => $client_id, 'status' => 'client']);
        return (int) $client_id;
    }

    public static function free_code($name){
        $words = preg_split('/\s+/', remove_accents((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $base = strtoupper(count($words) > 1 ? implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 3))) : substr($words[0] ?? 'CLI', 0, 3));
        $base = preg_replace('/[^A-Z0-9]/', '', $base);
        if ( strlen($base) < 2 ) $base = 'CLI';
        $taken = array_map(fn($c) => strtoupper($c['code']), Clients::all());
        $code = $base;
        for ( $i = 2; in_array($code, $taken, true); $i++ ) $code = $base . $i;
        return $code;
    }

    private static function hydrate($row){
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] ? (int) $row['client_id'] : null;
        $row['meeting_start'] = $row['meeting_start'] ? (int) $row['meeting_start'] : null;
        $row['meeting_end'] = $row['meeting_end'] ? (int) $row['meeting_end'] : null;
        $row['approval_id'] = $row['approval_id'] ? (int) $row['approval_id'] : null;
        $row['services'] = json_decode($row['services'] ?: '[]', true) ?: [];
        return $row;
    }
}
