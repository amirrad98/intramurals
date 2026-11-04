<?php
/**
 * Real HTTP acceptance tests for portal writes; never run against an existing site.
 *
 * Run with `LEAGUEFLOW_DISPOSABLE_WP=1 wp eval-file tests/wordpress-portal-http.php` on the
 * disposable http://leagueflow.test installation. The program creates its own fixtures,
 * installs a test-only mail/outbound guard as a must-use plugin, starts PHP's built-in
 * server on 127.0.0.1 only, and drives wp-login.php, the portal page and
 * wp-admin/admin-post.php with cookies, nonces scraped from rendered forms and multipart
 * uploads. Database state is read back in this process after every request. It covers the
 * application through PHP's CLI server, not a browser, a production web server or a device.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) || 'http://leagueflow.test' !== get_option( 'siteurl' ) ) {
	throw new RuntimeException( 'Set LEAGUEFLOW_DISPOSABLE_WP=1 and use only the disposable leagueflow.test installation.' );
}
if ( ! function_exists( 'curl_init' ) ) {
	throw new RuntimeException( 'The curl extension is required for the HTTP portal tests.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

const LFH_HOST = 'leagueflow.test';
$lfh_port = (int) ( getenv( 'LEAGUEFLOW_HTTP_PORT' ) ?: 8765 );
if ( $lfh_port < 1024 || $lfh_port > 65535 ) { throw new RuntimeException( 'LEAGUEFLOW_HTTP_PORT must be an unprivileged port.' ); }
define( 'LFH_BASE', 'http://127.0.0.1:' . $lfh_port );

function lfh_assert( $value, $message ) { global $lfh_assertions; ++$lfh_assertions; if ( ! $value ) { throw new RuntimeException( $message ); } }
function lfh_fresh() { wp_cache_flush(); }

/** One cookie session per user; requests only ever go to the local test server. */
class LFH_Client {
	public $label; private $curl;
	public function __construct( $label ) { $this->label = $label; $this->curl = curl_init(); curl_setopt( $this->curl, CURLOPT_COOKIEFILE, '' ); }
	public function request( $method, $path, $fields = null, $multipart = false ) {
		if ( '/' !== substr( $path, 0, 1 ) ) { throw new RuntimeException( 'Only local paths are allowed.' ); }
		$opts = array(
			CURLOPT_URL => LFH_BASE . $path, CURLOPT_HTTPHEADER => array( 'Host: ' . LFH_HOST ), CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POST => 'POST' === $method,
		);
		if ( 'POST' === $method ) { $opts[ CURLOPT_POSTFIELDS ] = $multipart ? $fields : http_build_query( (array) $fields ); } else { $opts[ CURLOPT_HTTPGET ] = true; }
		curl_setopt_array( $this->curl, $opts );
		$raw = curl_exec( $this->curl );
		if ( false === $raw ) { throw new RuntimeException( 'HTTP request failed: ' . curl_error( $this->curl ) ); }
		$size = curl_getinfo( $this->curl, CURLINFO_HEADER_SIZE );
		$headers = substr( $raw, 0, $size ); $location = preg_match( '/^Location:\s*(\S+)/mi', $headers, $m ) ? $m[1] : '';
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $q );
		return array( 'status' => (int) curl_getinfo( $this->curl, CURLINFO_RESPONSE_CODE ), 'headers' => $headers, 'body' => substr( $raw, $size ), 'location' => $location, 'notice' => $q['leagueflow_notice'] ?? '' );
	}
	public function cookies() { return implode( "\n", curl_getinfo( $this->curl, CURLINFO_COOKIELIST ) ); }
	public function login( $login, $password ) {
		$this->request( 'GET', '/wp-login.php' );
		$r = $this->request( 'POST', '/wp-login.php', array( 'log' => $login, 'pwd' => $password, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => '/wp-admin/profile.php' ) );
		lfh_assert( 302 === $r['status'] && false !== strpos( $this->cookies(), 'wordpress_logged_in_' ), "Cookie login failed for {$this->label} (HTTP {$r['status']})." );
	}
	public function portal( $query = '' ) {
		$r = $this->request( 'GET', '/?page_id=' . absint( get_option( 'leagueflow_portal_page_id' ) ) . ( $query ? '&' . $query : '' ) );
		lfh_assert( 200 === $r['status'], "Portal GET for {$this->label} returned HTTP {$r['status']}." ); return $r['body'];
	}
	/** Hidden fields of the first rendered portal form for $action whose hidden fields match $match (team workspace when $team is set). */
	public function form( $action, $match = array(), $team = 0, $query = '' ) {
		$team = $team ?: ( $match['lf_team_id'] ?? 0 );
		$html = $this->portal( $team ? 'leagueflow_view=teams&leagueflow_team=' . absint( $team ) : $query ); $seen = array();
		foreach ( lfh_forms( $html ) as $form ) {
			$seen[] = ( $form['hidden']['leagueflow_portal_action'] ?? '?' ) . ( isset( $form['hidden']['lf_team_id'] ) ? '#' . $form['hidden']['lf_team_id'] : '' );
			if ( ( $form['hidden']['leagueflow_portal_action'] ?? '' ) !== $action || array_diff_assoc( array_map( 'strval', $match ), $form['hidden'] ) ) { continue; }
			lfh_assert( false !== strpos( $form['action'], '/wp-admin/admin-post.php' ) && 'leagueflow_portal' === ( $form['hidden']['action'] ?? '' ) && ! empty( $form['hidden']['leagueflow_portal_nonce'] ), "Rendered $action form does not post to admin-post.php with a nonce." );
			return $form['hidden'];
		}
		if ( getenv( 'LEAGUEFLOW_HTTP_DEBUG' ) ) { file_put_contents( $GLOBALS['lfh_tmp'] . "/portal-{$this->label}.html", $html ); }
		throw new RuntimeException( "No rendered $action form for {$this->label}; rendered forms: " . implode( ', ', $seen ) . '.' );
	}
	public function submit( $fields, $files = array() ) {
		if ( ! $files ) { return $this->request( 'POST', '/wp-admin/admin-post.php', $fields ); }
		return $this->request( 'POST', '/wp-admin/admin-post.php', array_merge( lfh_flatten( $fields ), $files ), true );
	}
}
function lfh_forms( $html ) {
	$forms = array();
	preg_match_all( '#<form\b([^>]*)>(.*?)</form>#si', $html, $all, PREG_SET_ORDER );
	foreach ( $all as $f ) {
		$hidden = array();
		preg_match_all( '#<input\b([^>]*)>#i', $f[2], $inputs );
		foreach ( $inputs[1] as $attrs ) {
			preg_match_all( '#([a-z_-]+)\s*=\s*"([^"]*)"#i', $attrs, $a ); $a = array_change_key_case( array_combine( $a[1], $a[2] ) );
			if ( 'hidden' === strtolower( $a['type'] ?? '' ) && isset( $a['name'] ) ) { $hidden[ html_entity_decode( $a['name'] ) ] = html_entity_decode( $a['value'] ?? '' ); }
		}
		preg_match( '#action\s*=\s*"([^"]*)"#i', $f[1], $act );
		$forms[] = array( 'action' => html_entity_decode( $act[1] ?? '' ), 'hidden' => $hidden );
	}
	return $forms;
}
function lfh_flatten( $fields, $prefix = '' ) {
	$out = array();
	foreach ( $fields as $k => $v ) { $key = '' === $prefix ? $k : "{$prefix}[{$k}]"; if ( is_array( $v ) ) { $out += lfh_flatten( $v, $key ); } else { $out[ $key ] = (string) $v; } }
	return $out;
}
function lfh_expect( $notice, $r, $label ) {
	lfh_assert( 302 === $r['status'] && $notice === $r['notice'], "$label: expected 302 notice '$notice', got HTTP {$r['status']} notice '{$r['notice']}'." );
}
function lfh_snapshot( $ids ) { lfh_fresh(); $out = array(); foreach ( $ids as $id ) { $p = get_post( $id ); $out[ $id ] = $p ? array( $p->post_title, $p->post_content, $p->post_status, get_post_meta( $id ) ) : null; } return $out; }
function lfh_count( $type ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type=%s", $type ) ); }
function lfh_players_by( $key, $value ) { lfh_fresh(); return array_map( 'intval', get_posts( array( 'post_type' => 'lf_player', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => $key, 'meta_value' => $value ) ) ); }
function lfh_roles( $user_id ) { lfh_fresh(); clean_user_cache( $user_id ); $u = new WP_User( $user_id ); $r = $u->roles; sort( $r ); return $r; }
function lfh_file( $name, $bytes, $type ) { $tmp = $GLOBALS['lfh_tmp'] . '/' . $name; file_put_contents( $tmp, $bytes ); return new CURLFile( $tmp, $type, $name ); }
function lfh_mail() { $log = $GLOBALS['lfh_mail_log']; return is_file( $log ) ? array_map( 'json_decode', array_filter( explode( "\n", (string) file_get_contents( $log ) ) ) ) : array(); }

// Isolation: this process and the test server both load the must-use guard.
$GLOBALS['lfh_assertions'] = 0;
$lfh_tmp = $GLOBALS['lfh_tmp'] = rtrim( sys_get_temp_dir(), '/' ) . '/leagueflow-http-' . getmypid(); wp_mkdir_p( $lfh_tmp );
$lfh_mail_log = $GLOBALS['lfh_mail_log'] = $lfh_tmp . '/mail.jsonl';
$guard_file = WPMU_PLUGIN_DIR . '/leagueflow-http-test-guard.php';
if ( file_exists( $guard_file ) ) { throw new RuntimeException( 'A previous HTTP test guard is still installed; remove it after checking no test server is running.' ); }
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( $guard_file, '<?php
// Test-only guard written by tests/wordpress-portal-http.php; removed when the run ends.
if ( "http://leagueflow.test" !== get_option( "home" ) ) { return; }
add_filter( "pre_http_request", static function() { return new WP_Error( "leagueflow_http_test_offline", "Outbound HTTP blocked by the portal HTTP test." ); }, PHP_INT_MAX );
add_filter( "pre_wp_mail", static function( $pre, $atts ) { $log = getenv( "LEAGUEFLOW_HTTP_MAIL_LOG" ); if ( $log ) { file_put_contents( $log, wp_json_encode( array( "to" => (array) $atts["to"], "subject" => $atts["subject"] ) ) . "\n", FILE_APPEND | LOCK_EX ); } return true; }, PHP_INT_MAX, 2 );
add_filter( "leagueflow_registration_email_allowed", static function( $allowed, $email ) { return $allowed || "@example.test" === substr( (string) $email, -13 ); }, 10, 2 );
' );
add_filter( 'pre_http_request', static function() { return new WP_Error( 'lfh_offline', 'Outbound HTTP blocked.' ); }, PHP_INT_MAX );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$server = null;

try {
	// Fixtures (unique http_ prefix; passwords are random per run and never printed).
	\LeagueFlow\ensure_portal_roles();
	update_option( 'leagueflow_enabled_sports', array_values( array_unique( array_merge( (array) get_option( 'leagueflow_enabled_sports', array() ), array( 'soccer' ) ) ) ) );
	$sport = get_term_by( 'slug', 'soccer', 'lf_sport' ); $sport = $sport ? (int) $sport->term_id : (int) wp_insert_term( 'Soccer', 'lf_sport', array( 'slug' => 'soccer' ) )['term_id'];
	$level = get_term_by( 'slug', 'http-competitive', 'lf_league_level' ); $level = $level ? (int) $level->term_id : (int) wp_insert_term( 'HTTP Competitive', 'lf_league_level', array( 'slug' => 'http-competitive' ) )['term_id'];
	$settings = get_option( 'leagueflow_settings', array() ); $settings = is_array( $settings ) ? $settings : array();
	update_option( 'leagueflow_settings', array_merge( $settings, array( 'player_registration_open' => 1, 'captain_registration_open' => 1 ) ) );
	$u = array(); $pw = array(); $suffix = substr( md5( (string) microtime( true ) ), 0, 6 );
	foreach ( array( 'admin' => 'administrator', 'captain_a' => 'leagueflow_team_manager', 'captain_b' => 'leagueflow_team_manager', 'player_p' => 'leagueflow_player', 'joiner' => 'leagueflow_player', 'joiner2' => 'leagueflow_player', 'invitee' => 'subscriber', 'editor' => 'editor', 'creator' => 'subscriber', 'newbie' => 'subscriber' ) as $key => $role ) {
		$pw[ $key ] = wp_generate_password( 24, false );
		$u[ $key ] = wp_insert_user( array( 'user_login' => "http_{$key}_{$suffix}", 'user_pass' => $pw[ $key ], 'user_email' => "http_{$key}_{$suffix}@example.test", 'role' => $role, 'display_name' => "HTTP {$key} display {$suffix}" ) );
		lfh_assert( ! is_wp_error( $u[ $key ] ), "User fixture $key failed." );
	}
	$email = static function( $key ) use ( $suffix ) { return "http_{$key}_{$suffix}@example.test"; };
	$admin_id = $u['admin']; wp_set_current_user( $admin_id );
	$post = static function( $type, $title, $status ) use ( $admin_id ) { $id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'post_author' => $admin_id ), true ); lfh_assert( ! is_wp_error( $id ), 'Post fixture failed.' ); return (int) $id; };
	$team = static function( $title, $managers ) use ( $post, $sport, $level ) { $id = $post( 'lf_team', $title, 'publish' ); wp_set_object_terms( $id, array( $sport ), 'lf_sport' ); wp_set_object_terms( $id, array( $level ), 'lf_league_level' ); update_post_meta( $id, 'lf_manager_user_ids', $managers ); return $id; };
	$team_a = $team( "HTTP Team A $suffix", array( $u['captain_a'] ) ); $team_b = $team( "HTTP Team B $suffix", array( $u['captain_b'] ) ); $team_c = $team( "HTTP Team C $suffix", array( $u['captain_b'] ) );
	$player = static function( $title, $key, $teams ) use ( $post, $u, $email ) { $id = $post( 'lf_player', $title, 'private' ); if ( $key ) { update_post_meta( $id, 'lf_user_id', $u[ $key ] ); update_post_meta( $id, 'lf_email', $email( $key ) ); } foreach ( $teams as $t ) { \LeagueFlow\assign_player_to_team( $id, $t ); } return $id; };
	$p_ca = $player( 'HTTP Captain A', 'captain_a', array( $team_a ) ); $p_cb = $player( 'HTTP Captain B', 'captain_b', array( $team_b, $team_c ) );
	$p_p = $player( 'HTTP Player P', 'player_p', array( $team_a ) ); $p_q = $player( 'HTTP Player Q', '', array( $team_b ) ); $p_j = $player( 'HTTP Joiner', 'joiner', array() ); $p_j2 = $player( 'HTTP Joiner Two', 'joiner2', array() );
	foreach ( array( $p_ca, $p_cb, $p_p, $p_q, $p_j, $p_j2 ) as $id ) { wp_set_object_terms( $id, array( $sport ), 'lf_sport' ); wp_set_object_terms( $id, array( $level ), 'lf_league_level' ); }
	$match = static function( $home, $away ) use ( $post ) { $id = $post( 'lf_match', 'HTTP match', 'publish' ); update_post_meta( $id, 'lf_home_team_id', $home ); update_post_meta( $id, 'lf_away_team_id', $away ); update_post_meta( $id, 'lf_status', 'scheduled' ); update_post_meta( $id, 'lf_match_datetime', gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) . ' 18:30' ); return $id; };
	$m_ab = $match( $team_a, $team_b ); $m_bc = $match( $team_b, $team_c );
	lfh_assert( absint( get_option( 'leagueflow_portal_page_id' ) ) > 0, 'Portal page is missing.' );

	// Local server bound to 127.0.0.1 only.
	$probe = @stream_socket_client( 'tcp://127.0.0.1:' . $lfh_port, $errno, $errstr, 1 );
	if ( $probe ) { fclose( $probe ); throw new RuntimeException( "Port $lfh_port is already in use." ); }
	$env = array_merge( getenv(), array( 'LEAGUEFLOW_HTTP_MAIL_LOG' => $lfh_mail_log ) );
	$server = proc_open( array( PHP_BINARY, '-d', 'upload_max_filesize=2M', '-d', 'post_max_size=8M', '-d', 'display_errors=0', '-S', '127.0.0.1:' . $lfh_port, '-t', ABSPATH ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $lfh_tmp . '/server.log', 'a' ), 2 => array( 'file', $lfh_tmp . '/server.log', 'a' ) ), $pipes, ABSPATH, $env );
	for ( $i = 0; $i < 50; ++$i ) { $probe = @stream_socket_client( 'tcp://127.0.0.1:' . $lfh_port, $errno, $errstr, 1 ); if ( $probe ) { fclose( $probe ); break; } usleep( 100000 ); }
	lfh_assert( ! empty( $probe ), 'The local PHP test server did not start.' );

	$c = array(); foreach ( array_keys( $u ) as $key ) { $c[ $key ] = new LFH_Client( $key ); }
	$tests = array();

	$tests['HTTP-01 cookie login, routing and logged-out denial'] = static function() use ( $c, $u, $pw, $team_a ) {
		$anon = new LFH_Client( 'anonymous' );
		$anon->request( 'GET', '/wp-login.php' );
		$bad = $anon->request( 'POST', '/wp-login.php', array( 'log' => get_userdata( $u['captain_a'] )->user_login, 'pwd' => 'wrong-' . $pw['captain_a'], 'testcookie' => '1' ) );
		lfh_assert( 200 === $bad['status'] && false === strpos( $anon->cookies(), 'wordpress_logged_in_' ), 'Wrong password produced a session.' );
		$before = lfh_snapshot( array( $team_a ) );
		$r = $anon->submit( array( 'action' => 'leagueflow_portal', 'leagueflow_portal_action' => 'update_team_profile', 'leagueflow_portal_nonce' => 'abc123def4', 'lf_team_id' => $team_a, 'lf_team_name' => 'FORGED' ) );
		lfh_assert( in_array( $r['status'], array( 302, 400 ), true ) && $before === lfh_snapshot( array( $team_a ) ), "Logged-out admin-post request was not refused (HTTP {$r['status']})." );
		lfh_assert( false === strpos( $anon->portal(), 'leagueflow_portal_nonce' ), 'Logged-out portal rendered a write form.' );
		foreach ( $c as $key => $client ) { $client->login( get_userdata( $u[ $key ] )->user_login, $pw[ $key ] ); }
		lfh_assert( false !== strpos( $c['captain_a']->portal(), 'HTTP Team A' ), 'Captain portal does not show the managed team.' );
	};

	$tests['HTTP-02 missing, forged, other-user and wrong-action nonces'] = static function() use ( $c, $team_a, $team_b ) {
		$form = $c['captain_a']->form( 'update_team_profile', array( 'lf_team_id' => $team_a ) );
		$fields = array_merge( $form, array( 'lf_team_name' => 'FORGED NAME', 'lf_team_description' => 'FORGED' ) );
		$other = $c['captain_b']->form( 'update_team_profile', array(), $team_b )['leagueflow_portal_nonce'];
		$wrong = $c['captain_a']->form( 'add_player', array( 'lf_team_id' => $team_a ) )['leagueflow_portal_nonce'];
		$before = lfh_snapshot( array( $team_a ) );
		$missing = $fields; unset( $missing['leagueflow_portal_nonce'] );
		lfh_expect( 'invalid-request', $c['captain_a']->submit( $missing ), 'Missing nonce' );
		foreach ( array( 'forged' => 'abc123def4', 'other user' => $other, 'wrong action' => $wrong ) as $label => $nonce ) {
			lfh_expect( 'invalid-request', $c['captain_a']->submit( array_merge( $fields, array( 'leagueflow_portal_nonce' => $nonce ) ) ), "Nonce: $label" );
		}
		lfh_assert( $before === lfh_snapshot( array( $team_a ) ), 'A request with a bad nonce changed the team.' );
	};

	$tests['HTTP-03 own team save and forged cross-team edit'] = static function() use ( $c, $team_a, $team_b ) {
		$before = lfh_snapshot( array( $team_a ) );
		$b_form = $c['captain_b']->form( 'update_team_profile', array( 'lf_team_id' => $team_b ) );
		lfh_expect( 'team-denied', $c['captain_b']->submit( array_merge( $b_form, array( 'lf_team_id' => $team_a, 'lf_team_name' => 'FORGED', 'lf_team_description' => 'FORGED' ) ) ), 'Captain B posting to team A' );
		lfh_assert( $before === lfh_snapshot( array( $team_a ) ), 'Forged cross-team edit wrote.' );
		$form = $c['captain_a']->form( 'update_team_profile', array( 'lf_team_id' => $team_a ) );
		lfh_expect( 'team-saved', $c['captain_a']->submit( array_merge( $form, array( 'lf_team_name' => get_the_title( $team_a ), 'lf_team_description' => 'HTTP audit description <script>x</script>', 'lf_short_name' => 'HTA' ) ) ), 'Own team save' );
		lfh_fresh();
		$content = get_post_field( 'post_content', $team_a );
		lfh_assert( false !== strpos( $content, 'HTTP audit description' ) && false === strpos( $content, '<script' ) && 'HTA' === get_post_meta( $team_a, 'lf_short_name', true ), 'Own team save not stored/sanitized.' );
		lfh_assert( false !== strpos( $c['captain_a']->portal( 'leagueflow_view=teams&leagueflow_team=' . $team_a ), 'HTTP audit description' ), 'Saved description not shown after reload.' );
	};

	$tests['HTTP-04 roster add, duplicate submit and remove'] = static function() use ( $c, $team_a, $team_b, $p_p, $suffix ) {
		$players = lfh_count( 'lf_player' );
		$b_add = $c['captain_b']->form( 'add_player', array( 'lf_team_id' => $team_b ) );
		lfh_expect( 'team-denied', $c['captain_b']->submit( array_merge( $b_add, array( 'lf_team_id' => $team_a, 'lf_player_name' => 'Forged Add' ) ) ), 'Captain B adding to team A' );
		$add = array_merge( $c['captain_a']->form( 'add_player', array( 'lf_team_id' => $team_a ) ), array( 'lf_player_name' => 'HTTP Roster New', 'lf_email' => "http-roster-new-$suffix@example.test", 'lf_jersey_number' => '7' ) );
		lfh_expect( 'player-added', $c['captain_a']->submit( $add ), 'Own roster add' );
		lfh_expect( 'player-added', $c['captain_a']->submit( $add ), 'Duplicate identical add' );
		$new = lfh_players_by( 'lf_email', "http-roster-new-$suffix@example.test" );
		lfh_assert( 1 === count( $new ) && $players + 1 === lfh_count( 'lf_player' ) && \LeagueFlow\player_has_team( $new[0], $team_a ) && 'private' === get_post_status( $new[0] ), 'Duplicate add was not idempotent/private.' );
		lfh_assert( false !== strpos( $c['captain_a']->portal( 'leagueflow_view=teams&leagueflow_team=' . $team_a ), 'HTTP Roster New' ), 'Added player not shown after reload.' );
		$b_remove = $c['captain_b']->form( 'remove_roster_player', array( 'lf_team_id' => $team_b ) );
		lfh_expect( 'team-denied', $c['captain_b']->submit( array_merge( $b_remove, array( 'lf_team_id' => $team_a, 'lf_player_id' => $p_p ) ) ), 'Captain B removing a team A player' );
		lfh_fresh(); lfh_assert( \LeagueFlow\player_has_team( $p_p, $team_a ), 'Forged removal changed team A.' );
		$remove = $c['captain_a']->form( 'remove_roster_player', array( 'lf_team_id' => $team_a, 'lf_player_id' => $new[0] ) );
		lfh_expect( 'player-removed', $c['captain_a']->submit( $remove ), 'Own roster removal' );
		lfh_expect( 'team-denied', $c['captain_a']->submit( $remove ), 'Duplicate removal' );
		lfh_fresh(); lfh_assert( ! \LeagueFlow\player_has_team( $new[0], $team_a ) && 'private' === get_post_status( $new[0] ), 'Removal did not detach (or deleted) the player.' );
	};

	$tests['HTTP-05 malformed email, privileged invites, name privacy and self-claim'] = static function() use ( $c, $u, $email, $team_a, $pw ) {
		$form = $c['captain_a']->form( 'add_player', array( 'lf_team_id' => $team_a ) );
		$players = lfh_count( 'lf_player' );
		lfh_expect( 'invalid-email', $c['captain_a']->submit( array_merge( $form, array( 'lf_player_name' => 'Bad Email', 'lf_email' => 'not-an-email' ) ) ), 'Malformed email' );
		// F-031: a captain must not learn another account's display name from its email.
		lfh_expect( 'invalid-request', $c['captain_a']->submit( array_merge( $form, array( 'lf_player_name' => '', 'lf_email' => $email( 'editor' ) ) ) ), 'Existing account email without a name' );
		lfh_assert( $players === lfh_count( 'lf_player' ) && false === strpos( $c['captain_a']->portal(), get_userdata( $u['editor'] )->display_name ), 'Captain saw or stored another account display name.' );
		// F-029: inviting privileged emails never binds or promotes those accounts.
		$roles = array( 'admin' => lfh_roles( $u['admin'] ), 'editor' => lfh_roles( $u['editor'] ) );
		foreach ( array( 'admin', 'editor' ) as $key ) {
			lfh_expect( 'player-added', $c['captain_a']->submit( array_merge( $form, array( 'lf_player_name' => "Invited $key", 'lf_email' => $email( $key ) ) ) ), "Captain invites $key email" );
			lfh_assert( $roles[ $key ] === lfh_roles( $u[ $key ] ) && ! lfh_players_by( 'lf_user_id', $u[ $key ] ), "Captain bound or promoted the $key account." );
		}
		// F-032: privileged accounts opening the portal do not claim captain-created entries.
		// (Administrators manage every team, so the portal adds leagueflow_team_manager by design.)
		$c['editor']->portal(); $c['admin']->portal();
		foreach ( array( 'admin', 'editor' ) as $key ) {
			lfh_assert( ! in_array( 'leagueflow_player', lfh_roles( $u[ $key ] ), true ) && ! lfh_players_by( 'lf_user_id', $u[ $key ] ), "The $key account claimed a captain-created entry at portal sign-in." );
		}
		lfh_assert( $roles['editor'] === lfh_roles( $u['editor'] ), 'Editor roles changed at portal sign-in.' );
		// The ordinary invited account still claims its own entry.
		lfh_expect( 'player-added', $c['captain_a']->submit( array_merge( $form, array( 'lf_player_name' => 'Invited subscriber', 'lf_email' => $email( 'invitee' ) ) ) ), 'Captain invites subscriber email' );
		lfh_assert( ! lfh_players_by( 'lf_user_id', $u['invitee'] ), 'Subscriber was bound before signing in.' );
		$c['invitee']->portal();
		$claimed = lfh_players_by( 'lf_user_id', $u['invitee'] );
		lfh_assert( 1 === count( $claimed ) && \LeagueFlow\player_has_team( $claimed[0], $team_a ) && in_array( 'leagueflow_player', lfh_roles( $u['invitee'] ), true ), 'Invited subscriber could not claim its entry.' );
	};

	$tests['HTTP-06 join request, review, approval and rejection'] = static function() use ( $c, $u, $email, $team_a, $team_b, $p_j, $p_j2, $sport, $level ) {
		$req = static function( $client, $team ) use ( $level ) { return array_merge( $client->form( 'request_join_team' ), array( 'lf_sport_slug' => 'soccer', 'lf_team_id' => (string) $team, 'lf_league_level_id' => $level, 'lf_request_note' => 'HTTP audit request' ) ); };
		$requests = lfh_count( 'lf_join_request' ); @unlink( $GLOBALS['lfh_mail_log'] );
		$join = $req( $c['joiner'], $team_a );
		lfh_expect( 'join-request-sent', $c['joiner']->submit( $join ), 'Join request' );
		lfh_expect( 'join-request-exists', $c['joiner']->submit( $join ), 'Duplicate join request' );
		lfh_expect( 'join-request-sent', $c['joiner2']->submit( $req( $c['joiner2'], $team_b ) ), 'Second join request' );
		lfh_assert( $requests + 2 === lfh_count( 'lf_join_request' ), 'Join requests not stored exactly once each.' );
		$mail = lfh_mail();
		lfh_assert( 2 === count( $mail ) && in_array( $email( 'captain_a' ), $mail[0]->to, true ), 'Captain notifications were not captured by the local mail guard exactly once each.' );
		lfh_fresh();
		$find = static function( $player ) { return (int) get_posts( array( 'post_type' => 'lf_join_request', 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'lf_player_id', 'meta_value' => $player ) )[0]; };
		$r1 = $find( $p_j ); $r2 = $find( $p_j2 );
		$b_review = $c['captain_b']->form( 'review_join_request', array( 'lf_join_request_id' => $r2 ), $team_b );
		lfh_expect( 'team-denied', $c['captain_b']->submit( array_merge( $b_review, array( 'lf_join_request_id' => $r1, 'lf_join_decision' => 'approve' ) ) ), 'Captain B approving a team A request' );
		lfh_fresh(); lfh_assert( 'pending' === get_post_meta( $r1, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j, $team_a ), 'Forged review changed the request.' );
		$approve = array_merge( $c['captain_a']->form( 'review_join_request', array( 'lf_join_request_id' => $r1 ), $team_a ), array( 'lf_join_decision' => 'approve' ) );
		lfh_assert( 16 === strlen( (string) ( $approve['lf_request_state'] ?? '' ) ), 'The rendered review form does not carry the request state.' );
		lfh_expect( 'join-request-changed', $c['captain_a']->submit( array_diff_key( $approve, array( 'lf_request_state' => 1 ) ) ), 'Approval with the state field removed' );
		// The request changes after the captain loaded the page (another reviewer's view of it differs).
		update_post_meta( $r1, 'lf_player_id', $p_j2 );
		lfh_expect( 'join-request-changed', $c['captain_a']->submit( $approve ), 'Approval from a page loaded before the request changed' );
		update_post_meta( $r1, 'lf_player_id', $p_j );
		lfh_fresh(); lfh_assert( 'pending' === get_post_meta( $r1, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j, $team_a ) && ! \LeagueFlow\player_has_team( $p_j2, $team_a ) && 0 === count( \LeagueFlow\Join_Request_Service::shared()->get_history( $r1 ) ), 'A stale portal form changed the request.' );
		lfh_expect( 'join-request-approved', $c['captain_a']->submit( $approve ), 'Owner approval' );
		lfh_fresh(); lfh_assert( 1 === count( \LeagueFlow\Join_Request_Service::shared()->get_history( $r1 ) ), 'Approval was not recorded exactly once.' );
		lfh_expect( 'team-denied', $c['captain_a']->submit( $approve ), 'Duplicate approval' );
		lfh_fresh(); lfh_assert( 'approved' === get_post_meta( $r1, 'lf_request_status', true ) && \LeagueFlow\player_has_team( $p_j, $team_a ), 'Approval did not add the player.' );
		lfh_expect( 'join-request-declined', $c['captain_b']->submit( array_merge( $b_review, array( 'lf_join_decision' => 'decline' ) ) ), 'Owner rejection' );
		lfh_fresh(); lfh_assert( 'declined' === get_post_meta( $r2, 'lf_request_status', true ) && ! \LeagueFlow\player_has_team( $p_j2, $team_b ), 'Rejected request joined the team.' );
	};

	$tests['HTTP-07 match availability'] = static function() use ( $c, $m_ab, $m_bc, $p_p ) {
		$form = $c['player_p']->form( 'set_availability', array( 'lf_match_id' => $m_ab ) );
		$fields = array_merge( $form, array( 'lf_availability_status' => 'available' ) );
		lfh_expect( 'availability-saved', $c['player_p']->submit( $fields ), 'Own match availability' );
		lfh_expect( 'availability-saved', $c['player_p']->submit( $fields ), 'Duplicate availability' );
		lfh_fresh(); lfh_assert( 'available' === \LeagueFlow\Availability::get_status( $p_p, $m_ab ) && 1 === \LeagueFlow\Availability::counts( $m_ab )['total'], 'Duplicate availability stored more than one response.' );
		lfh_expect( 'access-denied', $c['player_p']->submit( array_merge( $fields, array( 'lf_match_id' => $m_bc ) ) ), 'Availability for a non-member match' );
		lfh_fresh(); lfh_assert( '' === \LeagueFlow\Availability::get_status( $p_p, $m_bc ), 'Foreign availability stored.' );
	};

	$tests['HTTP-08 multipart uploads; rejected files leave text unchanged'] = static function() use ( $c, $p_p, $team_a, $team_b, $level ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==' );
		$form = array_merge( $c['player_p']->form( 'update_player_profile' ), array( 'lf_player_sports' => array( 'soccer' ), 'lf_player_sport_levels' => array( 'soccer' => $level ) ) );
		$attachments = lfh_count( 'attachment' );
		lfh_expect( 'profile-saved', $c['player_p']->submit( array_merge( $form, array( 'lf_player_description' => 'HTTP profile with photo' ) ), array( 'lf_player_photo' => lfh_file( 'http-avatar.png', $png, 'image/png' ) ) ), 'Valid PNG upload' );
		lfh_fresh(); $thumb = (int) get_post_thumbnail_id( $p_p );
		lfh_assert( $attachments + 1 === lfh_count( 'attachment' ) && $thumb && 'image/png' === get_post_mime_type( $thumb ) && is_file( get_attached_file( $thumb ) ), 'Valid multipart PNG was not stored as the player photo.' );
		$uploads = wp_upload_dir(); $profile_before = lfh_snapshot( array( $p_p ) );
		foreach ( array( 'php-as-png' => array( 'http-evil.png', '<?php echo 1; ?>', 'image/png' ), 'php' => array( 'http-evil.php', '<?php echo 1; ?>', 'application/x-php' ), 'svg' => array( 'http-evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml' ), 'oversize' => array( 'http-big.png', $png . str_repeat( "\0", 2500000 ), 'image/png' ) ) as $label => $file ) {
			$text = "HTTP rejected upload text $label";
			lfh_expect( 'upload-error', $c['player_p']->submit( array_merge( $form, array( 'lf_player_description' => $text ) ), array( 'lf_player_photo' => lfh_file( $file[0], $file[1], $file[2] ) ) ), "Rejected upload $label" );
			lfh_fresh();
			lfh_assert( $attachments + 1 === lfh_count( 'attachment' ) && $thumb === (int) get_post_thumbnail_id( $p_p ), "Rejected upload $label created an attachment or replaced the photo." );
			// A rejected file must not leave a partial save of the submitted text fields.
			lfh_assert( $profile_before === lfh_snapshot( array( $p_p ) ) && 'HTTP profile with photo' === get_post_field( 'post_content', $p_p ), "Rejected upload $label still saved the profile text." );
		}
		$stray = array(); foreach ( array( 'php', 'svg' ) as $ext ) { $stray = array_merge( $stray, (array) glob( trailingslashit( $uploads['basedir'] ) . '*/*/http-evil*.' . $ext ) ); }
		lfh_assert( empty( $stray ), 'A rejected upload reached the uploads directory.' );
		$b_form = $c['captain_b']->form( 'update_team_profile', array( 'lf_team_id' => $team_b ) );
		lfh_expect( 'team-denied', $c['captain_b']->submit( array_merge( $b_form, array( 'lf_team_id' => $team_a, 'lf_team_name' => 'X' ) ), array( 'lf_team_logo' => lfh_file( 'http-logo-forged.png', $png, 'image/png' ) ) ), 'Forged logo upload for team A' );
		lfh_fresh(); lfh_assert( $attachments + 1 === lfh_count( 'attachment' ) && ! get_post_thumbnail_id( $team_a ), 'Forged logo upload stored a file.' );
		$a_form = $c['captain_a']->form( 'update_team_profile', array( 'lf_team_id' => $team_a ) ); $team_before = lfh_snapshot( array( $team_a ) );
		lfh_expect( 'upload-error', $c['captain_a']->submit( array_merge( $a_form, array( 'lf_team_name' => 'HTTP rejected logo rename', 'lf_team_description' => 'HTTP rejected logo text', 'lf_short_name' => 'RLX' ) ), array( 'lf_team_logo' => lfh_file( 'http-evil-logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml' ) ) ), 'Own team SVG logo' );
		lfh_assert( $team_before === lfh_snapshot( array( $team_a ) ) && ! get_post_thumbnail_id( $team_a ), 'Rejected team logo still saved the team text.' );
		lfh_expect( 'team-saved', $c['captain_a']->submit( array_merge( $a_form, array( 'lf_team_name' => get_the_title( $team_a ) ) ), array( 'lf_team_logo' => lfh_file( 'http-logo.png', $png, 'image/png' ) ) ), 'Own team logo upload' );
		lfh_fresh(); lfh_assert( get_post_thumbnail_id( $team_a ) && $attachments + 2 === lfh_count( 'attachment' ), 'Own team logo was not stored.' );
	};

	$tests['HTTP-09 team creation and player onboarding validation over HTTP'] = static function() use ( $c, $u, $team_a, $m_ab, $level, $suffix ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==' );
		$state = static function() use ( $u ) { lfh_fresh(); return array( lfh_count( 'lf_team' ), lfh_count( 'lf_player' ), lfh_count( 'lf_join_request' ), lfh_count( 'attachment' ), lfh_roles( $u['creator'] ), lfh_roles( $u['newbie'] ), get_userdata( $u['creator'] )->display_name, get_userdata( $u['newbie'] )->display_name, count( lfh_mail() ) ); };
		$before = $state();
		$cap = array_merge( $c['creator']->form( 'complete_captain_onboarding', array(), 0, 'leagueflow_path=captain' ), array( 'lf_sport_slug' => 'soccer', 'lf_league_level_id' => $level, 'lf_team_name' => "HTTP Created Team $suffix", 'lf_captain_name' => 'HTTP Creator Person', 'lf_short_name' => 'HCT' ) );
		lfh_expect( 'upload-error', $c['creator']->submit( $cap, array( 'lf_team_logo' => lfh_file( 'http-evil-create.png', '<?php echo 1; ?>', 'image/png' ) ) ), 'Team creation with a PHP file named .png' );
		lfh_expect( 'invalid-request', $c['creator']->submit( array_merge( $cap, array( 'lf_league_level_id' => 999999 ) ) ), 'Team creation with a nonexistent level' );
		lfh_expect( 'invalid-request', $c['creator']->submit( array_merge( $cap, array( 'lf_sport_slug' => 'quidditch' ) ) ), 'Team creation with an unknown sport' );
		lfh_expect( 'team-exists', $c['creator']->submit( array_merge( $cap, array( 'lf_team_name' => get_the_title( $team_a ) ) ) ), 'Team creation duplicating a team name' );
		lfh_expect( 'invalid-request', $c['creator']->submit( array_merge( $cap, array( 'leagueflow_portal_action' => 'create_team' ) ) ), 'Captain-onboarding nonce replayed for create_team' );
		lfh_expect( 'team-denied', $c['captain_a']->submit( array_merge( $c['captain_a']->form( 'update_team_profile', array( 'lf_team_id' => $team_a ) ), array( 'lf_team_id' => 0, 'lf_team_name' => 'X' ) ) ), 'Team profile save with a zero team ID' );
		lfh_assert( $before === $state(), 'Rejected team creation left a team, player, role, display name, attachment or mail.' );
		$join = array_merge( $c['newbie']->form( 'complete_player_onboarding', array(), 0, 'leagueflow_path=player' ), array( 'lf_player_name' => 'HTTP Newbie Person', 'lf_player_sports' => array( 'soccer' ), 'lf_player_sport_levels' => array( 'soccer' => $level ), 'lf_player_team_choices' => array( 'soccer' => $team_a ) ) );
		lfh_expect( 'upload-error', $c['newbie']->submit( $join, array( 'lf_player_photo' => lfh_file( 'http-evil-join.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/svg+xml' ) ) ), 'Player onboarding with an SVG photo' );
		lfh_expect( 'team-level-mismatch', $c['newbie']->submit( array_merge( $join, array( 'lf_player_team_choices' => array( 'soccer' => $m_ab ) ) ) ), 'Player onboarding naming a match as the team' );
		lfh_expect( 'name-setup-required', $c['newbie']->submit( array_merge( $join, array( 'lf_player_name' => get_userdata( $u['newbie'] )->user_login ) ) ), 'Player onboarding with the login code as name' );
		lfh_expect( 'sport-level-required', $c['newbie']->submit( array_merge( $join, array( 'lf_player_sports' => array( 'hockey' ) ) ) ), 'Player onboarding for a disabled sport' );
		lfh_assert( $before === $state(), 'Rejected player onboarding left a player, join request, role, display name, attachment or mail.' );
		// Positive anchor: the same form with a real PNG creates one owned team with a stored logo.
		lfh_expect( 'team-registered', $c['creator']->submit( $cap, array( 'lf_team_logo' => lfh_file( 'http-created-logo.png', $png, 'image/png' ) ) ), 'Valid multipart team creation' );
		lfh_fresh(); $created = get_posts( array( 'post_type' => 'lf_team', 'post_status' => 'publish', 'title' => "HTTP Created Team $suffix", 'fields' => 'ids' ) );
		lfh_assert( 1 === count( $created ) && array( $u['creator'] ) === array_map( 'intval', (array) get_post_meta( $created[0], 'lf_manager_user_ids', true ) ) && 'image/png' === get_post_mime_type( (int) get_post_thumbnail_id( $created[0] ) ) && in_array( 'leagueflow_team_manager', lfh_roles( $u['creator'] ), true ), 'Valid team creation did not store one owned team with its PNG logo.' );
		lfh_expect( 'captain-sport-exists', $c['creator']->submit( array_merge( $cap, array( 'lf_team_name' => "HTTP Created Team Two $suffix" ) ) ), 'Repeat team creation in the same sport' );
	};

	$failures = array();
	foreach ( $tests as $name => $test ) {
		try { $test(); WP_CLI::log( 'PASS ' . $name ); }
		catch ( Throwable $error ) { $failures[] = $name . ': ' . $error->getMessage(); WP_CLI::warning( end( $failures ) ); }
	}
} finally {
	if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); }
	if ( file_exists( $guard_file ) && ! unlink( $guard_file ) ) {
		throw new RuntimeException( 'Could not remove the portal HTTP test guard: ' . $guard_file );
	}
	clearstatcache( true, $guard_file );
	lfh_assert( ! file_exists( $guard_file ), 'The portal HTTP test guard was left installed after cleanup.' );
	if ( ! getenv( 'LEAGUEFLOW_HTTP_DEBUG' ) ) { foreach ( (array) glob( $lfh_tmp . '/*' ) as $tmp_file ) { @unlink( $tmp_file ); } @rmdir( $lfh_tmp ); }
}
if ( ! empty( $failures ) ) { WP_CLI::error( implode( "\n", $failures ) ); }
lfh_assert( $GLOBALS['lfh_assertions'] > 60, 'HTTP portal assertions were unexpectedly skipped.' );
WP_CLI::success( count( $tests ) . ' HTTP portal groups pass; ' . $GLOBALS['lfh_assertions'] . ' assertions. WordPress ' . get_bloginfo( 'version' ) );
