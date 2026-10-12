<?php
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Core\Seeder;
use MAD_Oficina\Clients\Actions;

if ( ! defined('ABSPATH') ) exit;
$clients = Clients::all();
$status_labels = ['active' => __('Activo', 'mad-oficina'), 'paused' => __('En pausa', 'mad-oficina'), 'archived' => __('Archivado', 'mad-oficina')];
?>
<div class="wrap madof-wrap">
    <?php AdminUi::header(__('Gerencia', 'mad-oficina'), __('Clientes', 'mad-oficina'), __('Cada cliente tiene un código corto (el que usas en Calendar), sus dominios, su carpeta de Drive, sus contactos y los usuarios que entran al portal.', 'mad-oficina')); ?>

    <p><a class="madof-btn" href="<?php echo esc_url(Actions::client_url(0, ['new' => 1])); ?>"><?php echo AdminUi::icon('plus'); ?> <?php esc_html_e('Nuevo cliente', 'mad-oficina'); ?></a></p>

    <?php if ( ! $clients ) : ?>
        <div class="madof-card"><p><?php esc_html_e('Todavía no hay clientes. Crea el primero o carga los datos demo para probar.', 'mad-oficina'); ?></p></div>
    <?php else : ?>
    <table class="madof-table">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Código', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Cliente', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Dominios', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Contactos', 'mad-oficina'); ?></th>
            <th scope="col"><?php esc_html_e('Portal', 'mad-oficina'); ?></th>
            <?php do_action('madoficina_client_list_head'); ?>
            <th scope="col"><?php esc_html_e('Estado', 'mad-oficina'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $clients as $c ) : ?>
            <tr>
                <td><span class="madof-code"><?php echo esc_html($c['code']); ?></span></td>
                <td><a href="<?php echo esc_url(Actions::client_url($c['id'])); ?>"><strong><?php echo esc_html($c['name']); ?></strong></a>
                    <?php if ( $c['is_demo'] ) : ?><span class="madof-pill is-demo">DEMO</span><?php endif; ?></td>
                <td><?php echo esc_html(implode(', ', $c['domains'])); ?></td>
                <td><?php echo (int) count(Clients::contacts($c['id'])); ?></td>
                <td><?php echo (int) count(Clients::users($c['id'])); ?></td>
                <?php do_action('madoficina_client_list_cell', $c); ?>
                <td><span class="madof-pill"><?php echo esc_html($status_labels[$c['status']] ?? $c['status']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <section class="madof-card is-muted">
        <span class="madof-kicker"><?php esc_html_e('Datos demo', 'mad-oficina'); ?></span>
        <p><?php esc_html_e('Tres clientes ficticios marcados como DEMO, con contactos, usuarios, tareas, ideas y una reunión, para probar sin tocar tus datos reales.', 'mad-oficina'); ?></p>
        <div class="madof-actions">
            <?php echo AdminUi::form_open('demo_seed', ''); ?>
                <button class="madof-btn is-ghost"><?php echo Seeder::has_demo() ? esc_html__('Regenerar datos demo', 'mad-oficina') : esc_html__('Crear datos demo', 'mad-oficina'); ?></button>
            </form>
            <?php if ( Seeder::has_demo() ) : ?>
            <?php echo AdminUi::form_open('demo_purge', ''); ?>
                <button class="madof-btn is-ghost"><?php esc_html_e('Borrar datos demo', 'mad-oficina'); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </section>
</div>
