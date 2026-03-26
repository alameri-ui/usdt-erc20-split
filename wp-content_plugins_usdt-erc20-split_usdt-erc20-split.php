<?php
/**
 * Plugin Name: USDT ERC20 Split Gateway (99% Merchant + 1% Fee)
 * Description: WooCommerce payment gateway for USDT (ERC20) on Ethereum mainnet with split payment: 99% to merchant, 1% to platform fee address. On-chain verification via Ethereum RPC (Transfer logs).
 * Version: 1.0.0
 * Author: Your Name
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

if (!defined('ABSPATH')) exit;

define('USDT_ERC20_SPLIT_VERSION', '1.0.0');
define('USDT_ERC20_SPLIT_PLUGIN_FILE', __FILE__);
define('USDT_ERC20_SPLIT_DIR', plugin_dir_path(__FILE__));

require_once USDT_ERC20_SPLIT_DIR . 'includes/class-usdt-erc20-split-gateway.php';
require_once USDT_ERC20_SPLIT_DIR . 'includes/class-usdt-erc20-split-eth.php';

add_action('plugins_loaded', function () {
  if (!class_exists('WC_Payment_Gateway')) return;
  if (!class_exists('WC_Gateway_USDT_ERC20_Split')) return;
}, 11);

add_filter('woocommerce_payment_gateways', function ($methods) {
  $methods[] = 'WC_Gateway_USDT_ERC20_Split';
  return $methods;
});

register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled('usdt_erc20_split_cron_check')) {
    wp_schedule_event(time() + 120, 'five_minutes', 'usdt_erc20_split_cron_check');
  }
});

register_deactivation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('usdt_erc20_split_cron_check');
});

add_filter('cron_schedules', function ($schedules) {
  if (!isset($schedules['five_minutes'])) {
    $schedules['five_minutes'] = [
      'interval' => 5 * 60,
      'display'  => __('Every 5 Minutes (USDT Split)', 'usdt-erc20-split'),
    ];
  }
  return $schedules;
});

add_action('usdt_erc20_split_cron_check', function () {
  // Run lightweight checks on pending/on-hold orders created with this gateway.
  if (!class_exists('WC_Order_Query')) return;

  $query = new WC_Order_Query([
    'limit'        => 10,
    'status'       => ['on-hold', 'pending'],
    'payment_method' => 'usdt_erc20_split',
    'orderby'      => 'date',
    'order'        => 'DESC',
  ]);

  $orders = $query->get_orders();
  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) continue;
    WC_Gateway_USDT_ERC20_Split::maybe_check_and_mark_paid($order);
  }
});