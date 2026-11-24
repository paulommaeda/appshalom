<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Per-site church identity. No network-wide option or request-supplied tenant ID. */
final class Settings
{
    public const OPTION = 'igreja_erp_settings';
    private const VERSION = '1.0.0';
    private const CAPABILITY = 'erp_gerenciar_configuracoes';

    public static function defaults(): array
    {
        return [
            'nome_igreja' => 'Comunhão Cristã Shalom',
            'nome_curto' => 'CCShalom Curitiba',
            'logo_id' => 0,
            'logo_url' => 'https://ccshalom.com.br/curitiba/wp-content/uploads/sites/3/2024/09/logo-rodape.png',
            'cor_primaria' => '#000A52',
            'cor_destaque' => '#001BE1',
            'cor_fundo' => '#FFFFFF',
            'cor_texto' => '#000A52',
            'mensagem_login' => 'Ser como Ele. Servir como Ele.',
            'site' => 'https://ccshalom.com.br/curitiba/',
            'email' => 'shalom@comshalom.org.br',
            'telefone' => '41 98428-7328',
            'logradouro' => 'Rua Cascavel',
            'numero' => '750',
            'complemento' => '',
            'bairro' => 'Boqueirão',
            'cidade' => 'Curitiba',
            'uf' => 'PR',
            'cep' => '',
            'pais' => 'Brasil',
            'restrito' => true,
        ];
    }

