<?php
namespace MAD_Oficina\Chat;

use MAD_Oficina\Core\Agents\Characters;
use MAD_Oficina\Core\OfficeState;

if ( ! defined('ABSPATH') ) exit;

/**
 * Message builders. Cards (with buttons) only go to Jessica and Liz: external
 * users cannot click cards. Client messages are plain text with a clear
 * "automatic message" signature.
 */
final class Cards {

    const SIGNATURE = 'Mensaje automático de la oficina de MAD. Jessica lo ha revisado antes de enviarlo.';
    const SIGNATURE_AUTO = 'Mensaje automático de la oficina de MAD.';

    public static function button($text, $function, array $params){
        $p = [];
        foreach ( $params as $k => $v ) $p[] = ['key' => (string) $k, 'value' => (string) $v];
        return ['text' => $text, 'onClick' => ['action' => ['function' => $function, 'parameters' => $p]]];
    }

    public static function link($text, $url){
        return ['text' => $text, 'onClick' => ['openLink' => ['url' => $url]]];
    }

    public static function card($id, $title, $subtitle, array $widgets){
        return ['cardsV2' => [['cardId' => $id, 'card' => [
            'header'   => ['title' => $title, 'subtitle' => $subtitle],
            'sections' => [['widgets' => $widgets]],
        ]]]];
    }

    public static function who($agent){
        $slug = OfficeState::AGENT_TO_CHARACTER[$agent] ?? null;
        $c = $slug ? Characters::get($slug) : null;
        return $c ? $c['name'] . ' · ' . $c['role'] : 'MAD Oficina';
    }

    public static function approval(array $a, $inbox_url, array $extra_lines = []){
        $text = esc_html($a['summary'] ?: __('Tienes una petición nueva en la bandeja.', 'mad-oficina'));
        if ( $extra_lines ) $text .= '<br><br>' . implode('<br>', array_map('esc_html', $extra_lines));
        return self::card('approval-' . $a['id'], self::who($a['agent']), $a['title'], [
            ['textParagraph' => ['text' => $text]],
            ['buttonList' => ['buttons' => [
                self::button(__('Aprobar tal cual', 'mad-oficina'), 'approve', ['approval_id' => $a['id']]),
                self::link(__('Revisar en la web', 'mad-oficina'), $inbox_url . '#approval-' . $a['id']),
                self::button(__('Descartar', 'mad-oficina'), 'reject', ['approval_id' => $a['id']]),
            ]]],
        ]);
    }

    public static function resolved(array $a, $text){
        return self::card('approval-' . $a['id'], self::who($a['agent']), $a['title'], [
            ['decoratedText' => ['topLabel' => __('Resuelta', 'mad-oficina'), 'text' => esc_html($text), 'wrapText' => true]],
        ]);
    }

    public static function task_for_team(array $task, ?array $client, $url){
        $lines = [];
        if ( $task['due_date'] ) $lines[] = '<b>' . esc_html__('Fecha:', 'mad-oficina') . '</b> ' . esc_html(mysql2date('j M Y', $task['due_date']));
        if ( $task['description'] ) $lines[] = esc_html($task['description']);
        return self::card('task-' . $task['id'], ($client ? $client['code'] . ' · ' : '') . __('Nueva tarea', 'mad-oficina'), $task['title'], [
            ['textParagraph' => ['text' => $lines ? implode('<br><br>', $lines) : esc_html__('Sin más detalle.', 'mad-oficina')]],
            ['buttonList' => ['buttons' => [
                self::button(__('Hecha', 'mad-oficina'), 'task_done', ['task_id' => $task['id']]),
                self::link(__('Abrir la oficina', 'mad-oficina'), $url),
            ]]],
        ]);
    }

    public static function task_done(array $task){
        return self::card('task-' . $task['id'], __('Tarea hecha', 'mad-oficina'), $task['title'], [
            ['decoratedText' => ['text' => esc_html__('Marcada como hecha. ¡Gracias!', 'mad-oficina'), 'wrapText' => true]],
        ]);
    }

    /** Plain-text meeting summary for the client (Chat formatting: *bold*, _italic_). */
    public static function client_summary(array $meeting, array $client, array $decisions, array $client_tasks, array $mad_tasks, $portal_url){
        $date = $meeting['starts_at'] ? get_date_from_gmt($meeting['starts_at'], 'j \d\e F') : '';
        $out = '*' . sprintf(__('Resumen de la reunión%1$s · %2$s', 'mad-oficina'), $date ? ' ' . sprintf(__('del %s', 'mad-oficina'), $date) : '', $meeting['title']) . "*\n\n";
        $out .= trim((string) $meeting['summary']) . "\n";
        $list = function($title, array $items) use (&$out){
            if ( ! $items ) return;
            $out .= "\n*" . $title . "*\n";
            foreach ( $items as $i ) $out .= '• ' . $i . "\n";
        };
        $fmt = fn($t) => $t['title'] . ($t['due_date'] ? ' (' . mysql2date('j M', $t['due_date']) . ')' : '');
        $list(__('Acuerdos', 'mad-oficina'), $decisions);
        $list(__('Os toca a vosotros', 'mad-oficina'), array_map($fmt, $client_tasks));
        $list(__('Lo hacemos en MAD', 'mad-oficina'), array_map($fmt, $mad_tasks));
        if ( $meeting['next_meeting'] ) $out .= "\n*" . __('Próxima reunión:', 'mad-oficina') . '* ' . $meeting['next_meeting'] . "\n";
        if ( $portal_url ) $out .= "\n" . sprintf(__('Lo tenéis todo en vuestra oficina: %s', 'mad-oficina'), $portal_url) . "\n";
        $out .= "\n_" . __(self::SIGNATURE, 'mad-oficina') . '_';
        return $out;
    }
}
