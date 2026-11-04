<?php
/**
 * Referee desk: rosters, score entry and conflict flags for officials.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Officials hold the LeagueFlow Referee role, alone or next to a player role.
 * They can open every published match and team roster from the front-end
 * Referee desk and enter results through the shared Result_Service. They never
 * receive general post, user or roster editing permissions.
 *
 * Assigning a referee to a match is optional. A referee who plays for either
 * team is flagged on the match, the Results screen and the desk, and can only
 * save a score for staff to publish.
 */
class Referee {
	const ASSIGNMENT_META = '_lf_referee_user_id';
	/** Legacy per-match score switch. Score entry is now open to every referee. */
	const SCORE_META  = '_lf_referee_scores_enabled';
	const AUDIT_META  = '_lf_referee_result';
	const PAGE        = 'leagueflow-referee';
	const PAGE_OPTION = 'leagueflow_referee_page_id';
	const ROLE        = 'leagueflow_referee';
	const VIEW_CAP    = 'leagueflow_view_assigned_rosters';
	const SCORE_CAP   = 'leagueflow_submit_assigned_scores';
	const MANAGE_CAP  = 'leagueflow_manage_referees';

	/** Games listed per desk section or search page. */
	const PER_PAGE = 20;

	/** @var Renderer|null */
	protected $renderer;

	/** @var Result_Service|null */
	protected $results;

	/** @var array<int, int[]> Teams each user plays for, per request. */
	protected static $team_cache = array();

	public function __construct( $renderer = null, $results = null ) {
		$this->renderer = $renderer instanceof Renderer ? $renderer : null;
		$this->results  = $results instanceof Result_Service ? $results : null;
	}

