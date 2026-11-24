<?php
/**
 * Plugin Name: Igreja ERP — Membros
 * Plugin URI: https://github.com/paulommaeda/appshalom
 * Description: Addon de membros, famílias e formulário público configurável do App Shalom.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: igreja-erp-core
 * Author: App Shalom
 * License: GPL-2.0-or-later
 * Text Domain: igreja-erp-membros
 * Update URI: https://github.com/paulommaeda/appshalom
 */
defined('ABSPATH') || exit;

define('IGREJA_ERP_MEMBROS_VERSION', '0.2.0');
define('IGREJA_ERP_MEMBROS_FILE', __FILE__);
define('IGREJA_ERP_MEMBROS_DIR', plugin_dir_path(__FILE__));
define('IGREJA_ERP_MEMBROS_URL', plugin_dir_url(__FILE__));

// Preserve the published service names for other addons; Core no longer loads them.
spl_autoload_register(static function ($class) {
    $map = [
        'IgrejaERP\\Core\\Membros' => 'includes/class-membros.php',
        'IgrejaERP\\Core\\MembersAdmin' => 'includes/class-members-admin.php',
        'IgrejaERP\\Core\\Registration' => 'includes/class-registration.php',
        'IgrejaERP\\Membros\\Accounts' => 'includes/class-accounts.php',
        'IgrejaERP\\Membros\\MemberTransfer' => 'includes/class-member-transfer.php',
        'IgrejaERP\\Membros\\Updater' => 'includes/class-updater.php',
    ];
    if (isset($map[$class])) {
        require_once IGREJA_ERP_MEMBROS_DIR . $map[$class];
    }
});

register_activation_hook(__FILE__, static function () {
    if (!defined('IGREJA_ERP_CORE_VERSION') || version_compare(IGREJA_ERP_CORE_VERSION, '0.5.0', '<') || !class_exists(\IgrejaERP\Core\Access::class) || !method_exists(\IgrejaERP\Core\Access::class, 'register_public_route')) {
        wp_die('Ative e atualize o Igreja ERP Core para a versão 0.5.0 ou superior antes de ativar o addon Membros.', '', ['back_link' => true]);
    }
});

add_action('plugins_loaded', static function () {
    \IgrejaERP\Membros\Updater::register();
    if (!defined('IGREJA_ERP_CORE_VERSION') || version_compare(IGREJA_ERP_CORE_VERSION, '0.5.0', '<') || !did_action('igreja_erp_core_loaded')) {
        add_action('admin_notices', static function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>O addon Membros precisa do Igreja ERP Core 0.5.0 ou superior ativo e inicializado.</p></div>';
            }
        });
        return;
    }
    \IgrejaERP\Core\MembersAdmin::register();
    \IgrejaERP\Core\Registration::register();
    \IgrejaERP\Membros\Accounts::register();
    \IgrejaERP\Membros\MemberTransfer::register();
    do_action('igreja_erp_membros_loaded', IGREJA_ERP_MEMBROS_VERSION);
}, 10);

register_deactivation_hook(__FILE__, static function ($network_wide) {
    $clear = static fn () => wp_clear_scheduled_hook('igreja_erp_cleanup_registration');
    if (is_multisite() && $network_wide) {
        $offset = 0;
        do {
            $sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
            foreach ($sites as $site_id) {
                switch_to_blog((int) $site_id);
                try { $clear(); } finally { restore_current_blog(); }
            }
            $offset += 100;
        } while (count($sites) === 100);
    } else {
        $clear();
    }
    // Records, families, consent and settings intentionally remain in this site's DB.
});
