<?php
/**
 * Plugin Name: Carolina – Deploy automático (WordPress → GitHub)
 * Description: Cuando se crea, edita, publica, despublica o borra una propiedad, dispara el workflow de GitHub Actions que reconstruye y publica el sitio Astro. El token se configura en Ajustes → Deploy automático.
 * Version: 2.0
 */
if (!defined('ABSPATH')) exit;

const CAROLINA_CPT   = 'propiedad';
const CAROLINA_DELAY = 45; // segundos: junta varios guardados seguidos en un solo build

/* ---------- Configuración ---------- */

function carolina_token() {
    if (defined('CAROLINA_GH_TOKEN') && CAROLINA_GH_TOKEN) return CAROLINA_GH_TOKEN; // opcional: wp-config.php
    return (string) get_option('carolina_gh_token', '');
}
function carolina_repo()  { return trim((string) get_option('carolina_gh_repo', 'codigoraul/propiedadescarolina.cl')); }
function carolina_event() { return trim((string) get_option('carolina_gh_event', 'wp-update')); }

/* ---------- Disparo ---------- */

function carolina_send_dispatch() {
    $token = carolina_token();
    if (!$token) return ['code' => 0, 'msg' => 'Falta el token'];
    $res = wp_remote_post('https://api.github.com/repos/' . carolina_repo() . '/dispatches', [
        'timeout' => 15,
        'headers' => [
            'Accept'               => 'application/vnd.github+json',
            'Authorization'        => 'Bearer ' . $token,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'carolina-deploy-webhook',
            'Content-Type'         => 'application/json',
        ],
        'body' => wp_json_encode(['event_type' => carolina_event()]),
    ]);
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $msg  = is_wp_error($res) ? $res->get_error_message() : '';
    $out  = ['code' => $code, 'msg' => $msg, 't' => current_time('mysql')];
    update_option('carolina_last_deploy', $out, false);
    if ($code !== 204) error_log('[carolina-deploy] GitHub respondió ' . ($code ?: $msg));
    return $out;
}

function carolina_schedule_deploy() {
    if (!carolina_token()) return;
    if (!wp_next_scheduled('carolina_run_deploy')) {
        wp_schedule_single_event(time() + CAROLINA_DELAY, 'carolina_run_deploy');
    }
}
add_action('carolina_run_deploy', 'carolina_send_dispatch');

/* ---------- Eventos de propiedades ---------- */

add_action('acf/save_post', function ($post_id) {
    if (get_post_type($post_id) === CAROLINA_CPT) carolina_schedule_deploy();
}, 30);

add_action('transition_post_status', function ($new, $old, $post) {
    if ($post->post_type !== CAROLINA_CPT || $new === $old) return;
    if ($new === 'publish' || $old === 'publish') carolina_schedule_deploy();
}, 10, 3);

add_action('before_delete_post', function ($post_id) {
    if (get_post_type($post_id) === CAROLINA_CPT) carolina_schedule_deploy();
});

/* ---------- Página de ajustes (solo administradores) ---------- */

add_action('admin_menu', function () {
    add_options_page('Deploy automático', 'Deploy automático', 'manage_options', 'carolina-deploy', 'carolina_settings_page');
});

add_action('admin_init', function () {
    register_setting('carolina_deploy', 'carolina_gh_token', ['sanitize_callback' => function ($v) {
        $v = trim((string) $v);
        return $v === '' ? get_option('carolina_gh_token', '') : $v; // vacío = conservar el actual
    }]);
    register_setting('carolina_deploy', 'carolina_gh_repo',  ['sanitize_callback' => fn($v) => preg_replace('~[^A-Za-z0-9_.\-/]~', '', (string) $v)]);
    register_setting('carolina_deploy', 'carolina_gh_event', ['sanitize_callback' => fn($v) => preg_replace('~[^A-Za-z0-9_.\-]~', '', (string) $v)]);
});

// Botón "Publicar ahora / Probar conexión"
add_action('admin_post_carolina_deploy_now', function () {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('carolina_deploy_now');
    carolina_send_dispatch();
    wp_safe_redirect(admin_url('options-general.php?page=carolina-deploy&probado=1'));
    exit;
});

// Borrar el token guardado
add_action('admin_post_carolina_token_clear', function () {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('carolina_token_clear');
    delete_option('carolina_gh_token');
    wp_safe_redirect(admin_url('options-general.php?page=carolina-deploy&borrado=1'));
    exit;
});

