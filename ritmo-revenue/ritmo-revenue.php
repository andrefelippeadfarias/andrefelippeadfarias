<?php
/**
 * Plugin Name:       Ritmo Revenue
 * Description:       Revenue management for small hotels and inns: pace-based pricing rules, market anchors and safe, auditable rate updates.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Ritmo
 * Text Domain:       ritmo-revenue
 * Domain Path:       /languages
 *
 * @package Ritmo
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('RITMO_VERSION', '0.1.0');
define('RITMO_FILE', __FILE__);
define('RITMO_DIR', __DIR__);

require_once RITMO_DIR . '/src/autoload.php';

register_activation_hook(__FILE__, [\Ritmo\WordPress\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Ritmo\WordPress\Deactivator::class, 'deactivate']);
add_action('plugins_loaded', [\Ritmo\Plugin::class, 'boot']);
