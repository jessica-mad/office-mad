<?php
namespace MAD_Oficina\Chat;

if ( ! defined('ABSPATH') ) exit;

/**
 * Voice messages → text, with Google Cloud Speech-to-Text v2 and the bot's own
 * service account (no new keys). Claude does not take audio, so this step is Google's.
 *
 * Needs, once, in the bot's Google Cloud project: the «Cloud Speech-to-Text API»
 * enabled and the service account with the «Cloud Speech Client» role.
 * Google takes one minute per request: longer m4a notes (up to 10) go in equal pieces.
 */
final class Transcriber {

    const MAX_BYTES = 10 * 1024 * 1024;
    const MAX_SECONDS = 600; // up to 10 minutes, in pieces
    const PIECE_SECONDS = 55; // Google's synchronous limit is one minute
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

    /** Google's ways of recognising, tried in order until one takes the audio; the one that worked goes first next time. */
    const ATTEMPTS = [
        // Chat's voice notes are AAC in an m4a box, which automatic detection reads as empty: said explicitly.
        // Opus in an m4a box: rewrapped as Ogg Opus (Mp4Audio), which Google always takes.
        'long_ogg'    => ['location' => 'global', 'model' => 'long', 'for' => 'opus'],
        'chirp_2_ogg' => ['location' => 'europe-west4', 'model' => 'chirp_2', 'for' => 'opus'],
        'long_m4a'    => ['location' => 'global', 'model' => 'long', 'encoding' => 'M4A_AAC', 'for' => 'm4a'],
        'chirp_3_m4a' => ['location' => 'eu', 'model' => 'chirp_3', 'encoding' => 'M4A_AAC', 'for' => 'm4a'],
        'chirp_3'     => ['location' => 'eu', 'model' => 'chirp_3', 'for' => 'm4a'],
        'chirp_2_m4a' => ['location' => 'europe-west4', 'model' => 'chirp_2', 'encoding' => 'M4A_AAC', 'for' => 'm4a'],
        'long_mp4'    => ['location' => 'global', 'model' => 'long', 'encoding' => 'MP4_AAC', 'for' => 'm4a'],
        'long'        => ['location' => 'global', 'model' => 'long'],
        'short'       => ['location' => 'global', 'model' => 'short'],
        'chirp_2'     => ['location' => 'europe-west4', 'model' => 'chirp_2'],
    ];
    const WORKED = 'madoficina_voice_worked';
    const LAST = 'madoficina_voice_last';

