<?php
namespace MAD_Oficina\Meetings;

if ( ! defined('ABSPATH') ) exit;

/**
 * Parses a WhatsApp "Export chat" .txt (Android or iPhone, Spanish or English).
 *   Android: 07/10/26, 17:57 - Rocío: texto
 *   iPhone:  [07/10/26, 17:57:12] Rocío: texto
 * Lines without a timestamp continue the previous message.
 */
final class WhatsApp {

    const LINE_RE = '#^\[?(\d{1,2})/(\d{1,2})/(\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::\d{2})?\s*(?:[ap]\.?\s?m\.?)?\]?\s*(?:-\s*)?([^:]{1,60}):\s(.*)$#iu';

    /**
     * @return array{messages: array<int,array{at:string,author:string,text:string}>, people: string[], from: ?string, to: ?string}
     */
    public static function parse($text, $since = null){
        $text = str_replace(["\r\n", "\r", "\u{200E}", "\u{200F}"], ["\n", "\n", '', ''], (string) $text);
        $messages = [];
        foreach ( explode("\n", $text) as $line ){
            if ( preg_match(self::LINE_RE, $line, $m) ) {
                $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
                $at = sprintf('%04d-%02d-%02d %02d:%02d', $year, (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5]);
                $messages[] = ['at' => $at, 'author' => trim($m[6]), 'text' => trim($m[7])];
            } elseif ( $messages && trim($line) !== '' ) {
                $messages[count($messages) - 1]['text'] .= "\n" . trim($line);
            }
        }
        $messages = array_values(array_filter($messages, function($msg) use ($since){
            if ( $since && substr($msg['at'], 0, 10) < $since ) return false;
            return ! preg_match('/^<(Multimedia omitido|Media omitted)>$|^(Este mensaje fue eliminado|This message was deleted)$/iu', $msg['text']);
        }));
        return [
            'messages' => $messages,
            'people'   => array_values(array_unique(array_column($messages, 'author'))),
            'from'     => $messages[0]['at'] ?? null,
            'to'       => $messages ? end($messages)['at'] : null,
        ];
    }

    /** Compact transcript for the secretary. */
    public static function to_transcript(array $parsed){
        $out = '';
        foreach ( $parsed['messages'] as $m ) $out .= '[' . $m['at'] . '] ' . $m['author'] . ': ' . $m['text'] . "\n";
        return $out;
    }
}
