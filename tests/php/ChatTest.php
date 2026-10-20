<?php
use MAD_Oficina\Chat\GoogleJwt;
use MAD_Oficina\Chat\TaskList;
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
    private $claude = [];   // queued Claude answers (arrays)
    private $prompts = [];  // what Claude was sent
    private $speech = null; // [code, body] for Speech-to-Text
    private $speech_calls = 0;
    private $speech_by_model = [];
    private $speech_models = [];
    private $speech_configs = [];
    private $audio_bytes = 'fake-audio-bytes';
    private $sent_audio = [];

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
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'project_id' => 'mad-oficina-test', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => self::$sa_key, 'token_uri' => 'https://oauth2.googleapis.com/token'])),
        ]);
        delete_transient('madof_chat_token_' . md5('bot@proj.iam.gserviceaccount.com'));
        delete_transient('madof_chat_token_' . md5('bot@proj.iam.gserviceaccount.com|' . \MAD_Oficina\Chat\ServiceAccount::SCOPE_CLOUD));
        delete_option(TaskList::OPTION);
        delete_option(\MAD_Oficina\Chat\Assistant::STATUS);
        delete_option(\MAD_Oficina\Chat\Transcriber::WORKED);
        delete_option(\MAD_Oficina\Chat\Transcriber::LAST);
        delete_option(\MAD_Oficina\Chat\Memory::FACTS);
        delete_option(\MAD_Oficina\Chat\Memory::HISTORY);
        add_filter('madoficina_chat_assistant_mode', fn() => 'inline');

        $this->client = Clients::save(['name' => 'Lanas del Norte', 'code' => 'LN']);
        Clients::save_contact($this->client, ['name' => 'Rosa', 'receives_summaries' => 1], [['email' => 'rosa@lanasnorte.example']]);
        $this->liz = Team::save(['name' => 'Liz', 'slug' => 'liz', 'workspace_email' => 'liz@imad.example', 'chat_dm_space' => 'spaces/LIZDM']);
    }

    public function tear_down(){
        remove_all_filters('madoficina_chat_certs');
        remove_all_filters('madoficina_chat_assistant_mode');
        remove_filter('pre_http_request', [$this, 'fake_google']);
        parent::tear_down();
    }

    public function fake_google($pre, $args, $url){
        if ( $url === 'https://oauth2.googleapis.com/token' ) {
            $this->assertStringContainsString('jwt-bearer', $args['body']['grant_type']);
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 'bot-token', 'expires_in' => 3600])];
        }
        if ( strpos($url, 'https://chat.googleapis.com/v1/media/') === 0 ) {
            if ( strpos($url, 'TRANSCRIPT') !== false ) return ['response' => ['code' => 200], 'headers' => ['content-type' => 'text/plain'], 'body' => 'La de los anuncios ya está hecha'];
            return ['response' => ['code' => 200], 'headers' => ['content-type' => 'application/octet-stream'], 'body' => $this->audio_bytes];
        }
        if ( preg_match('#^https://(?:([a-z0-9-]+)-)?speech\.googleapis\.com/v2/projects/mad-oficina-test/locations/([a-z0-9-]+)/recognizers/_:recognize#', $url, $m) ) {
            $this->speech_calls++;
            $body = json_decode($args['body'], true);
            $sent_audio = base64_decode($body['content']);
            if ( substr($sent_audio, 0, 4) !== 'OggS' && strlen($sent_audio) > strlen($this->audio_bytes) / 2 ) $this->assertSame(strlen($this->audio_bytes), strlen($sent_audio), 'same bytes (maybe reordered)');
            $this->sent_audio[] = $sent_audio;
            $this->speech_configs[] = $body['config'];
            $this->assertSame($m[1] ?: 'global', $m[2], 'regional models go to their regional endpoint');
            $this->speech_models[] = $body['config']['model'];
            [$code, $out] = $this->speech_by_model[$body['config']['model']] ?? $this->speech ?? [200, ['results' => [['alternatives' => [['transcript' => 'ya he subido los anuncios']]]]]];
            return ['response' => ['code' => $code], 'body' => wp_json_encode($out)];
        }
        if ( $url === 'https://api.anthropic.com/v1/messages' ) {
            $body = json_decode($args['body'], true);
            $this->prompts[] = $body;
            $next = array_shift($this->claude);
            if ( $next === null ) return ['response' => ['code' => 500], 'body' => '{}'];
            $tool = isset($next['tool']);
            $content = $tool ? [['type' => 'tool_use', 'id' => 'tu_' . count($this->prompts), 'name' => $next['tool'], 'input' => $next['input'] ?? new stdClass()]] : [['type' => 'text', 'text' => $next['text']]];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['model' => $body['model'], 'stop_reason' => $tool ? 'tool_use' : 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'content' => $content])];
        }
        if ( strpos($url, 'https://chat.googleapis.com/v1/') === 0 ) {
            $this->sent[] = ['url' => $url, 'method' => $args['method'] ?? 'POST', 'auth' => $args['headers']['Authorization'] ?? '', 'body' => json_decode($args['body'] ?? '{}', true)];
            $space = preg_match('#/v1/(spaces/[A-Za-z0-9_-]+)#', $url, $m) ? $m[1] : 'spaces/X';
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['name' => $space . '/messages/' . count($this->sent)])];
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

    /* ───────── marking tasks done from Chat ───────── */

    private function say($text, $email = 'jessica@imad.example'){
        $space = $email === 'liz@imad.example' ? 'spaces/LIZDM' : 'spaces/JESSDM';
        return $this->event(['type' => 'MESSAGE', 'user' => ['email' => $email], 'space' => ['name' => $space, 'type' => 'DM'],
            'message' => ['text' => $text, 'argumentText' => $text]])->get_data();
    }

    private function status($id){
        return $GLOBALS['wpdb']->get_var('SELECT status FROM ' . Schema::table('action_items') . ' WHERE id = ' . (int) $id);
    }

    public function test_words_are_read_without_ai(){
        $p = fn($t) => TaskList::parse($t);
        $this->assertSame(['action' => 'done', 'nums' => [2, 5]], $p('La 2 y la 5 están hechas'));
        $this->assertSame(['action' => 'done', 'nums' => [1, 2, 3]], $p('1-3 listas'));
        $this->assertSame(['action' => 'done', 'nums' => [1, 4]], $p('1, 4'));
        $this->assertSame(['action' => 'done', 'nums' => 'all'], $p('Todas hechas!'));
        $this->assertSame(['action' => 'undo', 'nums' => [3]], $p('deshacer la 3'));
        $this->assertSame('list', $p('¿qué tareas tengo?')['action']);
        $this->assertSame('list', $p('mis tareas')['action']);
        $this->assertNull($p('quita las tareas duplicadas de Lanas')['action'], 'a sentence about tasks goes to the assistant');
        $this->assertNull($p('¿qué tareas tiene Liz esta semana?')['action']);
        $this->assertSame('ask', $p('ya está hecho')['action']);
        $this->assertNull($p('hola, ¿qué tal?')['action']);
        $this->assertNull($p('ok, te escribo a las 5')['action']);
        $this->assertNull($p('mándame la factura de los 2 meses')['action'], 'numbers in a sentence are not an answer');
    }

    public function test_jessica_marks_tasks_done_by_number_from_her_last_list(){
        $a = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Subir anuncios', 'owner' => 'jessica', 'status' => 'open', 'due_date' => '2026-10-10']);
        $b = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Revisar textos', 'owner' => 'mad', 'status' => 'in_progress', 'due_date' => '2026-10-11']);
        $c = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Llamar a Rosa', 'owner' => 'jessica', 'status' => 'open', 'due_date' => '2026-10-12']);
        $lizs = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Montar vídeo', 'owner' => 'liz', 'team_member_id' => $this->liz, 'status' => 'open']);

        $list = wp_json_encode($this->say('tareas'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('1 · LN', $list);
        $this->assertStringNotContainsString('Montar vídeo', $list, "Liz's tasks are not in Jessica's list");

        $res = wp_json_encode($this->say('la 1 y la 3 hechas, y la 9'), JSON_UNESCAPED_UNICODE);
        $this->assertSame(['done', 'in_progress', 'done', 'open'], [$this->status($a), $this->status($b), $this->status($c), $this->status($lizs)]);
        $this->assertStringContainsString('Marcadas como hechas', $res);
        $this->assertStringContainsString('No hay número 9', $res);
        $this->assertStringContainsString('task_undo', $res);

        // «Deshacer» restores how it was.
        $this->click('task_undo', ['prev' => "{$a}:open,{$c}:open"], 'jessica@imad.example');
        $this->assertSame(['open', 'open'], [$this->status($a), $this->status($c)]);

        $this->say('todas hechas');
        $this->assertSame(['done', 'done', 'done'], [$this->status($a), $this->status($b), $this->status($c)]);
        $this->say('deshacer la 2');
        $this->assertSame('open', $this->status($b));
    }

    public function test_liz_only_touches_her_own_list_and_tasks(){
        $hers = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Montar vídeo', 'owner' => 'liz', 'team_member_id' => $this->liz, 'status' => 'open']);
        $jess = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Margen', 'owner' => 'jessica', 'status' => 'open']);
        $this->say('tareas', 'liz@imad.example');
        $this->say('todas hechas', 'liz@imad.example');
        $this->assertSame(['done', 'open'], [$this->status($hers), $this->status($jess)]);

        // A forged click on someone else's task from a list does nothing.
        $res = $this->click('task_list_done', ['task_id' => (string) $jess, 'ids' => "{$hers},{$jess}"], 'liz@imad.example');
        $this->assertStringContainsString('no es tuya', $res->get_data()['text']);
        $this->assertSame('open', $this->status($jess));
        // And a stranger cannot mark anything by words.
        TaskList::remember('rosa@lanasnorte.example', [$jess]);
        $this->say('todas hechas', 'rosa@lanasnorte.example');
        $this->assertSame('open', $this->status($jess));
    }

    public function test_the_hecha_button_redraws_the_same_numbered_list(){
        $a = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Primera', 'owner' => 'jessica', 'status' => 'open']);
        $b = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Segunda', 'owner' => 'jessica', 'status' => 'open']);
        $res = $this->click('task_list_done', ['task_id' => (string) $b, 'ids' => "{$a},{$b}", 'title' => 'Tus tareas'], 'jessica@imad.example')->get_data();
        $this->assertSame('UPDATE_MESSAGE', $res['actionResponse']['type']);
        $json = wp_json_encode($res, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('2 · LN · HECHA', $json);
        $this->assertStringContainsString('1 · LN', $json);
        $this->assertSame('done', $this->status($b));
        $this->click('task_list_undo', ['task_id' => (string) $b, 'ids' => "{$a},{$b}"], 'jessica@imad.example');
        $this->assertSame('open', $this->status($b));
    }

    /* ───────── her assistant and the area agents ───────── */

    private function key(){
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
    }

    /** Everything the bot wrote in Jessica's DM, in order (created and edited). */
    private function to_jessica(){
        return array_values(array_filter($this->sent, fn($s) => strpos($s['url'], 'spaces/JESSDM/messages') !== false));
    }

    private function last_to_jessica(){
        $all = $this->to_jessica();
        return wp_json_encode(end($all)['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function tool_names(array $prompt){ return array_column($prompt['tools'] ?? [], 'name'); }

    /** What a tool gave back to Claude in a given request (the last tool_result of that prompt). */
    private function tool_result(array $prompt){
        $last = end($prompt['messages']);
        return is_array($last['content']) ? (string) end($last['content'])['content'] : '';
    }

    public function test_jessica_speaks_naturally_and_the_projects_agent_changes_only_what_it_may(){
        $this->key();
        $a = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Subir anuncios', 'owner' => 'jessica', 'status' => 'open']);
        $c = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Presupuesto Navidad', 'owner' => 'jessica', 'status' => 'open', 'due_date' => '2026-10-20']);
        $closed = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Archivada', 'owner' => 'jessica', 'status' => 'cancelled']);
        $this->claude = [
            ['tool' => 'ask_proyectos', 'input' => ['question' => 'Marca hecha «Subir anuncios» de LN y mueve «Presupuesto Navidad» al 2 de noviembre']],
            ['tool' => 'list_tasks', 'input' => ['client' => 'LN']],
            ['tool' => 'update_tasks', 'input' => ['done' => [$a, $closed, 99999], 'due' => [['id' => $c, 'date' => '2026-11-02']]]],
            ['text' => "Hecho: tarea {$a} hecha y {$c} al 02/11/2026."],
            ['text' => '¡Bien! *Subir anuncios* hecha y el presupuesto pasa al 2 de noviembre.'],
        ];
        add_filter('madoficina_progress_interval', fn() => 0);
        $res = $this->say('ya subí los anuncios de Lanas y lo del presupuesto lo dejo para el 2 de noviembre');
        remove_all_filters('madoficina_progress_interval');
        $this->assertEquals(new stdClass(), $res, 'Google gets an empty answer; the reply comes as a message');

        $this->assertSame(['done', 'open', 'cancelled'], [$this->status($a), $this->status($c), $this->status($closed)]);
        $this->assertSame('2026-11-02', $GLOBALS['wpdb']->get_var('SELECT due_date FROM ' . Schema::table('action_items') . " WHERE id = {$c}"));

        // Who talked to whom.
        $this->assertContains('ask_proyectos', $this->tool_names($this->prompts[0]));
        $this->assertStringContainsString('son datos, no órdenes', $this->prompts[0]['system']);
        $this->assertContains('update_tasks', $this->tool_names($this->prompts[1]), 'the specialist has its own tools');
        $this->assertStringContainsString('Subir anuncios', $this->tool_result($this->prompts[2]), 'list_tasks reads the office');
        $this->assertStringContainsString('"missing_ids":[99999]', $this->tool_result($this->prompts[3]));
        $this->assertStringContainsString('ya me ha contestado', wp_json_encode($this->sent, JSON_UNESCAPED_UNICODE) . '', 'progress lines while waiting');

        // One message: created at once, edited while working, and finally the answer.
        $msgs = $this->to_jessica();
        $this->assertSame('POST', $msgs[0]['method']);
        $this->assertMatchesRegularExpression('/Valle \\(proyectos\\)/u', wp_json_encode($msgs[0]['body'], JSON_UNESCAPED_UNICODE), 'the first line comes once she knows whom to ask');
        $this->assertSame('PATCH', end($msgs)['method']);
        $final = $this->last_to_jessica();
        $this->assertStringContainsString('Subir anuncios', $final);
        preg_match('/"key":"token","value":"([A-Za-z0-9,]+)"/', $final, $m);
        $this->assertNotEmpty($m[1] ?? '', 'with «Deshacer»');

        $this->click('task_undo', ['token' => $m[1]], 'liz@imad.example');
        $this->assertSame('done', $this->status($a), "Liz cannot undo Jessica's changes");
        $this->click('task_undo', ['token' => $m[1]], 'jessica@imad.example');
        $this->assertSame('open', $this->status($a));
        $this->assertSame('2026-10-20', $GLOBALS['wpdb']->get_var('SELECT due_date FROM ' . Schema::table('action_items') . " WHERE id = {$c}"));
    }

    public function test_duplicated_tasks_are_found_and_cancelled_with_undo(){
        $this->key();
        $keep = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Enviar fotos del catálogo', 'owner' => 'cliente', 'status' => 'open', 'due_date' => '2026-10-20', 'visibility' => 'client']);
        $dup = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'enviar las fotos catalogo', 'owner' => 'cliente', 'status' => 'open']);
        $other = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Revisar textos', 'owner' => 'jessica', 'status' => 'open']);
        $this->claude = [
            ['tool' => 'ask_proyectos', 'input' => ['question' => 'Quita las tareas duplicadas de LN']],
            ['tool' => 'find_duplicates', 'input' => ['client' => 'LN']],
            ['tool' => 'update_tasks', 'input' => ['cancel' => [$dup]]],
            ['text' => "Anulada la {$dup}, duplicada de la {$keep}."],
            ['text' => 'Listo: quité la repetida de las fotos.'],
        ];
        $this->say('quita las tareas duplicadas de Lanas');
        $groups = json_decode($this->tool_result($this->prompts[2]), true)['groups'];
        $this->assertCount(1, $groups, 'only the photos pair');
        $this->assertEqualsCanonicalizing([$keep, $dup], array_column($groups[0], 'id'));
        $this->assertSame(['open', 'cancelled', 'open'], [$this->status($keep), $this->status($dup), $this->status($other)]);

        preg_match('/"key":"token","value":"([A-Za-z0-9,]+)"/', $this->last_to_jessica(), $m);
        $this->click('task_undo', ['token' => $m[1]], 'jessica@imad.example');
        $this->assertSame('open', $this->status($dup), '«Deshacer» brings it back');
    }

    public function test_for_tasks_valle_reads_the_minutes_herself_in_one_answer(){
        $this->key();
        $meeting = Meetings::upsert(['source' => 'manual', 'title' => 'LN x MAD', 'starts_at' => '2026-10-07 17:00', 'transcript' => 'x', 'client_id' => $this->client]);
        Meetings::update($meeting, ['status' => 'approved', 'summary' => 'Catálogo de invierno', 'extraction' => ['summary_internal' => 'Catálogo de invierno', 'client_decisions' => ['Fotos por colores']]]);
        $t = $this->insert('action_items', ['client_id' => $this->client, 'meeting_id' => $meeting, 'title' => 'Enviar fotos', 'owner' => 'cliente', 'status' => 'open', 'evidence' => 'Os mando las fotos el lunes']);
        $this->claude = [
            ['tool' => 'ask_proyectos', 'input' => ['question' => '¿De dónde sale la tarea de las fotos de LN y qué se acordó?']],
            ['tool' => 'meeting_context', 'input' => ['task_id' => $t]],
            ['text' => 'Salió de la reunión del 7/10: «Os mando las fotos el lunes». Acuerdo: fotos por colores.'],
            ['text' => 'Salió de la reunión del 7 de octubre: Rosa dijo «Os mando las fotos el lunes».'],
        ];
        $this->say('¿de dónde salió lo de las fotos de Lanas?');
        $ctx = $this->tool_result($this->prompts[2]);
        $this->assertStringContainsString('Os mando las fotos el lunes', $ctx);
        $this->assertStringContainsString('Fotos por colores', $ctx);
        $this->assertCount(4, $this->prompts, 'one area, one answer: Actas was not asked');
        $this->assertStringContainsString('SOLO a Proyectos', $this->prompts[0]['system']);
        $this->assertContains('meeting_context', $this->tool_names($this->prompts[1]));
    }

    public function test_finanzas_finds_the_last_invoice_with_its_pdf(){
        $this->key();
        $wp = $this->factory()->user->create(['role' => 'administrator', 'user_email' => 'jessica@imad.example']);
        get_user_by('id', $wp)->add_cap(MAD_Oficina_Core::CAPABILITY);
        $base = ['series' => 'F', 'year' => 2026, 'client_id' => $this->client, 'client_name' => 'Lanas del Norte SL', 'status' => 'sent', 'base' => 100, 'total' => 121, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
        $GLOBALS['wpdb']->insert(Schema::table('invoices'), $base + ['number' => 'F-2026-010', 'seq' => 10, 'issue_date' => '2026-09-28', 'pdf_url' => 'https://drive.google.com/file/d/OLD/view']);
        $GLOBALS['wpdb']->insert(Schema::table('invoices'), $base + ['number' => 'F-2026-014', 'seq' => 14, 'issue_date' => '2026-10-28', 'pdf_url' => 'https://drive.google.com/file/d/NEW/view']);
        $this->claude = [
            ['tool' => 'ask_finanzas', 'input' => ['question' => 'Última factura emitida a LN, con PDF']],
            ['tool' => 'find_invoices', 'input' => ['client' => 'LN', 'limit' => 1]],
            ['text' => 'F-2026-014 · 28/10/2026 · 121,00 € · no cobrada · https://drive.google.com/file/d/NEW/view'],
            ['text' => 'Aquí la tienes: *F-2026-014* (28/10/2026, 121,00 €), aún sin cobrar. https://drive.google.com/file/d/NEW/view'],
        ];
        $this->say('envíame la última factura que le pasamos a Lanas');
        $found = $this->tool_result($this->prompts[2]);
        $this->assertStringContainsString('F-2026-014', $found);
        $this->assertStringNotContainsString('F-2026-010', $found, 'the most recent only');
        $this->assertStringContainsString('drive.google.com/file/d/NEW', $this->last_to_jessica());
        $this->assertContains('ask_finanzas', $this->tool_names($this->prompts[0]), 'Jessica reaches Finanzas');
        $this->assertNotContains('issue_invoice', $this->tool_names($this->prompts[1]), 'Finanzas never issues or sends');
        $this->assertNotContains('send_invoice', $this->tool_names($this->prompts[1]));
    }

    public function test_amparo_changes_finanzas_when_asked_and_jessica_can_undo(){
        $this->key();
        $wp = $this->factory()->user->create(['role' => 'administrator', 'user_email' => 'jessica@imad.example']);
        get_user_by('id', $wp)->add_cap(MAD_Oficina_Core::CAPABILITY);
        $GLOBALS['wpdb']->insert(Schema::table('invoices'), ['series' => 'F', 'number' => 'F-2026-020', 'seq' => 20, 'year' => 2026, 'issue_date' => '2026-10-01', 'client_id' => $this->client, 'client_name' => 'Lanas del Norte SL',
            'status' => 'sent', 'base' => 100, 'total' => 121, 'paid' => 0, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        $inv = (int) $GLOBALS['wpdb']->insert_id;
        $this->claude = [
            ['tool' => 'ask_finanzas', 'input' => ['question' => 'Marca como cobrada hoy la F-2026-020']],
            ['tool' => 'set_invoice_paid', 'input' => ['invoice_id' => $inv, 'paid' => true, 'date' => '2026-10-16']],
            ['text' => 'F-2026-020 marcada como cobrada el 16/10/2026.'],
            ['text' => 'Hecho: la F-2026-020 ya está cobrada.'],
        ];
        $this->say('marca como cobrada la última de Lanas');
        $this->assertSame(1, (int) \MAD_Oficina\Billing\Invoices::get($inv)['paid']);
        preg_match('/"key":"token","value":"(f:[A-Za-z0-9]+)"/', $this->last_to_jessica(), $m);
        $this->assertNotEmpty($m[1] ?? '', 'with «Deshacer»');
        $this->assertStringContainsString('solo lo puede deshacer Jessica', $this->click('task_undo', ['token' => $m[1]], 'liz@imad.example')->get_data()['text']);
        $card = wp_json_encode($this->click('task_undo', ['token' => $m[1]], 'jessica@imad.example')->get_data());
        $this->assertSame(0, (int) \MAD_Oficina\Billing\Invoices::get($inv)['paid']);
        $this->assertStringContainsString('Finanzas: 1 cambio deshecho', $card);
        $h = \MAD_Oficina\Chat\Memory::history('jessica@imad.example');
        $this->assertStringContainsString('Deshecho, ya no existe', end($h)['text'], 'Nora knows it was undone');
    }

    public function test_finanzas_says_when_a_client_has_no_fiscal_data_joined(){
        $this->key();
        $wp = $this->factory()->user->create(['role' => 'administrator', 'user_email' => 'jessica@imad.example']);
        get_user_by('id', $wp)->add_cap(MAD_Oficina_Core::CAPABILITY);
        $GLOBALS['wpdb']->insert(Schema::table('invoices'), ['series' => 'F', 'year' => 2026, 'client_id' => null, 'client_name' => 'Lanas del Norte SL', 'status' => 'sent', 'base' => 100, 'total' => 121,
            'number' => 'F-2026-030', 'seq' => 30, 'issue_date' => '2026-10-02', 'created_at' => '2026-10-02 00:00:00', 'updated_at' => '2026-10-02 00:00:00']);
        $this->claude = [
            ['tool' => 'ask_finanzas', 'input' => ['question' => 'Última factura de LN']],
            ['tool' => 'find_invoices', 'input' => ['client' => 'LN', 'limit' => 1]],
            ['text' => 'F-2026-030'],
            ['text' => 'La F-2026-030.'],
        ];
        $this->say('¿cuál es la última factura de Lanas?');
        $found = $this->tool_result($this->prompts[2]);
        $this->assertStringContainsString('F-2026-030', $found, 'found by the fiscal name');
        $this->assertStringContainsString('Unir', $found, 'and says how to join it');
    }

    public function test_jessica_hands_tasks_to_liz_and_decides_when_she_is_told(){
        $this->key();
        $t = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Montar vídeo de bufandas', 'owner' => 'jessica', 'status' => 'open', 'visibility' => 'internal']);
        $this->claude = [
            ['tool' => 'ask_proyectos', 'input' => ['question' => 'Asigna a Liz el vídeo de bufandas y crea para ella «Retocar fotos» para el viernes']],
            ['tool' => 'update_tasks', 'input' => ['assign' => [['id' => $t, 'to' => 'Liz']]]],
            ['tool' => 'create_task', 'input' => ['title' => 'Retocar fotos', 'client' => 'LN', 'owner' => 'liz', 'due_date' => '2026-10-23']],
            ['text' => 'Hecho: dos tareas para Liz.'],
            ['text' => 'Listo, Liz tiene el vídeo y el retoque de fotos.'],
        ];
        $this->say('el vídeo de bufandas es de Liz, y que retoque las fotos para el viernes');
        $row = $GLOBALS['wpdb']->get_row('SELECT owner, team_member_id, visibility FROM ' . Schema::table('action_items') . " WHERE id = {$t}", ARRAY_A);
        $this->assertSame(['liz', (string) $this->liz, 'team'], [$row['owner'], $row['team_member_id'], $row['visibility']], 'hers now, and she can see it');
        $new = (int) $GLOBALS['wpdb']->get_var('SELECT id FROM ' . Schema::table('action_items') . " WHERE title = 'Retocar fotos'");
        $this->assertSame((string) $this->liz, $GLOBALS['wpdb']->get_var('SELECT team_member_id FROM ' . Schema::table('action_items') . " WHERE id = {$new}"));
        $this->assertSame([], array_filter($this->sent, fn($x) => strpos($x['url'], 'spaces/LIZDM') !== false), 'Liz is not told until Jessica says so');

        $final = $this->last_to_jessica();
        $this->assertStringContainsString('Avisar a Liz', $final);
        preg_match('/"key":"ids","value":"([\d,]+)"/', $final, $ids);
        $this->assertStringContainsString('Solo Jessica', $this->click('notify_member', ['member_id' => (string) $this->liz, 'ids' => $ids[1]], 'liz@imad.example')->get_data()['text']);
        $this->click('notify_member', ['member_id' => (string) $this->liz, 'ids' => $ids[1]], 'jessica@imad.example');
        $to_liz = array_values(array_filter($this->sent, fn($x) => strpos($x['url'], 'spaces/LIZDM') !== false));
        $this->assertCount(2, $to_liz, 'one card per task, in her DM');
        $this->assertStringContainsString('task_done', wp_json_encode($to_liz[0]['body']));

        preg_match('/"key":"token","value":"([A-Za-z0-9,]+)"/', $final, $m);
        $this->click('task_undo', ['token' => $m[1]], 'jessica@imad.example');
        $row = $GLOBALS['wpdb']->get_row('SELECT owner, team_member_id, visibility FROM ' . Schema::table('action_items') . " WHERE id = {$t}", ARRAY_A);
        $this->assertSame(['jessica', null, 'internal'], [$row['owner'], $row['team_member_id'], $row['visibility']], '«Deshacer» gives it back');
    }

    public function test_liz_only_reaches_her_own_tasks(){
        $this->key();
        $hers = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Montar vídeo', 'owner' => 'liz', 'team_member_id' => $this->liz, 'status' => 'open']);
        $jess = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Margen', 'owner' => 'jessica', 'status' => 'open']);
        $this->claude = [
            ['tool' => 'ask_proyectos', 'input' => ['question' => 'tareas abiertas y marca hechas el vídeo y el margen']],
            ['tool' => 'list_tasks', 'input' => []],
            ['tool' => 'update_tasks', 'input' => ['done' => [$hers, $jess]]],
            ['text' => 'Hecha la del vídeo; el margen no es suyo.'],
            ['text' => '¡Vídeo hecho!'],
        ];
        $this->say('he terminado el vídeo y marca también lo del margen', 'liz@imad.example');
        $this->assertSame(['ask_proyectos', 'remember', 'forget'], $this->tool_names($this->prompts[0]), 'no Finanzas, no Actas for the team');
        $this->assertStringNotContainsString('Margen', $this->tool_result($this->prompts[2]), "Jessica's tasks are not even listed");
        $this->assertSame(['done', 'open'], [$this->status($hers), $this->status($jess)]);
        $this->assertStringContainsString('not_allowed', $this->tool_result($this->prompts[3]));
        $this->assertNotEmpty(array_filter($this->sent, fn($s) => strpos($s['url'], 'spaces/LIZDM/messages') !== false));
    }

    public function test_finanzas_is_only_for_who_sees_finanzas(){
        $this->key();
        $jess = $this->factory()->user->create(['role' => 'administrator', 'user_email' => 'jessica@imad.example']);
        $other = $this->factory()->user->create(['role' => 'administrator']);
        foreach ( [$jess, $other] as $u ) get_user_by('id', $u)->add_cap(MAD_Oficina_Core::CAPABILITY);
        update_option(\MAD_Oficina\Core\FinanceAccess::OPTION, [$other]); // reserved to someone else
        $this->claude = [['text' => 'Hola.']];
        $this->say('hola');
        $this->assertNotContains('ask_finanzas', $this->tool_names($this->prompts[0]));
        delete_option(\MAD_Oficina\Core\FinanceAccess::OPTION);
    }

    public function test_amparo_is_reachable_even_if_jessicas_wordpress_email_is_another(){
        $this->key();
        $jess = $this->factory()->user->create(['role' => 'administrator', 'user_email' => 'otra-direccion@personal.example']);
        get_user_by('id', $jess)->add_cap(MAD_Oficina_Core::CAPABILITY);
        update_option(\MAD_Oficina\Core\FinanceAccess::OPTION, [$jess]); // she reserved Finanzas to herself
        $this->claude = [['text' => 'Hola.']];
        $this->say('pregúntale a Amparo por la última factura');
        $this->assertContains('ask_finanzas', $this->tool_names($this->prompts[0]));
        $this->assertStringContainsString('Amparo', $this->prompts[0]['system']);
        delete_option(\MAD_Oficina\Core\FinanceAccess::OPTION);
    }

    public function test_an_area_she_cannot_reach_is_named_not_unknown(){
        $this->key();
        $this->claude = [['text' => 'Eso es de Jessica.']];
        $this->say('¿cuánto hemos facturado?', 'liz@imad.example');
        $this->assertNotContains('ask_finanzas', $this->tool_names($this->prompts[0]));
        $this->assertStringContainsString('Amparo (Finanzas): existe, pero esa área es solo de Jessica', $this->prompts[0]['system']);
    }

    public function test_she_has_a_map_of_whom_to_ask_and_knows_the_clients(){
        $this->key();
        $this->claude = [['text' => 'Hola.']];
        $this->say('hola');
        $sys = $this->prompts[0]['system'];
        $this->assertStringContainsString('A quién preguntar (elige por el TEMA', $sys);
        $this->assertStringContainsString('aunque la tarea saliera de una reunión', $sys, 'tasks from meetings go to Proyectos');
        $this->assertStringContainsString('LN · Lanas del Norte', $sys, 'client codes, to resolve names before asking');
        $this->assertStringContainsString('Todavía NO lo sabe nadie', $sys);
        $this->assertStringContainsString('Pregúntale para:', wp_json_encode($this->prompts[0]['tools'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('contestó ella sola', get_option(\MAD_Oficina\Chat\Assistant::STATUS)['answered']['detail']);
    }

    public function test_she_does_not_ask_an_area_over_and_over_nor_repeat_herself(){
        $this->key();
        $this->claude = [
            ['tool' => 'ask_proyectos', 'input' => ['question' => '¿Qué tareas abiertas hay?']],
            ['text' => 'No hay tareas abiertas.'],                                               // Valle, 1st
            ['tool' => 'ask_proyectos', 'input' => ['question' => '¿Qué tareas abiertas hay?']],   // same again: no new call to Valle
            ['tool' => 'ask_proyectos', 'input' => ['question' => '¿Y de LN?']],
            ['text' => 'Tampoco.'],                                                              // Valle, 2nd
            ['tool' => 'ask_proyectos', 'input' => ['question' => '¿Seguro?']],                   // 3rd: refused
            ['text' => 'Nada abierto.'],
        ];
        $this->say('¿qué tengo pendiente?');
        $this->assertCount(7, $this->prompts, 'the repeated question did not reach Valle again');
        $this->assertStringContainsString('"repeated":true', $this->tool_result($this->prompts[3]));
        $this->assertStringContainsString('Ya has preguntado 2 veces', $this->tool_result($this->prompts[6]));
        $this->assertStringContainsString('di solo lo nuevo o lo que cambia', $this->prompts[0]['system']);
        $this->assertStringContainsString('no se repite ni se reformula', $this->prompts[0]['system']);
    }

    public function test_she_has_a_name_a_personality_and_remembers(){
        $this->key();
        $settings = get_option(MAD_Oficina_Core::option_key('chat'));
        update_option(MAD_Oficina_Core::option_key('chat'), $settings + ['assistant_name' => 'Greta', 'assistant_personality' => 'Seca y eficaz.']);
        $this->claude = [
            ['tool' => 'remember', 'input' => ['fact' => 'A LN lo llama «las lanas»']],
            ['text' => 'Apuntado.'],
            ['text' => 'Claro, las lanas.'],
        ];
        $this->say('recuerda que a LN lo llamo las lanas');
        $this->assertStringContainsString('Eres Greta', $this->prompts[0]['system']);
        $this->assertStringContainsString('Seca y eficaz.', $this->prompts[0]['system']);
        $this->assertSame('A LN lo llama «las lanas»', \MAD_Oficina\Chat\Memory::facts('jessica@imad.example')[0]['text']);

        $this->say('¿cómo llamo a LN?');
        $second = $this->prompts[2];
        $this->assertStringContainsString('las lanas', $second['system'], 'what she asked to remember');
        $this->assertSame('recuerda que a LN lo llamo las lanas', $second['messages'][0]['content'], 'and the conversation so far');
        $this->assertSame('assistant', $second['messages'][1]['role']);
        delete_option(\MAD_Oficina\Chat\Memory::FACTS); delete_option(\MAD_Oficina\Chat\Memory::HISTORY);
    }

    public function test_without_php_fpm_the_answer_still_comes_in_the_same_request(){
        $this->key();
        remove_all_filters('madoficina_chat_assistant_mode'); // CLI: no fastcgi/litespeed finisher
        $this->assertNull(\MAD_Oficina\Chat\Assistant::finisher());
        $this->claude = [['text' => '¡Hola, Jessica!']];
        $this->say('hola');
        $this->assertStringContainsString('¡Hola, Jessica!', $this->last_to_jessica(), 'never left in a queue that may not run');
        $this->assertCount(1, $this->to_jessica(), 'nothing to wait for: no «un momento», just the answer');
        $this->assertSame('POST', $this->to_jessica()[0]['method']);
        $st = get_option(\MAD_Oficina\Chat\Assistant::STATUS);
        $this->assertArrayHasKey('received', $st);
        $this->assertArrayHasKey('answered', $st);
    }

    private function voice(array $attachments){
        return $this->event(['type' => 'MESSAGE', 'user' => ['email' => 'jessica@imad.example'], 'space' => ['name' => 'spaces/JESSDM', 'type' => 'DM'],
            'message' => ['text' => '', 'attachment' => $attachments]])->get_data();
    }

    public function test_an_invoice_sent_to_nora_is_registered_by_amparo_as_approved(){
        $this->key();
        $wp = $this->factory()->user->create(['role' => 'administrator', 'user_email' => 'jessica@imad.example']);
        get_user_by('id', $wp)->add_cap(MAD_Oficina_Core::CAPABILITY);
        $this->audio_bytes = '%PDF-1.4 factura de prueba ' . wp_generate_password(8, false);
        $read = ['is_invoice' => true, 'doc_type' => 'invoice', 'supplier_name' => 'Nube SL', 'supplier_tax_id' => 'B99999999', 'recipient_name' => '', 'recipient_tax_id' => '', 'recipient_email' => '',
            'number' => 'N-77', 'issue_date' => '2026-10-03', 'currency' => 'EUR', 'base' => 10.0, 'vat' => 2.1, 'irpf' => 0, 'total' => 12.1, 'concept' => 'Hosting octubre', 'category' => 'hosting', 'periodicity' => 'monthly', 'confidence' => 'high'];
        $this->claude = [
            ['tool' => 'ask_finanzas', 'input' => ['question' => 'Registra la factura adjunta']],
            ['tool' => 'register_attached_invoices', 'input' => []],
            ['text' => wp_json_encode($read)], // the Gastos reader
            ['text' => 'Registrada: Nube SL, 03/10/2026, 12,10 €.'],
            ['text' => 'Hecho: la de Nube SL (12,10 €) ya está en Gastos.'],
        ];
        $this->event(['type' => 'MESSAGE', 'user' => ['email' => 'jessica@imad.example'], 'space' => ['name' => 'spaces/JESSDM', 'type' => 'DM'],
            'message' => ['text' => 'pásale esta a Amparo', 'argumentText' => 'pásale esta a Amparo', 'attachment' => [['contentName' => 'nube.pdf', 'contentType' => 'application/pdf', 'attachmentDataRef' => ['resourceName' => 'PDF-REF']]]]]);

        $this->assertStringContainsString('ask_finanzas', $this->prompts[0]['system'], 'Nora knows the files go to Finanzas');
        $this->assertStringContainsString('nube.pdf · pdf', $this->prompts[0]['messages'][count($this->prompts[0]['messages']) - 1]['content']);
        $e = \MAD_Oficina\Suppliers\Expenses::all(['status' => 'registered']);
        $this->assertCount(1, $e, 'she sent it: approved, not left «por revisar»');
        $this->assertSame(['Nube SL', 'upload'], [$e[0]['supplier_name'], $e[0]['source']]);
        $this->assertNotNull(\MAD_Oficina\Suppliers\Suppliers::match('B99999999', '', ''), 'the new supplier is created with what was read');
        $this->assertStringContainsString('registrada', $this->tool_result($this->prompts[3]));
        $this->assertStringContainsString('Nube SL', $this->last_to_jessica());
    }

    public function test_voice_messages_are_transcribed_and_understood(){
        $this->key();
        $this->claude = [['text' => 'Genial, me lo apunto.']];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'source' => 'UPLOADED_CONTENT', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertSame(1, $this->speech_calls);
        $this->assertSame('ya he subido los anuncios', end($this->prompts[0]['messages'])['content']);
        $this->assertStringContainsString('He oído', $this->last_to_jessica());
        $shape = get_option(\MAD_Oficina\Chat\Transcriber::SHAPE_OPTION);
        $this->assertSame('audio/mp4', $shape['message']['attachment'][0]['contentType'], 'we keep what Google sends (no content)');
        $this->assertSame('string', $shape['message']['attachment'][0]['contentName']);
    }

    public function test_googles_own_transcript_is_used_when_it_comes(){
        $this->key();
        $this->claude = [['text' => 'Vale.']];
        $this->voice([
            ['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']],
            ['contentName' => 'transcript.txt', 'contentType' => 'text/plain', 'attachmentDataRef' => ['resourceName' => 'TRANSCRIPT-REF']],
        ]);
        $this->assertSame(0, $this->speech_calls, 'no need to transcribe');
        $this->assertSame('La de los anuncios ya está hecha', end($this->prompts[0]['messages'])['content']);
    }

    public function test_voice_without_speech_api_explains_what_to_enable(){
        $this->speech = [403, ['error' => ['message' => 'Cloud Speech-to-Text API has not been used in project']]];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $msg = $this->last_to_jessica();
        $this->assertStringContainsString('Cloud Speech-to-Text API', $msg);
        $this->assertStringContainsString('mad-oficina-test', $msg);
        $this->assertSame([], $this->prompts);
    }

    public function test_when_google_refuses_the_audio_other_models_are_tried_and_the_one_that_works_is_remembered(){
        $this->key();
        $this->claude = [['text' => 'Hecho.'], ['text' => 'Vale.']];
        add_filter('madoficina_ffmpeg', fn() => '/nonexistent/ffmpeg');
        $bad = [400, ['error' => ['code' => 400, 'message' => 'Audio encoding not supported for this model.', 'status' => 'INVALID_ARGUMENT']]];
        $this->speech_by_model = ['long' => $bad, 'short' => $bad];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertSame(['long', 'short', 'chirp_2'], $this->speech_models);
        $this->assertSame('ya he subido los anuncios', end($this->prompts[0]['messages'])['content']);
        $this->assertSame('chirp_2', get_option(\MAD_Oficina\Chat\Transcriber::WORKED));
        $this->speech_models = [];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertSame(['chirp_2'], $this->speech_models, 'next time it goes straight to the one that worked');

        // None takes it: she reads what Google says, and the detail stays in the settings.
        $this->speech_by_model = ['long' => $bad, 'short' => $bad, 'chirp_2' => $bad];
        delete_option(\MAD_Oficina\Chat\Transcriber::WORKED);
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $msg = $this->last_to_jessica();
        $this->assertStringContainsString('Audio encoding not supported', $msg);
        $last = get_option(\MAD_Oficina\Chat\Transcriber::LAST);
        $this->assertFalse($last['ok']);
        $this->assertCount(3, $last['tried']);
        $this->assertFileExists(wp_upload_dir()['basedir'] . '/mad-oficina/voice/' . $last['kept'], 'the audio is kept to look at it');
        $this->assertStringContainsString('application/octet-stream', $last['audio'], 'what Chat said it was, and what it really is');
        remove_all_filters('madoficina_ffmpeg');
    }

    public function test_chats_m4a_voice_notes_are_sent_as_aac_with_their_rate(){
        $this->key();
        $this->claude = [['text' => 'Vale.']];
        // An invented m4a: «ftyp» box, then an «mp4a» sample entry (1 channel, 48 kHz).
        $this->audio_bytes = "\0\0\0\x18ftypM4A \0\0\0\0M4A isom" . str_repeat("\0", 20) . 'mp4a' . str_repeat("\0", 6) . "\0\x01" . str_repeat("\0", 8) . "\0\x01\0\x10\0\0\0\0" . pack('N', 48000 << 16) . 'aac-frames';
        $this->speech_by_model = [];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertSame(['encoding' => 'M4A_AAC', 'sampleRateHertz' => 48000, 'audioChannelCount' => 1], $this->speech_configs[0]['explicitDecodingConfig']);
        $this->assertArrayNotHasKey('autoDecodingConfig', $this->speech_configs[0]);
        $this->assertSame('ya he subido los anuncios', end($this->prompts[0]['messages'])['content']);
        $this->assertSame('long_m4a', get_option(\MAD_Oficina\Chat\Transcriber::WORKED));
        $this->audio_bytes = 'fake-audio-bytes';
    }

    public function test_opus_voice_notes_in_an_m4a_box_are_rewrapped_as_ogg(){
        $this->key();
        $this->claude = [['text' => 'Vale.']];
        $this->audio_bytes = file_get_contents(dirname(__DIR__) . '/fixtures/voz-opus-sintetica.m4a'); // a 3 s synthetic tone
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertSame(['long'], $this->speech_models);
        $this->assertSame('OggS', substr($this->sent_audio[0], 0, 4), 'Google gets Ogg Opus, not the m4a');
        $this->assertStringContainsString('OpusHead', substr($this->sent_audio[0], 0, 80));
        $this->assertArrayHasKey('autoDecodingConfig', $this->speech_configs[0]);
        $this->assertSame('long_ogg', get_option(\MAD_Oficina\Chat\Transcriber::WORKED));
        $this->assertStringContainsString('Opus, fragmentado', \MAD_Oficina\Chat\Transcriber::container($this->audio_bytes));
        $this->audio_bytes = 'fake-audio-bytes';
    }

    public function test_phone_m4a_with_the_index_at_the_end_is_sent_index_first(){
        $this->key();
        $this->claude = [['text' => 'Vale.']];
        $this->audio_bytes = file_get_contents(dirname(__DIR__) . '/fixtures/voz-aac-indice-al-final-sintetica.m4a'); // 4 s synthetic tone, «moov» after «mdat»
        $this->assertGreaterThan(strpos($this->audio_bytes, 'mdat'), strpos($this->audio_bytes, 'moov'));
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mpeg', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $sent = $this->sent_audio[0];
        $this->assertLessThan(strpos($sent, 'mdat'), strpos($sent, 'moov'), 'Google gets the index first');
        $this->assertNull((new \MAD_Oficina\Chat\Mp4Audio($sent))->faststart(), 'nothing left to move');
        $this->assertSame('ya he subido los anuncios', end($this->prompts[0]['messages'])['content']);
        $this->assertStringContainsString('índice movido', get_option(\MAD_Oficina\Chat\Transcriber::LAST)['audio']);
        $this->audio_bytes = 'fake-audio-bytes';
    }

    public function test_a_voice_note_longer_than_a_minute_goes_in_equal_pieces(){
        $this->key();
        $this->claude = [['text' => 'Vale.']];
        $this->audio_bytes = file_get_contents(dirname(__DIR__) . '/fixtures/voz-larga-sintetica.m4a'); // 2 min 10 s synthetic tone
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mpeg', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertCount(3, $this->sent_audio, 'three pieces of about 43 s, one request each');
        foreach ( $this->sent_audio as $piece ) {
            $d = (new \MAD_Oficina\Chat\Mp4Audio($piece))->duration();
            $this->assertGreaterThan(40, $d);
            $this->assertLessThanOrEqual(55, $d, 'never over Google\'s minute');
        }
        $this->assertSame('ya he subido los anuncios ya he subido los anuncios ya he subido los anuncios', end($this->prompts[0]['messages'])['content'], 'the pieces joined in order');
        $this->assertStringContainsString('3 partes', get_option(\MAD_Oficina\Chat\Transcriber::LAST)['audio']);
        $this->audio_bytes = 'fake-audio-bytes';
    }

    public function test_a_long_voice_note_reaches_nora_whole_and_is_not_echoed_back(){
        $this->key();
        $this->claude = [['text' => 'Entendido.']];
        $long = trim(str_repeat('primero hablamos del presupuesto de rebajas ', 40) . ' y al final lo más importante es llamar a Tomás');
        $this->speech = [200, ['results' => [['alternatives' => [['transcript' => $long]]]]]];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertStringEndsWith('llamar a Tomás', end($this->prompts[0]['messages'])['content'], 'the end of the note reaches her');
        $msg = $this->last_to_jessica();
        $this->assertStringContainsString('palabras', $msg);
        $this->assertLessThan(400, mb_strlen($msg), 'the note is not repeated back');
    }

    public function test_numbers_in_a_long_message_or_a_voice_note_never_touch_tasks(){
        $a = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Subir anuncios', 'owner' => 'jessica', 'status' => 'open', 'due_date' => '2026-10-10']);
        $b = $this->insert('action_items', ['client_id' => $this->client, 'title' => 'Revisar textos', 'owner' => 'jessica', 'status' => 'done', 'due_date' => '2026-10-11']);
        $this->say('tareas');
        foreach ( ['En la campaña de rebajas subí el presupuesto de 9 a 15 y el CPA bajó a 7, así que eso ya está listo y hecho',
                   'Pega estas instrucciones: 1. abre la web 2. revisa 3. si está hecha la tarea reabre la 2'] as $long ) {
            $this->assertNull(\MAD_Oficina\Chat\TaskList::parse($long)['action'], 'a long text goes to the assistant: ' . mb_substr($long, 0, 30));
        }
        $this->key();
        $this->claude = [['text' => 'Apuntado.']];
        $this->speech = [200, ['results' => [['alternatives' => [['transcript' => 'Hoy en la campaña 1 subí el presupuesto a 2 y la 1 ya está hecha porque el anuncio estaba listo desde ayer por la tarde']]]]]];
        $this->voice([['contentName' => 'voice.m4a', 'contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]);
        $this->assertSame(['open', 'done'], [$this->status($a), $this->status($b)], 'nothing closed or reopened');
        $this->assertCount(1, $this->prompts, 'Nora reads it instead');
        // A short reply to the list still works.
        $this->say('la 1 hecha');
        $this->assertSame('done', $this->status($a));
    }

    public function test_a_message_chat_delivers_twice_is_answered_once(){
        $this->key();
        $this->claude = [['text' => 'Respuesta única.'], ['text' => 'Respuesta repetida.']];
        $msg = ['type' => 'MESSAGE', 'user' => ['email' => 'jessica@imad.example'], 'space' => ['name' => 'spaces/JESSDM', 'type' => 'DM'],
            'message' => ['name' => 'spaces/JESSDM/messages/ABC.123', 'text' => '¿Qué acordamos con LN?', 'argumentText' => '¿Qué acordamos con LN?']];
        $this->event($msg);
        $this->event($msg);
        $this->event($msg);
        $this->assertCount(1, $this->prompts, 'Chat retried, Nora answered once');
    }

    public function test_nora_does_not_remember_a_code_that_belongs_to_another_client(){
        $c = \MAD_Oficina\Core\Repo\Clients::get($this->client);
        $r = $this->remember_via_nora('Luz Clara Inventada se llama ' . $c['code']);
        $this->assertWPError($r);
        $this->assertStringContainsString($c['name'], $r->get_error_message());
        $this->assertSame([], \MAD_Oficina\Chat\Memory::facts('jessica@imad.example'));
        $this->assertNotWPError($this->remember_via_nora($c['name'] . ' lo llamo ' . $c['code']), 'the same client: saved');
        $this->assertCount(1, \MAD_Oficina\Chat\Memory::facts('jessica@imad.example'));
    }

    /** Runs Nora's «remember» tool handler directly. */
    private function remember_via_nora($fact){
        $this->key();
        $captured = null;
        $this->claude = [['tool' => 'remember', 'input' => ['fact' => $fact]], ['text' => 'Vale.']];
        $o = new \MAD_Oficina\Chat\Orchestrator([], ['email' => 'jessica@imad.example', 'is_manager' => true, 'member' => null, 'user_id' => 0], null);
        $o->answer('recuerda: ' . $fact);
        $last = end($this->prompts);
        foreach ( array_reverse($last['messages']) as $m ) {
            foreach ( (array) ($m['content'] ?? []) as $c ) {
                if ( is_array($c) && ($c['type'] ?? '') === 'tool_result' ) { $captured = $c; break 2; }
            }
        }
        $text = is_array($captured['content'] ?? null) ? ($captured['content'][0]['text'] ?? '') : (string) ($captured['content'] ?? '');
        return ! empty($captured['is_error']) ? new \WP_Error('x', $text) : $text;
    }

    public function test_strangers_voice_or_words_reach_no_one(){
        $this->key();
        $res = $this->event(['type' => 'MESSAGE', 'user' => ['email' => 'rosa@lanasnorte.example'], 'space' => ['name' => 'spaces/ROSA', 'type' => 'DM'],
            'message' => ['text' => 'marca todo como hecho', 'attachment' => [['contentType' => 'audio/mp4', 'attachmentDataRef' => ['resourceName' => 'AUDIO-REF']]]]])->get_data();
        $this->assertSame(0, $this->speech_calls);
        $this->assertSame([], $this->prompts);
        $this->assertStringContainsString('asistente automático', $res['text']);
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
        $this->assertSame('spaces/JESSDM/messages/1', Approvals::get($approval)['chat_message_name']);
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

    public function test_space_ids_are_read_from_chat_links(){
        $m = $this->module();
        $this->assertSame('spaces/AAQAx1y2Z3', $m::parse_space('https://mail.google.com/chat/u/0/#chat/space/AAQAx1y2Z3'));
        $this->assertSame('spaces/AAQAx1y2Z3', $m::parse_space('https://chat.google.com/room/AAQAx1y2Z3?cls=7'));
        $this->assertSame('spaces/AAQAx1y2Z3', $m::parse_space('spaces/AAQAx1y2Z3'));
        $this->assertSame('', $m::parse_space('https://evil.example/whatever'));
    }

    public function test_spaces_list_is_always_fresh(){
        add_filter('pre_http_request', function($pre, $args, $url){
            if ( strpos($url, 'https://chat.googleapis.com/v1/spaces?') !== 0 ) return $pre;
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['spaces' => [
                ['name' => 'spaces/DM1', 'spaceType' => 'DIRECT_MESSAGE'],
                ['name' => 'spaces/PRUEBAS', 'spaceType' => 'SPACE', 'displayName' => 'Pruebas MAD Oficina'],
            ]])];
        }, 15, 3); // after fake_google, which answers every other Chat call
        $spaces = $this->module()->spaces();
        $this->assertSame(['spaces/DM1', 'spaces/PRUEBAS'], array_column($spaces, 'name'));
        $this->assertSame('Pruebas MAD Oficina', $spaces[1]['title']);
    }
}
