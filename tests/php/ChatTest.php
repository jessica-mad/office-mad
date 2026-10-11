<?php
use MAD_Oficina\Chat\GoogleJwt;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Meetings\Meetings;
use MAD_Oficina\Meetings\WhatsApp;

/**
 * Google Chat bot: signed events, who may approve, test mode and messages.
 * Everything here is invented data.
 */
class ChatTest extends MADOF_TestCase {

    const PROJECT = '123456789012';
    private static $google_key;   // Google's signing key (simulated)
    private static $google_cert;
    private static $sa_key;       // the bot's service-account key

    private $client;
    private $liz;
    private $sent = [];

    public static function wpSetUpBeforeClass($factory){
        self::$google_key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'chat@system.gserviceaccount.com'], self::$google_key);
        openssl_x509_export(openssl_csr_sign($csr, null, self::$google_key, 1), self::$google_cert);
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), self::$sa_key);
    }

    public function set_up(){
        parent::set_up();
        add_filter('madoficina_chat_certs', fn() => ['kid1' => self::$google_cert]);
        add_filter('pre_http_request', [$this, 'fake_google'], 10, 3);
        update_option(MAD_Oficina_Core::option_key('chat'), [
            'project_number'  => self::PROJECT,
            'manager_email'   => 'jessica@imad.example',
            'manager_dm'      => 'spaces/JESSDM',
            'test_space'      => 'spaces/TEST',
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => self::$sa_key, 'token_uri' => 'https://oauth2.googleapis.com/token'])),
        ]);
        delete_transient('madof_chat_token_' . md5('bot@proj.iam.gserviceaccount.com'));

        $this->client = Clients::save(['name' => 'Lanas del Norte', 'code' => 'LN']);
        Clients::save_contact($this->client, ['name' => 'Rosa', 'receives_summaries' => 1], [['email' => 'rosa@lanasnorte.example']]);
        $this->liz = Team::save(['name' => 'Liz', 'slug' => 'liz', 'workspace_email' => 'liz@imad.example', 'chat_dm_space' => 'spaces/LIZDM']);
    }

    public function tear_down(){
        remove_all_filters('madoficina_chat_certs');
        remove_filter('pre_http_request', [$this, 'fake_google']);
        parent::tear_down();
    }

    public function fake_google($pre, $args, $url){
        if ( $url === 'https://oauth2.googleapis.com/token' ) {
            $this->assertStringContainsString('jwt-bearer', $args['body']['grant_type']);
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 'bot-token', 'expires_in' => 3600])];
        }
        if ( strpos($url, 'https://chat.googleapis.com/v1/') === 0 ) {
            $this->sent[] = ['url' => $url, 'auth' => $args['headers']['Authorization'] ?? '', 'body' => json_decode($args['body'] ?? '{}', true)];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['name' => 'spaces/X/messages/' . count($this->sent)])];
        }
        return $pre;
    }

    private function module(){ return MAD_Oficina_Core::instance()->get_module('chat'); }

    private function token(array $override = [], $key = null){
        return GoogleJwt::sign($override + ['iss' => GoogleJwt::CHAT_ISSUER, 'aud' => self::PROJECT, 'iat' => time(), 'exp' => time() + 3600], $this->pem($key ?? self::$google_key), 'kid1');
    }

    private function pem($key){ openssl_pkey_export($key, $out); return $out; }

    private function event(array $body, $token = null){
        $req = new WP_REST_Request('POST', '/mad-oficina/v1/chat/events');
        $req->set_header('Content-Type', 'application/json');
        $req->set_header('Authorization', 'Bearer ' . ($token ?? $this->token()));
        $req->set_body(wp_json_encode($body));
        return rest_do_request($req);
    }

    /* ───────── signed events ───────── */

    public function test_events_require_a_valid_google_token(){
        $msg = ['type' => 'MESSAGE', 'user' => ['email' => 'jessica@imad.example'], 'space' => ['name' => 'spaces/JESSDM', 'type' => 'DM']];
        $this->assertSame(200, $this->event($msg)->get_status());
        $this->assertSame(401, $this->event($msg, $this->token(['aud' => '999']))->get_status(), 'Other project');
        $this->assertSame(401, $this->event($msg, $this->token(['iss' => 'evil@example.com']))->get_status());
        $this->assertSame(401, $this->event($msg, $this->token(['exp' => time() - 3600, 'iat' => time() - 7200]))->get_status());
        $this->assertSame(401, $this->event($msg, $this->token([], openssl_pkey_new(['private_key_bits' => 2048])))->get_status(), 'Forged signature');
        $this->assertSame(401, $this->event($msg, 'not-a-jwt')->get_status());
    }

    public function test_dm_spaces_are_learned_for_jessica_and_liz(){
        update_option(MAD_Oficina_Core::option_key('chat'), ['manager_dm' => ''] + get_option(MAD_Oficina_Core::option_key('chat')));
        $this->event(['type' => 'ADDED_TO_SPACE', 'user' => ['email' => 'Jessica@imad.example'], 'space' => ['name' => 'spaces/NEWJESS', 'type' => 'DM']]);
        $this->assertSame('spaces/NEWJESS', get_option(MAD_Oficina_Core::option_key('chat'))['manager_dm']);
        $this->event(['type' => 'MESSAGE', 'user' => ['email' => 'liz@imad.example'], 'space' => ['name' => 'spaces/NEWLIZ', 'spaceType' => 'DIRECT_MESSAGE']]);
        $this->assertSame('spaces/NEWLIZ', Team::get($this->liz)['chat_dm_space']);
    }

    /* ───────── approvals from Chat ───────── */

    private function draft(){
        update_option(MAD_Oficina_Core::option_key('meetings'), []);
        $id = Meetings::upsert(['source' => 'manual', 'title' => 'LN x MAD', 'starts_at' => '2026-10-07 17:00', 'transcript' => 'x', 'client_id' => $this->client]);
        Meetings::update($id, ['status' => 'extracted', 'extraction' => [
            'summary_client' => 'Resumen para Rosa', 'summary_internal' => 'interno', 'decisions' => ['Catálogo por colores'],
            'tasks' => [
                ['title' => 'Enviar fotos', 'description' => '', 'owner' => 'cliente', 'due_date' => '2026-10-16', 'priority' => 'high', 'client_visible' => true],
                ['title' => 'Editar vídeo', 'description' => 'Cambiar texto', 'owner' => 'liz', 'due_date' => null, 'priority' => 'normal', 'client_visible' => true],
                ['title' => 'Revisar margen', 'description' => '', 'owner' => 'jessica', 'due_date' => null, 'priority' => 'low', 'client_visible' => false],
            ],
            'pending_topics' => [], 'ideas' => [], 'next_meeting' => ['status' => 'tbd', 'date' => null, 'notes' => ''], 'internal_notes' => [],
            '_identification' => ['unknown' => [], 'reasons' => []],
        ]]);
        return [$id, Approvals::create('secretary', 'meeting_draft', 'Acta LN', ['meeting_id' => $id], ['client_id' => $this->client, 'object_type' => 'meeting', 'object_id' => $id])];
    }

    private function click($fn, $params, $email){
        return $this->event(['type' => 'CARD_CLICKED', 'user' => ['email' => $email], 'space' => ['name' => 'spaces/JESSDM', 'type' => 'DM'],
            'common' => ['invokedFunction' => $fn, 'parameters' => $params]]);
    }

    public function test_only_jessica_can_approve_from_chat_and_it_applies_the_draft_as_proposed(){
        [$meeting, $approval] = $this->draft();

        $res = $this->click('approve', ['approval_id' => (string) $approval], 'liz@imad.example');
        $this->assertStringContainsString('Solo Jessica', $res->get_data()['text']);
        $this->assertSame('pending', Approvals::get($approval)['status']);

        $res = $this->click('approve', ['approval_id' => (string) $approval], 'jessica@imad.example');
        $this->assertSame('UPDATE_MESSAGE', $res->get_data()['actionResponse']['type']);
        $this->assertSame('approved', Approvals::get($approval)['status']);
        $this->assertSame('chat', Approvals::get($approval)['channel']);
        $this->assertSame('approved', Meetings::get($meeting)['status']);
        $vis = $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare('SELECT visibility FROM ' . Schema::table('action_items') . ' WHERE meeting_id = %d ORDER BY id', $meeting));
        $this->assertSame(['client', 'client', 'internal'], $vis);

        $again = $this->click('approve', ['approval_id' => (string) $approval], 'jessica@imad.example');
        $this->assertStringContainsString('ya se había resuelto', wp_json_encode($again->get_data(), JSON_UNESCAPED_UNICODE));
    }

    public function test_task_done_only_by_its_owner_or_jessica(){
        global $wpdb;
        $mine = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'De Liz', 'owner' => 'liz', 'team_member_id' => $this->liz, 'status' => 'open']);
        $other = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'De Jessica', 'owner' => 'jessica', 'status' => 'open']);
        $this->click('task_done', ['task_id' => (string) $other], 'liz@imad.example');
        $this->assertSame('open', $wpdb->get_var("SELECT status FROM " . Schema::table('action_items') . " WHERE id = {$other}"));
        $this->click('task_done', ['task_id' => (string) $mine], 'liz@imad.example');
        $this->assertSame('done', $wpdb->get_var("SELECT status FROM " . Schema::table('action_items') . " WHERE id = {$mine}"));
    }

    /* ───────── outgoing messages ───────── */

    public function test_new_approvals_send_a_card_to_jessica_as_the_bot(){
        [, $approval] = $this->draft();
        $this->module()->send_approval_card($approval);
        $this->assertSame('https://chat.googleapis.com/v1/spaces/JESSDM/messages', $this->sent[0]['url']);
        $this->assertSame('Bearer bot-token', $this->sent[0]['auth']);
        $buttons = $this->sent[0]['body']['cardsV2'][0]['card']['sections'][0]['widgets'][1]['buttonList']['buttons'];
        $this->assertSame(['approve', 'reject'], [$buttons[0]['onClick']['action']['function'], $buttons[2]['onClick']['action']['function']]);
        $this->assertStringContainsString('3 tareas', $this->sent[0]['body']['cardsV2'][0]['card']['sections'][0]['widgets'][0]['textParagraph']['text']);
        $this->assertSame('spaces/X/messages/1', Approvals::get($approval)['chat_message_name']);
    }

    public function test_approved_meeting_messages_respect_test_mode_and_visibility(){
        [$meeting, $approval] = $this->draft();
        $GLOBALS['wpdb']->update(Schema::table('clients'), ['chat_targets' => wp_json_encode(['summaries' => ['type' => 'space', 'value' => 'spaces/CLIENTE', 'label' => 'LN x MAD']])], ['id' => $this->client]);
        Approvals::resolve($approval, 'approve', MAD_Oficina\Meetings\Secretary::default_input([], Approvals::get($approval)), 1);
        $this->module()->send_meeting_messages($meeting, true, true);

        $urls = array_column($this->sent, 'url');
        $this->assertContains('https://chat.googleapis.com/v1/spaces/LIZDM/messages', $urls, 'Liz gets her task');
        $this->assertContains('https://chat.googleapis.com/v1/spaces/TEST/messages', $urls, 'Test mode: client summary goes to the test space');
        $this->assertNotContains('https://chat.googleapis.com/v1/spaces/CLIENTE/messages', $urls);

        $summary = array_values(array_filter($this->sent, fn($s) => str_contains($s['url'], 'TEST')))[0]['body']['text'];
        $this->assertStringContainsString('[PRUEBA · Destino real: LN · LN x MAD]', $summary);
        $this->assertStringContainsString('Enviar fotos', $summary);
        $this->assertStringNotContainsString('Revisar margen', $summary, 'Internal tasks never reach the client');
        $this->assertStringContainsString('Mensaje automático de la oficina de MAD', $summary);
        $liz_cards = array_filter($this->sent, fn($s) => str_contains($s['url'], 'LIZDM'));
        $this->assertCount(1, $liz_cards);

        // Test mode off → the client's real space.
        $this->sent = [];
        $s = get_option(MAD_Oficina_Core::option_key('chat'));
        update_option(MAD_Oficina_Core::option_key('chat'), ['test_mode' => false] + $s);
        $this->module()->send_meeting_messages($meeting, false, true);
        $this->assertSame(['https://chat.googleapis.com/v1/spaces/CLIENTE/messages'], array_column($this->sent, 'url'));
    }

    public function test_email_destination_and_nothing_without_the_checkbox(){
        [$meeting, $approval] = $this->draft();
        Approvals::resolve($approval, 'approve', MAD_Oficina\Meetings\Secretary::default_input([], Approvals::get($approval)), 1);
        $GLOBALS['wpdb']->update(Schema::table('clients'), ['chat_targets' => wp_json_encode(['summaries' => ['type' => 'email', 'value' => '']])], ['id' => $this->client]);
        update_option(MAD_Oficina_Core::option_key('chat'), ['test_mode' => false] + get_option(MAD_Oficina_Core::option_key('chat')));

        reset_phpmailer_instance();
        $this->module()->send_meeting_messages($meeting, false, false);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent());
        $this->assertSame([], $this->sent);

        $this->module()->send_meeting_messages($meeting, false, true);
        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertSame('rosa@lanasnorte.example', $mail->to[0][0]);
        $this->assertStringContainsString('Mensaje automático', $mail->body);
    }

    /* ───────── WhatsApp import ───────── */

    public function test_whatsapp_exports_from_android_and_iphone_are_parsed(){
        $android = "06/10/26, 9:15 - Rosa: Hola Jessica\n07/10/26, 17:57 - Rosa: ¿Podéis cambiar el texto del anuncio?\nY subirlo hoy\n07/10/26, 18:01 - Jessica: <Multimedia omitido>\n08/10/26, 10:00 - Jessica: Hecho";
        $p = WhatsApp::parse($android, '2026-10-07');
        $this->assertCount(2, $p['messages']);
        $this->assertSame("¿Podéis cambiar el texto del anuncio?\nY subirlo hoy", $p['messages'][0]['text']);
        $this->assertSame('2026-10-08 10:00', $p['to']);

        $ios = "[7/10/26, 17:57:12] Rosa Prieto: Buenas\n[7/10/26, 17:58:03] Jessica Murillo: Dime";
        $q = WhatsApp::parse($ios);
        $this->assertSame(['Rosa Prieto', 'Jessica Murillo'], $q['people']);
        $this->assertSame('2026-10-07 17:57', $q['from']);
    }
}
