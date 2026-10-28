<?php
namespace MAD_Oficina\Billing;

use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\ClientCopy;

if ( ! defined('ABSPATH') ) exit;

/**
 * Cobros: when an issued invoice (Argentina included) reaches its due date and is not
 * marked as collected, Jessica is asked in Chat: «Ya está cobrada» · «Recordárselo al
 * cliente» (a friendly email she approves) · «Pregúntame en 7 días». If nobody answers,
 * it asks again a week later. Collected invoices (by hand or by the bank) stop asking.
 */
final class Collections {

    const ASKED = 'madoficina_collections_asked';   // invoice id => next day to ask
    const DAY = 'madoficina_collections_day';
    const ACTION = 'payment_reminder';
    const AGAIN_DAYS = 7;

    private $settings;

    public function __construct(array $settings){ $this->settings = $settings; }

    private static function asked(){ $a = get_option(self::ASKED, []); return is_array($a) ? $a : []; }

    public static function next_ask($invoice_id, $date){
        $a = self::asked();
        $a[(int) $invoice_id] = $date;
        update_option(self::ASKED, $a, false);
    }

    /** The due date (or, without one, the issue date plus the usual days to pay). */
    public function due(array $inv){
        return $inv['due_date'] ?: gmdate('Y-m-d', strtotime($inv['issue_date'] . ' +' . max(0, (int) ($this->settings['payment_days'] ?? 15)) . ' days'));
    }

    /** Once a day: invoices due and not collected. @return int asked */
    public function run($today = null){
        global $wpdb;
        $today = $today ?: current_time('Y-m-d');
        if ( get_option(self::DAY) === $today ) return 0;
        update_option(self::DAY, $today, false);
        $rows = $wpdb->get_results("SELECT * FROM " . Invoices::table() . " WHERE status IN ('issued','sent') AND paid = 0 AND total > 0 AND (number IS NOT NULL OR series = 'AR') ORDER BY issue_date", ARRAY_A);
        $asked = self::asked();
        foreach ( array_keys($asked) as $id ) if ( ! in_array($id, array_map('intval', array_column($rows, 'id')), true) ) unset($asked[$id]); // collected meanwhile
        update_option(self::ASKED, $asked, false);
        $n = 0;
        foreach ( $rows as $inv ) {
            $due = $this->due($inv);
            if ( $due > $today ) continue;
            if ( isset($asked[(int) $inv['id']]) && $asked[(int) $inv['id']] > $today ) continue;
            if ( ! $this->ask($inv, $due, $today) ) break; // Chat down: try tomorrow
            self::next_ask($inv['id'], gmdate('Y-m-d', strtotime($today . ' +' . self::AGAIN_DAYS . ' days')));
            $n++;
        }
        return $n;
    }

    public static function card(array $inv, $due, $today){
        $when = $due === $today ? __('vence hoy', 'mad-oficina') : sprintf(__('venció el %s', 'mad-oficina'), date_i18n('j/m', strtotime($due)));
        $text = sprintf(__('La factura %1$s de %2$s (%3$s) %4$s y no consta cobrada.', 'mad-oficina'), $inv['number'] ?: __('de Argentina', 'mad-oficina'), $inv['client_name'], Money::format($inv['total']) . ($inv['currency'] !== 'EUR' ? ' ' . $inv['currency'] : ''), $when);
        $buttons = [\MAD_Oficina\Chat\Cards::button(__('Ya está cobrada', 'mad-oficina'), 'collect_paid', ['invoice_id' => $inv['id']])];
        if ( $inv['client_email'] ) $buttons[] = \MAD_Oficina\Chat\Cards::button(__('Recordárselo al cliente', 'mad-oficina'), 'collect_remind', ['invoice_id' => $inv['id']]);
        $buttons[] = \MAD_Oficina\Chat\Cards::button(sprintf(__('Pregúntame en %d días', 'mad-oficina'), self::AGAIN_DAYS), 'collect_later', ['invoice_id' => $inv['id']]);
        $buttons[] = \MAD_Oficina\Chat\Cards::link(__('Ver factura', 'mad-oficina'), admin_url('admin.php?page=mad-oficina-facturacion&invoice=' . (int) $inv['id']));
        return \MAD_Oficina\Chat\Cards::card('collect-' . $inv['id'], __('Cobros', 'mad-oficina'), $inv['client_name'], [
            ['textParagraph' => ['text' => esc_html($text)]],
            ['buttonList' => ['buttons' => $buttons]],
        ]);
    }

    private function ask(array $inv, $due, $today){
        $chat = \MAD_Oficina_Core::instance()->get_module('chat');
        if ( ! $chat ) return false;
        $r = $chat->messenger()->to_manager(self::card($inv, $due, $today));
        return ! is_wp_error($r);
    }

    /* ───────── answers from Chat ───────── */

