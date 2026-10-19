<?php
/**
 * Tareas: every task with its full title and description, where it came from and who has it.
 * @var array $rows @var array $f filters @var int $total
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Repo\Team;

if ( ! defined('ABSPATH') ) exit;
$base = admin_url('admin.php?page=mad-oficina-tareas');
$clients = Clients::all();
$codes = array_column($clients, 'code', 'id');
$team = array_column(Team::all(), 'name', 'id');
$owners = ['jessica' => __('Jessica', 'mad-oficina'), 'mad' => __('MAD', 'mad-oficina'), 'liz' => __('Equipo', 'mad-oficina'), 'cliente' => __('Cliente', 'mad-oficina')];
$statuses = ['open' => __('Abierta', 'mad-oficina'), 'in_progress' => __('En curso', 'mad-oficina'), 'done' => __('Hecha', 'mad-oficina'), 'cancelled' => __('Anulada', 'mad-oficina')];
$sources = ['meeting' => __('Acta de reunión', 'mad-oficina'), 'whatsapp' => __('WhatsApp', 'mad-oficina'), 'chat' => __('Nora (Chat)', 'mad-oficina'), 'manual' => __('A mano', 'mad-oficina'), 'idea' => __('Idea', 'mad-oficina')];
$date = fn($d) => $d ? date_i18n('j M Y', strtotime($d)) : '—';
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Sala de proyectos', 'mad-oficina'), __('Tareas', 'mad-oficina'), __('Todas las tareas con su título y descripción completos, de dónde salieron y quién las tiene. Nora, las actas y Valle las crean y cambian; aquí las ves y puedes cerrarlas o reabrirlas.', 'mad-oficina')); ?>

    <form method="get" class="madof-bank-filter">
        <input type="hidden" name="page" value="mad-oficina-tareas">
        <input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" placeholder="<?php esc_attr_e('Buscar en título o descripción, o #253', 'mad-oficina'); ?>">
        <select name="client" aria-label="<?php esc_attr_e('Cliente', 'mad-oficina'); ?>"><option value=""><?php esc_html_e('Todos los clientes', 'mad-oficina'); ?></option><?php foreach ( $clients as $c ) echo '<option value="' . (int) $c['id'] . '" ' . selected($f['client'], (int) $c['id'], false) . '>' . esc_html($c['code'] . ' · ' . $c['name']) . '</option>'; ?></select>
        <select name="owner" aria-label="<?php esc_attr_e('Responsable', 'mad-oficina'); ?>"><option value=""><?php esc_html_e('Cualquier responsable', 'mad-oficina'); ?></option><?php foreach ( $owners as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($f['owner'], $k, false) . '>' . esc_html($l) . '</option>'; ?></select>
        <select name="status" aria-label="<?php esc_attr_e('Estado', 'mad-oficina'); ?>">
            <?php foreach ( ['open' => __('Abiertas', 'mad-oficina'), 'done' => __('Hechas', 'mad-oficina'), 'cancelled' => __('Anuladas', 'mad-oficina'), 'all' => __('Todas', 'mad-oficina')] as $k => $l ) echo '<option value="' . esc_attr($k) . '" ' . selected($f['status'], $k, false) . '>' . esc_html($l) . '</option>'; ?>
        </select>
        <button class="madof-btn is-small"><?php esc_html_e('Filtrar', 'mad-oficina'); ?></button>
        <small class="madof-muted"><?php echo esc_html(sprintf(_n('%d tarea', '%d tareas', $total, 'mad-oficina'), $total)); ?></small>
    </form>

    <?php if ( ! $rows ) : ?>
        <p class="madof-empty"><?php esc_html_e('Nada con estos filtros.', 'mad-oficina'); ?></p>
    <?php else : ?>
    <table class="madof-table madof-tasks">
        <thead><tr><th>#</th><th><?php esc_html_e('Tarea', 'mad-oficina'); ?></th><th><?php esc_html_e('Responsable', 'mad-oficina'); ?></th><th><?php esc_html_e('Fecha límite', 'mad-oficina'); ?></th><th><?php esc_html_e('Estado', 'mad-oficina'); ?></th><th><?php esc_html_e('Origen', 'mad-oficina'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $rows as $t ) : $open = in_array($t['status'], ['open', 'in_progress'], true); ?>
            <tr id="tarea-<?php echo (int) $t['id']; ?>">
                <td class="madof-nowrap"><strong>#<?php echo (int) $t['id']; ?></strong></td>
                <td>
                    <?php if ( $t['client_id'] ) echo '<span class="madof-code">' . esc_html($codes[$t['client_id']] ?? '?') . '</span> '; ?>
                    <strong><?php echo esc_html($t['title']); ?></strong>
                    <?php if ( $t['priority'] !== 'normal' ) echo ' <span class="madof-pill' . ($t['priority'] === 'high' ? ' is-alert' : '') . '">' . esc_html($t['priority'] === 'high' ? __('prioridad alta', 'mad-oficina') : __('prioridad baja', 'mad-oficina')) . '</span>'; ?>
                    <?php if ( $t['visibility'] === 'client' ) echo ' <span class="madof-pill">' . esc_html__('la ve el cliente', 'mad-oficina') . '</span>'; ?>
                    <?php if ( trim((string) $t['description']) !== '' ) : ?><div class="madof-task-desc"><?php echo nl2br(esc_html($t['description'])); ?></div><?php endif; ?>
                    <?php if ( trim((string) $t['evidence']) !== '' ) : ?><details class="madof-more"><summary><?php esc_html_e('De dónde sale', 'mad-oficina'); ?></summary><div class="madof-muted"><?php echo nl2br(esc_html($t['evidence'])); ?></div></details><?php endif; ?>
                </td>
                <td><?php echo esc_html($t['team_member_id'] ? ($team[$t['team_member_id']] ?? __('Equipo', 'mad-oficina')) : ($owners[$t['owner']] ?? $t['owner'])); ?></td>
                <td class="madof-nowrap"><?php echo esc_html($date($t['due_date'])); ?></td>
                <td class="madof-nowrap"><?php echo esc_html($statuses[$t['status']] ?? $t['status']); ?><?php if ( $t['completed_at'] ) echo '<br><small class="madof-muted">' . esc_html($date($t['completed_at'])) . '</small>'; ?></td>
                <td><small><?php echo esc_html($sources[$t['source']] ?? $t['source']); ?><?php if ( $t['meeting_id'] ) : ?><br><a href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-reuniones&meeting=' . (int) $t['meeting_id'])); ?>"><?php esc_html_e('ver reunión', 'mad-oficina'); ?></a><?php endif; ?><br><span class="madof-muted"><?php echo esc_html(sprintf(__('creada %s', 'mad-oficina'), $date($t['created_at']))); ?></span></small></td>
                <td>
                    <?php echo AdminUi::form_open('task_status', 'madof-inline'); ?>
                        <input type="hidden" name="task" value="<?php echo (int) $t['id']; ?>"><input type="hidden" name="back" value="<?php echo esc_attr(wp_unslash($_SERVER['QUERY_STRING'] ?? '')); ?>">
                        <input type="hidden" name="status" value="<?php echo $open ? 'done' : 'open'; ?>">
                        <button class="madof-btn is-small <?php echo $open ? '' : 'is-ghost'; ?>"><?php echo esc_html($open ? __('Hecha', 'mad-oficina') : __('Reabrir', 'mad-oficina')); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ( $total > count($rows) ) echo '<p class="madof-muted">' . esc_html(sprintf(__('Se muestran las %d más recientes: usa los filtros para ver el resto.', 'mad-oficina'), count($rows))) . '</p>'; ?>
    <?php endif; ?>
</div>
