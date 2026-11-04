<?php
/**
 * Join and placement request rules shared by the admin inbox and roster changes.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnose, review and reconcile join requests.
 *
 * A request (`lf_join_request`) is either a request to join a named team
 * (`lf_team_id` > 0) or a placement request (`lf_team_id` = 0). Business status
 * lives in `lf_request_status`: pending, approved, declined or dismissed. Any
 * other stored value (including none) is kept as-is and flagged for review.
 * Every reviewed change appends an entry to the protected `_lf_request_history`
 * meta.
 *
 * Nothing here runs on activation, upgrade, import or page view. Requests only
 * change when a person takes a reviewed action, or when an authorized person
 * adds a roster membership through an explicit entry point that calls
 * reconcile_roster_additions() (admin player save, portal roster add, portal
 * join review, an inbox approval). The generic membership setters never
 * reconcile, so migrations, demo data and rollbacks leave requests untouched.
 */
class Join_Request_Service {

	const HISTORY_META = '_lf_request_history';

	/**
	 * Team statuses that can hold a roster. Trashed teams cannot. Drafts and
	 * scheduled teams are allowed for staff who can edit them, with a warning,
	 * because a draft team may be a legitimate team that is not public yet.
	 */
	const ROSTER_TEAM_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Post statuses of a request record that can be reviewed. A request in the
	 * Trash, an auto-draft or any other state is never changed by a review.
	 */
	const REQUEST_POST_STATUSES = array( 'publish', 'private', 'pending', 'draft', 'future' );

	/**
	 * Shared instance used by every entry point (plugin hooks, admin, portal).
	 *
	 * @var Join_Request_Service|null
	 */
	protected static $shared = null;

	/**
	 * Whether the reconciliation hook is registered (once per request).
	 *
	 * @var bool
	 */
	protected static $registered = false;

	/**
	 * The shared service instance.
	 *
	 * @return Join_Request_Service
	 */
	public static function shared() {
		if ( null === self::$shared ) {
			self::$shared = new self();
		}

		return self::$shared;
	}

