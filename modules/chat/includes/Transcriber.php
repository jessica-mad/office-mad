<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * Voice messages → text, with Google Cloud Speech-to-Text v2 and the bot's own
 * service account (no new keys). Claude does not take audio, so this step is Google's.
 *
 * Needs, once, in the bot's Google Cloud project: the «Cloud Speech-to-Text API»
 * enabled and the service account with the «Cloud Speech Client» role.
 * Synchronous recognition takes up to 1 minute of audio.
 */
final class Transcriber {

    const MAX_BYTES = 10 * 1024 * 1024;
    const MAX_SECONDS = 60;
    const AUDIO_NAME = '/\.(m4a|aac|mp3|mp4|ogg|oga|opus|wav|amr|3gp|webm|flac)$/i';

    const SHAPE_OPTION = 'madoficina_chat_voice_shape';

    /**
     * What a voice message brings: Google's own transcript when the message carries it
     * (as text or as a text attachment), and the audio otherwise.
     * @return array{text:string, transcript:?string, audio:?string}|null null = no audio
     */
    public static function voice_of(array $message){
        $audio = null; $transcript = null;
        foreach ( (array) ($message['attachment'] ?? []) as $a ) {
            $type = strtolower((string) ($a['contentType'] ?? ''));
            $name = (string) ($a['contentName'] ?? '');
            $resource = (string) ($a['attachmentDataRef']['resourceName'] ?? '');
            if ( $resource === '' ) continue;
            if ( ! $audio && (strpos($type, 'audio/') === 0 || preg_match(self::AUDIO_NAME, $name)) ) $audio = $resource;
            elseif ( ! $transcript && (strpos($type, 'text/') === 0 || stripos($name, 'transcri') !== false) ) $transcript = $resource;
        }
        if ( ! $audio && ! $transcript ) return null;
        self::note_shape($message);
        return ['text' => trim((string) ($message['argumentText'] ?? $message['text'] ?? '')), 'transcript' => $transcript, 'audio' => $audio];
    }

    /** Images sent with a message (screenshots…). @return array[] [resource, name] */
    public static function images_of(array $message){
        $out = [];
        foreach ( (array) ($message['attachment'] ?? []) as $a ) {
            $type = strtolower((string) ($a['contentType'] ?? ''));
            $resource = (string) ($a['attachmentDataRef']['resourceName'] ?? '');
            if ( $resource !== '' && strpos($type, 'image/') === 0 ) $out[] = ['resource' => $resource, 'name' => (string) ($a['contentName'] ?? '')];
        }
        return array_slice($out, 0, 5);
    }

    /** Keeps the structure (field names and types, no content) of the last voice message, to see what Google sends. */
    private static function note_shape(array $message){
        $shape = function($v) use (&$shape){
            if ( is_array($v) ) { $o = []; foreach ( $v as $k => $x ) $o[$k] = $shape($x); return $o; }
            return gettype($v);
        };
        $keep = $shape($message);
        foreach ( (array) ($message['attachment'] ?? []) as $i => $a ) $keep['attachment'][$i]['contentType'] = (string) ($a['contentType'] ?? '');
        update_option(self::SHAPE_OPTION, ['at' => time(), 'message' => $keep], false);
    }

    /** Text of a voice message: Google's transcript if there is one, else ours. @return string|\WP_Error */
    public static function text_of(ChatApi $api, array $voice){
        if ( $voice['transcript'] ) {
            $file = $api->download($voice['transcript'], 200000);
            if ( ! is_wp_error($file) && trim(wp_strip_all_tags($file['body'])) !== '' ) return trim(wp_strip_all_tags($file['body']));
        }
        if ( $voice['text'] !== '' ) return $voice['text'];
        return $voice['audio'] ? self::transcribe($api, $voice['audio']) : new \WP_Error('madof_stt_empty', __('No he oído nada en el audio. ¿Me lo repites?', 'mad-oficina'));
    }

    /** @return string|\WP_Error */
    public static function transcribe(ChatApi $api, $resource){
        $file = $api->download($resource, self::MAX_BYTES);
        if ( is_wp_error($file) ) return $file;
        $account = $api->account();
        $project = $account->project_id();
        if ( $project === '' ) return new \WP_Error('madof_stt_project', __('La clave del bot no dice de qué proyecto de Google Cloud es.', 'mad-oficina'));
        $token = $account->access_token(ServiceAccount::SCOPE_CLOUD);
        if ( is_wp_error($token) ) return $token;

        $url = 'https://speech.googleapis.com/v2/projects/' . rawurlencode($project) . '/locations/global/recognizers/_:recognize';
        $res = wp_remote_post($url, [
            'timeout' => 45,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode([
                'config'  => [
                    'autoDecodingConfig' => new \stdClass(), // m4a/aac, ogg/opus, mp3, wav…
                    'languageCodes'      => (array) apply_filters('madoficina_voice_languages', ['es-ES']),
                    'model'              => (string) apply_filters('madoficina_voice_model', 'long'),
                    'features'           => ['enableAutomaticPunctuation' => true],
                ],
                'content' => base64_encode($file['body']),
            ]),
        ]);
        if ( is_wp_error($res) ) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true) ?: [];
        if ( $code >= 300 ) {
            $msg = (string) ($data['error']['message'] ?? '');
            if ( $code === 403 ) {
                return new \WP_Error('madof_stt_off', sprintf(__('Para entender audios, en Google Cloud (proyecto %1$s) activa «Cloud Speech-to-Text API» y da a la cuenta de servicio %2$s el rol «Cloud Speech Client». Mientras, escríbemelo.', 'mad-oficina'), $project, $account->email()));
            }
            if ( $code === 400 && stripos($msg, 'duration') !== false ) {
                return new \WP_Error('madof_stt_long', sprintf(__('El audio dura más de %d segundos: mándamelo más corto o en dos partes.', 'mad-oficina'), self::MAX_SECONDS));
            }
            return new \WP_Error('madof_stt_http', sprintf(__('No he podido transcribir el audio (%1$d: %2$s).', 'mad-oficina'), $code, $msg));
        }
        $parts = [];
        foreach ( (array) ($data['results'] ?? []) as $r ) {
            $t = trim((string) ($r['alternatives'][0]['transcript'] ?? ''));
            if ( $t !== '' ) $parts[] = $t;
        }
        $text = trim(implode(' ', $parts));
        return $text !== '' ? $text : new \WP_Error('madof_stt_empty', __('No he oído nada en el audio. ¿Me lo repites?', 'mad-oficina'));
    }
}
