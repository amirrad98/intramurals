<?php
/** Run with wp eval-file on a disposable WordPress installation only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'This integration test requires the disposable leagueflow.test site.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

function leagueflow_test_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function leagueflow_test_hashes() {
	$root = WP_PLUGIN_DIR . '/leagueflow'; $hashes = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() ) { $hashes[substr( $file->getPathname(), strlen( $root ) )] = hash_file( 'sha256', $file->getPathname() ); }
	}
	ksort( $hashes ); return $hashes;
}

$plugin = 'leagueflow/leagueflow.php';
leagueflow_test_assert( is_plugin_active( $plugin ), 'Package did not activate under the stable plugin basename.' );
$admin = get_user_by( 'login', 'admin' ); wp_set_current_user( $admin->ID );
$current = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'];
$parts = explode( '.', $current ); ++$parts[2]; $next = implode( '.', $parts );
$source_zip = getenv( 'LEAGUEFLOW_TEST_PACKAGE' );
leagueflow_test_assert( is_string( $source_zip ) && is_file( $source_zip ), 'Set LEAGUEFLOW_TEST_PACKAGE to the built ZIP.' );
$fixture_zip = wp_tempnam( 'leagueflow-next.zip' );
$zip = new ZipArchive(); $fixture = new ZipArchive();
leagueflow_test_assert( true === $zip->open( $source_zip ), 'Built package missing.' );
leagueflow_test_assert( true === $fixture->open( $fixture_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Fixture ZIP cannot be created.' );
for ( $index = 0; $index < $zip->numFiles; ++$index ) {
	$name = $zip->getNameIndex( $index ); $contents = $zip->getFromIndex( $index );
	if ( 'leagueflow/leagueflow.php' === $name ) {
		$contents = str_replace( array( ' * Version: ' . $current, "'LEAGUEFLOW_VERSION', '" . $current . "'" ), array( ' * Version: ' . $next, "'LEAGUEFLOW_VERSION', '" . $next . "'" ), $contents );
	}
	$fixture->addFromString( $name, $contents );
}
$zip->close(); $fixture->close();
$repo = 'https://github.com/amirrad98/intramurals';
$package = $repo . '/releases/download/v' . $next . '/leagueflow-' . $next . '.zip';
$manifest = array( 'schema' => 1, 'slug' => 'leagueflow', 'version' => $next,
	'sha256' => hash_file( 'sha256', $fixture_zip ), 'package' => $package, 'requires' => '6.5', 'requires_php' => '8.1' );
$corrupt = true;
$transport = static function( $pre, $args, $url ) use ( $repo, $next, $package, $manifest, $fixture_zip, &$corrupt ) {
	$body = null;
	if ( in_array( $url, array( $repo . '/releases/latest/download/latest.json', $repo . '/releases/download/v' . $next . '/latest.json' ), true ) ) {
		$body = wp_json_encode( $manifest );
	} elseif ( $package === $url ) {
		$body = $corrupt ? 'tampered package' : file_get_contents( $fixture_zip );
		if ( ! empty( $args['stream'] ) ) { file_put_contents( $args['filename'], $body ); $body = ''; }
	} elseif ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check/' ) ) {
		$body = wp_json_encode( array( 'plugins' => (object) array(), 'no_update' => (object) array(), 'translations' => array() ) );
	}
	if ( null === $body ) { return $pre; }
	return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
};
add_filter( 'pre_http_request', $transport, 10, 3 );
delete_site_transient( 'leagueflow_github_release_' . md5( 'latest' ) );
delete_site_transient( 'leagueflow_github_release_' . md5( $next ) );
update_option( 'leagueflow_update_sentinel', 'preserve league settings' );
$team = wp_insert_post( array( 'post_type' => 'lf_team', 'post_status' => 'publish', 'post_title' => 'Preserved release test team' ) );
leagueflow_test_assert( $team && ! is_wp_error( $team ), 'Sentinel team could not be created.' );

try {
	delete_site_transient( 'update_plugins' ); wp_update_plugins();
	$updates = get_site_transient( 'update_plugins' );
	leagueflow_test_assert( isset( $updates->response[$plugin] ) && $next === $updates->response[$plugin]->new_version, 'Native WordPress update discovery failed.' );
	$info = plugins_api( 'plugin_information', (object) array( 'slug' => 'leagueflow' ) );
	leagueflow_test_assert( ! is_wp_error( $info ) && $next === $info->version && $package === $info->download_link, 'Native plugin details failed.' );
	$before = leagueflow_test_hashes();
	$skin = new WP_Ajax_Upgrader_Skin(); $upgrader = new Plugin_Upgrader( $skin );
	// Match wp_ajax_update_plugin(), including its active-plugin preservation.
	$results = $upgrader->bulk_upgrade( array( $plugin ) );
	leagueflow_test_assert( is_array( $results ) && is_wp_error( $results[$plugin] ?? null ), 'Tampered package was installed.' );
	leagueflow_test_assert( in_array( 'leagueflow_update_checksum', $skin->get_errors()->get_error_codes(), true ), 'Native upgrade did not report checksum failure.' );
	leagueflow_test_assert( $before === leagueflow_test_hashes(), 'Rejected upgrade changed installed files.' );
	leagueflow_test_assert( is_plugin_active( $plugin ), 'Rejected upgrade changed active status.' );
	$corrupt = false;
	delete_site_transient( 'update_plugins' ); wp_update_plugins();
	$skin = new WP_Ajax_Upgrader_Skin(); $upgrader = new Plugin_Upgrader( $skin );
	$results = $upgrader->bulk_upgrade( array( $plugin ) );
	leagueflow_test_assert( is_array( $results ) && is_array( $results[$plugin] ?? null ) && ! $skin->get_errors()->has_errors(), 'Native verified upgrade failed.' );
	wp_clean_plugins_cache();
	leagueflow_test_assert( $next === get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'], 'Upgraded plugin header differs from selected release.' );
	leagueflow_test_assert( is_plugin_active( $plugin ), 'Upgrade changed active plugin basename.' );
	leagueflow_test_assert( 1 === count( glob( WP_PLUGIN_DIR . '/leagueflow*', GLOB_ONLYDIR ) ), 'Upgrade created another plugin folder.' );
	leagueflow_test_assert( 'preserve league settings' === get_option( 'leagueflow_update_sentinel' ), 'Upgrade replaced stored options.' );
	leagueflow_test_assert( 'Preserved release test team' === get_post( $team )->post_title, 'Upgrade replaced stored league data.' );
	WP_CLI::success( 'Native discovery, details, tamper rejection and verified upgrade preserve active folder and league data. WordPress ' . get_bloginfo( 'version' ) );
} finally {
	remove_filter( 'pre_http_request', $transport, 10 ); wp_delete_file( $fixture_zip );
}