	public function register() {
		add_action( 'init', array( __CLASS__, 'ensure_roles' ), 2 );
		add_action( 'init', array( $this, 'maybe_create_desk_page' ), 100 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'add_meta_boxes_lf_match', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_lf_match', array( $this, 'save_assignments' ), 20, 2 );
		add_action( 'admin_post_leagueflow_referee_result', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_leagueflow_referee_access', array( $this, 'handle_access_change' ) );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_shortcode( 'leagueflow_referee', array( $this, 'render' ) );
	}

	public static function ensure_roles() {
		add_role( self::ROLE, __( 'LeagueFlow Referee', 'leagueflow' ), array( 'read' => true, self::VIEW_CAP => true, self::SCORE_CAP => true ) );
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::MANAGE_CAP ) ) {
			$admin->add_cap( self::MANAGE_CAP );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Desk page                                                           */
	/* ------------------------------------------------------------------ */

	/** Create the front-end Referee desk page once. */
	public function maybe_create_desk_page() {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( $page_id && get_post( $page_id ) instanceof \WP_Post ) {
			return;
		}
		$page = get_page_by_path( 'referee-desk', OBJECT, 'page' );
		if ( $page instanceof \WP_Post ) {
			update_option( self::PAGE_OPTION, $page->ID );
			return;
		}
		// One request creates the page; concurrent first requests do not duplicate it.
		if ( ! add_option( self::PAGE_OPTION . '_creating', time(), '', false ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Referee desk', 'leagueflow' ),
				'post_name'    => 'referee-desk',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:shortcode -->[leagueflow_referee]<!-- /wp:shortcode -->',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::PAGE_OPTION, absint( $page_id ) );
		}
		delete_option( self::PAGE_OPTION . '_creating' );
	}

	/** Public URL of the Referee desk. */
	public static function desk_url() {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** Whether the current (or given) user may open the Referee desk. */
	public static function can_use_desk( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		return $user_id && ( user_can( $user_id, self::VIEW_CAP ) || user_can( $user_id, self::MANAGE_CAP ) );
	}

	public function post_states( $states, $post ) {
		if ( $post instanceof \WP_Post && (int) $post->ID === absint( get_option( self::PAGE_OPTION ) ) ) {
			$states['leagueflow_referee'] = __( 'Referee desk', 'leagueflow' );
		}
		return $states;
	}

	/** The desk is a signed-in tool; keep it out of search results. */
	public function robots( $robots ) {
		$page_id = absint( get_option( self::PAGE_OPTION ) );
		if ( $page_id && is_page( $page_id ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/* ------------------------------------------------------------------ */
	/* Access, conflicts                                                   */
	/* ------------------------------------------------------------------ */

	public static function assigned_users( $match_id ) {
		return array_values( array_unique( array_filter( array_map( 'absint', get_post_meta( absint( $match_id ), self::ASSIGNMENT_META, false ) ) ) ) );
	}

	/** Home and away team IDs. */
	protected static function match_teams( $match_id ) {
		return array( absint( get_post_meta( $match_id, 'lf_home_team_id', true ) ), absint( get_post_meta( $match_id, 'lf_away_team_id', true ) ) );
	}

	/**
	 * Whether the current user may open a match on the desk, and with $score,
	 * enter its result. Referees see published matches between two published
	 * teams; staff who can edit the match see it too. Every read and write
	 * rechecks this.
	 */
	public static function can_access( $match_id, $score = false ) {
		$post = get_post( absint( $match_id ) );
		if ( ! is_user_logged_in() || ! $post instanceof \WP_Post || 'lf_match' !== $post->post_type || 'trash' === $post->post_status ) {
			return false;
		}
		$staff = current_user_can( 'edit_post', $post->ID );
		if ( ! $staff && ( ! current_user_can( self::VIEW_CAP ) || 'publish' !== $post->post_status || '' !== $post->post_password ) ) {
			return false;
		}
		$teams = self::match_teams( $post->ID );
		if ( ! $teams[0] || ! $teams[1] || $teams[0] === $teams[1] ) {
			return false;
		}
		foreach ( $teams as $team_id ) {
			$team = get_post( $team_id );
			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || ( ! $staff && ( 'publish' !== $team->post_status || '' !== $team->post_password ) ) ) {
				return false;
			}
		}
		if ( ! $score ) {
			return true;
		}
		if ( ! $staff && ! current_user_can( self::SCORE_CAP ) ) {
			return false;
		}
		return self::valid_result_context( $post->ID, $teams )
			&& ! in_array( (string) get_post_meta( $post->ID, 'lf_status', true ), array( 'postponed', 'cancelled' ), true );
	}

	/** Whether the current user may open a team roster on the desk. */
	public static function can_view_team( $team_id ) {
		$team = get_post( absint( $team_id ) );
		if ( ! self::can_use_desk() || ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
			return false;
		}
		return current_user_can( 'edit_post', $team->ID ) || ( 'publish' === $team->post_status && '' === $team->post_password );
	}

	/** Imported or reassigned participants must still match the fixture context. */
	public static function valid_result_context( $match_id, $teams ) {
		$sport = get_post_primary_term_id( $match_id, 'lf_sport' );
		$level = get_post_primary_term_id( $match_id, 'lf_league_level' );
		if ( ! $sport ) { return false; }
		$sport_term = get_term( $sport, 'lf_sport' );
		if ( ! $sport_term instanceof \WP_Term ) { return false; }
		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $match_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) { return false; }
			foreach ( $terms as $term_id ) {
				$owner = (string) get_term_meta( $term_id, 'lf_sport_slug', true );
				if ( $owner && $owner !== $sport_term->slug ) { return false; }
			}
		}
		$team_levels = array();
		foreach ( $teams as $team_id ) {
			if ( $sport !== get_post_primary_term_id( $team_id, 'lf_sport' ) ) { return false; }
			$team_level = get_post_primary_term_id( $team_id, 'lf_league_level' );
			if ( $level && $team_level && $level !== $team_level ) { return false; }
			if ( $team_level ) { $team_levels[] = $team_level; }
		}
		return count( array_unique( $team_levels ) ) <= 1;
	}

	/**
	 * Player profiles that belong to a user: linked to the account, or carrying
	 * its email (captain-added profiles are often not linked yet).
	 */
	public static function user_player_ids( $user_id ) {
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user instanceof \WP_User ) {
			return array();
		}
		$clauses = array( array( 'key' => 'lf_user_id', 'value' => (int) $user->ID ) );
		if ( '' !== (string) $user->user_email ) {
			$clauses[] = array( 'key' => 'lf_email', 'value' => strtolower( (string) $user->user_email ) );
		}
		return array_map(
			'absint',
			get_posts(
				array(
					'post_type'      => 'lf_player',
					'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array_merge( array( 'relation' => 'OR' ), $clauses ),
				)
			)
		);
	}

	/** Teams a user plays for. */
	public static function user_team_ids( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! isset( self::$team_cache[ $user_id ] ) ) {
			$teams = array();
			foreach ( self::user_player_ids( $user_id ) as $player_id ) {
				$teams = array_merge( $teams, get_player_team_ids( $player_id ) );
			}
			self::$team_cache[ $user_id ] = array_values( array_unique( array_map( 'absint', $teams ) ) );
		}
		return self::$team_cache[ $user_id ];
	}

	/** Forget cached team lists (after roster changes in the same request). */
	public static function flush_team_cache() {
		self::$team_cache = array();
	}

	/** The team a user plays for in a match, or 0. */
	public static function playing_team( $user_id, $match_id ) {
		if ( ! $user_id ) {
			return 0;
		}
		$mine = self::user_team_ids( $user_id );
		foreach ( self::match_teams( $match_id ) as $team_id ) {
			if ( $team_id && in_array( $team_id, $mine, true ) ) {
				return $team_id;
			}
		}
		return 0;
	}

	/**
	 * Assigned referees who play in the match.
	 *
	 * @return array<int, int> user ID => team ID.
	 */
	public static function conflicts( $match_id ) {
		$found = array();
		foreach ( self::assigned_users( $match_id ) as $user_id ) {
			$team_id = self::playing_team( $user_id, $match_id );
			if ( $team_id ) {
				$found[ $user_id ] = $team_id;
			}
		}
		return $found;
	}

	/** One sentence per conflict, for staff screens. */
	public static function conflict_messages( $match_id ) {
		$messages = array();
		foreach ( self::conflicts( $match_id ) as $user_id => $team_id ) {
			$user = get_user_by( 'id', $user_id );
			/* translators: 1: referee name, 2: team name */
			$messages[] = sprintf( __( '%1$s is assigned to referee this game but plays for %2$s.', 'leagueflow' ), $user ? $user->display_name : __( 'A referee', 'leagueflow' ), get_the_title( $team_id ) );
		}
		return $messages;
	}

	/**
	 * Why the current user may not save this result in this mode, or null.
	 * Staff who can edit the match follow the normal Results screen rules.
	 * A referee may publish or save any played result; one who plays in the
	 * game may only save it for staff, and cannot change a published result.
	 *
	 * @return \WP_Error|null
	 */
	public static function score_error( $match_id, $mode ) {
		if ( current_user_can( 'edit_post', $match_id ) ) {
			return null;
		}
		if ( ! self::can_access( $match_id, true ) ) {
			return new \WP_Error( 'forbidden', __( 'This match is not open for score entry. It may be postponed, cancelled, unpublished, or set up with teams from another league.', 'leagueflow' ) );
		}
		if ( ! in_array( $mode, array( 'finish', 'hold' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Publish the score or save it for staff.', 'leagueflow' ) );
		}
		$published = 'finished' === (string) get_post_meta( $match_id, 'lf_status', true );
		$team_id   = self::playing_team( get_current_user_id(), $match_id );
		if ( $team_id && $published ) {
			/* translators: %s: team name */
			return new \WP_Error( 'conflict', sprintf( __( 'You play for %s in this game, so staff must make any change to its published result.', 'leagueflow' ), get_the_title( $team_id ) ) );
		}
		if ( $team_id && 'finish' === $mode ) {
			/* translators: %s: team name */
			return new \WP_Error( 'conflict', sprintf( __( 'You play for %s in this game. Save the score for staff to publish.', 'leagueflow' ), get_the_title( $team_id ) ) );
		}
		if ( $published && 'hold' === $mode ) {
			return new \WP_Error( 'published', __( 'This result is already published. Use Update published score to correct it.', 'leagueflow' ) );
		}
		return null;
	}

	/** Opaque token binding a committed referee result to the exact match state (knockout advancement). */
	public static function state_token( $match_id ) {
		$state = array( get_post_status( $match_id ), get_post_field( 'post_password', $match_id ), self::assigned_users( $match_id ) );
		foreach ( array( 'lf_home_team_id', 'lf_away_team_id', 'lf_home_score', 'lf_away_score', 'lf_status', 'lf_outcome', 'lf_is_knockout', 'lf_winner_team_id', 'lf_match_datetime', 'lf_venue', 'lf_next_match_id', 'lf_next_match_slot', 'lf_round_order', self::SCORE_META ) as $key ) {
			$state[ $key ] = get_post_meta( $match_id, $key, true );
		}
		foreach ( array( 'lf_sport', 'lf_season', 'lf_competition', 'lf_league_level' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $match_id, $taxonomy, array( 'fields' => 'ids' ) );
			$state[ $taxonomy ] = is_wp_error( $terms ) ? array() : $terms;
		}
		return hash_hmac( 'sha256', wp_json_encode( $state ), wp_salt( 'nonce' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Saving results                                                      */
	/* ------------------------------------------------------------------ */

	protected function results() {
		if ( ! $this->results ) {
			$this->results = new Result_Service();
		}
		return $this->results;
	}

	/**
	 * Save a result from the desk through the shared result writer.
	 *
	 * @param int    $match_id Match ID.
	 * @param string $home     Home score.
	 * @param string $away     Away score.
	 * @param string $state    Result_Service::state_token() from the form.
	 * @param string $mode     finish (publish) or hold (save for staff).
	 * @param string $outcome  '' or a forfeit key.
	 * @return true|\WP_Error
	 */
	public function submit_result( $match_id, $home, $away, $state, $mode = 'finish', $outcome = '' ) {
		return $this->results()->save(
			absint( $match_id ),
			array(
				// Only text or whole numbers; anything else (arrays, booleans) is not a score.
				'home'    => is_string( $home ) || is_int( $home ) ? (string) $home : 'invalid',
				'away'    => is_string( $away ) || is_int( $away ) ? (string) $away : 'invalid',
				'outcome' => is_string( $outcome ) ? $outcome : 'invalid',
				'mode'    => is_string( $mode ) ? $mode : '',
				'state'   => is_string( $state ) ? $state : '',
				'source'  => 'referee-desk',
			)
		);
	}

	public function handle_submission() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Use the result form to submit a score.', 'leagueflow' ), '', array( 'response' => 405 ) ); }
		$id = isset( $_POST['match_id'] ) && is_scalar( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'leagueflow_referee_result_' . $id, 'leagueflow_referee_result_nonce' );
		$mode    = isset( $_POST['mode'] ) && is_scalar( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$outcome = isset( $_POST['outcome'] ) && is_scalar( $_POST['outcome'] ) ? sanitize_key( wp_unslash( $_POST['outcome'] ) ) : '';
		$result  = $this->submit_result( $id, wp_unslash( $_POST['home_score'] ?? '' ), wp_unslash( $_POST['away_score'] ?? '' ), wp_unslash( $_POST['result_state'] ?? '' ), $mode, $outcome );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
		} elseif ( 'finish' === $mode ) {
			$message = __( 'Score published. Standings are updated.', 'leagueflow' );
			if ( get_post_meta( $id, '_lf_bracket_sync_notice', true ) ) { $message .= ' ' . __( 'A bracket conflict needs staff review.', 'leagueflow' ); }
		} else {
			$message = __( 'Score saved. Staff will review and publish it.', 'leagueflow' );
		}
		set_transient( 'leagueflow_referee_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => $message ), 120 );
		$url = wp_get_referer() ?: self::desk_url();
		wp_safe_redirect( add_query_arg( 'lf_referee_match', $id, $url ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Staff: menu, match panel, referee access                            */
	/* ------------------------------------------------------------------ */

	public function register_menu() {
		if ( current_user_can( self::MANAGE_CAP ) ) {
			add_submenu_page( 'leagueflow', __( 'Referees', 'leagueflow' ), __( 'Referees', 'leagueflow' ), self::MANAGE_CAP, self::PAGE, array( $this, 'render_admin_page' ) );
		} elseif ( current_user_can( self::VIEW_CAP ) ) {
			add_menu_page( __( 'Referee desk', 'leagueflow' ), __( 'Referee desk', 'leagueflow' ), self::VIEW_CAP, self::PAGE, array( $this, 'render_admin_page' ), 'dashicons-clipboard', 25 );
		}
	}

	public function register_meta_box() {
		if ( current_user_can( self::MANAGE_CAP ) ) {
			add_meta_box( 'leagueflow-referees', __( 'Referee', 'leagueflow' ), array( $this, 'render_assignment_box' ), 'lf_match', 'side' );
		}
	}

	public function render_assignment_box( $post ) {
		wp_nonce_field( 'leagueflow_assign_referees_' . $post->ID, 'leagueflow_referee_nonce' );
		$users    = get_users( array( 'capability' => self::VIEW_CAP, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$assigned = self::assigned_users( $post->ID );
		foreach ( self::conflict_messages( $post->ID ) as $message ) {
			echo '<p class="leagueflow-referee-conflict"><strong>' . esc_html__( 'Conflict:', 'leagueflow' ) . '</strong> ' . esc_html( $message ) . '</p>';
		}
		?>
		<p><?php esc_html_e( 'Optional. Any referee can open this game on the Referee desk; assigning one puts it at the top of their list.', 'leagueflow' ); ?></p>
		<label for="lf_referee_user_ids" class="screen-reader-text"><?php esc_html_e( 'Referees', 'leagueflow' ); ?></label>
		<select id="lf_referee_user_ids" name="lf_referee_user_ids[]" multiple size="5" style="width:100%" data-leagueflow-searchable data-leagueflow-search-label="<?php esc_attr_e( 'Filter referees', 'leagueflow' ); ?>">
			<?php foreach ( $users as $user ) : ?>
				<?php $plays = self::playing_team( (int) $user->ID, $post->ID ); ?>
				<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( in_array( (int) $user->ID, $assigned, true ) ); ?>><?php echo esc_html( $user->display_name . ( $plays ? ' — ' . sprintf( /* translators: %s: team name */ __( 'plays for %s', 'leagueflow' ), get_the_title( $plays ) ) : '' ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! $users ) : ?>
			<p class="description"><?php printf( esc_html__( 'No referees yet. Add them on %s.', 'leagueflow' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'LeagueFlow → Referees', 'leagueflow' ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<?php endif; ?>
		<?php
		$history = get_post_meta( $post->ID, Result_Service::LOG_META, false );
		foreach ( array_reverse( $history ) as $entry ) {
			if ( is_array( $entry ) && 'referee-desk' === ( $entry['source'] ?? '' ) ) {
				$user = get_user_by( 'id', absint( $entry['user_id'] ?? 0 ) );
				/* translators: 1: referee, 2: published or saved, 3: date */
				echo '<p class="description">' . esc_html( sprintf( __( 'Last desk entry: %1$s %2$s a score on %3$s.', 'leagueflow' ), $user ? $user->display_name : __( 'a former user', 'leagueflow' ), 'finish' === ( $entry['mode'] ?? '' ) ? __( 'published', 'leagueflow' ) : __( 'saved', 'leagueflow' ), get_date_from_gmt( (string) ( $entry['at_utc'] ?? '' ), 'M j, g:i a' ) ) ) . '</p>';
				break;
			}
		}
	}

	public function save_assignments( $post_id, $post ) {
		if ( 'lf_match' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! current_user_can( self::MANAGE_CAP ) || ! current_user_can( 'edit_post', $post_id )
			|| ! isset( $_POST['leagueflow_referee_nonce'] ) || ! is_scalar( $_POST['leagueflow_referee_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['leagueflow_referee_nonce'] ) ), 'leagueflow_assign_referees_' . $post_id ) ) {
			return;
		}
		$submitted = $_POST['lf_referee_user_ids'] ?? array();
		if ( ! is_array( $submitted ) ) { return; }
		$ids = array();
		foreach ( $submitted as $value ) {
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || ! user_can( absint( $value ), self::VIEW_CAP ) ) { return; }
			$ids[] = absint( $value );
		}
		$lock = db_lock_name( 'result', (string) $post_id );
		if ( ! acquire_db_lock( $lock, 1 ) ) { return; }
		try {
			clean_post_cache( $post_id );
			if ( ! current_user_can( self::MANAGE_CAP ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
			delete_post_meta( $post_id, self::ASSIGNMENT_META );
			foreach ( array_unique( $ids ) as $id ) { add_post_meta( $post_id, self::ASSIGNMENT_META, $id ); }
		} finally { release_db_lock( $lock ); }
	}

	/** Give or remove referee access. The role is added next to any player role. */
	public function handle_access_change() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( self::MANAGE_CAP ) || ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You cannot change referee access.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'leagueflow_referee_access', 'leagueflow_referee_access_nonce' );
		$action = isset( $_POST['referee_action'] ) && is_scalar( $_POST['referee_action'] ) ? sanitize_key( wp_unslash( $_POST['referee_action'] ) ) : '';
		$notice = '';
		$error  = false;
		if ( 'add' === $action ) {
			$login = isset( $_POST['referee_user'] ) && is_scalar( $_POST['referee_user'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['referee_user'] ) ) ) : '';
			$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
			if ( ! $user instanceof \WP_User ) {
				$error  = true;
				$notice = __( 'No account uses that email or username. The person needs to sign in to the site once first.', 'leagueflow' );
			} elseif ( ! current_user_can( 'promote_user', $user->ID ) ) {
				$error  = true;
				$notice = __( 'You cannot change that account.', 'leagueflow' );
			} else {
				$user->add_role( self::ROLE );
				/* translators: %s: name */
				$notice = sprintf( __( '%s can now use the Referee desk.', 'leagueflow' ), $user->display_name );
			}
		} elseif ( 'remove' === $action ) {
			$user = get_user_by( 'id', isset( $_POST['referee_user_id'] ) && is_scalar( $_POST['referee_user_id'] ) ? absint( $_POST['referee_user_id'] ) : 0 );
			if ( $user instanceof \WP_User && current_user_can( 'promote_user', $user->ID ) ) {
				$user->remove_role( self::ROLE );
				if ( ! $user->roles ) {
					$user->add_role( 'subscriber' );
				}
				/* translators: %s: name */
				$notice = sprintf( __( 'Referee access removed for %s.', 'leagueflow' ), $user->display_name );
			} else {
				$error  = true;
				$notice = __( 'You cannot change that account.', 'leagueflow' );
			}
		}
		set_transient( 'leagueflow_referee_notice_' . get_current_user_id(), array( 'error' => $error, 'message' => $notice ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public function render_admin_page() {
		echo '<div class="wrap">';
		if ( current_user_can( self::MANAGE_CAP ) ) {
			$this->render_staff_page();
		} else {
			echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by renderer.
		}
		echo '</div>';
	}

	protected function render_staff_page() {
		$notice = $this->take_notice();
		$users  = get_users( array( 'role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Referees', 'leagueflow' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( self::desk_url() ); ?>"><?php esc_html_e( 'Open the Referee desk', 'leagueflow' ); ?></a>
		<hr class="wp-header-end">
		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo $notice['error'] ? 'error' : 'success'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Referees sign in on the website and choose Referee desk from their account menu. They can open any published game, see both rosters, and publish scores or save them for you to review. A referee can also be a player. Assigning a referee to a game is optional; if they play in that game they are flagged and can only save the score for staff.', 'leagueflow' ); ?></p>

		<h2><?php esc_html_e( 'Give referee access', 'leagueflow' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-referee-add">
			<input type="hidden" name="action" value="leagueflow_referee_access">
			<input type="hidden" name="referee_action" value="add">
			<?php wp_nonce_field( 'leagueflow_referee_access', 'leagueflow_referee_access_nonce' ); ?>
			<label for="lf-referee-user"><?php esc_html_e( 'Email or username', 'leagueflow' ); ?></label>
			<input id="lf-referee-user" name="referee_user" type="text" class="regular-text" required autocomplete="off">
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Add referee', 'leagueflow' ); ?></button>
			<p class="description"><?php esc_html_e( 'Their existing role (for example, player) is kept.', 'leagueflow' ); ?></p>
		</form>

		<h2><?php esc_html_e( 'Current referees', 'leagueflow' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Name', 'leagueflow' ); ?></th><th><?php esc_html_e( 'Email', 'leagueflow' ); ?></th><th><?php esc_html_e( 'Also plays for', 'leagueflow' ); ?></th><th><?php esc_html_e( 'Assigned games ahead', 'leagueflow' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'leagueflow' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $users as $user ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
					<td><?php echo esc_html( $user->user_email ); ?></td>
					<td><?php echo esc_html( implode( ', ', array_map( 'get_the_title', self::user_team_ids( (int) $user->ID ) ) ) ?: '—' ); ?></td>
					<td><?php echo esc_html( (string) count( $this->match_ids( array( 'assigned_to' => (int) $user->ID, 'from' => $this->local_time( '-3 hours' ), 'limit' => 100 ) )['ids'] ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="leagueflow_referee_access">
							<input type="hidden" name="referee_action" value="remove">
							<input type="hidden" name="referee_user_id" value="<?php echo esc_attr( $user->ID ); ?>">
							<?php wp_nonce_field( 'leagueflow_referee_access', 'leagueflow_referee_access_nonce' ); ?>
							<button class="button-link button-link-delete" type="submit"><?php esc_html_e( 'Remove access', 'leagueflow' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $users ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No referees yet.', 'leagueflow' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Conflicts', 'leagueflow' ); ?></h2>
		<?php
		$rows = array();
		foreach ( $this->match_ids( array( 'assigned_any' => true, 'from' => $this->local_time( '-14 days' ), 'limit' => 200, 'statuses' => array( 'publish', 'future', 'draft', 'pending', 'private' ) ) )['ids'] as $match_id ) {
			foreach ( self::conflict_messages( $match_id ) as $message ) {
				$rows[] = '<li><a href="' . esc_url( (string) get_edit_post_link( $match_id ) ) . '">' . esc_html( get_the_title( $match_id ) ) . '</a> — ' . esc_html( $message ) . '</li>';
			}
		}
		echo $rows ? '<ul class="ul-disc">' . implode( '', $rows ) . '</ul>' : '<p>' . esc_html__( 'No assigned referee plays in a game from the last two weeks or ahead.', 'leagueflow' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                             */
	/* ------------------------------------------------------------------ */

	/** Site-local wall-clock time, in the stored match format. */
	protected function local_time( $modify = 'now' ) {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( $modify )->format( 'Y-m-d H:i' );
	}

	/**
	 * Find matches.
	 *
	 * @param array $q from, to (Y-m-d H:i), unfinished, assigned_to, assigned_any,
	 *                 search, sport (slug), team, teams, order, limit, page, statuses.
	 * @return array{ids: int[], pages: int, total: int}
	 */
	protected function match_ids( $q ) {
		$meta = array( 'relation' => 'AND', 'when' => array( 'key' => 'lf_match_datetime', 'compare' => 'EXISTS' ) );
		if ( ! empty( $q['from'] ) ) {
			$meta[] = array( 'key' => 'lf_match_datetime', 'value' => $q['from'], 'compare' => '>=', 'type' => 'DATETIME' );
		}
		if ( ! empty( $q['to'] ) ) {
			$meta[] = array( 'key' => 'lf_match_datetime', 'value' => $q['to'], 'compare' => '<=', 'type' => 'DATETIME' );
		}
		if ( ! empty( $q['unfinished'] ) ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => 'lf_status', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'lf_status', 'value' => array( 'finished', 'postponed', 'cancelled' ), 'compare' => 'NOT IN' ),
			);
		}
		if ( ! empty( $q['assigned_to'] ) ) {
			$meta[] = array( 'key' => self::ASSIGNMENT_META, 'value' => (int) $q['assigned_to'] );
		}
		if ( ! empty( $q['assigned_any'] ) ) {
			$meta[] = array( 'key' => self::ASSIGNMENT_META, 'compare' => 'EXISTS' );
		}
		$teams = ! empty( $q['team'] ) ? array( (int) $q['team'] ) : array_map( 'absint', (array) ( $q['teams'] ?? array() ) );
		if ( $teams ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => 'lf_home_team_id', 'value' => $teams, 'compare' => 'IN' ),
				array( 'key' => 'lf_away_team_id', 'value' => $teams, 'compare' => 'IN' ),
			);
		}
		$args = array(
			'post_type'      => 'lf_match',
			'post_status'    => $q['statuses'] ?? 'publish',
			'has_password'   => isset( $q['statuses'] ) ? null : false,
			'fields'         => 'ids',
			'posts_per_page' => (int) ( $q['limit'] ?? self::PER_PAGE ),
			'paged'          => max( 1, (int) ( $q['page'] ?? 1 ) ),
			'meta_query'     => $meta,
			'orderby'        => array( 'when' => ( 'DESC' === ( $q['order'] ?? 'ASC' ) ? 'DESC' : 'ASC' ), 'ID' => 'ASC' ),
		);
		if ( ! empty( $q['search'] ) ) {
			$args['s'] = (string) $q['search'];
		}
		if ( ! empty( $q['sport'] ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'lf_sport', 'field' => 'slug', 'terms' => (string) $q['sport'] ) );
		}
		$query = new \WP_Query( $args );
		return array( 'ids' => array_map( 'absint', $query->posts ), 'pages' => (int) $query->max_num_pages, 'total' => (int) $query->found_posts );
	}

	/** Published teams whose name matches a search. */
	protected function team_ids( $search, $sport = '', $limit = 12 ) {
		$args = array(
			'post_type'      => 'lf_team',
			'post_status'    => 'publish',
			'has_password'   => false,
			'fields'         => 'ids',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $sport ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'lf_sport', 'field' => 'slug', 'terms' => $sport ) );
		}
		return array_map( 'absint', get_posts( $args ) );
	}

	/* ------------------------------------------------------------------ */
	/* Front-end desk                                                      */
	/* ------------------------------------------------------------------ */

	protected function take_notice() {
		$key    = 'leagueflow_referee_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			return $notice;
		}
		return null;
	}

	/** Desk URL with only the given query arguments. */
	protected function url( $args = array() ) {
		$base = is_admin() ? admin_url( 'admin.php?page=' . self::PAGE ) : ( get_permalink() ?: self::desk_url() );
		$base = remove_query_arg( array( 'lf_referee_match', 'lf_referee_team', 'lf_ref_q', 'lf_ref_sport', 'lf_ref_when', 'lf_referee_page' ), $base );
		return add_query_arg( array_filter( $args, static function ( $value ) { return '' !== $value && null !== $value && 0 !== $value; } ), $base );
	}

	protected function query_arg( $key ) {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function render() {
		wp_enqueue_style( 'leagueflow-frontend' );
		if ( ! is_user_logged_in() ) {
			return '<div class="leagueflow leagueflow-referee"><div class="leagueflow-referee__empty"><h2>' . esc_html__( 'Referee desk', 'leagueflow' ) . '</h2><p>' . esc_html__( 'Sign in with your referee account to see games, rosters and score entry.', 'leagueflow' ) . '</p><a class="leagueflow-referee__button" href="' . esc_url( wp_login_url( get_permalink() ?: self::desk_url() ) ) . '">' . esc_html__( 'Sign in', 'leagueflow' ) . '</a></div></div>';
		}
		if ( ! self::can_use_desk() ) {
			return '<div class="leagueflow leagueflow-referee"><div class="leagueflow-referee__empty"><h2>' . esc_html__( 'Referee desk', 'leagueflow' ) . '</h2><p>' . esc_html__( 'This account does not have referee access. Intramurals staff can add it.', 'leagueflow' ) . '</p></div></div>';
		}
		if ( ! headers_sent() ) { nocache_headers(); }
		ob_start();
		echo '<div class="leagueflow leagueflow-referee">';
		$notice = $this->take_notice();
		if ( $notice ) {
			echo '<p role="status" class="leagueflow-referee__notice' . ( $notice['error'] ? ' is-error' : '' ) . '">' . esc_html( $notice['message'] ) . '</p>';
		}
		$match_id = absint( $this->query_arg( 'lf_referee_match' ) );
		$team_id  = absint( $this->query_arg( 'lf_referee_team' ) );
		if ( $match_id ) {
			self::can_access( $match_id ) ? $this->render_match( $match_id ) : $this->render_unavailable( __( 'This game is not available.', 'leagueflow' ) );
		} elseif ( $team_id ) {
			self::can_view_team( $team_id ) ? $this->render_team( $team_id ) : $this->render_unavailable( __( 'This team is not available.', 'leagueflow' ) );
		} else {
			$this->render_home();
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	protected function render_unavailable( $message ) {
		echo '<p class="leagueflow-referee__back"><a href="' . esc_url( $this->url() ) . '">← ' . esc_html__( 'Referee desk', 'leagueflow' ) . '</a></p><p>' . esc_html( $message ) . '</p>';
	}

	protected function render_search_form( $q, $sport, $when ) {
		$sports = get_terms( array( 'taxonomy' => 'lf_sport', 'hide_empty' => true ) );
		?>
		<form class="leagueflow-referee__search" method="get" action="<?php echo esc_url( $this->url() ); ?>" role="search">
			<?php if ( is_admin() ) : ?><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><?php endif; ?>
			<label class="leagueflow-referee__field leagueflow-referee__field--grow">
				<span><?php esc_html_e( 'Find a game or team', 'leagueflow' ); ?></span>
				<input type="search" name="lf_ref_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Team name', 'leagueflow' ); ?>">
			</label>
			<label class="leagueflow-referee__field">
				<span><?php esc_html_e( 'Sport', 'leagueflow' ); ?></span>
				<select name="lf_ref_sport">
					<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
					<?php foreach ( is_array( $sports ) ? $sports : array() as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $sport, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="leagueflow-referee__field">
				<span><?php esc_html_e( 'When', 'leagueflow' ); ?></span>
				<select name="lf_ref_when">
					<?php foreach ( $this->when_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $when, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button class="leagueflow-referee__button" type="submit"><?php esc_html_e( 'Search', 'leagueflow' ); ?></button>
		</form>
		<?php
	}

	protected function when_options() {
		return array(
			''         => __( 'Any time', 'leagueflow' ),
			'needs'    => __( 'Needs a score', 'leagueflow' ),
			'today'    => __( 'Today', 'leagueflow' ),
			'upcoming' => __( 'Coming up', 'leagueflow' ),
			'past'     => __( 'Played', 'leagueflow' ),
		);
	}

	protected function render_home() {
		$q     = $this->query_arg( 'lf_ref_q' );
		$sport = sanitize_key( $this->query_arg( 'lf_ref_sport' ) );
		$when  = sanitize_key( $this->query_arg( 'lf_ref_when' ) );
		$when  = array_key_exists( $when, $this->when_options() ) ? $when : '';
		$user  = wp_get_current_user();
		$teams = self::user_team_ids( (int) $user->ID );
		?>
		<header class="leagueflow-referee__header">
			<p class="leagueflow-referee__eyebrow"><?php esc_html_e( 'Referee desk', 'leagueflow' ); ?></p>
			<h2><?php /* translators: %s: first name */ echo esc_html( sprintf( __( 'Hi, %s', 'leagueflow' ), $user->first_name ?: $user->display_name ) ); ?></h2>
			<p><?php esc_html_e( 'Open a game to check both rosters and enter the score.', 'leagueflow' ); ?>
			<?php if ( $teams ) : ?>
				<?php /* translators: %s: team names */ echo esc_html( sprintf( __( 'You also play for %s; those games are marked and their scores go to staff.', 'leagueflow' ), implode( ', ', array_map( 'get_the_title', $teams ) ) ) ); ?>
			<?php endif; ?>
			</p>
		</header>
		<?php
		$this->render_search_form( $q, $sport, $when );

		if ( '' !== $q || '' !== $sport || '' !== $when ) {
			$this->render_search_results( $q, $sport, $when );
			return;
		}

		$now      = $this->local_time();
		$assigned = $this->match_ids( array( 'assigned_to' => (int) $user->ID, 'from' => $this->local_time( '-3 hours' ), 'limit' => 6 ) )['ids'];
		$needs    = $this->match_ids( array( 'unfinished' => true, 'from' => $this->local_time( '-30 days' ), 'to' => $now, 'order' => 'DESC', 'limit' => 12 ) );
		$today    = $this->match_ids( array( 'from' => $this->local_time( 'today' ), 'to' => $this->local_time( 'tomorrow' ), 'limit' => 12 ) )['ids'];
		$soon     = $this->match_ids( array( 'from' => $this->local_time( 'tomorrow' ), 'to' => $this->local_time( '+8 days' ), 'limit' => 8 ) )['ids'];

		$this->render_section( __( 'Assigned to you', 'leagueflow' ), $assigned, '' );
		$this->render_section(
			__( 'Needs a score', 'leagueflow' ),
			$needs['ids'],
			__( 'Nothing waiting. Every game played in the last 30 days has a published score.', 'leagueflow' ),
			$needs['total'] > count( $needs['ids'] ) ? $this->url( array( 'lf_ref_when' => 'needs' ) ) : ''
		);
		// Assigned games are already listed above.
		$this->render_section( __( 'Today', 'leagueflow' ), array_values( array_diff( $today, $assigned ) ), $today ? __( 'Your assigned games are today’s only games.', 'leagueflow' ) : __( 'No games today.', 'leagueflow' ) );
		$this->render_section( __( 'Coming up', 'leagueflow' ), $soon, __( 'Nothing in the next week.', 'leagueflow' ), $this->url( array( 'lf_ref_when' => 'upcoming' ) ) );
	}

	protected function render_search_results( $q, $sport, $when ) {
		$page = max( 1, absint( $this->query_arg( 'lf_referee_page' ) ) );
		$args = array( 'sport' => $sport, 'page' => $page, 'limit' => self::PER_PAGE );
		if ( '' !== $q ) {
			// Referees search by team name; match titles are only a fallback.
			$named = $this->team_ids( $q, $sport, 100 );
			if ( $named ) {
				$args['teams'] = $named;
			} else {
				$args['search'] = $q;
			}
		}
		$now  = $this->local_time();
		switch ( $when ) {
			case 'needs':
				$args += array( 'unfinished' => true, 'to' => $now, 'order' => 'DESC' );
				break;
			case 'today':
				$args += array( 'from' => $this->local_time( 'today' ), 'to' => $this->local_time( 'tomorrow' ) );
				break;
			case 'upcoming':
				$args += array( 'from' => $now );
				break;
			case 'past':
				$args += array( 'to' => $now, 'order' => 'DESC' );
				break;
			default:
				$args += array( 'order' => 'DESC' );
		}
		if ( '' !== $q ) {
			$teams = array_values( array_filter( $this->team_ids( $q, $sport ), array( __CLASS__, 'can_view_team' ) ) );
			if ( $teams ) {
				echo '<section class="leagueflow-referee__section"><h3>' . esc_html__( 'Teams', 'leagueflow' ) . '</h3><ul class="leagueflow-referee__chips">';
				foreach ( $teams as $team_id ) {
					echo '<li><a href="' . esc_url( $this->url( array( 'lf_referee_team' => $team_id ) ) ) . '">' . esc_html( get_the_title( $team_id ) ) . ' <span>' . esc_html( $this->context_label( $team_id ) ) . '</span></a></li>';
				}
				echo '</ul></section>';
			}
		}
		$found = $this->match_ids( $args );
		/* translators: %d: number of games */
		$this->render_section( sprintf( _n( '%d game', '%d games', $found['total'], 'leagueflow' ), $found['total'] ), $found['ids'], __( 'No games match. Try another team name or time.', 'leagueflow' ) );
		if ( $found['pages'] > 1 ) {
			$base = array( 'lf_ref_q' => $q, 'lf_ref_sport' => $sport, 'lf_ref_when' => $when );
			echo '<nav class="leagueflow-referee__pages" aria-label="' . esc_attr__( 'Result pages', 'leagueflow' ) . '">';
			if ( $page > 1 ) { echo '<a href="' . esc_url( $this->url( $base + array( 'lf_referee_page' => $page - 1 ) ) ) . '">← ' . esc_html__( 'Previous', 'leagueflow' ) . '</a>'; }
			/* translators: 1: page, 2: pages */
			echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'leagueflow' ), $page, $found['pages'] ) ) . '</span>';
			if ( $page < $found['pages'] ) { echo '<a href="' . esc_url( $this->url( $base + array( 'lf_referee_page' => $page + 1 ) ) ) . '">' . esc_html__( 'Next', 'leagueflow' ) . ' →</a>'; }
			echo '</nav>';
		}
	}

	/** Sport · level for a team or match. */
	protected function context_label( $post_id ) {
		$parts = array();
		foreach ( array( 'lf_sport', 'lf_league_level' ) as $taxonomy ) {
			$term_id = get_post_primary_term_id( $post_id, $taxonomy );
			$term    = $term_id ? get_term( $term_id, $taxonomy ) : null;
			if ( $term instanceof \WP_Term ) {
				$parts[] = $term->name;
			}
		}
		return implode( ' · ', $parts );
	}

	protected function render_section( $title, $ids, $empty, $more = '' ) {
		$ids = array_values( array_filter( $ids, array( __CLASS__, 'can_access' ) ) );
		if ( ! $ids && '' === $empty ) {
			return;
		}
		echo '<section class="leagueflow-referee__section"><div class="leagueflow-referee__section-head"><h3>' . esc_html( $title ) . '</h3>';
		if ( $more ) {
			echo '<a href="' . esc_url( $more ) . '">' . esc_html__( 'See all', 'leagueflow' ) . ' →</a>';
		}
		echo '</div>';
		if ( ! $ids ) {
			echo '<p class="leagueflow-referee__muted">' . esc_html( $empty ) . '</p></section>';
			return;
		}
		echo '<div class="leagueflow-match-stack">';
		foreach ( $ids as $id ) {
			echo $this->card( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_match_card escapes.
		}
		echo '</div></section>';
	}

	/** Labels for where a match is and how it relates to the current user. */
	protected function badges( $match_id ) {
		$badges = array();
		$me     = get_current_user_id();
		if ( self::playing_team( $me, $match_id ) ) {
			$badges[] = array( __( 'You play', 'leagueflow' ), 'is-conflict' );
		}
		if ( in_array( $me, self::assigned_users( $match_id ), true ) ) {
			$badges[] = array( __( 'Assigned to you', 'leagueflow' ), 'is-assigned' );
		}
		if ( current_user_can( self::MANAGE_CAP ) && self::conflicts( $match_id ) ) {
			$badges[] = array( __( 'Referee conflict', 'leagueflow' ), 'is-conflict' );
		}
		$stage  = Result_Service::stage( $match_id );
		$labels = array(
			'needs_score' => array( __( 'Needs score', 'leagueflow' ), 'is-needs' ),
			'held'        => array( __( 'Saved, not published', 'leagueflow' ), 'is-held' ),
			'scheduled'   => array( __( 'Publishes later', 'leagueflow' ), 'is-held' ),
			'conflict'    => array( __( 'Staff review', 'leagueflow' ), 'is-held' ),
		);
		if ( isset( $labels[ $stage ] ) ) {
			$badges[] = $labels[ $stage ];
		}
		return $badges;
	}

	protected function match_item( $match_id ) {
		if ( ! $this->renderer ) {
			$this->renderer = new Renderer( new Standings_Service(), new Knockout_Service(), new Sports_Manager() );
		}
		return $this->renderer->get_match_item( $match_id );
	}

	protected function card( $match_id ) {
		$item              = $this->match_item( $match_id );
		$item['permalink'] = $this->url( array( 'lf_referee_match' => $match_id ) );
		return render_match_card( $item, array( 'badges' => $this->badges( $match_id ) ) );
	}

	protected function render_match( $id ) {
		$item  = $this->match_item( $id );
		$teams = self::match_teams( $id );
		$me    = get_current_user_id();
		$plays = self::playing_team( $me, $id );
		echo '<p class="leagueflow-referee__back"><a href="' . esc_url( $this->url() ) . '">← ' . esc_html__( 'Referee desk', 'leagueflow' ) . '</a></p>';
		echo render_match_card( $item, array( 'link' => false, 'badges' => $this->badges( $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $plays ) {
			/* translators: %s: team name */
			echo '<p class="leagueflow-referee__flag" role="note">' . esc_html( sprintf( __( 'You play for %s in this game. You can save a score, and staff will check and publish it.', 'leagueflow' ), get_the_title( $plays ) ) ) . '</p>';
		}
		if ( current_user_can( self::MANAGE_CAP ) ) {
			foreach ( self::conflict_messages( $id ) as $message ) {
				echo '<p class="leagueflow-referee__flag" role="note">' . esc_html( $message ) . '</p>';
			}
		}
		$officials = array_filter( array_map( static function ( $user_id ) { $user = get_user_by( 'id', $user_id ); return $user ? $user->display_name : ''; }, self::assigned_users( $id ) ) );
		if ( $officials ) {
			echo '<p class="leagueflow-referee__muted">' . esc_html__( 'Referee:', 'leagueflow' ) . ' ' . esc_html( implode( ', ', $officials ) ) . '</p>';
		}

		echo '<div class="leagueflow-referee__layout"><div class="leagueflow-referee__rosters">';
		foreach ( $teams as $team_id ) {
			$this->render_roster( $team_id, $id );
		}
		echo '</div><aside class="leagueflow-referee__score">';
		$this->render_score_form( $id, $teams );
		echo '</aside></div>';
	}

	protected function render_roster( $team_id, $match_id = 0 ) {
		$players  = get_team_member_player_ids( $team_id, array( 'publish', 'private' ) );
		$me       = get_current_user_id();
		$assigned = $match_id ? self::assigned_users( $match_id ) : array();
		usort(
			$players,
			static function ( $a, $b ) use ( $team_id ) {
				$ca = ! empty( get_player_team_detail( $a, $team_id )['is_captain'] );
				$cb = ! empty( get_player_team_detail( $b, $team_id )['is_captain'] );
				return $ca === $cb ? strcasecmp( (string) get_post_field( 'post_title', $a ), (string) get_post_field( 'post_title', $b ) ) : ( $ca ? -1 : 1 );
			}
		);
		$waivers = (bool) Registration_Consent::documents();
		echo '<section class="leagueflow-referee__roster"><div class="leagueflow-referee__section-head"><h3>';
		echo $match_id ? '<a href="' . esc_url( $this->url( array( 'lf_referee_team' => $team_id ) ) ) . '">' . esc_html( get_the_title( $team_id ) ) . '</a>' : esc_html( get_the_title( $team_id ) );
		/* translators: %d: player count */
		echo '</h3><span>' . esc_html( sprintf( _n( '%d player', '%d players', count( $players ), 'leagueflow' ), count( $players ) ) ) . '</span></div>';
		echo '<table class="leagueflow-referee__table"><thead><tr><th scope="col">#</th><th scope="col">' . esc_html__( 'Player', 'leagueflow' ) . '</th><th scope="col">' . esc_html__( 'Position', 'leagueflow' ) . '</th>' . ( $waivers ? '<th scope="col">' . esc_html__( 'Waiver', 'leagueflow' ) . '</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $players as $player_id ) {
			$detail = get_player_team_detail( $player_id, $team_id );
			$user   = absint( get_post_meta( $player_id, 'lf_user_id', true ) );
			$tags   = '';
			if ( ! empty( $detail['is_captain'] ) ) {
				$tags .= '<span class="leagueflow-referee__tag">' . esc_html__( 'Captain', 'leagueflow' ) . '</span>';
			}
			if ( $user && $user === $me ) {
				$tags .= '<span class="leagueflow-referee__tag is-conflict">' . esc_html__( 'You', 'leagueflow' ) . '</span>';
			} elseif ( $user && in_array( $user, $assigned, true ) ) {
				$tags .= '<span class="leagueflow-referee__tag is-conflict">' . esc_html__( 'Assigned referee', 'leagueflow' ) . '</span>';
			}
			$waiver = Registration_Consent::is_current( $player_id );
			echo '<tr><td class="is-number">' . esc_html( (string) $detail['jersey_number'] ) . '</td><th scope="row">' . esc_html( (string) get_post_field( 'post_title', $player_id ) ) . $tags . '</th><td>' . esc_html( (string) $detail['position'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tags is escaped above.
			if ( $waivers ) {
				echo '<td><span class="leagueflow-referee__waiver ' . ( $waiver ? 'is-ok' : 'is-missing' ) . '">' . esc_html( $waiver ? __( 'Signed', 'leagueflow' ) : __( 'Missing', 'leagueflow' ) ) . '</span></td>';
			}
			echo '</tr>';
		}
		if ( ! $players ) {
			echo '<tr><td colspan="' . ( $waivers ? 4 : 3 ) . '">' . esc_html__( 'No players on this roster yet.', 'leagueflow' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	protected function render_score_form( $id, $teams ) {
		$status    = (string) get_post_meta( $id, 'lf_status', true );
		$published = 'finished' === $status;
		$home      = (string) get_post_meta( $id, 'lf_home_score', true );
		$away      = (string) get_post_meta( $id, 'lf_away_score', true );
		$outcome   = (string) get_post_meta( $id, 'lf_outcome', true );
		$staff     = current_user_can( 'edit_post', $id );
		echo '<h3>' . esc_html( $published ? __( 'Published score', 'leagueflow' ) : __( 'Enter the score', 'leagueflow' ) ) . '</h3>';
		if ( ! self::can_access( $id, true ) ) {
			echo '<p class="leagueflow-referee__muted">' . esc_html( in_array( $status, array( 'postponed', 'cancelled' ), true ) ? __( 'This game is postponed or cancelled.', 'leagueflow' ) : __( 'Score entry is closed for this game. Ask staff to check its teams and league.', 'leagueflow' ) ) . '</p>';
			return;
		}
		$can_publish = ! is_wp_error( self::score_error( $id, 'finish' ) );
		$can_hold    = ! $published && ! is_wp_error( self::score_error( $id, 'hold' ) );
		if ( ! $can_publish && ! $can_hold ) {
			$error = self::score_error( $id, 'finish' );
			echo '<p class="leagueflow-referee__score-now">' . esc_html( $home . ' – ' . $away ) . '</p><p class="leagueflow-referee__muted">' . esc_html( $error ? $error->get_error_message() : '' ) . '</p>';
			return;
		}
		$start = Result_Service::start_timestamp( $id );
		if ( null !== $start && $start > time() + HOUR_IN_SECONDS ) {
			echo '<p class="leagueflow-referee__muted">' . esc_html__( 'This game has not started yet.', 'leagueflow' ) . '</p>';
		}
		$stage = Result_Service::stage( $id );
		if ( 'held' === $stage || 'scheduled' === $stage || 'conflict' === $stage ) {
			echo '<p class="leagueflow-referee__muted">' . esc_html__( 'A score is saved but not published yet.', 'leagueflow' ) . '</p>';
		}
		?>
		<form class="leagueflow-referee__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leagueflow_referee_result">
			<input type="hidden" name="match_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="result_state" value="<?php echo esc_attr( Result_Service::state_token( $id ) ); ?>">
			<?php wp_nonce_field( 'leagueflow_referee_result_' . $id, 'leagueflow_referee_result_nonce' ); ?>
			<div class="leagueflow-referee__scores">
				<label><span><?php echo esc_html( get_the_title( $teams[0] ) ); ?></span><input name="home_score" type="number" inputmode="numeric" min="0" step="1" value="<?php echo esc_attr( $home ); ?>"></label>
				<span aria-hidden="true">–</span>
				<label><span><?php echo esc_html( get_the_title( $teams[1] ) ); ?></span><input name="away_score" type="number" inputmode="numeric" min="0" step="1" value="<?php echo esc_attr( $away ); ?>"></label>
			</div>
			<?php if ( 'volleyball' === get_post_primary_term_slug( $id, 'lf_sport' ) ) : ?>
				<p class="leagueflow-referee__muted"><?php esc_html_e( 'Volleyball: enter sets won (for example 2–1), not points.', 'leagueflow' ); ?></p>
			<?php endif; ?>
			<label class="leagueflow-referee__field">
				<span><?php esc_html_e( 'Result', 'leagueflow' ); ?></span>
				<select name="outcome">
					<?php foreach ( Result_Service::outcomes() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $outcome, $key ); ?>><?php echo esc_html( 'forfeit_home' === $key ? sprintf( /* translators: %s: team */ __( '%s forfeited', 'leagueflow' ), get_the_title( $teams[0] ) ) : ( 'forfeit_away' === $key ? sprintf( /* translators: %s: team */ __( '%s forfeited', 'leagueflow' ), get_the_title( $teams[1] ) ) : $label ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="leagueflow-referee__actions">
				<?php if ( $can_publish ) : ?>
					<button class="leagueflow-referee__button" type="submit" name="mode" value="finish"><?php echo esc_html( $published ? __( 'Update published score', 'leagueflow' ) : __( 'Publish final score', 'leagueflow' ) ); ?></button>
				<?php endif; ?>
				<?php if ( $can_hold ) : ?>
					<button class="leagueflow-referee__button <?php echo $can_publish ? 'is-secondary' : ''; ?>" type="submit" name="mode" value="hold"><?php esc_html_e( 'Save for staff review', 'leagueflow' ); ?></button>
				<?php endif; ?>
			</div>
			<p class="leagueflow-referee__muted">
				<?php
				if ( $can_publish ) {
					esc_html_e( 'Publishing shows the score on the site and updates standings. Saving keeps it private until staff publish it.', 'leagueflow' );
				} else {
					esc_html_e( 'Staff will check the score and publish it.', 'leagueflow' );
				}
				if ( $staff ) {
					echo ' ' . esc_html__( 'You are signed in as staff, so the Results screen rules apply.', 'leagueflow' );
				}
				?>
			</p>
		</form>
		<?php
	}

	protected function render_team( $team_id ) {
		echo '<p class="leagueflow-referee__back"><a href="' . esc_url( $this->url() ) . '">← ' . esc_html__( 'Referee desk', 'leagueflow' ) . '</a></p>';
		echo '<header class="leagueflow-referee__header"><p class="leagueflow-referee__eyebrow">' . esc_html( $this->context_label( $team_id ) ) . '</p><h2>' . esc_html( get_the_title( $team_id ) ) . '</h2></header>';
		echo '<div class="leagueflow-referee__layout"><div class="leagueflow-referee__rosters">';
		$this->render_roster( $team_id );
		echo '</div><aside class="leagueflow-referee__team-games">';
		$now = $this->local_time();
		$this->render_section( __( 'Next games', 'leagueflow' ), $this->match_ids( array( 'team' => $team_id, 'from' => $now, 'limit' => 4 ) )['ids'], __( 'No games ahead.', 'leagueflow' ) );
		$this->render_section( __( 'Played', 'leagueflow' ), $this->match_ids( array( 'team' => $team_id, 'to' => $now, 'order' => 'DESC', 'limit' => 4 ) )['ids'], __( 'No games played yet.', 'leagueflow' ) );
		echo '</aside></div>';
	}
}
