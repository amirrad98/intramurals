<?php
/** Isolated updater contracts; native WordPress coverage is in wordpress-update.php. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'LEAGUEFLOW_FILE', '/plugins/leagueflow/leagueflow.php' );
define( 'LEAGUEFLOW_VERSION', '1.0.2' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
$filters = array(); $cache = array(); $ttls = array(); $responses = array(); $requests = array();
$download_mode = 'valid'; $download_bytes = 'verified package fixture'; $downloaded = array(); $assertions = 0;
function add_filter( $hook, $callback, $priority, $arguments ) { $GLOBALS['filters'][$hook] = array( $callback, $arguments ); }
function plugin_basename( $file ) { return 'leagueflow/leagueflow.php'; }
function get_site_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient( $key, $value, $ttl ) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttls'][$key] = $ttl; }
function wp_safe_remote_get( $url, $options ) { $GLOBALS['requests'][] = array( $url, $options ); return $GLOBALS['responses'][$url] ?? new WP_Error( 'network', 'No fixture' ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return $value; }
function esc_url( $value ) { return $value; }
function wp_delete_file( $file ) { unlink( $file ); }
function download_url( $url, $timeout ) {
	if ( 'error' === $GLOBALS['download_mode'] ) { return new WP_Error( 'download_error', 'Fixture error' ); }
	if ( 'missing' === $GLOBALS['download_mode'] ) { return __DIR__ . '/does-not-exist.zip'; }
	$file = tempnam( sys_get_temp_dir(), 'leagueflow-update-' );
	file_put_contents( $file, $GLOBALS['download_bytes'] );
	$GLOBALS['downloaded'][] = $file;
	return $file;
}
function check( $condition, $message ) {
	++$GLOBALS['assertions'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function release_fixture( $version = '1.0.3' ) {
	return array( 'schema' => 1, 'slug' => 'leagueflow', 'version' => $version, 'sha256' => hash( 'sha256', 'verified package fixture' ),
		'requires' => '6.5', 'requires_php' => '8.1', 'package' => 'https://github.com/amirrad98/intramurals/releases/download/v' . $version . '/leagueflow-' . $version . '.zip' );
}
function reset_feed( $body, $code = 200 ) {
	$GLOBALS['cache'] = array(); $GLOBALS['requests'] = array(); $GLOBALS['ttls'] = array();
	$GLOBALS['responses'] = array( 'https://github.com/amirrad98/intramurals/releases/latest/download/latest.json' => array( 'code' => $code, 'body' => is_array( $body ) ? json_encode( $body ) : $body ) );
}
require __DIR__ . '/../includes/class-github-updater.php';
use LeagueFlow\GitHub_Updater as Updater;
Updater::register();
check( 4 === $filters['update_plugins_github.com'][1] && 4 === $filters['upgrader_pre_download'][1] && 3 === $filters['plugins_api'][1], 'Native hook contracts missing' );
$manifest = release_fixture();
reset_feed( $manifest );
$update = Updater::check_update( false, array( 'Version' => '1.0.2' ), 'leagueflow/leagueflow.php', array() );
check( '1.0.3' === $update['version'] && $manifest['package'] === $update['package'], 'New stable release not offered' );
check( false === $update['autoupdate'], 'Automatic updates enabled unexpectedly' );
check( 300 === reset( $ttls ), 'Success cache TTL incorrect' );
check( 10 === $requests[0][1]['timeout'] && 5 === $requests[0][1]['redirection'] && 8193 === $requests[0][1]['limit_response_size'], 'HTTP response is not bounded' );
Updater::check_update( false, array( 'Version' => '1.0.2' ), 'leagueflow/leagueflow.php', array() );
check( 1 === count( $requests ), 'Validated discovery was not cached' );
foreach ( array( '1.0.3', '2.0.0' ) as $installed ) {
	check( false === Updater::check_update( false, array( 'Version' => $installed ), 'leagueflow/leagueflow.php', array() ), 'Equal/older version offered' );
}
check( 'untouched' === Updater::check_update( 'untouched', array(), 'other/plugin.php', array() ), 'Another plugin update changed' );
$info = Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'leagueflow' ) );
check( '1.0.3' === $info->version && $manifest['package'] === $info->download_link, 'Plugin details do not match release' );
check( 'untouched' === Updater::plugin_information( 'untouched', 'query_plugins', (object) array( 'slug' => 'leagueflow' ) ), 'Other API action changed' );
check( 'untouched' === Updater::plugin_information( 'untouched', 'plugin_information', (object) array( 'slug' => 'another' ) ), 'Other plugin details changed' );

$invalid = array( '{}', 'invalid json', str_repeat( 'x', 8193 ) );
foreach ( array( 'schema' => 2, 'slug' => 'another', 'version' => '1.0.3-rc.1', 'sha256' => 'bad', 'requires' => array(), 'requires_php' => 'bad', 'package' => 'https://evil.example/plugin.zip' ) as $key => $bad ) {
	$invalid[] = array_merge( $manifest, array( $key => $bad ) );
}
$invalid[] = array_merge( $manifest, array( 'version' => '01.0.3' ) );
foreach ( $invalid as $bad ) {
	reset_feed( $bad );
	check( false === Updater::check_update( false, array( 'Version' => '1.0.2' ), 'leagueflow/leagueflow.php', array() ), 'Invalid manifest accepted' );
	check( 60 === reset( $ttls ), 'Failure cache TTL incorrect' );
	Updater::check_update( false, array(), 'leagueflow/leagueflow.php', array() );
	check( 1 === count( $requests ), 'Failed discovery not briefly cached' );
}
foreach ( array( 404, 500 ) as $code ) {
	reset_feed( $manifest, $code );
	check( false === Updater::check_update( false, array(), 'leagueflow/leagueflow.php', array() ), 'HTTP failure accepted' );
}
reset_feed( $manifest ); $responses = array();
check( false === Updater::check_update( false, array(), 'leagueflow/leagueflow.php', array() ), 'Network failure accepted' );
check( Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'leagueflow' ) ) instanceof WP_Error, 'Missing release details not reported' );

// Latest can advance while an administrator is installing the selected older release.
reset_feed( release_fixture( '1.0.4' ) );
$pinned_url = 'https://github.com/amirrad98/intramurals/releases/download/v1.0.3/latest.json';
$responses[$pinned_url] = array( 'code' => 200, 'body' => json_encode( $manifest ) );
$package = $manifest['package'];
$file = Updater::verify_download( false, $package, null, array( 'plugin' => 'leagueflow/leagueflow.php' ) );
check( is_string( $file ) && file_get_contents( $file ) === $download_bytes, 'Pinned release download not verified' );
check( $pinned_url === $requests[0][0] && 21600 === reset( $ttls ), 'Download did not use immutable version manifest/cache' );
unlink( $file );
$download_bytes = 'corrupted package';
$error = Updater::verify_download( false, $package, null, array() );
check( $error instanceof WP_Error && 'leagueflow_update_checksum' === $error->code, 'Altered package accepted' );
check( ! file_exists( end( $downloaded ) ), 'Rejected package retained' );
$download_mode = 'missing';
check( Updater::verify_download( false, $package, null, array() ) instanceof WP_Error, 'Missing downloaded file accepted' );
$download_mode = 'error';
check( 'download_error' === Updater::verify_download( false, $package, null, array() )->code, 'Download error not preserved' );
check( 'existing' === Updater::verify_download( 'existing', $package, null, array() ), 'Prior download result overwritten' );
check( false === Updater::verify_download( false, $package, null, array( 'plugin' => 'other/plugin.php' ) ), 'Another plugin download intercepted' );
check( false === Updater::verify_download( false, $package, null, array( 'type' => 'theme' ) ), 'Theme download intercepted' );
check( false === Updater::verify_download( false, '/tmp/manual-upload.zip', null, array() ), 'Manual upload intercepted' );
check( false === Updater::verify_download( false, 'https://example.test/another.zip', null, array() ), 'Unrelated package intercepted' );
check( Updater::verify_download( false, $package . '?altered=true', null, array() ) instanceof WP_Error, 'Malformed own package URL accepted' );
reset_feed( $manifest );
$responses[$pinned_url] = array( 'code' => 200, 'body' => json_encode( release_fixture( '1.0.4' ) ) );
check( 'leagueflow_update_manifest' === Updater::verify_download( false, $package, null, array() )->code, 'Pinned manifest mismatch accepted' );
reset_feed( $manifest );
check( 'leagueflow_update_manifest' === Updater::verify_download( false, $package, null, array() )->code, 'Missing pinned manifest accepted' );
echo 'PASS ' . $assertions . " updater contract assertions\n";
