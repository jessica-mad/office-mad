<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Agents\ClaudeClient;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Core\Time;

if ( ! defined('ABSPATH') ) exit;

/**
 * What Jessica (or Liz) writes or says to the bot in their DM. Numbers («la 2 hecha»)
 * and «tareas» are handled here without AI; anything else goes to the Orchestrator
 * (her personal assistant), which asks the area agents. While it works, one message
 * in the DM tells what is going on and finally becomes the answer.
 */
final class Assistant {

    const AGENT = 'chat_assistant';
    const HOOK = 'madoficina_chat_understand';
    const DAILY = 80;

    const STATUS = 'madoficina_chat_assistant_status';

    /** The server's way to answer Google first and keep working (PHP-FPM, LiteSpeed), or null. */
    public static function finisher(){
        foreach ( ['fastcgi_finish_request', 'litespeed_finish_request'] as $fn ) if ( function_exists($fn) ) return $fn;
        return null;
    }

    /**
     * Chat waits 30 s at most. Where the server can answer Google first (PHP-FPM,
     * LiteSpeed), the work runs right after; elsewhere it runs within the request.
     * Either way the reply goes as a new message, never through a queue that may not run.
     */
    public static function defer($email, $text, ?array $voice){
        $args = [(string) $email, (string) $text, $voice ?: []];
        self::status('received', $voice ? 'voice' : 'text');
        $finish = self::finisher();
        $mode = apply_filters('madoficina_chat_assistant_mode', $finish ? 'shutdown' : 'inline');
        $work = function() use ($args){
            ignore_user_abort(true);
            if ( function_exists('set_time_limit') ) @set_time_limit(180);
            try {
                do_action(self::HOOK, ...$args);
            } catch ( \Throwable $e ) {
                self::status('error', $e->getMessage());
            }
        };
        if ( $mode === 'shutdown' ) {
            register_shutdown_function(function() use ($work, $finish){
                if ( $finish ) $finish();
                $work();
            });
            return;
        }
        $work();
    }

    /** What happened with the last message (for Chat → ajustes). */
    public static function status($what, $detail = ''){
        $s = get_option(self::STATUS, []);
        $s = is_array($s) ? $s : [];
        $s[$what] = ['at' => time(), 'detail' => mb_substr(sanitize_text_field((string) $detail), 0, 300)];
        update_option(self::STATUS, $s, false);
    }

    /* ───────── the work ───────── */

    private $messenger;
    private $api;
    private $manager_email;
    private $settings;
    /** @var Progress|null */
    private $progress;
    /** @var string[] areas asked for the last answer (shown in Chat → ajustes) */
    private $asked = [];

    public function __construct(Messenger $messenger, ?ChatApi $api, array $settings = []){
        $this->messenger = $messenger;
        $this->api = $api;
        $this->settings = $settings;
        $this->manager_email = $messenger->manager_email();
    }

    /** @return array|\WP_Error what was sent */
    public function run($email, $text, array $voice = []){
        $email = strtolower((string) $email);
        $is_manager = $email !== '' && $email === $this->manager_email;
        $member = $is_manager ? null : self::member($email);
        if ( ! $is_manager && ! $member ) return new \WP_Error('madof_assistant_who', 'Unknown sender');
        $this->progress = new Progress($this->api, $this->messenger->dm_space($member));

        $heard = '';
        if ( $voice ) {
            if ( ! $this->api ) return $this->reply($member, ['text' => __('El bot no está configurado.', 'mad-oficina')]);
            $text = Transcriber::text_of($this->api, $voice + ['text' => '', 'transcript' => null, 'audio' => null]);
            if ( is_wp_error($text) ) return $this->reply($member, ['text' => $text->get_error_message()]);
            $heard = $text;
        }
        $text = mb_substr(trim(wp_strip_all_tags((string) $text)), 0, 2000);
        if ( $text === '' ) return $this->reply($member, ['text' => __('No te he entendido. ¿Me lo repites?', 'mad-oficina')]);
        $prefix = $heard !== '' ? sprintf(__('He oído: «%s»', 'mad-oficina'), mb_substr($heard, 0, 400)) : '';

        // Numbers («la 2 y la 5 hechas») never need the AI.
        $p = TaskList::parse($text);
        if ( in_array($p['action'], ['done', 'undo'], true) && TaskList::last($email) ) {
            return $this->reply($member, $this->with_prefix(self::by_numbers($p, $email, $this->manager_email), $prefix));
        }
        if ( $p['action'] === 'list' && mb_strlen($text) < 40 ) {
            $ids = TaskList::open_ids($member ? (int) $member['id'] : null);
            if ( ! $ids ) return $this->reply($member, $this->with_prefix(__('No tienes tareas abiertas. ¡Bien!', 'mad-oficina'), $prefix));
            TaskList::remember($email, $ids);
            return $this->reply($member, $this->with_prefix(TaskList::card(__('Tus tareas abiertas', 'mad-oficina'), $ids), $prefix));
        }

        if ( ! ClaudeClient::has_key() ) return $this->reply($member, $this->with_prefix(__('Para entender frases necesito la clave de Claude. Mientras, contéstame con los números de la lista: «la 2 y la 5 hechas».', 'mad-oficina'), $prefix));
        $key = 'madof_assistant_' . md5($email) . '_' . current_time('Ymd');
        $used = (int) get_transient($key);
        if ( $used >= self::DAILY ) return $this->reply($member, ['text' => __('Hoy ya hemos hablado muchísimo: mañana sigo. Para tareas, contéstame con los números de la lista.', 'mad-oficina')]);
        set_transient($key, $used + 1, DAY_IN_SECONDS);

        $user = get_user_by('email', $email);
        $ctx = [
            'email' => $email, 'is_manager' => $is_manager, 'member' => $member, 'manager_email' => $this->manager_email,
            'user_id' => $member ? (int) $member['wp_user_id'] : self::manager_user_id($email),
        ];
        $boss = new Orchestrator($this->settings, $ctx, $this->progress);
        $answer = $boss->answer($text);
        $this->asked = $boss->asked;
        $this->log('answered', ['asked' => $boss->asked, 'changes' => count($boss->undo), 'error' => is_wp_error($answer) ? $answer->get_error_message() : null]);
        if ( is_wp_error($answer) ) return $this->reply($member, ['text' => trim($prefix . "\n\n" . $answer->get_error_message())]);
        $message = ['text' => $answer];
        if ( $boss->undo ) {
            $message += Cards::card('undo-' . substr(md5(implode(',', $boss->undo)), 0, 8), __('Cambios hechos', 'mad-oficina'), '', [
                ['buttonList' => ['buttons' => [Cards::button(__('Deshacer', 'mad-oficina'), 'task_undo', ['token' => implode(',', $boss->undo)])]]],
            ]);
        }
        return $this->reply($member, $this->with_prefix($message, $prefix));
    }

