<?php
/**
 * Plugin uninstall handler.
 *
 * @package LeagueFlow
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/class-availability.php';
require_once __DIR__ . '/includes/class-activator.php';

\LeagueFlow\Activator::cleanup();