    /** What the audio really is, by its first bytes (the content type Chat gives may say little). */
    public static function container($bytes){
        $head = substr((string) $bytes, 0, 16);
        if ( substr($head, 4, 4) === 'ftyp' ) {
            $m = new Mp4Audio($bytes);
            $aac = $m->codec() === 'mp4a' ? $m->aac_profile() : '';
            return 'm4a/mp4 (' . ($m->codec() ?: '¿?') . ($aac ? ', ' . $aac : '') . ($m->fragmented() ? ', fragmentado' : '') . ')';
        }
        if ( substr($head, 0, 4) === 'OggS' ) return 'ogg (opus)';
        if ( substr($head, 0, 4) === "\x1A\x45\xDF\xA3" ) return 'webm';
        if ( substr($head, 0, 4) === 'RIFF' ) return 'wav';
        if ( substr($head, 0, 3) === 'ID3' || (ord($head[0] ?? "\0") === 0xFF && (ord($head[1] ?? "\0") & 0xE0) === 0xE0) ) return 'mp3';
        if ( substr($head, 0, 5) === '#!AMR' ) return 'amr';
        if ( substr($head, 0, 4) === 'fLaC' ) return 'flac';
        return 'desconocido (' . bin2hex(substr($head, 0, 8)) . ')';
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

        $audio = self::container($file['body']);
        // Phones save the index after the sound; Google reads in chunks and finds «no audio» once
        // the note is longer than a few seconds. Index first, same sound.
        if ( str_starts_with($audio, 'm4a') && ( $fast = (new Mp4Audio($file['body']))->faststart() ) ) {
            $file['body'] = $fast;
            $audio = rtrim($audio, ')') . ', índice movido al principio)';
        }
        $about = sprintf('%s · %s · %d KB', $audio, $file['type'] ?: '—', (int) ceil(strlen($file['body']) / 1024));
        $is_m4a = str_starts_with($audio, 'm4a');
        $ogg = $is_m4a && str_contains($audio, '(Opus') ? (new Mp4Audio($file['body']))->opus_to_ogg() : null;
        $params = $is_m4a ? self::mp4_audio($file['body']) : [];
        $tried = [];
        $fail = function($error, $keep = false) use (&$tried, $about, $file){
            update_option(self::LAST, ['at' => time(), 'ok' => false, 'audio' => $about, 'tried' => $tried] + ($keep ? ['kept' => self::keep_failed($file['body'])] : []), false);
            return $error;
        };

        // Google takes one minute per request: a longer note goes in equal pieces, read one after the other.
        $pieces = [$file['body']];
        if ( $is_m4a && ! $ogg ) {
            $m = new Mp4Audio($file['body']);
            if ( $m->duration() > self::MAX_SECONDS ) return $fail(new \WP_Error('madof_stt_long', sprintf(__('El audio dura más de %d minutos: mándamelo en partes más cortas.', 'mad-oficina'), self::MAX_SECONDS / 60)));
            if ( $m->duration() > self::PIECE_SECONDS ) {
                $pieces = $m->split(self::PIECE_SECONDS);
                $about .= ' · ' . sprintf(_n('%d parte', '%d partes', count($pieces), 'mad-oficina'), count($pieces));
            }
        }
        $texts = []; $how = '';
        foreach ( $pieces as $i => $piece ) {
            $r = self::one($token, $project, $piece, $is_m4a, $ogg, $params, $tried, count($pieces) > 1 ? 'parte ' . ($i + 1) . ' · ' : '');
            if ( is_wp_error($r) ) {
                if ( $r->get_error_code() === 'madof_stt_empty' && count($pieces) > 1 ) continue; // a silent stretch
                if ( $r->get_error_code() === 'madof_stt_long' ) return $fail($r);
                if ( $r->get_error_code() === 'madof_stt_forbidden' ) return $fail(new \WP_Error('madof_stt_off', sprintf(__('Para entender audios, en Google Cloud (proyecto %1$s) activa «Cloud Speech-to-Text API» y da a la cuenta de servicio %2$s el rol «Cloud Speech Client». Mientras, escríbemelo.', 'mad-oficina'), $project, $account->email())));
                // Last resort: the server converts the audio to ogg/opus (Google always takes it), if it has ffmpeg.
                if ( count($pieces) === 1 && $tried && ! str_starts_with($audio, 'ogg') && ( $conv = self::to_ogg($piece) ) ) {
                    $r = self::recognize($token, $project, self::ATTEMPTS['long'], $conv);
                    if ( ! is_wp_error($r) ) { update_option(self::LAST, ['at' => time(), 'ok' => true, 'how' => 'long (convertido a ogg)', 'audio' => $about], false); return $r; }
                    $tried[] = 'ogg: ' . (int) ($r->get_error_data()['status'] ?? 0) . ' ' . $r->get_error_message();
                }
                return $fail(new \WP_Error('madof_stt_http', sprintf(__('No he podido transcribir el audio (formato %1$s). Google dice: %2$s. Mientras lo miramos, escríbemelo; el detalle está en Chat → ajustes.', 'mad-oficina'),
                    $audio, $tried ? preg_replace('/^(parte \d+ · )?[a-z_0-9]+: /', '', end($tried)) : '—')), true);
            }
            $texts[] = $r;
            $how = (string) get_option(self::WORKED, '');
        }
        if ( ! $texts ) return $fail(new \WP_Error('madof_stt_empty', __('No he oído nada en el audio. ¿Me lo repites?', 'mad-oficina')), true);
        update_option(self::LAST, ['at' => time(), 'ok' => true, 'how' => $how, 'audio' => $about], false);
        return implode(' ', $texts);
    }

    /** One piece through Google's ways, the one that worked last time first. @return string|\WP_Error */
    private static function one($token, $project, $body, $is_m4a, $ogg, array $params, array &$tried, $label = ''){
        $order = array_keys(self::ATTEMPTS);
        $worked = (string) get_option(self::WORKED, '');
        if ( isset(self::ATTEMPTS[$worked]) ) $order = array_values(array_unique(array_merge([$worked], $order)));
        $order = (array) apply_filters('madoficina_voice_attempts', $order);
        $last = null; $only_silence = true;
        foreach ( $order as $key ) {
            $a = self::ATTEMPTS[$key] ?? null;
            $for = $a['for'] ?? '';
            if ( ! $a || ( $for === 'm4a' && ( ! $is_m4a || $ogg ) ) || ( $for === 'opus' && ! $ogg ) ) continue;
            $r = self::recognize($token, $project, $a + ($for === 'm4a' ? $params : []), $for === 'opus' ? $ogg : $body);
            if ( ! is_wp_error($r) ) { update_option(self::WORKED, $key, false); return $r; }
            $code = (int) ($r->get_error_data()['status'] ?? 0);
            $tried[] = $label . $key . ': ' . $code . ' ' . $r->get_error_message();
            $last = $r;
            if ( $code !== 200 ) $only_silence = false; // 200 with no words = it was read and nobody spoke
            if ( $code === 403 ) return new \WP_Error('madof_stt_forbidden', $r->get_error_message());
            if ( $code === 400 && stripos($r->get_error_message(), 'duration') !== false ) return new \WP_Error('madof_stt_long', sprintf(__('El audio dura más de un minuto y en este formato no lo puedo partir: mándamelo más corto o en dos partes.', 'mad-oficina')));
            if ( in_array($code, [401, 429], true) || $code >= 500 ) break; // not about the audio or the model: no point in trying others
        }
        if ( $last && $only_silence ) return new \WP_Error('madof_stt_empty', $last->get_error_message());
        return $last ?: new \WP_Error('madof_stt_http', '—');
    }