	/**
	 * Register hooks once, whichever instance asks.
	 *
	 * @return void
	 */
	public function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		self::$shared     = $this;
		add_action( 'leagueflow_roster_memberships_added', array( $this, 'handle_roster_additions' ), 10, 3 );
	}

	/**
	 * Business statuses with labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'pending'   => __( 'Pending', 'leagueflow' ),
			'approved'  => __( 'Approved', 'leagueflow' ),
			'declined'  => __( 'Declined', 'leagueflow' ),
			'dismissed' => __( 'Dismissed', 'leagueflow' ),
		);
	}

	/**
	 * Label for a status, including unknown legacy values.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = self::statuses();

		return $labels[ $status ] ?? __( 'Needs review (unknown status)', 'leagueflow' );
	}

	/**
	 * Whether the current user may review requests at all, and this one.
	 *
	 * @param int $request_id Request ID (0 for the inbox as a whole).
	 * @return bool
	 */
	public function current_user_can_review( $request_id = 0 ) {
		if ( ! current_user_can( 'leagueflow_manage_placements' ) ) {
			return false;
		}

		return ! $request_id || current_user_can( 'edit_post', absint( $request_id ) );
	}

	/**
	 * Read a request's facts and every problem that affects review.
	 *
	 * Flags:
	 * - status_unknown: the stored status is empty or not a known value.
	 * - missing_player: the player record is gone or is not a player.
	 * - team_missing: a named team ID points at nothing (or not a team).
	 * - team_trashed: the named team is in the Trash.
	 * - team_not_public: the named team is a draft/pending/scheduled record (a warning).
	 * - sport_mismatch / level_mismatch: the named team is in another sport or level.
	 * - sport_missing: the request has no sport.
	 * - already_member: the player is already on the requested team.
	 * - has_team_in_sport: the player is already on another team in this sport.
	 * - membership_conflict: placement request, but the player's team(s) in the
	 *   sport do not give exactly one match for the requested level.
	 *
	 * @param int $request_id Request ID.
	 * @return array<string, mixed>|null Null when the ID is not a join request.
	 */
	public function inspect( $request_id ) {
		$request = get_post( absint( $request_id ) );

		if ( ! $request instanceof \WP_Post || 'lf_join_request' !== $request->post_type ) {
			return null;
		}

		$player_id  = absint( get_post_meta( $request->ID, 'lf_player_id', true ) );
		$team_id    = absint( get_post_meta( $request->ID, 'lf_team_id', true ) );
		$sport_slug = sanitize_key( (string) get_post_meta( $request->ID, 'lf_sport_slug', true ) );
		$level_id   = get_join_request_level_id( $request->ID );
		$raw_status = (string) get_post_meta( $request->ID, 'lf_request_status', true );
		$status     = isset( self::statuses()[ $raw_status ] ) ? $raw_status : 'unknown';
		$player     = $player_id ? get_post( $player_id ) : null;
		$has_player = $player instanceof \WP_Post && 'lf_player' === $player->post_type && 'trash' !== $player->post_status;
		$team       = $team_id ? get_post( $team_id ) : null;
		$is_team    = $team instanceof \WP_Post && 'lf_team' === $team->post_type;
		$flags      = array();

		if ( ! in_array( $request->post_status, self::REQUEST_POST_STATUSES, true ) ) {
			$flags[] = 'request_invalid';
		}

		if ( 'unknown' === $status ) {
			$flags[] = 'status_unknown';
		}

		if ( ! $has_player ) {
			$flags[] = 'missing_player';
		}

		if ( '' === $sport_slug ) {
			$flags[] = 'sport_missing';
		}

		if ( $has_player && ! Registration_Consent::is_current( $player_id ) ) {
			$flags[] = 'consent_pending';
		}

		if ( $team_id ) {
			if ( ! $is_team ) {
				$flags[] = 'team_missing';
			} elseif ( 'trash' === $team->post_status ) {
				$flags[] = 'team_trashed';
			} elseif ( 'publish' !== $team->post_status && 'private' !== $team->post_status ) {
				$flags[] = 'team_not_public';
			}

			if ( $is_team ) {
				if ( $sport_slug && get_post_primary_term_slug( $team->ID, 'lf_sport' ) !== $sport_slug ) {
					$flags[] = 'sport_mismatch';
				}

				if ( $level_id && get_post_primary_term_id( $team->ID, 'lf_league_level' ) !== $level_id ) {
					$flags[] = 'level_mismatch';
				}

				if ( $has_player && player_has_team( $player_id, $team->ID ) ) {
					$flags[] = 'already_member';
				}
			}
		}

		$sport_team_ids    = $has_player ? $this->get_player_team_ids_in_sport( $player_id, $sport_slug, $team_id ) : array();
		$matching_team_ids = array();

		if ( $sport_team_ids ) {
			$flags[] = 'has_team_in_sport';
		}

		if ( ! $team_id && $sport_team_ids ) {
			$matching_team_ids = $this->filter_teams_for_request( $sport_team_ids, $sport_slug, $level_id );

			if ( 1 !== count( $matching_team_ids ) ) {
				$flags[] = 'membership_conflict';
			}
		}

		return array(
			'id'                => (int) $request->ID,
			'title'             => $request->post_title,
			'post_status'       => $request->post_status,
			'date_gmt'          => $request->post_date_gmt,
			'type'              => $team_id ? 'team' : 'placement',
			'status'            => $status,
			'raw_status'        => $raw_status,
			'player_id'         => $player_id,
			'has_player'        => $has_player,
			'team_id'           => $team_id,
			'team_status'       => $is_team ? $team->post_status : '',
			'sport_slug'        => $sport_slug,
			'level_id'          => $level_id,
			'note'              => (string) get_post_meta( $request->ID, 'lf_request_note', true ),
			'flags'             => $flags,
			'sport_team_ids'    => $sport_team_ids,
			'matching_team_ids' => $matching_team_ids,
			'history_count'     => count( $this->get_history( $request->ID ) ),
		);
	}

	/**
	 * Fingerprint of the state a reviewer saw. A form submitted after the
	 * request changed (another reviewer, a roster change) is refused.
	 *
	 * @param array<string, mixed> $info inspect() result.
	 * @return string
	 */
	public function state_token( $info ) {
		return substr( hash( 'sha256', implode( '|', array( $info['id'], $info['raw_status'], $info['team_id'], $info['player_id'], $info['sport_slug'], $info['level_id'], $info['history_count'] ) ) ), 0, 16 );
	}

	/**
	 * Human-readable explanation for each flag.
	 *
	 * @return array<string, string>
	 */
	public static function flag_labels() {
		return array(
			'request_invalid'     => __( 'This request record is in the Trash or is not a saved record: it cannot be reviewed', 'leagueflow' ),
			'status_unknown'      => __( 'Status is missing or not recognised: review before deciding', 'leagueflow' ),
			'consent_pending'     => __( 'The player must acknowledge the current registration documents in their portal before being added to a team', 'leagueflow' ),
			'missing_player'      => __( 'Player record missing', 'leagueflow' ),
			'sport_missing'       => __( 'Request has no sport', 'leagueflow' ),
			'team_missing'        => __( 'Requested team no longer exists', 'leagueflow' ),
			'team_trashed'        => __( 'Requested team is in the Trash', 'leagueflow' ),
			'team_not_public'     => __( 'Requested team is a draft (not public yet)', 'leagueflow' ),
			'sport_mismatch'      => __( 'Requested team is in another sport', 'leagueflow' ),
			'level_mismatch'      => __( 'Requested team is in another level', 'leagueflow' ),
			'already_member'      => __( 'Already on the requested team', 'leagueflow' ),
			'has_team_in_sport'   => __( 'Already on another team in this sport', 'leagueflow' ),
			'membership_conflict' => __( 'Current team in this sport does not match the requested level (or there is more than one)', 'leagueflow' ),
		);
	}

	/**
	 * Flags that are warnings only and do not need attention by themselves.
	 *
	 * @return array<int, string>
	 */
	public static function warning_flags() {
		return array( 'team_not_public' );
	}

	/**
	 * Actions a reviewer may take on a request in its current state.
	 *
	 * @param array<string, mixed> $info inspect() result.
	 * @return array<int, string>
	 */
	public function available_actions( $info ) {
		if ( ! $info ) {
			return array();
		}

		$flags = $info['flags'];

		if ( in_array( 'request_invalid', $flags, true ) ) {
			return array();
		}

		if ( 'unknown' === $info['status'] ) {
			// A legacy or damaged status is never treated as a normal pending
			// request. Without a player it can only be dismissed.
			return in_array( 'missing_player', $flags, true ) ? array( 'dismiss' ) : array( 'reopen', 'dismiss' );
		}

		if ( 'pending' !== $info['status'] ) {
			return array();
		}

		if ( in_array( 'missing_player', $flags, true ) || in_array( 'sport_missing', $flags, true ) ) {
			return array( 'dismiss' );
		}

		$actions = array();

		if ( 'team' === $info['type'] ) {
			$target_broken = (bool) array_intersect( array( 'team_missing', 'team_trashed', 'sport_mismatch', 'level_mismatch' ), $flags );

			if ( ! $target_broken && in_array( 'already_member', $flags, true ) ) {
				$actions[] = 'resolve_member';
			} elseif ( ! $target_broken && ! in_array( 'has_team_in_sport', $flags, true ) ) {
				$actions[] = 'approve';
			}

			if ( $target_broken ) {
				$actions[] = 'move_to_placement';
			}

			$actions[] = 'decline';
		} elseif ( in_array( 'has_team_in_sport', $flags, true ) ) {
			if ( ! in_array( 'membership_conflict', $flags, true ) ) {
				$actions[] = 'resolve_member';
			}

			$actions[] = 'decline';
		} else {
			$actions[] = 'assign';
			$actions[] = 'decline';
		}

		$actions[] = 'dismiss';

		if ( in_array( 'consent_pending', $flags, true ) ) {
			$actions = array_values( array_diff( $actions, array( 'approve', 'assign' ) ) );
		}

		return $actions;
	}

	/**
	 * Teams a placement request may be assigned to: same sport, same level when
	 * the request names one, not in the Trash, and editable by the current user.
	 * Drafts are included for staff who can edit them (marked in the UI).
	 *
	 * @param array<string, mixed> $info inspect() result.
	 * @return array<int, \WP_Post>
	 */
	public function get_assignable_teams( $info ) {
		if ( ! $info || '' === $info['sport_slug'] ) {
			return array();
		}

		$tax_query = array(
			array(
				'taxonomy' => 'lf_sport',
				'field'    => 'slug',
				'terms'    => array( $info['sport_slug'] ),
			),
		);

		if ( $info['level_id'] ) {
			$tax_query[] = array(
				'taxonomy' => 'lf_league_level',
				'field'    => 'term_id',
				'terms'    => array( (int) $info['level_id'] ),
			);
		}

		$teams = get_posts(
			array(
				'post_type'        => 'lf_team',
				'post_status'      => self::ROSTER_TEAM_STATUSES,
				'perm'             => 'editable',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'tax_query'        => $tax_query,
				'suppress_filters' => false,
			)
		);

		return array_values(
			array_filter(
				$teams,
				function ( $team ) use ( $info ) {
					return $this->team_fits_request( $team->ID, $info['sport_slug'], $info['level_id'] ) && current_user_can( 'edit_post', $team->ID );
				}
			)
		);
	}

	/**
	 * Apply a reviewed action. Authority and the request record are checked
	 * first, then again under a per-request lock after the caches are cleared,
	 * together with the state and the reviewer's state token. A double click,
	 * a second reviewer or a stale form changes nothing.
	 *
	 * The state token is required whenever `state` is passed (HTTP forms always
	 * pass it, so an empty or omitted token there is refused). Programmatic
	 * callers that omit the `state` key entirely still get every other check.
	 *
	 * @param int                  $request_id Request ID.
	 * @param string               $action approve, assign, decline, dismiss, resolve_member, move_to_placement or reopen.
	 * @param array<string, mixed> $args team_id (assign), note, state (token from the form).
	 * @return true|\WP_Error
	 */
	public function apply_action( $request_id, $action, $args = array() ) {
		$request_id = absint( $request_id );
		$authority  = function () use ( $request_id ) {
			return $this->current_user_can_review( $request_id );
		};

		if ( ! $this->is_reviewable_record( $request_id ) ) {
			return new \WP_Error( 'leagueflow_request_missing', __( 'That request no longer exists.', 'leagueflow' ) );
		}

		if ( ! $authority() ) {
			return new \WP_Error( 'leagueflow_request_denied', __( 'You cannot review this request.', 'leagueflow' ) );
		}

		$note = sanitize_textarea_field( (string) ( $args['note'] ?? '' ) );

		if ( in_array( $action, array( 'decline', 'dismiss', 'move_to_placement', 'reopen' ), true ) && '' === trim( $note ) ) {
			return new \WP_Error( 'leagueflow_request_reason', __( 'Add a short reason so the history explains this decision.', 'leagueflow' ) );
		}

		return $this->with_fresh_request(
			$request_id,
			$authority,
			$args,
			function ( $info ) use ( $action, $args, $note ) {
				if ( ! in_array( $action, $this->available_actions( $info ), true ) ) {
					return new \WP_Error( 'leagueflow_request_state', __( 'That action is not available for this request any more. It may already have been reviewed.', 'leagueflow' ) );
				}

				return $this->apply_locked( $info, $action, $args, $note );
			}
		);
	}

	/**
	 * A team manager's portal decision on a request for a team they manage.
	 *
	 * Uses the same lock, fresh re-read and state token as the staff inbox, so
	 * a captain and staff (or two captains, or a double click) cannot both
	 * decide. Captains keep their owned-team authority through $can_manage;
	 * this grants no inbox access. Placement requests are staff-only.
	 *
	 * @param int      $request_id Request ID.
	 * @param string   $decision approve or decline.
	 * @param int      $team_id Team the manager acts for.
	 * @param int      $user_id Acting user (recorded in the history).
	 * @param string   $state State token from the portal form (required).
	 * @param callable $can_manage Callback( $team_id ): whether the user manages the team.
	 * @return true|\WP_Error
	 */
	public function apply_team_manager_decision( $request_id, $decision, $team_id, $user_id, $state, callable $can_manage ) {
		$request_id = absint( $request_id );
		$team_id    = absint( $team_id );

		if ( ! in_array( $decision, array( 'approve', 'decline' ), true ) ) {
			return new \WP_Error( 'leagueflow_request_state', __( 'Unknown decision.', 'leagueflow' ) );
		}

		$authority = static function () use ( $can_manage, $team_id ) {
			return $team_id && (bool) call_user_func( $can_manage, $team_id );
		};

		return $this->with_fresh_request(
			$request_id,
			$authority,
			array( 'state' => (string) $state ),
			function ( $info ) use ( $decision, $team_id, $user_id ) {
				if ( 'pending' !== $info['status'] || 'team' !== $info['type'] || (int) $info['team_id'] !== $team_id || ! $info['has_player'] ) {
					return new \WP_Error( 'leagueflow_request_state', __( 'That request was already reviewed or changed.', 'leagueflow' ) );
				}

				// Older portal requests may lack a stored sport; the portal has always
				// read those as the managed team's sport.
				$sport_slug = '' !== $info['sport_slug'] ? $info['sport_slug'] : get_post_primary_term_slug( $team_id, 'lf_sport' );

				if ( ! $this->team_fits_request( $team_id, $sport_slug, $info['level_id'] ) || 'trash' === $info['team_status'] ) {
					return new \WP_Error( 'leagueflow_request_team', __( 'The team is not eligible for this request.', 'leagueflow' ) );
				}

				if ( 'decline' === $decision ) {
					update_post_meta( $info['id'], 'lf_request_status', 'declined' );
					$this->add_history( $info['id'], 'declined', __( 'Declined by a team manager in the portal.', 'leagueflow' ), $team_id, $user_id );
					return true;
				}

				if ( $this->get_player_team_ids_in_sport( $info['player_id'], $sport_slug, $team_id ) ) {
					return new \WP_Error( 'leagueflow_request_member', __( 'The player is already on another team in this sport.', 'leagueflow' ) );
				}

				if ( ! Registration_Consent::is_current( $info['player_id'] ) ) {
					return new \WP_Error( 'leagueflow_consent_pending', __( 'The player needs to acknowledge the current registration documents first.', 'leagueflow' ) );
				}

				$before = get_player_team_ids( $info['player_id'] );

				if ( ! assign_player_to_team( $info['player_id'], $team_id ) ) {
					return new \WP_Error( 'leagueflow_request_failed', __( 'The player could not be added to the team.', 'leagueflow' ) );
				}

				update_post_meta( $info['id'], 'lf_request_status', 'approved' );
				update_post_meta( $info['id'], 'lf_team_id', $team_id );
				$this->add_history( $info['id'], 'approved', __( 'Approved by a team manager in the portal.', 'leagueflow' ), $team_id, $user_id );
				$this->reconcile_new_memberships( $info['player_id'], array_values( array_diff( get_player_team_ids( $info['player_id'] ), $before ) ), 'portal_review', $info['id'] );

				return true;
			}
		);
	}

	/**
	 * Run a decision on a freshly re-read request under its lock.
	 *
	 * After the lock is taken the caches are cleared and the record, its post
	 * state, the caller's authority and (when given) the state token are all
	 * checked again before $apply may write.
	 *
	 * @param int                  $request_id Request ID.
	 * @param callable             $authority Returns whether the caller may decide.
	 * @param array<string, mixed> $args May contain state.
	 * @param callable             $apply Callback( $info ) returning true|\WP_Error.
	 * @return true|\WP_Error
	 */
	protected function with_fresh_request( $request_id, callable $authority, $args, callable $apply ) {
		if ( ! $this->lock( $request_id ) ) {
			return new \WP_Error( 'leagueflow_request_busy', __( 'Someone else is updating this request, or the request lock is unavailable. Nothing was changed; reload and try again.', 'leagueflow' ) );
		}

		try {
			$this->flush_request_cache( $request_id );

			if ( ! $this->is_reviewable_record( $request_id ) ) {
				return new \WP_Error( 'leagueflow_request_missing', __( 'That request no longer exists.', 'leagueflow' ) );
			}

			if ( ! $authority() ) {
				return new \WP_Error( 'leagueflow_request_denied', __( 'You cannot review this request.', 'leagueflow' ) );
			}

			$info = $this->inspect( $request_id );

			if ( ! $info ) {
				return new \WP_Error( 'leagueflow_request_missing', __( 'That request no longer exists.', 'leagueflow' ) );
			}

			if ( array_key_exists( 'state', $args ) ) {
				$state = (string) $args['state'];

				if ( '' === $state || ! hash_equals( $this->state_token( $info ), $state ) ) {
					return new \WP_Error( 'leagueflow_request_state', __( 'That request changed since you opened it. Review it again.', 'leagueflow' ) );
				}
			}

			return $apply( $info );
		} finally {
			$this->unlock( $request_id );
		}
	}

	/**
	 * Whether an ID is a join request record in a reviewable post state.
	 *
	 * @param int $request_id Request ID.
	 * @return bool
	 */
	public function is_reviewable_record( $request_id ) {
		$request = get_post( absint( $request_id ) );

		return $request instanceof \WP_Post && 'lf_join_request' === $request->post_type && in_array( $request->post_status, self::REQUEST_POST_STATUSES, true );
	}

	/**
	 * Apply an action whose state was verified under the lock.
	 *
	 * @param array<string, mixed> $info Fresh inspect() result.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args Arguments.
	 * @param string               $note Note.
	 * @return true|\WP_Error
	 */
	protected function apply_locked( $info, $action, $args, $note ) {
		$user_id = get_current_user_id();

		switch ( $action ) {
			case 'approve':
				return $this->add_to_team( $info, $info['team_id'], 'approved', $note );

			case 'assign':
				$team_id = absint( $args['team_id'] ?? 0 );

				if ( ! in_array( $team_id, array_map( 'intval', wp_list_pluck( $this->get_assignable_teams( $info ), 'ID' ) ), true ) ) {
					return new \WP_Error( 'leagueflow_request_team', __( 'Choose a team in the requested sport and level.', 'leagueflow' ) );
				}

				return $this->add_to_team( $info, $team_id, 'assigned', $note );

			case 'resolve_member':
				if ( 'team' === $info['type'] ) {
					$team_id = $info['team_id'];
				} else {
					// Only an unambiguous membership in the requested sport and level closes a placement.
					$team_id = 1 === count( $info['matching_team_ids'] ) ? (int) $info['matching_team_ids'][0] : 0;
				}

				if ( ! $team_id || ! player_has_team( $info['player_id'], $team_id ) || ! $this->team_fits_request( $team_id, $info['sport_slug'], $info['level_id'] ) ) {
					return new \WP_Error( 'leagueflow_request_member', __( 'The player is not on a team that matches this request.', 'leagueflow' ) );
				}

				update_post_meta( $info['id'], 'lf_request_status', 'approved' );

				if ( 'placement' === $info['type'] ) {
					update_post_meta( $info['id'], 'lf_team_id', $team_id );
					update_post_meta( $info['id'], 'lf_placed_from_queue', 1 );
				}

				$this->add_history( $info['id'], 'resolved_member', $note ? $note : __( 'Closed: the player was already on this team. The roster was not changed.', 'leagueflow' ), $team_id, $user_id );
				return true;

			case 'decline':
				update_post_meta( $info['id'], 'lf_request_status', 'declined' );
				$this->add_history( $info['id'], 'declined', $note, $info['team_id'], $user_id );
				return true;

			case 'dismiss':
				if ( 'unknown' === $info['status'] ) {
					// Keep the unrecognised stored value; the history names it too.
					if ( '' !== $info['raw_status'] ) {
						update_post_meta( $info['id'], 'lf_previous_request_status', sanitize_text_field( $info['raw_status'] ) );
					}

					/* translators: 1: stored status, 2: reviewer note */
					$note = sprintf( __( 'Stored status "%1$s" reviewed and dismissed. %2$s', 'leagueflow' ), '' === $info['raw_status'] ? __( '(none)', 'leagueflow' ) : sanitize_text_field( $info['raw_status'] ), $note );
				}

				update_post_meta( $info['id'], 'lf_request_status', 'dismissed' );
				$this->add_history( $info['id'], 'dismissed', $note, $info['team_id'], $user_id );
				return true;

			case 'reopen':
				// Keep the unrecognised value in the history, then treat it as pending.
				if ( '' !== $info['raw_status'] ) {
					update_post_meta( $info['id'], 'lf_previous_request_status', sanitize_text_field( $info['raw_status'] ) );
				}

				update_post_meta( $info['id'], 'lf_request_status', 'pending' );
				/* translators: 1: stored status, 2: reviewer note */
				$this->add_history( $info['id'], 'reopened', sprintf( __( 'Stored status "%1$s" reviewed and reopened as pending. %2$s', 'leagueflow' ), '' === $info['raw_status'] ? __( '(none)', 'leagueflow' ) : sanitize_text_field( $info['raw_status'] ), $note ), $info['team_id'], $user_id );
				return true;

			case 'move_to_placement':
				// Keep the original target; the request stays pending in the placement queue.
				update_post_meta( $info['id'], 'lf_original_team_id', $info['team_id'] );
				update_post_meta( $info['id'], 'lf_team_id', 0 );
				update_post_meta( $info['id'], 'lf_request_type', 'placement' );
				$this->add_history( $info['id'], 'moved_to_placement', $note, $info['team_id'], $user_id );
				return true;
		}

		return new \WP_Error( 'leagueflow_request_state', __( 'Unknown action.', 'leagueflow' ) );
	}

	/**
	 * Add the request's player to a team and close the request.
	 *
	 * @param array<string, mixed> $info inspect() result.
	 * @param int                  $team_id Team ID.
	 * @param string               $event History event.
	 * @param string               $note Note.
	 * @return true|\WP_Error
	 */
	protected function add_to_team( $info, $team_id, $event, $note ) {
		if ( ! $this->team_fits_request( $team_id, $info['sport_slug'], $info['level_id'] ) ) {
			return new \WP_Error( 'leagueflow_request_team', __( 'The team is not eligible for this request.', 'leagueflow' ) );
		}

		if ( ! current_user_can( 'edit_post', $team_id ) || ! current_user_can( 'edit_post', $info['player_id'] ) ) {
			return new \WP_Error( 'leagueflow_request_denied', __( 'You cannot change this team or player.', 'leagueflow' ) );
		}

		if ( $this->get_player_team_ids_in_sport( $info['player_id'], $info['sport_slug'], $team_id ) ) {
			return new \WP_Error( 'leagueflow_request_member', __( 'The player is already on another team in this sport.', 'leagueflow' ) );
		}

		$before = get_player_team_ids( $info['player_id'] );

		if ( ! assign_player_to_team( $info['player_id'], $team_id ) ) {
			return new \WP_Error( 'leagueflow_request_failed', __( 'The player could not be added to the team.', 'leagueflow' ) );
		}

		update_post_meta( $info['id'], 'lf_team_id', $team_id );
		update_post_meta( $info['id'], 'lf_request_status', 'approved' );

		if ( 'placement' === $info['type'] ) {
			update_post_meta( $info['id'], 'lf_placed_from_queue', 1 );
		}

		$this->add_history( $info['id'], $event, $note, $team_id, get_current_user_id() );

		// The approval is itself an authorized roster change: close the player's
		// other matching pending requests, but never this one a second time.
		$this->reconcile_new_memberships( $info['player_id'], array_values( array_diff( get_player_team_ids( $info['player_id'] ), $before ) ), 'request_review', $info['id'] );

		return true;
	}

	/**
	 * Hook target for leagueflow_roster_memberships_added (explicit entry points only).
	 *
	 * @param int                  $player_id Player ID.
	 * @param array<int>           $team_ids Newly added team IDs.
	 * @param array<string, mixed> $context source and exclude_request_id.
	 * @return void
	 */
	public function handle_roster_additions( $player_id, $team_ids, $context = array() ) {
		$context = is_array( $context ) ? $context : array();
		$this->reconcile_new_memberships( $player_id, $team_ids, (string) ( $context['source'] ?? 'roster' ), absint( $context['exclude_request_id'] ?? 0 ) );
	}

	/**
	 * Close the player's pending requests that a new membership satisfies.
	 *
	 * A named request matches only when its team is the new team AND its sport
	 * and level (when stored) equal the team's. A placement request matches
	 * when its sport equals the team's and its level (when stored) equals the
	 * team's. Unknown statuses, requests without a sport, and other players'
	 * requests are never touched. Idempotent: a closed request never changes.
	 *
	 * @param int        $player_id Player ID.
	 * @param array<int> $team_ids Newly added team IDs.
	 * @param string     $source Entry point, kept in the history.
	 * @param int        $exclude_request_id Request being reviewed (already recorded).
	 * @return array<int, int> Closed request IDs.
	 */
	public function reconcile_new_memberships( $player_id, $team_ids, $source = 'roster', $exclude_request_id = 0 ) {
		$player_id = absint( $player_id );
		$closed    = array();

		if ( ! $player_id || 'lf_player' !== get_post_type( $player_id ) ) {
			return $closed;
		}

		foreach ( array_unique( array_map( 'absint', (array) $team_ids ) ) as $team_id ) {
			$team = $team_id ? get_post( $team_id ) : null;

			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || 'trash' === $team->post_status || ! player_has_team( $player_id, $team_id ) ) {
				continue;
			}

			$sport_slug = get_post_primary_term_slug( $team_id, 'lf_sport' );
			$level_id   = get_post_primary_term_id( $team_id, 'lf_league_level' );

			if ( '' === $sport_slug ) {
				continue;
			}

			$candidates = get_posts(
				array(
					'post_type'      => 'lf_join_request',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => 'lf_player_id',
							'value' => $player_id,
						),
						array(
							'key'   => 'lf_request_status',
							'value' => 'pending',
						),
						array(
							'key'   => 'lf_sport_slug',
							'value' => $sport_slug,
						),
					),
				)
			);

			foreach ( $candidates as $request_id ) {
				$request_id = (int) $request_id;

				if ( $request_id === (int) $exclude_request_id || in_array( $request_id, $closed, true ) || ! $this->lock( $request_id ) ) {
					continue;
				}

				try {
					$this->flush_request_cache( $request_id );

					if ( ! $this->is_reviewable_record( $request_id ) || 'pending' !== (string) get_post_meta( $request_id, 'lf_request_status', true ) || absint( get_post_meta( $request_id, 'lf_player_id', true ) ) !== $player_id ) {
						continue;
					}

					$request_team  = absint( get_post_meta( $request_id, 'lf_team_id', true ) );
					$request_sport = sanitize_key( (string) get_post_meta( $request_id, 'lf_sport_slug', true ) );
					$request_level = get_join_request_level_id( $request_id );
					$satisfies     = $request_sport === $sport_slug
						&& ( ! $request_level || $request_level === $level_id )
						&& ( ! $request_team || $request_team === $team_id );

					if ( ! $satisfies ) {
						continue;
					}

					update_post_meta( $request_id, 'lf_request_status', 'approved' );

					if ( ! $request_team ) {
						update_post_meta( $request_id, 'lf_team_id', $team_id );
						update_post_meta( $request_id, 'lf_placed_from_queue', 1 );
					}

					/* translators: %s: where the roster change was made */
					$this->add_history( $request_id, 'reconciled', sprintf( __( 'Closed automatically: the player was added to this team (%s).', 'leagueflow' ), $this->source_label( $source ) ), $team_id, get_current_user_id() );
					$closed[] = $request_id;
				} finally {
					$this->unlock( $request_id );
				}
			}
		}

		return $closed;
	}

	/**
	 * Readable name of a reconciliation entry point.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	protected function source_label( $source ) {
		$labels = array(
			'admin_player'   => __( 'player edit screen', 'leagueflow' ),
			'portal_roster'  => __( 'team manager roster', 'leagueflow' ),
			'portal_review'  => __( 'team manager request review', 'leagueflow' ),
			'request_review' => __( 'request review', 'leagueflow' ),
		);

		return $labels[ $source ] ?? __( 'roster change', 'leagueflow' );
	}

	/**
	 * Append a history entry.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event Event key.
	 * @param string $note Note.
	 * @param int    $team_id Team involved.
	 * @param int    $user_id Acting user.
	 * @return void
	 */
	public function add_history( $request_id, $event, $note, $team_id, $user_id ) {
		$history   = $this->get_history( $request_id );
		$history[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'event'   => sanitize_key( $event ),
			'note'    => sanitize_textarea_field( (string) $note ),
			'team_id' => absint( $team_id ),
			'user_id' => absint( $user_id ),
		);

		update_post_meta( absint( $request_id ), self::HISTORY_META, $history );
	}

	/**
	 * Read a request's history.
	 *
	 * @param int $request_id Request ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_history( $request_id ) {
		$history = get_post_meta( absint( $request_id ), self::HISTORY_META, true );

		return is_array( $history ) ? array_values( array_filter( $history, 'is_array' ) ) : array();
	}

	/**
	 * Whether a team can take this request's player: exists, not trashed, holds
	 * rosters, and matches the request's sport and (when set) level.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $sport_slug Sport.
	 * @param int    $level_id Level (0 = any).
	 * @return bool
	 */
	public function team_fits_request( $team_id, $sport_slug, $level_id ) {
		$team = get_post( absint( $team_id ) );

		return $team instanceof \WP_Post
			&& 'lf_team' === $team->post_type
			&& in_array( $team->post_status, self::ROSTER_TEAM_STATUSES, true )
			&& '' !== (string) $sport_slug
			&& get_post_primary_term_slug( $team->ID, 'lf_sport' ) === $sport_slug
			&& ( ! $level_id || get_post_primary_term_id( $team->ID, 'lf_league_level' ) === (int) $level_id );
	}

	/**
	 * Teams from a list that satisfy a request's sport and level.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @param string     $sport_slug Sport.
	 * @param int        $level_id Level.
	 * @return array<int, int>
	 */
	protected function filter_teams_for_request( $team_ids, $sport_slug, $level_id ) {
		return array_values(
			array_filter(
				array_map( 'intval', $team_ids ),
				function ( $team_id ) use ( $sport_slug, $level_id ) {
					return $this->team_fits_request( $team_id, $sport_slug, $level_id );
				}
			)
		);
	}

	/**
	 * Teams the player is on in a sport, except one team.
	 *
	 * @param int    $player_id Player ID.
	 * @param string $sport_slug Sport slug.
	 * @param int    $except_team_id Team to ignore.
	 * @return array<int, int>
	 */
	protected function get_player_team_ids_in_sport( $player_id, $sport_slug, $except_team_id = 0 ) {
		if ( '' === $sport_slug ) {
			return array();
		}

		$found = array();

		foreach ( get_player_team_ids( $player_id ) as $team_id ) {
			$team = get_post( $team_id );

			if ( (int) $team_id === (int) $except_team_id || ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || 'trash' === $team->post_status ) {
				continue;
			}

			if ( get_post_primary_term_slug( $team_id, 'lf_sport' ) === $sport_slug ) {
				$found[] = (int) $team_id;
			}
		}

		return $found;
	}

	/**
	 * Drop cached meta so a decision is based on the stored state.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	protected function flush_request_cache( $request_id ) {
		wp_cache_delete( absint( $request_id ), 'post_meta' );
		clean_post_cache( absint( $request_id ) );
	}

	/**
	 * Take a per-request database lock (MySQL/MariaDB GET_LOCK). Fails closed:
	 * a timeout or an unavailable lock service means no change is made.
	 *
	 * @param int $request_id Request ID.
	 * @return bool
	 */
	protected function lock( $request_id ) {
		/**
		 * Seconds to wait for another reviewer to finish with a request.
		 *
		 * @param int $timeout Seconds.
		 */
		$timeout = max( 0, (int) apply_filters( 'leagueflow_request_lock_timeout', 5 ) );

		return acquire_db_lock( $this->lock_name( $request_id ), $timeout );
	}

	/**
	 * Release a per-request lock.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	protected function unlock( $request_id ) {
		release_db_lock( $this->lock_name( $request_id ) );
	}

	/**
	 * Lock name, unique per site and request.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function lock_name( $request_id ) {
		return db_lock_name( 'req', (string) absint( $request_id ) );
	}
}
