<?php
/**
 * Requests inbox: join-a-team and placement requests in one reviewed queue.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen and guarded handlers for join and placement requests.
 */
class Admin_Requests {

	const PAGE     = 'leagueflow-requests';
	const PER_PAGE = 50;

	/**
	 * Request rules.
	 *
	 * @var Join_Request_Service
	 */
	protected $service;

	/**
	 * Sports manager.
	 *
	 * @var Sports_Manager
	 */
	protected $sports_manager;

	/**
	 * Constructor.
	 *
	 * @param Join_Request_Service $service Request rules.
	 * @param Sports_Manager       $sports_manager Sports.
	 */
	public function __construct( Join_Request_Service $service, Sports_Manager $sports_manager ) {
		$this->service        = $service;
		$this->sports_manager = $sports_manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_leagueflow_request_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_leagueflow_reconcile_requests', array( $this, 'handle_reconcile' ) );
		add_action( 'add_meta_boxes_lf_join_request', array( $this, 'register_record_meta_box' ) );
	}

	/**
	 * The request rules service.
	 *
	 * @return Join_Request_Service
	 */
	public function service() {
		return $this->service;
	}

	/**
	 * Labels for reviewer actions.
	 *
	 * @return array<string, string>
	 */
	public static function action_labels() {
		return array(
			'approve'           => __( 'Approve and add to team', 'leagueflow' ),
			'assign'            => __( 'Assign to team', 'leagueflow' ),
			'resolve_member'    => __( 'Close: already on the team', 'leagueflow' ),
			'move_to_placement' => __( 'Move to Needs placement', 'leagueflow' ),
			'decline'           => __( 'Decline', 'leagueflow' ),
			'dismiss'           => __( 'Dismiss', 'leagueflow' ),
			'reopen'            => __( 'Reopen as pending', 'leagueflow' ),
		);
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array<string, string|int>
	 */
	public function get_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen filters.
		$type   = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'pending' ) );
		$sport  = sanitize_key( wp_unslash( $_GET['lf_sport'] ?? $_GET['sport'] ?? '' ) );
		$level  = sanitize_title( wp_unslash( $_GET['lf_league_level'] ?? '' ) );
		$level  = '' !== $level && get_term_by( 'slug', $level, 'lf_league_level' ) instanceof \WP_Term ? $level : '';
		$filter = array(
			'type'     => in_array( $type, array( 'team', 'placement' ), true ) ? $type : '',
			'status'   => ( 'all' === $status || 'unknown' === $status || isset( Join_Request_Service::statuses()[ $status ] ) ) ? $status : 'pending',
			'lf_sport' => in_array( $sport, $this->sports_manager->get_enabled_sport_slugs(), true ) ? $sport : '',
			// Division (league level) is kept on tabs, counts, search, paging, details, actions and Back.
			'lf_league_level' => $level,
			'issues'   => ! empty( $_GET['issues'] ) ? 1 : 0,
			's'        => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			'paged'    => max( 1, absint( $_GET['paged'] ?? 1 ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $filter;
	}

	/**
	 * Inbox URL with filters.
	 *
	 * @param array<string, mixed> $args Arguments that override the current filters.
	 * @param bool                 $keep Whether to keep the current filters.
	 * @return string
	 */
	public function url( $args = array(), $keep = true ) {
		$base = $keep ? $this->get_filters() : array();
		unset( $base['paged'] );
		$query = array_merge( array( 'page' => self::PAGE ), $base, $args );

		if ( isset( $query['status'] ) && 'pending' === $query['status'] ) {
			unset( $query['status'] );
		}

		return add_query_arg( array_filter( $query, static function ( $value ) { return '' !== $value && 0 !== $value && null !== $value; } ), admin_url( 'admin.php' ) );
	}

	/**
	 * Load and inspect requests for the sport/search scope (status and type are
	 * filtered afterwards so the tab counts stay accurate).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function load( $filters ) {
		$args = array(
			'post_type'      => 'lf_join_request',
			'post_status'    => array( 'private', 'publish', 'draft', 'pending', 'future' ),
			'perm'           => 'readable',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(),
		);

		if ( $filters['lf_sport'] ) {
			$args['meta_query'][] = array(
				'key'   => 'lf_sport_slug',
				'value' => $filters['lf_sport'],
			);
		}

		$level    = '' !== (string) ( $filters['lf_league_level'] ?? '' ) ? get_term_by( 'slug', $filters['lf_league_level'], 'lf_league_level' ) : null;
		$level_id = $level instanceof \WP_Term ? (int) $level->term_id : 0;
		$items    = array();
		$search   = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $filters['s'] ) ) : strtolower( trim( (string) $filters['s'] ) );

		foreach ( get_posts( $args ) as $request_id ) {
			$info = $this->service->inspect( $request_id );

			if ( ! $info || ( $level_id && (int) $info['level_id'] !== $level_id ) ) {
				continue;
			}

			// Search the player's name (and the request title), not post content.
			if ( '' !== $search ) {
				$haystack = $this->player_label( $info ) . ' ' . $info['title'];
				$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$items[] = $info;
		}

		return $items;
	}

	/**
	 * Render the inbox.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'leagueflow_manage_placements' ) ) {
			wp_die( esc_html__( 'You do not have permission to review requests.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		$filters    = $this->get_filters();
		$request_id = absint( wp_unslash( $_GET['request_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all        = $this->load( $filters );
		$by_type    = array( '' => array(), 'team' => array(), 'placement' => array() );

		foreach ( $all as $info ) {
			$by_type[''][]             = $info;
			$by_type[ $info['type'] ][] = $info;
		}

		$scoped = $by_type[ $filters['type'] ];
		$counts = array( 'all' => count( $scoped ) );

		foreach ( array_merge( array_keys( Join_Request_Service::statuses() ), array( 'unknown' ) ) as $status ) {
			$counts[ $status ] = count( wp_list_filter( $scoped, array( 'status' => $status ) ) );
		}

		$rows = 'all' === $filters['status'] ? $scoped : array_values( wp_list_filter( $scoped, array( 'status' => $filters['status'] ) ) );

		if ( $filters['issues'] ) {
			$rows = array_values( array_filter( $rows, static function ( $info ) { return (bool) array_diff( $info['flags'], Join_Request_Service::warning_flags() ); } ) );
		}

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$rows  = array_slice( $rows, ( min( $filters['paged'], $pages ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		?>
		<div class="wrap leagueflow-admin-page leagueflow-requests-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Requests', 'leagueflow' ); ?></h1>
			<hr class="wp-header-end" />
			<p class="leagueflow-page-intro"><?php esc_html_e( 'Players ask to join a named team (Join a team) or ask staff to find them one (Needs placement). Review each request here. Approving or assigning adds the player to the roster; declining or dismissing never removes anyone from a roster.', 'leagueflow' ); ?></p>
			<p class="description leagueflow-scope-note"><?php esc_html_e( 'Requests name a sport, a level (division) and sometimes a team. They have no competition or season of their own, so there is no competition or season filter here; use the team lists for those.', 'leagueflow' ); ?></p>

			<?php $this->render_notice(); ?>
			<?php
			if ( $request_id ) {
				$this->render_detail( $request_id );
			}
			?>

			<nav class="leagueflow-request-tabs nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Request type', 'leagueflow' ); ?>">
				<?php
				foreach ( array( '' => __( 'All requests', 'leagueflow' ), 'team' => __( 'Join a team', 'leagueflow' ), 'placement' => __( 'Needs placement', 'leagueflow' ) ) as $type => $label ) {
					$pending = count( wp_list_filter( $by_type[ $type ], array( 'status' => 'pending' ) ) );
					printf(
						'<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s <span class="count">(%5$s)</span></a>',
						$filters['type'] === $type ? ' nav-tab-active' : '',
						esc_url( $this->url( array( 'type' => $type, 'request_id' => null ) ) ),
						$filters['type'] === $type ? ' aria-current="page"' : '',
						esc_html( $label ),
						/* translators: %s: number of pending requests */
						esc_html( sprintf( __( '%s pending', 'leagueflow' ), number_format_i18n( $pending ) ) )
					);
				}
				?>
			</nav>

			<ul class="subsubsub leagueflow-request-statuses">
				<?php
				$links = array();

				$status_tabs = Join_Request_Service::statuses();

				if ( $counts['unknown'] ) {
					$status_tabs['unknown'] = __( 'Needs status review', 'leagueflow' );
				}

				foreach ( array_merge( $status_tabs, array( 'all' => __( 'All statuses', 'leagueflow' ) ) ) as $status => $label ) {
					$current = $filters['status'] === $status;
					$links[] = sprintf(
						'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a></li>',
						esc_url( $this->url( array( 'status' => $status, 'request_id' => null ) ) ),
						$current ? ' class="current" aria-current="page"' : '',
						esc_html( $label ),
						esc_html( number_format_i18n( $counts[ $status ] ) )
					);
				}

				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				?>
			</ul>

			<form method="get" class="leagueflow-filter-bar leagueflow-request-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" aria-label="<?php esc_attr_e( 'Filter requests', 'leagueflow' ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="hidden" name="type" value="<?php echo esc_attr( $filters['type'] ); ?>" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				<span class="leagueflow-filter-field">
					<label for="leagueflow-request-sport"><?php esc_html_e( 'Sport', 'leagueflow' ); ?></label>
					<select id="leagueflow-request-sport" name="lf_sport">
						<option value=""><?php esc_html_e( 'All sports', 'leagueflow' ); ?></option>
						<?php foreach ( $this->sports_manager->get_enabled_sports() as $sport ) : ?>
							<option value="<?php echo esc_attr( $sport['slug'] ); ?>" <?php selected( $filters['lf_sport'], $sport['slug'] ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<span class="leagueflow-filter-field">
					<label for="leagueflow-request-level"><?php esc_html_e( 'Level', 'leagueflow' ); ?></label>
					<select id="leagueflow-request-level" name="lf_league_level">
						<option value=""><?php esc_html_e( 'All levels', 'leagueflow' ); ?></option>
						<?php foreach ( get_terms( array( 'taxonomy' => 'lf_league_level', 'hide_empty' => false ) ) as $level_term ) : ?>
							<?php if ( $level_term instanceof \WP_Term ) : ?>
								<option value="<?php echo esc_attr( $level_term->slug ); ?>" <?php selected( $filters['lf_league_level'], $level_term->slug ); ?>><?php echo esc_html( $level_term->name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</span>
				<span class="leagueflow-filter-field">
					<label for="leagueflow-request-search"><?php esc_html_e( 'Search player', 'leagueflow' ); ?></label>
					<input type="search" id="leagueflow-request-search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" />
				</span>
				<label class="leagueflow-inline-check"><input type="checkbox" name="issues" value="1" <?php checked( $filters['issues'], 1 ); ?> /> <?php esc_html_e( 'Only requests that need attention', 'leagueflow' ); ?></label>
				<?php submit_button( __( 'Filter', 'leagueflow' ), 'secondary', '', false ); ?>
			</form>

			<?php $this->render_reconcile_form( $rows ); ?>

			<?php if ( empty( $rows ) ) : ?>
				<div class="leagueflow-empty-state">
					<p><strong><?php esc_html_e( 'No requests match these filters.', 'leagueflow' ); ?></strong></p>
					<p><?php esc_html_e( 'Players send requests from the portal. Try All statuses or All sports to see earlier decisions.', 'leagueflow' ); ?></p>
				</div>
			<?php else : ?>
				<div class="leagueflow-table-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Requests', 'leagueflow' ); ?>">
				<table class="widefat striped leagueflow-requests-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Requests matching the current filters', 'leagueflow' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Player', 'leagueflow' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Request', 'leagueflow' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'leagueflow' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Submitted', 'leagueflow' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $info ) : ?>
							<tr<?php echo $request_id === $info['id'] ? ' class="leagueflow-row-current"' : ''; ?>>
								<th scope="row">
									<a href="<?php echo esc_url( $this->url( array( 'request_id' => $info['id'] ) ) . '#leagueflow-request-detail' ); ?>"><?php echo esc_html( $this->player_label( $info ) ); ?></a>
								</th>
								<td><?php echo esc_html( $this->summary( $info ) ); ?></td>
								<td><?php $this->render_status( $info ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( $info['date_gmt'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php
				if ( $pages > 1 ) {
					echo '<nav class="tablenav"><div class="tablenav-pages" aria-label="' . esc_attr__( 'Pages', 'leagueflow' ) . '">';
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped links.
						array(
							'base'    => add_query_arg( 'paged', '%#%', $this->url( array( 'request_id' => null ) ) ),
							'format'  => '',
							'current' => min( $filters['paged'], $pages ),
							'total'   => $pages,
						)
					);
					echo '</div></nav>';
				}
				?>
			<?php endif; ?>
			<p class="description"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lf_join_request' ) ); ?>"><?php esc_html_e( 'Raw request records (classic list)', 'leagueflow' ); ?></a></p>
		</div>
		<?php
	}

	/** Preview only the already-placed requests on this page; preserve their history. */
	protected function render_reconcile_form( $rows ) {
		$ready = array_filter( $rows, function ( $info ) {
			return $this->service->current_user_can_review( $info['id'] ) && in_array( 'resolve_member', $this->service->available_actions( $info ), true );
		} );
		if ( ! $ready ) { return; }
		?>
		<details class="leagueflow-request-reconcile">
			<summary><?php echo esc_html( sprintf( __( 'Review %d already-placed requests on this page', 'leagueflow' ), count( $ready ) ) ); ?></summary>
			<p><?php esc_html_e( 'These exact player records already belong to a team matching their request. Close the pending requests below as approved; roster memberships and request history are kept.', 'leagueflow' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leagueflow_reconcile_requests" />
				<input type="hidden" name="lf_return" value="<?php echo esc_attr( $this->url() ); ?>" />
				<?php wp_nonce_field( 'leagueflow_reconcile_requests', 'leagueflow_reconcile_nonce' ); ?>
				<ul>
				<?php foreach ( $ready as $info ) : ?>
					<li><?php echo esc_html( $this->player_label( $info ) . ' — ' . $this->summary( $info ) ); ?>
					<input type="hidden" name="lf_request_states[<?php echo esc_attr( (string) $info['id'] ); ?>]" value="<?php echo esc_attr( $this->service->state_token( $info ) ); ?>" /></li>
				<?php endforeach; ?>
				</ul>
				<button class="button button-secondary" type="submit"><?php esc_html_e( 'Close these already-placed requests', 'leagueflow' ); ?></button>
			</form>
		</details>
		<?php
	}

	/** Apply the bounded preview through the same locked state-aware single-request path. */
	public function handle_reconcile() {
		if ( ! $this->service->current_user_can_review() ) {
			wp_die( esc_html__( 'You do not have permission to review requests.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}
		$return = $this->safe_return_url( 0 );
		$nonce = isset( $_POST['leagueflow_reconcile_nonce'] ) && is_string( $_POST['leagueflow_reconcile_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['leagueflow_reconcile_nonce'] ) ) : '';
		$states = isset( $_POST['lf_request_states'] ) && is_array( $_POST['lf_request_states'] ) ? wp_unslash( $_POST['lf_request_states'] ) : array();
		if ( ! wp_verify_nonce( $nonce, 'leagueflow_reconcile_requests' ) || ! $states || count( $states ) > self::PER_PAGE ) {
			$this->redirect( $return, array( 'leagueflow_request_notice' => 'invalid' ) );
		}
		foreach ( $states as $id => $state ) {
			if ( ! ctype_digit( (string) $id ) || ! absint( $id ) || ! is_string( $state ) || ! preg_match( '/^[a-f0-9]{16}$/', $state ) ) {
				$this->redirect( $return, array( 'leagueflow_request_notice' => 'invalid' ) );
			}
		}
		$closed = 0;
		foreach ( $states as $id => $state ) {
			$result = $this->service->apply_action( (int) $id, 'resolve_member', array( 'state' => $state ) );
			if ( ! is_wp_error( $result ) ) { ++$closed; }
		}
		$this->redirect( $return, array( 'leagueflow_request_notice' => 'reconciled', 'lf_closed' => $closed, 'lf_skipped' => count( $states ) - $closed ) );
	}

	/**
	 * Player name, or a safe label when the player is missing.
	 *
	 * @param array<string, mixed> $info Request.
	 * @return string
	 */
	public function player_label( $info ) {
		/* translators: %d: request ID */
		return $info['has_player'] ? get_the_title( $info['player_id'] ) : sprintf( __( 'Unknown player (request #%d)', 'leagueflow' ), $info['id'] );
	}

	/**
	 * One-line summary: what was requested, in which sport and level.
	 *
	 * @param array<string, mixed> $info Request.
	 * @return string
	 */
	public function summary( $info ) {
		$sport = $this->sports_manager->get_definition( $info['sport_slug'] );
		$level = $info['level_id'] ? get_term( $info['level_id'], 'lf_league_level' ) : null;
		if ( $this->is_resolved_placement( $info ) ) {
			/* translators: %s: team name */
			$request = sprintf( __( 'Placement request: placed on %s', 'leagueflow' ), $this->team_label( $info['team_id'] ) );
		} elseif ( 'team' === $info['type'] ) {
			/* translators: %s: team name */
			$request = sprintf( __( 'Join %s', 'leagueflow' ), $this->team_label( $info['team_id'] ) );
		} else {
			$request = __( 'Needs placement', 'leagueflow' );
		}

		$parts = array(
			$request,
			! empty( $sport['label'] ) && $info['sport_slug'] ? $sport['label'] : __( 'Sport not specified', 'leagueflow' ),
			$level instanceof \WP_Term ? $level->name : __( 'Level not specified', 'leagueflow' ),
		);

		return implode( ' · ', $parts );
	}

	/**
	 * Whether an approved request started in the placement queue.
	 *
	 * Assigning a placement stores the chosen team on the request, which makes
	 * it look like a named join request. The persisted lf_placed_from_queue
	 * marker keeps its origin for display; review rules are unchanged.
	 *
	 * @param array<string, mixed> $info Request.
	 * @return bool
	 */
	protected function is_resolved_placement( $info ) {
		return 'team' === $info['type'] && 'approved' === $info['status'] && (bool) get_post_meta( $info['id'], 'lf_placed_from_queue', true );
	}

	/**
	 * Teams to list as the player's current team in the request's sport.
	 *
	 * The service lists other teams only (for conflict checks), so the
	 * requested team is added back when the player is already on it.
	 *
	 * @param array<string, mixed> $info Request.
	 * @return int[]
	 */
	protected function current_team_ids( $info ) {
		$team_ids = array_map( 'intval', $info['sport_team_ids'] );

		if ( $info['team_id'] && in_array( 'already_member', $info['flags'], true ) && ! in_array( (int) $info['team_id'], $team_ids, true ) ) {
			array_unshift( $team_ids, (int) $info['team_id'] );
		}

		return $team_ids;
	}

	/**
	 * Team name with its state when it is not an ordinary team.
	 *
	 * @param int $team_id Team ID.
	 * @return string
	 */
	protected function team_label( $team_id ) {
		$team = get_post( $team_id );

		if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
			/* translators: %d: team ID */
			return sprintf( __( 'missing team #%d', 'leagueflow' ), $team_id );
		}

		// Titles of teams the reviewer cannot read stay hidden.
		if ( 'trash' !== $team->post_status && ! current_user_can( 'read_post', $team->ID ) ) {
			/* translators: %d: team ID */
			return sprintf( __( 'team #%d (not visible to you)', 'leagueflow' ), $team->ID );
		}

		if ( 'trash' === $team->post_status ) {
			/* translators: %s: team name */
			return sprintf( __( '%s (in Trash)', 'leagueflow' ), current_user_can( 'edit_post', $team->ID ) ? $team->post_title : '#' . $team->ID );
		}

		if ( 'publish' !== $team->post_status && 'private' !== $team->post_status ) {
			/* translators: %s: team name */
			return sprintf( __( '%s (draft)', 'leagueflow' ), $team->post_title );
		}

		return $team->post_title;
	}

	/**
	 * Status badge plus flags.
	 *
	 * @param array<string, mixed> $info Request.
	 * @return void
	 */
	protected function render_status( $info ) {
		printf( '<span class="leagueflow-badge leagueflow-badge--%1$s">%2$s</span>', esc_attr( $info['status'] ), esc_html( Join_Request_Service::status_label( $info['status'] ) ) );

		if ( $info['flags'] ) {
			$flag_labels = Join_Request_Service::flag_labels();
			echo '<ul class="leagueflow-flags">';

			foreach ( $info['flags'] as $flag ) {
				echo '<li>' . esc_html( $flag_labels[ $flag ] ?? $flag ) . '</li>';
			}

			echo '</ul>';
		}
	}

	/**
	 * Detail and review panel for one request.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function render_detail( $request_id ) {
		$info = $this->service->inspect( $request_id );

		if ( ! $info || ! current_user_can( 'read_post', $request_id ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'That request could not be found.', 'leagueflow' ) . '</p></div>';
			return;
		}

		$sport   = $this->sports_manager->get_definition( $info['sport_slug'] );
		$level   = $info['level_id'] ? get_term( $info['level_id'], 'lf_league_level' ) : null;
		$email   = $info['has_player'] ? (string) get_post_meta( $info['player_id'], 'lf_email', true ) : '';
		$actions = $this->service->current_user_can_review( $request_id ) ? $this->service->available_actions( $info ) : array();
		$labels  = self::action_labels();
		$current = array();

		$placed  = $this->is_resolved_placement( $info );

		foreach ( $this->current_team_ids( $info ) as $team_id ) {
			$current[] = $this->team_label( $team_id );
		}
		?>
		<section id="leagueflow-request-detail" class="leagueflow-admin-card leagueflow-request-detail" aria-labelledby="leagueflow-request-detail-title" tabindex="-1">
			<p><a href="<?php echo esc_url( $this->url( array( 'request_id' => null ) ) ); ?>">&larr; <?php esc_html_e( 'Back to the request list', 'leagueflow' ); ?></a></p>
			<h2 id="leagueflow-request-detail-title"><?php echo esc_html( $this->player_label( $info ) ); ?></h2>
			<dl class="leagueflow-detail-list">
				<dt><?php esc_html_e( 'Request', 'leagueflow' ); ?></dt>
				<dd><?php echo esc_html( $placed ? __( 'Placement request', 'leagueflow' ) : ( 'team' === $info['type'] ? __( 'Join a team', 'leagueflow' ) : __( 'Needs placement', 'leagueflow' ) ) ); ?></dd>
				<?php if ( 'team' === $info['type'] ) : ?>
					<dt><?php echo esc_html( $placed ? __( 'Assigned team', 'leagueflow' ) : __( 'Requested team', 'leagueflow' ) ); ?></dt>
					<dd>
						<?php
						$team = get_post( $info['team_id'] );
						if ( $team instanceof \WP_Post && 'lf_team' === $team->post_type && 'trash' !== $team->post_status && current_user_can( 'edit_post', $team->ID ) ) {
							printf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_post_link( $team->ID ) ), esc_html( $team->post_title ) );
						} else {
							echo esc_html( $this->team_label( $info['team_id'] ) );
						}
						?>
					</dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Sport and level', 'leagueflow' ); ?></dt>
				<dd><?php echo esc_html( ( ! empty( $sport['label'] ) && $info['sport_slug'] ? $sport['label'] : __( 'Sport not specified', 'leagueflow' ) ) . ' · ' . ( $level instanceof \WP_Term ? $level->name : __( 'Level not specified', 'leagueflow' ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Status', 'leagueflow' ); ?></dt>
				<dd><?php $this->render_status( $info ); ?></dd>
				<?php if ( $info['has_player'] ) : ?>
					<dt><?php esc_html_e( 'Player', 'leagueflow' ); ?></dt>
					<dd>
						<?php if ( current_user_can( 'edit_post', $info['player_id'] ) ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $info['player_id'] ) ); ?>"><?php esc_html_e( 'Open player profile', 'leagueflow' ); ?></a>
						<?php endif; ?>
						<?php if ( is_email( $email ) ) : ?> · <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a><?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Current team in this sport', 'leagueflow' ); ?></dt>
					<dd><?php echo $current ? esc_html( implode( ', ', $current ) ) : esc_html__( 'None', 'leagueflow' ); ?></dd>
				<?php endif; ?>
				<?php if ( '' !== $info['note'] ) : ?>
					<dt><?php esc_html_e( 'Player note', 'leagueflow' ); ?></dt>
					<dd><?php echo esc_html( $info['note'] ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Submitted', 'leagueflow' ); ?></dt>
				<dd><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $info['date_gmt'] ) ) ); ?></dd>
			</dl>

			<?php if ( $actions ) : ?>
				<h3><?php esc_html_e( 'Review', 'leagueflow' ); ?></h3>
				<?php
				$primary   = array_values( array_intersect( $actions, array( 'approve', 'assign', 'resolve_member' ) ) );
				$secondary = array_values( array_diff( $actions, $primary ) );
				?>
				<div class="leagueflow-request-actions">
					<?php foreach ( $primary as $action ) : ?>
						<?php $this->render_action_form( $info, $action, $labels[ $action ] ); ?>
					<?php endforeach; ?>
					<?php if ( $secondary ) : ?>
						<?php // Native disclosure: keyboard and no-JS friendly; open when there is no primary decision. ?>
						<details class="leagueflow-request-more"<?php echo $primary ? '' : ' open'; ?>>
							<summary><?php echo esc_html( sprintf( /* translators: %s: list of decisions */ __( 'Other decisions: %s', 'leagueflow' ), implode( ', ', array_map( static function ( $key ) use ( $labels ) { return $labels[ $key ]; }, $secondary ) ) ) ); ?></summary>
							<?php foreach ( $secondary as $action ) : ?>
								<?php $this->render_action_form( $info, $action, $labels[ $action ] ); ?>
							<?php endforeach; ?>
						</details>
					<?php endif; ?>
				</div>
			<?php elseif ( in_array( $info['status'], array( 'pending', 'unknown' ), true ) ) : ?>
				<p class="description"><?php esc_html_e( 'You cannot review this request.', 'leagueflow' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'This request is closed. Its history is kept below.', 'leagueflow' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'History', 'leagueflow' ); ?></h3>
			<?php $this->render_history( $info ); ?>
		</section>
		<?php
	}

	/**
	 * One guarded action form.
	 *
	 * @param array<string, mixed> $info Request.
	 * @param string               $action Action key.
	 * @param string               $label Button label.
	 * @return void
	 */
	protected function render_action_form( $info, $action, $label ) {
		$id          = (int) $info['id'];
		$needs_note  = in_array( $action, array( 'decline', 'dismiss', 'move_to_placement', 'reopen' ), true );
		$field_base  = 'leagueflow-request-' . $action . '-' . $id;
		$teams       = 'assign' === $action ? $this->service->get_assignable_teams( $info ) : array();
		$can_notify  = in_array( $action, array( 'approve', 'assign', 'decline' ), true ) && $info['has_player'];
		$descriptions = array(
			'approve'           => __( 'Adds the player to the requested team.', 'leagueflow' ),
			'assign'            => __( 'Adds the player to the chosen team. Only teams in the requested sport and level are listed.', 'leagueflow' ),
			'resolve_member'    => 'team' === $info['type']
				? __( 'Marks the request approved because the player is already on the requested team. The roster is not changed.', 'leagueflow' )
				/* translators: %s: team name */
				: sprintf( __( 'Marks the request approved because the player is already on %s, which matches the requested sport and level. The roster is not changed.', 'leagueflow' ), $this->team_label( (int) ( $info['matching_team_ids'][0] ?? 0 ) ) ),
			'reopen'            => __( 'The stored status is missing or not recognised, so this request is not treated as pending. Reopen it as pending after checking it, or dismiss it. The old value is kept in the history.', 'leagueflow' ),
			'move_to_placement' => __( 'The requested team cannot take players. Keep the request open in Needs placement so staff can choose another team. The original team stays in the history.', 'leagueflow' ),
			'decline'           => __( 'Closes the request without adding the player. It does not remove anyone from a roster.', 'leagueflow' ),
			'dismiss'           => __( 'Closes a request that needs no decision, such as a duplicate, test or stale record. Nothing is deleted.', 'leagueflow' ),
		);
		?>
		<form method="post" class="leagueflow-request-action leagueflow-request-action--<?php echo esc_attr( $action ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
			<input type="hidden" name="action" value="leagueflow_request_action" />
			<input type="hidden" name="lf_join_request_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="lf_request_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="lf_return" value="<?php echo esc_attr( $this->url( array( 'request_id' => $id ) ) ); ?>" />
			<input type="hidden" name="lf_request_state" value="<?php echo esc_attr( $this->service->state_token( $info ) ); ?>" />
			<?php wp_nonce_field( 'leagueflow_request_' . $action . '_' . $id, 'leagueflow_request_nonce' ); ?>
			<p class="description"><?php echo esc_html( $descriptions[ $action ] ?? '' ); ?></p>
			<?php if ( 'assign' === $action ) : ?>
				<p>
					<label class="leagueflow-placement-label" for="<?php echo esc_attr( $field_base . '-team' ); ?>"><?php esc_html_e( 'Team', 'leagueflow' ); ?><span class="screen-reader-text"> <?php echo esc_html( sprintf( /* translators: 1: player 2: request summary */ __( 'for %1$s (%2$s)', 'leagueflow' ), $this->player_label( $info ), $this->summary( $info ) ) ); ?></span></label>
					<select id="<?php echo esc_attr( $field_base . '-team' ); ?>" name="lf_team_id" required <?php disabled( empty( $teams ) ); ?>>
						<option value=""><?php esc_html_e( 'Choose a team', 'leagueflow' ); ?></option>
						<?php foreach ( $teams as $team ) : ?>
							<option value="<?php echo esc_attr( (string) $team->ID ); ?>"><?php echo esc_html( $team->post_title . ( 'private' === $team->post_status ? ' ' . __( '(private)', 'leagueflow' ) : ( 'publish' !== $team->post_status ? ' ' . __( '(draft, not public yet)', 'leagueflow' ) : '' ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php if ( empty( $teams ) ) : ?>
					<p class="description"><?php esc_html_e( 'No team you can edit matches this sport and level yet. Add a team first, or decline the request.', 'leagueflow' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $needs_note || in_array( $action, array( 'approve', 'assign', 'resolve_member' ), true ) ) : ?>
				<p>
					<label for="<?php echo esc_attr( $field_base . '-note' ); ?>"><?php echo esc_html( $needs_note ? __( 'Reason (required, kept in the history)', 'leagueflow' ) : __( 'Note (optional, kept in the history)', 'leagueflow' ) ); ?></label><br />
					<textarea id="<?php echo esc_attr( $field_base . '-note' ); ?>" name="lf_request_decision_note" rows="2" class="large-text" <?php echo $needs_note ? 'required' : ''; ?>></textarea>
				</p>
			<?php endif; ?>
			<?php if ( $can_notify ) : ?>
				<p><label><input type="checkbox" name="lf_notify" value="1" <?php checked( 'decline' !== $action ); ?> /> <?php esc_html_e( 'E-mail the player about this decision', 'leagueflow' ); ?></label></p>
			<?php endif; ?>
			<p><button type="submit" class="button <?php echo in_array( $action, array( 'approve', 'assign' ), true ) ? 'button-primary' : 'button-secondary'; ?>" <?php disabled( 'assign' === $action && empty( $teams ) ); ?>><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Request history list.
	 *
	 * @param array<string, mixed> $info Request.
	 * @return void
	 */
	protected function render_history( $info ) {
		$history = $this->service->get_history( $info['id'] );
		$events  = array(
			'approved'           => __( 'Approved', 'leagueflow' ),
			'assigned'           => __( 'Assigned to a team', 'leagueflow' ),
			'resolved_member'    => __( 'Closed: already on the team', 'leagueflow' ),
			'declined'           => __( 'Declined', 'leagueflow' ),
			'dismissed'          => __( 'Dismissed', 'leagueflow' ),
			'moved_to_placement' => __( 'Moved to Needs placement', 'leagueflow' ),
			'reconciled'         => __( 'Closed by a roster change', 'leagueflow' ),
			'reopened'           => __( 'Reopened as pending', 'leagueflow' ),
		);

		if ( ! $history ) {
			echo '<p class="description">' . esc_html__( 'No review history yet. Decisions made before this inbox existed were not recorded.', 'leagueflow' ) . '</p>';
			return;
		}

		echo '<ol class="leagueflow-history">';

		foreach ( array_reverse( $history ) as $entry ) {
			$user = $entry['user_id'] ? get_userdata( $entry['user_id'] ) : null;
			printf(
				'<li><strong>%1$s</strong> · %2$s%3$s%4$s%5$s</li>',
				esc_html( $events[ $entry['event'] ] ?? $entry['event'] ),
				esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $entry['time'] ) ) ),
				$user ? ' · ' . esc_html( $user->display_name ) : '',
				$entry['team_id'] ? ' · ' . esc_html( $this->team_label( $entry['team_id'] ) ) : '',
				'' !== $entry['note'] ? '<br />' . esc_html( $entry['note'] ) : ''
			);
		}

		echo '</ol>';
	}

	/**
	 * Notice after an action.
	 *
	 * @return void
	 */
	protected function render_notice() {
		$code     = sanitize_key( wp_unslash( $_GET['leagueflow_request_notice'] ?? $_GET['leagueflow_placement_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'reconciled' === $code ) {
			$closed = min( self::PER_PAGE, absint( $_GET['lf_closed'] ?? 0 ) );
			$skipped = min( self::PER_PAGE, absint( $_GET['lf_skipped'] ?? 0 ) );
			printf( '<div class="notice notice-info inline" role="status"><p>%s</p></div>', esc_html( sprintf( __( '%1$d already-placed requests closed. %2$d changed or unavailable requests were skipped; review them again. No players were removed.', 'leagueflow' ), $closed, $skipped ) ) );
			return;
		}
		$messages = array(
			'done'     => array( 'success', __( 'The request was updated.', 'leagueflow' ) ),
			'assigned' => array( 'success', __( 'The player was added to the team.', 'leagueflow' ) ),
			'invalid'  => array( 'error', __( 'The request could not be updated: the form expired or was not valid. Nothing was changed.', 'leagueflow' ) ),
			'denied'   => array( 'error', __( 'You cannot change this request. Nothing was changed.', 'leagueflow' ) ),
		);

		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s inline" role="status"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
			return;
		}

		$error = sanitize_text_field( wp_unslash( $_GET['leagueflow_request_error'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			$errors = array(
				'leagueflow_request_missing' => __( 'That request no longer exists.', 'leagueflow' ),
				'leagueflow_request_state'   => __( 'That action is not available for this request any more. It may already have been reviewed. Nothing was changed.', 'leagueflow' ),
				'leagueflow_request_reason'  => __( 'Add a short reason so the history explains this decision. Nothing was changed.', 'leagueflow' ),
				'leagueflow_request_team'    => __( 'That team is not eligible for this request. Nothing was changed.', 'leagueflow' ),
				'leagueflow_request_denied'  => __( 'You cannot change this team or player. Nothing was changed.', 'leagueflow' ),
				'leagueflow_request_member'  => __( 'The player is already on another team in this sport. Nothing was changed.', 'leagueflow' ),
				'leagueflow_request_failed'  => __( 'The player could not be added to the team.', 'leagueflow' ),
				'leagueflow_request_busy'    => __( 'Someone else was updating this request (or the request lock was unavailable). Nothing was changed; review it again.', 'leagueflow' ),
				'leagueflow_request_stale'   => __( 'That form is out of date (it has no current review state). Nothing was changed; review the request below and decide again.', 'leagueflow' ),
			);
			printf( '<div class="notice notice-error inline" role="alert"><p>%s</p></div>', esc_html( $errors[ $error ] ?? $errors['leagueflow_request_state'] ) );
		}
	}

	/**
	 * Handle a reviewed action. Checks capability, the record, and an
	 * action- and record-bound nonce before the service validates the state.
	 *
	 * @return void
	 */
	public function handle_action() {
		$request_id = absint( wp_unslash( $_POST['lf_join_request_id'] ?? 0 ) );
		$action     = sanitize_key( wp_unslash( $_POST['lf_request_action'] ?? '' ) );
		$nonce      = sanitize_text_field( wp_unslash( $_POST['leagueflow_request_nonce'] ?? '' ) );
		$return     = $this->safe_return_url( $request_id );

		if ( ! $this->service->current_user_can_review() ) {
			wp_die( esc_html__( 'You do not have permission to review requests.', 'leagueflow' ), '', array( 'response' => 403 ) );
		}

		if ( ! $request_id || ! isset( self::action_labels()[ $action ] ) || ! wp_verify_nonce( $nonce, 'leagueflow_request_' . $action . '_' . $request_id ) ) {
			$this->redirect( $return, array( 'leagueflow_request_notice' => 'invalid' ) );
		}

		if ( 'lf_join_request' !== get_post_type( $request_id ) || ! $this->service->current_user_can_review( $request_id ) ) {
			$this->redirect( $return, array( 'leagueflow_request_notice' => 'denied' ) );
		}

		// HTTP decisions always need the state the reviewer saw. An omitted or
		// malformed token (an old or forged form) goes back to a fresh review.
		$state = sanitize_key( wp_unslash( $_POST['lf_request_state'] ?? '' ) );

		if ( ! preg_match( '/^[a-f0-9]{16}$/', $state ) ) {
			$this->redirect( $return, array( 'leagueflow_request_error' => 'leagueflow_request_stale' ) );
		}

		$result = $this->apply( $request_id, $action, absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) ), sanitize_textarea_field( wp_unslash( $_POST['lf_request_decision_note'] ?? '' ) ), ! empty( $_POST['lf_notify'] ), $state );

		if ( is_wp_error( $result ) ) {
			$this->redirect( $return, array( 'leagueflow_request_error' => $result->get_error_code() ) );
		}

		$this->redirect( $return, array( 'leagueflow_request_notice' => in_array( $action, array( 'approve', 'assign' ), true ) ? 'assigned' : 'done' ) );
	}

	/**
	 * Apply an action and send the optional decision e-mail.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $action Action.
	 * @param int    $team_id Team for assign.
	 * @param string $note Note.
	 * @param bool   $notify Whether to e-mail the player (and team managers on a roster change).
	 * @param string|null $state State token the reviewer saw. Any string (even '') is
	 *                          checked; only programmatic callers that pass null skip it.
	 * @return true|\WP_Error
	 */
	public function apply( $request_id, $action, $team_id, $note, $notify, $state = null ) {
		$args = array( 'team_id' => $team_id, 'note' => $note );

		if ( null !== $state ) {
			$args['state'] = (string) $state;
		}

		$result = $this->service->apply_action( $request_id, $action, $args );

		if ( true === $result && $notify && in_array( $action, array( 'approve', 'assign', 'decline' ), true ) ) {
			$this->notify( $request_id, $action );
		}

		return $result;
	}

	/**
	 * E-mail the player (and, for roster changes, the team managers).
	 *
	 * @param int    $request_id Request ID.
	 * @param string $action Action taken.
	 * @return void
	 */
	protected function notify( $request_id, $action ) {
		$player_id  = absint( get_post_meta( $request_id, 'lf_player_id', true ) );
		$team_id    = absint( get_post_meta( $request_id, 'lf_team_id', true ) );
		$email      = sanitize_email( (string) get_post_meta( $player_id, 'lf_email', true ) );
		$portal_url = get_permalink( absint( get_option( 'leagueflow_portal_page_id' ) ) );
		$team_name  = $team_id ? get_the_title( $team_id ) : '';

		if ( 'decline' === $action ) {
			if ( is_email( $email ) ) {
				send_registration_email(
					$email,
					__( '[UNBC Intramurals] Update on your team request', 'leagueflow' ),
					/* translators: %s: portal URL */
					sprintf( __( 'Intramurals staff reviewed your team request and could not approve it. You can choose another option from your portal: %s', 'leagueflow' ), $portal_url )
				);
			}

			return;
		}

		if ( is_email( $email ) ) {
			send_registration_email(
				$email,
				/* translators: %s: team name */
				sprintf( __( '[UNBC Intramurals] You were placed on %s', 'leagueflow' ), $team_name ),
				/* translators: 1: team name 2: portal URL */
				sprintf( __( 'Intramurals staff placed you on %1$s. View your profile and team: %2$s', 'leagueflow' ), $team_name, $portal_url )
			);
		}

		send_registration_email(
			get_team_manager_emails( $team_id ),
			/* translators: %s: player name */
			sprintf( __( '[UNBC Intramurals] %s was added to your roster', 'leagueflow' ), get_the_title( $player_id ) ),
			/* translators: 1: player 2: team 3: portal URL */
			sprintf( __( 'Intramurals staff placed %1$s on %2$s. Review your roster: %3$s', 'leagueflow' ), get_the_title( $player_id ), $team_name, $portal_url )
		);
	}

	/**
	 * Return URL from the form, limited to this inbox.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	protected function safe_return_url( $request_id ) {
		$fallback = add_query_arg( array( 'page' => self::PAGE, 'request_id' => $request_id ), admin_url( 'admin.php' ) );
		$return   = esc_url_raw( wp_unslash( $_POST['lf_return'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by the caller; this only picks a redirect.
		$inbox    = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		return ( '' !== $return && 0 === strpos( $return, $inbox ) ) ? $return : $fallback;
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string               $url URL.
	 * @param array<string, mixed> $args Notice arguments.
	 * @return void
	 */
	protected function redirect( $url, $args ) {
		$url = remove_query_arg( array( 'leagueflow_request_notice', 'leagueflow_request_error', 'leagueflow_placement_notice' ), $url );
		wp_safe_redirect( add_query_arg( $args, $url ) . '#leagueflow-request-detail' );
		exit;
	}

	/**
	 * Summary box on the classic request edit screen.
	 *
	 * @return void
	 */
	public function register_record_meta_box() {
		add_meta_box( 'leagueflow-request-summary', __( 'Request details', 'leagueflow' ), array( $this, 'render_record_meta_box' ), 'lf_join_request', 'normal', 'high' );
	}

	/**
	 * Read-only summary with a link to the inbox review.
	 *
	 * @param \WP_Post $post Request.
	 * @return void
	 */
	public function render_record_meta_box( $post ) {
		$info = $this->service->inspect( $post->ID );

		if ( ! $info ) {
			return;
		}

		echo '<p><strong>' . esc_html( $this->player_label( $info ) ) . '</strong> · ' . esc_html( $this->summary( $info ) ) . '</p>';
		echo '<p>';
		$this->render_status( $info );
		echo '</p>';

		if ( current_user_can( 'leagueflow_manage_placements' ) ) {
			printf( '<p><a class="button button-primary" href="%1$s">%2$s</a></p>', esc_url( add_query_arg( array( 'page' => self::PAGE, 'status' => 'all', 'request_id' => $post->ID ), admin_url( 'admin.php' ) ) . '#leagueflow-request-detail' ), esc_html__( 'Review in Requests', 'leagueflow' ) );
		}

		echo '<p class="description">' . esc_html__( 'Requests are reviewed in the Requests inbox so roster changes, decisions and history stay consistent.', 'leagueflow' ) . '</p>';
	}
}