    /** The last audio Google could not read, kept privately so it can be looked at (one at a time, a week). */
    private static function keep_failed($bytes){
        $up = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'mad-oficina/voice';
        if ( ! is_dir($dir) ) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        foreach ( glob($dir . '/failed-*') ?: [] as $old ) @unlink($old);
        $name = 'failed-' . wp_generate_password(20, false) . '.m4a';
        return file_put_contents($dir . '/' . $name, $bytes) ? $name : '';
    }

    /** admin-post: download that audio (office only). */
    public static function serve_failed(){
        if ( ! current_user_can(\MAD_Oficina_Core::CAPABILITY) ) wp_die(esc_html__('No tienes permisos suficientes.', 'mad-oficina'), 403);
        check_admin_referer('madof_voice_failed');
        $last = get_option(self::LAST);
        $up = wp_upload_dir();
        $path = trailingslashit($up['basedir']) . 'mad-oficina/voice/' . basename((string) ($last['kept'] ?? ''));
        if ( empty($last['kept']) || ! is_readable($path) || (int) $last['at'] < time() - WEEK_IN_SECONDS ) wp_die(esc_html__('Ese audio ya no está.', 'mad-oficina'), 404);
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="audio-que-fallo.m4a"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /** Sample rate and channels of the AAC track of an m4a, read from its «mp4a» entry. @return array{rate?:int, channels?:int} */
    public static function mp4_audio($bytes){
        $p = strpos((string) $bytes, 'mp4a');
        if ( $p === false || strlen($bytes) < $p + 32 ) return [];
        $entry = substr($bytes, $p + 4, 28); // 6 reserved, 2 data ref, 8 reserved, 2 channels, 2 sample size, 4 pre-defined, 4 rate (16.16)
        $channels = unpack('n', substr($entry, 16, 2))[1];
        $rate = unpack('N', substr($entry, 24, 4))[1] >> 16;
        $out = [];
        if ( $rate >= 8000 && $rate <= 96000 ) $out['rate'] = $rate;
        if ( $channels >= 1 && $channels <= 8 ) $out['channels'] = $channels;
        return $out;
    }

    /** @return string|null the audio as ogg/opus, when the server has ffmpeg */
    public static function to_ogg($bytes){
        $bin = (string) apply_filters('madoficina_ffmpeg', '');
        if ( $bin === '' && function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) ) {
            @exec('command -v ffmpeg 2>/dev/null', $out);
            $bin = trim((string) ($out[0] ?? ''));
        }
        if ( $bin === '' || ! is_executable($bin) ) return null;
        $in = wp_tempnam('madof-voice'); $out_file = $in . '.ogg';
        file_put_contents($in, $bytes);
        @exec(escapeshellarg($bin) . ' -y -loglevel error -i ' . escapeshellarg($in) . ' -ac 1 -ar 16000 -c:a libopus ' . escapeshellarg($out_file) . ' 2>/dev/null', $o, $code);
        $ogg = $code === 0 && is_readable($out_file) ? (string) file_get_contents($out_file) : '';
        @unlink($in); @unlink($out_file);
        return $ogg !== '' ? $ogg : null;
    }

    /** One recognition request. @return string|\WP_Error the text */
    private static function recognize($token, $project, array $a, $bytes){
        $host = $a['location'] === 'global' ? 'speech.googleapis.com' : $a['location'] . '-speech.googleapis.com';
        $url = 'https://' . $host . '/v2/projects/' . rawurlencode($project) . '/locations/' . $a['location'] . '/recognizers/_:recognize';
        $res = wp_remote_post($url, [
            'timeout' => 45,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode([
                'config'  => ( empty($a['encoding'])
                    ? ['autoDecodingConfig' => new \stdClass()] // ogg/opus, mp3, wav, flac…
                    : ['explicitDecodingConfig' => array_filter(['encoding' => $a['encoding'], 'sampleRateHertz' => $a['rate'] ?? null, 'audioChannelCount' => $a['channels'] ?? null])] ) + [
                    'languageCodes'      => (array) apply_filters('madoficina_voice_languages', ['es-ES']),
                    'model'              => $a['model'],
                    'features'           => ['enableAutomaticPunctuation' => true],
                ],
                'content' => base64_encode($bytes),
            ]),
        ]);
        if ( is_wp_error($res) ) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true) ?: [];
        if ( $code >= 300 ) return new \WP_Error('madof_stt_http', mb_substr((string) ($data['error']['message'] ?? wp_remote_retrieve_response_message($res)), 0, 220), ['status' => $code]);
        $parts = [];
        foreach ( (array) ($data['results'] ?? []) as $r ) {
            $t = trim((string) ($r['alternatives'][0]['transcript'] ?? ''));
            if ( $t !== '' ) $parts[] = $t;
        }
        $text = trim(implode(' ', $parts));
        return $text !== '' ? $text : new \WP_Error('madof_stt_empty', __('No he oído nada en el audio. ¿Me lo repites?', 'mad-oficina'), ['status' => 200]);
    }
}
