<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** A standalone public intake, never a general exception to private WordPress. */
final class Registration
{
    public const OPTION = 'igreja_erp_registration';
    public const ACTION = 'member_registration';
    private const GUARD_PREFIX = 'igreja_erp_guard_';

    public static function fields(): array
    {
        return [
            'nome_completo' => ['label' => 'Nome completo', 'type' => 'text', 'max' => 180, 'autocomplete' => 'name', 'locked' => true],
            'nome_social_apelido' => ['label' => 'Como prefere ser chamado(a)', 'type' => 'text', 'max' => 100, 'autocomplete' => 'nickname'],
            'data_nascimento' => ['label' => 'Data de nascimento', 'type' => 'date', 'max' => 10, 'autocomplete' => 'bday'],
            'email' => ['label' => 'E-mail', 'type' => 'email', 'max' => 150, 'autocomplete' => 'email'],
            'celular_whatsapp' => ['label' => 'WhatsApp com DDD', 'type' => 'tel', 'max' => 20, 'autocomplete' => 'tel'],
            'profissao' => ['label' => 'Profissão', 'type' => 'text', 'max' => 120, 'autocomplete' => 'organization-title'],
            'sexo' => ['label' => 'Sexo', 'type' => 'select', 'options' => ['M' => 'Masculino', 'F' => 'Feminino']],
            'estado_civil' => ['label' => 'Estado civil', 'type' => 'select', 'options' => array_combine(Membros::ESTADOS_CIVIS, Membros::ESTADOS_CIVIS)],
        ];
    }

    public static function normalize_fields(mixed $input): array
    {
        $result = [];
        foreach (self::fields() as $key => $field) {
            $result[$key] = !empty($field['locked']) || (is_array($input) && in_array($input[$key] ?? null, [true, 1, '1'], true));
        }
        return $result;
    }

    public static function settings(): array
    {
        $value = get_option(self::OPTION, []);
        $value = is_array($value) ? $value : [];
        $defaults = ['enabled' => true, 'site_key' => '', 'secret_key' => '', 'captcha_enabled' => !empty($value['site_key']) || !empty($value['secret_key']), 'fields' => array_fill_keys(array_keys(self::fields()), true)];
        $settings = array_replace($defaults, $value);
        $settings['fields'] = self::normalize_fields($settings['fields']);
        return $settings;
    }

    public static function ready(): bool
    {
        $settings = self::settings();
        return (bool) $settings['enabled'];
    }

    public static function uses_turnstile(): bool
    {
        return (bool) self::settings()['captcha_enabled'];
    }

    public static function field_enabled(string $key): bool
    {
        return self::settings()['fields'][$key] ?? false;
    }

    public static function public_input(array $input): array
    {
        $enabled = array_filter(self::settings()['fields']);
        return array_intersect_key($input, $enabled);
    }

    public static function url(): string
    {
        // Works with plain permalinks, subdirectory installations and Multisite.
        return add_query_arg('igreja_cadastro', '1', home_url('/'));
    }

    public static function register(): void
    {
        Access::register_public_route([self::class, 'is_public_request'], [self::class, 'page']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_igreja_erp_save_registration', [self::class, 'save_settings']);
        add_action('igreja_erp_cleanup_registration', [self::class, 'cleanup']);
    }

    public static function is_public_request(): bool
    {
        if (($_GET['igreja_cadastro'] ?? null) !== '1' || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST', 'HEAD'], true)) {
            return false;
        }
        // A switch on a search/feed/REST/other page must not open that resource.
        if (array_diff(array_keys($_GET), ['igreja_cadastro', 'enviado'])) {
            return false;
        }
        $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/';
        return is_string($path) && untrailingslashit($path) === untrailingslashit($home);
    }

    public static function remote_ip(): string
    {
        // Do not trust arbitrary client-supplied forwarding headers.
        $value = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    /** Atomic option insert covers concurrent replays, including persistent caches. */
    private static function claim(string $key, int $expires): bool
    {
        $name = self::GUARD_PREFIX . $key;
        if ((int) get_option($name, 0) && (int) get_option($name) < time()) {
            delete_option($name);
        }
        return add_option($name, $expires, '', false);
    }

    public static function cleanup(): void
    {
        global $wpdb;
        // These are non-autoloading, short-lived guards, not member information.
        $names = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 1000', $wpdb->options, $wpdb->esc_like(self::GUARD_PREFIX) . '%', time()));
        foreach ($names as $name) {
            delete_option($name);
        }
    }

    public static function authorize_submission(string $token): bool|\WP_Error
    {
        $error = static fn () => new \WP_Error('igreja_erp_captcha', 'Não foi possível confirmar a verificação de segurança. Atualize a verificação e tente novamente.');
        if (!self::ready()) {
            return $error();
        }
        if (!wp_next_scheduled('igreja_erp_cleanup_registration')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'igreja_erp_cleanup_registration');
        }
        $ip_hash = hash_hmac('sha256', self::remote_ip(), wp_salt('nonce'));
        $window = (int) floor(time() / (10 * MINUTE_IN_SECONDS));
        $claimed = false;
        for ($slot = 0; $slot < 20; $slot++) {
            if (self::claim('ip_' . $ip_hash . '_' . $window . '_' . $slot, time() + 20 * MINUTE_IN_SECONDS)) {
                $claimed = true;
                break;
            }
        }
        if (!$claimed) {
            return new \WP_Error('igreja_erp_limite', 'Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.');
        }
        // CAPTCHA is optional, but rate limiting remains active for every submission.
        if (!self::uses_turnstile()) {
            return true;
        }
        $settings = self::settings();
        if ($settings['site_key'] === '' || $settings['secret_key'] === '' || $token === '' || strlen($token) > 2048) {
            return $error();
        }
        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'redirection' => 0,
            'limit_response_size' => 16384,
            'body' => ['secret' => $settings['secret_key'], 'response' => $token, 'remoteip' => self::remote_ip()],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $error();
        }
        $result = json_decode(wp_remote_retrieve_body($response), true);
        $hostname = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (!is_array($result) || ($result['success'] ?? null) !== true || ($result['action'] ?? null) !== self::ACTION || !is_string($result['hostname'] ?? null) || strtolower($result['hostname']) !== $hostname) {
            return $error();
        }
        // Cloudflare replay checks are eventually consistent; enforce locally too.
        if (!self::claim('token_' . hash_hmac('sha256', $token, wp_salt('nonce')), time() + 10 * MINUTE_IN_SECONDS)) {
            return $error();
        }
        return true;
    }

