<?php
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Autonomy;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Followup\Coordinator;

/**
 * Coordinadora: reminders, ideas, meeting briefs and the weekly summary.
 * Everything here is invented data.
 */
class FollowupTest extends MADOF_TestCase {

    private static $sa_key;
    private $client;
    private $liz;
    private $sent = [];
    private $events = [];

    public static function wpSetUpBeforeClass($factory){
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), self::$sa_key);
    }

    public function set_up(){
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        add_filter('pre_http_request', [$this, 'fake_google'], 10, 3);
        update_option(MAD_Oficina_Core::option_key('chat'), [
            'manager_email'   => 'jessica@imad.example',
            'manager_dm'      => 'spaces/JESSDM',
            'test_space'      => 'spaces/TEST',
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => self::$sa_key, 'token_uri' => 'https://oauth2.googleapis.com/token'])),
        ]);
        delete_option(Coordinator::SENT);
        delete_transient('madof_briefs_checked');
        $this->client = Clients::save(['name' => 'Lanas del Norte', 'code' => 'LN', 'domains' => 'lanasnorte.example']);
        $GLOBALS['wpdb']->update(Schema::table('clients'), ['chat_targets' => wp_json_encode(['reminders' => ['type' => 'space', 'value' => 'spaces/CLIENTE', 'label' => 'LN x MAD']])], ['id' => $this->client]);
        $this->liz = Team::save(['name' => 'Liz', 'slug' => 'liz', 'workspace_email' => 'liz@imad.example', 'chat_dm_space' => 'spaces/LIZDM']);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake_google']);
        delete_option(Autonomy::OPTION);
        parent::tear_down();
    }

    public function fake_google($pre, $args, $url){
        if ( $url === 'https://oauth2.googleapis.com/token' ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 'bot-token', 'expires_in' => 3600])];
        if ( strpos($url, 'https://chat.googleapis.com/v1/') === 0 ) {
            $this->sent[] = ['url' => $url, 'body' => json_decode($args['body'] ?? '{}', true)];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['name' => 'spaces/X/messages/' . count($this->sent)])];
        }
        if ( strpos($url, 'googleapis.com/calendar/v3/calendars/primary/events') !== false ) {
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['items' => $this->events])];
        }
        return $pre;
    }

    private function sent_to($space){
        return array_values(array_filter($this->sent, fn($s) => $s['url'] === "https://chat.googleapis.com/v1/{$space}/messages"));
    }

    private function task(array $row){
        return $this->insert('action_items', $row + ['client_id' => $this->client, 'title' => 'Tarea', 'owner' => 'mad', 'status' => 'open', 'visibility' => 'client']);
    }

    private function coordinator(array $settings = []){ return new Coordinator($settings); }

    public function test_daily_reminders_go_to_jessica_and_liz_once_a_day(){
        $today = current_time('Y-m-d');
        $this->task(['title' => 'Subir anuncios', 'owner' => 'jessica', 'due_date' => $today]);
        $this->task(['title' => 'Montar el vídeo', 'owner' => 'liz', 'team_member_id' => $this->liz, 'due_date' => gmdate('Y-m-d', strtotime($today . ' -1 day'))]);
        $this->task(['title' => 'Dentro de un mes', 'owner' => 'jessica', 'due_date' => gmdate('Y-m-d', strtotime($today . ' +30 days'))]);
        $this->task(['title' => 'Ya hecha', 'owner' => 'jessica', 'due_date' => $today, 'status' => 'done']);

        $noon = (new DateTimeImmutable($today . ' 12:00', wp_timezone()))->getTimestamp();
        $ran = $this->coordinator(['briefs' => false, 'client_reminders' => false, 'ideas' => false])->run($noon);
        $this->assertSame(2, $ran['reminders']);

        $mine = wp_json_encode($this->sent_to('spaces/JESSDM')[0]['body'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('1 · LN · hoy', $mine, 'numbered lines');
        $this->assertStringContainsString('Subir anuncios', $mine);
        $this->assertStringContainsString('task_list_done', $mine, 'a «Hecha» button per line');
        $this->assertStringNotContainsString('Dentro de un mes', $mine);
        $this->assertStringNotContainsString('Ya hecha', $mine);
        $this->assertStringNotContainsString('Montar el vídeo', $mine, "Liz's tasks go to Liz");
        $liz = wp_json_encode($this->sent_to('spaces/LIZDM')[0]['body'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Montar el vídeo', $liz);
        $this->assertStringContainsString('vencida', $liz);
        $this->assertCount(1, \MAD_Oficina\Chat\TaskList::last('jessica@imad.example')['ids'], 'remembered for answers by number');
        $this->assertCount(1, \MAD_Oficina\Chat\TaskList::last('liz@imad.example')['ids']);

        $this->sent = [];
        $this->coordinator(['briefs' => false])->run($noon + 3600);
        $this->assertSame([], $this->sent, 'only once a day');
    }

    public function test_nothing_before_the_daily_hour(){
        $today = current_time('Y-m-d');
        $this->task(['owner' => 'jessica', 'due_date' => $today]);
        $early = (new DateTimeImmutable($today . ' 07:00', wp_timezone()))->getTimestamp();
        $this->assertArrayNotHasKey('reminders', $this->coordinator(['briefs' => false])->run($early));
    }

    public function test_client_reminders_wait_in_the_inbox_and_never_include_internal_tasks(){
        $today = current_time('Y-m-d');
        $this->task(['title' => 'Enviar fotos', 'owner' => 'cliente', 'due_date' => $today]);
        $this->task(['title' => 'Nota interna', 'owner' => 'cliente', 'visibility' => 'internal', 'due_date' => $today]);
        $c = $this->coordinator();
        $this->assertSame(1, $c->client_reminders($today));
        $this->assertSame(0, $c->client_reminders($today), 'one per client every 7 days');
        $this->assertSame([], $this->sent_to('spaces/TEST'));

        $a = Approvals::pending()[0];
        $this->assertSame('client_reminder', $a['action_type']);
        $this->assertStringContainsString('Enviar fotos', $a['payload']['text']);
        $this->assertStringNotContainsString('Nota interna', $a['payload']['text']);

        $r = Approvals::resolve($a['id'], 'approve', ['text' => 'Hola, ¿nos mandáis las fotos?'], 1);
        $this->assertIsArray($r);
        $msg = $this->sent_to('spaces/TEST')[0]['body']['text'];
        $this->assertStringContainsString('[PRUEBA · Destino real: LN · LN x MAD]', $msg);
        $this->assertStringContainsString('Hola, ¿nos mandáis las fotos?', $msg);
        $this->assertStringContainsString('Jessica lo ha revisado', $msg);
    }

    public function test_autonomous_client_reminders_say_they_were_not_reviewed(){
        Autonomy::save(['client_reminder' => 'silent']);
        $this->task(['title' => 'Enviar fotos', 'owner' => 'cliente', 'due_date' => current_time('Y-m-d')]);
        $this->coordinator()->client_reminders(current_time('Y-m-d'));
        $msg = $this->sent_to('spaces/TEST')[0]['body']['text'];
        $this->assertStringContainsString('Mensaje automático de la oficina de MAD.', $msg);
        $this->assertStringNotContainsString('revisado', $msg);
    }

    public function test_ideas_come_back_once_and_are_resolved_only_by_jessica(){
        $idea = $this->insert('ideas', ['client_id' => $this->client, 'title' => 'Activación en tienda', 'resurface_at' => current_time('Y-m-d'), 'status' => 'dormant']);
        $this->insert('ideas', ['client_id' => $this->client, 'title' => 'Más adelante', 'resurface_at' => gmdate('Y-m-d', strtotime('+2 months')), 'status' => 'dormant']);
        $c = $this->coordinator();
        $this->assertSame(1, $c->surface_ideas(current_time('Y-m-d')));
        $this->assertSame(0, $c->surface_ideas(current_time('Y-m-d')));
        $card = $this->sent_to('spaces/JESSDM')[0]['body']['cardsV2'][0]['card'];
        $this->assertSame('Activación en tienda', $card['header']['subtitle']);

        $this->assertSame('Solo Jessica puede decidir sobre las ideas.', apply_filters('madoficina_chat_action', null, 'idea_task', ['idea_id' => $idea], 'liz@imad.example', false));
        $out = apply_filters('madoficina_chat_action', null, 'idea_task', ['idea_id' => $idea], 'jessica@imad.example', true);
        $this->assertIsArray($out);
        global $wpdb;
        $this->assertSame('converted', $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . Schema::table('ideas') . ' WHERE id = %d', $idea)));
        $task = $wpdb->get_row("SELECT * FROM " . Schema::table('action_items') . " WHERE source = 'idea'", ARRAY_A);
        $this->assertSame('Activación en tienda', $task['title']);
        $this->assertSame('internal', $task['visibility']);
        $this->assertIsString(apply_filters('madoficina_chat_action', null, 'idea_discard', ['idea_id' => $idea], 'jessica@imad.example', true), 'already resolved');
        $this->assertNull(apply_filters('madoficina_chat_action', null, 'something_else', [], 'jessica@imad.example', true));
    }

    public function test_postponed_ideas_sleep_for_a_month(){
        $idea = $this->insert('ideas', ['client_id' => $this->client, 'title' => 'Idea', 'resurface_at' => current_time('Y-m-d'), 'status' => 'surfaced']);
        Coordinator::resolve_idea($idea, 'postpone');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('ideas') . ' WHERE id = %d', $idea), ARRAY_A);
        $this->assertSame('dormant', $row['status']);
        $this->assertSame(gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +1 month')), $row['resurface_at']);
    }

    public function test_brief_before_a_client_meeting_is_sent_once(){
        $google = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        update_option(MAD_Oficina_Core::option_key('google'), [
            'refresh_token' => Crypto::encrypt('r'), 'access_token' => Crypto::encrypt('a'), 'expires_at' => time() + 3600,
            'scopes' => $google->required_scopes(), 'workspace_domain' => 'imad.example',
        ]);
        $start = time() + 5 * HOUR_IN_SECONDS;
        $this->events = [
            ['id' => 'ev1', 'summary' => 'LN x MAD', 'start' => ['dateTime' => gmdate('c', $start)], 'attendees' => [['email' => 'rosa@lanasnorte.example'], ['email' => 'jessica@imad.example']]],
            ['id' => 'ev2', 'summary' => 'Dentista', 'start' => ['dateTime' => gmdate('c', $start)]],
        ];
        $this->task(['title' => 'Enviar fotos', 'owner' => 'cliente', 'due_date' => current_time('Y-m-d')]);
        $this->task(['title' => 'Margen interno', 'owner' => 'jessica', 'visibility' => 'internal']);
        $this->insert('pending_topics', ['client_id' => $this->client, 'text' => 'Confirmar presupuesto de Navidad']);

        $c = $this->coordinator();
        $this->assertSame(1, $c->briefs(time()));
        $this->assertSame(0, $c->briefs(time()));
        $brief = $this->sent_to('spaces/JESSDM')[0]['body']['text'];
        $this->assertStringContainsString('Ficha para tu reunión con Lanas del Norte', $brief);
        $this->assertStringContainsString('Enviar fotos', $brief);
        $this->assertStringContainsString('Margen interno', $brief, 'the brief is for Jessica: internal tasks included');
        $this->assertStringContainsString('Confirmar presupuesto de Navidad', $brief);
    }

    public function test_weekly_summary_goes_to_jessica(){
        $this->task(['title' => 'Entregar logo', 'owner' => 'liz', 'team_member_id' => $this->liz, 'due_date' => current_time('Y-m-d')]);
        $this->assertSame(1, $this->coordinator()->weekly_summary(current_time('Y-m-d')));
        $text = $this->sent_to('spaces/JESSDM')[0]['body']['text'];
        $this->assertStringContainsString('Tu semana en MAD Oficina', $text);
        $this->assertStringContainsString('_Liz_', $text);
        $this->assertStringContainsString('Entregar logo', $text);
    }
}
