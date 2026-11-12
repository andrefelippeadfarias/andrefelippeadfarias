<?php
/**
 * Uninstall handler. Removes the plugin's data only when the site chose "delete data on
 * uninstall"; see Ritmo\WordPress\Uninstaller.
 *
 * @package Ritmo
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/autoload.php';

\Ritmo\WordPress\Uninstaller::forSite()->run();
