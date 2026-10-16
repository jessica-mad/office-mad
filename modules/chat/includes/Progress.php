<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * One message in the person's DM that tells what is going on while they wait
 * («Un momento, se lo pregunto a Amparo…») and finally becomes the answer. It only
 * appears once there is something to wait for; a quick answer just arrives.
 * If it cannot be edited, the answer simply goes as a new message.
 */
final class Progress {

    private $api;
    private $space;
    private $name = null;
    private $last = '';
    private $last_at = 0;

    public function __construct(?ChatApi $api, $space){
        $this->api = $api;
        $this->space = (string) $space;
    }

    public function start($text){
        if ( ! $this->api || ! $this->space ) return;
        $r = $this->api->create_message($this->space, ['text' => $text]);
        if ( ! is_wp_error($r) && ! empty($r['name']) ) { $this->name = $r['name']; $this->last = $text; $this->last_at = microtime(true); }
    }

    /** A new status line: the first one creates the message; then it is edited (not more than once a second). */
    public function say($text){
        if ( ! $this->name ) { $this->start($text); return; }
        if ( ! $this->name || $text === $this->last || microtime(true) - $this->last_at < (float) apply_filters('madoficina_progress_interval', 1.0) ) return; // Chat allows ~1 edit per second
        $this->last = $text; $this->last_at = microtime(true);
        $this->api->update_message($this->name, ['text' => $text]);
    }

    /** The answer replaces the status line. @return array|\WP_Error */
    public function finish(array $message){
        if ( $this->name ) {
            $r = $this->api->update_message($this->name, $message);
            if ( ! is_wp_error($r) ) return $r;
        }
        return $this->api && $this->space ? $this->api->create_message($this->space, $message) : new \WP_Error('madof_chat_off', 'Chat not configured');
    }
}
