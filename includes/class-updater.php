<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Native WordPress updates from the public appshalom GitHub repository. */
final class Updater
{
    private const REPOSITORY = 'https://github.com/paulommaeda/appshalom';
    private const METADATA = 'https://raw.githubusercontent.com/paulommaeda/appshalom/main/update.json';
    private const CACHE_KEY = 'igreja_erp_core_update_metadata';
    private const SLUG = 'igreja-erp-core';

    public static function register(): void
    {
        add_filter('update_plugins_github.com', [self::class, 'update_response'], 10, 4);
        add_filter('plugins_api', [self::class, 'plugin_information'], 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(IGREJA_ERP_CORE_FILE), [self::class, 'action_links']);
        add_filter('network_admin_plugin_action_links_' . plugin_basename(IGREJA_ERP_CORE_FILE), [self::class, 'action_links']);
        add_action('admin_post_igreja_erp_check_updates', [self::class, 'check_now']);
    }

    public static function metadata(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return isset($cached['version']) ? $cached : null;
        }
        $response = wp_remote_get(self::METADATA, [
            'timeout' => 10, 'redirection' => 2, 'limit_response_size' => 65536,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, ['error' => true], 300);
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['version'], $data['download_url']) || !is_string($data['version']) || !is_string($data['download_url']) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/D', $data['version'])) {
            set_transient(self::CACHE_KEY, ['error' => true], 300);
            return null;
        }
        $expected = self::REPOSITORY . '/releases/download/v' . $data['version'] . '/igreja-erp-core-' . $data['version'] . '.zip';
        if ($data['download_url'] !== $expected) {
            set_transient(self::CACHE_KEY, ['error' => true], 300);
            return null;
        }
        $data['requires'] = isset($data['requires']) && is_string($data['requires']) ? sanitize_text_field($data['requires']) : '6.5';
        $data['requires_php'] = isset($data['requires_php']) && is_string($data['requires_php']) ? sanitize_text_field($data['requires_php']) : '8.1';
        set_transient(self::CACHE_KEY, $data, HOUR_IN_SECONDS);
        return $data;
    }

    public static function update_response($update, array $plugin_data, string $plugin_file, array $locales)
    {
        if ($plugin_file !== plugin_basename(IGREJA_ERP_CORE_FILE)) {
            return $update;
        }
        $data = self::metadata();
        return $data ? [
            'id' => self::REPOSITORY, 'slug' => self::SLUG, 'version' => $data['version'],
            'url' => self::REPOSITORY, 'package' => $data['download_url'],
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'],
        ] : $update;
    }

    public static function plugin_information($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }
        $data = self::metadata();
        if (!$data) {
            return $result;
        }
        $sections = [];
        foreach (['description', 'installation', 'changelog'] as $section) {
            if (isset($data['sections'][$section]) && is_string($data['sections'][$section])) {
                $sections[$section] = wp_kses_post($data['sections'][$section]);
            }
        }
        return (object) [
            'name' => 'Igreja ERP Core', 'slug' => self::SLUG, 'version' => $data['version'],
            'author' => 'App Shalom', 'homepage' => self::REPOSITORY,
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'],
            'download_link' => $data['download_url'], 'sections' => $sections,
        ];
    }

    public static function action_links(array $links): array
    {
        if (current_user_can('update_plugins')) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=igreja_erp_check_updates'), 'igreja_erp_check_updates');
            $links[] = '<a href="' . esc_url($url) . '">Verificar atualizações</a>';
        }
        return $links;
    }

    public static function check_now(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die('Você não tem permissão para atualizar plugins.', '', ['response' => 403]);
        }
        check_admin_referer('igreja_erp_check_updates');
        delete_transient(self::CACHE_KEY);
        wp_clean_plugins_cache(true);
        wp_update_plugins();
        wp_safe_redirect(self_admin_url('update-core.php'));
        exit;
    }
}
