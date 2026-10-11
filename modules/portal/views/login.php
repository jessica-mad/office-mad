<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="madof-portal-login">
    <span class="madof-kicker"><?php esc_html_e('MAD · Oficina', 'mad-oficina'); ?></span>
    <h2><?php esc_html_e('Bienvenido a la oficina', 'mad-oficina'); ?></h2>
    <p><?php esc_html_e('Entra con tu usuario para ver cómo va tu proyecto, tus reuniones y tus tareas.', 'mad-oficina'); ?></p>
    <?php wp_login_form(['redirect' => get_permalink() ?: home_url('/'), 'remember' => true]); ?>
    <p><a href="<?php echo esc_url(wp_lostpassword_url(get_permalink() ?: home_url('/'))); ?>"><?php esc_html_e('¿Has olvidado la contraseña?', 'mad-oficina'); ?></a></p>
</div>
<style>
.madof-portal-login{max-width:440px;margin:40px auto;padding:32px;background:var(--y) var(--madof-dots-yellow);background-size:22px 22px;border:2px solid var(--k);border-radius:22px;font:300 16px/1.6 var(--madof-font);color:var(--k)}
.madof-portal-login h2{font:400 30px/1.15 var(--madof-font);margin:8px 0}
.madof-portal-login form p{display:flex;flex-direction:column;gap:4px;margin:0 0 12px}
.madof-portal-login form p.login-remember{flex-direction:row}
.madof-portal-login input[type=text],.madof-portal-login input[type=password]{border:2px solid var(--k);border-radius:12px;padding:10px 12px;font:300 16px var(--madof-font);background:var(--c)}
.madof-portal-login input[type=submit]{background:var(--k);color:var(--y);border:0;border-radius:999px;padding:11px 22px;font:600 15px var(--madof-font);cursor:pointer}
.madof-portal-login a{color:var(--k)}
</style>