function carolina_settings_page() {
    if (!current_user_can('manage_options')) return;
    $tiene = (bool) carolina_token();
    $desde_config = defined('CAROLINA_GH_TOKEN') && CAROLINA_GH_TOKEN;
    $last = get_option('carolina_last_deploy');
    ?>
    <div class="wrap">
      <h1>Deploy automático</h1>
      <p>Cada vez que se crea, edita, publica o borra una propiedad, se avisa a GitHub para reconstruir y publicar el sitio. Los cambios se ven en la web 2–3 minutos después.</p>

      <?php if (!empty($_GET['probado'])): $c = $last['code'] ?? 0; ?>
        <div class="notice notice-<?php echo $c === 204 ? 'success' : 'error'; ?>"><p>
          <?php echo $c === 204 ? 'Aviso enviado correctamente. Revisa la pestaña Actions del repositorio.' : 'Falló el aviso (código ' . intval($c) . ' ' . esc_html($last['msg'] ?? '') . '). Revisa el token, el repositorio y que el token tenga permiso Contents: Read and write.'; ?>
        </p></div>
      <?php endif; ?>
      <?php if (!empty($_GET['borrado'])): ?><div class="notice notice-info"><p>Token borrado.</p></div><?php endif; ?>

      <form method="post" action="options.php">
        <?php settings_fields('carolina_deploy'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="carolina_gh_token">Token de GitHub</label></th>
            <td>
              <?php if ($desde_config): ?>
                <p><strong>Definido en wp-config.php</strong> (tiene prioridad sobre este campo).</p>
              <?php else: ?>
                <input type="password" id="carolina_gh_token" name="carolina_gh_token" class="regular-text" value="" autocomplete="new-password"
                       placeholder="<?php echo $tiene ? '•••••••• (guardado — escribe otro solo para reemplazarlo)' : 'github_pat_...'; ?>">
                <p class="description">Token fine-grained del repositorio con permiso <em>Contents: Read and write</em>. Se guarda en la base de datos y nunca se vuelve a mostrar.</p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="carolina_gh_repo">Repositorio</label></th>
            <td><input type="text" id="carolina_gh_repo" name="carolina_gh_repo" class="regular-text" value="<?php echo esc_attr(carolina_repo()); ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="carolina_gh_event">Evento</label></th>
            <td><input type="text" id="carolina_gh_event" name="carolina_gh_event" class="regular-text" value="<?php echo esc_attr(carolina_event()); ?>">
              <p class="description">Debe coincidir con el <code>types</code> de <code>repository_dispatch</code> en el workflow (wp-update).</p></td>
          </tr>
        </table>
        <?php submit_button('Guardar'); ?>
      </form>

      <hr>
      <h2>Estado</h2>
      <p>Token: <strong><?php echo $tiene ? 'configurado' : 'no configurado'; ?></strong>.
        <?php if ($last): ?> Último aviso: <?php echo esc_html($last['t'] ?? ''); ?> — <?php echo ($last['code'] ?? 0) === 204 ? 'enviado correctamente' : 'falló (código ' . intval($last['code'] ?? 0) . ')'; ?>.<?php endif; ?></p>

      <?php if ($tiene): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
          <input type="hidden" name="action" value="carolina_deploy_now">
          <?php wp_nonce_field('carolina_deploy_now'); submit_button('Publicar ahora / probar conexión', 'secondary', 'submit', false); ?>
        </form>
        <?php if (!$desde_config): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
          <input type="hidden" name="action" value="carolina_token_clear">
          <?php wp_nonce_field('carolina_token_clear'); submit_button('Borrar token', 'delete', 'submit', false); ?>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

/* ---------- Aviso en la lista de Propiedades ---------- */

add_action('admin_notices', function () {
    $s = get_current_screen();
    if (!$s || $s->post_type !== CAROLINA_CPT || $s->base !== 'edit') return;
    $last = get_option('carolina_last_deploy');
    if (!carolina_token()) {
        if (current_user_can('manage_options')) echo '<div class="notice notice-warning"><p>El deploy automático no está configurado. <a href="' . esc_url(admin_url('options-general.php?page=carolina-deploy')) . '">Configurar token</a>.</p></div>';
    } elseif ($last) {
        $ok = ($last['code'] ?? 0) === 204;
        printf('<div class="notice notice-%s"><p>Último aviso de publicación: %s — %s. Los cambios se ven en la web 2–3 minutos después.</p></div>',
            $ok ? 'info' : 'error', esc_html($last['t'] ?? ''), $ok ? 'enviado correctamente' : 'falló (código ' . intval($last['code'] ?? 0) . ')');
    }
});
