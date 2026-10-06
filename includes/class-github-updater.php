<?php
/**
 * Discover and verify LeagueFlow's public GitHub release packages.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

final class GitHub_Updater {

	private const REPOSITORY = 'https://github.com/amirrad98/intramurals';
	private const VERSION_PATTERN = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)';

	public static function register() {
		add_filter( 'update_plugins_github.com', array( self::class, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'verify_download' ), 10, 4 );
	}

	private static function plugin_file() {
		return plugin_basename( LEAGUEFLOW_FILE );
	}

	/** Return validated release metadata, with a short negative cache for outages. */
	private static function manifest( $version = '' ) {
		$cache_key = 'leagueflow_github_release_' . md5( $version ?: 'latest' );
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return isset( $cached['unavailable'] ) ? null : $cached;
		}

		$url = self::REPOSITORY . '/releases/' . ( $version ? 'download/v' . $version : 'latest/download' ) . '/latest.json';
		$response = wp_safe_remote_get(
			$url,
			array( 'timeout' => 10, 'redirection' => 5, 'limit_response_size' => 8193, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		$body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		$data = is_string( $body ) && strlen( $body ) <= 8192 ? json_decode( $body, true ) : null;
		$valid = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response )
			&& is_array( $data ) && 1 === ( $data['schema'] ?? null ) && 'leagueflow' === ( $data['slug'] ?? null )
			&& is_string( $data['version'] ?? null ) && preg_match( '~^' . self::VERSION_PATTERN . '$~D', $data['version'] )
			&& ( ! $version || $version === $data['version'] )
			&& is_string( $data['sha256'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] )
			&& is_string( $data['requires'] ?? null ) && preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $data['requires'] )
			&& is_string( $data['requires_php'] ?? null ) && preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $data['requires_php'] )
			&& ( $data['package'] ?? null ) === self::package_url( $data['version'] );

		if ( ! $valid ) {
			set_site_transient( $cache_key, array( 'unavailable' => true ), MINUTE_IN_SECONDS );
			return null;
		}
		set_site_transient( $cache_key, $data, $version ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	private static function package_url( $version ) {
		return self::REPOSITORY . '/releases/download/v' . $version . '/leagueflow-' . $version . '.zip';
	}

	public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( self::plugin_file() !== $plugin_file ) {
			return $update;
		}
		$release = self::manifest();
		if ( ! $release || version_compare( $release['version'], $plugin_data['Version'] ?? LEAGUEFLOW_VERSION, '<=' ) ) {
			return false;
		}
		return array(
			'id' => self::REPOSITORY,
			'slug' => 'leagueflow',
			'version' => $release['version'],
			'url' => self::REPOSITORY . '/releases/tag/v' . $release['version'],
			'package' => $release['package'],
			'requires' => $release['requires'],
			'requires_php' => $release['requires_php'],
			'autoupdate' => false,
		);
	}

	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'leagueflow' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::manifest();
		if ( ! $release ) {
			return new \WP_Error( 'leagueflow_release_unavailable', __( 'The LeagueFlow release information is temporarily unavailable. Please try again later.', 'leagueflow' ) );
		}
		return (object) array(
			'name' => 'LeagueFlow',
			'slug' => 'leagueflow',
			'version' => $release['version'],
			'author' => '1stform',
			'homepage' => self::REPOSITORY,
			'requires' => $release['requires'],
			'requires_php' => $release['requires_php'],
			'download_link' => $release['package'],
			'external' => true,
			'sections' => array(
				'description' => __( 'WordPress league management for teams, players, fixtures, standings and knockout brackets.', 'leagueflow' ),
				'changelog' => '<a href="' . esc_url( self::REPOSITORY . '/releases/tag/v' . $release['version'] ) . '">' . esc_html__( 'View release notes on GitHub', 'leagueflow' ) . '</a>',
			),
		);
	}

	/** Pin verification to the selected release, even when a newer release exists. */
	public static function verify_download( $reply, $package, $upgrader, $hook_extra ) {
		if ( false !== $reply || ( isset( $hook_extra['plugin'] ) && self::plugin_file() !== $hook_extra['plugin'] )
			|| ( isset( $hook_extra['type'] ) && 'plugin' !== $hook_extra['type'] ) ) {
			return $reply;
		}
		$prefix = self::REPOSITORY . '/releases/download/';
		if ( ! is_string( $package ) || 0 !== strpos( $package, $prefix ) ) {
			return $reply;
		}
		$pattern = '~^' . preg_quote( $prefix, '~' ) . 'v(' . self::VERSION_PATTERN . ')/leagueflow-\1\.zip$~D';
		if ( ! preg_match( $pattern, $package, $matches ) ) {
			return new \WP_Error( 'leagueflow_update_package', __( 'The LeagueFlow release package URL is invalid.', 'leagueflow' ) );
		}
		$release = self::manifest( $matches[1] );
		if ( ! $release || $package !== $release['package'] ) {
			return new \WP_Error( 'leagueflow_update_manifest', __( 'The LeagueFlow release manifest could not be verified.', 'leagueflow' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$digest = is_string( $file ) && is_file( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( ! is_string( $digest ) || ! hash_equals( $release['sha256'], $digest ) ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
			return new \WP_Error( 'leagueflow_update_checksum', __( 'The LeagueFlow download did not match its release checksum.', 'leagueflow' ) );
		}
		return $file;
	}
}
