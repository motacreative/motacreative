<?php
/**
 * Plugin Name: SureCart to FluentCart Migration
 * Description: Migrates products, customers, and orders from SureCart to FluentCart.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Mota Creative
 * License: GPL v2 or later
 * Text Domain: sc-to-fct
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SC_FCT_VERSION', '1.0.0');
define('SC_FCT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SC_FCT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Autoload plugin classes.
 */
spl_autoload_register(function ($class) {
    $prefix = 'ScToFct\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = SC_FCT_PLUGIN_PATH . 'includes/' . str_replace('\\', '/', $relative) . '.php';

    // Convert CamelCase class name to kebab-case file name
    $parts = explode('/', $file);
    $filename = array_pop($parts);
    $filename = 'class-' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $filename));
    $file = implode('/', $parts) . '/' . $filename;

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Plugin activation hook.
 */
register_activation_hook(__FILE__, function () {
    require_once SC_FCT_PLUGIN_PATH . 'includes/class-migration-activator.php';
    ScToFct\MigrationActivator::activate();
});

/**
 * Initialize the plugin after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    // Check dependencies
    $surecart_active = class_exists('SureCart') || defined('SURECART_PLUGIN_FILE');
    $fluentcart_active = defined('FLUENTCART_PLUGIN_PATH') || class_exists('FluentCart\\App\\App');

    if (!$surecart_active || !$fluentcart_active) {
        add_action('admin_notices', function () use ($surecart_active, $fluentcart_active) {
            $missing = [];
            if (!$surecart_active) {
                $missing[] = 'SureCart';
            }
            if (!$fluentcart_active) {
                $missing[] = 'FluentCart';
            }
            printf(
                '<div class="notice notice-error"><p><strong>SureCart to FluentCart Migration:</strong> The following required plugins are not active: %s</p></div>',
                esc_html(implode(', ', $missing))
            );
        });
        return;
    }

    // Load plugin classes
    require_once SC_FCT_PLUGIN_PATH . 'includes/class-migration-logger.php';
    require_once SC_FCT_PLUGIN_PATH . 'includes/class-id-mapper.php';
    require_once SC_FCT_PLUGIN_PATH . 'includes/class-admin-page.php';
    require_once SC_FCT_PLUGIN_PATH . 'includes/class-ajax-handler.php';

    new ScToFct\AdminPage();
    new ScToFct\AjaxHandler();
});
