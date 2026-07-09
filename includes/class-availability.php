<?php
/**
 * Per-match player availability (RSVP) storage.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each player's availability for each match.
 *
 * This is a high-cardinality many-to-many relationship (players x matches), so
 * it lives in a dedicated table with a UNIQUE(player, match) upsert rather than
 * post meta.
 */
class Availability {

	/**
	 * Option name tracking the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'leagueflow_availability_db_version';

	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Allowed availability statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'available' => __( 'Available', 'leagueflow' ),
			'maybe'     => __( 'Maybe', 'leagueflow' ),
			'out'       => __( 'Out', 'leagueflow' ),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'maybe_create_table' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	/**
	 * Remove availability rows when a match is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete_post( $post_id ) {
		if ( 'lf_match' === get_post_type( $post_id ) ) {
			self::delete_for_match( $post_id );
		}
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'leagueflow_availability';
	}

	/**
	 * Create or upgrade the availability table when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::create_table();
	}

	/**
	 * Create the availability table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			player_id BIGINT UNSIGNED NOT NULL,
			match_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'available',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY player_match (player_id, match_id),
			KEY match_id (match_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Record a player's availability for a match (idempotent upsert).
	 *
	 * @param int    $player_id Player post ID.
	 * @param int    $match_id Match post ID.
	 * @param string $status Availability status.
	 * @return bool Whether the row was written.
	 */
	public static function set( $player_id, $match_id, $status ) {
		global $wpdb;

		$player_id = absint( $player_id );
		$match_id  = absint( $match_id );
		$status    = sanitize_key( $status );

		if ( ! $player_id || ! $match_id || ! isset( self::statuses()[ $status ] ) ) {
			return false;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (player_id, match_id, status, updated_at)
				VALUES (%d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)",
				$player_id,
				$match_id,
				$status,
				current_time( 'mysql' )
			)
		);

		return false !== $result;
	}

	/**
	 * Get a single player's status for a match.
	 *
	 * @param int $player_id Player post ID.
	 * @param int $match_id Match post ID.
	 * @return string Status or '' when none saved.
	 */
	public static function get_status( $player_id, $match_id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE player_id = %d AND match_id = %d",
				absint( $player_id ),
				absint( $match_id )
			)
		);

		return $status ? (string) $status : '';
	}

	/**
	 * Get a player's statuses for a set of matches.
	 *
	 * @param int             $player_id Player post ID.
	 * @param array<int, int> $match_ids Match IDs.
	 * @return array<int, string> Status keyed by match ID.
	 */
	public static function statuses_for_player( $player_id, $match_ids ) {
		global $wpdb;

		$match_ids = array_values( array_filter( array_map( 'absint', (array) $match_ids ) ) );

		if ( ! $player_id || empty( $match_ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $match_ids ), '%d' ) );
		$params       = array_merge( array( absint( $player_id ) ), $match_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT match_id, status FROM {$table} WHERE player_id = %d AND match_id IN ({$placeholders})",
				$params
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->match_id ] = (string) $row->status;
		}

		return $out;
	}

	/**
	 * Count availability responses for a match.
	 *
	 * @param int $match_id Match post ID.
	 * @return array<string, int> Counts keyed by status plus a 'total' key.
	 */
	public static function counts( $match_id ) {
		global $wpdb;

		$counts = array(
			'available' => 0,
			'maybe'     => 0,
			'out'       => 0,
			'total'     => 0,
		);

		$match_id = absint( $match_id );

		if ( ! $match_id ) {
			return $counts;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE match_id = %d GROUP BY status",
				$match_id
			)
		);

		foreach ( (array) $rows as $row ) {
			$status = (string) $row->status;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row->total;
			}
			$counts['total'] += (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Remove all availability rows for a match (used when a match is deleted).
	 *
	 * @param int $match_id Match post ID.
	 * @return void
	 */
	public static function delete_for_match( $match_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), array( 'match_id' => absint( $match_id ) ), array( '%d' ) );
	}
}
