<?php
/** @var array $meeting */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Repo\Clients;

if ( ! defined('ABSPATH') ) exit;
$client = $meeting['client_id'] ? Clients::get($meeting['client_id']) : null;
$x = $meeting['extraction'];
$pending = array_filter(Approvals::for_object('meeting', $meeting['id']), fn($a) => $a['status'] === 'pending');
$drive = fn($id) => $id ? 'https://drive.google.com/open?id=' . rawurlencode($id) : '';
?>
<div class="wrap madof-wrap">
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-reuniones')); ?>">← <?php esc_html_e('Reuniones', 'mad-oficina'); ?></a></p>
    <?php AdminUi::header($meeting['starts_at'] ? get_date_from_gmt($meeting['starts_at'], 'j M Y · H:i') : __('Reunión', 'mad-oficina'), $meeting['title']); ?>

    <section class="madof-card">
        <div class="madof-cols">
            <div><span class="madof-kicker"><?php esc_html_e('Cliente', 'mad-oficina'); ?></span>
                <p><?php echo $client ? '<span class="madof-code">' . esc_html($client['code']) . '</span> ' . esc_html($client['name']) : esc_html__('Sin identificar', 'mad-oficina'); ?></p></div>
            <div><span class="madof-kicker"><?php esc_html_e('Estado', 'mad-oficina'); ?></span><p><?php echo esc_html($meeting['status']); ?></p></div>
            <div><span class="madof-kicker"><?php esc_html_e('Archivos', 'mad-oficina'); ?></span><p>
                <?php foreach ( ['drive_folder_id' => __('Carpeta', 'mad-oficina'), 'recording_file_id' => __('Grabación', 'mad-oficina'), 'gemini_notes_id' => __('Notas de Gemini', 'mad-oficina')] as $field => $label ) : ?>
                    <?php if ( $meeting[$field] ) : ?><a href="<?php echo esc_url($drive($meeting[$field])); ?>" target="_blank" rel="noopener"><?php echo esc_html($label); ?></a><br><?php endif; ?>
                <?php endforeach; ?></p></div>
        </div>
        <?php if ( $meeting['attendees'] ) : ?>
            <span class="madof-kicker"><?php esc_html_e('Invitados', 'mad-oficina'); ?></span>
            <p><?php echo esc_html(implode(' · ', array_map(fn($a) => trim($a['name'] . ' <' . $a['email'] . '>'), $meeting['attendees']))); ?></p>
        <?php endif; ?>
        <?php if ( ! empty($x['error']) ) : ?><p class="madof-warn"><?php echo esc_html($x['error']); ?></p><?php endif; ?>

        <div class="madof-actions">
            <?php if ( $pending ) : ?>
                <a class="madof-btn" href="<?php echo esc_url(admin_url('admin.php?page=mad-oficina-bandeja#approval-' . reset($pending)['id'])); ?>"><?php esc_html_e('Revisar el borrador en la bandeja', 'mad-oficina'); ?></a>
            <?php endif; ?>
            <?php if ( ! in_array($meeting['status'], ['approved', 'archived'], true) ) : ?>
            <?php echo AdminUi::form_open('meeting_process', 'madof-inline'); ?>
                <input type="hidden" name="meeting_id" value="<?php echo (int) $meeting['id']; ?>">
                <button class="madof-btn <?php echo $pending ? 'is-ghost' : ''; ?>"><?php echo $x && empty($x['error']) ? esc_html__('Volver a procesar', 'mad-oficina') : esc_html__('Procesar esta reunión', 'mad-oficina'); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if ( $meeting['summary'] ) : ?>
    <section class="madof-card"><span class="madof-kicker"><?php esc_html_e('Resumen aprobado', 'mad-oficina'); ?></span><p><?php echo esc_html($meeting['summary']); ?></p></section>
    <?php endif; ?>

    <?php if ( $meeting['transcript'] ) : ?>
    <details class="madof-card madof-details">
        <summary><?php echo esc_html(sprintf(__('Notas y transcripción (%s caracteres)', 'mad-oficina'), number_format_i18n(mb_strlen($meeting['transcript'])))); ?></summary>
        <pre class="madof-pre"><?php echo esc_html(mb_substr($meeting['transcript'], 0, 20000)); ?></pre>
    </details>
    <?php endif; ?>
</div>
