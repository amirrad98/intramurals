<?php
// Shared match-card/admin formatter: saved dates mean site wall-clock time.
define( 'ABSPATH', __DIR__ );
$site_timezone = 'America/Vancouver';
function wp_timezone() { global $site_timezone; return new DateTimeZone( $site_timezone ); }
function get_option( $key, $fallback = null ) { return $fallback; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_date( $format, $timestamp, $timezone = null ) {
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format );
}
require ( $argv[1] ?? dirname( __DIR__ ) ) . '/includes/helpers.php';
$failures = 0;
function check_time( $input, $expected ) {
	global $failures;
	$actual = LeagueFlow\format_match_datetime( $input );
	$ok = $actual === $expected;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $input . ': ' . $actual . "\n";
	if ( ! $ok ) { $failures++; }
}
foreach ( array( 'UTC', 'Asia/Tokyo', 'America/Vancouver' ) as $php_timezone ) {
	date_default_timezone_set( $php_timezone );
	$site_timezone = 'America/Vancouver';
	check_time( '2026-10-15 18:30', 'October 15, 2026 6:30 pm' );
	check_time( '2026-01-15 18:30', 'January 15, 2026 6:30 pm' );
	check_time( '2026-10-16T01:30:00Z', 'October 15, 2026 6:30 pm' );
	$site_timezone = 'UTC';
	check_time( '2026-10-15 18:30', 'October 15, 2026 6:30 pm' );
}
check_time( '', '' );
check_time( 'not-a-date', '' );
check_time( '2026-02-30 18:30', '' );
exit( $failures ? 1 : 0 );
