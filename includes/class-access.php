<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Login boundary for WordPress responses; static files remain a server concern. */
final class Access
{
    public static function register(): void
    {
        if (Settings::is_restricted() && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        add_action('template_redirect', [self::class, 'front_gate'], -100);
        add_action('admin_init', [self::class, 'admin_gate'], 0);
        add_action('init', [self::class, 'xmlrpc_gate'], 0);
        add_action('before_signup_header', [self::class, 'signup_gate']);
        // Native REST cookie/nonce validation runs at priority 100, before this gate.
        add_filter('rest_authentication_errors', [self::class, 'rest_gate'], 200);
        add_filter('rest_post_dispatch', [self::class, 'rest_headers'], 200, 3);
        add_filter('wp_headers', [self::class, 'headers']);
        add_filter('nocache_headers', [self::class, 'headers']);
        add_filter('wp_sitemaps_enabled', static fn ($enabled) => Settings::is_restricted() ? false : $enabled);
        add_filter('robots_txt', static fn ($text) => Settings::is_restricted() ? "User-agent: *\nDisallow: /\n" : $text);
        add_filter('pre_option_blog_public', static fn ($pre) => Settings::is_restricted() ? 0 : $pre);
        add_filter('pre_option_users_can_register', static fn ($pre) => Settings::is_restricted() ? 0 : $pre);
    }

    public static function can_access(): bool
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }
        return !is_multisite() || is_super_admin($user_id) || is_user_member_of_blog($user_id, get_current_blog_id());
    }

    public static function front_gate(): void
    {
        if (!Settings::is_restricted()) {
            return;
        }
        nocache_headers();
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }
        if (!self::can_access()) {
            wp_die('Sua conta não está vinculada a esta igreja. Entre com uma conta autorizada.', 'Acesso restrito', ['response' => 403]);
        }
    }

    public static function admin_gate(): void
    {
        if (!Settings::is_restricted() || self::can_access()) {
            return;
        }
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => 'Entre com uma conta desta igreja para continuar.'], is_user_logged_in() ? 403 : 401);
        }
        if (($GLOBALS['pagenow'] ?? '') === 'admin-post.php' || is_user_logged_in()) {
            wp_die('Entre com uma conta desta igreja para continuar.', 'Acesso restrito', ['response' => is_user_logged_in() ? 403 : 401]);
        }
    }

    public static function xmlrpc_gate(): void
    {
        if (Settings::is_restricted() && defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            nocache_headers();
            status_header(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Este site utiliza acesso autenticado pela API REST.';
            exit;
        }
    }

    public static function signup_gate(): void
    {
        if (Settings::is_restricted()) {
            wp_die('O cadastro de contas é realizado pela administração da igreja.', 'Acesso restrito', ['response' => 403]);
        }
    }

    public static function rest_gate(mixed $error): mixed
    {
        if (is_wp_error($error) || !Settings::is_restricted()) {
            return $error;
        }
        if (!self::can_access()) {
            return new \WP_Error(
                'igreja_erp_site_restrito',
                'Entre com uma conta desta igreja para continuar.',
                ['status' => is_user_logged_in() ? 403 : 401]
            );
        }
        return $error;
    }

    public static function headers(array $headers): array
    {
        if (Settings::is_restricted()) {
            $headers['Cache-Control'] = 'private, no-store, no-cache, must-revalidate, max-age=0';
            $headers['Pragma'] = 'no-cache';
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
            $headers['Vary'] = isset($headers['Vary']) ? $headers['Vary'] . ', Cookie, Authorization' : 'Cookie, Authorization';
        }
        return $headers;
    }

    public static function rest_headers($response, $server, $request)
    {
        if (Settings::is_restricted()) {
            $response = rest_ensure_response($response);
            foreach (self::headers([]) as $name => $value) {
                $response->header($name, $value);
            }
        }
        return $response;
    }
}
