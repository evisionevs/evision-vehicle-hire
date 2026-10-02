<?php
/**
 * Plugin Name: EVision Vehicle Hire
 * Description: Vehicle hire pricing, notice rules, insurance extras and fleet reservations for WooCommerce. Test release.
 * Version: 0.1.8
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: EVision
 * License: GPL-2.0-or-later
 * Text Domain: evision-vehicle-hire
 */
defined('ABSPATH') || exit;
define('EVH_VERSION', '0.1.8');
define('EVH_SCHEMA_VERSION', '0.1.0');
define('EVH_PATH', plugin_dir_path(__FILE__));
define('EVH_URL', plugin_dir_url(__FILE__));
require_once EVH_PATH . 'includes/class-evh-rules.php';
require_once EVH_PATH . 'includes/class-evh-storage.php';
require_once EVH_PATH . 'includes/class-evh-plugin.php';
require_once EVH_PATH . 'includes/class-evh-admin.php';

register_activation_hook(__FILE__, array('EVH_Storage', 'install'));
register_deactivation_hook(__FILE__, function() { wp_clear_scheduled_hook('evh_refresh_holidays'); });
add_action('before_woocommerce_init', function() {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
    }
});
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() { echo '<div class="notice notice-error"><p>EVision Vehicle Hire requires WooCommerce.</p></div>'; });
        return;
    }
    if (get_option('evh_schema_version') !== EVH_SCHEMA_VERSION) { EVH_Storage::install(); }
    EVH_Plugin::boot();
    EVH_Admin::boot();
});
