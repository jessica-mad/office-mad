<?php
/**
 * @var \MAD_Oficina\Followup\Coordinator $coordinator @var array $ideas @var array $log @var int|false $next
 */
use MAD_Oficina\Core\AdminUi;

if ( ! defined('ABSPATH') ) exit;
$jobs = [
    'reminders'        => __('Recordatorios para ti y Liz', 'mad-oficina'),
    'client_reminders' => __('Recordatorios a clientes (a la bandeja)', 'mad-oficina'),
    'ideas'            => __('Ideas que vuelven', 'mad-oficina'),
    'briefs'           => __('Fichas de reunión', 'mad-oficina'),
    'weekly'           => __('Resumen semanal', 'mad-oficina'),
];
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Sala de proyectos', 'mad-oficina'), __('Seguimiento', 'mad-oficina'), __('La Coordinadora vigila fechas y te escribe por Chat. A los clientes no les llega nada sin pasar por tu bandeja (salvo que le des autonomía).', 'mad-oficina')); ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_followup_settings'); ?>
        <input type="hidden" name="action" value="madof_followup_settings">
        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Qué hace y cuándo', 'mad-oficina'); ?></span>
            <label class="madof-check"><input type="checkbox" name="reminders" value="1" <?php checked($coordinator->setting('reminders')); ?>> <?php esc_html_e('Cada mañana, recordatorio por Chat de las tareas que vencen pronto o están vencidas (a ti y a cada persona del equipo).', 'mad-oficina'); ?></label>
            <label class="madof-check"><input type="checkbox" name="client_reminders" value="1" <?php checked($coordinator->setting('client_reminders')); ?>> <?php esc_html_e('Proponer recordatorios a clientes por sus tareas pendientes (te llegan a la bandeja).', 'mad-oficina'); ?></label>
            <label class="madof-check"><input type="checkbox" name="ideas" value="1" <?php checked($coordinator->setting('ideas')); ?>> <?php esc_html_e('Traerte las ideas cuando llega su fecha, con botones para convertirla en tarea, posponerla o descartarla.', 'mad-oficina'); ?></label>
            <label class="madof-check"><input type="checkbox" name="briefs" value="1" <?php checked($coordinator->setting('briefs')); ?>> <?php esc_html_e('Ficha de cada reunión con cliente en las 24 h previas (tareas, temas pendientes, ideas).', 'mad-oficina'); ?></label>
            <label class="madof-check"><input type="checkbox" name="weekly" value="1" <?php checked($coordinator->setting('weekly')); ?>> <?php esc_html_e('Resumen de la semana los lunes a las 8:45.', 'mad-oficina'); ?></label>
            <div class="madof-cols">
                <label><?php esc_html_e('Hora de los avisos diarios', 'mad-oficina'); ?><input type="number" min="6" max="20" name="daily_hour" value="<?php echo (int) $coordinator->setting('daily_hour'); ?>"></label>
                <label><?php esc_html_e('Avisar con días de antelación', 'mad-oficina'); ?><input type="number" min="0" max="14" name="days_ahead" value="<?php echo (int) $coordinator->setting('days_ahead'); ?>"></label>
                <label><?php esc_html_e('Como mucho un recordatorio por cliente cada (días)', 'mad-oficina'); ?><input type="number" min="1" max="60" name="client_every" value="<?php echo (int) $coordinator->setting('client_every'); ?>"></label>
            </div>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </section>
    </form>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Probar ahora', 'mad-oficina'); ?></span>
        <p class="description"><?php echo $next ? esc_html(sprintf(__('Se revisa sola cada hora y con cada aviso del Apps Script. Próxima revisión de WP-Cron: %s.', 'mad-oficina'), wp_date('j M H:i', $next))) : esc_html__('Se revisa sola cada hora y con cada aviso del Apps Script.', 'mad-oficina'); ?></p>
        <div class="madof-actions">
        <?php foreach ( $jobs as $job => $label ) : ?>
            <?php echo AdminUi::form_open('followup_run', 'madof-inline'); ?><input type="hidden" name="job" value="<?php echo esc_attr($job); ?>"><button class="madof-btn is-ghost"><?php echo esc_html($label); ?></button></form>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Banco de ideas', 'mad-oficina'); ?></span>
        <?php if ( ! $ideas ) : ?>
            <p class="description"><?php esc_html_e('Aún no hay ideas guardadas. Salen de las reuniones aprobadas.', 'mad-oficina'); ?></p>
        <?php else : ?>
        <table class="madof-table">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Cliente', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Idea', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Vuelve', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Decidir', 'mad-oficina'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $ideas as $i ) : ?>
                <tr>
                    <td><?php echo esc_html($i['client_code'] ?: '—'); ?></td>
                    <td><strong><?php echo esc_html($i['title']); ?></strong>
                        <?php if ( $i['description'] ) : ?><details class="madof-more"><summary><?php esc_html_e('Detalle', 'mad-oficina'); ?></summary><p><?php echo esc_html($i['description']); ?></p></details><?php endif; ?></td>
                    <td><?php echo $i['status'] === 'surfaced' ? '<span class="madof-pill is-on">' . esc_html__('Ahora', 'mad-oficina') . '</span>' : esc_html($i['resurface_at'] ? mysql2date('j M Y', $i['resurface_at']) : __('Sin fecha', 'mad-oficina')); ?></td>
                    <td>
                        <?php echo AdminUi::form_open('idea_resolve', 'madof-inline'); ?>
                            <input type="hidden" name="idea_id" value="<?php echo (int) $i['id']; ?>">
                            <button class="madof-btn is-small" name="decision" value="task"><?php esc_html_e('A tarea', 'mad-oficina'); ?></button>
                            <button class="madof-btn is-small is-ghost" name="decision" value="postpone"><?php esc_html_e('+1 mes', 'mad-oficina'); ?></button>
                            <button class="madof-btn is-small is-ghost" name="decision" value="discard"><?php esc_html_e('Descartar', 'mad-oficina'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <?php if ( $log ) : ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Últimos avisos con problemas o enviados', 'mad-oficina'); ?></span>
        <ul>
        <?php foreach ( $log as $l ) : $d = json_decode($l['data'], true) ?: []; ?>
            <li><?php echo esc_html(mysql2date('j M H:i', get_date_from_gmt($l['created_at'])) . ' · ' . $l['event'] . (isset($d['error']) ? ' · ' . $d['error'] : '')); ?></li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</div>
