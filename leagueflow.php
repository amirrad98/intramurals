<?php
/**
 * Plugin Name: LeagueFlow
 * Plugin URI: https://example.com/leagueflow
 * Description: Native WordPress soccer league management for teams, players, fixtures, standings, and knockout brackets.
 * Version: 1.0.0
 * Author: 1stform
 * Text Domain: leagueflow
 * Domain Path: /languages
 *
 * @package LeagueFlow
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LEAGUEFLOW_VERSION' ) ) {
	define( 'LEAGUEFLOW_VERSION', '1.0.0' );
}

if ( ! defined( 'LEAGUEFLOW_FILE' ) ) {
	define( 'LEAGUEFLOW_FILE', __FILE__ );
}

if ( ! defined( 'LEAGUEFLOW_PATH' ) ) {
	define( 'LEAGUEFLOW_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'LEAGUEFLOW_URL' ) ) {
	define( 'LEAGUEFLOW_URL', plugin_dir_url( __FILE__ ) );
}

$leagueflow_files = array(
	'includes/helpers.php',
	'includes/class-sports-manager.php',
	'includes/class-exporter.php',
	'includes/class-field-availability-manager.php',
	'includes/class-plugin.php',
	'includes/class-activator.php',
	'includes/class-settings.php',
	'includes/class-post-types.php',
	'includes/class-taxonomies.php',
	'includes/class-assets.php',
	'includes/class-standings-service.php',
	'includes/class-knockout-service.php',
	'includes/class-renderer.php',
	'includes/class-seeder.php',
	'includes/class-admin.php',
	'includes/class-portal.php',
	'includes/class-rest-controller.php',
	'includes/class-shortcodes.php',
	'includes/class-blocks.php',
	'includes/class-template-loader.php',
);

foreach ( $leagueflow_files as $leagueflow_file ) {
	require_once LEAGUEFLOW_PATH . $leagueflow_file;
}

register_activation_hook( __FILE__, array( 'LeagueFlow\\Activator', 'activate' ) );

if ( ! function_exists( 'leagueflow' ) ) {
	/**
	 * Boot the plugin container.
	 *
	 * @return \LeagueFlow\Plugin
	 */
	function leagueflow() {
		return \LeagueFlow\Plugin::instance();
	}
}

add_action(
	'plugins_loaded',
	static function() {
		leagueflow()->register();
	}
);
