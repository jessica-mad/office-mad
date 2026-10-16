<?php
namespace MAD_Oficina\Meetings;

if ( ! defined('ABSPATH') ) exit;

/**
 * What the cartero (the Apps Script) is doing, for Jessica: when it last came by, whether
 * its code is up to date, what it did in its last run and its latest errors.
 * Everything here comes from the script's signed requests.
 */
final class Cartero {

    const OPTION = 'madoficina_cartero';
    /** Code.gs version this plugin expects (apps-script/Code.gs → CARTERO_VERSION). */
    const VERSION = '2026-10-17';
    const RUN_MINUTES = 10;

    public static function get(){
        $s = get_option(self::OPTION, []);
        return is_array($s) ? $s : [];
    }

    /** Every verified request: it is alive (written at most every 30 s). */
    public static function seen(\WP_REST_Request $req){
        $s = self::get();
        $version = substr(preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $req->get_header('x_mad_version')), 0, 20);
        if ( ! empty($s['last_seen']) && time() - (int) $s['last_seen'] < 30 && ($s['version'] ?? '') === $version ) return;
        $s['last_seen'] = time();
        $s['version'] = $version;
        update_option(self::OPTION, $s, false);
    }

    /** The summary the script sends at the end of each run (with its tick). */
    public static function report($body){
        if ( ! is_array($body) || empty($body['report']) || ! is_array($body['report']) ) return;
        $r = $body['report'];
        $n = fn($v) => max(0, (int) $v);
        $s = self::get();
        $s['last_run'] = [
            'at' => time(),
            'seconds' => $n($r['seconds'] ?? 0),
            'meetings' => $n($r['meetings'] ?? 0),
            'gmail' => $n($r['gmail'] ?? 0),
            'drive' => is_array($r['drive'] ?? null) ? array_map($n, array_intersect_key($r['drive'], array_flip(['files', 'sent', 'pending', 'errors']))) : null,
            'orders_done' => $n($r['orders_done'] ?? 0),
            'orders_failed' => $n($r['orders_failed'] ?? 0),
        ];
        $errors = $s['errors'] ?? [];
        foreach ( array_slice((array) ($r['errors'] ?? []), 0, 10) as $e ) {
            $errors[] = ['at' => time(), 'msg' => mb_substr(sanitize_text_field((string) $e), 0, 300)];
        }
        $s['errors'] = array_slice($errors, -15);
        update_option(self::OPTION, $s, false);
    }

    /** @return array{state:string, minutes:?int, version_ok:bool, version:string, last_run:?array, errors:array, queued:int, failed:array} */
    public static function status(){
        global $wpdb;
        $s = self::get();
        $minutes = empty($s['last_seen']) ? null : (int) floor((time() - (int) $s['last_seen']) / 60);
        $state = $minutes === null ? 'never' : ($minutes <= self::RUN_MINUTES * 2 + 5 ? 'ok' : 'late');
        $t = \MAD_Oficina\Core\Schema::table('tasks');
        $queued = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE agent = %s AND status = 'queued'", Orders::AGENT));
        $failed = $wpdb->get_results($wpdb->prepare("SELECT type, result, updated_at FROM {$t} WHERE agent = %s AND status = 'failed' AND updated_at >= %s ORDER BY id DESC LIMIT 5", Orders::AGENT, gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)), ARRAY_A);
        return [
            'state' => $state, 'minutes' => $minutes,
            'version' => (string) ($s['version'] ?? ''), 'version_ok' => ($s['version'] ?? '') >= self::VERSION,
            'last_run' => $s['last_run'] ?? null, 'errors' => array_reverse($s['errors'] ?? []),
            'queued' => $queued, 'failed' => $failed,
        ];
    }

    private static function ago($ts){
        return sprintf(__('hace %s', 'mad-oficina'), human_time_diff((int) $ts));
    }

    /** The «El cartero» card (Gastos, Reuniones → Apps Script). */
    public static function render(){
        $st = self::status();
        $kinds = ['meeting_archive' => __('archivar una reunión', 'mad-oficina'), 'invoice_archive' => __('guardar y enviar una factura', 'mad-oficina'), 'invoice_test' => __('enviarte una prueba', 'mad-oficina'),
            'libro_upsert' => __('apuntar en el Libro MAD', 'mad-oficina'), 'libro_sync' => __('sincronizar el Libro MAD', 'mad-oficina'), 'expense_archive' => __('archivar un gasto', 'mad-oficina'),
            'expense_move' => __('mover un gasto en Drive', 'mad-oficina'), 'gmail_filter' => __('crear un filtro de Gmail', 'mad-oficina')];
        ob_start(); ?>
        <section class="madof-card madof-cartero">
            <span class="madof-kicker"><?php esc_html_e('El cartero (Apps Script)', 'mad-oficina'); ?></span>
            <p class="description"><?php esc_html_e('Es el script de tu Google que cada 10 minutos lleva y trae cosas entre tu Google y la oficina: reuniones de Meet, facturas de Gmail, archivos de tu Drive, y lo que apruebas (guardar en Drive, enviar facturas).', 'mad-oficina'); ?></p>
            <?php if ( $st['state'] === 'never' ) : ?>
                <p class="madof-warn"><?php esc_html_e('Todavía no ha pasado nunca. En Apps Script: pega el Code.gs, rellena MAD_ENDPOINT y MAD_SECRET (Reuniones → Apps Script y ajustes) y ejecuta «install».', 'mad-oficina'); ?></p>
            <?php elseif ( $st['state'] === 'late' ) : ?>
                <p class="madof-warn"><?php echo esc_html(sprintf(__('No pasa desde %s (debería pasar cada 10 minutos). En Apps Script → Activadores, comprueba que hay uno para «run» y mira en «Ejecuciones» si da error.', 'mad-oficina'), self::ago(time() - $st['minutes'] * 60))); ?></p>
            <?php else : ?>
                <p><strong><?php echo esc_html(sprintf(__('Funciona: pasó por última vez %s.', 'mad-oficina'), $st['minutes'] < 1 ? __('hace un momento', 'mad-oficina') : self::ago(time() - $st['minutes'] * 60))); ?></strong></p>
            <?php endif; ?>
            <?php if ( $st['state'] !== 'never' && ! $st['version_ok'] ) : ?>
                <p class="madof-warn"><?php echo esc_html(sprintf(__('Tiene un Code.gs antiguo%s. Copia el nuevo (apps-script/Code.gs del plugin), pégalo en Apps Script y guarda: sin él no importa tu archivo de Drive ni cuenta lo que hace.', 'mad-oficina'), $st['version'] ? ' (' . $st['version'] . ')' : '')); ?></p>
            <?php endif; ?>
            <?php if ( $r = $st['last_run'] ) : ?>
                <p><?php echo esc_html(sprintf(__('Última pasada (%1$s, %2$d s):', 'mad-oficina'), self::ago($r['at']), $r['seconds'])); ?></p>
                <ul class="madof-cartero-list">
                    <li><?php echo esc_html(sprintf(_n('%d reunión enviada', '%d reuniones enviadas', $r['meetings'], 'mad-oficina'), $r['meetings'])); ?></li>
                    <li><?php echo esc_html(sprintf(_n('%d factura recogida de Gmail', '%d facturas recogidas de Gmail', $r['gmail'], 'mad-oficina'), $r['gmail'])); ?></li>
                    <?php if ( $r['drive'] ) : ?><li><?php echo esc_html(sprintf(__('Archivo de Drive: %1$d archivos en las carpetas, %2$d enviados en esta pasada, %3$d por enviar, %4$d con error', 'mad-oficina'), $r['drive']['files'] ?? 0, $r['drive']['sent'] ?? 0, $r['drive']['pending'] ?? 0, $r['drive']['errors'] ?? 0)); ?></li><?php endif; ?>
                    <li><?php echo esc_html(sprintf(__('%1$d encargos hechos, %2$d fallidos', 'mad-oficina'), $r['orders_done'], $r['orders_failed'])); ?></li>
                </ul>
            <?php endif; ?>
            <p><?php echo esc_html($st['queued'] ? sprintf(_n('Tiene %d encargo esperando para la próxima pasada.', 'Tiene %d encargos esperando para la próxima pasada.', $st['queued'], 'mad-oficina'), $st['queued']) : __('No tiene encargos pendientes.', 'mad-oficina')); ?></p>
            <?php if ( $st['failed'] || $st['errors'] ) : ?>
                <details><summary><?php esc_html_e('Errores recientes', 'mad-oficina'); ?></summary>
                    <ul class="madof-cartero-list">
                    <?php foreach ( $st['failed'] as $f ) : $res = json_decode((string) $f['result'], true) ?: []; ?>
                        <li><?php echo esc_html(sprintf(__('No pudo %1$s: %2$s', 'mad-oficina'), $kinds[$f['type']] ?? $f['type'], $res['error'] ?? '—')); ?> <small class="madof-muted">(<?php echo esc_html(mysql2date('j M H:i', get_date_from_gmt($f['updated_at']))); ?>)</small></li>
                    <?php endforeach; ?>
                    <?php foreach ( array_slice($st['errors'], 0, 8) as $e ) : ?>
                        <li><?php echo esc_html($e['msg']); ?> <small class="madof-muted">(<?php echo esc_html(self::ago($e['at'])); ?>)</small></li>
                    <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }
}
