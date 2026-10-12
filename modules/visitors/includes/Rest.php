<?php
namespace MAD_Oficina\Visitors;

use MAD_Oficina\Core\ClientContext;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Roles;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * Public endpoints (no login): visitor brief and email confirmation. Every
 * public write goes through the honeypot, Turnstile and per-IP limits, and a
 * visitor's lead only reaches Jessica once its email is confirmed.
 * Clients use /portal/brief («Nuevo encargo»): no confirmation needed.
 */
final class Rest {

    const NS = 'mad-oficina/v1';
    const BRIEFS_PER_DAY = 3;

    private $settings;

    /** @param callable $settings returns the module settings, read on each request */
    public function __construct(callable $settings){
        $this->settings = $settings;
    }

    private function settings(){
        return (array) call_user_func($this->settings);
    }

    public function register(){
        register_rest_route(self::NS, '/public/brief', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [$this, 'visitor_brief'],
        ]);
        register_rest_route(self::NS, '/public/slots', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [$this, 'slots'],
        ]);
        register_rest_route(self::NS, '/public/meeting', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [$this, 'visitor_meeting'],
        ]);
        register_rest_route(self::NS, '/public/chat', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [$this, 'visitor_chat'],
            'args' => ['message' => ['type' => 'string', 'required' => true, 'maxLength' => VisitorChat::MAX_CHARS]],
        ]);
        register_rest_route(self::NS, '/public/verify', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [$this, 'verify'],
            'args' => ['lead' => ['type' => 'integer', 'required' => true], 'token' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(self::NS, '/portal/brief', [
            'methods' => 'POST', 'callback' => [$this, 'client_brief'],
            'permission_callback' => function(){
                if ( ! is_user_logged_in() ) return new \WP_Error('rest_not_logged_in', __('Inicia sesión.', 'mad-oficina'), ['status' => 401]);
                return current_user_can(Roles::CAP_PORTAL) ? true : new \WP_Error('rest_forbidden', __('Sin acceso.', 'mad-oficina'), ['status' => 403]);
            },
        ]);
    }

    private function secret(){
        return \MAD_Oficina\Core\Crypto::decrypt($this->settings()['turnstile_secret'] ?? '') ?: '';
    }

    public function visitor_brief(\WP_REST_Request $req){
        $p = (array) $req->get_json_params() + (array) $req->get_body_params();
        // Bots that fill the hidden field get a normal-looking answer and nothing is stored.
        if ( Guard::is_bot($p) ) return rest_ensure_response(['ok' => true, 'verify' => true]);
        if ( empty($p['consent']) ) return new \WP_Error('madof_consent', __('Necesitamos tu consentimiento para guardar tus datos y responderte.', 'mad-oficina'), ['status' => 400]);
        $ts = Guard::turnstile($this->secret(), $p['turnstile'] ?? '');
        if ( is_wp_error($ts) ) return $ts;

        $brief = Leads::clean_brief($p, true);
        if ( is_wp_error($brief) ) return $brief;
        if ( ! Guard::allow('brief', self::BRIEFS_PER_DAY) || ! Guard::allow('brief-email', self::BRIEFS_PER_DAY, DAY_IN_SECONDS, $brief['email']) ) {
            return new \WP_Error('madof_limit', __('Ya hemos recibido varios mensajes tuyos hoy. Te responderemos pronto.', 'mad-oficina'), ['status' => 429]);
        }

        $token = Leads::new_token();
        $id = Leads::create($brief + [
            'source' => 'brief', 'verify_hash' => hash('sha256', $token),
            'consent_at' => Time::now(), 'ip_hash' => Guard::ip_hash(),
        ]);
        $sent = $this->send_confirmation($id, $token, $brief, $p['return'] ?? '');
        if ( ! $sent ) {
            Leads::delete($id);
            return new \WP_Error('madof_mail', __('No hemos podido enviarte el email de confirmación. Inténtalo más tarde, por favor.', 'mad-oficina'), ['status' => 500]);
        }
        return rest_ensure_response(['ok' => true, 'verify' => true]);
    }

    public function visitor_chat(\WP_REST_Request $req){
        $p = (array) $req->get_json_params();
        if ( Guard::is_bot($p) ) return new \WP_Error('madof_bot', 'No', ['status' => 400]);
        $chat = new VisitorChat($this->settings());
        return rest_ensure_response($chat->reply($p['token'] ?? null, (string) ($p['message'] ?? ''), $this->secret(), (string) ($p['turnstile'] ?? '')));
    }

    public function slots(){
        if ( ! Guard::allow('public-slots', 60, HOUR_IN_SECONDS) ) return new \WP_Error('madof_limit', __('Demasiadas consultas. Inténtalo en un rato.', 'mad-oficina'), ['status' => 429]);
        $slots = FirstMeeting::offer();
        if ( is_wp_error($slots) ) return rest_ensure_response(['slots' => [], 'error' => $slots->get_error_message()]);
        return rest_ensure_response(['slots' => $slots, 'duration' => FirstMeeting::DURATION]);
    }

    public function visitor_meeting(\WP_REST_Request $req){
        $p = (array) $req->get_json_params() + (array) $req->get_body_params();
        if ( Guard::is_bot($p) ) return rest_ensure_response(['ok' => true, 'verify' => true]);
        if ( empty($p['consent']) ) return new \WP_Error('madof_consent', __('Necesitamos tu consentimiento para guardar tus datos y responderte.', 'mad-oficina'), ['status' => 400]);
        $ts = Guard::turnstile($this->secret(), $p['turnstile'] ?? '');
        if ( is_wp_error($ts) ) return $ts;
        $r = FirstMeeting::submit($p);
        if ( is_wp_error($r) ) return $r;
        $lead = Leads::get($r['lead']);
        $sent = $this->send_confirmation($r['lead'], $r['token'], $lead, $p['return'] ?? '', $r['label']);
        if ( ! $sent ) {
            Leads::delete($r['lead']);
            return new \WP_Error('madof_mail', __('No hemos podido enviarte el email de confirmación. Inténtalo más tarde, por favor.', 'mad-oficina'), ['status' => 500]);
        }
        return rest_ensure_response(['ok' => true, 'verify' => true, 'label' => $r['label']]);
    }

    private function send_confirmation($id, $token, array $brief, $return, $meeting_label = null){
        $return = wp_validate_redirect(esc_url_raw((string) $return), '') ?: ($this->settings()['portal_url'] ?? home_url('/oficina/'));
        $link = add_query_arg(['lead' => $id, 'token' => $token, 'return' => rawurlencode($return)], rest_url(self::NS . '/public/verify'));
        if ( $meeting_label ) {
            $subject = __('Confirma tu petición de reunión con MAD', 'mad-oficina');
            $body = sprintf(__("Hola, %1\$s:\n\nHas pedido una primera reunión con Jessica para el %2\$s. Confirma que este email es tuyo para que le llegue la petición:\n\n%3\$s\n\nCuando la apruebe, te llegará la invitación de Google Calendar con el enlace de Meet. El enlace caduca en 3 días. Si no has sido tú, ignora este email y borraremos los datos.\n\nMAD · Marketing & Ads\nhttps://imad.pro", 'mad-oficina'), $brief['name'], $meeting_label, $link);
        } else {
            $subject = __('Confirma tu mensaje a MAD', 'mad-oficina');
            $body = sprintf(__("Hola, %s:\n\nGracias por escribirnos. Para que tu mensaje llegue a Jessica, confirma que este email es tuyo:\n\n%s\n\nEl enlace caduca en 3 días. Si no has sido tú, ignora este email y borraremos los datos.\n\nMAD · Marketing & Ads\nhttps://imad.pro", 'mad-oficina'), $brief['name'], $link);
        }
        $from = function(){ return __('MAD · Marketing & Ads', 'mad-oficina'); };
        add_filter('wp_mail_from_name', $from);
        $ok = wp_mail($brief['email'], $subject, $body);
        remove_filter('wp_mail_from_name', $from);
        return $ok;
    }

    public function verify(\WP_REST_Request $req){
        $lead = Leads::verify((int) $req->get_param('lead'), (string) $req->get_param('token'));
        $return = wp_validate_redirect(esc_url_raw((string) $req->get_param('return')), '') ?: ($this->settings()['portal_url'] ?? home_url('/oficina/'));
        if ( is_wp_error($lead) ) {
            wp_safe_redirect(add_query_arg('madof', 'enlace-no-valido', $return));
            exit;
        }
        do_action('madoficina_lead_verified', $lead);
        do_action('madoficina_lead_created', $lead['id']);
        wp_safe_redirect(add_query_arg('madof', $lead['source'] === 'meeting' ? 'reunion-pedida' : 'confirmado', $return));
        exit;
    }

    public function client_brief(\WP_REST_Request $req){
        $user = wp_get_current_user();
        $client_id = ClientContext::client_for_user($user->ID);
        if ( ! $client_id ) return new \WP_Error('rest_forbidden', __('Solo los clientes pueden enviar un encargo desde aquí.', 'mad-oficina'), ['status' => 403]);
        if ( ! Guard::allow('client-brief', 10, DAY_IN_SECONDS, 'c' . $client_id) ) {
            return new \WP_Error('madof_limit', __('Has enviado varios encargos hoy. Jessica los está revisando.', 'mad-oficina'), ['status' => 429]);
        }
        $p = (array) $req->get_json_params();
        $brief = Leads::clean_brief($p, false);
        if ( is_wp_error($brief) ) return $brief;
        $client = Clients::get($client_id);
        $id = Leads::create(array_merge($brief, [
            'client_id' => $client_id, 'name' => $user->display_name, 'email' => $user->user_email,
            'company' => $client['name'], 'source' => 'portal', 'verified_at' => Time::now(),
        ]));
        do_action('madoficina_lead_created', $id);
        return rest_ensure_response(['ok' => true, 'verify' => false]);
    }
}