    /** @return array|string|null */
    public static function chat_action($out, $fn, array $params, $email, $is_manager){
        if ( ! in_array($fn, ['collect_paid', 'collect_remind', 'collect_later'], true) ) return $out;
        if ( ! $is_manager ) return __('Solo Jessica puede decidir sobre los cobros.', 'mad-oficina');
        $inv = Invoices::get((int) ($params['invoice_id'] ?? 0));
        if ( ! $inv ) return __('Esa factura ya no existe.', 'mad-oficina');
        if ( $fn === 'collect_paid' ) {
            Invoices::set_paid($inv['id'], true);
            Issuing::queue_libro($inv['id']);
            $msg = __('Marcada como cobrada.', 'mad-oficina');
        } elseif ( $fn === 'collect_later' ) {
            self::next_ask($inv['id'], gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +' . self::AGAIN_DAYS . ' days')));
            $msg = sprintf(__('Te vuelvo a preguntar el %s.', 'mad-oficina'), date_i18n('j/m', strtotime('+' . self::AGAIN_DAYS . ' days', current_time('timestamp'))));
        } else {
            $r = self::propose_reminder($inv);
            $msg = is_wp_error($r) ? $r->get_error_message() : __('Te he dejado el recordatorio en la bandeja para que lo revises antes de que salga.', 'mad-oficina');
        }
        return \MAD_Oficina\Chat\Cards::card('collect-' . $inv['id'], __('Cobros', 'mad-oficina'), $inv['client_name'], [
            ['decoratedText' => ['topLabel' => __('Hecho', 'mad-oficina'), 'text' => esc_html($msg), 'wrapText' => true]],
        ]);
    }

    /** The friendly email, to approve in the inbox (from her Gmail, with the invoice attached). @return int|\WP_Error approval id */
    public static function propose_reminder(array $inv){
        if ( ! $inv['client_email'] ) return new \WP_Error('madof_collect_email', __('Esta factura no tiene email del cliente.', 'mad-oficina'));
        foreach ( Approvals::for_object('invoice', $inv['id']) as $a ) {
            if ( $a['action_type'] === self::ACTION && $a['status'] === Approvals::PENDING ) return (int) $a['id'];
        }
        $to = '';
        foreach ( preg_split('/[\s,;]+/', (string) $inv['client_email'], -1, PREG_SPLIT_NO_EMPTY) as $e ) if ( is_email($e) ) { $to = strtolower($e); break; }
        $number = $inv['number'] ?: __('de este mes', 'mad-oficina');
        $body = sprintf(__("Hola:\n\nTe escribo por la factura %1\$s, de %2\$s, con vencimiento el %3\$s. Todavía no nos consta el pago; ¿puedes revisarlo, por favor? Te la adjunto de nuevo.\n\nSi ya lo habéis hecho, ignora este mensaje y perdona la molestia.\n\nUn abrazo,\nJessica\nMAD · Marketing & Ads", 'mad-oficina'),
            $number, Money::format($inv['total']) . ($inv['currency'] !== 'EUR' ? ' ' . $inv['currency'] : ''), date_i18n('j \d\e F', strtotime((new self(get_option(\MAD_Oficina_Core::option_key('billing'), [])))->due($inv))));
        return Approvals::create('accountant', self::ACTION, sprintf(__('Recordar el pago de %1$s a %2$s', 'mad-oficina'), $number, $inv['client_name']),
            ['invoice_id' => (int) $inv['id'], 'to' => $to, 'subject' => sprintf(__('Factura %s pendiente de pago', 'mad-oficina'), $number), 'body' => $body],
            ['client_id' => $inv['client_id'], 'object_type' => 'invoice', 'object_id' => $inv['id'], 'summary' => $body]);
    }

    /** @return array|\WP_Error */
    public static function approve(array $approval, array $input){
        $p = $approval['payload'];
        $inv = Invoices::get((int) $p['invoice_id']);
        if ( ! $inv ) return new \WP_Error('madof_inv', __('Esa factura ya no existe.', 'mad-oficina'));
        if ( $inv['paid'] ) return ['message' => __('Ya estaba cobrada: no envío nada.', 'mad-oficina')];
        $to = sanitize_email($input['to'] ?? '') ?: $p['to'];
        if ( ! is_email($to) ) return new \WP_Error('madof_collect_email', __('Escribe un email válido.', 'mad-oficina'));
        $email = ['to' => $to, 'subject' => sanitize_text_field($input['subject'] ?? '') ?: $p['subject'], 'body' => sanitize_textarea_field($input['body'] ?? '') ?: $p['body']];
        if ( $copy = ClientCopy::for_invoice($inv) ) $email['bcc'] = $copy;
        \MAD_Oficina\Meetings\Orders::queue(self::ACTION, ['key' => $approval['id'], 'invoice_id' => $inv['number'] && $inv['series'] !== 'AR' ? (int) $inv['id'] : 0, 'email' => $email], $inv['client_id']);
        self::next_ask($inv['id'], gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +' . self::AGAIN_DAYS . ' days')));
        return ['message' => sprintf(__('El cartero enviará el recordatorio a %s desde tu Gmail en unos minutos.', 'mad-oficina'), $to)];
    }

    public static function render_form($html, array $approval){
        $p = $approval['payload'];
        ob_start(); ?>
        <label><?php esc_html_e('Para', 'mad-oficina'); ?><input type="email" name="input[to]" value="<?php echo esc_attr($p['to']); ?>"></label>
        <label><?php esc_html_e('Asunto', 'mad-oficina'); ?><input type="text" name="input[subject]" value="<?php echo esc_attr($p['subject']); ?>"></label>
        <label><?php esc_html_e('Mensaje', 'mad-oficina'); ?><textarea name="input[body]" rows="9"><?php echo esc_textarea($p['body']); ?></textarea></label>
        <p class="description"><?php esc_html_e('Sale desde tu Gmail con la factura adjunta (y tu copia oculta, si la tienes activada).', 'mad-oficina'); ?></p>
        <?php return ob_get_clean();
    }
}
