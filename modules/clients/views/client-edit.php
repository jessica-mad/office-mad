<?php
/** @var array|null $client */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\PortalAreas;
use MAD_Oficina\Clients\Actions;

if ( ! defined('ABSPATH') ) exit;
$is_new = ! $client;
$c = $client ?: ['id' => 0, 'name' => '', 'code' => '', 'status' => 'active', 'domains' => [], 'drive_folder_id' => '', 'notes' => '', 'is_demo' => 0];
$statuses = ['active' => __('Activo', 'mad-oficina'), 'paused' => __('En pausa', 'mad-oficina'), 'archived' => __('Archivado', 'mad-oficina')];
$edit_contact = isset($_GET['contact']) ? absint($_GET['contact']) : 0;
?>
<div class="wrap madof-wrap">
    <p><a href="<?php echo esc_url(Actions::client_url()); ?>">← <?php esc_html_e('Clientes', 'mad-oficina'); ?></a></p>
    <?php AdminUi::header($is_new ? __('Nuevo cliente', 'mad-oficina') : $c['code'], $is_new ? __('Nuevo cliente', 'mad-oficina') : $c['name']); ?>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Ficha', 'mad-oficina'); ?></span>
        <?php echo AdminUi::form_open('client_save'); ?>
            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre', 'mad-oficina'); ?>
                    <input type="text" name="name" required value="<?php echo esc_attr($c['name']); ?>"></label>
                <label><?php esc_html_e('Código', 'mad-oficina'); ?>
                    <input type="text" name="code" required maxlength="12" class="madof-input-code" value="<?php echo esc_attr($c['code']); ?>">
                    <small><?php esc_html_e('El que pones en los títulos de Calendar, p. ej. MOM.', 'mad-oficina'); ?></small></label>
                <label><?php esc_html_e('Estado', 'mad-oficina'); ?>
                    <select name="status"><?php foreach ( $statuses as $value => $label ) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($c['status'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?></select></label>
            </div>
            <label><?php esc_html_e('Dominios de email', 'mad-oficina'); ?>
                <input type="text" name="domains" value="<?php echo esc_attr(implode(', ', $c['domains'])); ?>" placeholder="empresa.com, empresa.es">
                <small><?php esc_html_e('Sirven para reconocer al cliente por los invitados de una reunión. No pongas gmail.com ni dominios genéricos.', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('Carpeta de Drive', 'mad-oficina'); ?>
                <input type="text" name="drive_folder_id" value="<?php echo esc_attr($c['drive_folder_id']); ?>" placeholder="https://drive.google.com/drive/folders/…">
                <small><?php esc_html_e('Pega el enlace de la carpeta; se guarda solo el ID.', 'mad-oficina'); ?></small></label>
            <label><?php esc_html_e('Notas internas', 'mad-oficina'); ?>
                <textarea name="notes" rows="3"><?php echo esc_textarea($c['notes']); ?></textarea>
                <small><?php esc_html_e('Solo las ves tú. Nunca se muestran al cliente.', 'mad-oficina'); ?></small></label>
            <button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
        </form>
    </section>

    <?php if ( ! $is_new ) do_action('madoficina_client_edit_identity', $c); ?>

    <?php if ( ! $is_new ) : $contacts = Clients::contacts($c['id']); ?>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Contactos', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Personas de la empresa. Sus emails (de trabajo y personales) sirven para reconocer al cliente en las reuniones.', 'mad-oficina'); ?></p>
        <?php if ( $contacts ) : ?>
        <table class="madof-table">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Nombre', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Rol', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Emails', 'mad-oficina'); ?></th>
                <th scope="col"><?php esc_html_e('Resúmenes', 'mad-oficina'); ?></th>
                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Acciones', 'mad-oficina'); ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $contacts as $ct ) : ?>
                <tr>
                    <td><strong><?php echo esc_html($ct['name']); ?></strong>
                        <?php if ( $ct['aliases'] ) : ?><br><small><?php echo esc_html(implode(', ', $ct['aliases'])); ?></small><?php endif; ?></td>
                    <td><?php echo esc_html($ct['role']); ?></td>
                    <td><?php foreach ( $ct['emails'] as $e ) : ?>
                        <div><?php echo esc_html($e['email']); ?> <span class="madof-tag"><?php echo $e['kind'] === 'personal' ? esc_html__('personal', 'mad-oficina') : esc_html__('trabajo', 'mad-oficina'); ?></span></div>
                    <?php endforeach; ?></td>
                    <td><?php echo $ct['receives_summaries'] ? AdminUi::icon('check', __('Sí', 'mad-oficina')) : '—'; ?></td>
                    <td class="madof-row-actions">
                        <a href="<?php echo esc_url(Actions::client_url($c['id'], ['contact' => $ct['id']]) . '#contact-form'); ?>"><?php esc_html_e('Editar', 'mad-oficina'); ?></a>
                        <?php echo AdminUi::form_open('contact_delete', 'madof-inline'); ?>
                            <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
                            <input type="hidden" name="contact_id" value="<?php echo (int) $ct['id']; ?>">
                            <button class="madof-link"><?php esc_html_e('Eliminar', 'mad-oficina'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php
        $ec = null;
        foreach ( $contacts as $ct ) if ( $ct['id'] === $edit_contact ) $ec = $ct;
        $emails_of = fn($kind) => $ec ? implode(', ', array_column(array_filter($ec['emails'], fn($e) => $e['kind'] === $kind), 'email')) : '';
        ?>
        <h3 id="contact-form"><?php echo $ec ? esc_html(sprintf(__('Editar a %s', 'mad-oficina'), $ec['name'])) : esc_html__('Añadir contacto', 'mad-oficina'); ?></h3>
        <?php echo AdminUi::form_open('contact_save'); ?>
            <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
            <input type="hidden" name="contact_id" value="<?php echo $ec ? (int) $ec['id'] : 0; ?>">
            <div class="madof-cols">
                <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="name" required value="<?php echo esc_attr($ec['name'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Rol', 'mad-oficina'); ?><input type="text" name="role" value="<?php echo esc_attr($ec['role'] ?? ''); ?>"></label>
                <label><?php esc_html_e('Alias', 'mad-oficina'); ?><input type="text" name="aliases" value="<?php echo esc_attr(implode(', ', $ec['aliases'] ?? [])); ?>" placeholder="Marta, Martita">
                    <small><?php esc_html_e('Nombres que usas en los títulos de Calendar.', 'mad-oficina'); ?></small></label>
            </div>
            <div class="madof-cols">
                <label><?php esc_html_e('Emails de trabajo', 'mad-oficina'); ?><input type="text" name="work_emails" value="<?php echo esc_attr($emails_of('work')); ?>"></label>
                <label><?php esc_html_e('Emails personales', 'mad-oficina'); ?><input type="text" name="personal_emails" value="<?php echo esc_attr($emails_of('personal')); ?>"></label>
            </div>
            <label class="madof-check"><input type="checkbox" name="receives_summaries" value="1" <?php checked($ec ? $ec['receives_summaries'] : 1, 1); ?>>
                <?php esc_html_e('Recibe los resúmenes de reunión', 'mad-oficina'); ?></label>
            <button class="madof-btn"><?php echo $ec ? esc_html__('Guardar contacto', 'mad-oficina') : esc_html__('Añadir contacto', 'mad-oficina'); ?></button>
            <?php if ( $ec ) : ?><a href="<?php echo esc_url(Actions::client_url($c['id'])); ?>"><?php esc_html_e('Cancelar', 'mad-oficina'); ?></a><?php endif; ?>
        </form>
    </section>

    <section class="madof-card">
        <span class="madof-kicker"><?php esc_html_e('Acceso al portal', 'mad-oficina'); ?></span>
        <p class="description"><?php esc_html_e('Usuarios de WordPress que entran en /oficina y ven solo lo de este cliente, y de eso, solo las partes que marques para cada persona. Crear un usuario no envía ningún email: el acceso se manda aparte, cuando tú quieras.', 'mad-oficina'); ?></p>
        <?php $users = Clients::users($c['id']); if ( $users ) : ?>
        <table class="madof-table">
            <tbody>
            <?php foreach ( $users as $u ) : ?>
                <tr>
                    <td><?php echo AdminUi::icon('user'); ?> <strong><?php echo esc_html($u->display_name); ?></strong><br><small><?php echo esc_html($u->user_email); ?></small></td>
                    <td><?php $areas = PortalAreas::for_user($u->ID); echo AdminUi::form_open('user_areas', 'madof-areas'); ?>
                            <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                            <?php foreach ( PortalAreas::AREAS as $k => $l ) : ?>
                                <label class="madof-check"><input type="checkbox" name="areas[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $areas, true)); ?>> <?php echo esc_html__($l, 'mad-oficina'); ?></label>
                            <?php endforeach; ?>
                            <button class="madof-btn is-small is-ghost"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
                        </form></td>
                    <td class="madof-row-actions">
                        <?php echo AdminUi::form_open('user_send_access', 'madof-inline'); ?>
                            <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                            <button class="madof-link"><?php esc_html_e('Enviar email de acceso', 'mad-oficina'); ?></button>
                        </form>
                        <?php echo AdminUi::form_open('user_remove', 'madof-inline'); ?>
                            <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                            <button class="madof-link"><?php esc_html_e('Quitar acceso', 'mad-oficina'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php echo AdminUi::form_open('user_add'); ?>
            <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
            <div class="madof-cols">
                <label><?php esc_html_e('Email', 'mad-oficina'); ?><input type="email" name="email" required></label>
                <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="name"></label>
            </div>
            <input type="hidden" name="areas_sent" value="1">
            <fieldset class="madof-fieldset madof-areas"><legend><?php esc_html_e('Qué ve en su oficina', 'mad-oficina'); ?></legend>
                <?php foreach ( PortalAreas::AREAS as $k => $l ) : ?>
                    <label class="madof-check"><input type="checkbox" name="areas[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, PortalAreas::DEFAULT, true)); ?>> <?php echo esc_html__($l, 'mad-oficina'); ?></label>
                <?php endforeach; ?>
                <small><?php esc_html_e('Las facturas y presupuestos solo los ve quien marques (normalmente, quien paga). Pedir reuniones, hacer encargos y hablar con la recepcionista está abierto para todos.', 'mad-oficina'); ?></small>
            </fieldset>
            <button class="madof-btn"><?php esc_html_e('Dar acceso', 'mad-oficina'); ?></button>
        </form>
    </section>

    <?php do_action('madoficina_client_edit_sections', $c); ?>

    <section class="madof-card is-danger">
        <span class="madof-kicker"><?php esc_html_e('Borrar cliente', 'mad-oficina'); ?></span>
        <p><?php esc_html_e('Borra el cliente y todos sus datos en MAD Oficina (contactos, reuniones, tareas, ideas, mensajes). Sus usuarios pierden el acceso al portal. No toca nada de Google Drive. No se puede deshacer.', 'mad-oficina'); ?></p>
        <?php echo AdminUi::form_open('client_delete'); ?>
            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
            <label><?php echo esc_html(sprintf(__('Escribe %s para confirmar', 'mad-oficina'), $c['code'])); ?>
                <input type="text" name="confirm_code" autocomplete="off"></label>
            <button class="madof-btn is-danger"><?php esc_html_e('Borrar definitivamente', 'mad-oficina'); ?></button>
        </form>
    </section>
    <?php endif; ?>
</div>
