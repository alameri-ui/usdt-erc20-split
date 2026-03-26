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
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
  exit;
}

define('USDT_ERC20_SPLIT_VERSION', '1.0.0');
define('USDT_ERC20_SPLIT_PLUGIN_FILE', __FILE__);
define('USDT_ERC20_SPLIT_DIR', plugin_dir_path(__FILE__));

action_hooks:;