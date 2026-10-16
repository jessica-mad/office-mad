<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Sends what the office has to say, always as the bot.
 *
 * Test mode (on by default): everything addressed to a client goes to Jessica's
 * test space instead, with a banner naming the real destination.
 */
final class Messenger {

    const KINDS = ['summaries' => 'Resúmenes de reunión', 'reminders' => 'Recordatorios'];

    private $settings;
    private $api;

    public function __construct(array $settings, ?ChatApi $api){
        $this->settings = $settings;
        $this->api = $api;
    }

    public function test_mode(){
        return ! array_key_exists('test_mode', $this->settings) || ! empty($this->settings['test_mode']);
    }

    public function manager_email(){
        if ( ! empty($this->settings['manager_email']) ) return strtolower($this->settings['manager_email']);
        $google = get_option(\MAD_Oficina_Core::option_key('google'), []);
        return strtolower($google['connected_email'] ?? get_option('admin_email'));
    }

    /** The DM where the bot talks to Jessica (null) or to a team member. */
    public function dm_space(?array $member = null){
        return $member ? (string) ($member['chat_dm_space'] ?? '') : (string) ($this->settings['manager_dm'] ?? '');
    }

    /** @return array|\WP_Error */
    public function to_manager(array $message){
        $dm = $this->settings['manager_dm'] ?? '';
        if ( ! $this->api ) return new \WP_Error('madof_chat_off', __('El bot no está configurado.', 'mad-oficina'));
        if ( ! $dm ) return new \WP_Error('madof_chat_dm', __('El bot todavía no tiene tu chat directo: escríbele «hola» en Google Chat.', 'mad-oficina'));
        return $this->api->create_message($dm, $message);
    }

    /** @return array|\WP_Error */
    public function to_team_member(array $member, array $message){
        if ( ! $this->api ) return new \WP_Error('madof_chat_off', __('El bot no está configurado.', 'mad-oficina'));
        if ( empty($member['chat_dm_space']) ) return new \WP_Error('madof_chat_dm', sprintf(__('%s todavía no ha abierto un chat con el bot.', 'mad-oficina'), $member['name']));
        return $this->api->create_message($member['chat_dm_space'], $message);
    }

    /**
     * Client destination for a kind of message: ['type' => space|webhook|email|none, 'value' => ...].
     */
    public static function target(array $client, $kind){
        $t = $client['chat_targets'][$kind] ?? null;
        return is_array($t) && ! empty($t['type']) ? $t : ['type' => 'none', 'value' => ''];
    }

    /**
     * Sends plain text to a client. Callers only reach this after an approval.
     * @return array{channel:string, to:string}|\WP_Error
     */
    public function to_client(array $client, $kind, $text, $subject = ''){
        $target = self::target($client, $kind);
        if ( $target['type'] === 'none' ) return new \WP_Error('madof_chat_target', sprintf(__('%s no tiene destino para este tipo de mensaje.', 'mad-oficina'), $client['name']));

        $real = $target['type'] === 'email' ? __('email a los contactos', 'mad-oficina') : ($target['label'] ?? $target['value']);
        if ( $this->test_mode() ) {
            $space = $this->settings['test_space'] ?? '';
            if ( ! $space || ! $this->api ) return new \WP_Error('madof_chat_test', __('Modo pruebas activo pero sin espacio de pruebas elegido.', 'mad-oficina'));
            $banner = '*' . sprintf(__('[PRUEBA · Destino real: %1$s · %2$s]', 'mad-oficina'), $client['code'], $real) . "*\n\n";
            $res = $this->api->create_message($space, ['text' => $banner . $text]);
            return is_wp_error($res) ? $res : $this->log($client, 'chat', $text, 'test:' . $space);
        }

        switch ( $target['type'] ) {
            case 'space':
                if ( ! $this->api ) return new \WP_Error('madof_chat_off', __('El bot no está configurado.', 'mad-oficina'));
                $res = $this->api->create_message($target['value'], ['text' => $text]);
                return is_wp_error($res) ? $res : $this->log($client, 'chat', $text, $target['value']);
            case 'webhook':
                $target['value'] = \MAD_Oficina\Core\Crypto::decrypt($target['value']) ?? '';
                if ( ! preg_match('#^https://chat\.googleapis\.com/v1/spaces/[^/]+/messages\?#', $target['value']) ) return new \WP_Error('madof_chat_webhook', __('URL de webhook no válida.', 'mad-oficina'));
                $res = wp_remote_post($target['value'], ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json; charset=utf-8'], 'body' => wp_json_encode(['text' => $text])]);
                if ( is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 300 ) return new \WP_Error('madof_chat_webhook', __('El webhook no ha aceptado el mensaje.', 'mad-oficina'));
                return $this->log($client, 'chat', $text, 'webhook');
            case 'email':
                $emails = [];
                foreach ( Clients::contacts($client['id']) as $c ){
                    if ( ! $c['receives_summaries'] ) continue;
                    $work = array_values(array_filter($c['emails'], fn($e) => $e['kind'] === 'work'));
                    if ( $pick = ($work[0]['email'] ?? ($c['emails'][0]['email'] ?? null)) ) $emails[] = $pick;
                }
                if ( ! $emails ) return new \WP_Error('madof_chat_email', __('Ningún contacto del cliente recibe resúmenes.', 'mad-oficina'));
                $from = fn() => 'MAD Oficina · Asistente';
                add_filter('wp_mail_from_name', $from);
                $copy = \MAD_Oficina\Core\ClientCopy::address($client);
                $ok = wp_mail($emails, $subject ?: __('Resumen de la reunión con MAD', 'mad-oficina'), str_replace(['*', '_'], '', $text), $copy ? ['Bcc: ' . $copy] : []);
                remove_filter('wp_mail_from_name', $from);
                return $ok ? $this->log($client, 'email', $text, implode(',', $emails)) : new \WP_Error('madof_chat_email', __('No se pudo enviar el email.', 'mad-oficina'));
        }
        return new \WP_Error('madof_chat_target', __('Tipo de destino desconocido.', 'mad-oficina'));
    }

    private function log(array $client, $channel, $text, $to){
        global $wpdb;
        $wpdb->insert(Schema::table('messages'), [
            'client_id'   => $client['id'],
            'thread'      => 'outbox',
            'author_type' => 'agent',
            'agent'       => 'chat',
            'body'        => $text,
            'channel'     => $channel,
            'visibility'  => Schema::VISIBILITY_INTERNAL,
            'created_at'  => Time::now(),
        ]);
        return ['channel' => $channel, 'to' => $to];
    }
}