    /** Seed exactly once. New releases never overwrite the church's customization. */
    public static function initialize(): void
    {
        if (get_option('igreja_erp_settings_version') === self::VERSION) {
            return;
        }
        if (!is_array(get_option(self::OPTION))) {
            $preset = apply_filters('igreja_erp_initial_settings', self::defaults(), get_current_blog_id());
            $preset = is_array($preset) ? $preset : [];
            add_option(self::OPTION, self::sanitize(array_replace(self::defaults(), $preset)), '', false);
        }
        if (!get_option('igreja_erp_tenant_id')) {
            add_option('igreja_erp_tenant_id', wp_generate_uuid4(), '', false);
        }
        update_option('igreja_erp_settings_version', self::VERSION, false);
    }

    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($stored) ? array_intersect_key($stored, self::defaults()) : []);
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::all()[$key] ?? $fallback;
    }

    public static function tenant_id(): string
    {
        return (string) get_option('igreja_erp_tenant_id', '');
    }

    public static function is_restricted(): bool
    {
        return in_array(self::get('restrito'), [true, 1, '1'], true);
    }

    public static function logo_url(): string
    {
        $id = absint(self::get('logo_id'));
        if ($id && wp_attachment_is_image($id)) {
            return (string) (wp_get_attachment_image_url($id, 'full') ?: '');
        }
        return esc_url_raw((string) self::get('logo_url'), ['https', 'http']);
    }

    private static function error(string $key, string $message): void
    {
        if (function_exists('add_settings_error')) {
            add_settings_error(self::OPTION, 'igreja_erp_' . $key, $message, 'error');
        }
    }

    /** Validate every field; invalid values retain their previous valid value. */
    public static function sanitize(mixed $input): array
    {
        $old = self::all();
        if (!is_array($input)) {
            self::error('formato', 'Não foi possível ler as configurações enviadas.');
            return $old;
        }
        $out = $old;
        $texts = [
            'nome_igreja' => 180, 'nome_curto' => 100, 'mensagem_login' => 180,
            'telefone' => 30, 'logradouro' => 200, 'numero' => 30,
            'complemento' => 100, 'bairro' => 100, 'cidade' => 100, 'pais' => 80,
        ];
        foreach ($texts as $key => $limit) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_scalar($input[$key])) {
                self::error($key, 'Um dos campos de texto possui formato inválido.');
                continue;
            }
            $value = sanitize_text_field((string) $input[$key]);
            if (!preg_match('/^.{0,' . $limit . '}$/us', $value) || (in_array($key, ['nome_igreja', 'nome_curto'], true) && $value === '')) {
                self::error($key, 'Informe o nome da igreja e respeite o tamanho máximo dos campos.');
                continue;
            }
            $out[$key] = $value;
        }
        foreach (['cor_primaria', 'cor_destaque', 'cor_fundo', 'cor_texto'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $color = is_string($input[$key]) ? sanitize_hex_color($input[$key]) : null;
            if (!$color) {
                self::error($key, 'Escolha uma cor válida, como #000A52.');
                continue;
            }
            $out[$key] = strtoupper($color);
        }
        foreach (['site', 'logo_url'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_string($input[$key])) {
                self::error($key, 'Informe um endereço de site ou imagem válido.');
                continue;
            }
            $url = esc_url_raw(trim($input[$key]), ['https', 'http']);
            if ($input[$key] !== '' && (!$url || !in_array(wp_parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || !wp_parse_url($url, PHP_URL_HOST))) {
                self::error($key, 'Informe uma URL completa iniciada por https://.');
                continue;
            }
            $out[$key] = $url;
        }
        if (array_key_exists('email', $input)) {
            $email = is_string($input['email']) ? sanitize_email($input['email']) : '';
            if (!is_string($input['email']) || ($input['email'] !== '' && !is_email($email))) {
                self::error('email', 'Informe um e-mail válido.');
            } else {
                $out['email'] = $email;
            }
        }
        if (array_key_exists('uf', $input)) {
            $uf = is_string($input['uf']) ? strtoupper(sanitize_text_field($input['uf'])) : 'INVALID';
            if ($uf === '' || preg_match('/^[A-Z]{2}$/D', $uf)) {
                $out['uf'] = $uf;
            } else {
                self::error('uf', 'Informe a sigla do estado com duas letras.');
            }
        }
        if (array_key_exists('cep', $input)) {
            $cep = is_scalar($input['cep']) ? preg_replace('/\D/', '', (string) $input['cep']) : 'INVALID';
            if ($cep === '' || strlen($cep) === 8) {
                $out['cep'] = $cep === '' ? '' : substr($cep, 0, 5) . '-' . substr($cep, 5);
            } else {
                self::error('cep', 'Informe um CEP com oito números.');
            }
        }
        if (array_key_exists('logo_id', $input)) {
            $raw = $input['logo_id'];
            if (!is_scalar($raw) || !preg_match('/^[0-9]+$/D', (string) $raw)) {
                self::error('logo', 'Selecione uma imagem válida na biblioteca.');
            } else {
                $id = absint($raw);
                if (!$id || (wp_attachment_is_image($id) && in_array(get_post_mime_type($id), ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif'], true))) {
                    $out['logo_id'] = $id;
                } else {
                    self::error('logo', 'A logo precisa ser uma imagem PNG, JPEG, WebP, GIF ou AVIF.');
                }
            }
        }
        if (array_key_exists('restrito', $input)) {
            if (in_array($input['restrito'], [true, false, 1, 0, '1', '0'], true)) {
                $out['restrito'] = in_array($input['restrito'], [true, 1, '1'], true);
            } else {
                self::error('restrito', 'A opção de acesso restrito possui valor inválido.');
            }
        }
        return $out;
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_igreja_erp_save_settings', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);
        add_action('login_enqueue_scripts', [self::class, 'login_assets']);
        add_action('wp_enqueue_scripts', [self::class, 'front_assets']);
        add_filter('login_headertext', static fn () => sanitize_text_field((string) self::get('nome_curto')));
        add_filter('login_headerurl', static fn () => esc_url_raw((string) self::get('site')) ?: home_url('/'));
        add_filter('login_title', static fn ($full, $title) => $title . ' — ' . sanitize_text_field((string) self::get('nome_curto')), 10, 2);
        add_filter('login_message', [self::class, 'login_message']);
        add_filter('plugin_action_links_' . plugin_basename(IGREJA_ERP_CORE_FILE), [self::class, 'action_links']);
    }

    public static function menu(): void
    {
        // Secretaria must see the parent menu without acquiring settings access.
        $capability = current_user_can(self::CAPABILITY) ? self::CAPABILITY : 'erp_visualizar_membros';
        $callback = current_user_can(self::CAPABILITY) ? [self::class, 'render'] : [Addons::class, 'dashboard'];
        add_menu_page('Igreja ERP', 'Igreja ERP', $capability, 'igreja-erp-core', $callback, 'dashicons-building', 58);
        add_submenu_page('igreja-erp-core', 'Configurações da igreja', 'Configurações', self::CAPABILITY, 'igreja-erp-core', [self::class, 'render']);
    }

    public static function action_links(array $links): array
    {
        if (current_user_can(self::CAPABILITY)) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=igreja-erp-core')) . '">Configurações da igreja</a>');
        }
        return $links;
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Você não tem permissão para configurar esta igreja.', '', ['response' => 403]);
        }
        $settings = self::all();
        $logo = self::logo_url();
        $errors = get_transient('igreja_erp_settings_errors_' . get_current_user_id());
        delete_transient('igreja_erp_settings_errors_' . get_current_user_id());
        require IGREJA_ERP_CORE_DIR . 'includes/views/view-admin-settings.php';
    }

    public static function save(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Você não tem permissão para configurar esta igreja.', '', ['response' => 403]);
        }
        check_admin_referer('igreja_erp_save_settings');
        $input = isset($_POST[self::OPTION]) ? wp_unslash($_POST[self::OPTION]) : null;
        $previous = self::all();
        $clean = self::sanitize($input);
        update_option(self::OPTION, $clean, false);
        set_transient('igreja_erp_settings_errors_' . get_current_user_id(), get_settings_errors(self::OPTION), 60);
        do_action('igreja_erp_settings_updated', $clean, $previous, get_current_blog_id());
        wp_safe_redirect(add_query_arg('igreja_salvo', '1', admin_url('admin.php?page=igreja-erp-core')));
        exit;
    }

    public static function contrast(string $hex): string
    {
        $hex = ltrim(sanitize_hex_color($hex) ?: '#000A52', '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $linear = static function ($value) {
            $value /= 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $linear(hexdec(substr($hex, 0, 2))) + 0.7152 * $linear(hexdec(substr($hex, 2, 2))) + 0.0722 * $linear(hexdec(substr($hex, 4, 2)));
        return $luminance > 0.179 ? '#000000' : '#FFFFFF';
    }

    public static function css_variables(): string
    {
        $defaults = self::defaults();
        $variables = '';
        foreach (['cor_primaria' => 'primary', 'cor_destaque' => 'accent', 'cor_fundo' => 'surface', 'cor_texto' => 'text'] as $key => $name) {
            $color = sanitize_hex_color((string) self::get($key)) ?: $defaults[$key];
            $variables .= '--igreja-' . $name . ':' . $color . ';';
        }
        $variables .= '--igreja-on-primary:' . self::contrast((string) self::get('cor_primaria')) . ';';
        $variables .= '--igreja-on-accent:' . self::contrast((string) self::get('cor_destaque')) . ';';
        return ':root{' . $variables . '}';
    }

    public static function admin_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_igreja-erp-core' || !current_user_can(self::CAPABILITY)) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('igreja-erp-settings', IGREJA_ERP_CORE_URL . 'assets/css/settings.css', [], IGREJA_ERP_CORE_VERSION);
        wp_add_inline_style('igreja-erp-settings', self::css_variables());
        wp_enqueue_script('igreja-erp-settings', IGREJA_ERP_CORE_URL . 'assets/js/settings.js', ['media-editor'], IGREJA_ERP_CORE_VERSION, true);
    }

    public static function login_assets(): void
    {
        wp_enqueue_style('igreja-erp-login', IGREJA_ERP_CORE_URL . 'assets/css/login.css', ['login'], IGREJA_ERP_CORE_VERSION);
        wp_add_inline_style('igreja-erp-login', self::css_variables());
        $logo = self::logo_url();
        if ($logo) {
            // JSON string quoting keeps URL data outside CSS syntax.
            wp_add_inline_style('igreja-erp-login', '.login h1 a{background-image:url(' . wp_json_encode(esc_url_raw($logo), JSON_UNESCAPED_SLASHES) . ')}');
        } else {
            wp_add_inline_style('igreja-erp-login', '.login h1 a{background:none;text-indent:0;height:auto;font-size:1.6rem;line-height:1.3;color:var(--igreja-on-primary)}');
        }
    }

    public static function login_message(string $message): string
    {
        $name = sanitize_text_field((string) self::get('nome_curto'));
        $subtitle = sanitize_text_field((string) self::get('mensagem_login'));
        return '<div class="igreja-login-heading"><p class="igreja-login-name">' . esc_html($name) . '</p><p>' . esc_html($subtitle) . '</p></div>' . $message;
    }

    public static function front_assets(): void
    {
        wp_enqueue_style('igreja-erp-brand', IGREJA_ERP_CORE_URL . 'assets/css/brand.css', [], IGREJA_ERP_CORE_VERSION);
        wp_add_inline_style('igreja-erp-brand', self::css_variables());
    }
}
