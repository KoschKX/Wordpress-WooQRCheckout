<?php
/**
 * Plugin Name: WooCommerce QR Checkout
 * Plugin URI: https://your-website.com
 * Description: Generate QR codes that link to WooCommerce checkout with automatic product addition and coupon code system.
 * Version: 1.0.0
 * Author: Gary Angelone Jr.
 * Author URI: https://your-website.com
 * License: GPL v2 or later
 * Text Domain: woocommerce-qr-checkout
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * 
 * This plugin generates QR codes for WooCommerce products that lead to checkout.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_QR_VERSION', '1.0.0');
define('WC_QR_PLUGIN_FILE', __FILE__);
define('WC_QR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_QR_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include main class
require_once WC_QR_PLUGIN_PATH . 'includes/class-wc-qr-admin.php';

// Initialize the plugin
function wc_qr_checkout_init() {
    if (class_exists('WooCommerce')) {
        new WC_QR_Admin();
    } else {
        add_action('admin_notices', 'wc_qr_checkout_woocommerce_notice');
    }
}
add_action('plugins_loaded', 'wc_qr_checkout_init');

/**
 * Show notice if WooCommerce is not active
 */
function wc_qr_checkout_woocommerce_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>WooCommerce QR Checkout</strong> requires WooCommerce to be installed and activated.</p>
    </div>
    <?php
}

// Add settings link to plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_qr_checkout_settings_link');
function wc_qr_checkout_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-qr-manager') . '">Manage QR Codes</a>';
    array_unshift($links, $settings_link);
    return $links;
}