    public static function page(): void
    {
        if (!self::is_public_request()) {
            return;
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        $error = '';
        $values = [];
        $success = ($_GET['enviado'] ?? null) === '1';
        if (!self::ready()) {
            status_header(503);
            header('Retry-After: 3600');
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $success = false;
            $nonce = $_POST['_wpnonce'] ?? null;
            if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'igreja_erp_public_registration')) {
                $error = 'A página expirou. Recarregue e preencha o cadastro novamente.';
                status_header(403);
            } elseif (!empty($_POST['website'])) {
                $error = 'Não foi possível enviar o cadastro. Recarregue e tente novamente.';
                status_header(400);
            } else {
                $values = isset($_POST['membro']) && is_array($_POST['membro']) ? wp_unslash($_POST['membro']) : [];
                $token = is_string($_POST['cf-turnstile-response'] ?? null) ? wp_unslash($_POST['cf-turnstile-response']) : '';
                $saved = Membros::cadastrar_visitante($values, $token);
                if (is_wp_error($saved)) {
                    $error = $saved->get_error_message();
                    status_header(422);
                } else {
                    wp_safe_redirect(add_query_arg('enviado', '1', self::url()), 303);
                    exit;
                }
            }
        }
        $settings = self::settings();
        $logo = Settings::logo_url();
        require IGREJA_ERP_MEMBROS_DIR . 'includes/views/view-public-registration.php';
        exit;
    }

    public static function menu(): void
    {
        add_submenu_page('igreja-erp-core', 'Cadastro público', 'Cadastro público', 'erp_gerenciar_configuracoes', 'igreja-erp-cadastro', [self::class, 'render_settings']);
    }

    public static function render_settings(): void
    {
        if (!current_user_can('erp_gerenciar_configuracoes')) {
            wp_die('Você não tem permissão para configurar o cadastro.', '', ['response' => 403]);
        }
        $settings = self::settings();
        require IGREJA_ERP_MEMBROS_DIR . 'includes/views/view-registration-settings.php';
    }

    public static function save_settings(): void
    {
        if (!current_user_can('erp_gerenciar_configuracoes')) {
            wp_die('Você não tem permissão para configurar o cadastro.', '', ['response' => 403]);
        }
        check_admin_referer('igreja_erp_save_registration');
        $settings = self::settings();
        foreach (['site_key' => 100, 'secret_key' => 200] as $key => $limit) {
            $value = $_POST[$key] ?? '';
            if (!is_string($value) || ($value !== '' && !preg_match('/^[A-Za-z0-9_-]{1,' . $limit . '}$/D', $value))) {
                wp_die('As chaves Turnstile possuem formato inválido.', '', ['response' => 400]);
            }
            if ($key === 'site_key' || $value !== '') {
                $settings[$key] = $value;
            }
        }
        $settings['enabled'] = ($_POST['enabled'] ?? '') === '1';
        $settings['captcha_enabled'] = ($_POST['captcha_enabled'] ?? '') === '1';
        $settings['fields'] = self::normalize_fields($_POST['fields'] ?? []);
        if (($_POST['remove_secret'] ?? '') === '1') {
            $settings['secret_key'] = '';
            $settings['captcha_enabled'] = false;
        }
        if ($settings['captcha_enabled'] && ($settings['site_key'] === '' || $settings['secret_key'] === '')) {
            wp_die('Para habilitar o Turnstile, informe as duas chaves. Você pode deixar a opção desmarcada para usar o formulário sem CAPTCHA.', '', ['response' => 400]);
        }
        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(admin_url('admin.php?page=igreja-erp-cadastro&salvo=1'));
        exit;
    }
}
