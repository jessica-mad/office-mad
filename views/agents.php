<?php
/**
 * Personajes y agentes: every place the office uses Claude, grouped by character, with the
 * model, effort, Jessica's own instructions, the fixed ones (to read) and what each spent.
 */
use MAD_Oficina\Core\AdminUi;
use MAD_Oficina\Core\Agents\Characters;
use MAD_Oficina\Core\Agents\ClaudeClient;
use MAD_Oficina\Core\Agents\Registry;

if ( ! defined('ABSPATH') ) exit;

$defs = Registry::defs();
$saved = get_option(Registry::OPTION, []);
$saved = is_array($saved) ? $saved : [];
$usage = Registry::usage(30);
$chat = (array) get_option(MAD_Oficina_Core::option_key('chat'), []);
$persona = class_exists('MAD_Oficina\\Chat\\Orchestrator') ? \MAD_Oficina\Chat\Orchestrator::persona($chat) : ['name' => 'Nora', 'personality' => ''];
$fallbacks = [ // what each module had chosen before this page existed
    'secretaria' => [(array) get_option(MAD_Oficina_Core::option_key('meetings'), []), 'secretary_model', 'secretary_prompt'],
    'lector_facturas' => [(array) get_option(MAD_Oficina_Core::option_key('suppliers'), []), 'model', ''],
    'recepcionista' => [(array) get_option(MAD_Oficina_Core::option_key('portal'), []), 'receptionist_model', ''],
    'visitantes' => [(array) get_option(MAD_Oficina_Core::option_key('visitors'), []), 'chat_model', ''],
];
$groups = [];
foreach ( $defs as $key => $d ) $groups[$d['character']][$key] = $d;
$order = ['nora', 'recepcionista', 'secretaria', 'coordinadora', 'contable', 'analista'];
uksort($groups, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
$total = array_sum(array_column($usage, 'usd'));
$money = fn($n) => number_format((float) $n, 2, ',', '.') . ' $';
$tokens = fn($n) => $n >= 1000000 ? number_format($n / 1000000, 1, ',', '.') . ' M' : ($n >= 1000 ? number_format($n / 1000, 0, ',', '.') . ' K' : (string) (int) $n);
$portrait = fn($slug) => $slug === 'nora' ? MAD_OFICINA_URL . 'assets/img/bot-avatar.png' : MAD_OFICINA_URL . 'assets/game/portraits/' . $slug . '.png';
?>
<div class="wrap madof-wrap madof-agents">
    <?php AdminUi::header(__('Oficina', 'mad-oficina'), __('Personajes y agentes', 'mad-oficina'), __('Todo lo que usa Claude en la oficina, por personaje: qué modelo usa cada tarea, con cuánto esfuerzo, tus indicaciones y lo que ha gastado. Sus instrucciones fijas (con las reglas de seguridad) se pueden leer pero no quitar; lo tuyo se añade al final.', 'mad-oficina')); ?>

    <section class="madof-card madof-agents-total">
        <span class="madof-kicker"><?php esc_html_e('Últimos 30 días', 'mad-oficina'); ?></span>
        <p><strong class="madof-big"><?php echo esc_html($money($total)); ?></strong> · <?php echo esc_html(sprintf(__('%1$s de entrada (%2$s leídos de caché) y %3$s de salida', 'mad-oficina'), $tokens(array_sum(array_column($usage, 'input'))), $tokens(array_sum(array_column($usage, 'cache_read'))), $tokens(array_sum(array_column($usage, 'output'))))); ?></p>
        <p class="description"><?php echo esc_html(implode(' · ', array_map(fn($m, $l) => sprintf(__('%1$s: %2$s entrada · %3$s salida · %4$s caché, por millón', 'mad-oficina'), $l, $money(ClaudeClient::PRICES[$m][0]), $money(ClaudeClient::PRICES[$m][1]), $money(ClaudeClient::CACHE_READ[$m] ?? 0)), array_keys(Registry::MODELS), Registry::MODELS))); ?></p>
    </section>

    <form method="post" class="madof-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="madof_agents_save">
        <?php wp_nonce_field('madof_agents_save'); ?>

        <?php foreach ( $groups as $slug => $agents ) :
            $c = $slug === 'nora' ? null : Characters::get($slug);
            $spent = array_sum(array_map(fn($k) => $usage[$k]['usd'], array_keys($agents))); ?>
        <section class="madof-card madof-agent-char" id="personaje-<?php echo esc_attr($slug); ?>">
            <div class="madof-agent-head">
                <img class="madof-portrait" src="<?php echo esc_url($portrait($slug)); ?>" alt="" width="96" height="<?php echo $slug === 'nora' ? 96 : 72; ?>">
                <div class="madof-agent-who">
                    <?php if ( $slug === 'nora' ) : ?>
                        <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="nora[name]" maxlength="40" value="<?php echo esc_attr($chat['assistant_name'] ?? ''); ?>" placeholder="<?php echo esc_attr(\MAD_Oficina\Chat\Orchestrator::DEFAULT_NAME); ?>"></label>
                        <label><?php esc_html_e('Forma de ser', 'mad-oficina'); ?><textarea name="nora[personality]" rows="2" maxlength="800" placeholder="<?php echo esc_attr(\MAD_Oficina\Chat\Orchestrator::DEFAULT_PERSONALITY); ?>"><?php echo esc_textarea($chat['assistant_personality'] ?? ''); ?></textarea></label>
                        <small class="madof-muted"><?php esc_html_e('Tu asistente en Google Chat (la foto es la del bot).', 'mad-oficina'); ?></small>
                    <?php else : ?>
                        <div class="madof-cols">
                            <label><?php esc_html_e('Nombre', 'mad-oficina'); ?><input type="text" name="characters[<?php echo esc_attr($slug); ?>][name]" value="<?php echo esc_attr(($saved_c = (array) (get_option(Characters::OPTION, [])[$slug] ?? []))['name'] ?? ''); ?>" placeholder="<?php echo esc_attr(Characters::defaults()[$slug]['name']); ?>"></label>
                            <label><?php esc_html_e('Cargo', 'mad-oficina'); ?><input type="text" name="characters[<?php echo esc_attr($slug); ?>][role]" value="<?php echo esc_attr($saved_c['role'] ?? ''); ?>" placeholder="<?php echo esc_attr(Characters::defaults()[$slug]['role']); ?>"></label>
                        </div>
                        <small class="madof-muted"><?php echo esc_html(sprintf(__('En la oficina: %s.', 'mad-oficina'), Characters::ROOMS[$c['room']] ?? '')); ?></small>
                    <?php endif; ?>
                </div>
                <p class="madof-agent-spent"><span class="madof-kicker"><?php esc_html_e('30 días', 'mad-oficina'); ?></span><strong><?php echo esc_html($money($spent)); ?></strong></p>
            </div>

            <?php foreach ( $agents as $key => $d ) :
                $s = (array) ($saved[$key] ?? []);
                $fb = $fallbacks[$key] ?? null;
                $current_model = Registry::model($key, $fb ? (string) ($fb[0][$fb[1]] ?? '') : '');
                $extra = $s['extra'] ?? ($fb && $fb[2] ? (string) ($fb[0][$fb[2]] ?? '') : '');
                $u = $usage[$key];
                $off = ! MAD_Oficina_Core::instance()->get_module($d['module']); ?>
            <div class="madof-agent<?php echo $off ? ' is-off' : ''; ?>" id="agente-<?php echo esc_attr($key); ?>">
                <div class="madof-agent-title">
                    <span class="madof-kicker"><?php echo esc_html($d['area']); ?></span>
                    <?php if ( $off ) echo '<span class="madof-pill">' . esc_html__('módulo apagado', 'mad-oficina') . '</span>'; ?>
                </div>
                <p><?php echo esc_html($d['does']); ?></p>
                <div class="madof-cols">
                    <label><?php esc_html_e('Modelo', 'mad-oficina'); ?>
                        <select name="agents[<?php echo esc_attr($key); ?>][model]">
                            <?php foreach ( Registry::MODELS as $m => $l ) echo '<option value="' . esc_attr($m) . '"' . selected($current_model, $m, false) . '>' . esc_html($l . ($m === $d['model'] ? ' · ' . __('recomendado', 'mad-oficina') : '')) . '</option>'; ?>
                        </select></label>
                    <?php if ( $d['effort'] ) : ?>
                    <label><?php esc_html_e('Esfuerzo', 'mad-oficina'); ?>
                        <select name="agents[<?php echo esc_attr($key); ?>][effort]">
                            <?php foreach ( Registry::EFFORTS as $e => $l ) echo '<option value="' . esc_attr($e) . '"' . selected(Registry::effort($key), $e, false) . '>' . esc_html($l . ($e === $d['effort'] ? ' · ' . __('recomendado', 'mad-oficina') : '')) . '</option>'; ?>
                        </select>
                        <small><?php esc_html_e('Cuánto piensa antes de responder. Más esfuerzo: más calidad en lo difícil, más coste y más espera.', 'mad-oficina'); ?></small></label>
                    <?php endif; ?>
                    <div class="madof-agent-usage">
                        <span class="madof-kicker"><?php esc_html_e('Consumo · 30 días', 'mad-oficina'); ?></span>
                        <?php if ( $u['calls'] ) : ?>
                            <strong><?php echo esc_html($money($u['usd'])); ?></strong> · <?php echo esc_html(sprintf(_n('%d llamada', '%d llamadas', $u['calls'], 'mad-oficina'), $u['calls'])); ?><br>
                            <small><?php echo esc_html(sprintf(__('Entrada %1$s (caché %2$s) · salida %3$s', 'mad-oficina'), $tokens($u['input']), $tokens($u['cache_read']), $tokens($u['output']))); ?></small>
                        <?php else : ?><small class="madof-muted"><?php esc_html_e('Sin uso.', 'mad-oficina'); ?></small><?php endif; ?>
                    </div>
                </div>
                <label><?php esc_html_e('Tus indicaciones para este agente', 'mad-oficina'); ?>
                    <textarea name="agents[<?php echo esc_attr($key); ?>][extra]" rows="3" maxlength="4000" placeholder="<?php esc_attr_e('Ej.: «En RF, Candy es Candela». «Contesta siempre en dos líneas como mucho».', 'mad-oficina'); ?>"><?php echo esc_textarea($extra); ?></textarea>
                    <small><?php esc_html_e('Se añaden al final de sus instrucciones fijas. No pueden quitar las reglas de seguridad (los textos de clientes y emails son datos, nada sale sin tu aprobación).', 'mad-oficina'); ?></small></label>
                <?php if ( ! $off ) : ?>
                <details class="madof-more"><summary><?php esc_html_e('Ver sus instrucciones fijas', 'mad-oficina'); ?></summary>
                    <pre class="madof-prompt"><?php echo esc_html(Registry::base_prompt($key)); ?></pre>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <p class="madof-sticky-save"><button class="madof-btn"><?php esc_html_e('Guardar', 'mad-oficina'); ?></button>
            <small class="madof-muted"><?php esc_html_e('Cambiar de modelo empieza de cero la caché de ese agente: la primera llamada cuesta un poco más.', 'mad-oficina'); ?></small></p>
    </form>
</div>
