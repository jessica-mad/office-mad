<?php
namespace MAD_Oficina\Suppliers;

use MAD_Oficina\Billing\Money;
use MAD_Oficina\Core\Approvals;

if ( ! defined('ABSPATH') ) exit;

/**
 * Once a day: invoices that should have arrived and did not (asks Jessica with
 * quick options), early notice of yearly invoices, and on Mondays a summary of
 * what was registered. Everything here is internal: nothing goes to suppliers.
 */
final class Watch {

    const ACTION = 'missing_invoice';
    const STATE_OPTION = 'madoficina_missing_invoices';
    const DAY_OPTION = 'madoficina_suppliers_watch_day';
    const WINDOW = ['monthly' => 20, 'quarterly' => 45, 'yearly' => 60];

    private $settings;

    public function __construct(array $settings){
        $this->settings = $settings;
    }

    public function grace(){
        return max(1, min(30, (int) ($this->settings['grace_days'] ?? 5)));
    }

    public function run($now = null){
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(wp_timezone());
        $today = $local->format('Y-m-d');
        if ( (int) $local->format('G') < 9 || get_option(self::DAY_OPTION) === $today ) return [];
        update_option(self::DAY_OPTION, $today, false);
        $out = ['missing' => $this->missing($today), 'notices' => $this->notices($today)];
        if ( $local->format('N') === '1' && ! empty($this->settings['weekly'] ?? true) ) $out['weekly'] = $this->weekly($today);
        return $out;
    }

    /* ==== When an invoice is expected ==== */

    private static function date_in($year, $month, $day){
        $last = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
        return sprintf('%04d-%02d-%02d', $year, $month, min(max(1, (int) $day), $last));
    }

    /** Expected dates of a supplier around today (current and previous periods). @return string[] */
    public static function expected_dates(array $s, $today){
        $day = $s['expected_day'] ?: 1;
        $y = (int) substr($today, 0, 4); $m = (int) substr($today, 5, 2);
        $out = [];
        switch ( $s['periodicity'] ) {
            case 'monthly':
                foreach ( [0, -1] as $d ) { $t = gmmktime(0, 0, 0, $m + $d, 1, $y); $out[] = self::date_in((int) gmdate('Y', $t), (int) gmdate('n', $t), $day); }
                break;
            case 'quarterly':
                if ( ! $s['expected_month'] ) break;
                for ( $d = 0; $d >= -3; $d-- ) {
                    $t = gmmktime(0, 0, 0, $m + $d, 1, $y);
                    if ( ((int) gmdate('n', $t) - $s['expected_month']) % 3 === 0 ) $out[] = self::date_in((int) gmdate('Y', $t), (int) gmdate('n', $t), $day);
                }
                break;
            case 'yearly':
                if ( ! $s['expected_month'] ) break;
                $out[] = self::date_in($y, $s['expected_month'], $day);
                $out[] = self::date_in($y - 1, $s['expected_month'], $day);
                break;
        }
        return $out;
    }

