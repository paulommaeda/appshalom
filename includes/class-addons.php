<?php
namespace IgrejaERP\Core;
defined('ABSPATH') || exit;

/** Addons are separate WordPress plugins with native activation and updates. */
final class Addons
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 15);
        // Keep updates available for an installed but inactive managed addon.
        $updater = WP_PLUGIN_DIR . '/igreja-erp-membros/includes/class-updater.php';
        if (is_file($updater)) {
            require_once $updater;
            if (class_exists('IgrejaERP\\Membros\\Updater')) {
                \IgrejaERP\Membros\Updater::register();
            }
        }
    }

    public static function menu(): void
    {
        add_submenu_page('igreja-erp-core', 'Addons', 'Addons', 'activate_plugins', 'igreja-erp-addons', [self::class, 'render']);
    }

    public static function dashboard(): void
    {
        if (!current_user_can('erp_visualizar_membros')) {
            wp_die('Você não tem permissão para acessar esta área.', '', ['response' => 403]);
        }
        echo '<div class="wrap"><h1>Igreja ERP</h1><p>Os recursos de cadastro ficam disponíveis quando o addon Membros está ativo.</p>';
        if (did_action('igreja_erp_membros_loaded')) {
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=igreja-erp-membros')) . '">Acessar membros</a></p>';
        }
        echo '</div>';
    }

    public static function render(): void
    {
        if (!current_user_can('activate_plugins')) {
            wp_die('Você não tem permissão para gerenciar addons.', '', ['response' => 403]);
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $catalog = apply_filters('igreja_erp_addons', [
            'membros' => ['name' => 'Membros', 'file' => 'igreja-erp-membros/igreja-erp-membros.php', 'description' => 'Cadastros de membros e famílias, situação na igreja e formulário público com campos configuráveis.'],
        ]);
        echo '<div class="wrap"><h1>Addons da igreja</h1><p>Ative os recursos que sua igreja utiliza. Desativar um addon preserva seus dados e configurações.</p><div style="display:flex;flex-wrap:wrap;gap:20px">';
        foreach (is_array($catalog) ? $catalog : [] as $addon) {
            if (!is_array($addon) || !is_string($addon['file'] ?? null) || !preg_match('~^[a-z0-9_-]+/[a-z0-9_-]+\.php$~D', $addon['file'])) {
                continue;
            }
            $file = $addon['file'];
            $installed = file_exists(WP_PLUGIN_DIR . '/' . $file);
            $active = $installed && is_plugin_active($file);
            $network = is_multisite() && is_plugin_active_for_network($file);
            echo '<section class="card" style="margin:0;max-width:480px;flex:1 1 280px"><h2>' . esc_html($addon['name'] ?? '') . '</h2><p>' . esc_html($addon['description'] ?? '') . '</p><p><strong>' . ($active ? 'Ativo' : ($installed ? 'Desativado' : 'Não instalado')) . '</strong></p>';
            if (!$installed) {
                echo '<p>Instale o ZIP deste addon em Plugins → Adicionar plugin → Enviar plugin.</p>';
            } elseif ($network) {
                echo '<p>Ativo em toda a rede. <a href="' . esc_url(network_admin_url('plugins.php')) . '">Gerenciar plugins da rede</a></p>';
            } elseif (current_user_can($active ? 'deactivate_plugin' : 'activate_plugin', $file)) {
                $action = $active ? 'deactivate' : 'activate';
                $url = wp_nonce_url(add_query_arg(['action' => $action, 'plugin' => $file], self_admin_url('plugins.php')), $action . '-plugin_' . $file);
                echo '<a class="button ' . ($active ? '' : 'button-primary') . '" href="' . esc_url($url) . '">' . ($active ? 'Desativar' : 'Ativar') . '</a>';
            }
            echo '</section>';
        }
        echo '</div></div>';
    }
}
