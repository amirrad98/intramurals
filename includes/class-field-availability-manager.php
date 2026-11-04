<?php
/**
 * Field availability and match scheduling service.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Stores field availability windows and assigns matches into open slots.
 */
class Field_Availability_Manager {

	/**
	 * Option name for saved availability windows.
	 *
	 * @var string
	 */
	const OPTION = 'leagueflow_field_availabilities';

	/**
	 * Match meta key linking an auto-scheduled match to an availability window.
	 *
	 * @var string
	 */
	const META_AVAILABILITY_ID = 'lf_field_availability_id';

	/**
	 * Match meta key describing whether scheduling was manual or automatic.
	 *
	 * @var string
	 */
	const META_SCHEDULE_SOURCE = 'lf_schedule_source';

	/**
	 * Match meta key storing when the assistant last updated a fixture.
	 *
	 * @var string
	 */
	const META_SCHEDULED_AT = 'lf_schedule_generated_at';

	/**
	 * Default scheduling range length.
	 *
	 * @var int
	 */
	const DEFAULT_RANGE_DAYS = 90;

	/**
	 * Maximum number of days to scan at once.
	 *
	 * @var int
	 */
	const MAX_RANGE_DAYS = 366;

	/**
	 * Active scheduling constraints for the current run.
	 *
	 * @var array<string, int>
	 */
	protected $scheduling_constraints = array(
		'min_rest_days'              => 0,
		'min_days_between_rematch'   => 0,
		'max_games_per_day_per_team' => 0,
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Reserved for future REST or cron hooks.
	}

	/**
	 * Saved match duration, with a legacy availability fallback.
	 *
	 * Persisting duration on the fixture means later edits to an availability
	 * rule do not resize an already scheduled match. Older fixtures can still
	 * use the rule they were scheduled from, or the historical one-hour default.
	 *
	 * @param int $match_id Match post ID.
	 * @return int Duration in minutes.
	 */
	public static function get_match_duration_minutes( $match_id ) {
		$stored = get_post_meta( $match_id, 'lf_duration_minutes', true );
		if ( Match_Status::is_valid_score( $stored ) && (int) $stored > 0 && (int) $stored <= (int) ( PHP_INT_MAX / MINUTE_IN_SECONDS ) ) {
			return (int) $stored;
		}
		$id = (string) get_post_meta( $match_id, self::META_AVAILABILITY_ID, true );
		if ( '' !== $id ) {
			$availability = ( new self() )->get_availability( $id );
			if ( $availability ) {
				return max( 1, (int) $availability['slot_minutes'] );
			}
		}
		return 60;
	}

	/**
	 * Load scheduling constraints from plugin settings for the current run.
	 *
	 * All constraints default to off (0) so a site that never configures them
	 * keeps the original first-fit behavior.
	 *
	 * @return void
	 */
	protected function load_scheduling_constraints() {
		$this->scheduling_constraints = array(
			'min_rest_days'              => max( 0, (int) get_setting( 'min_rest_days', 0 ) ),
			'min_days_between_rematch'   => max( 0, (int) get_setting( 'min_days_between_rematch', 0 ) ),
			'max_games_per_day_per_team' => max( 0, (int) get_setting( 'max_games_per_day_per_team', 0 ) ),
		);
	}