    /** A registered invoice of this supplier around that date. */
    public static function arrived(array $s, $expected){
        global $wpdb;
        $w = self::WINDOW[$s['periodicity']] ?? 20;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Expenses::table() . " WHERE supplier_id = %d AND status = 'registered' AND issue_date BETWEEN %s AND %s LIMIT 1",
            $s['id'], gmdate('Y-m-d', strtotime($expected . " -{$w} days")), gmdate('Y-m-d', strtotime($expected . " +{$w} days"))
        ));
    }

    private static function state(){ $s = get_option(self::STATE_OPTION, []); return is_array($s) ? $s : []; }
    private static function set_state($key, array $v){ $s = self::state(); $s[$key] = $v; update_option(self::STATE_OPTION, $s, false); }

    /** @return int questions asked */
    public function missing($today){
        $n = 0;
        foreach ( Suppliers::all('active') as $s ) {
            foreach ( self::expected_dates($s, $today) as $exp ) {
                $due = gmdate('Y-m-d', strtotime($exp . ' +' . $this->grace() . ' days'));
                if ( $today < $due || $today > gmdate('Y-m-d', strtotime($exp . ' +60 days')) ) continue;
                if ( strtotime($exp) < strtotime($s['created_at']) - 5 * DAY_IN_SECONDS ) continue; // before we knew this supplier
                $key = $s['id'] . '|' . $exp;
                $st = self::state()[$key] ?? null;
                if ( $st && ( $st['state'] !== 'later' || $st['until'] > $today ) ) continue;
                if ( self::arrived($s, $exp) ) { self::set_state($key, ['state' => 'found']); continue; }
                self::set_state($key, ['state' => 'asked']);
                Approvals::create(Reader::AGENT, self::ACTION,
                    sprintf(__('No me ha llegado la factura de %1$s (%2$s)', 'mad-oficina'), $s['name'], self::period_label($s, $exp)),
                    ['supplier_id' => $s['id'], 'expected' => $exp],
                    ['object_type' => 'supplier', 'object_id' => $s['id'], 'summary' => trim(sprintf(__('La esperaba hacia el %s.', 'mad-oficina'), date_i18n('j/m', strtotime($exp))) . ($s['usual_amount'] ? ' ' . sprintf(__('Suele ser de %s.', 'mad-oficina'), Money::format($s['usual_amount'])) : '') . ' ' . __('La he buscado en tu Gmail y no está.', 'mad-oficina'))]);
                $n++;
            }
        }
        return $n;
    }

    public static function period_label(array $s, $exp){
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $m = $months[(int) substr($exp, 5, 2) - 1];
        return $s['periodicity'] === 'yearly' ? sprintf(__('la anual de %s', 'mad-oficina'), substr($exp, 0, 4)) : $m . ' ' . substr($exp, 0, 4);
    }

    /** Jessica's answer. $choice: forward | download | later | none | found */
    public static function answer(array $approval, $choice, $days = 7, $today = null){
        $p = $approval['payload'];
        $key = (int) $p['supplier_id'] . '|' . $p['expected'];
        $s = Suppliers::get((int) $p['supplier_id']);
        $today = $today ?: current_time('Y-m-d');
        switch ( $choice ) {
            case 'none':
                self::set_state($key, ['state' => 'none']);
                return ['message' => __('Apuntado: este periodo no hay factura.', 'mad-oficina')];
            case 'found':
                self::set_state($key, ['state' => 'found']);
                return ['message' => __('Ya ha llegado. ¡Todo en orden!', 'mad-oficina')];
            case 'forward':
            case 'download':
                self::set_state($key, ['state' => 'later', 'until' => gmdate('Y-m-d', strtotime($today . ' +3 days'))]);
                return ['message' => $choice === 'forward'
                    ? __('Perfecto: cuando me la reenvíes (o la subas), la registro. Si en 3 días no está, te vuelvo a preguntar.', 'mad-oficina')
                    : sprintf(__('Descárgala de %s y súbela en «Subir factura» (o reenvíatela). Si en 3 días no está, te vuelvo a preguntar.', 'mad-oficina'), $s['panel_url'] ?? __('su panel', 'mad-oficina'))];
            default:
                $days = max(1, min(60, (int) $days));
                self::set_state($key, ['state' => 'later', 'until' => gmdate('Y-m-d', strtotime($today . " +{$days} days"))]);
                return ['message' => sprintf(__('Te lo recuerdo el %s.', 'mad-oficina'), date_i18n('j/m', strtotime($today . " +{$days} days")))];
        }
    }

    /** A new invoice of a supplier closes its «no me ha llegado» questions. */
    public static function on_registered($expense_id){
        $e = Expenses::get($expense_id);
        if ( ! $e || ! $e['supplier_id'] ) return;
        foreach ( Approvals::for_object('supplier', $e['supplier_id']) as $a ) {
            if ( $a['action_type'] === self::ACTION && $a['status'] === Approvals::PENDING ) Approvals::resolve($a['id'], 'approve', ['choice' => 'found'], 0, 'auto');
        }
    }

    /* ==== Early notice and weekly summary ==== */

    public function notices($today){
        $n = 0;
        foreach ( Suppliers::all('active') as $s ) {
            if ( $s['periodicity'] !== 'yearly' || ! $s['expected_month'] ) continue;
            $exp = self::date_in((int) substr($today, 0, 4), $s['expected_month'], $s['expected_day'] ?: 1);
            $days = (strtotime($exp) - strtotime($today)) / DAY_IN_SECONDS;
            $key = 'notice|' . $s['id'] . '|' . $exp;
            if ( $days < 0 || $days > 7 || isset(self::state()[$key]) ) continue;
            self::set_state($key, ['state' => 'sent']);
            do_action('madoficina_notify_manager', sprintf(__('Esta semana debería llegarte la factura anual de %1$s%2$s.', 'mad-oficina'), $s['name'], $s['usual_amount'] ? ' (' . sprintf(__('el año pasado, %s', 'mad-oficina'), Money::format($s['usual_amount'])) . ')' : ''));
            $n++;
        }
        return $n;
    }

    public function weekly($today){
        global $wpdb;
        $t = Expenses::table();
        $since = gmdate('Y-m-d H:i:s', strtotime($today . ' -7 days'));
        $reg = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, SUM(total) AS total FROM {$t} WHERE status = 'registered' AND updated_at >= %s", $since), ARRAY_A);
        $review = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status = 'review'");
        $aside = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE status = 'not_mine' AND updated_at >= %s", $since));
        $up = $wpdb->get_col($wpdb->prepare("SELECT notes FROM {$t} WHERE reason = 'price_up' AND updated_at >= %s", $since));
        if ( ! (int) $reg['n'] && ! $review && ! $aside ) return 0;
        $lines = [sprintf(__('Gastos de la semana: %1$d facturas registradas (%2$s).', 'mad-oficina'), (int) $reg['n'], Money::format((float) $reg['total']))];
        if ( $review ) $lines[] = sprintf(_n('%d factura espera tu decisión en la bandeja.', '%d facturas esperan tu decisión en la bandeja.', $review, 'mad-oficina'), $review);
        if ( $aside ) $lines[] = sprintf(_n('%d factura a nombre de otros, guardada aparte.', '%d facturas a nombre de otros, guardadas aparte.', $aside, 'mad-oficina'), $aside);
        foreach ( $up as $note ) $lines[] = $note;
        do_action('madoficina_notify_manager', implode("\n", $lines) . "\n" . admin_url('admin.php?page=mad-oficina-gastos'));
        return 1;
    }

    /* ==== Inbox and Chat ==== */

    public static function render_form($html, array $approval){
        $s = Suppliers::get((int) ($approval['payload']['supplier_id'] ?? 0));
        ob_start(); ?>
        <?php if ( $s && $s['panel_url'] ) : ?><p><a href="<?php echo esc_url($s['panel_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Abrir su panel', 'mad-oficina'); ?></a></p><?php endif; ?>
        <?php foreach ( ['forward' => __('Te la reenvío', 'mad-oficina'), 'download' => __('La descargo de su panel', 'mad-oficina'), 'later' => __('Llegará más tarde: recuérdamelo en', 'mad-oficina'), 'none' => __('Este periodo no hay factura', 'mad-oficina')] as $k => $l ) : ?>
            <label class="madof-check"><input type="radio" name="input[choice]" value="<?php echo esc_attr($k); ?>" <?php checked($k, 'later'); ?>> <?php echo esc_html($l); ?>
                <?php if ( $k === 'later' ) : ?><input type="number" name="input[days]" value="7" min="1" max="60" style="width:70px"> <?php esc_html_e('días', 'mad-oficina'); ?><?php endif; ?></label>
        <?php endforeach; ?>
        <?php
        return ob_get_clean();
    }

    public static function chat_buttons(array $buttons, array $approval){
        if ( $approval['action_type'] !== self::ACTION ) return $buttons;
        $s = Suppliers::get((int) ($approval['payload']['supplier_id'] ?? 0));
        $b = [\MAD_Oficina\Chat\Cards::button(__('Te la reenvío', 'mad-oficina'), 'missing_answer', ['approval_id' => $approval['id'], 'choice' => 'forward'])];
        if ( $s && $s['panel_url'] ) $b[] = \MAD_Oficina\Chat\Cards::link(__('Descárgala del panel', 'mad-oficina'), $s['panel_url']);
        $b[] = \MAD_Oficina\Chat\Cards::button(__('Recuérdamelo en 7 días', 'mad-oficina'), 'missing_answer', ['approval_id' => $approval['id'], 'choice' => 'later']);
        $b[] = \MAD_Oficina\Chat\Cards::button(__('Este mes no hay', 'mad-oficina'), 'missing_answer', ['approval_id' => $approval['id'], 'choice' => 'none']);
        return $b;
    }

    public static function chat_action($out, $fn, array $params, $email, $is_manager){
        if ( $fn !== 'missing_answer' ) return $out;
        if ( ! $is_manager ) return __('Solo Jessica puede responder a esto.', 'mad-oficina');
        $a = Approvals::get((int) ($params['approval_id'] ?? 0));
        if ( ! $a ) return __('Esa pregunta ya no existe.', 'mad-oficina');
        $user = get_user_by('email', $email);
        $r = Approvals::resolve($a['id'], 'approve', ['choice' => sanitize_key($params['choice'] ?? 'later'), 'days' => 7], $user ? $user->ID : 0, 'chat');
        if ( is_wp_error($r) ) return $r->get_error_message();
        return \MAD_Oficina\Chat\Cards::resolved($a, $r['message']);
    }
}
