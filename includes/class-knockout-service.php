<?php
/**
 * Knockout stage helpers.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Knockout round service.
 */
class Knockout_Service {

	/** Prevent title-only writes from recursively processing the bracket. */
	protected $syncing = false;

	/** A referee result authorizes only its configured downstream slot. */
	protected $referee_source = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'leagueflow_match_result_saved', array( $this, 'synchronize_result' ) );
		add_action( 'leagueflow_referee_result_submitted', array( $this, 'handle_referee_result' ), 10, 2 );
		// Core has saved metadata and terms by this point. save_post runs too
		// early for meta_input/REST and for a transactional result correction.
		add_action( 'wp_after_insert_post', array( $this, 'handle_match_save' ), 30, 3 );
		add_action( 'trashed_post', array( $this, 'synchronize_result' ) );
		add_action( 'untrashed_post', array( $this, 'synchronize_result' ) );
	}

	/**
	 * Advance winners after a knockout match is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an update.
	 * @return void
	 */
	public function handle_match_save( $post_id, $post, $update ) {
		unset( $update );

		if ( $this->syncing || ! $post instanceof \WP_Post || 'lf_match' !== $post->post_type || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->synchronize_result( $post_id );
	}

	/** Process a committed, assigned referee submission without granting post-edit access. */
	public function handle_referee_result( $match_id, $user_id ) {
		if ( (int) $user_id !== get_current_user_id() || ! $this->is_committed_referee_result( $match_id ) ) { return; }
		$this->referee_source = (int) $match_id;
		try { $this->synchronize_result( $match_id ); }
		finally { $this->referee_source = 0; }
	}

	/** Keep the slot we previously filled consistent after corrections or reopening. */
	public function synchronize_result( $match_id ) {
		$match_id = absint( $match_id );
		if ( $this->syncing || 'lf_match' !== get_post_type( $match_id ) || ! $this->can_edit_bracket_match( $match_id ) ) { return; }
		$lock = db_lock_name( 'result', (string) $match_id );
		if ( ! acquire_db_lock( $lock, 0 ) ) { return; }
		try {
			clean_post_cache( $match_id );
			if ( ! $this->can_edit_bracket_match( $match_id ) ) { return; }
			$active = get_post_meta( $match_id, 'lf_is_knockout', true ) && ! in_array( get_post_status( $match_id ), array( 'trash', 'auto-draft' ), true );
			$winner = $active ? $this->determine_winner_team_id( $match_id ) : 0;
			$next = $active ? absint( get_post_meta( $match_id, 'lf_next_match_id', true ) ) : 0;
			$slot = 'away' === get_post_meta( $match_id, 'lf_next_match_slot', true ) ? 'away' : 'home';
			$notice = '';
			$targets = get_posts( array( 'post_type' => 'lf_match', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_lf_home_advanced_from', 'value' => $match_id ), array( 'key' => '_lf_away_advanced_from', 'value' => $match_id ) ) ) );
			foreach ( $targets as $target_id ) {
				foreach ( array( 'home', 'away' ) as $old_slot ) {
					if ( $match_id !== (int) get_post_meta( $target_id, '_lf_' . $old_slot . '_advanced_from', true ) || ( $winner && (int) $target_id === $next && $old_slot === $slot ) ) { continue; }
					if ( ! $this->retract_slot( $match_id, (int) $target_id, $old_slot ) ) {
						$notice = __( 'The result was saved, but a previously advanced bracket slot needs staff review. A later match has started, is locked, or is no longer editable; its participants were preserved.', 'leagueflow' );
					}
				}
			}
			$already_advanced = $winner && $next && $winner === (int) get_post_meta( $next, 'lf_' . $slot . '_team_id', true )
				&& $winner !== (int) get_post_meta( $next, 'lf_' . ( 'home' === $slot ? 'away' : 'home' ) . '_team_id', true )
				&& $match_id === (int) get_post_meta( $next, '_lf_' . $slot . '_advanced_from', true ) && $this->can_advance_to( $match_id, $next );
			if ( ! $notice && $winner && $next && ! $already_advanced && ! $this->advance_winner_locked( $match_id ) ) {
				$notice = __( 'The result was saved, but its winner could not advance. Check the next match, bracket context, destination slot and whether the later match has started.', 'leagueflow' );
			} elseif ( ! $notice && $active && 'finished' === get_post_meta( $match_id, 'lf_status', true ) && $next && ! $winner ) {
				$notice = __( 'The final knockout result has no advancing winner. Review a tied score, double forfeit or missing participant, and set a winner only when your competition rules decide one.', 'leagueflow' );
			}
			if ( $notice ) { update_post_meta( $match_id, '_lf_bracket_sync_notice', $notice ); }
			else { delete_post_meta( $match_id, '_lf_bracket_sync_notice' ); }
		} finally { release_db_lock( $lock ); }
	}

	/** A started or decided target is never rewritten by a source correction. */
	protected function is_unstarted( $match_id ) {
		return in_array( (string) get_post_meta( $match_id, 'lf_status', true ), array( '', 'scheduled', 'postponed' ), true )
			&& '' === (string) get_post_meta( $match_id, 'lf_home_score', true ) && '' === (string) get_post_meta( $match_id, 'lf_away_score', true )
			&& '' === (string) get_post_meta( $match_id, 'lf_outcome', true ) && ! get_post_meta( $match_id, 'lf_winner_team_id', true );
	}

	protected function retract_slot( $source_id, $target_id, $slot ) {
		if ( ! $this->can_edit_bracket_match( $target_id ) ) { return false; }
		$lock = db_lock_name( 'result', (string) $target_id );
		if ( ! acquire_db_lock( $lock, 0 ) ) { return false; }
		try {
			clean_post_cache( $target_id );
			if ( (int) get_post_meta( $target_id, '_lf_' . $slot . '_advanced_from', true ) !== $source_id ) { return true; }
			if ( ! $this->can_edit_bracket_match( $source_id ) || ! $this->can_edit_bracket_match( $target_id ) || ! $this->is_unstarted( $target_id ) ) { return false; }
			return $this->write_slot( $source_id, $target_id, $slot, 0 );
		} finally { release_db_lock( $lock ); }
	}

	/**
	 * Get bracket data grouped by round.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_bracket( $competition_id = 0, $season_id = 0, $sport_id = 0, $league_level_id = 0 ) {
		$matches = array_values( array_filter( get_posts( $this->build_query_args( $competition_id, $season_id, $sport_id, $league_level_id ) ), __NAMESPACE__ . '\\can_view_league_post' ) );

		usort(
			$matches,
			function( $left, $right ) {
				$left_order  = $this->get_round_order( $left->ID );
				$right_order = $this->get_round_order( $right->ID );

				if ( $left_order !== $right_order ) {
					return $left_order <=> $right_order;
				}

				$left_time  = strtotime( (string) get_post_meta( $left->ID, 'lf_match_datetime', true ) );
				$right_time = strtotime( (string) get_post_meta( $right->ID, 'lf_match_datetime', true ) );

				if ( $left_time !== $right_time ) {
					return $left_time <=> $right_time;
				}

				return $left->ID <=> $right->ID;
			}
		);

		$rounds = array();

		foreach ( $matches as $match ) {
			$round_label = get_post_meta( $match->ID, 'lf_round_label', true );
			$round_label = $round_label ? $round_label : __( 'Knockout Round', 'leagueflow' );

			if ( ! isset( $rounds[ $round_label ] ) ) {
				$rounds[ $round_label ] = array(
					'label'   => $round_label,
					'order'   => $this->get_round_order( $match->ID ),
					'matches' => array(),
				);
			}

			$rounds[ $round_label ]['matches'][] = $this->format_bracket_match( $match );
		}

		$rounds = array_values( $rounds );

		usort(
			$rounds,
			static function( $left, $right ) {
				if ( $left['order'] !== $right['order'] ) {
					return $left['order'] <=> $right['order'];
				}

				return strcasecmp( (string) $left['label'], (string) $right['label'] );
			}
		);

		return $rounds;
	}

	/**
	 * Build the bracket as a tree derived from next-match links.
	 *
	 * Each node's children are the matches that feed into it (home slot first,
	 * then away). Roots are matches with no outgoing link (typically the final).
	 * When no match links to another, 'linked' is false and callers should fall
	 * back to the round-column layout.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array{linked: bool, roots: array<int, array<string, mixed>>}
	 */
	public function get_bracket_tree( $competition_id = 0, $season_id = 0, $sport_id = 0, $league_level_id = 0 ) {
		$matches = array_values( array_filter( get_posts( $this->build_query_args( $competition_id, $season_id, $sport_id, $league_level_id ) ), __NAMESPACE__ . '\\can_view_league_post' ) );

		if ( empty( $matches ) ) {
			return array(
				'linked' => false,
				'roots'  => array(),
			);
		}

		$by_id       = array();
		$children_of = array();
		$has_parent  = array();
		$linked      = false;

		foreach ( $matches as $match ) {
			$by_id[ (int) $match->ID ] = $match;
		}

		foreach ( $matches as $match ) {
			$next_id = (int) get_post_meta( $match->ID, 'lf_next_match_id', true );

			if ( $next_id && isset( $by_id[ $next_id ] ) ) {
				$slot = get_post_meta( $match->ID, 'lf_next_match_slot', true );
				$slot = in_array( $slot, array( 'home', 'away' ), true ) ? $slot : 'home';

				$children_of[ $next_id ][] = array(
					'slot'  => $slot,
					'match' => $match,
				);

				$has_parent[ (int) $match->ID ] = true;
				$linked                         = true;
			}
		}

		$roots = array();

		foreach ( $matches as $match ) {
			if ( empty( $has_parent[ (int) $match->ID ] ) ) {
				$roots[] = $this->build_bracket_node( $match, $children_of, array() );
			}
		}

		return array(
			'linked' => $linked,
			'roots'  => $roots,
		);
	}

	/**
	 * Recursively build a bracket node and its feeding children.
	 *
	 * @param \WP_Post                              $match Match post.
	 * @param array<int, array<int, array<string, mixed>>> $children_of Child index.
	 * @param array<int, bool>                      $visited Guard against cycles.
	 * @return array<string, mixed>
	 */
	protected function build_bracket_node( $match, $children_of, $visited ) {
		$match_id             = (int) $match->ID;
		$visited[ $match_id ] = true;

		$node             = $this->format_bracket_match( $match );
		$node['children'] = array();

		if ( ! empty( $children_of[ $match_id ] ) ) {
			$children = $children_of[ $match_id ];

			usort(
				$children,
				static function( $left, $right ) {
					// Home feed above away feed.
					return ( 'home' === $left['slot'] ? 0 : 1 ) <=> ( 'home' === $right['slot'] ? 0 : 1 );
				}
			);

			foreach ( $children as $child ) {
				$child_id = (int) $child['match']->ID;

				if ( isset( $visited[ $child_id ] ) ) {
					continue;
				}

				$node['children'][] = $this->build_bracket_node( $child['match'], $children_of, $visited );
			}
		}

		return $node;
	}

	/**
	 * Format a match post into bracket display data.
	 *
	 * @param \WP_Post $match Match post.
	 * @return array<string, mixed>
	 */
	protected function format_bracket_match( $match ) {
		$home_team_id = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
		$away_team_id = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );
		$status = (string) get_post_meta( $match->ID, 'lf_status', true );
		$outcome = (string) get_post_meta( $match->ID, 'lf_outcome', true );
		$home_score = get_post_meta( $match->ID, 'lf_home_score', true );
		$away_score = get_post_meta( $match->ID, 'lf_away_score', true );
		$winner_id = $this->determine_winner_team_id( $match->ID );
		// An incomplete later round is waiting for a participant, not a bye.
		// Legacy confirmed byes carry an explicit sole winner and no score.
		$is_bye = $winner_id && $winner_id === (int) get_post_meta( $match->ID, 'lf_winner_team_id', true )
			&& ( (bool) $home_team_id !== (bool) $away_team_id ) && '' === $outcome && '' === $home_score && '' === $away_score;
		$played_result = '' === $outcome && $home_team_id && $away_team_id && $home_team_id !== $away_team_id;
		// Saved scores stay private until the result is published (Finished).
		$public_result = Result_Service::result_is_public( $match->ID, $status );
		$home_score = $public_result && $played_result && Match_Status::is_valid_score( $home_score ) ? (string) (int) $home_score : '';
		$away_score = $public_result && $played_result && Match_Status::is_valid_score( $away_score ) ? (string) (int) $away_score : '';
		$status_labels = Match_Status::statuses();
		$outcome_label = $public_result ? Match_Status::outcome_label( $outcome ) : '';
		$result_label = $outcome_label;
		if ( 'finished' === $status && ( '' === $home_score || '' === $away_score ) && ! $winner_id && '' === $result_label ) {
			$result_label = __( 'Score unavailable', 'leagueflow' );
		}

		return array(
			'id'             => (int) $match->ID,
			'title'          => $match->post_title,
			'permalink'      => get_permalink( $match->ID ),
			'round_label'    => (string) get_post_meta( $match->ID, 'lf_round_label', true ),
			'home_team_id'   => $home_team_id,
			'away_team_id'   => $away_team_id,
			'home_team'      => $home_team_id ? get_the_title( $home_team_id ) : '',
			'away_team'      => $away_team_id ? get_the_title( $away_team_id ) : '',
			'home_score'     => $home_score,
			'away_score'     => $away_score,
			'outcome'        => $outcome,
			'outcome_label'  => $outcome_label,
			'result_label'   => $result_label,
			'status'         => $status,
			'status_label'   => $status_labels[ $status ] ?? ( $status ? ucfirst( $status ) : $status_labels['scheduled'] ),
			'datetime'       => format_match_datetime( (string) get_post_meta( $match->ID, 'lf_match_datetime', true ) ),
			'datetime_raw'   => (string) get_post_meta( $match->ID, 'lf_match_datetime', true ),
			'venue'          => get_post_meta( $match->ID, 'lf_venue', true ),
			'winner_team_id' => $winner_id,
			'is_bye'         => $is_bye,
		);
	}

	/**
	 * Advance the winner into the next match.
	 *
	 * @param int $match_id Match ID.
	 * @return int
	 */
	public function advance_winner( $match_id ) {
		$match_id = absint( $match_id );
		if ( 'lf_match' !== get_post_type( $match_id ) || ! $this->can_edit_bracket_match( $match_id ) ) { return 0; }
		$lock = db_lock_name( 'result', (string) $match_id );
		if ( ! acquire_db_lock( $lock, 0 ) ) { return 0; }
		try {
			clean_post_cache( $match_id );
			return $this->advance_winner_locked( $match_id );
		} finally { release_db_lock( $lock ); }
	}

	/** The source lock is already held by advancement or synchronization. */
	protected function advance_winner_locked( $match_id ) {
		if ( ! get_post_meta( $match_id, 'lf_is_knockout', true ) || 'finished' !== get_post_meta( $match_id, 'lf_status', true ) ) {
			return 0;
		}

		$winner_team_id = $this->determine_winner_team_id( $match_id );
		$next_match_id  = (int) get_post_meta( $match_id, 'lf_next_match_id', true );
		$slot           = get_post_meta( $match_id, 'lf_next_match_slot', true );

		if ( ! $winner_team_id || 'lf_team' !== get_post_type( $winner_team_id ) || ! $this->can_advance_to( $match_id, $next_match_id ) ) {
			return 0;
		}

		// Result entry and bracket advancement change the same match state.
		$lock = db_lock_name( 'result', (string) $next_match_id );
		if ( ! acquire_db_lock( $lock, 0 ) ) {
			return 0;
		}

		try {
			// The graph check above primed metadata caches before the lock. A
			// concurrent result or feeder may have changed either record since then.
			clean_post_cache( $match_id );
			clean_post_cache( $next_match_id );
			if ( ! $this->can_edit_bracket_match( $match_id ) || ! $this->can_edit_bracket_match( $next_match_id ) ) { return 0; }
			if ( ! get_post_meta( $match_id, 'lf_is_knockout', true )
				|| $next_match_id !== (int) get_post_meta( $match_id, 'lf_next_match_id', true )
				|| ! $this->can_advance_to( $match_id, $next_match_id ) ) { return 0; }
			$winner_team_id = $this->determine_winner_team_id( $match_id );
			if ( ! $winner_team_id ) { return 0; }
			$slot       = get_post_meta( $match_id, 'lf_next_match_slot', true );
			$slot       = in_array( $slot, array( 'home', 'away' ), true ) ? $slot : 'home';
			$other_slot = 'home' === $slot ? 'away' : 'home';
			$current    = (int) get_post_meta( $next_match_id, 'lf_' . $slot . '_team_id', true );
			$origin     = (int) get_post_meta( $next_match_id, '_lf_' . $slot . '_advanced_from', true );

			// A corrected semifinal may update an unplayed final fed by that
			// semifinal, but must not replace a manually chosen opponent or a
			// participant in a match that has already started. Staff resolve those
			// cases explicitly instead of silently corrupting later results.
			if ( ! $this->is_unstarted( $next_match_id )
				|| ( $current && $current !== $winner_team_id && $origin !== (int) $match_id )
				|| ( $origin && $origin !== (int) $match_id )
				|| $winner_team_id === (int) get_post_meta( $next_match_id, 'lf_' . $other_slot . '_team_id', true ) ) {
				return 0;
			}

			return $this->write_slot( $match_id, $next_match_id, $slot, $winner_team_id ) ? $winner_team_id : 0;
		} finally {
			release_db_lock( $lock );
		}
	}

	/** Persist participant, feeder provenance and title together, or roll them all back. */
	protected function write_slot( $source_id, $target_id, $slot, $team_id ) {
		global $wpdb;
		if ( ! $this->can_edit_bracket_match( $source_id ) || ! $this->can_edit_bracket_match( $target_id ) || ! $this->is_authorized_referee_slot( $source_id, $target_id, $slot ) ) { return false; }
		foreach ( array( $wpdb->posts, $wpdb->postmeta ) as $table_name ) {
			$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $table || 'innodb' !== strtolower( (string) $table->Engine ) ) { return false; }
		}
		// START TRANSACTION commits an existing transaction in MySQL. A harmless
		// savepoint probe detects a caller-owned transaction (for example a demo
		// seed/import); use a savepoint there so that caller can still roll back.
		$savepoint = 'lf_bracket_' . (int) $source_id . '_' . (int) $target_id;
		$previous_errors = $wpdb->suppress_errors( true );
		$probe = $wpdb->query( 'SAVEPOINT ' . $savepoint ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$nested = false !== $wpdb->query( 'RELEASE SAVEPOINT ' . $savepoint ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$probe_error = $wpdb->last_error;
		$wpdb->suppress_errors( $previous_errors );
		if ( false === $probe || ( ! $nested && ! preg_match( '/SAVEPOINT .+ does not exist/i', $probe_error ) ) ) { return false; }
		if ( false === $wpdb->query( $nested ? 'SAVEPOINT ' . $savepoint : 'START TRANSACTION' ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$committed = false;
		try {
			update_post_meta( $target_id, 'lf_' . $slot . '_team_id', $team_id );
			if ( $team_id ) { update_post_meta( $target_id, '_lf_' . $slot . '_advanced_from', $source_id ); }
			else { delete_post_meta( $target_id, '_lf_' . $slot . '_advanced_from' ); }
			if ( (int) get_post_meta( $target_id, 'lf_' . $slot . '_team_id', true ) !== $team_id
				|| ( $team_id ? $source_id !== (int) get_post_meta( $target_id, '_lf_' . $slot . '_advanced_from', true ) : metadata_exists( 'post', $target_id, '_lf_' . $slot . '_advanced_from' ) ) ) { return false; }
			$title = build_match_title( (int) get_post_meta( $target_id, 'lf_home_team_id', true ), (int) get_post_meta( $target_id, 'lf_away_team_id', true ), (string) get_post_meta( $target_id, 'lf_match_datetime', true ) );
			// A title refresh must never replay the source editor's form on a target.
			$submitted = $_POST;
			$this->syncing = true;
			$_POST = array();
			try { $saved = wp_update_post( array( 'ID' => $target_id, 'post_title' => $title ), true, false ); }
			finally { $_POST = $submitted; $this->syncing = false; }
			if ( is_wp_error( $saved ) || ! $saved || get_post_field( 'post_title', $target_id ) !== $title
				|| ! $this->can_edit_bracket_match( $source_id ) || ! $this->can_edit_bracket_match( $target_id ) || ! $this->is_authorized_referee_slot( $source_id, $target_id, $slot ) ) { return false; }
			if ( false === $wpdb->query( $nested ? 'RELEASE SAVEPOINT ' . $savepoint : 'COMMIT' ) ) { return false; } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$committed = true;
			return true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( $nested ? 'ROLLBACK TO SAVEPOINT ' . $savepoint : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $nested ) { $wpdb->query( 'RELEASE SAVEPOINT ' . $savepoint ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				clean_post_cache( $target_id );
			}
		}
	}

	/** A referee submission authorizes only the configured slot in the same bracket context. */
	protected function is_authorized_referee_slot( $source_id, $target_id, $slot ) {
		if ( ! $this->referee_source || ( current_user_can( 'edit_post', $source_id ) && current_user_can( 'edit_post', $target_id ) ) ) { return true; }
		return (int) $source_id === $this->referee_source && (int) $target_id === (int) get_post_meta( $source_id, 'lf_next_match_id', true )
			&& $slot === ( 'away' === get_post_meta( $source_id, 'lf_next_match_slot', true ) ? 'away' : 'home' )
			&& $this->can_advance_to( $source_id, $target_id );
	}

	/** Normal staff authorization, with a narrowly scoped committed-referee path. */
	protected function can_edit_bracket_match( $match_id ) {
		if ( current_user_can( 'edit_post', $match_id ) ) { return true; }
		if ( ! $this->referee_source || ! $this->is_committed_referee_result( $this->referee_source ) ) { return false; }
		if ( (int) $match_id !== $this->referee_source && (int) $match_id !== (int) get_post_meta( $this->referee_source, 'lf_next_match_id', true ) ) { return false; }
		$post = get_post( $match_id );
		return $post instanceof \WP_Post && 'lf_match' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
	}

	/** A referee may inspect the configured public path to validate it, without editing later rounds. */
	protected function can_inspect_bracket_match( $match_id ) {
		if ( current_user_can( 'edit_post', $match_id ) ) { return true; }
		if ( ! $this->referee_source || ! $this->is_committed_referee_result( $this->referee_source ) ) { return false; }
		$post = get_post( $match_id );
		return $post instanceof \WP_Post && 'lf_match' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
	}

	protected function is_committed_referee_result( $match_id ) {
		if ( ! current_user_can( Referee::SCORE_CAP ) || ! Referee::can_access( $match_id, true )
			|| 'finished' !== get_post_meta( $match_id, 'lf_status', true ) || get_post_meta( $match_id, 'lf_outcome', true ) || get_post_meta( $match_id, 'lf_winner_team_id', true ) ) { return false; }
		$history = get_post_meta( $match_id, Referee::AUDIT_META, false );
		$last = $history ? end( $history ) : null;
		return is_array( $last ) && get_current_user_id() === (int) ( $last['user_id'] ?? 0 )
			&& isset( $last['result_state'] ) && is_string( $last['result_state'] ) && hash_equals( Referee::state_token( $match_id ), $last['result_state'] )
			&& Referee::valid_result_context( $match_id, array( (int) get_post_meta( $match_id, 'lf_home_team_id', true ), (int) get_post_meta( $match_id, 'lf_away_team_id', true ) ) )
			&& (string) get_post_meta( $match_id, 'lf_home_score', true ) === (string) ( $last['home_score'] ?? '' )
			&& (string) get_post_meta( $match_id, 'lf_away_score', true ) === (string) ( $last['away_score'] ?? '' );
	}

	/** Validate the whole referenced graph before permitting a cross-match write. */
	public function can_advance_to( $match_id, $next_match_id, $context = array(), $round_order = null ) {
		if ( 'lf_match' !== get_post_type( $match_id ) || ! $this->can_edit_bracket_match( $match_id ) || ! $next_match_id ) { return false; }
		if ( $this->referee_source && ! current_user_can( 'edit_post', $match_id )
			&& ( (int) $match_id !== $this->referee_source || (int) $next_match_id !== (int) get_post_meta( $match_id, 'lf_next_match_id', true ) ) ) { return false; }
		$visited = array( (int) $match_id => true );
		$previous = (int) $match_id;
		$node = (int) $next_match_id;
		for ( $hop = 0; $node && $hop < 100; ++$hop ) {
			if ( isset( $visited[$node] ) || 'lf_match' !== get_post_type( $node ) || ! get_post_meta( $node, 'lf_is_knockout', true ) || ! $this->can_inspect_bracket_match( $node ) ) { return false; }
			foreach ( array( 'lf_sport', 'lf_league_level', 'lf_competition', 'lf_season' ) as $taxonomy ) {
				$source = $context[$taxonomy] ?? wp_get_object_terms( $match_id, $taxonomy, array( 'fields' => 'ids' ) );
				$target = wp_get_object_terms( $node, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $source ) || is_wp_error( $target ) ) { return false; }
				sort( $source ); sort( $target );
				if ( $source !== $target ) { return false; }
			}
			$order = $previous === (int) $match_id && null !== $round_order ? (int) $round_order : (int) get_post_meta( $previous, 'lf_round_order', true );
			if ( $order > 0 && (int) get_post_meta( $node, 'lf_round_order', true ) <= $order ) { return false; }
			$visited[$node] = true;
			$previous = $node;
			$node = (int) get_post_meta( $node, 'lf_next_match_id', true );
		}
		return 0 === $node;
	}

	/**
	 * Determine the winner of a knockout match.
	 *
	 * @param int $match_id Match ID.
	 * @return int
	 */
	public function determine_winner_team_id( $match_id ) {
		if ( 'lf_match' !== get_post_type( $match_id ) || in_array( get_post_status( $match_id ), array( 'trash', 'auto-draft' ), true ) || 'finished' !== get_post_meta( $match_id, 'lf_status', true ) ) {
			return 0;
		}

		$home_team_id = (int) get_post_meta( $match_id, 'lf_home_team_id', true );
		$away_team_id = (int) get_post_meta( $match_id, 'lf_away_team_id', true );
		$winner_id    = (int) get_post_meta( $match_id, 'lf_winner_team_id', true );

		if ( $home_team_id === $away_team_id || ( $home_team_id && ( 'lf_team' !== get_post_type( $home_team_id ) || 'trash' === get_post_status( $home_team_id ) ) ) || ( $away_team_id && ( 'lf_team' !== get_post_type( $away_team_id ) || 'trash' === get_post_status( $away_team_id ) ) ) ) {
			return 0;
		}

		$outcome = sanitize_key( (string) get_post_meta( $match_id, 'lf_outcome', true ) );

		if ( 'forfeit_home' === $outcome ) {
			return $away_team_id;
		}

		if ( 'forfeit_away' === $outcome ) {
			return $home_team_id;
		}

		if ( 'double_forfeit' === $outcome ) {
			return 0;
		}

		if ( '' !== $outcome ) { return 0; }
		if ( $winner_id && in_array( $winner_id, array( $home_team_id, $away_team_id ), true ) ) {
			return $winner_id;
		}

		if ( ! $home_team_id || ! $away_team_id ) {
			return 0;
		}

		$home_score = get_post_meta( $match_id, 'lf_home_score', true );
		$away_score = get_post_meta( $match_id, 'lf_away_score', true );

		if ( ! Match_Status::is_valid_score( $home_score ) || ! Match_Status::is_valid_score( $away_score ) ) {
			return 0;
		}

		$home_score = score_to_int( $home_score );
		$away_score = score_to_int( $away_score );

		if ( $home_score > $away_score ) {
			return $home_team_id;
		}

		if ( $away_score > $home_score ) {
			return $away_team_id;
		}

		return 0;
	}

	/**
	 * Build a knockout match query.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<string, mixed>
	 */
	protected function build_query_args( $competition_id, $season_id, $sport_id, $league_level_id ) {
		$args = array(
			'post_type'      => 'lf_match',
			'post_status'    => frontend_post_statuses(),
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => 'lf_is_knockout',
					'value'   => '1',
					'compare' => '=',
				),
			),
		);

		$tax_query = array();

		if ( $competition_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_competition',
				'field'    => 'term_id',
				'terms'    => array( $competition_id ),
			);
		}

		if ( $season_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_season',
				'field'    => 'term_id',
				'terms'    => array( $season_id ),
			);
		}

		if ( $sport_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_sport',
				'field'    => 'term_id',
				'terms'    => array( $sport_id ),
			);
		}

		if ( $league_level_id ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'term_id',
				'terms'    => array( $league_level_id ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		return $args;
	}

	/**
	 * Get round order with sensible defaults.
	 *
	 * @param int $match_id Match ID.
	 * @return int
	 */
	protected function get_round_order( $match_id ) {
		$explicit = (int) get_post_meta( $match_id, 'lf_round_order', true );

		if ( $explicit ) {
			return $explicit;
		}

		$label = strtolower( (string) get_post_meta( $match_id, 'lf_round_label', true ) );
		$map   = array(
			'round of 32'  => 5,
			'round of 16'  => 10,
			'quarterfinal' => 20,
			'quarterfinals' => 20,
			'semifinal'    => 30,
			'semifinals'   => 30,
			'final'        => 40,
		);

		return $map[ $label ] ?? 100;
	}
}
