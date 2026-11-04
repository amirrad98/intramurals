<?php
/**
 * Match status lifecycle.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Watches the lf_status meta of matches and fires a transition action.
 *
 * This is the single choke point every status write flows through — the admin
 * metabox, the "Set status to X" bulk action, and any REST or programmatic
 * update all land here — so features such as notifications, live scoring, and
 * derived-standings triggers can subscribe to one dependable event instead of
 * piggy-backing on the generic save_post_lf_match hook.
 *
 * Consumers hook:
 *   do_action( 'leagueflow_match_status_changed', int $match_id, string $new, string $old );
 */
class Match_Status {

	/**
	 * The match meta key that carries the lifecycle status.
	 *
	 * @var string
	 */
	const META_KEY = 'lf_status';

	/**
	 * Previous status values captured before a meta update, keyed by post ID.
	 *
	 * @var array<int, string>
	 */
	protected static $previous = array();

	/** Result writers defer notifications until all result fields commit. */
	protected static $deferred = array();

	/** Start collecting this match's lifecycle notifications inside a transaction. */
	public static function defer_transitions( $match_id ) {
		self::$deferred[ (int) $match_id ] = null;
	}

	/** Publish one committed transition, or discard a rolled-back transition. */
	public static function flush_transitions( $match_id, $committed = true ) {
		$match_id = (int) $match_id;
		$pending = self::$deferred[ $match_id ] ?? null;
		unset( self::$deferred[ $match_id ], self::$previous[ $match_id ] );
		if ( $committed && is_array( $pending ) && $pending['old'] !== $pending['new'] ) {
			do_action( 'leagueflow_match_status_changed', $match_id, $pending['new'], $pending['old'] );
		}
	}

	/** Resolve deliberate editor score entry without overriding an active lifecycle. */
	public static function status_for_score_entry( $status, $home, $away, $outcome = '', $keep_status = false, $finalize = false ) {
		if ( $finalize ) { return 'finished'; }
		$status = isset( self::statuses()[ $status ] ) ? $status : 'scheduled';
		$forfeit = in_array( $outcome, array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true );
		if ( ! $keep_status && 'scheduled' === $status && ( $forfeit || ( self::is_valid_score( $home ) && self::is_valid_score( $away ) ) ) ) {
			return 'finished';
		}
		return $status;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'update_post_metadata', array( $this, 'capture_previous_status' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_status_updated' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'on_status_added' ), 10, 4 );
	}

	/**
	 * Canonical match statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'scheduled' => __( 'Scheduled', 'leagueflow' ),
			'live'      => __( 'Live', 'leagueflow' ),
			'finished'  => __( 'Finished', 'leagueflow' ),
			'postponed' => __( 'Postponed', 'leagueflow' ),
			'cancelled' => __( 'Cancelled', 'leagueflow' ),
		);
	}

	/** Human-readable result type; a forfeit is not a played score. */
	public static function outcome_label( $outcome ) {
		$labels = array(
			'forfeit_home'   => __( 'Home team forfeited', 'leagueflow' ),
			'forfeit_away'   => __( 'Away team forfeited', 'leagueflow' ),
			'double_forfeit' => __( 'Double forfeit', 'leagueflow' ),
		);
		return is_string( $outcome ) && isset( $labels[ $outcome ] ) ? $labels[ $outcome ] : '';
	}

	/**
	 * Whether a score is a non-negative whole number representable by PHP.
	 *
	 * A blank score is not 0. Reject decimals, negative values, booleans and
	 * malformed imported metadata before a result can affect a table or bracket.
	 * Leading zeroes remain valid, as they are in the admin score fields.
	 *
	 * @param mixed $score Score value.
	 * @return bool
	 */
	public static function is_valid_score( $score ) {
		if ( ! is_int( $score ) && ! is_string( $score ) ) {
			return false;
		}

		$value = (string) $score;
		if ( ! preg_match( '/^[0-9]+$/D', $value ) ) {
			return false;
		}

		$value = ltrim( $value, '0' );
		$max   = (string) PHP_INT_MAX;

		return strlen( $value ) < strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) <= 0 );
	}

	/**
	 * Remember the stored status right before it is overwritten.
	 *
	 * Runs on the pre-update filter so both the old and new values are known
	 * once the write completes. Returns the check value unchanged so it never
	 * short-circuits the update.
	 *
	 * @param mixed  $check Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value New value.
	 * @return mixed
	 */
	public function capture_previous_status( $check, $object_id, $meta_key, $meta_value ) {
		unset( $meta_value );

		if ( self::META_KEY === $meta_key && 'lf_match' === get_post_type( $object_id ) ) {
			self::$previous[ (int) $object_id ] = (string) get_post_meta( (int) $object_id, self::META_KEY, true );
		}

		return $check;
	}

	/**
	 * Fire the transition action when a match status changes.
	 *
	 * @param int    $meta_id Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_status_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );

		if ( self::META_KEY !== $meta_key || 'lf_match' !== get_post_type( $object_id ) ) {
			return;
		}

		$object_id = (int) $object_id;
		$old       = isset( self::$previous[ $object_id ] ) ? self::$previous[ $object_id ] : '';
		unset( self::$previous[ $object_id ] );

		$new = sanitize_key( (string) $meta_value );

		if ( $old === $new ) {
			return;
		}

		$this->fire_transition( $object_id, $new, sanitize_key( $old ) );
	}

	/**
	 * Fire the transition action when a match status is first set.
	 *
	 * @param int    $meta_id Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_status_added( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );

		if ( self::META_KEY !== $meta_key || 'lf_match' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->fire_transition( (int) $object_id, sanitize_key( (string) $meta_value ), '' );
	}

	/**
	 * Record the change timestamp and broadcast the transition.
	 *
	 * @param int    $match_id Match ID.
	 * @param string $new New status.
	 * @param string $old Previous status.
	 * @return void
	 */
	protected function fire_transition( $match_id, $new, $old ) {
		update_post_meta( $match_id, 'lf_status_changed_at', current_time( 'mysql' ) );
		if ( array_key_exists( $match_id, self::$deferred ) ) {
			$first = self::$deferred[ $match_id ];
			self::$deferred[ $match_id ] = array( 'old' => is_array( $first ) ? $first['old'] : $old, 'new' => $new );
			return;
		}

		/**
		 * Fires when a match moves from one lifecycle status to another.
		 *
		 * @param int    $match_id Match post ID.
		 * @param string $new New status slug (e.g. finished).
		 * @param string $old Previous status slug, or '' when first set.
		 */
		do_action( 'leagueflow_match_status_changed', $match_id, $new, $old );
	}
}