    private function with_prefix($message, $prefix){
        if ( is_string($message) ) $message = ['text' => $message];
        if ( $prefix !== '' ) $message['text'] = trim($prefix . "\n\n" . ($message['text'] ?? ''));
        return $message;
    }

    private function reply(?array $member, array $message){
        $r = $this->progress ? $this->progress->finish($message) : ($member ? $this->messenger->to_team_member($member, $message) : $this->messenger->to_manager($message));
        if ( is_wp_error($r) ) { $this->log('reply_error', ['error' => $r->get_error_message()]); self::status('error', $r->get_error_message()); }
        else self::status('answered', $this->asked ? 'preguntó a: ' . implode(', ', array_unique($this->asked)) : 'contestó ella sola');
        return is_wp_error($r) ? $r : $message;
    }

    /**
     * Jessica's WordPress user, for what depends on it (Finanzas). Chat verified she is the
     * manager; her WP user may have another email: then the one Finanzas is reserved to,
     * or — when it is not reserved, so any manager sees it — any manager.
     */
    public static function manager_user_id($email){
        $cap = \MAD_Oficina\Core\Roles::CAP_MANAGE;
        $user = get_user_by('email', $email);
        if ( $user && user_can($user, $cap) ) return (int) $user->ID;
        $reserved = \MAD_Oficina\Core\FinanceAccess::users();
        if ( $reserved ) return (int) $reserved[0];
        foreach ( get_users(['fields' => 'ID', 'orderby' => 'ID', 'number' => 50]) as $id ) if ( user_can((int) $id, $cap) ) return (int) $id;
        return 0;
    }

    private static function member($email){
        foreach ( \MAD_Oficina\Core\Repo\Team::all(true) as $m ) if ( strtolower((string) $m['workspace_email']) === $email ) return $m;
        return null;
    }

    /** «la 2 y la 5 hechas» against the last list. @return array message */
    public static function by_numbers(array $p, $email, $manager_email){
        $last = TaskList::last($email);
        $ids = $last['ids'];
        $missing = [];
        if ( $p['nums'] !== 'all' ) {
            $pick = [];
            foreach ( $p['nums'] as $n ) isset($ids[$n - 1]) ? $pick[] = $ids[$n - 1] : $missing[] = $n;
            $ids = $pick;
        }
        $rows = array_values(TaskList::rows($ids));
        if ( $p['action'] === 'undo' ) return TaskList::result_card(TaskList::reopen($rows, array_fill_keys($ids, 'open'), $email, $manager_email), true, $missing);
        if ( $p['nums'] === 'all' ) $rows = array_values(array_filter($rows, fn($t) => in_array($t['status'], TaskList::STATUSES_OPEN, true)));
        return TaskList::result_card(TaskList::mark($rows, $email, $manager_email), false, $missing);
    }

    private function log($event, array $data){
        global $wpdb;
        $wpdb->insert(Schema::table('agent_log'), ['agent' => self::AGENT, 'event' => $event, 'client_id' => null, 'data' => wp_json_encode($data), 'created_at' => Time::now()]);
    }
}
