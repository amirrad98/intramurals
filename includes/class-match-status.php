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
