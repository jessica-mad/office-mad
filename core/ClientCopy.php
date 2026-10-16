<?php
namespace MAD_Oficina\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * A hidden copy (Bcc) of every email that goes to a client, to an address with the
 * client in it (e.g. you+c.lanas-del-norte@yourdomain), so Jessica can follow in her
 * Gmail what each client was sent (search or filter by «to:»).
 */
final class ClientCopy {

    const OPTION = 'madoficina_client_copy';

    /** ['on' => bool, 'template' => 'name+c.{cliente}@domain'] */
    public static function settings(){
        $s = get_option(self::OPTION, null);
        if ( ! is_array($s) ) $s = ['on' => true, 'template' => ''];
        if ( trim((string) ($s['template'] ?? '')) === '' ) $s['template'] = self::default_template();
        return $s;
    }

    /** Jessica's own address with «+c.{cliente}» (Chat manager email, else the site's admin email). */
    public static function default_template(){
        $chat = get_option(\MAD_Oficina_Core::option_key('chat'), []);
        $email = strtolower((string) (($chat['manager_email'] ?? '') ?: get_option('admin_email')));
        if ( ! is_email($email) ) return '';
        [$local, $domain] = explode('@', $email, 2);
        return preg_replace('/\+.*$/', '', $local) . '+c.{cliente}@' . $domain;
    }

    /** @return true|\WP_Error */
    public static function save($on, $template){
        $template = strtolower(trim((string) $template));
        if ( $template !== '' && ! is_email(self::fill($template, 'cliente', 'COD')) ) {
            return new \WP_Error('madof_copy', __('La dirección de copia no es válida. Ejemplo: tu+c.{cliente}@tudominio.com', 'mad-oficina'));
        }
        update_option(self::OPTION, ['on' => (bool) $on, 'template' => $template === self::default_template() ? '' : $template], false);
        return true;
    }

    /** «Lanas del Norte, S.L.» → «lanas-del-norte-sl». */
    public static function slug($name){
        return substr(trim(sanitize_title(str_replace(['.', "'"], '', remove_accents((string) $name))), '-'), 0, 40);
    }

    private static function fill($template, $name, $code){
        return strtr($template, ['{cliente}' => self::slug($name) ?: 'cliente', '{codigo}' => strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $code)) ?: 'cliente']);
    }

    /**
     * The copy address for a client (row from clients, or just a name), or '' if off.
     * @param array|string $client
     */
    public static function address($client){
        $s = self::settings();
        if ( empty($s['on']) || $s['template'] === '' ) return '';
        $name = is_array($client) ? ($client['name'] ?? '') : (string) $client;
        $code = is_array($client) ? ($client['code'] ?? '') : '';
        if ( trim($name) === '' ) return '';
        $to = self::fill($s['template'], $name, $code);
        return is_email($to) ? $to : '';
    }

    /** For an invoice: the client's name in the office when it has one, else the invoice's. */
    public static function for_invoice(array $inv){
        $client = ! empty($inv['client_id']) ? Repo\Clients::get((int) $inv['client_id']) : null;
        return self::address($client ?: (string) ($inv['client_name'] ?? ''));
    }
}
