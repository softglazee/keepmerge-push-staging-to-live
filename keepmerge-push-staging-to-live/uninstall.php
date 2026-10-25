<?php
/**
 * Uninstall routine.
 *
 * @package SoftGlaze\SafePush
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TODO(Phase 9): remove receiver users, role and credentials always; data only when the toggle is on.
