<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Per-site transfer UI. Addons provide their own validators and persistence. */
final class Transfer
{
    private static array $providers = [];
    private static array $settings = [];
    public const MAX_BYTES = 2097152;

    public static function register(): void
    {
        self::register_settings('igreja', Settings::OPTION, static function () {
            $data = Settings::all();
            $data['logo_id'] = 0;
            $data['logo_url'] = Settings::logo_url();
            return $data;
        }, static function ($input) {
            if (!is_array($input) || array_diff(array_keys($input), array_keys(Settings::defaults()))) {
                return new \WP_Error('transfer_settings', 'Configurações da igreja com campos desconhecidos.');
            }
            require_once ABSPATH . 'wp-admin/includes/template.php';
            $before = count(get_settings_errors(Settings::OPTION));
            $clean = Settings::sanitize($input);
            $errors = array_slice(get_settings_errors(Settings::OPTION), $before);
            return $errors ? new \WP_Error('transfer_settings', implode(' ', array_column($errors, 'message'))) : $clean;
        });
        self::register_provider('settings', [
            'name' => 'Configurações', 'cap' => 'erp_gerenciar_configuracoes', 'extension' => 'json',
            'export' => [self::class, 'export_settings'], 'preview' => [self::class, 'preview_settings'], 'apply' => [self::class, 'apply_settings'],
        ]);
        add_action('admin_menu', [self::class, 'menu'], 30);
        foreach (['export', 'preview', 'apply'] as $action) {
            add_action('admin_post_igreja_erp_transfer_' . $action, [self::class, $action . '_handler']);
        }
    }

    public static function register_provider(string $key, array $provider): void
    {
        self::$providers[$key] = $provider;
    }

    public static function register_settings(string $key, string $option, callable $export, callable $validate): void
    {
        self::$settings[$key] = compact('option', 'export', 'validate');
    }

