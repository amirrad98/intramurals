<?php
/**
 * Result entry and publication: finish now, hold, or publish at a set time.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * One locked, transactional result writer shared by the Results screen,
 * the Referee desk, scheduled publication and the WP-CLI backfill.
 *
 * A result is public (counted in standings, shown on the site) once the match
 * status is Finished. A held result keeps its scores private: the match keeps
 * its status and `_lf_result_keep_status`, and public views hide the scores.
 * A scheduled result is a held result with a publication time; WP-Cron
 * finishes it at that time if the saved result has not changed since.
 */
class Result_Service {

	const PUBLISH_AT_META = '_lf_result_publish_at';
	const PUBLISH_BY_META = '_lf_result_publish_by';
	const PUBLISH_FOR_META = '_lf_result_publish_for';
	const CONFLICT_META   = '_lf_result_publish_conflict';
	const LOG_META        = '_lf_result_log';
	const CRON_HOOK       = 'leagueflow_publish_due_results';
	const CRON_SINGLE     = 'leagueflow_publish_result';

	/** Result modes accepted by save(). */
	const MODES = array( 'finish', 'hold', 'schedule' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'ensure_sweep' ) );
		add_action( self::CRON_HOOK, array( $this, 'publish_due' ) );
		add_action( self::CRON_SINGLE, array( $this, 'publish_one' ) );
		// Any other route that finishes or reopens a match ends its publication schedule.
		add_action( 'leagueflow_match_status_changed', array( $this, 'clear_schedule_on_status_change' ), 10, 3 );
	}

	/** Fifteen-minute fallback sweep for scheduled results. */
	public function cron_schedules( $schedules ) {
		$schedules['leagueflow_quarter_hour'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes (LeagueFlow results)', 'leagueflow' ) );
		return $schedules;
	}

	/** Keep the fallback sweep scheduled. */
	public function ensure_sweep() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'leagueflow_quarter_hour', self::CRON_HOOK );
		}
	}

	/** Valid result types for the Results screen. */
	public static function outcomes() {
		return array(
			''               => __( 'Played', 'leagueflow' ),
			'forfeit_home'   => __( 'Home team forfeited', 'leagueflow' ),
			'forfeit_away'   => __( 'Away team forfeited', 'leagueflow' ),
			'double_forfeit' => __( 'Double forfeit', 'leagueflow' ),
		);
	}

	/** Whether an outcome is a forfeit. */
	public static function is_forfeit( $outcome ) {
		return in_array( $outcome, array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true );
	}

	/**
	 * Whether a match result may be shown publicly.
	 *
	 * Finished results are public. Live scores are public unless staff saved
	 * them as a held (unpublished) result. Everything else stays private.
	 *
	 * @param int    $match_id Match ID.
	 * @param string $status   Stored lf_status.
	 * @return bool
	 */
	public static function result_is_public( $match_id, $status ) {
		if ( 'finished' === $status ) {
			return true;
		}
		return 'live' === $status && '1' !== (string) get_post_meta( $match_id, '_lf_result_keep_status', true );
	}

	/**
	 * Where a match is in the result workflow.
	 *
	 * needs_score: the game time has passed and nothing is saved.
	 * held: a result is saved but not published.
	 * scheduled: a held result with a publication time.
	 * conflict: a scheduled publication stopped because the result changed.
	 * published: Finished.
	 * upcoming / postponed / cancelled: not yet actionable.
	 *
	 * @param int      $match_id Match ID.
	 * @param int|null $now      Timestamp.
	 * @return string
	 */
	public static function stage( $match_id, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$status  = (string) get_post_meta( $match_id, 'lf_status', true );
		$status  = '' === $status ? 'scheduled' : $status;
		if ( 'finished' === $status ) {
			return 'published';
		}
		if ( in_array( $status, array( 'postponed', 'cancelled' ), true ) ) {
			return $status;
		}
		if ( get_post_meta( $match_id, self::CONFLICT_META, true ) ) {
			return 'conflict';
		}
		if ( get_post_meta( $match_id, self::PUBLISH_AT_META, true ) ) {
			return 'scheduled';
		}
		if ( self::has_result( $match_id ) ) {
			return 'held';
		}
		$start = self::start_timestamp( $match_id );
		return null !== $start && $start <= $now ? 'needs_score' : 'upcoming';
	}

	/** Whether any score or forfeit is saved. */
	public static function has_result( $match_id ) {
		return '' !== (string) get_post_meta( $match_id, 'lf_home_score', true )
			|| '' !== (string) get_post_meta( $match_id, 'lf_away_score', true )
			|| self::is_forfeit( (string) get_post_meta( $match_id, 'lf_outcome', true ) );
	}

	/** Match start as a timestamp (saved values are site wall-clock times). */
	public static function start_timestamp( $match_id ) {
		$raw = (string) get_post_meta( $match_id, 'lf_match_datetime', true );
		if ( '' === $raw ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $raw, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $error ) {
			return null;
		}
	}

	/**
	 * Validate a result before any write. Returns error messages.
	 *
	 * @param string $home    Home score ('' for blank).
	 * @param string $away    Away score ('' for blank).
	 * @param string $outcome Outcome key.
	 * @return string[]
	 */
	public static function validate_scores( $home, $away, $outcome ) {
		$errors = array();
		if ( ! array_key_exists( $outcome, self::outcomes() ) ) {
			$errors[] = __( 'Choose a valid result type.', 'leagueflow' );
			return $errors;
		}
		foreach ( array( __( 'Home score', 'leagueflow' ) => $home, __( 'Away score', 'leagueflow' ) => $away ) as $label => $score ) {
			if ( '' !== $score && ! Match_Status::is_valid_score( $score ) ) {
				/* translators: %s: Home score or Away score */
				$errors[] = sprintf( __( '%s must be a whole number of 0 or more.', 'leagueflow' ), $label );
			}
		}
		if ( ! $errors && '' === $outcome && ( '' === $home || '' === $away ) ) {
			$errors[] = __( 'Enter both scores (0 for a scoreless side), or choose a forfeit.', 'leagueflow' );
		}
		return $errors;
	}

	/**
	 * Whether the match context allows a final result: two different
	 * accessible teams of the match's sport and level, and season and
	 * competition terms owned by that sport. Mirrors the bulk Finished rules.
	 *
	 * @param int $match_id Match ID.
	 * @return bool
	 */
	public static function can_finish_context( $match_id ) {
		$home_id = (int) get_post_meta( $match_id, 'lf_home_team_id', true );
		$away_id = (int) get_post_meta( $match_id, 'lf_away_team_id', true );
		$editable = array( 'publish', 'private', 'draft', 'pending', 'future' );
		if ( ! $home_id || ! $away_id || $home_id === $away_id || 'lf_team' !== get_post_type( $home_id ) || 'lf_team' !== get_post_type( $away_id )
			|| ! in_array( get_post_status( $home_id ), $editable, true ) || ! in_array( get_post_status( $away_id ), $editable, true )
			|| ! can_view_league_post( $home_id ) || ! can_view_league_post( $away_id ) ) {
			return false;
		}
		$home_sport = get_post_primary_term_id( $home_id, 'lf_sport' );
		$home_level = get_post_primary_term_id( $home_id, 'lf_league_level' );
		$away_level = get_post_primary_term_id( $away_id, 'lf_league_level' );
		if ( ! $home_sport || get_post_primary_term_id( $away_id, 'lf_sport' ) !== $home_sport || get_post_primary_term_id( $match_id, 'lf_sport' ) !== $home_sport
			|| ( $home_level && $away_level && $home_level !== $away_level ) ) {
			return false;
		}
		$sport_term = get_term( $home_sport, 'lf_sport' );
		if ( ! $sport_term instanceof \WP_Term ) {
			return false;
		}
		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $match_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				return false;
			}
			foreach ( $terms as $term_id ) {
				$owner = (string) get_term_meta( $term_id, 'lf_sport_slug', true );
				if ( '' !== $owner && $owner !== $sport_term->slug ) {
					return false;
				}
			}
		}
		return true;
	}

	/** Stale-form token: the result fields, status, schedule and publication. */
	public static function state_token( $match_id ) {
		$state = array( Admin::match_form_state( $match_id ), get_post_status( $match_id ) );
		foreach ( array( self::PUBLISH_AT_META, self::PUBLISH_FOR_META, self::CONFLICT_META ) as $key ) {
			$state[ $key ] = get_post_meta( $match_id, $key, true );
		}
		return hash_hmac( 'sha256', wp_json_encode( $state ), wp_salt( 'nonce' ) );
	}

	/** Fingerprint of the saved result a schedule was made for. */
	protected static function result_fingerprint( $match_id ) {
		return implode( '|', array( (string) get_post_meta( $match_id, 'lf_home_score', true ), (string) get_post_meta( $match_id, 'lf_away_score', true ), (string) get_post_meta( $match_id, 'lf_outcome', true ) ) );
	}

	/**
	 * Save a result.
	 *
	 * @param int                  $match_id Match ID.
	 * @param array<string, mixed> $args {
	 *     @type string $home       Home score, '' for blank.
	 *     @type string $away       Away score, '' for blank.
	 *     @type string $outcome    '' (played) or a forfeit key.
	 *     @type string $mode       finish | hold | schedule.
	 *     @type int    $publish_at Timestamp for schedule mode.
	 *     @type string $state      Optional state_token() from the form.
	 *     @type bool   $publish_post Publish an unpublished fixture when finishing.
	 *     @type string $source     Audit label (results-screen, cron, cli).
	 * }
	 * @return true|\WP_Error
	 */
	public function save( $match_id, $args ) {
		global $wpdb;
		$match_id = absint( $match_id );
		$home     = trim( (string) ( $args['home'] ?? '' ) );
		$away     = trim( (string) ( $args['away'] ?? '' ) );
		$outcome  = (string) ( $args['outcome'] ?? '' );
		$mode     = (string) ( $args['mode'] ?? 'finish' );
		$at       = (int) ( $args['publish_at'] ?? 0 );
		$source   = sanitize_key( (string) ( $args['source'] ?? 'results-screen' ) );

		if ( 'lf_match' !== get_post_type( $match_id ) || in_array( get_post_status( $match_id ), array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'not_found', __( 'This match no longer exists.', 'leagueflow' ) );
		}
		// Staff edit the match; referees publish or save results from the Referee desk.
		$editor = current_user_can( 'edit_post', $match_id );
		if ( ! $editor && ! current_user_can( Referee::SCORE_CAP ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot edit this match.', 'leagueflow' ) );
		}
		$denied = $editor ? null : Referee::score_error( $match_id, $mode );
		if ( $denied ) {
			return $denied;
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Choose whether to publish, hold or schedule the result.', 'leagueflow' ) );
		}
		$errors = self::validate_scores( $home, $away, $outcome );
		if ( $errors ) {
			return new \WP_Error( 'invalid_score', implode( ' ', $errors ) );
		}
		if ( 'schedule' === $mode && $at <= time() ) {
			return new \WP_Error( 'invalid_time', __( 'Choose a publication time in the future, or publish now.', 'leagueflow' ) );
		}

		$lock = db_lock_name( 'result', (string) $match_id );
		if ( ! acquire_db_lock( $lock, 1 ) ) {
			return new \WP_Error( 'busy', __( 'Another person is updating this match. Reload and try again.', 'leagueflow' ) );
		}
		$transaction = false;
		$saved       = false;
		$before      = array();
		try {
			clean_post_cache( $match_id );
			if ( isset( $args['state'] ) && ( ! is_string( $args['state'] ) || ! hash_equals( self::state_token( $match_id ), $args['state'] ) ) ) {
				return new \WP_Error( 'stale', __( 'This match changed after the page loaded. Reload to see the latest result before saving.', 'leagueflow' ) );
			}
			$denied = $editor ? null : Referee::score_error( $match_id, $mode );
			if ( $denied ) {
				return $denied;
			}
			$status = (string) get_post_meta( $match_id, 'lf_status', true );
			$status = isset( Match_Status::statuses()[ $status ] ) ? $status : 'scheduled';
			if ( in_array( $status, array( 'postponed', 'cancelled' ), true ) ) {
				return new \WP_Error( 'not_played', __( 'This match is postponed or cancelled. Change its status in the match editor first.', 'leagueflow' ) );
			}
			if ( 'hold' !== $mode && ! self::can_finish_context( $match_id ) ) {
				return new \WP_Error( 'context', __( 'A final result needs two different teams in the same sport, league level and season. Fix the match in its editor first.', 'leagueflow' ) );
			}

			$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->postmeta ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $table || 'innodb' !== strtolower( (string) $table->Engine ) || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'save_failed', __( 'The result could not be safely saved. Ask staff to review the database configuration.', 'leagueflow' ) );
			}
			$transaction = true;
			Match_Status::defer_transitions( $match_id );
			$before = array(
				'status'  => $status,
				'home'    => (string) get_post_meta( $match_id, 'lf_home_score', true ),
				'away'    => (string) get_post_meta( $match_id, 'lf_away_score', true ),
				'outcome' => (string) get_post_meta( $match_id, 'lf_outcome', true ),
			);
			// Holding a finished result takes it off the site until it is published again.
			$new_status = 'finish' === $mode ? 'finished' : ( 'finished' === $status ? 'scheduled' : $status );
			$meta = array(
				'lf_home_score'          => '' === $home ? null : (string) (int) $home,
				'lf_away_score'          => '' === $away ? null : (string) (int) $away,
				'lf_outcome'             => '' === $outcome ? null : $outcome,
				'_lf_result_keep_status' => 'finish' === $mode ? null : '1',
				self::CONFLICT_META      => null,
				self::PUBLISH_AT_META    => 'schedule' === $mode ? (string) $at : null,
				self::PUBLISH_BY_META    => 'schedule' === $mode ? (string) get_current_user_id() : null,
				self::PUBLISH_FOR_META   => null,
			);
			foreach ( $meta as $key => $value ) {
				if ( null === $value ) {
					delete_post_meta( $match_id, $key );
				} else {
					update_post_meta( $match_id, $key, $value );
				}
			}
			if ( 'schedule' === $mode ) {
				update_post_meta( $match_id, self::PUBLISH_FOR_META, self::result_fingerprint( $match_id ) );
			}
			foreach ( $meta as $key => $value ) {
				if ( self::PUBLISH_FOR_META !== $key && (string) $value !== (string) get_post_meta( $match_id, $key, true ) ) {
					return new \WP_Error( 'save_failed', __( 'The complete result could not be saved. Nothing was changed; please try again.', 'leagueflow' ) );
				}
			}
			// Result fields precede the lifecycle transition, so observers see complete data.
			if ( $new_status !== $status ) {
				update_post_meta( $match_id, 'lf_status', $new_status );
				if ( $new_status !== get_post_meta( $match_id, 'lf_status', true ) ) {
					return new \WP_Error( 'save_failed', __( 'The match status could not be saved. Nothing was changed; please try again.', 'leagueflow' ) );
				}
			}
			// A referee's published result carries the evidence knockout advancement checks.
			if ( ! $editor && 'finish' === $mode && ! add_post_meta(
				$match_id,
				Referee::AUDIT_META,
				array(
					'user_id'          => get_current_user_id(),
					'submitted_at_utc' => gmdate( 'Y-m-d H:i:s' ),
					'home_score'       => '' === $home ? '' : (string) (int) $home,
					'away_score'       => '' === $away ? '' : (string) (int) $away,
					'previous'         => $before,
					'result_state'     => Referee::state_token( $match_id ),
				)
			) ) {
				return new \WP_Error( 'save_failed', __( 'The result record could not be saved. Nothing was changed; please try again.', 'leagueflow' ) );
			}
			$logged = add_post_meta(
				$match_id,
				self::LOG_META,
				array(
					'user_id'    => get_current_user_id(),
					'at_utc'     => gmdate( 'Y-m-d H:i:s' ),
					'mode'       => $mode,
					'source'     => $source,
					'home'       => $home,
					'away'       => $away,
					'outcome'    => $outcome,
					'publish_at' => 'schedule' === $mode ? $at : null,
					'previous'   => $before,
				)
			);
			if ( ! $logged || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'save_failed', __( 'The result could not be committed. Nothing was changed; please try again.', 'leagueflow' ) );
			}
			$transaction = false;
			$saved       = true;
		} finally {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $match_id );
			}
			release_db_lock( $lock );
			Match_Status::flush_transitions( $match_id, $saved );
		}

		wp_clear_scheduled_hook( self::CRON_SINGLE, array( $match_id ) );
		if ( 'schedule' === $mode ) {
			wp_schedule_single_event( $at, self::CRON_SINGLE, array( $match_id ) );
		}
		if ( 'finish' === $mode && ! empty( $args['publish_post'] ) && 'publish' !== get_post_status( $match_id ) && current_user_can( 'publish_post', $match_id ) ) {
			wp_update_post( array( 'ID' => $match_id, 'post_status' => 'publish' ) );
		}
		do_action( 'leagueflow_match_result_saved', $match_id );
		if ( ! $editor && 'finish' === $mode ) {
			do_action( 'leagueflow_referee_result_submitted', $match_id, get_current_user_id() );
		}
		return true;
	}

	/** Publish every scheduled result that is due. */
	public function publish_due() {
		$ids = get_posts(
			array(
				'post_type'      => 'lf_match',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => self::PUBLISH_AT_META, 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			$this->publish_one( (int) $id );
		}
	}

	/**
	 * Finish one scheduled result as the person who scheduled it. A result
	 * edited after scheduling is not published; it is flagged for review.
	 *
	 * @param int $match_id Match ID.
	 * @return true|\WP_Error|null Null when nothing was due.
	 */
	public function publish_one( $match_id ) {
		$match_id = absint( $match_id );
		$at       = (int) get_post_meta( $match_id, self::PUBLISH_AT_META, true );
		if ( ! $at || $at > time() ) {
			return null;
		}
		if ( self::result_fingerprint( $match_id ) !== (string) get_post_meta( $match_id, self::PUBLISH_FOR_META, true ) ) {
			update_post_meta( $match_id, self::CONFLICT_META, gmdate( 'Y-m-d H:i:s' ) );
			delete_post_meta( $match_id, self::PUBLISH_AT_META );
			return new \WP_Error( 'changed', __( 'The result changed after it was scheduled.', 'leagueflow' ) );
		}
		$previous_user = get_current_user_id();
		wp_set_current_user( (int) get_post_meta( $match_id, self::PUBLISH_BY_META, true ) );
		try {
			$result = $this->save(
				$match_id,
				array(
					'home'    => (string) get_post_meta( $match_id, 'lf_home_score', true ),
					'away'    => (string) get_post_meta( $match_id, 'lf_away_score', true ),
					'outcome' => (string) get_post_meta( $match_id, 'lf_outcome', true ),
					'mode'    => 'finish',
					'source'  => 'scheduled',
				)
			);
		} finally {
			wp_set_current_user( $previous_user );
		}
		if ( is_wp_error( $result ) ) {
			// Keep it visible on the Results screen instead of retrying forever.
			update_post_meta( $match_id, self::CONFLICT_META, gmdate( 'Y-m-d H:i:s' ) );
			delete_post_meta( $match_id, self::PUBLISH_AT_META );
		}
		return $result;
	}

	/** Finishing or reopening a match elsewhere ends its publication schedule. */
	public function clear_schedule_on_status_change( $match_id, $new, $old ) {
		unset( $old );
		if ( 'finished' === $new || in_array( $new, array( 'postponed', 'cancelled' ), true ) ) {
			foreach ( array( self::PUBLISH_AT_META, self::PUBLISH_BY_META, self::PUBLISH_FOR_META, self::CONFLICT_META ) as $key ) {
				delete_post_meta( $match_id, $key );
			}
			wp_clear_scheduled_hook( self::CRON_SINGLE, array( (int) $match_id ) );
		}
	}
}
