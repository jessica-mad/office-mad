<?php
/**
 * Vista: bandeja de gerencia (aprobaciones de los agentes).
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Agents\Characters;
use MAD_Oficina\Core\OfficeState;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;

$types = Approvals::types();
$counts = Approvals::count_by_type();
$type = sanitize_key($_GET['type'] ?? '');
if ( $type && ! isset($counts[$type]) ) $type = '';
$pending = Approvals::pending(200, $type);
$resolved = Approvals::recent_resolved(20);
$who = function($agent){
    $slug = OfficeState::AGENT_TO_CHARACTER[$agent] ?? null;
    $c = $slug ? Characters::get($slug) : null;
    return $c ? $c['name'] . ' · ' . $c['role'] : $agent;
};
$base = admin_url('admin.php?page=mad-oficina-bandeja');
$open = absint($_GET['open'] ?? 0);
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Despacho de gerencia', 'mad-oficina'), __('Bandeja', 'mad-oficina'), __('Aquí llega todo lo que los agentes quieren hacer. Nada sale hacia fuera ni aparece en el portal del cliente hasta que lo apruebas.', 'mad-oficina')); ?>

    <?php if ( $counts ) : ?>
    <nav class="madof-filters" aria-label="<?php esc_attr_e('Tipo de petición', 'mad-oficina'); ?>">
        <a class="madof-pill <?php echo $type ? '' : 'is-on'; ?>" href="<?php echo esc_url($base); ?>"><?php echo esc_html(sprintf(__('Todo (%d)', 'mad-oficina'), array_sum($counts))); ?></a>
        <?php foreach ( $counts as $t => $n ) : ?>
            <a class="madof-pill <?php echo $type === $t ? 'is-on' : ''; ?>" href="<?php echo esc_url($base . '&type=' . $t); ?>"><?php echo esc_html(($types[$t]['label'] ?? $t) . ' (' . $n . ')'); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if ( ! $pending ) : ?>
        <div class="madof-card is-muted"><p><?php esc_html_e('No hay nada pendiente. Todo en orden.', 'mad-oficina'); ?></p></div>
    <?php else : ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="madof-bulk" class="madof-bulk">
        <?php wp_nonce_field('madof_approval_bulk'); ?>
        <input type="hidden" name="action" value="madof_approval_bulk">
        <input type="hidden" name="type" value="<?php echo esc_attr($type); ?>">
        <label class="madof-check"><input type="checkbox" class="madof-select-all"> <?php echo esc_html($type ? sprintf(__('Seleccionar todas (%d)', 'mad-oficina'), count($pending)) : __('Seleccionar todas', 'mad-oficina')); ?></label>
        <span class="madof-bulk-count" aria-live="polite"></span>
        <button class="madof-btn is-small" name="decision" value="approve" disabled><?php echo AdminUi::icon('check'); ?> <?php esc_html_e('Aprobar tal cual', 'mad-oficina'); ?></button>
        <button class="madof-btn is-small is-ghost" name="decision" value="reject" disabled><?php esc_html_e('Descartar', 'mad-oficina'); ?></button>
        <button type="button" class="madof-link madof-toggle-all"><?php esc_html_e('Desplegar todas', 'mad-oficina'); ?></button>
    </form>
    <p class="description"><?php esc_html_e('«Tal cual» aplica lo que propone cada agente (lo mismo que el botón de Chat). Para cambiar algo, despliega la petición y edítala antes de aprobar.', 'mad-oficina'); ?></p>

    <div class="madof-approvals">
    <?php foreach ( $pending as $a ) :
        $client = $a['client_id'] ? Clients::get($a['client_id']) : null;
        $external = ! empty($types[$a['action_type']]['external']);
        $busy = $a['status'] === Approvals::PROCESSING;
    ?>
    <details class="madof-approval" id="approval-<?php echo (int) $a['id']; ?>" <?php echo $open === (int) $a['id'] ? 'open' : ''; ?> data-external="<?php echo $external ? '1' : '0'; ?>">
        <summary>
            <?php if ( ! $busy ) : ?><input type="checkbox" form="madof-bulk" name="ids[]" value="<?php echo (int) $a['id']; ?>" class="madof-pick" aria-label="<?php echo esc_attr($a['title']); ?>" onclick="event.stopPropagation()"><?php endif; ?>
            <span class="madof-approval-main">
                <span class="madof-kicker"><?php echo esc_html(($types[$a['action_type']]['label'] ?? $a['action_type']) . ' · ' . $who($a['agent']) . ' · ' . mysql2date('j M H:i', get_date_from_gmt($a['created_at']))); ?><?php if ( $external ) echo ' · ' . esc_html__('sale fuera', 'mad-oficina'); ?></span>
                <strong><?php if ( $client ) : ?><span class="madof-code"><?php echo esc_html($client['code']); ?></span> <?php endif; ?><?php echo esc_html($a['title']); ?></strong>
                <?php if ( $a['summary'] ) : ?><small><?php echo esc_html(wp_trim_words($a['summary'], 22)); ?></small><?php endif; ?>
            </span>
            <?php if ( $busy ) : ?>
                <span class="madof-pill"><?php esc_html_e('Ejecutándose…', 'mad-oficina'); ?></span>
            <?php else : ?>
            <span class="madof-approval-quick">
                <button class="madof-btn is-small" form="madof-bulk" name="one" value="approve:<?php echo (int) $a['id']; ?>" onclick="event.stopPropagation()" <?php echo $external ? 'data-external="1"' : ''; ?>><?php echo AdminUi::icon('check'); ?> <span class="screen-reader-text"><?php esc_html_e('Aprobar tal cual', 'mad-oficina'); ?></span></button>
                <button class="madof-btn is-small is-ghost" form="madof-bulk" name="one" value="reject:<?php echo (int) $a['id']; ?>" onclick="event.stopPropagation()" aria-label="<?php esc_attr_e('Descartar', 'mad-oficina'); ?>">✕</button>
            </span>
            <?php endif; ?>
        </summary>
        <?php if ( ! $busy ) : ?>
        <div class="madof-approval-body">
            <?php if ( $a['summary'] ) : ?><p><?php echo nl2br(esc_html($a['summary'])); ?></p><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
                <?php wp_nonce_field('madof_approval_' . $a['id']); ?>
                <input type="hidden" name="action" value="madof_approval_resolve">
                <input type="hidden" name="approval_id" value="<?php echo (int) $a['id']; ?>">
                <input type="hidden" name="type" value="<?php echo esc_attr($type); ?>">
                <?php echo apply_filters('madoficina_approval_form_' . $a['action_type'], '', $a); // built by the module with escaped values ?>
                <div class="madof-actions">
                    <button class="madof-btn" name="decision" value="approve"><?php echo AdminUi::icon('check'); ?> <?php esc_html_e('Aprobar', 'mad-oficina'); ?></button>
                    <button class="madof-btn is-ghost" name="decision" value="reject" formnovalidate><?php esc_html_e('Descartar', 'mad-oficina'); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </details>
    <?php endforeach; ?>
    </div>
    <script>
    (function () {
      // Links from Chat («Revisar en la web») point to #approval-N: open that one.
      var target = location.hash && document.querySelector(location.hash + '.madof-approval');
      if (target) { target.open = true; target.scrollIntoView(); }
      var bulk = document.getElementById('madof-bulk');
      if (!bulk) return;
      var picks = function () { return [].slice.call(document.querySelectorAll('.madof-pick')); };
      var update = function () {
        var n = picks().filter(function (c) { return c.checked; }).length;
        bulk.querySelector('.madof-bulk-count').textContent = n ? n + ' <?php echo esc_js(__('marcadas', 'mad-oficina')); ?>' : '';
        [].forEach.call(bulk.querySelectorAll('button[name=decision]'), function (b) { b.disabled = !n; });
      };
      bulk.querySelector('.madof-select-all').addEventListener('change', function (e) {
        picks().forEach(function (c) { c.checked = e.target.checked; });
        update();
      });
      document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('madof-pick')) update(); });
      bulk.querySelector('.madof-toggle-all').addEventListener('click', function (e) {
        var all = [].slice.call(document.querySelectorAll('details.madof-approval'));
        var open = all.some(function (d) { return !d.open; });
        all.forEach(function (d) { d.open = open; });
        e.target.textContent = open ? '<?php echo esc_js(__('Plegar todas', 'mad-oficina')); ?>' : '<?php echo esc_js(__('Desplegar todas', 'mad-oficina')); ?>';
      });
      // Approving several at once that send something out asks first.
      bulk.addEventListener('submit', function (e) {
        var btn = e.submitter;
        if (!btn) return;
        var approving = btn.value === 'approve' || /^approve:/.test(btn.value);
        if (!approving) return;
        var ext = btn.name === 'one' ? (btn.dataset.external === '1' ? 1 : 0)
          : picks().filter(function (c) { return c.checked && c.closest('details').dataset.external === '1'; }).length;
        if (ext && !confirm(ext === 1 && btn.name === 'one'
          ? '<?php echo esc_js(__('Esto sale fuera de la oficina (un email, una factura o una reunión) con lo que propone el agente. ¿Lo apruebo tal cual?', 'mad-oficina')); ?>'
          : ext + ' <?php echo esc_js(__('de las marcadas salen fuera de la oficina (emails, facturas o reuniones) tal cual. ¿Sigo?', 'mad-oficina')); ?>')) e.preventDefault();
      });
    })();
    </script>
    <?php endif; ?>

    <?php if ( $resolved ) : ?>
    <details class="madof-more"><summary><?php esc_html_e('Resueltas recientemente', 'mad-oficina'); ?></summary>
    <table class="madof-table">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Fecha', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Quién', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Petición', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Resultado', 'mad-oficina'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $resolved as $a ) : ?>
            <tr>
                <td><?php echo esc_html(mysql2date('j M H:i', get_date_from_gmt($a['resolved_at']))); ?></td>
                <td><?php echo esc_html($who($a['agent'])); ?></td>
                <td><?php echo esc_html($a['title']); ?></td>
                <td><span class="madof-pill <?php echo $a['status'] === 'approved' ? 'is-on' : ''; ?>"><?php echo $a['status'] === 'approved' ? esc_html__('Aprobada', 'mad-oficina') : esc_html__('Descartada', 'mad-oficina'); ?></span>
                    <?php if ( ! empty($a['resolution']['result']['message']) ) : ?><br><small><?php echo esc_html($a['resolution']['result']['message']); ?></small><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </details>
    <?php endif; ?>

    <details class="madof-card madof-more" id="autonomia"><summary><?php esc_html_e('Autonomía de los agentes', 'mad-oficina'); ?></summary>
    <section>
        <span class="madof-kicker"><?php esc_html_e('Autonomía de los agentes', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Por defecto todo te pregunta. Aquí puedes dejar que algunas acciones se hagan solas. Los resúmenes, las facturas y los mensajes a Liz siempre pasan por ti.', 'mad-oficina'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
            <?php wp_nonce_field('madof_autonomy_save'); ?>
            <input type="hidden" name="action" value="madof_autonomy_save">
            <?php $levels = ['ask' => __('Preguntarme siempre', 'mad-oficina'), 'notify' => __('Hacerlo y avisarme', 'mad-oficina'), 'silent' => __('Hacerlo en silencio', 'mad-oficina')]; ?>
            <?php foreach ( \MAD_Oficina\Core\Autonomy::ADJUSTABLE as $type => $label ) : $current = \MAD_Oficina\Core\Autonomy::level($type); ?>
                <label><?php echo esc_html__($label, 'mad-oficina'); ?>
                    <select name="autonomy[<?php echo esc_attr($type); ?>]"><?php foreach ( $levels as $value => $text ) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>><?php echo esc_html($text); ?></option>
                    <?php endforeach; ?></select></label>
            <?php endforeach; ?>
            <button class="madof-btn is-ghost"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </form>
    </section>
    </details>
</div>
