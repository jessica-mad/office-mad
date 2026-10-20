<?php
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\TaskChanges;
use MAD_Oficina\Followup\Reconciler;
use MAD_Oficina\Meetings\Meetings;

/**
 * A clean task list: changes from new contacts, the secretary updating instead of
 * duplicating, and Valle's review (duplicates merged, repeated tasks up in priority).
 * Everything here is invented data.
 */
class TaskCleanupTest extends MADOF_TestCase {

    private static $sa_key;
    private $client;
    private $other;
    private $claude = [];
    private $prompts = [];
    private $sent = [];

    public static function wpSetUpBeforeClass($factory){
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), self::$sa_key);
    }

    public function set_up(){
        parent::set_up();
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
        update_option(MAD_Oficina_Core::option_key('chat'), [
            'manager_email' => 'jessica@imad.example', 'manager_dm' => 'spaces/JESSDM',
            'service_account' => Crypto::encrypt(wp_json_encode(['type' => 'service_account', 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => self::$sa_key, 'token_uri' => 'https://oauth2.googleapis.com/token'])),
        ]);
        delete_transient('madof_chat_token_' . md5('bot@proj.iam.gserviceaccount.com'));
        delete_option(Reconciler::QUEUE);
        $this->client = Clients::save(['name' => 'Domestilo', 'code' => 'DT']);
        $this->other = Clients::save(['name' => 'Lanas del Norte', 'code' => 'LN']);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        if ( $url === 'https://oauth2.googleapis.com/token' ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['access_token' => 't', 'expires_in' => 3600])];
        if ( $url === 'https://api.anthropic.com/v1/messages' ) {
            $this->prompts[] = json_decode($args['body'], true);
            $next = array_shift($this->claude);
            if ( $next === null ) return ['response' => ['code' => 500], 'body' => '{}'];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['model' => 'm', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'content' => [['type' => 'text', 'text' => wp_json_encode($next)]]])];
        }
        if ( strpos($url, 'https://chat.googleapis.com/v1/') === 0 ) {
            $this->sent[] = ['url' => $url, 'body' => json_decode($args['body'] ?? '{}', true)];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['name' => 'spaces/JESSDM/messages/' . count($this->sent)])];
        }
        return $pre;
    }

    private function task(array $row){
        return $this->insert('action_items', $row + ['client_id' => $this->client, 'owner' => 'mad', 'status' => 'open', 'priority' => 'normal', 'visibility' => 'internal']);
    }

    private function row($id){
        return $GLOBALS['wpdb']->get_row('SELECT * FROM ' . Schema::table('action_items') . ' WHERE id = ' . (int) $id, ARRAY_A);
    }

    public function test_changes_from_a_new_contact_only_touch_that_clients_open_tasks_and_can_be_undone(){
        $video = $this->task(['title' => 'Vídeo para reels', 'due_date' => '2026-10-20']);
        $logo = $this->task(['title' => 'Mandar logo', 'priority' => 'low']);
        $foreign = $this->task(['title' => 'De otro cliente', 'client_id' => $this->other]);
        $r = TaskChanges::apply([
            ['task_id' => $video, 'action' => 'change', 'title' => 'Vídeo para YouTube', 'due_date' => '2026-11-03', 'note' => 'Tomás lo quiere para YouTube, no para reels.'],
            ['task_id' => $logo, 'action' => 'repeat'],
            ['task_id' => $foreign, 'action' => 'done'],
        ], $this->client, 'WhatsApp del 17/10');
        $this->assertCount(2, $r['applied']);
        $this->assertSame('es de otro cliente', $r['skipped'][0]['why']);
        $v = $this->row($video);
        $this->assertSame(['Vídeo para YouTube', '2026-11-03'], [$v['title'], $v['due_date']]);
        $this->assertStringContainsString('WhatsApp del 17/10] Tomás lo quiere para YouTube', $v['description'], 'the task says why it changed');
        $this->assertSame('normal', $this->row($logo)['priority'], 'came up again: priority goes up a level');
        $this->assertSame('open', $this->row($foreign)['status']);

        \MAD_Oficina\Chat\TaskList::restore($r['undo'], 'jessica@imad.example', 'jessica@imad.example');
        $this->assertSame(['Vídeo para reels', '2026-10-20', 'low'], [$this->row($video)['title'], $this->row($video)['due_date'], $this->row($logo)['priority']]);
    }

    private function meeting_with(array $extraction){
        $id = Meetings::upsert(['source' => 'whatsapp', 'title' => 'WhatsApp con Tomás', 'starts_at' => '2026-10-17 18:00', 'transcript' => 'x', 'client_id' => $this->client]);
        Meetings::update($id, ['status' => 'extracted', 'extraction' => $extraction + ['summary_client' => '', 'summary_internal' => 'Cambios', 'decisions' => [], 'tasks' => [], 'pending_topics' => [], 'ideas' => [], 'next_meeting' => ['status' => 'none', 'date' => null, 'notes' => ''], 'internal_notes' => [], '_identification' => ['unknown' => [], 'reasons' => []]]]);
        return [$id, Approvals::create('secretary', 'meeting_draft', 'Acta', ['meeting_id' => $id], ['client_id' => $this->client, 'object_type' => 'meeting', 'object_id' => $id])];
    }

    public function test_the_secretary_sees_open_tasks_and_updates_them_instead_of_duplicating(){
        $video = $this->task(['title' => 'Vídeo para reels']);
        $id = Meetings::upsert(['source' => 'whatsapp', 'title' => 'WhatsApp con Tomás', 'starts_at' => '2026-10-17 18:00', 'transcript' => 'Tomás: mejor para YouTube', 'client_id' => $this->client]);
        $this->claude[] = ['summary_client' => '', 'summary_internal' => '', 'decisions' => [], 'tasks' => [], 'pending_topics' => [], 'ideas' => [], 'next_meeting' => ['status' => 'none', 'date' => null, 'notes' => ''], 'internal_notes' => [],
            'task_updates' => [['task_id' => $video, 'action' => 'change', 'title' => 'Vídeo para YouTube', 'due_date' => null, 'note' => 'Ahora para YouTube', 'evidence' => 'Tomás: mejor para YouTube']]];
        (new MAD_Oficina\Meetings\Secretary([]))->process($id);
        $user = $this->prompts[0]['messages'][0]['content'];
        $this->assertStringContainsString("<tareas_abiertas>\n- [{$video}] Vídeo para reels", $user);
        $this->assertStringContainsString('SOLO compromisos explícitos', $this->prompts[0]['system'][0]['text']);
        $this->assertContains('task_updates', array_keys($this->prompts[0]['output_config']['format']['schema']['properties']));

        // Approved «as is» (Chat): the existing task changes, nothing new is created.
        $approval = Approvals::for_object('meeting', $id)[0];
        $input = apply_filters('madoficina_approval_default_input_meeting_draft', [], $approval);
        $r = Approvals::resolve($approval['id'], 'approve', $input, 1, 'chat');
        $this->assertStringContainsString('1 tarea que ya existía, actualizada', $r['message']);
        $this->assertSame('Vídeo para YouTube', $this->row($video)['title']);
        $this->assertSame(1, (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . Schema::table('action_items')));
        $this->assertArrayHasKey($this->client, get_option(Reconciler::QUEUE), 'and Valle reviews this client next');
    }

    public function test_doubtful_tasks_come_unticked_with_their_quote(){
        [, $approval] = $this->meeting_with(['tasks' => [
            ['title' => 'Mandar fotos', 'description' => '', 'owner' => 'cliente', 'owner_name' => '', 'due_date' => null, 'priority' => 'normal', 'client_visible' => true, 'evidence' => 'Te las mando el lunes', 'sure' => true],
            ['title' => 'Pensar campaña', 'description' => '', 'owner' => 'mad', 'owner_name' => '', 'due_date' => null, 'priority' => 'normal', 'client_visible' => false, 'evidence' => 'podríamos pensar algo', 'sure' => false],
        ]]);
        $input = apply_filters('madoficina_approval_default_input_meeting_draft', [], Approvals::get($approval));
        $this->assertSame([1, 0], array_column($input['tasks'], 'on'));
        $html = apply_filters('madoficina_approval_form_meeting_draft', '', Approvals::get($approval));
        $this->assertStringContainsString('«Te las mando el lunes»', $html);
        $this->assertStringContainsString('dudosa: sin marcar', $html);
        Approvals::resolve($approval, 'approve', $input, 1, 'chat');
        $this->assertSame(['Mandar fotos'], $GLOBALS['wpdb']->get_col('SELECT title FROM ' . Schema::table('action_items')));
        $this->assertSame('Te las mando el lunes', $GLOBALS['wpdb']->get_var('SELECT evidence FROM ' . Schema::table('action_items')));
    }

    public function test_valle_never_closes_a_task_alone_she_asks(){
        $a = $this->task(['title' => 'Enviar fotos del catálogo', 'due_date' => '2026-10-20', 'visibility' => 'client']);
        $b = $this->task(['title' => 'Mandar las fotos del catálogo']);
        $c = $this->task(['title' => 'Revisar la web']);
        $d = $this->task(['title' => 'Subir el banner']);
        $foreign = $this->task(['title' => 'De otro', 'client_id' => $this->other]);
        $this->claude[] = ['actions' => [
            ['type' => 'merge', 'keep_id' => $a, 'task_ids' => [$b, $foreign], 'title' => '', 'due_date' => null, 'reason' => 'Es la misma tarea', 'evidence' => 'Salió el 3/10 y el 17/10', 'sure' => true],
            ['type' => 'done', 'keep_id' => 0, 'task_ids' => [$c], 'title' => '', 'due_date' => null, 'reason' => 'Parece hecha', 'evidence' => 'acta 17/10', 'sure' => false],
            ['type' => 'repeat', 'keep_id' => 0, 'task_ids' => [$d], 'title' => '', 'due_date' => null, 'reason' => 'Sale otra vez', 'evidence' => 'acta 17/10', 'sure' => true],
        ]];
        Reconciler::enqueue($this->client);
        $this->assertSame(1, (new Reconciler())->run_queue());

        $this->assertSame(['open', 'open', 'open', 'open'], [$this->row($a)['status'], $this->row($b)['status'], $this->row($c)['status'], $this->row($foreign)['status']], 'a «duplicate», even a sure one, is not cancelled without her');
        $this->assertSame('high', $this->row($d)['priority'], 'what only raises the priority is applied');
        $this->assertStringContainsString('ha ordenado las tareas de DT', wp_json_encode(end($this->sent)['body'], JSON_UNESCAPED_UNICODE));

        $review = array_values(array_filter(Approvals::pending(10), fn($x) => $x['action_type'] === Reconciler::ACTION));
        $this->assertCount(1, $review, 'the merge and the doubtful one wait for Jessica');
        $html = apply_filters('madoficina_approval_form_' . Reconciler::ACTION, '', $review[0]);
        $this->assertStringContainsString('Es la misma tarea', $html);
        Approvals::resolve($review[0]['id'], 'approve', [], 1, 'chat');
        $this->assertSame(['open', 'cancelled', 'done', 'open'], [$this->row($a)['status'], $this->row($b)['status'], $this->row($c)['status'], $this->row($foreign)['status']], 'when she says yes; never another client\'s');
    }

    public function test_a_minute_never_recreates_a_task_that_exists(){
        $done = $this->task(['title' => 'Preparar la propuesta para Camila', 'status' => 'done']);
        $open = $this->task(['title' => 'Enviar el presupuesto de la web']);
        $id = Meetings::upsert(['source' => 'whatsapp', 'title' => 'WhatsApp con Camila', 'starts_at' => '2026-10-17 18:00', 'transcript' => 'x', 'client_id' => $this->client]);
        $this->claude[] = ['summary_client' => '', 'summary_internal' => '', 'decisions' => [], 'pending_topics' => [], 'ideas' => [], 'next_meeting' => ['status' => 'none', 'date' => null, 'notes' => ''], 'internal_notes' => [], 'task_updates' => [], 'tasks' => [
            ['title' => 'Preparar propuesta para Camila', 'description' => '', 'owner' => 'jessica', 'owner_name' => '', 'due_date' => null, 'priority' => 'normal', 'client_visible' => false, 'evidence' => 'la propuesta de Camila', 'sure' => true],
            ['title' => 'Enviar el presupuesto de la web', 'description' => '', 'owner' => 'jessica', 'owner_name' => '', 'due_date' => null, 'priority' => 'normal', 'client_visible' => false, 'evidence' => 'el presupuesto', 'sure' => true],
            ['title' => 'Grabar el vídeo nuevo', 'description' => '', 'owner' => 'liz', 'owner_name' => '', 'due_date' => null, 'priority' => 'normal', 'client_visible' => false, 'evidence' => 'el vídeo', 'sure' => true],
        ]];
        (new MAD_Oficina\Meetings\Secretary([]))->process($id);
        $approval = Approvals::for_object('meeting', $id)[0]['id'];
        $this->assertStringContainsString("<tareas_cerradas>\n- [{$done}] Preparar la propuesta para Camila · hecha", end($this->prompts)['messages'][0]['content'], 'the secretary knows what was done');
        $html = apply_filters('madoficina_approval_form_meeting_draft', '', Approvals::get($approval));
        $this->assertStringContainsString('Ya existe: #' . $done, $html);
        $input = apply_filters('madoficina_approval_default_input_meeting_draft', [], Approvals::get($approval));
        $this->assertSame([0, 0, 1], array_column($input['tasks'], 'on'), 'what exists comes unticked');
        $input['tasks'][1]['on'] = 1; // even if ticked by mistake, an open one is not created twice
        $r = Approvals::resolve($approval, 'approve', $input, 1, 'chat');
        $this->assertStringContainsString('ya estaba abierta', $r['message']);
        $this->assertSame('done', $this->row($done)['status'], 'the done task stays done');
        $this->assertSame(1, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Schema::table('action_items') . ' WHERE title = %s', 'Enviar el presupuesto de la web')));
    }
}
