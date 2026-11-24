<?php
/**
 * Plugin Name: Igreja ERP Core
 * Plugin URI: https://github.com/paulommaeda/appshalom
 * Description: Base do App Shalom com identidade por igreja, acesso restrito, banco e permissões.
 * Version: 0.4.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: App Shalom
 * License: GPL-2.0-or-later
 * Text Domain: igreja-erp-core
 * Update URI: https://github.com/paulommaeda/appshalom
 */
defined('ABSPATH') || exit;

define('IGREJA_ERP_CORE_VERSION', '0.4.0');
define('IGREJA_ERP_CORE_DB_VERSION', '1.0.0');
define('IGREJA_ERP_CORE_FILE', __FILE__);
define('IGREJA_ERP_CORE_DIR', plugin_dir_path(__FILE__));
define('IGREJA_ERP_CORE_URL', plugin_dir_url(__FILE__));

// Explicit class map preserves the class-*.php layout requested for this project.
spl_autoload_register(static function ($class) {
    $map = [
        'IgrejaERP\\Core\\Database' => 'includes/class-database.php',
        'IgrejaERP\\Core\\RBAC' => 'includes/class-rbac.php',
        'IgrejaERP\\Core\\Updater' => 'includes/class-updater.php',
        'IgrejaERP\\Core\\Settings' => 'includes/class-settings.php',
        'IgrejaERP\\Core\\Access' => 'includes/class-access.php',
        'IgrejaERP\\Core\\Addons' => 'includes/class-addons.php',
    ];
    if (isset($map[$class])) {
        require_once IGREJA_ERP_CORE_DIR . $map[$class];
    }
});

register_activation_hook(__FILE__, static function ($network_wide) {
    if (version_compare(PHP_VERSION, '8.1', '<') || version_compare(get_bloginfo('version'), '6.5', '<')) {
        wp_die('O Igreja ERP Core requer WordPress 6.5+ e PHP 8.1+.');
    }
    $install = static function () {
        $result = \IgrejaERP\Core\Database::install();
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        \IgrejaERP\Core\RBAC::install_roles();
        \IgrejaERP\Core\Settings::initialize();
    };
    if (is_multisite() && $network_wide) {
        $offset = 0;
        do {
            $sites = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset]);
            foreach ($sites as $site_id) {
                switch_to_blog((int) $site_id);
                try {
                    $install();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += 100;
        } while (count($sites) === 100);
    } else {
        $install();
    }
});

add_action('plugins_loaded', static function () {
    if (version_compare(PHP_VERSION, '8.1', '<') || version_compare(get_bloginfo('version'), '6.5', '<')) {
        add_action('admin_notices', static function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>O Igreja ERP Core requer WordPress 6.5+ e PHP 8.1+.</p></div>';
            }
        });
        return;
    }
    class_exists(\IgrejaERP\Core\Database::class);
    class_exists(\IgrejaERP\Core\RBAC::class);
    class_exists(\IgrejaERP\Core\Updater::class);
    \IgrejaERP\Core\Updater::register();
    \IgrejaERP\Core\Settings::initialize();
    \IgrejaERP\Core\Settings::register();
    \IgrejaERP\Core\Access::register();
    if (!\IgrejaERP\Core\Database::maybe_upgrade()) {
        add_action('admin_notices', [\IgrejaERP\Core\Database::class, 'admin_notice']);
        return;
    }
    \IgrejaERP\Core\RBAC::maybe_upgrade();
    \IgrejaERP\Core\Addons::register();
    /** Infrastructure ready; add-ons must check their minimum required Core version. */
    do_action('igreja_erp_core_loaded', IGREJA_ERP_CORE_VERSION);
}, 5);

add_action('wp_initialize_site', static function ($site) {
    if (version_compare(PHP_VERSION, '8.1', '<') || version_compare(get_bloginfo('version'), '6.5', '<')) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!is_plugin_active_for_network(plugin_basename(IGREJA_ERP_CORE_FILE))) {
        return;
    }
    switch_to_blog((int) $site->blog_id);
    try {
        $result = \IgrejaERP\Core\Database::install();
        if (!is_wp_error($result)) {
            \IgrejaERP\Core\RBAC::install_roles();
            \IgrejaERP\Core\Settings::initialize();
        }
    } finally {
        restore_current_blog();
    }
}, 200);
