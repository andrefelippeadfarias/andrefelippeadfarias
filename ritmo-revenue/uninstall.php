<?php
/**
 * Uninstall handler.
 *
 * Deliberately removes nothing yet: the plugin's tables and options are created by the
 * migrations module, and the routine that drops them ships together with it. Until then
 * an uninstall must never touch data, so this file only refuses direct access.
 *
 * @package Ritmo
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;
