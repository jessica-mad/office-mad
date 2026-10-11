<?php
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Team;

if ( ! defined('ABSPATH') ) exit;
$members = Team::all();
$edit = isset($_GET['member']) ? Team::get(absint($_GET['member'])) : null;
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Gerencia', 'mad-oficina'), __('Equipo', 'mad-oficina'), __('Personas reales que trabajan contigo. Reciben sus tareas por Google Chat y, si les das acceso, ven sus encargos en la oficina.', 'mad-oficina')); ?>

    <?php if ( $members ) : ?>
    <table class="madof-table">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Nombre', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Email de Workspace', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Especialidad', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Acceso a la oficina', 'mad-oficina'); ?></th>
            <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Acciones', 'mad-oficina'); ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $members as $m ) : ?>
            <tr>
                <td><strong><?php echo esc_html($m['name']); ?></strong>
                    <?php if ( $m['is_demo'] ) : ?><span class="madof-pill is-demo">DEMO</span><?php endif; ?>
                    <?php if ( ! $m['active'] ) : ?><span class="madof-pill"><?php esc_html_e('inactiva', 'mad-oficina'); ?></span><?php endif; ?></td>
                <td><?php echo esc_html($m['workspace_email']); ?></td>
                <td><?php echo esc_html(implode(', ', $m['skills'])); ?></td>
                <td><?php echo $m['wp_user_id'] ? AdminUi::icon('check', __('Sí', 'mad-oficina')) : '—'; ?></td>
                <td class="madof-row-actions">
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'mad-oficina-equipo', 'member' => $m['id']], admin_url('admin.php'))); ?>"><?php esc_html_e('Editar', 'mad-oficina'); ?></a>
                    <?php echo AdminUi::form_open('team_delete', 'madof-inline'); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>">
                        <button class="madof-link"><?php esc_html_e('Eliminar', 'mad-oficina'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <section class="madof-card">
        <span class="madof-kicker"><?php echo $edit ? esc_html__('Editar', 'mad-oficina') : esc_html__('Añadir al equipo', 'mad-oficina'); ?></span>
        <?php echo AdminUi::form_open('team_save'); ?>
            <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit['id'] : 0; ?>">
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="name" required value="<?php echo esc_attr($edit['name'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Email de Workspace', 'mad-oficina'); ?><input type="email" name="workspace_email" value="<?php echo esc_attr($edit['workspace_email'] ?? ''); ?>" placeholder="liz@imad.pro"></label>
                <label><?php esc_html_e('Especialidad', 'mad-oficina'); ?><input type="text" name="skills" value="<?php echo esc_attr(implode(', ', $edit['skills'] ?? [])); ?>" placeholder="diseño, vídeo"></label>
            </div>
            <label class="madof-check"><input type="checkbox" name="portal_access" value="1" <?php checked(! empty($edit['wp_user_id'])); ?>>
                <?php esc_html_e('Puede entrar en la oficina (crea o vincula su usuario de WordPress con el rol «Equipo MAD», sin enviar emails)', 'mad-oficina'); ?></label>
            <label class="madof-check"><input type="checkbox" name="active" value="1" <?php checked($edit ? $edit['active'] : 1, 1); ?>>
                <?php esc_html_e('Activa', 'mad-oficina'); ?></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </form>
    </section>
</div>
