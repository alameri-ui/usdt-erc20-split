<?php
/**
 * Plugin Name: USDT ERC20 Split (WooCommerce)
 * Plugin URI: https://github.com/alameri-ui/usdt-erc20-split
 * Description: WooCommerce USDT (ERC20) split payment gateway on Ethereum mainnet (99% to merchant + 1% platform fee).
 * Version: 1.0.0
 * Author: Zaid Alameri Digital
 * Author URI: https://github.com/alameri-ui
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: usdt-erc20-split
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

if (!defined('ABSPATH')) exit;

define('USDT_ERC20_SPLIT_VERSION', '1.0.0');
define('USDT_ERC20_SPLIT_PLUGIN_FILE', __FILE__);
define('USDT_ERC20_SPLIT_DIR', plugin_dir_path(__FILE__));

function usdt_erc20_split_admin_notice_missing_wc() {
  echo '<div class="notice notice-error"><p><strong>USDT ERC20 Split:</strong> WooCommerce is required. Please install/activate WooCommerce.</p></div>';
}

add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) {
    add_action('admin_notices', 'usdt_erc20_split_admin_notice_missing_wc');
    return;
  }

  $gateway = USDT_ERC20_SPLIT_DIR . 'includes/class-usdt-erc20-split-gateway.php';
  $eth     = USDT_ERC20_SPLIT_DIR . 'includes/class-usdt-erc20-split-eth.php';

  if (!file_exists($gateway) || !file_exists($eth)) {
    add_action('admin_notices', function () use ($gateway, $eth) {
      echo '<div class="notice notice-error"><p><strong>USDT ERC20 Split:</strong> Missing required files. Expected:<br><code>' . esc_html($gateway) . '</code><br><code>' . esc_html($eth) . '</code></p></div>';
    });
    return;
  }

  require_once $eth;
  require_once $gateway;

  add_filter('woocommerce_payment_gateways', function ($methods) {
    $methods[] = 'WC_Gateway_USDT_ERC20_Split';
    return $methods;
  });
});