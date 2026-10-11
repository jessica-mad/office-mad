<?php
/**
 * @var array $settings @var array $rules @var bool $ready
 * @var array|\WP_Error|null $calendars @var array|\WP_Error|null $preview
 * @var \MAD_Oficina\Scheduling\Requests $requests
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Scheduling\Slots;

if ( ! defined('ABSPATH') ) exit;
$days = [1 => __('Lunes', 'mad-oficina'), 2 => __('Martes', 'mad-oficina'), 3 => __('Miércoles', 'mad-oficina'), 4 => __('Jueves', 'mad-oficina'), 5 => __('Viernes', 'mad-oficina'), 6 => __('Sábado', 'mad-oficina'), 7 => __('Domingo', 'mad-oficina')];
$chosen = $requests->calendars();
$tz = wp_timezone();
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Agenda', 'mad-oficina'), __('Reuniones con clientes', 'mad-oficina'), __('El portal ofrece huecos reales de tu calendario, consultado en el momento. Cuando un cliente elige uno, te llega a la bandeja; al aprobar se crea el evento con Meet e invitaciones.', 'mad-oficina')); ?>

    <?php if ( ! $ready ) : ?>
    <section class="madof-card">
        <p class="madof-warn"><?php esc_html_e('Faltan los permisos de Calendar. Ve a Conexiones y pulsa «Reconectar con Google» para concederlos.', 'mad-oficina'); ?></p>
        <a class="madof-btn" href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-conexiones')); ?>"><?php esc_html_e('Ir a Conexiones', 'mad-oficina'); ?></a>
    </section>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="madof-form">
        <?php wp_nonce_field('madof_scheduling_settings'); ?>
        <input type="hidden" name="action" value="madof_scheduling_settings">

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Horario para reuniones', 'mad-oficina'); ?></span>
            <p class="description"><?php esc_html_e('Franjas separadas por comas, p. ej. «10:00-14:00, 16:00-18:00». Vacío = ese día no hay reuniones.', 'mad-oficina'); ?></p>
            <div class="madof-cols">
            <?php foreach ( $days as $n => $label ) : ?>
                <label><?php echo esc_html($label); ?><input type="text" name="rules[hours][<?php echo (int) $n; ?>]" value="<?php echo esc_attr($rules['hours'][$n] ?? ''); ?>"></label>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Reglas', 'mad-oficina'); ?></span>
            <div class="madof-cols">
                <label><?php esc_html_e('Duración (min)', 'mad-oficina'); ?><input type="number" min="15" max="240" name="rules[duration]" value="<?php echo (int) $rules['duration']; ?>"></label>
                <label><?php esc_html_e('Margen entre reuniones (min)', 'mad-oficina'); ?><input type="number" min="0" max="120" name="rules[buffer]" value="<?php echo (int) $rules['buffer']; ?>"></label>
                <label><?php esc_html_e('Antelación mínima (horas)', 'mad-oficina'); ?><input type="number" min="0" max="336" name="rules[min_notice]" value="<?php echo (int) $rules['min_notice']; ?>"></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Máximo de reuniones por día', 'mad-oficina'); ?><input type="number" min="1" max="12" name="rules[max_per_day]" value="<?php echo (int) $rules['max_per_day']; ?>">
                    <small><?php esc_html_e('Cuenta todo lo ocupado en tu horario ese día.', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Ofrecer huecos hasta (días)', 'mad-oficina'); ?><input type="number" min="1" max="60" name="rules[horizon_days]" value="<?php echo (int) $rules['horizon_days']; ?>"></label>
                <label><?php esc_html_e('Empezar reuniones cada', 'mad-oficina'); ?>
                    <select name="rules[step]"><?php foreach ( [15, 30, 60] as $s ) : ?><option value="<?php echo (int) $s; ?>" <?php selected((int) $rules['step'], $s); ?>><?php echo esc_html(sprintf(__('%d min', 'mad-oficina'), $s)); ?></option><?php endforeach; ?></select></label>
            </div>
            <label><?php esc_html_e('Días bloqueados (AAAA-MM-DD, separados por comas)', 'mad-oficina'); ?>
                <input type="text" name="rules[blocked]" value="<?php echo esc_attr(implode(', ', (array) $rules['blocked'])); ?>" placeholder="2026-12-24, 2026-12-31"></label>
        </section>

        <section class="madof-card">
            <span class="madof-kicker"><?php esc_html_e('Calendarios que cuentan como ocupado', 'mad-oficina'); ?></span>
            <?php if ( is_array($calendars) && $calendars ) : ?>
                <?php foreach ( $calendars as $c ) : $value = $c['primary'] ? 'primary' : $c['id']; ?>
                    <label class="madof-check"><input type="checkbox" name="calendars[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $chosen, true) || ($c['primary'] && in_array($c['id'], $chosen, true))); ?>> <?php echo esc_html($c['summary']); ?><?php if ( $c['primary'] ) echo ' <small>(' . esc_html__('principal', 'mad-oficina') . ')</small>'; ?></label>
                <?php endforeach; ?>
            <?php else : ?>
                <?php foreach ( $chosen as $c ) : ?><input type="hidden" name="calendars[]" value="<?php echo esc_attr($c); ?>"><?php endforeach; ?>
                <p class="description"><?php echo is_wp_error($calendars) ? esc_html($calendars->get_error_message()) : esc_html__('Se usa tu calendario principal. Conecta Google para elegir más.', 'mad-oficina'); ?></p>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Los eventos nuevos se crean siempre en tu calendario principal.', 'mad-oficina'); ?></p>
        </section>

        <button class="madof-btn"><?php esc_html_e('Guardar reglas', 'mad-oficina'); ?></button>
    </form>

    <?php if ( $ready ) : ?>
    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Lo que vería un cliente ahora', 'mad-oficina'); ?></span>
        <?php if ( is_wp_error($preview) ) : ?>
            <p class="madof-warn"><?php echo esc_html($preview->get_error_message()); ?></p>
        <?php elseif ( ! $preview ) : ?>
            <p class="description"><?php esc_html_e('No hay huecos libres con estas reglas.', 'mad-oficina'); ?></p>
        <?php else : ?>
            <ul>
                <?php foreach ( Slots::offer($preview, 5, $tz) as $s ) : ?><li><?php echo esc_html(Slots::label($s['start'], $tz)); ?></li><?php endforeach; ?>
            </ul>
            <p class="description"><?php echo esc_html(sprintf(_n('%d hueco libre en total.', '%d huecos libres en total.', count($preview), 'mad-oficina'), count($preview))); ?></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