	/**
	 * Build a stable key for the pairing of two teams.
	 *
	 * @param array<int, int> $team_ids Team IDs.
	 * @return string
	 */
	protected function pair_key( $team_ids ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $team_ids ) ) );

		if ( count( $ids ) < 2 ) {
			return '';
		}

		sort( $ids );

		return $ids[0] . '-' . $ids[1];
	}

	/**
	 * Get weekday labels keyed by PHP date('w') indexes.
	 *
	 * @return array<int, string>
	 */
	public function get_weekday_options() {
		return array(
			0 => __( 'Sunday', 'leagueflow' ),
			1 => __( 'Monday', 'leagueflow' ),
			2 => __( 'Tuesday', 'leagueflow' ),
			3 => __( 'Wednesday', 'leagueflow' ),
			4 => __( 'Thursday', 'leagueflow' ),
			5 => __( 'Friday', 'leagueflow' ),
			6 => __( 'Saturday', 'leagueflow' ),
		);
	}

	/**
	 * Get supported auto-scheduling update modes.
	 *
	 * @return array<string, string>
	 */
	public function get_update_modes() {
		return array(
			'both'     => __( 'Set date/time and venue', 'leagueflow' ),
			'datetime' => __( 'Set date/time only', 'leagueflow' ),
			'venue'    => __( 'Set venue only', 'leagueflow' ),
		);
	}

	/**
	 * Read saved availability windows.
	 *
	 * @param array<string, mixed> $args Optional filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_availabilities( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'active_only'     => false,
				'sport_slug'      => '',
				'availability_id' => '',
				'date'            => '',
			)
		);

		$sport_slug      = sanitize_key( (string) $args['sport_slug'] );
		$availability_id = sanitize_key( (string) $args['availability_id'] );
		$date            = $this->sanitize_date( (string) $args['date'] );
		$stored          = get_option( self::OPTION, array() );
		$stored          = is_array( $stored ) ? $stored : array();
		$items           = array();

		foreach ( $stored as $raw ) {
			$availability = $this->normalize_availability( is_array( $raw ) ? $raw : array() );

			if ( empty( $availability['id'] ) ) {
				continue;
			}

			if ( ! empty( $args['active_only'] ) && empty( $availability['active'] ) ) {
				continue;
			}

			if ( $availability_id && $availability_id !== $availability['id'] ) {
				continue;
			}

			if ( $sport_slug && $availability['sport_slug'] && $sport_slug !== $availability['sport_slug'] ) {
				continue;
			}

			if ( $date && ! $this->availability_matches_date( $availability, $date ) ) {
				continue;
			}

			$items[] = $availability;
		}

		usort(
			$items,
			static function( $a, $b ) {
				$a_key = (string) ( $a['sport_slug'] . '|' . $a['venue'] . '|' . $a['date'] . '|' . $a['weekday'] . '|' . $a['start_time'] );
				$b_key = (string) ( $b['sport_slug'] . '|' . $b['venue'] . '|' . $b['date'] . '|' . $b['weekday'] . '|' . $b['start_time'] );

				return strnatcasecmp( $a_key, $b_key );
			}
		);

		return $items;
	}

	/**
	 * Get one availability window.
	 *
	 * @param string $availability_id Availability ID.
	 * @return array<string, mixed>|null
	 */
	public function get_availability( $availability_id ) {
		$items = $this->get_availabilities(
			array(
				'availability_id' => $availability_id,
			)
		);

		return empty( $items ) ? null : $items[0];
	}

	/**
	 * Build select options for availability windows.
	 *
	 * @param string $sport_slug Optional sport slug.
	 * @return array<string, string>
	 */
	public function get_availability_options( $sport_slug = '' ) {
		$options = array();

		foreach ( $this->get_availabilities( array( 'sport_slug' => $sport_slug ) ) as $availability ) {
			$options[ $availability['id'] ] = $this->format_availability_label( $availability );
		}

		return $options;
	}

	/**
	 * Format an availability for display in select controls.
	 *
	 * @param array<string, mixed> $availability Availability.
	 * @return string
	 */
	public function format_availability_label( $availability ) {
		$weekday_options = $this->get_weekday_options();
		$scope           = empty( $availability['date'] )
			? ( $weekday_options[ (int) $availability['weekday'] ] ?? __( 'Weekly', 'leagueflow' ) )
			: $availability['date'];

		return sprintf(
			/* translators: 1: availability name 2: venue 3: date or weekday 4: start time 5: end time */
			__( '%1$s - %2$s, %3$s %4$s-%5$s', 'leagueflow' ),
			(string) $availability['name'],
			(string) $availability['venue'],
			$scope,
			(string) $availability['start_time'],
			(string) $availability['end_time']
		);
	}

	/**
	 * Save an availability window.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_availability( $data ) {
		if ( ! current_user_can( 'leagueflow_manage_field_availability' ) ) {
			return new \WP_Error( 'leagueflow_availability_forbidden', __( 'Field configuration requires scheduling administration permission.', 'leagueflow' ), array( 'status' => 403 ) );
		}

		$availability = $this->sanitize_availability( $data );

		if ( is_wp_error( $availability ) ) {
			return $availability;
		}

		$items   = $this->get_availabilities();
		$updated = false;

		foreach ( $items as $index => $item ) {
			if ( $item['id'] === $availability['id'] ) {
				$items[ $index ] = $availability;
				$updated         = true;
				break;
			}
		}

		if ( ! $updated ) {
			$items[] = $availability;
		}

		update_option( self::OPTION, array_values( $items ), false );

		return $availability;
	}

	/**
	 * Delete an availability window.
	 *
	 * @param string $availability_id Availability ID.
	 * @return bool
	 */
	public function delete_availability( $availability_id ) {
		if ( ! current_user_can( 'leagueflow_manage_field_availability' ) ) {
			return false;
		}

		$availability_id = sanitize_key( (string) $availability_id );
		$items           = $this->get_availabilities();
		$remaining       = array();
		$deleted         = false;

		foreach ( $items as $item ) {
			if ( $item['id'] === $availability_id ) {
				$deleted = true;
				continue;
			}

			$remaining[] = $item;
		}

		if ( $deleted ) {
			update_option( self::OPTION, array_values( $remaining ), false );
		}

		return $deleted;
	}

	/**
	 * Get open slots for a date range.
	 *
	 * @param array<string, mixed> $args Slot filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_slots( $args = array() ) {
		$this->load_scheduling_constraints();
		$args      = $this->normalize_schedule_args( $args );
		if ( ! empty( $args['_invalid_scope'] ) ) { return array(); }
		$slots     = $this->build_slots( $args );
		$conflicts = $this->get_conflicts( $args['date_from'], $args['date_to'], array() );
		$open      = array();

		foreach ( $slots as $slot ) {
			if ( $this->is_slot_open( $slot, array(), $conflicts, array() ) ) {
				$open[] = $slot;
			}
		}

		return $open;
	}

	/**
	 * Schedule a single match with the assistant.
	 *
	 * @param int                  $match_id Match ID.
	 * @param array<string, mixed> $args Scheduling options.
	 * @return array<string, mixed>
	 */
	public function schedule_match( $match_id, $args = array() ) {
		$args              = (array) $args;
		$args['match_ids'] = array( $match_id );

		return $this->schedule_matches( $args );
	}

	/**
	 * Auto-schedule matching fixtures into available field windows.
	 *
	 * @param array<string, mixed> $args Scheduling options.
	 * @return array<string, mixed>
	 */
	public function schedule_matches( $args = array() ) {
		if ( ! current_user_can( 'leagueflow_manage_schedule' ) ) {
			return array( 'scheduled' => 0, 'skipped' => 0, 'failed' => 1, 'updated' => array(), 'messages' => array( __( 'You do not have permission to schedule these matches.', 'leagueflow' ) ) );
		}
		// Sports share venues. One site-wide scheduling lock prevents two
		// simultaneous runs from both reserving the same apparently free slot.
		$lock = db_lock_name( 'schedule', 'site' );
		if ( ! acquire_db_lock( $lock, 3 ) ) {
			return array( 'scheduled' => 0, 'skipped' => 0, 'failed' => 1, 'updated' => array(), 'messages' => array( __( 'Another scheduling run is in progress, or its lock is unavailable. No matches were changed; try again.', 'leagueflow' ) ) );
		}
		try {
			return $this->schedule_matches_locked( $args );
		} finally {
			release_db_lock( $lock );
		}
	}

	/** Schedule only while holding the site-wide reservation lock. */
	protected function schedule_matches_locked( $args ) {
		$args = $this->normalize_schedule_args( $args );
		$results = array( 'scheduled' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => array(), 'messages' => array() );
		if ( ! empty( $args['_invalid_scope'] ) ) {
			$results['failed'] = 1;
			$results['messages'][] = __( 'Scheduling IDs must be valid whole-number identifiers. No matches were changed.', 'leagueflow' );
			return $results;
		}
		if ( ! current_user_can( 'leagueflow_manage_schedule' ) || ( $args['overwrite'] && ! current_user_can( 'leagueflow_overwrite_schedule' ) ) ) {
			$results['failed'] = 1;
			$results['messages'][] = __( 'You do not have permission to schedule these matches.', 'leagueflow' );
			return $results;
		}
		foreach ( $args['match_ids'] as $match_id ) {
			if ( ! $this->can_schedule_target( $match_id, $args ) ) {
				$results['failed'] = 1;
				$results['messages'][] = __( 'Every requested scheduling target must be an editable match.', 'leagueflow' );
				return $results;
			}
		}
		$matches = $this->get_scheduleable_matches( $args );
		foreach ( $matches as $match ) {
			if ( ! $this->can_schedule_target( $match->ID, $args ) ) {
				$results['failed'] = count( $matches );
				$results['messages'][] = __( 'The scheduling scope contains a match you cannot edit. No matches were changed.', 'leagueflow' );
				return $results;
			}
		}
		$this->load_scheduling_constraints();
		$slots = $this->build_slots( $args );

		if ( empty( $matches ) ) {
			$results['messages'][] = __( 'No matches matched the selected scheduling scope.', 'leagueflow' );
			return $results;
		}

		if ( empty( $slots ) && 'venue' !== $args['mode'] ) {
			$results['failed']     = count( $matches );
			$results['messages'][] = __( 'No availability slots are defined for the selected date range.', 'leagueflow' );
			return $results;
		}

		// A selected fixture keeps occupying its old slot until it actually
		// moves. A skipped, locked or unschedulable selection must not make its
		// existing booking appear free to the other selected fixtures.
		$conflicts = $this->get_conflicts( $args['date_from'], $args['date_to'], array() );
		$reserved  = array();
		$moved     = array();

		// Place the hardest-to-fit fixtures first so scarce slots are not spent
		// on matches that have many alternatives (most-constrained-first).
		if ( ! empty( $slots ) && in_array( $args['mode'], array( 'both', 'datetime' ), true ) ) {
			$matches = $this->sort_matches_most_constrained_first( $matches, $args, $slots, $conflicts );
		}

		foreach ( $matches as $match ) {
			$current_conflicts = $this->without_match_conflicts( $conflicts, array_merge( $moved, array( (int) $match->ID ) ) );
			$decision = $this->schedule_one_match( (int) $match->ID, $args, $slots, $current_conflicts, $reserved );

			if ( ! empty( $decision['scheduled'] ) ) {
				$results['scheduled']++;
				$results['updated'][] = (int) $match->ID;
				$moved[] = (int) $match->ID;
				continue;
			}

			if ( ! empty( $decision['skipped'] ) ) {
				$results['skipped']++;
			} else {
				$results['failed']++;
			}

			if ( ! empty( $decision['message'] ) ) {
				$results['messages'][] = $decision['message'];
			}
		}

		$results['messages'] = array_values( array_unique( array_filter( $results['messages'] ) ) );

		return $results;
	}

	/**
	 * Schedule one match from the candidate slot list.
	 *
	 * @param int                            $match_id Match ID.
	 * @param array<string, mixed>           $args Scheduling args.
	 * @param array<int, array<string,mixed>> $slots Available slot candidates.
	 * @param array<string, mixed>           $conflicts Existing conflicts.
	 * @param array<string, mixed>           $reserved Already reserved slots.
	 * @return array<string, mixed>
	 */
	protected function schedule_one_match( $match_id, $args, $slots, $conflicts, &$reserved ) {
		// Never wait for an editor's result lock while holding the site-wide
		// scheduler lock. The editor may itself be requesting auto scheduling.
		$lock = db_lock_name( 'result', (string) $match_id );
		if ( ! acquire_db_lock( $lock, 0 ) ) {
			return array( 'failed' => true, 'message' => __( 'A selected match is being updated. Its existing schedule was preserved; retry after the update finishes.', 'leagueflow' ) );
		}
		try {
			clean_post_cache( $match_id );
			return $this->schedule_one_match_locked( $match_id, $args, $slots, $conflicts, $reserved );
		} finally {
			release_db_lock( $lock );
		}
	}

	/** Schedule a refreshed target while holding its shared result lock. */
	protected function schedule_one_match_locked( $match_id, $args, $slots, $conflicts, &$reserved ) {
		if ( ! $this->can_schedule_target( $match_id, $args ) ) {
			return array( 'failed' => true, 'message' => __( 'Match scheduling permission was denied.', 'leagueflow' ) );
		}
		if ( in_array( get_post_meta( $match_id, 'lf_status', true ), array( 'live', 'finished', 'cancelled' ), true ) ) {
			return array( 'failed' => true, 'message' => __( 'Live, finished and cancelled matches cannot be rescheduled by the assistant.', 'leagueflow' ) );
		}

		$datetime = (string) get_post_meta( $match_id, 'lf_match_datetime', true );
		$venue    = (string) get_post_meta( $match_id, 'lf_venue', true );
		$mode     = (string) $args['mode'];

		$needs_datetime = in_array( $mode, array( 'both', 'datetime' ), true ) && ( $args['overwrite'] || '' === $datetime );
		$needs_venue    = in_array( $mode, array( 'both', 'venue' ), true ) && ( $args['overwrite'] || '' === $venue );

		if ( ! $needs_datetime && ! $needs_venue ) {
			return array(
				'skipped' => true,
				'message' => __( 'A match already had the requested scheduling fields filled.', 'leagueflow' ),
			);
		}

		if ( 'venue' === $mode || ( 'both' === $mode && ! $needs_datetime && $needs_venue ) ) {
			$slot = $this->find_venue_slot_for_existing_datetime( $match_id, $args, $conflicts, $reserved );
		} else {
			$slot = $this->find_datetime_slot_for_match( $match_id, $args, $slots, $conflicts, $reserved );
		}

		if ( empty( $slot ) ) {
			return array(
				'failed'  => true,
				'message' => __( 'No open field slot was available for at least one match.', 'leagueflow' ),
			);
		}


		if ( ! $this->can_schedule_target( $match_id, $args ) ) {
			return array( 'failed' => true, 'message' => __( 'Match scheduling permission was denied.', 'leagueflow' ) );
		}
		if ( in_array( get_post_meta( $match_id, 'lf_status', true ), array( 'live', 'finished', 'cancelled' ), true ) ) {
			return array( 'failed' => true, 'message' => __( 'The match has started or closed since scheduling began. Its schedule was preserved.', 'leagueflow' ) );
		}
		$new_datetime = $needs_datetime ? (string) $slot['start_datetime'] : $datetime;
		$values = array(
			self::META_AVAILABILITY_ID => (string) $slot['availability_id'],
			self::META_SCHEDULE_SOURCE => 'auto',
			self::META_SCHEDULED_AT => current_time( 'mysql' ),
			'lf_duration_minutes' => max( 1, (int) round( ( $slot['end_ts'] - $slot['start_ts'] ) / MINUTE_IN_SECONDS ) ),
		);
		if ( $needs_datetime ) { $values['lf_match_datetime'] = $new_datetime; }
		if ( $needs_venue ) { $values['lf_venue'] = (string) $slot['venue']; }
		$saved = $this->persist_schedule( $match_id, $args, $values, $new_datetime );
		if ( is_wp_error( $saved ) ) { return array( 'failed' => true, 'message' => $saved->get_error_message() ); }
		if ( ! $saved ) {
			return array( 'failed' => true, 'message' => __( 'The complete schedule could not be saved. The match’s previous schedule was preserved; reload and try again.', 'leagueflow' ) );
		}
		// Only a fully persisted fixture may claim a slot or release its old one.
		$this->reserve_slot_for_match( $slot, $match_id, $reserved );

		return array(
			'scheduled' => true,
		);
	}

	/**
	 * Save all scheduling fields and the generated title as one checked update.
	 *
	 * @return bool|\WP_Error
	 */
	protected function persist_schedule( $match_id, $args, $values, $datetime ) {
		global $wpdb;
		foreach ( array( $wpdb->posts, $wpdb->postmeta ) as $table_name ) {
			$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $table || 'innodb' !== strtolower( (string) $table->Engine ) ) { return false; }
		}
		// Releasing the scheduler lock before an enclosing transaction commits
		// would let another run reserve the same invisible booking. Refuse that
		// call without committing or rolling back any of the caller's work.
		$savepoint = 'lf_schedule_probe_' . str_replace( '-', '', wp_generate_uuid4() );
		$previous_errors = $wpdb->suppress_errors( true );
		$probe = $wpdb->query( 'SAVEPOINT ' . $savepoint ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$nested = false !== $wpdb->query( 'RELEASE SAVEPOINT ' . $savepoint ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$probe_error = $wpdb->last_error;
		$wpdb->suppress_errors( $previous_errors );
		if ( false === $probe || ( ! $nested && ! preg_match( '/SAVEPOINT .+ does not exist/i', $probe_error ) ) ) { return false; }
		if ( $nested ) {
			return new \WP_Error( 'leagueflow_schedule_transaction', __( 'Finish the current database transaction by committing it or rolling it back, then run scheduling again. This match was not changed.', 'leagueflow' ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$committed = false;
		try {
			$expected = $values;
			foreach ( array( 'lf_match_datetime', 'lf_venue' ) as $key ) {
				if ( ! array_key_exists( $key, $expected ) ) { $expected[ $key ] = get_post_meta( $match_id, $key, true ); }
			}
			foreach ( $values as $key => $value ) {
				update_post_meta( $match_id, $key, wp_slash( $value ) );
				if ( (string) get_post_meta( $match_id, $key, true ) !== (string) $value ) { return false; }
			}
			if ( ! $this->can_schedule_target( $match_id, $args ) ) { return false; }
			if ( empty( $args['suppress_title_sync'] ) && ! $this->sync_match_title_from_meta( $match_id, $datetime ) ) { return false; }
			if ( ! $this->can_schedule_target( $match_id, $args ) || in_array( get_post_meta( $match_id, 'lf_status', true ), array( 'live', 'finished', 'cancelled' ), true ) ) { return false; }
			// Title and permission hooks may change a previously checked field.
			// Reserve only the date, venue and duration actually selected above.
			foreach ( $expected as $key => $value ) {
				if ( (string) get_post_meta( $match_id, $key, true ) !== (string) $value ) { return false; }
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { return false; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$committed = true;
			return true;
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $match_id );
			}
		}
	}

	/** Recheck global and object-specific authority at the mutation boundary. */
	protected function can_schedule_target( $match_id, $args ) {
		return current_user_can( 'leagueflow_manage_schedule' ) && 'lf_match' === get_post_type( $match_id )
			&& current_user_can( 'edit_post', $match_id )
			&& ( empty( $args['overwrite'] ) || current_user_can( 'leagueflow_overwrite_schedule' ) );
	}

	/** Remove only the fixture being moved and old slots already replaced. */
	protected function without_match_conflicts( $conflicts, $match_ids ) {
		$excluded = array_fill_keys( array_map( 'absint', $match_ids ), true );
		foreach ( $conflicts as $bucket => $groups ) {
			foreach ( $groups as $key => $entries ) {
				$conflicts[ $bucket ][ $key ] = array_values( array_filter( $entries, static function( $entry ) use ( $excluded ) {
					return ! isset( $excluded[ (int) $entry['post_id'] ] );
				} ) );
			}
		}
		return $conflicts;
	}

	/**
	 * Find a generated date/time slot for a match.
	 *
	 * @param int                            $match_id Match ID.
	 * @param array<string, mixed>           $args Scheduling args.
	 * @param array<int, array<string,mixed>> $slots Slots.
	 * @param array<string, mixed>           $conflicts Existing conflicts.
	 * @param array<string, mixed>           $reserved Reserved slots.
	 * @return array<string, mixed>|null
	 */
	protected function find_datetime_slot_for_match( $match_id, $args, $slots, $conflicts, $reserved ) {
		$team_ids       = $this->get_match_team_ids( $match_id );
		$existing_venue = (string) get_post_meta( $match_id, 'lf_venue', true );
		$match_sport    = $this->get_match_sport_slug( $match_id );
		$mode           = (string) $args['mode'];
		$pair_key       = $this->pair_key( $team_ids );

		$best         = null;
		$best_penalty = null;

		foreach ( $slots as $slot ) {
			if ( ! $this->slot_matches_sport( $slot, $match_sport ) ) {
				continue;
			}

			if ( ! empty( $existing_venue ) && ( 'datetime' === $mode || ( 'both' === $mode && empty( $args['overwrite'] ) ) ) && 0 !== strcasecmp( $existing_venue, (string) $slot['venue'] ) ) {
				continue;
			}

			if ( ! $this->is_slot_open( $slot, $team_ids, $conflicts, $reserved ) ) {
				continue;
			}

			// Keep the two legs of a rematch apart (hard constraint when set).
			if ( $this->violates_rematch_gap( $pair_key, (int) $slot['start_ts'], $conflicts, $reserved ) ) {
				continue;
			}

			$penalty = $this->slot_rest_penalty( $slot, $team_ids, $conflicts, $reserved );

			if ( null === $best_penalty || $penalty < $best_penalty ) {
				$best_penalty = $penalty;
				$best         = $slot;

				// Slots are pre-sorted ascending by start time, so the first
				// zero-penalty candidate is the earliest ideal slot. This keeps
				// the original first-fit outcome when no rest target is set.
				if ( 0 === $penalty ) {
					break;
				}
			}
		}

		return $best;
	}

	/**
	 * Score a candidate slot by how well it respects the preferred rest gap.
	 *
	 * Returns 0 when there is no rest target, no other games for the teams, or
	 * the nearest existing game is already far enough away. Otherwise the
	 * penalty grows as the slot crowds a team's other games.
	 *
	 * @param array<string, mixed> $slot Slot.
	 * @param array<int, int>      $team_ids Team IDs.
	 * @param array<string, mixed> $conflicts Existing conflicts.
	 * @param array<string, mixed> $reserved In-run reservations.
	 * @return int
	 */
	protected function slot_rest_penalty( $slot, $team_ids, $conflicts, $reserved ) {
		$min_rest = (int) $this->scheduling_constraints['min_rest_days'];

		if ( $min_rest <= 0 ) {
			return 0;
		}

		$start   = (int) $slot['start_ts'];
		$nearest = null;

		foreach ( array( $conflicts, $reserved ) as $source ) {
			foreach ( $team_ids as $team_id ) {
				if ( empty( $source['teams'][ $team_id ] ) ) {
					continue;
				}

				foreach ( $source['teams'][ $team_id ] as $entry ) {
					$days = (int) floor( abs( $start - (int) $entry['start_ts'] ) / DAY_IN_SECONDS );

					if ( null === $nearest || $days < $nearest ) {
						$nearest = $days;
					}
				}
			}
		}

		if ( null === $nearest || $nearest >= $min_rest ) {
			return 0;
		}

		return $min_rest - $nearest;
	}

	/**
	 * Check whether placing a pairing at a time breaks the rematch-gap rule.
	 *
	 * @param string               $pair_key Pairing key.
	 * @param int                  $start_ts Candidate start timestamp.
	 * @param array<string, mixed> $conflicts Existing conflicts.
	 * @param array<string, mixed> $reserved In-run reservations.
	 * @return bool
	 */
	protected function violates_rematch_gap( $pair_key, $start_ts, $conflicts, $reserved ) {
		$gap_days = (int) $this->scheduling_constraints['min_days_between_rematch'];

		if ( $gap_days <= 0 || '' === $pair_key ) {
			return false;
		}

		foreach ( array( $conflicts, $reserved ) as $source ) {
			if ( empty( $source['pairs'][ $pair_key ] ) ) {
				continue;
			}

			foreach ( $source['pairs'][ $pair_key ] as $entry ) {
				$days = (int) floor( abs( $start_ts - (int) $entry['start_ts'] ) / DAY_IN_SECONDS );

				if ( $days < $gap_days ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Order matches so the ones with the fewest open slots are scheduled first.
	 *
	 * @param array<int, \WP_Post>            $matches Matches.
	 * @param array<string, mixed>            $args Scheduling args.
	 * @param array<int, array<string,mixed>> $slots Slot candidates.
	 * @param array<string, mixed>            $conflicts Existing conflicts.
	 * @return array<int, \WP_Post>
	 */
	protected function sort_matches_most_constrained_first( $matches, $args, $slots, $conflicts ) {
		$no_reservations = array();
		$decorated       = array();

		foreach ( $matches as $index => $match ) {
			$team_ids    = $this->get_match_team_ids( (int) $match->ID );
			$match_sport = $this->get_match_sport_slug( (int) $match->ID );
			$open        = 0;
			$current_conflicts = $this->without_match_conflicts( $conflicts, array( (int) $match->ID ) );

			foreach ( $slots as $slot ) {
				if ( ! $this->slot_matches_sport( $slot, $match_sport ) ) {
					continue;
				}

				if ( $this->is_slot_open( $slot, $team_ids, $current_conflicts, $no_reservations ) ) {
					$open++;
				}
			}

			$decorated[] = array(
				'match' => $match,
				'open'  => $open,
				'index' => $index,
			);
		}

		usort(
			$decorated,
			static function( $a, $b ) {
				if ( $a['open'] !== $b['open'] ) {
					return $a['open'] <=> $b['open'];
				}

				return $a['index'] <=> $b['index'];
			}
		);

		return array_map(
			static function( $item ) {
				return $item['match'];
			},
			$decorated
		);
	}

	/**
	 * Find a venue for a match whose datetime is already fixed.
	 *
	 * @param int                  $match_id Match ID.
	 * @param array<string, mixed> $args Scheduling args.
	 * @param array<string, mixed> $conflicts Existing conflicts.
	 * @param array<string, mixed> $reserved Reserved slots.
	 * @return array<string, mixed>|null
	 */
	protected function find_venue_slot_for_existing_datetime( $match_id, $args, $conflicts, $reserved ) {
		$datetime = (string) get_post_meta( $match_id, 'lf_match_datetime', true );

		if ( '' === $datetime ) {
			return null;
		}

		$start_ts = $this->timestamp_from_datetime( $datetime );

		if ( ! $start_ts ) {
			return null;
		}

		$date     = wp_date( 'Y-m-d', $start_ts );
		$match_sport = $this->get_match_sport_slug( $match_id );
		$team_ids = $this->get_match_team_ids( $match_id );
		$args     = wp_parse_args(
			array(
				'date_from' => $date,
				'date_to'   => $date,
				'date'      => $date,
			),
			$args
		);

		foreach ( $this->get_availabilities(
			array(
				'active_only'     => true,
				'sport_slug'      => $args['sport_slug'],
				'availability_id' => $args['availability_id'],
				'date'            => $date,
			)
		) as $availability ) {
			if ( ! empty( $availability['sport_slug'] ) && $match_sport && $availability['sport_slug'] !== $match_sport ) {
				continue;
			}

			$window = $this->get_availability_window_for_date( $availability, $date );

			if ( empty( $window ) ) {
				continue;
			}

			$duration = max( 1, (int) $availability['slot_minutes'] ) * MINUTE_IN_SECONDS;
			$slot     = array(
				'availability_id'    => (string) $availability['id'],
				'availability_label' => $this->format_availability_label( $availability ),
				'venue'              => (string) $availability['venue'],
				'sport_slug'         => (string) $availability['sport_slug'],
				'date'               => $date,
				'start_time'         => wp_date( 'H:i', $start_ts ),
				'end_time'           => wp_date( 'H:i', $start_ts + $duration ),
				'start_datetime'     => wp_date( 'Y-m-d H:i', $start_ts ),
				'end_datetime'       => wp_date( 'Y-m-d H:i', $start_ts + $duration ),
				'start_ts'           => $start_ts,
				'end_ts'             => $start_ts + $duration,
			);

			if ( $slot['start_ts'] < $window['start_ts'] || $slot['end_ts'] > $window['end_ts'] ) {
				continue;
			}

			if ( $this->is_slot_open( $slot, $team_ids, $conflicts, $reserved ) ) {
				return $slot;
			}
		}

		return null;
	}

	/**
	 * Build all slot candidates from availability windows.
	 *
	 * @param array<string, mixed> $args Scheduling args.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_slots( $args ) {
		$availabilities = $this->get_availabilities(
			array(
				'active_only'     => true,
				'sport_slug'      => $args['sport_slug'],
				'availability_id' => $args['availability_id'],
			)
		);

		if ( empty( $availabilities ) ) {
			return array();
		}

		$slots = array();
		$tz    = wp_timezone();
		$date  = new \DateTimeImmutable( $args['date_from'] . ' 00:00:00', $tz );
		$end   = new \DateTimeImmutable( $args['date_to'] . ' 00:00:00', $tz );

		while ( $date <= $end ) {
			$date_key = $date->format( 'Y-m-d' );

			foreach ( $availabilities as $availability ) {
				if ( ! $this->availability_matches_date( $availability, $date_key ) ) {
					continue;
				}

				$window = $this->get_availability_window_for_date( $availability, $date_key );

				if ( empty( $window ) ) {
					continue;
				}

				$duration_seconds = max( 1, (int) $availability['slot_minutes'] ) * MINUTE_IN_SECONDS;
				$step_minutes     = max( 1, (int) $availability['slot_minutes'] + (int) $availability['buffer_minutes'] );
				$cursor_ts        = (int) $window['start_ts'];

				while ( $cursor_ts + $duration_seconds <= (int) $window['end_ts'] ) {
					$slots[] = array(
						'availability_id'    => (string) $availability['id'],
						'availability_label' => $this->format_availability_label( $availability ),
						'venue'              => (string) $availability['venue'],
						'sport_slug'         => (string) $availability['sport_slug'],
						'date'               => $date_key,
						'start_time'         => wp_date( 'H:i', $cursor_ts ),
						'end_time'           => wp_date( 'H:i', $cursor_ts + $duration_seconds ),
						'start_datetime'     => wp_date( 'Y-m-d H:i', $cursor_ts ),
						'end_datetime'       => wp_date( 'Y-m-d H:i', $cursor_ts + $duration_seconds ),
						'start_ts'           => $cursor_ts,
						'end_ts'             => $cursor_ts + $duration_seconds,
					);

					$cursor_ts += $step_minutes * MINUTE_IN_SECONDS;
				}
			}

			$date = $date->modify( '+1 day' );
		}

		usort(
			$slots,
			static function( $a, $b ) {
				if ( (int) $a['start_ts'] === (int) $b['start_ts'] ) {
					return strnatcasecmp( (string) $a['venue'], (string) $b['venue'] );
				}

				return (int) $a['start_ts'] <=> (int) $b['start_ts'];
			}
		);

		return $slots;
	}

	/**
	 * Get the timestamp window for an availability on a date.
	 *
	 * @param array<string, mixed> $availability Availability.
	 * @param string               $date Date.
	 * @return array<string, int>|null
	 */
	protected function get_availability_window_for_date( $availability, $date ) {
		$start = $this->timestamp_from_datetime( $date . ' ' . $availability['start_time'] );
		$end   = $this->timestamp_from_datetime( $date . ' ' . $availability['end_time'] );

		if ( ! $start || ! $end ) {
			return null;
		}

		if ( $end <= $start ) {
			$end += DAY_IN_SECONDS;
		}

		return array(
			'start_ts' => $start,
			'end_ts'   => $end,
		);
	}

	/**
	 * Check whether a slot is open.
	 *
	 * @param array<string, mixed> $slot Slot.
	 * @param array<int, int>      $team_ids Match team IDs.
	 * @param array<string, mixed> $conflicts Existing conflicts.
	 * @param array<string, mixed> $reserved In-run reservations.
	 * @return bool
	 */
	protected function is_slot_open( $slot, $team_ids, $conflicts, $reserved ) {
		$venue_key = $this->normalize_venue_key( (string) $slot['venue'] );
		$start     = (int) $slot['start_ts'];
		$end       = (int) $slot['end_ts'];

		foreach ( array( $conflicts, $reserved ) as $source ) {
			if ( ! empty( $source['venues'][ $venue_key ] ) ) {
				foreach ( $source['venues'][ $venue_key ] as $conflict ) {
					if ( $this->times_overlap( $start, $end, (int) $conflict['start_ts'], (int) $conflict['end_ts'] ) ) {
						return false;
					}
				}
			}

			foreach ( $team_ids as $team_id ) {
				if ( empty( $source['teams'][ $team_id ] ) ) {
					continue;
				}

				foreach ( $source['teams'][ $team_id ] as $conflict ) {
					if ( $this->times_overlap( $start, $end, (int) $conflict['start_ts'], (int) $conflict['end_ts'] ) ) {
						return false;
					}
				}
			}
		}

		// Hard cap on how many games a team may play on one day.
		$max_per_day = (int) $this->scheduling_constraints['max_games_per_day_per_team'];

		if ( $max_per_day > 0 ) {
			$slot_date = wp_date( 'Y-m-d', $start );

			foreach ( $team_ids as $team_id ) {
				$same_day = 0;

				foreach ( array( $conflicts, $reserved ) as $source ) {
					if ( empty( $source['teams'][ $team_id ] ) ) {
						continue;
					}

					foreach ( $source['teams'][ $team_id ] as $entry ) {
						if ( wp_date( 'Y-m-d', (int) $entry['start_ts'] ) === $slot_date ) {
							$same_day++;
						}
					}
				}

				if ( $same_day >= $max_per_day ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Reserve a selected slot for the current scheduling run.
	 *
	 * @param array<string, mixed> $slot Slot.
	 * @param int                  $match_id Match ID.
	 * @param array<string, mixed> $reserved Reservations.
	 * @return void
	 */
	protected function reserve_slot_for_match( $slot, $match_id, &$reserved ) {
		$venue_key = $this->normalize_venue_key( (string) $slot['venue'] );
		$entry     = array(
			'post_id'  => absint( $match_id ),
			'start_ts' => (int) $slot['start_ts'],
			'end_ts'   => (int) $slot['end_ts'],
		);

		if ( empty( $reserved['venues'][ $venue_key ] ) ) {
			$reserved['venues'][ $venue_key ] = array();
		}

		$reserved['venues'][ $venue_key ][] = $entry;

		$match_team_ids = $this->get_match_team_ids( $match_id );

		foreach ( $match_team_ids as $team_id ) {
			if ( empty( $reserved['teams'][ $team_id ] ) ) {
				$reserved['teams'][ $team_id ] = array();
			}

			$reserved['teams'][ $team_id ][] = $entry;
		}

		$pair_key = $this->pair_key( $match_team_ids );

		if ( '' !== $pair_key ) {
			if ( empty( $reserved['pairs'][ $pair_key ] ) ) {
				$reserved['pairs'][ $pair_key ] = array();
			}

			$reserved['pairs'][ $pair_key ][] = $entry;
		}
	}

	/**
	 * Build conflict indexes from existing matches and calendar events.
	 *
	 * @param string          $date_from Start date.
	 * @param string          $date_to End date.
	 * @param array<int, int> $exclude_match_ids Match IDs to ignore.
	 * @return array<string, mixed>
	 */
	protected function get_conflicts( $date_from, $date_to, $exclude_match_ids = array() ) {
		$conflicts         = array(
			'venues' => array(),
			'teams'  => array(),
			'pairs'  => array(),
		);
		$exclude_match_ids = array_filter( array_map( 'absint', (array) $exclude_match_ids ) );
		$padding           = max( 1, $this->scheduling_constraints['min_rest_days'], $this->scheduling_constraints['min_days_between_rematch'] );
		$query_start       = $this->date_shift( $date_from, -$padding ) . ' 00:00';
		$query_end         = $this->date_shift( $date_to, $padding ) . ' 23:59:59';

		$matches = get_posts(
			array(
				'post_type'      => 'lf_match',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post__not_in'   => $exclude_match_ids,
				'meta_query'     => array(
					array(
						'key'     => 'lf_match_datetime',
						'value'   => array( $query_start, $query_end ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
				),
			)
		);

		foreach ( $matches as $match_id ) {
			$status = sanitize_key( (string) get_post_meta( $match_id, 'lf_status', true ) );

			if ( in_array( $status, array( 'cancelled' ), true ) ) {
				continue;
			}

			$start_ts = $this->timestamp_from_datetime( (string) get_post_meta( $match_id, 'lf_match_datetime', true ) );

			if ( ! $start_ts ) {
				continue;
			}

			$venue = (string) get_post_meta( $match_id, 'lf_venue', true );
			$entry = array(
				'post_id'  => (int) $match_id,
				'start_ts' => $start_ts,
				'end_ts'   => $start_ts + self::get_match_duration_minutes( $match_id ) * MINUTE_IN_SECONDS,
			);

			if ( '' !== trim( $venue ) ) {
				$conflicts['venues'][ $this->normalize_venue_key( $venue ) ][] = $entry;
			}

			$match_team_ids = $this->get_match_team_ids( $match_id );

			foreach ( $match_team_ids as $team_id ) {
				$conflicts['teams'][ $team_id ][] = $entry;
			}

			$pair_key = $this->pair_key( $match_team_ids );

			if ( '' !== $pair_key ) {
				$conflicts['pairs'][ $pair_key ][] = $entry;
			}
		}

		$events = get_posts(
			array(
				'post_type'      => 'lf_calendar_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'lf_event_start_datetime',
						'value'   => $query_end,
						'compare' => '<=',
						'type'    => 'CHAR',
					),
					array(
						'relation' => 'OR',
						array( 'key' => 'lf_event_end_datetime', 'value' => $query_start, 'compare' => '>=', 'type' => 'CHAR' ),
						array( 'key' => 'lf_event_start_datetime', 'value' => $query_start, 'compare' => '>=', 'type' => 'CHAR' ),
					),
				),
			)
		);

		foreach ( $events as $event_id ) {
			$status = sanitize_key( (string) get_post_meta( $event_id, 'lf_event_status', true ) );

			if ( in_array( $status, array( 'cancelled' ), true ) ) {
				continue;
			}

			$start_ts = $this->timestamp_from_datetime( (string) get_post_meta( $event_id, 'lf_event_start_datetime', true ) );

			if ( ! $start_ts ) {
				continue;
			}

			$end_ts = $this->timestamp_from_datetime( (string) get_post_meta( $event_id, 'lf_event_end_datetime', true ) );

			if ( ! $end_ts || $end_ts <= $start_ts ) {
				$end_ts = $start_ts + HOUR_IN_SECONDS;
			}

			$venue = (string) get_post_meta( $event_id, 'lf_event_venue', true );

			if ( '' === trim( $venue ) ) {
				continue;
			}

			$conflicts['venues'][ $this->normalize_venue_key( $venue ) ][] = array(
				'post_id'  => (int) $event_id,
				'start_ts' => $start_ts,
				'end_ts'   => $end_ts,
			);
		}

		return $conflicts;
	}

	/**
	 * Query matches in the requested scheduling scope.
	 *
	 * @param array<string, mixed> $args Scheduling args.
	 * @return array<int, \WP_Post>
	 */
	protected function get_scheduleable_matches( $args ) {
		$query_args = array(
			'post_type'      => 'lf_match',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		if ( ! empty( $args['match_ids'] ) ) {
			$query_args['post__in'] = array_filter( array_map( 'absint', (array) $args['match_ids'] ) );
			$query_args['orderby']  = 'post__in';
		}

		$tax_query = array();

		if ( ! empty( $args['sport_slug'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_sport',
				'field'    => 'slug',
				'terms'    => array( sanitize_key( $args['sport_slug'] ) ),
			);
		}

		if ( ! empty( $args['competition_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_competition',
				'field'    => 'term_id',
				'terms'    => array( absint( $args['competition_id'] ) ),
			);
		}

		if ( ! empty( $args['season_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_season',
				'field'    => 'term_id',
				'terms'    => array( absint( $args['season_id'] ) ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		$matches = get_posts( $query_args );
		$mode    = (string) $args['mode'];

		return array_values(
			array_filter(
				$matches,
				function( $match ) use ( $args, $mode ) {
					$status = sanitize_key( (string) get_post_meta( $match->ID, 'lf_status', true ) );

					if ( in_array( $status, array( 'live', 'finished', 'cancelled' ), true ) ) {
						return false;
					}

					$datetime = (string) get_post_meta( $match->ID, 'lf_match_datetime', true );
					$venue    = (string) get_post_meta( $match->ID, 'lf_venue', true );

					if ( 'venue' === $mode && '' === $datetime ) {
						return false;
					}

					if ( ! $args['overwrite'] ) {
						if ( 'both' === $mode && '' !== $datetime && '' !== $venue ) {
							return false;
						}

						if ( 'datetime' === $mode && '' !== $datetime ) {
							return false;
						}

						if ( 'venue' === $mode && '' !== $venue ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Normalize scheduler arguments.
	 *
	 * @param array<string, mixed> $args Raw args.
	 * @return array<string, mixed>
	 */
	protected function normalize_schedule_args( $args ) {
		$today = current_time( 'Y-m-d' );
		$args  = wp_parse_args(
			$args,
			array(
				'sport_slug'          => '',
				'competition_id'      => 0,
				'season_id'           => 0,
				'date_from'           => $today,
				'date_to'             => $this->date_shift( $today, self::DEFAULT_RANGE_DAYS ),
				'date'                => '',
				'availability_id'     => '',
				'mode'                => 'both',
				'overwrite'           => false,
				'match_ids'           => array(),
				'suppress_title_sync' => false,
			)
		);

		$args['_invalid_scope'] = ! is_array( $args['match_ids'] ) && ! $this->is_schedule_id( $args['match_ids'] );
		foreach ( (array) $args['match_ids'] as $raw_id ) {
			if ( ! $this->is_schedule_id( $raw_id ) ) { $args['_invalid_scope'] = true; }
		}
		foreach ( array( 'competition_id', 'season_id' ) as $key ) {
			if ( '' !== $args[ $key ] && ! $this->is_schedule_id( $args[ $key ], true ) ) { $args['_invalid_scope'] = true; }
		}
		if ( $args['_invalid_scope'] ) {
			$args['match_ids'] = array();
			$args['competition_id'] = 0;
			$args['season_id'] = 0;
		}

		$date = $this->sanitize_date( (string) $args['date'] );

		if ( $date ) {
			$args['date_from'] = $date;
			$args['date_to']   = $date;
		} else {
			$args['date_from'] = $this->sanitize_date( (string) $args['date_from'] );
			$args['date_to']   = $this->sanitize_date( (string) $args['date_to'] );
		}

		if ( ! $args['date_from'] ) {
			$args['date_from'] = $today;
		}

		if ( ! $args['date_to'] ) {
			$args['date_to'] = $this->date_shift( $args['date_from'], self::DEFAULT_RANGE_DAYS );
		}

		if ( strcmp( $args['date_to'], $args['date_from'] ) < 0 ) {
			$args['date_to'] = $args['date_from'];
		}

		$days = (int) floor( ( $this->timestamp_from_datetime( $args['date_to'] . ' 00:00' ) - $this->timestamp_from_datetime( $args['date_from'] . ' 00:00' ) ) / DAY_IN_SECONDS );

		if ( $days > self::MAX_RANGE_DAYS ) {
			$args['date_to'] = $this->date_shift( $args['date_from'], self::MAX_RANGE_DAYS );
		}

		$args['sport_slug']          = sanitize_key( (string) $args['sport_slug'] );
		$args['availability_id']     = sanitize_key( (string) $args['availability_id'] );
		$args['competition_id']      = absint( $args['competition_id'] );
		$args['season_id']           = absint( $args['season_id'] );
		$args['overwrite']           = ! empty( $args['overwrite'] );
		$args['match_ids']           = array_values( array_unique( array_map( 'absint', (array) $args['match_ids'] ) ) );
		$args['suppress_title_sync'] = ! empty( $args['suppress_title_sync'] );
		$args['mode']                = sanitize_key( (string) $args['mode'] );
		$modes                       = $this->get_update_modes();

		if ( ! isset( $modes[ $args['mode'] ] ) ) {
			$args['mode'] = 'both';
		}

		return $args;
	}

	/** Reject coercible IDs before they can select an unintended fixture or scope. */
	protected function is_schedule_id( $value, $allow_zero = false ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return false; }
		$value = (string) $value;
		if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || ( ! $allow_zero && '0' === $value ) ) { return false; }
		$maximum = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 );
	}

	/**
	 * Sanitize and validate an availability window.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function sanitize_availability( $data ) {
		$id           = sanitize_key( (string) ( $data['id'] ?? '' ) );
		$name         = sanitize_text_field( wp_unslash( $data['name'] ?? '' ) );
		$venue        = sanitize_text_field( wp_unslash( $data['venue'] ?? '' ) );
		$sport_slug   = sanitize_key( (string) wp_unslash( $data['sport_slug'] ?? '' ) );
		$date         = $this->sanitize_date( (string) wp_unslash( $data['date'] ?? '' ) );
		$weekday      = isset( $data['weekday'] ) ? absint( wp_unslash( $data['weekday'] ) ) : -1;
		$start_time   = $this->sanitize_time( (string) wp_unslash( $data['start_time'] ?? '' ) );
		$end_time     = $this->sanitize_time( (string) wp_unslash( $data['end_time'] ?? '' ) );
		$slot_minutes = max( 1, absint( wp_unslash( $data['slot_minutes'] ?? 60 ) ) );
		$buffer       = max( 0, absint( wp_unslash( $data['buffer_minutes'] ?? 0 ) ) );
		$notes        = sanitize_textarea_field( wp_unslash( $data['notes'] ?? '' ) );
		$active       = ! empty( $data['active'] );

		if ( '' === $id ) {
			$id = 'field-' . sanitize_key( wp_generate_uuid4() );
		}

		if ( '' === $name ) {
			return new \WP_Error( 'leagueflow_availability_name', __( 'Availability name is required.', 'leagueflow' ) );
		}

		if ( '' === $venue ) {
			return new \WP_Error( 'leagueflow_availability_venue', __( 'Field or venue is required.', 'leagueflow' ) );
		}

		if ( '' === $date && ( $weekday < 0 || $weekday > 6 ) ) {
			return new \WP_Error( 'leagueflow_availability_weekday', __( 'Choose a weekday for recurring availability or set a specific date.', 'leagueflow' ) );
		}

		if ( '' === $start_time || '' === $end_time ) {
			return new \WP_Error( 'leagueflow_availability_time', __( 'Start and end times are required.', 'leagueflow' ) );
		}

		return array(
			'id'             => $id,
			'name'           => $name,
			'venue'          => $venue,
			'sport_slug'     => $sport_slug,
			'date'           => $date,
			'weekday'        => '' === $date ? $weekday : -1,
			'start_time'     => $start_time,
			'end_time'       => $end_time,
			'slot_minutes'   => $slot_minutes,
			'buffer_minutes' => $buffer,
			'active'         => $active,
			'notes'          => $notes,
		);
	}

	/**
	 * Normalize an already-saved availability.
	 *
	 * @param array<string, mixed> $raw Raw saved data.
	 * @return array<string, mixed>
	 */
	protected function normalize_availability( $raw ) {
		$date    = $this->sanitize_date( (string) ( $raw['date'] ?? '' ) );
		$weekday = isset( $raw['weekday'] ) ? (int) $raw['weekday'] : -1;

		return array(
			'id'             => sanitize_key( (string) ( $raw['id'] ?? '' ) ),
			'name'           => sanitize_text_field( (string) ( $raw['name'] ?? '' ) ),
			'venue'          => sanitize_text_field( (string) ( $raw['venue'] ?? '' ) ),
			'sport_slug'     => sanitize_key( (string) ( $raw['sport_slug'] ?? '' ) ),
			'date'           => $date,
			'weekday'        => '' === $date && $weekday >= 0 && $weekday <= 6 ? $weekday : -1,
			'start_time'     => $this->sanitize_time( (string) ( $raw['start_time'] ?? '' ) ),
			'end_time'       => $this->sanitize_time( (string) ( $raw['end_time'] ?? '' ) ),
			'slot_minutes'   => max( 1, absint( $raw['slot_minutes'] ?? 60 ) ),
			'buffer_minutes' => max( 0, absint( $raw['buffer_minutes'] ?? 0 ) ),
			'active'         => ! empty( $raw['active'] ),
			'notes'          => sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ),
		);
	}

	/**
	 * Check if an availability applies to a date.
	 *
	 * @param array<string, mixed> $availability Availability.
	 * @param string               $date Date.
	 * @return bool
	 */
	protected function availability_matches_date( $availability, $date ) {
		$date = $this->sanitize_date( $date );

		if ( ! $date ) {
			return false;
		}

		if ( ! empty( $availability['date'] ) ) {
			return $date === $availability['date'];
		}

		$timestamp = $this->timestamp_from_datetime( $date . ' 00:00' );

		return $timestamp && (int) wp_date( 'w', $timestamp ) === (int) $availability['weekday'];
	}

	/**
	 * Get team IDs for a match.
	 *
	 * @param int $match_id Match ID.
	 * @return array<int, int>
	 */
	protected function get_match_team_ids( $match_id ) {
		return array_values(
			array_filter(
				array_unique(
					array(
						absint( get_post_meta( $match_id, 'lf_home_team_id', true ) ),
						absint( get_post_meta( $match_id, 'lf_away_team_id', true ) ),
					)
				)
			)
		);
	}

	/**
	 * Get the sport slug assigned to a match.
	 *
	 * @param int $match_id Match ID.
	 * @return string
	 */
	protected function get_match_sport_slug( $match_id ) {
		return sanitize_key( get_post_primary_term_slug( absint( $match_id ), 'lf_sport' ) );
	}

	/**
	 * Check if a slot is valid for a match sport.
	 *
	 * @param array<string, mixed> $slot Slot.
	 * @param string               $match_sport Match sport slug.
	 * @return bool
	 */
	protected function slot_matches_sport( $slot, $match_sport ) {
		$slot_sport  = sanitize_key( (string) ( $slot['sport_slug'] ?? '' ) );
		$match_sport = sanitize_key( $match_sport );

		return '' === $slot_sport || '' === $match_sport || $slot_sport === $match_sport;
	}

	/**
	 * Sync the match title after assistant updates.
	 *
	 * @param int    $match_id Match ID.
	 * @param string $datetime Date/time.
	 * @return bool
	 */
	protected function sync_match_title_from_meta( $match_id, $datetime ) {
		$title = build_match_title( (int) get_post_meta( $match_id, 'lf_home_team_id', true ), (int) get_post_meta( $match_id, 'lf_away_team_id', true ), $datetime );
		// Do not replay an editor form during an internal title update.
		$submitted = $_POST;
		$_POST = array();
		try { $saved = wp_update_post( array( 'ID' => (int) $match_id, 'post_title' => $title ), true, false ); }
		finally { $_POST = $submitted; }
		return ! is_wp_error( $saved ) && $saved && $title === get_post_field( 'post_title', $match_id );
	}

	/**
	 * Normalize venue keys for conflict lookups.
	 *
	 * @param string $venue Venue.
	 * @return string
	 */
	protected function normalize_venue_key( $venue ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $venue ) ) );
	}

	/**
	 * Check whether two time ranges overlap.
	 *
	 * @param int $a_start First start.
	 * @param int $a_end First end.
	 * @param int $b_start Second start.
	 * @param int $b_end Second end.
	 * @return bool
	 */
	protected function times_overlap( $a_start, $a_end, $b_start, $b_end ) {
		return $a_start < $b_end && $b_start < $a_end;
	}

	/**
	 * Sanitize a YYYY-MM-DD date string.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	protected function sanitize_date( $date ) {
		$date = trim( sanitize_text_field( $date ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parts = array_map( 'absint', explode( '-', $date ) );

		return checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : '';
	}

	/**
	 * Sanitize an HH:MM time string.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	protected function sanitize_time( $time ) {
		$time = trim( sanitize_text_field( $time ) );

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $matches ) ) {
			return '';
		}

		$hour   = absint( $matches[1] );
		$minute = absint( $matches[2] );

		if ( $hour > 23 || $minute > 59 ) {
			return '';
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * Convert a local datetime string to a timestamp.
	 *
	 * @param string $datetime Date/time.
	 * @return int
	 */
	protected function timestamp_from_datetime( $datetime ) {
		$datetime = trim( str_replace( 'T', ' ', (string) $datetime ) );

		if ( '' === $datetime ) {
			return 0;
		}

		try {
			$date = new \DateTimeImmutable( $datetime, wp_timezone() );
		} catch ( \Exception $exception ) {
			return 0;
		}

		return (int) $date->getTimestamp();
	}

	/**
	 * Shift a YYYY-MM-DD date.
	 *
	 * @param string $date Date.
	 * @param int    $days Days.
	 * @return string
	 */
	protected function date_shift( $date, $days ) {
		$date = $this->sanitize_date( $date );

		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}

		$modifier = ( $days >= 0 ? '+' : '' ) . (int) $days . ' days';

		try {
			$next = new \DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
		} catch ( \Exception $exception ) {
			return current_time( 'Y-m-d' );
		}

		return $next->modify( $modifier )->format( 'Y-m-d' );
	}
}