    public static function export_settings(): string|\WP_Error
    {
        if (!current_user_can('erp_gerenciar_configuracoes')) { return new \WP_Error('transfer_cap', 'Acesso não autorizado.'); }
        $data = [];
        foreach (self::$settings as $key => $provider) {
            $data[$key] = ($provider['export'])();
        }
        return wp_json_encode(['format' => 'appshalom', 'version' => 1, 'type' => 'settings', 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function preview_settings(string $content): array|\WP_Error
    {
        if (!current_user_can('erp_gerenciar_configuracoes')) { return new \WP_Error('transfer_cap', 'Acesso não autorizado.'); }
        $packet = json_decode($content, true, 32);
        if (!is_array($packet) || ($packet['format'] ?? '') !== 'appshalom' || ($packet['version'] ?? 0) !== 1 || ($packet['type'] ?? '') !== 'settings' || !is_array($packet['data'] ?? null) || !$packet['data']) {
            return new \WP_Error('transfer_json', 'Selecione um JSON de configurações exportado pelo App Shalom.');
        }
        $options = [];
        $before = [];
        $rows = [];
        foreach ($packet['data'] as $key => $input) {
            if (!isset(self::$settings[$key])) {
                return new \WP_Error('transfer_addon', 'Configuração não reconhecida: ' . sanitize_text_field((string) $key) . '. Ative o addon correspondente.');
            }
            $provider = self::$settings[$key];
            $clean = ($provider['validate'])($input);
            if (is_wp_error($clean)) { return $clean; }
            $options[$provider['option']] = $clean;
            $before[$provider['option']] = hash('sha256', wp_json_encode(get_option($provider['option'], null)));
            $rows[] = $key;
            if ($key === 'igreja') {
                $rows[] = 'Nome: ' . ($clean['nome_curto'] ?? '');
                $rows[] = 'Acesso ao site: ' . (!empty($clean['restrito']) ? 'restrito a logados' : 'público');
                $rows[] = 'Logo: ' . ($clean['logo_url'] ?: 'sem imagem');
            }
        }
        return ['options' => $options, 'before' => $before, 'summary' => count($options) . ' grupo(s) de configurações serão aplicados.', 'rows' => $rows];
    }

    public static function apply_settings(array $plan): array|\WP_Error
    {
        if (!current_user_can('erp_gerenciar_configuracoes')) { return new \WP_Error('transfer_cap', 'Acesso não autorizado.'); }
        if (!is_array($plan['options'] ?? null) || !is_array($plan['before'] ?? null) || array_diff(array_keys($plan['options']), array_column(self::$settings, 'option')) || array_keys($plan['options']) !== array_keys($plan['before'])) { return new \WP_Error('transfer_plan', 'Prévia de configurações inválida.'); }
        foreach ($plan['before'] as $option => $hash) {
            if (!hash_equals($hash, hash('sha256', wp_json_encode(get_option($option, null))))) {
                return new \WP_Error('transfer_changed', 'As configurações mudaram após a prévia. Envie o arquivo novamente para conferir.');
            }
        }
        $backup = [];
        foreach ($plan['options'] as $option => $value) {
            $backup[$option] = get_option($option, null);
            update_option($option, $value, false);
            if (get_option($option) !== $value) {
                foreach ($backup as $restore => $old) {
                    $old === null ? delete_option($restore) : update_option($restore, $old, false);
                }
                return new \WP_Error('transfer_save', 'Não foi possível aplicar as configurações. Os valores anteriores foram restaurados.');
            }
        }
        return ['message' => 'Configurações importadas.'];
    }

    public static function menu(): void
    {
        $cap = current_user_can('erp_gerenciar_configuracoes') ? 'erp_gerenciar_configuracoes' : 'erp_gerenciar_membros';
        add_submenu_page('igreja-erp-core', 'Importar / exportar', 'Importar / exportar', $cap, 'igreja-erp-transfer', [self::class, 'render']);
    }

    private static function provider(mixed $type): array
    {
        if (!is_string($type) || !isset(self::$providers[$type])) {
            wp_die('Tipo de importação indisponível. Ative o addon necessário.', '', ['response' => 400]);
        }
        $provider = self::$providers[$type];
        if (!current_user_can($provider['cap'])) {
            wp_die('Você não tem permissão para transferir estes dados.', '', ['response' => 403]);
        }
        return $provider;
    }

    private static function redirect(string $message, bool $error = false, string $token = ''): void
    {
        set_transient('igreja_transfer_notice_' . get_current_user_id(), compact('message', 'error'), 120);
        wp_safe_redirect(add_query_arg($token ? ['preview' => $token] : [], admin_url('admin.php?page=igreja-erp-transfer')));
        exit;
    }

    private static function key(string $token): string
    {
        return 'igreja_transfer_' . get_current_user_id() . '_' . $token;
    }

    public static function export_handler(): void
    {
        $type = $_POST['type'] ?? '';
        $provider = self::provider($type);
        check_admin_referer('igreja_erp_transfer_export');
        $content = ($provider['export'])();
        if (is_wp_error($content)) { self::redirect($content->get_error_message(), true); }
        nocache_headers();
        header('Content-Type: ' . ($provider['extension'] === 'csv' ? 'text/csv' : 'application/json') . '; charset=UTF-8');
        header('Content-Disposition: attachment; filename="appshalom-' . $type . '-' . current_time('Y-m-d') . '.' . $provider['extension'] . '"');
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }

    public static function preview_handler(): void
    {
        $type = $_POST['type'] ?? '';
        $provider = self::provider($type);
        check_admin_referer('igreja_erp_transfer_preview');
        $file = $_FILES['transfer_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > self::MAX_BYTES) {
            self::redirect('Envie um arquivo válido de até 2 MB.', true);
        }
        $extension = strtolower(pathinfo(is_string($file['name'] ?? null) ? $file['name'] : '', PATHINFO_EXTENSION));
        if ($extension !== $provider['extension']) { self::redirect('Use um arquivo .' . $provider['extension'] . '.', true); }
        $content = file_get_contents($file['tmp_name']);
        $plan = ($provider['preview'])($content);
        if (is_wp_error($plan)) { self::redirect($plan->get_error_message(), true); }
        $token = bin2hex(random_bytes(16));
        set_transient(self::key($token), ['type' => $type, 'plan' => $plan, 'blog' => get_current_blog_id()], 30 * MINUTE_IN_SECONDS);
        self::redirect('Confira a prévia antes de importar.', false, $token);
    }

    public static function apply_handler(): void
    {
        check_admin_referer('igreja_erp_transfer_apply');
        $token = $_POST['token'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) { wp_die('Prévia inválida.', '', ['response' => 400]); }
        $stage = get_transient(self::key($token));
        if (!is_array($stage) || ($stage['blog'] ?? null) !== get_current_blog_id()) { self::redirect('A prévia expirou. Envie o arquivo novamente.', true); }
        $provider = self::provider($stage['type']);
        // Atomic claim prevents double clicks from applying a plan twice.
        $lock = 'igreja_transfer_lock_' . $token;
        if (!add_option($lock, time(), '', false)) { self::redirect('Esta importação já está sendo processada.', true); }
        try {
            $result = ($provider['apply'])($stage['plan']);
            if (!is_wp_error($result)) { delete_transient(self::key($token)); }
        } finally { delete_option($lock); }
        if (is_wp_error($result)) { self::redirect($result->get_error_message(), true); }
        self::redirect($result['message'] ?? 'Importação concluída.');
    }

    public static function render(): void
    {
        if (!current_user_can('erp_gerenciar_configuracoes') && !current_user_can('erp_gerenciar_membros')) { wp_die('Acesso não autorizado.', '', ['response' => 403]); }
        $notice = get_transient('igreja_transfer_notice_' . get_current_user_id());
        delete_transient('igreja_transfer_notice_' . get_current_user_id());
        $token = is_string($_GET['preview'] ?? null) ? $_GET['preview'] : '';
        $stage = preg_match('/^[a-f0-9]{32}$/D', $token) ? get_transient(self::key($token)) : false;
        ?>
        <div class="wrap"><h1>Importar / exportar dados</h1>
        <?php if (is_array($notice)): ?><div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>
        <?php foreach (self::$providers as $type => $provider): if (!current_user_can($provider['cap'])) { continue; } ?>
        <section class="card" style="max-width:900px"><h2><?php echo esc_html($provider['name']); ?></h2>
        <p><?php echo $type === 'settings' ? 'JSON com a identidade da igreja e configurações dos addons ativos. Senhas, chaves secretas e identificadores internos não são exportados. A logo é transferida pela URL.' : 'CSV UTF-8 para Excel, até 1.000 membros por importação. Inclui dados pessoais e vínculo familiar. Importar não aprova nem envia convites; confira e aprove na área de Membros. Para obter o modelo de colunas, exporte o cadastro atual.'; ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="igreja_erp_transfer_export"><input type="hidden" name="type" value="<?php echo esc_attr($type); ?>"><?php wp_nonce_field('igreja_erp_transfer_export'); ?><button class="button">Exportar <?php echo esc_html($provider['name']); ?></button></form>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px"><input type="hidden" name="action" value="igreja_erp_transfer_preview"><input type="hidden" name="type" value="<?php echo esc_attr($type); ?>"><?php wp_nonce_field('igreja_erp_transfer_preview'); ?><label>Arquivo .<?php echo esc_html($provider['extension']); ?> <input type="file" name="transfer_file" accept=".<?php echo esc_attr($provider['extension']); ?>" required></label> <button class="button button-primary">Conferir importação</button></form>
        </section><?php endforeach; ?>
        <?php if (!isset(self::$providers['members'])): ?><p>Ative o addon Membros para importar ou exportar cadastros.</p><?php endif; ?>
        <?php if (is_array($stage) && isset(self::$providers[$stage['type']]) && current_user_can(self::$providers[$stage['type']]['cap'])): ?>
        <section class="card" style="max-width:900px"><h2>Prévia da importação</h2><p><?php echo esc_html($stage['plan']['summary']); ?></p><ul><?php foreach (array_slice($stage['plan']['rows'] ?? [], 0, 15) as $row): ?><li><?php echo esc_html(is_string($row) ? $row : ($row['label'] ?? '')); ?></li><?php endforeach; ?></ul>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="igreja_erp_transfer_apply"><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>"><?php wp_nonce_field('igreja_erp_transfer_apply'); ?><button class="button button-primary">Confirmar importação</button></form></section>
        <?php endif; ?></div>
        <?php
    }
}
