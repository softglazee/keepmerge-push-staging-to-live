<?php
/**
 * Uninstall routine (blueprint §18.3; SoftGlaze\SafePush\Uninstaller says what goes and when).
 *
 * @package SoftGlaze\SafePush
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The plugin is inactive during uninstall, so its autoloader is not registered.
require_once __DIR__ . '/includes/class-tables.php';
require_once __DIR__ . '/includes/class-uninstaller.php';

SoftGlaze\SafePush\Uninstaller::run();
