<?php
/**
 * Front-end team and player portal.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * LeagueFlow front-end portal.
 */
class Portal {

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	protected $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', __NAMESPACE__ . '\\ensure_portal_roles', 1 );
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_action( 'init', array( $this, 'maybe_create_portal_page' ), 100 );
		add_shortcode( 'leagueflow_portal', array( $this, 'render_portal' ) );
		add_action( 'admin_post_leagueflow_portal', array( $this, 'handle_form_submission' ) );
		add_action( 'deleted_user', array( $this, 'handle_wp_user_deleted' ), 10, 3 );
	}

	/**
	 * Register the editable Gutenberg block that renders the portal logic.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type_from_metadata(
			LEAGUEFLOW_PATH . 'blocks/portal',
			array(
				'render_callback' => array( $this, 'render_portal_block' ),
			)
		);
	}

	/**
	 * Create a public portal page if one does not already exist.
	 *
	 * @return void
	 */
	public function maybe_create_portal_page() {
		$page_id = absint( get_option( 'leagueflow_portal_page_id' ) );

		if ( $page_id ) {
			$page = get_post( $page_id );

			if ( $page instanceof \WP_Post ) {
				$this->maybe_upgrade_portal_page_content( $page );
				return;
			}
		}

		$page = get_page_by_path( 'portal', OBJECT, 'page' );

		if ( $page instanceof \WP_Post ) {
			update_option( 'leagueflow_portal_page_id', $page->ID );
			$this->maybe_upgrade_portal_page_content( $page );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Portal', 'leagueflow' ),
				'post_name'    => 'portal',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $this->get_portal_block_content(),
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'leagueflow_portal_page_id', absint( $page_id ) );
		}
	}

	/**
	 * Upgrade generated portal page content to the portal block when safe.
	 *
	 * @param \WP_Post $page Portal page.
	 * @return void
	 */
	protected function maybe_upgrade_portal_page_content( $page ) {
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type ) {
			return;
		}

		$content = $this->get_upgraded_portal_page_content( (string) $page->post_content );

		if ( null === $content ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		);
	}

	/**
	 * Render the portal block.
	 *
	 * The shortcode remains registered for backwards compatibility, but the
	 * generated portal page now stores this dynamic block in editable content.
	 *
	 * @return string
	 */
	public function render_portal_block() {
		return $this->render_portal();
	}

	/**
	 * Get the default block content for the generated portal page.
	 *
	 * @return string
	 */
	protected function get_portal_block_content() {
		return '<!-- wp:leagueflow/portal /-->';
	}

	/**
	 * Upgrade generated portal content to the block while preserving edits.
	 *
	 * @param string $content Existing page content.
	 * @return string|null Upgraded content, or null when no change is needed.
	 */
	protected function get_upgraded_portal_page_content( $content ) {
		$trimmed = trim( $content );

		if ( '' === $trimmed ) {
			return $this->get_portal_block_content();
		}

		if ( false !== strpos( $content, '<!-- wp:leagueflow/portal' ) ) {
			return null;
		}

		$block = $this->get_portal_block_content();
		$count = 0;

		$upgraded = preg_replace(
			'/<!--\s+wp:shortcode\s+-->\s*\[leagueflow_portal\]\s*<!--\s+\/wp:shortcode\s+-->/',
			$block,
			$content,
			-1,
			$count
		);

		if ( $count > 0 && null !== $upgraded ) {
			return $upgraded;
		}

		$upgraded = str_replace( '[leagueflow_portal]', $block, $content, $count );

		return $count > 0 ? $upgraded : null;
	}

	/**
	 * Render the portal shortcode.
	 *
	 * @return string
	 */
	public function render_portal() {
		wp_enqueue_style( 'leagueflow-frontend' );

		if ( ! is_user_logged_in() ) {
			return $this->render_logged_out();
		}

		wp_enqueue_script( 'leagueflow-portal' );

		$user          = wp_get_current_user();
		$captain_open  = is_captain_registration_enabled();
		$player_open   = is_player_registration_enabled();
		$path          = $this->get_requested_onboarding_path();
		$player_id     = $this->get_player_id_for_user( $user, false );
		$manager_teams = $this->get_manager_teams( $user->ID );
		$has_profile   = (bool) $player_id || ! empty( $manager_teams ) || current_user_can( 'manage_options' );
		$notice        = sanitize_key( wp_unslash( $_GET['leagueflow_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'team-registered' === $notice && ! empty( $manager_teams ) ) {
			$path = '';
		}

		if ( ! $has_profile && ! $captain_open && ! $player_open ) {
			return $this->render_portal_shell( $this->render_registration_closed() );
		}

		if ( ! $has_profile && ! $this->user_can_begin_registration( $user ) ) {
			return $this->render_portal_shell( $this->render_registration_email_denied( $user ) );
		}

		if ( ! $has_profile && $captain_open && $player_open && ! $path ) {
			return $this->render_portal_shell( $this->render_role_choice() );
		}

		if ( ! $has_profile && ( ( 'captain' === $path && $captain_open ) || ( $captain_open && ! $player_open ) ) ) {
			return $this->render_portal_shell( $this->render_captain_registration_panel( $user, array(), 0 ) );
		}

		if ( ! $player_id && $player_open && ( 'player' === $path || ( ! $captain_open && empty( $manager_teams ) ) ) ) {
			return $this->render_portal_shell(
				$this->render_player_setup( 0, null, $user, 'leagueflow-profile-form-new', '' )
			);
		}

		if ( ! $this->user_can_access_portal( $user, $player_id, $manager_teams ) ) {
			return $this->render_access_denied( $user );
		}

		if ( ! empty( $manager_teams ) ) {
			add_user_role_if_missing( $user->ID, 'leagueflow_team_manager' );
		}

		$needs_initial_setup = $this->player_needs_initial_setup( $player_id, $user );

		if ( $needs_initial_setup && $captain_open && ! $player_open && empty( $manager_teams ) ) {
			return $this->render_portal_shell( $this->render_captain_registration_panel( $user, array(), $player_id ) );
		}

		if ( 'captain' === $path && $captain_open && empty( $manager_teams ) ) {
			return $this->render_portal_shell( $this->render_captain_registration_panel( $user, array(), $player_id ) );
		}

		if ( $needs_initial_setup && $player_id ) {
			return $this->render_portal_shell( $this->render_player_dashboard( $player_id, $user ) );
		}

		$content        = '';
		$dashboard_view = $this->get_requested_dashboard_view();

		if ( 'player' === $path && $player_id ) {
			$dashboard_view = 'player';
		}

		if ( 'captain' === $path && $captain_open ) {
			if ( ! empty( $manager_teams ) ) {
				$content .= $this->render_dashboard_navigation( 'teams', true, (bool) $player_id, false );
				$content .= $this->render_manager_dashboard( $manager_teams, $player_id, false );
			} elseif ( $player_id ) {
				$content .= $this->render_dashboard_navigation( 'teams', false, true, false );
			}

			$content .= $this->render_captain_registration_panel( $user, $manager_teams, $player_id );

			return $this->render_portal_shell( $content );
		}

		if ( ! empty( $manager_teams ) ) {
			$dashboard_view = 'player' === $dashboard_view && $player_id ? 'player' : 'teams';
			$content       .= $this->render_dashboard_navigation( $dashboard_view, true, (bool) $player_id, false );
			$content       .= 'player' === $dashboard_view
				? $this->render_player_dashboard( $player_id, $user )
				: $this->render_manager_dashboard( $manager_teams, $player_id );
		} elseif ( $player_id ) {
			$content .= $this->render_dashboard_navigation( 'player', false, true, $captain_open );
			$content .= $this->render_player_dashboard( $player_id, $user );
		}

		return $this->render_portal_shell( $content ? $content : $this->render_registration_closed() );
	}

	/**
	 * Wrap portal sections in the shared full-width shell.
	 *
	 * @param string $content Portal section markup.
	 * @return string
	 */
	protected function render_portal_shell( $content ) {
		ob_start();
		?>
		<div class="leagueflow leagueflow-portal alignfull" data-leagueflow-user-id="<?php echo esc_attr( (string) get_current_user_id() ); ?>">
			<?php echo wp_kses_post( $this->render_notice() ); ?>
			<div class="leagueflow-portal__layout">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal render methods return escaped markup. ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Resolve the requested role when both registration windows overlap.
	 *
	 * @return string
	 */
	protected function get_requested_onboarding_path() {
		$path = sanitize_key( wp_unslash( $_GET['leagueflow_path'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $path, array( 'captain', 'player' ), true ) ? $path : '';
	}

	/**
	 * Resolve the selected dashboard view for users with more than one role.
	 *
	 * @return string
	 */
	protected function get_requested_dashboard_view() {
		$view = sanitize_key( wp_unslash( $_GET['leagueflow_view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $view, array( 'teams', 'player' ), true ) ? $view : '';
	}

	/**
	 * Render server-backed role navigation that also works without JavaScript.
	 *
	 * @param string $active_view Active view.
	 * @param bool   $has_manager_view Whether the manager view is available.
	 * @param bool   $has_player_view Whether the player view is available.
	 * @param bool   $show_create_team Whether to show a create-team action.
	 * @return string
	 */
	protected function render_dashboard_navigation( $active_view, $has_manager_view, $has_player_view, $show_create_team ) {
		$portal_url = remove_query_arg( array( 'leagueflow_path', 'leagueflow_view', 'leagueflow_team', 'leagueflow_notice' ), $this->get_portal_url() );

		ob_start();
		?>
		<nav class="leagueflow-portal__view-nav" aria-label="<?php echo esc_attr__( 'Portal views', 'leagueflow' ); ?>">
			<div class="leagueflow-portal__view-tabs">
				<?php if ( $has_manager_view ) : ?>
					<a class="leagueflow-portal__view-tab<?php echo 'teams' === $active_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'leagueflow_view', 'teams', $portal_url ) ); ?>"<?php echo 'teams' === $active_view ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Teams I Manage', 'leagueflow' ); ?></a>
				<?php endif; ?>
				<?php if ( $has_player_view ) : ?>
					<a class="leagueflow-portal__view-tab<?php echo 'player' === $active_view ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'leagueflow_view', 'player', $portal_url ) ); ?>"<?php echo 'player' === $active_view ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'My Player Profile', 'leagueflow' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( $show_create_team ) : ?>
				<a class="leagueflow-portal__button" href="<?php echo esc_url( add_query_arg( 'leagueflow_path', 'captain', $portal_url ) ); ?>"><?php esc_html_e( 'Create a team', 'leagueflow' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Whether a user without an existing profile may begin self-registration.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function user_can_begin_registration( $user ) {
		return current_user_can( 'manage_options' ) || ( $user instanceof \WP_User && is_registration_email_allowed( $user->user_email ) );
	}

	/**
	 * Render the overlap role chooser.
	 *
	 * @return string
	 */
	protected function render_role_choice() {
		$portal_url = $this->get_portal_url();

		ob_start();
		?>
		<section class="leagueflow-portal__panel leagueflow-portal__panel--wide leagueflow-portal__role-choice">
			<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Registration', 'leagueflow' ); ?></p>
			<h2><?php esc_html_e( 'How are you joining?', 'leagueflow' ); ?></h2>
			<div class="leagueflow-portal__role-actions">
				<a class="leagueflow-portal__role-action" href="<?php echo esc_url( add_query_arg( 'leagueflow_path', 'captain', $portal_url ) ); ?>">
					<strong><?php esc_html_e( 'Create a team', 'leagueflow' ); ?></strong>
					<span><?php esc_html_e( 'Set up your captain profile and register one team.', 'leagueflow' ); ?></span>
				</a>
				<a class="leagueflow-portal__role-action" href="<?php echo esc_url( add_query_arg( 'leagueflow_path', 'player', $portal_url ) ); ?>">
					<strong><?php esc_html_e( 'Join a team', 'leagueflow' ); ?></strong>
					<span><?php esc_html_e( 'Build your player profile and choose teams by sport.', 'leagueflow' ); ?></span>
				</a>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the state shown to new users when registration is closed.
	 *
	 * @return string
	 */
	protected function render_registration_closed() {
		return $this->render_panel(
			__( 'Registration is closed', 'leagueflow' ),
			'<p>' . esc_html__( 'Captain and player registration are not open right now. Existing captains and players can still sign in to manage their teams and profiles.', 'leagueflow' ) . '</p>'
		);
	}

	/**
	 * Render a clear domain restriction message for new registrations.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	protected function render_registration_email_denied( $user ) {
		$email = $user instanceof \WP_User ? $user->user_email : '';

		return $this->render_panel(
			__( 'Use your UNBC email', 'leagueflow' ),
			'<p>' . sprintf(
				/* translators: %s: signed-in email address. */
				esc_html__( '%s cannot be used for a new intramurals registration. Sign out and continue with your verified UNBC email address.', 'leagueflow' ),
				esc_html( $email )
			) . '</p><a class="leagueflow-portal__button" href="' . esc_url( wp_logout_url( $this->get_portal_url() ) ) . '">' . esc_html__( 'Use another account', 'leagueflow' ) . '</a>'
		);
	}

	/**
	 * Process portal forms.
	 *
	 * @return void
	 */
	public function handle_form_submission() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->get_portal_url() ) );
			exit;
		}

		$user = wp_get_current_user();

		if ( ! $this->user_can_access_portal( $user, $this->get_player_id_for_user( $user, false ), $this->get_manager_teams( $user->ID ) ) ) {
			$this->redirect_with_notice( 'access-denied' );
		}

		$portal_action = sanitize_key( wp_unslash( $_POST['leagueflow_portal_action'] ?? '' ) );
		$nonce         = sanitize_text_field( wp_unslash( $_POST['leagueflow_portal_nonce'] ?? '' ) );

		if ( ! $portal_action || ! wp_verify_nonce( $nonce, 'leagueflow_portal_' . $portal_action ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		switch ( $portal_action ) {
			case 'complete_player_onboarding':
				$this->handle_complete_player_onboarding( $user );
				break;

			case 'complete_captain_onboarding':
				$this->handle_complete_captain_onboarding( $user );
				break;

			case 'update_player_profile':
				$this->handle_update_player_profile( $user );
				break;

			case 'request_join_team':
				$this->handle_request_join_team( $user );
				break;

			case 'update_team_profile':
				$this->handle_update_team_profile( $user );
				break;

			case 'create_team':
				$this->handle_complete_captain_onboarding( $user );
				break;

			case 'add_player':
				$this->handle_add_player( $user );
				break;

			case 'update_roster_player':
				$this->handle_update_roster_player( $user );
				break;

			case 'remove_roster_player':
				$this->handle_remove_roster_player( $user );
				break;

			case 'review_join_request':
				$this->handle_review_join_request( $user );
				break;

			case 'set_availability':
				$this->handle_set_availability( $user );
				break;

			default:
				$this->redirect_with_notice( 'invalid-request' );
		}
	}

	/**
	 * Reset linked player profile names when a user is removed in WordPress.
	 *
	 * Keeps the player record for existing team relationships, but clears
	 * the saved profile name so a later user who reclaims the same email is
	 * required to go through first-time setup again.
	 *
	 * @param int      $user_id  Deleted user ID.
	 * @param int      $reassign Unused reassign ID from the delete action.
	 * @param \WP_User $user     Deleted user object.
	 * @return void
	 */
	public function handle_wp_user_deleted( $user_id, $reassign = 0, $user = null ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$players = get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_user_id',
						'value' => $user_id,
					),
				),
			)
		);

		foreach ( $players as $player_id ) {
			$player_id = absint( $player_id );

			if ( ! $player_id ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => '',
				)
			);
			delete_post_meta( $player_id, 'lf_user_id' );
		}

		do_action( 'leagueflow_player_profiles_cleared_for_deleted_user', $user_id, $players, $user );
	}

	/**
	 * Render the logged-out portal state.
	 *
	 * @return string
	 */
	protected function render_logged_out() {
		ob_start();
		?>
		<div class="leagueflow leagueflow-portal leagueflow-portal--auth alignfull">
			<div class="leagueflow-portal__panel">
				<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'UNBC Intramurals', 'leagueflow' ); ?></p>
				<h1><?php esc_html_e( 'Sign in to your team portal', 'leagueflow' ); ?></h1>
				<p><?php esc_html_e( 'Use the username and temporary password provided by the intramurals admin to see your profile and upcoming games.', 'leagueflow' ); ?></p>
				<a class="leagueflow-portal__button" href="<?php echo esc_url( wp_login_url( $this->get_portal_url() ) ); ?>"><?php esc_html_e( 'Sign in', 'leagueflow' ); ?></a>
			</div>
		</div>
		<?php
		$default_html = (string) ob_get_clean();

		/**
		 * Filter the portal's logged-out panel.
		 *
		 * Allows an auth provider (e.g. Clerk) to replace the default WordPress
		 * login prompt with its own embedded sign-in experience.
		 *
		 * @param string $default_html Default logged-out markup.
		 * @param string $portal_url   Portal URL to return to after sign-in.
		 */
		return apply_filters( 'leagueflow_portal_logged_out_html', $default_html, $this->get_portal_url() );
	}

	/**
	 * Render an access denied message.
	 *
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_access_denied( $user ) {
		ob_start();
		?>
		<div class="leagueflow leagueflow-portal leagueflow-portal--auth alignfull">
			<div class="leagueflow-portal__panel">
				<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Access denied', 'leagueflow' ); ?></p>
				<h1><?php esc_html_e( 'No portal profile is linked', 'leagueflow' ); ?></h1>
				<p>
					<?php
					printf(
						/* translators: %s: current user email */
						esc_html__( 'This login is not connected to a player profile or managed team. You are signed in as %s.', 'leagueflow' ),
						esc_html( $user->user_login )
					);
					?>
				</p>
				<a class="leagueflow-portal__button" href="<?php echo esc_url( wp_logout_url( $this->get_portal_url() ) ); ?>"><?php esc_html_e( 'Use another account', 'leagueflow' ); ?></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render player dashboard.
	 *
	 * @param int      $player_id Player post ID.
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_player_dashboard( $player_id, $user ) {
		if ( ! $player_id ) {
			return $this->render_panel(
				__( 'Player Profile', 'leagueflow' ),
				'<p>' . esc_html__( 'No player record is linked to this account yet.', 'leagueflow' ) . '</p>'
			);
		}

		$player     = get_post( $player_id );

		if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) {
			return $this->render_panel(
				__( 'Player Profile', 'leagueflow' ),
				'<p>' . esc_html__( 'No player record is linked to this account yet.', 'leagueflow' ) . '</p>'
			);
		}

		$team_ids       = get_player_team_ids( $player_id );
		$photo          = get_post_image( $player_id, 'medium', 'leagueflow-portal__avatar' );
		$upcoming_games = $this->get_upcoming_matches_for_teams( $team_ids, 6 );
		$profile_form_id = 'leagueflow-profile-form-' . absint( $player_id );
		$needs_initial_setup = $this->player_needs_initial_setup( $player_id, $user );
		$preference_summary  = $this->render_player_sport_level_summary( $player_id );

		if ( $needs_initial_setup ) {
			return $this->render_player_setup( $player_id, $player, $user, $profile_form_id, $photo );
		}

		ob_start();
		?>
		<section class="leagueflow-portal__panel leagueflow-portal__panel--wide">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Player', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'My Profile', 'leagueflow' ); ?></h2>
				</div>
				<div class="leagueflow-portal__identity">
					<label class="leagueflow-portal__avatar-upload" title="<?php echo esc_attr__( 'Upload profile image', 'leagueflow' ); ?>">
						<?php if ( ! empty( $photo ) ) : ?>
							<?php echo wp_kses_post( $photo ); ?>
						<?php else : ?>
							<span class="leagueflow-portal__avatar-placeholder"><?php echo esc_html( strtoupper( substr( $player->post_title, 0, 1 ) ) ); ?></span>
						<?php endif; ?>
						<span class="leagueflow-portal__avatar-upload-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false">
								<path d="M12 16V5"></path>
								<path d="M8 9l4-4 4 4"></path>
								<path d="M5 19h14"></path>
							</svg>
						</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Upload profile image', 'leagueflow' ); ?></span>
						<input class="leagueflow-portal__avatar-upload-input" form="<?php echo esc_attr( $profile_form_id ); ?>" type="file" name="lf_player_photo" accept="image/*" />
					</label>
				</div>
			</div>

			<div class="leagueflow-portal__summary">
				<span><strong><?php esc_html_e( 'Name', 'leagueflow' ); ?></strong><?php echo esc_html( $player->post_title ); ?></span>
				<span><strong><?php esc_html_e( 'Email', 'leagueflow' ); ?></strong><?php echo esc_html( $user->user_email ); ?></span>
				<span><strong><?php esc_html_e( 'Teams', 'leagueflow' ); ?></strong><?php echo esc_html( (string) count( $team_ids ) ); ?></span>
				<span><strong><?php esc_html_e( 'Requests', 'leagueflow' ); ?></strong><?php echo esc_html( (string) $this->count_pending_join_requests( $player_id ) ); ?></span>
				<span><strong><?php esc_html_e( 'Upcoming', 'leagueflow' ); ?></strong><?php echo esc_html( (string) count( $upcoming_games ) ); ?></span>
				<?php if ( '' !== $preference_summary ) : ?>
					<span><strong><?php esc_html_e( 'Sport preferences', 'leagueflow' ); ?></strong><?php echo esc_html( $preference_summary ); ?></span>
				<?php endif; ?>
			</div>

			<?php echo $this->render_player_team_memberships( $player_id, $team_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<form id="<?php echo esc_attr( $profile_form_id ); ?>" class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php echo $this->render_hidden_fields( 'update_player_profile' ); ?>
				<label>
					<span><?php esc_html_e( 'Description', 'leagueflow' ); ?></span>
					<textarea name="lf_player_description" rows="6"><?php echo esc_textarea( $player->post_content ); ?></textarea>
				</label>
				<?php echo $this->render_player_sport_level_fields( $player_id ); ?>
				<button type="submit"><?php esc_html_e( 'Save profile', 'leagueflow' ); ?></button>
			</form>

			<div class="leagueflow-portal__subsection">
				<h3><?php esc_html_e( 'Upcoming Games', 'leagueflow' ); ?></h3>
				<?php echo ! empty( $team_ids ) ? $this->render_player_rsvp_matches( $player_id, $team_ids, 6 ) : '<p>' . esc_html__( 'You are not assigned to a team yet.', 'leagueflow' ) . '</p>'; ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h3><?php esc_html_e( 'Request a Team', 'leagueflow' ); ?></h3>
				<?php echo $this->render_join_request_form( $player_id, $user ); ?>
				<?php echo $this->render_player_join_requests( $player_id ); ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the player's role and roster fields independently for each team.
	 *
	 * @param int        $player_id Player ID.
	 * @param array<int> $team_ids Team IDs.
	 * @return string
	 */
	protected function render_player_team_memberships( $player_id, $team_ids ) {
		ob_start();
		?>
		<div class="leagueflow-portal__subsection leagueflow-portal__playing-teams">
			<h3><?php esc_html_e( 'My Playing Teams', 'leagueflow' ); ?></h3>
			<?php if ( empty( $team_ids ) ) : ?>
				<p><?php esc_html_e( 'You are not assigned to a team yet.', 'leagueflow' ); ?></p>
			<?php else : ?>
				<div class="leagueflow-portal__membership-list">
					<?php foreach ( $team_ids as $team_id ) : ?>
						<?php
						$team = get_post( $team_id );
						if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
							continue;
						}
						$detail    = get_player_team_detail( $player_id, $team_id );
						$permalink = get_permalink( $team_id );
						?>
						<article class="leagueflow-portal__membership-row">
							<div>
								<h4><?php echo $permalink ? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $team->post_title ) . '</a>' : esc_html( $team->post_title ); ?></h4>
								<p><?php echo esc_html( $this->get_sport_label( get_post_primary_term_slug( $team_id, 'lf_sport' ) ) ); ?> <span aria-hidden="true">/</span> <?php echo esc_html( get_post_league_level_label( $team_id ) ?: __( 'Level not specified', 'leagueflow' ) ); ?></p>
							</div>
							<div class="leagueflow-portal__membership-details">
								<span><strong><?php esc_html_e( 'Role', 'leagueflow' ); ?></strong><?php echo ! empty( $detail['is_captain'] ) ? esc_html__( 'Playing captain', 'leagueflow' ) : esc_html__( 'Player', 'leagueflow' ); ?></span>
								<span><strong><?php esc_html_e( 'No.', 'leagueflow' ); ?></strong><?php echo '' !== $detail['jersey_number'] ? esc_html( (string) $detail['jersey_number'] ) : esc_html__( 'Not set', 'leagueflow' ); ?></span>
								<span><strong><?php esc_html_e( 'Position', 'leagueflow' ); ?></strong><?php echo '' !== $detail['position'] ? esc_html( $detail['position'] ) : esc_html__( 'Not set', 'leagueflow' ); ?></span>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the required first-time profile setup screen.
	 *
	 * @param int      $player_id Player ID.
	 * @param \WP_Post|null $player Player post, or null for a new registration.
	 * @param \WP_User $user Current user.
	 * @param string   $profile_form_id Form ID.
	 * @param string   $photo Existing profile image markup.
	 * @return string
	 */
	protected function render_player_setup( $player_id, $player, $user, $profile_form_id, $photo ) {
		$draft               = $this->get_onboarding_draft( 'player' );
		$placeholder_initial = strtoupper( substr( (string) ( $user->display_name ?: $user->user_login ), 0, 1 ) );
		$needs_name_setup    = $this->player_name_needs_setup( $player, $user );
		$name_value          = isset( $draft['name'] ) ? (string) $draft['name'] : ( ! $needs_name_setup && $player instanceof \WP_Post ? (string) $player->post_title : '' );
		$description_value   = isset( $draft['description'] ) ? (string) $draft['description'] : ( $player instanceof \WP_Post ? (string) $player->post_content : '' );
		$draft_sports        = isset( $draft['sports'] ) && is_array( $draft['sports'] ) ? $draft['sports'] : array();
		$draft_levels        = isset( $draft['levels'] ) && is_array( $draft['levels'] ) ? $draft['levels'] : array();
		$draft_choices       = isset( $draft['choices'] ) && is_array( $draft['choices'] ) ? $draft['choices'] : array();
		$draft_notes         = isset( $draft['notes'] ) && is_array( $draft['notes'] ) ? $draft['notes'] : array();
		$sport_groups        = $this->get_requestable_sport_teams();
		$levels              = $this->get_league_level_terms();
		$preferences         = $this->get_player_sport_level_preferences( $player_id );
		$states              = $this->get_player_registration_states( $player_id );

		ob_start();
		?>
		<section class="leagueflow-portal__panel leagueflow-portal__panel--setup leagueflow-portal__panel--wide">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Player registration', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'Set Up Your Player Profile', 'leagueflow' ); ?></h2>
				</div>
				<div class="leagueflow-portal__identity">
					<label class="leagueflow-portal__avatar-upload" title="<?php echo esc_attr__( 'Upload profile image', 'leagueflow' ); ?>">
						<?php if ( ! empty( $photo ) ) : ?>
							<?php echo wp_kses_post( $photo ); ?>
						<?php else : ?>
							<span class="leagueflow-portal__avatar-placeholder"><?php echo esc_html( $placeholder_initial ); ?></span>
						<?php endif; ?>
						<span class="leagueflow-portal__avatar-upload-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false">
								<path d="M12 16V5"></path>
								<path d="M8 9l4-4 4 4"></path>
								<path d="M5 19h14"></path>
							</svg>
						</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Upload profile image', 'leagueflow' ); ?></span>
						<input class="leagueflow-portal__avatar-upload-input" form="<?php echo esc_attr( $profile_form_id ); ?>" type="file" name="lf_player_photo" accept="image/*" data-review-label="<?php echo esc_attr__( 'Profile photo', 'leagueflow' ); ?>" />
					</label>
				</div>
			</div>

			<form id="<?php echo esc_attr( $profile_form_id ); ?>" class="leagueflow-portal__form leagueflow-portal__onboarding" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-leagueflow-onboarding data-onboarding-kind="player" data-verified-email="<?php echo esc_attr( $user->user_email ); ?>">
				<?php echo $this->render_hidden_fields( 'complete_player_onboarding' ); ?>
				<?php echo $this->render_onboarding_progress( array( __( 'Profile', 'leagueflow' ), __( 'Sports and teams', 'leagueflow' ), __( 'Review', 'leagueflow' ) ) ); ?>

				<fieldset class="leagueflow-portal__onboarding-step" data-leagueflow-step="1">
					<legend class="screen-reader-text"><?php esc_html_e( 'Step 1: Profile', 'leagueflow' ); ?></legend>
					<div class="leagueflow-portal__summary">
						<span><strong><?php esc_html_e( 'Verified email', 'leagueflow' ); ?></strong><?php echo esc_html( $user->user_email ); ?></span>
					</div>
					<label>
						<span><?php esc_html_e( 'Full name', 'leagueflow' ); ?></span>
						<input type="text" name="lf_player_name" value="<?php echo esc_attr( $name_value ); ?>" autocomplete="name" required autofocus data-review-label="<?php echo esc_attr__( 'Name', 'leagueflow' ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Short bio (optional)', 'leagueflow' ); ?></span>
						<textarea name="lf_player_description" rows="4" placeholder="<?php echo esc_attr__( 'Share anything teammates should know.', 'leagueflow' ); ?>" data-review-label="<?php echo esc_attr__( 'Bio', 'leagueflow' ); ?>"><?php echo esc_textarea( $description_value ); ?></textarea>
					</label>
					<div class="leagueflow-portal__step-actions">
						<button type="button" data-leagueflow-step-next><?php esc_html_e( 'Continue', 'leagueflow' ); ?></button>
					</div>
				</fieldset>

				<fieldset class="leagueflow-portal__onboarding-step" data-leagueflow-step="2">
					<legend class="screen-reader-text"><?php esc_html_e( 'Step 2: Sports and teams', 'leagueflow' ); ?></legend>
					<p class="leagueflow-portal__form-help"><?php esc_html_e( 'Choose at least one sport. Select a level first, then a team or staff placement.', 'leagueflow' ); ?></p>
					<div class="leagueflow-portal__sport-preference-list" data-leagueflow-player-onboarding-sports>
						<?php foreach ( $sport_groups as $sport_slug => $group ) : ?>
							<?php
							$state             = $states[ $sport_slug ] ?? array();
							$is_locked         = ! empty( $state['team_id'] ) || ! empty( $state['request_id'] );
							$is_selected       = $is_locked || ( ! empty( $draft ) ? in_array( $sport_slug, $draft_sports, true ) : isset( $preferences[ $sport_slug ] ) );
							$selected_level_id = ! empty( $state['level_id'] ) ? absint( $state['level_id'] ) : ( isset( $draft_levels[ $sport_slug ] ) ? absint( $draft_levels[ $sport_slug ] ) : ( isset( $preferences[ $sport_slug ]['level_id'] ) ? absint( $preferences[ $sport_slug ]['level_id'] ) : 0 ) );
							$selected_choice   = isset( $draft_choices[ $sport_slug ] ) ? (string) $draft_choices[ $sport_slug ] : '';
							$input_id          = 'leagueflow-onboarding-sport-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
							$level_id          = 'leagueflow-onboarding-level-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
							$team_id           = 'leagueflow-onboarding-team-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
							?>
							<div class="leagueflow-portal__sport-onboarding-row<?php echo $is_selected ? ' is-selected' : ''; ?><?php echo $is_locked ? ' is-locked' : ''; ?>" data-leagueflow-player-sport-row data-sport-label="<?php echo esc_attr( $group['label'] ); ?>">
								<?php if ( $is_locked ) : ?>
									<input type="hidden" name="lf_player_sports[]" value="<?php echo esc_attr( $sport_slug ); ?>" />
									<input type="hidden" name="lf_player_sport_levels[<?php echo esc_attr( $sport_slug ); ?>]" value="<?php echo esc_attr( (string) $selected_level_id ); ?>" />
									<strong><?php echo esc_html( $group['label'] ); ?></strong>
									<span>
										<?php
										if ( ! empty( $state['team_id'] ) ) {
											echo esc_html( get_the_title( $state['team_id'] ) );
										} else {
											echo esc_html( ! empty( $state['team_name'] ) ? $state['team_name'] . ' - pending' : __( 'Staff placement pending', 'leagueflow' ) );
										}
										?>
									</span>
								<?php else : ?>
									<label class="leagueflow-portal__sport-choice" for="<?php echo esc_attr( $input_id ); ?>">
										<input id="<?php echo esc_attr( $input_id ); ?>" type="checkbox" name="lf_player_sports[]" value="<?php echo esc_attr( $sport_slug ); ?>" <?php checked( $is_selected ); ?> data-leagueflow-player-sport-toggle />
										<span><?php echo esc_html( $group['label'] ); ?></span>
									</label>
									<div class="leagueflow-portal__sport-onboarding-controls">
										<label for="<?php echo esc_attr( $level_id ); ?>">
											<span><?php esc_html_e( 'Level', 'leagueflow' ); ?></span>
											<select id="<?php echo esc_attr( $level_id ); ?>" name="lf_player_sport_levels[<?php echo esc_attr( $sport_slug ); ?>]" <?php echo $is_selected ? 'required' : ''; ?> data-leagueflow-player-level-select>
												<option value=""><?php esc_html_e( 'Choose a level', 'leagueflow' ); ?></option>
												<?php foreach ( $levels as $level ) : ?>
													<option value="<?php echo esc_attr( (string) $level->term_id ); ?>" <?php selected( $selected_level_id, (int) $level->term_id ); ?>><?php echo esc_html( $level->name ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label for="<?php echo esc_attr( $team_id ); ?>">
											<span><?php esc_html_e( 'Team', 'leagueflow' ); ?></span>
											<select id="<?php echo esc_attr( $team_id ); ?>" name="lf_player_team_choices[<?php echo esc_attr( $sport_slug ); ?>]" <?php echo $is_selected ? 'required' : ''; ?> data-leagueflow-onboarding-team-select>
												<option value=""><?php esc_html_e( 'Choose a team', 'leagueflow' ); ?></option>
												<?php foreach ( $group['teams'] as $team ) : ?>
													<?php $team_level_id = get_post_primary_term_id( $team->ID, 'lf_league_level' ); ?>
													<option value="<?php echo esc_attr( (string) $team->ID ); ?>" data-level-id="<?php echo esc_attr( (string) $team_level_id ); ?>" <?php selected( $selected_choice, (string) $team->ID ); ?>><?php echo esc_html( get_the_title( $team ) ); ?></option>
												<?php endforeach; ?>
												<option value="0" data-placement-option <?php selected( $selected_choice, '0' ); ?>><?php esc_html_e( "I don't have a team - place me", 'leagueflow' ); ?></option>
											</select>
										</label>
										<label>
											<span><?php esc_html_e( 'Note (optional)', 'leagueflow' ); ?></span>
											<textarea name="lf_player_request_notes[<?php echo esc_attr( $sport_slug ); ?>]" rows="2" data-leagueflow-onboarding-note placeholder="<?php echo esc_attr__( 'Availability, experience, or preferred role.', 'leagueflow' ); ?>"><?php echo esc_textarea( (string) ( $draft_notes[ $sport_slug ] ?? '' ) ); ?></textarea>
										</label>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="leagueflow-portal__field-error" data-leagueflow-sport-error hidden><?php esc_html_e( 'Choose at least one sport.', 'leagueflow' ); ?></p>
					<div class="leagueflow-portal__step-actions">
						<button type="button" class="leagueflow-portal__button--secondary" data-leagueflow-step-back><?php esc_html_e( 'Back', 'leagueflow' ); ?></button>
						<button type="button" data-leagueflow-step-next><?php esc_html_e( 'Review', 'leagueflow' ); ?></button>
					</div>
				</fieldset>

				<fieldset class="leagueflow-portal__onboarding-step" data-leagueflow-step="3">
					<legend class="screen-reader-text"><?php esc_html_e( 'Step 3: Review', 'leagueflow' ); ?></legend>
					<h3><?php esc_html_e( 'Review your registration', 'leagueflow' ); ?></h3>
					<div class="leagueflow-portal__review" data-leagueflow-onboarding-review>
						<p><?php esc_html_e( 'Your selected teams will receive approval requests. Staff will handle any placement selections.', 'leagueflow' ); ?></p>
						<ul data-leagueflow-review-list></ul>
					</div>
					<div class="leagueflow-portal__step-actions">
						<button type="button" class="leagueflow-portal__button--secondary" data-leagueflow-step-back><?php esc_html_e( 'Back', 'leagueflow' ); ?></button>
						<button type="submit"><?php esc_html_e( 'Finish profile and submit requests', 'leagueflow' ); ?></button>
					</div>
				</fieldset>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render manager dashboard.
	 *
	 * @param array<int, \WP_Post> $teams Teams.
	 * @param int                   $player_id Linked player ID.
	 * @param bool                  $show_workspace Whether to render the selected team workspace.
	 * @return string
	 */
	protected function render_manager_dashboard( $teams, $player_id = 0, $show_workspace = true ) {
		$selected_team_id = $show_workspace ? $this->get_requested_manager_team_id( $teams ) : 0;
		$eligible_sports  = is_captain_registration_enabled() ? $this->get_eligible_captain_sports( $player_id, $teams ) : array();
		$portal_url       = remove_query_arg( array( 'leagueflow_path', 'leagueflow_team', 'leagueflow_notice' ), $this->get_portal_url() );
		$selected_team    = null;

		foreach ( $teams as $team ) {
			if ( (int) $team->ID === $selected_team_id ) {
				$selected_team = $team;
				break;
			}
		}

		ob_start();
		?>
		<section id="my-teams" class="leagueflow-portal__panel leagueflow-portal__panel--wide">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Manager', 'leagueflow' ); ?></p>
					<h2><?php esc_html_e( 'My Teams', 'leagueflow' ); ?></h2>
				</div>
				<?php if ( ! empty( $eligible_sports ) ) : ?>
					<a class="leagueflow-portal__button" href="<?php echo esc_url( add_query_arg( 'leagueflow_path', 'captain', $portal_url ) ); ?>"><?php esc_html_e( 'Create another team', 'leagueflow' ); ?></a>
				<?php endif; ?>
			</div>

			<div class="leagueflow-portal__managed-team-list">
				<?php foreach ( $teams as $team ) : ?>
					<?php
					$team_id      = (int) $team->ID;
					$sport_label  = $this->get_sport_label( get_post_primary_term_slug( $team_id, 'lf_sport' ) );
					$level_label  = get_post_league_level_label( $team_id );
					$roster_count = count( get_team_roster_player_posts( $team_id ) );
					$detail       = $player_id && player_has_team( $player_id, $team_id ) ? get_player_team_detail( $player_id, $team_id ) : null;
					$manage_url   = add_query_arg(
						array(
							'leagueflow_view' => 'teams',
							'leagueflow_team' => $team_id,
						),
						$portal_url
					) . '#team-workspace';
					?>
					<article class="leagueflow-portal__managed-team<?php echo $selected_team_id === $team_id ? ' is-selected' : ''; ?>">
						<div class="leagueflow-portal__managed-team-main">
							<h3><?php echo esc_html( get_the_title( $team ) ); ?></h3>
							<div class="leagueflow-portal__managed-team-meta">
								<span><strong><?php esc_html_e( 'Sport', 'leagueflow' ); ?></strong><?php echo esc_html( $sport_label ); ?></span>
								<span><strong><?php esc_html_e( 'Level', 'leagueflow' ); ?></strong><?php echo esc_html( $level_label ? $level_label : __( 'Not specified', 'leagueflow' ) ); ?></span>
								<span><strong><?php esc_html_e( 'Roster', 'leagueflow' ); ?></strong><?php echo esc_html( (string) $roster_count ); ?></span>
							</div>
							<div class="leagueflow-portal__role-badges">
								<span><?php esc_html_e( 'Manager', 'leagueflow' ); ?></span>
								<?php if ( is_array( $detail ) ) : ?>
									<span><?php echo ! empty( $detail['is_captain'] ) ? esc_html__( 'Playing captain', 'leagueflow' ) : esc_html__( 'Player', 'leagueflow' ); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<a class="leagueflow-portal__button leagueflow-portal__button--secondary" href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage team', 'leagueflow' ); ?></a>
					</article>
				<?php endforeach; ?>
			</div>

			<?php if ( is_captain_registration_enabled() && empty( $eligible_sports ) ) : ?>
				<p class="leagueflow-portal__form-help"><?php esc_html_e( 'You already manage, play in, or have a pending request for every available sport.', 'leagueflow' ); ?></p>
			<?php endif; ?>
		</section>

		<?php if ( $selected_team instanceof \WP_Post ) : ?>
			<section id="team-workspace" class="leagueflow-portal__panel leagueflow-portal__panel--wide leagueflow-portal__team-workspace">
				<?php echo $this->render_manager_team( $selected_team, $player_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the captain team registration panel.
	 *
	 * @param \WP_User              $user Current user.
	 * @param array<int, \WP_Post> $manager_teams Existing managed teams.
	 * @param int                   $player_id Linked player ID.
	 * @return string
	 */
	protected function render_captain_registration_panel( $user, $manager_teams = array(), $player_id = 0 ) {
		$draft                    = $this->get_onboarding_draft( 'captain' );
		$player                   = $player_id ? get_post( $player_id ) : null;
		$is_repeat                = ! empty( $manager_teams );
		$sports                   = $this->get_eligible_captain_sports( $player_id, $manager_teams );
		$name                     = $is_repeat && $player instanceof \WP_Post
			? $player->post_title
			: ( isset( $draft['name'] ) ? (string) $draft['name'] : ( $player instanceof \WP_Post && ! $this->player_name_needs_setup( $player, $user ) ? $player->post_title : $this->get_default_user_full_name( $user ) ) );
		$player_description       = isset( $draft['player_description'] ) ? (string) $draft['player_description'] : ( $player instanceof \WP_Post ? $player->post_content : '' );
		$draft_sport              = sanitize_key( (string) ( $draft['sport_slug'] ?? '' ) );
		$draft_level              = absint( $draft['level_id'] ?? 0 );
		$draft_team               = (string) ( $draft['team_name'] ?? '' );
		$draft_short              = (string) ( $draft['short_name'] ?? '' );
		$draft_team_description   = (string) ( $draft['team_description'] ?? '' );
		$team_step                = $is_repeat ? 1 : 2;
		$review_step              = $is_repeat ? 2 : 3;
		$profile_url              = add_query_arg( 'leagueflow_view', 'player', remove_query_arg( array( 'leagueflow_path', 'leagueflow_team', 'leagueflow_notice' ), $this->get_portal_url() ) );

		if ( empty( $sports ) ) {
			return $this->render_panel(
				__( 'No additional sports available', 'leagueflow' ),
				'<p>' . esc_html__( 'You already manage, play in, or have a pending request for every available sport.', 'leagueflow' ) . '</p><a class="leagueflow-portal__button leagueflow-portal__button--secondary" href="' . esc_url( remove_query_arg( 'leagueflow_path', $this->get_portal_url() ) ) . '">' . esc_html__( 'Back to my teams', 'leagueflow' ) . '</a>'
			);
		}

		ob_start();
		?>
		<section class="leagueflow-portal__panel leagueflow-portal__panel--wide leagueflow-portal__panel--setup">
			<div class="leagueflow-portal__panel-head">
				<div>
					<p class="leagueflow-portal__eyebrow"><?php esc_html_e( 'Captain', 'leagueflow' ); ?></p>
					<h2><?php echo esc_html( empty( $manager_teams ) ? __( 'Set Up Your Team', 'leagueflow' ) : __( 'Create Another Team', 'leagueflow' ) ); ?></h2>
				</div>
			</div>

			<?php if ( $is_repeat ) : ?>
				<div class="leagueflow-portal__captain-identity">
					<?php echo wp_kses_post( get_post_image( $player_id, 'thumbnail', 'leagueflow-portal__avatar' ) ); ?>
					<div class="leagueflow-portal__summary">
						<span><strong><?php esc_html_e( 'Captain', 'leagueflow' ); ?></strong><?php echo esc_html( $name ); ?></span>
						<span><strong><?php esc_html_e( 'Verified email', 'leagueflow' ); ?></strong><?php echo esc_html( $user->user_email ); ?></span>
						<span><strong><?php esc_html_e( 'Teams managed', 'leagueflow' ); ?></strong><?php echo esc_html( (string) count( $manager_teams ) ); ?></span>
					</div>
					<a href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Edit player profile', 'leagueflow' ); ?></a>
				</div>
			<?php endif; ?>

			<form class="leagueflow-portal__form leagueflow-portal__onboarding" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-leagueflow-onboarding data-onboarding-kind="captain" data-verified-email="<?php echo esc_attr( $user->user_email ); ?>">
				<?php echo $this->render_hidden_fields( 'complete_captain_onboarding' ); ?>
				<input type="hidden" name="lf_captain_onboarding_mode" value="<?php echo $is_repeat ? 'repeat' : 'initial'; ?>" />
				<?php if ( $is_repeat ) : ?><input type="hidden" name="lf_captain_name" value="<?php echo esc_attr( $name ); ?>" /><?php endif; ?>
				<?php echo $this->render_onboarding_progress( $is_repeat ? array( __( 'Team', 'leagueflow' ), __( 'Review', 'leagueflow' ) ) : array( __( 'Profile', 'leagueflow' ), __( 'Team', 'leagueflow' ), __( 'Review', 'leagueflow' ) ) ); ?>

				<?php if ( ! $is_repeat ) : ?>
					<fieldset class="leagueflow-portal__onboarding-step" data-leagueflow-step="1">
						<legend class="screen-reader-text"><?php esc_html_e( 'Step 1: Captain profile', 'leagueflow' ); ?></legend>
						<div class="leagueflow-portal__summary">
							<span><strong><?php esc_html_e( 'Verified email', 'leagueflow' ); ?></strong><?php echo esc_html( $user->user_email ); ?></span>
						</div>
						<label>
							<span><?php esc_html_e( 'Full name', 'leagueflow' ); ?></span>
							<input type="text" name="lf_captain_name" value="<?php echo esc_attr( $name ); ?>" autocomplete="name" required data-review-label="<?php echo esc_attr__( 'Captain', 'leagueflow' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Profile photo (optional)', 'leagueflow' ); ?></span>
							<input type="file" name="lf_player_photo" accept="image/jpeg,image/png,image/gif,image/webp" data-review-label="<?php echo esc_attr__( 'Profile photo', 'leagueflow' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Short bio (optional)', 'leagueflow' ); ?></span>
							<textarea name="lf_player_description" rows="4" data-review-label="<?php echo esc_attr__( 'Bio', 'leagueflow' ); ?>"><?php echo esc_textarea( $player_description ); ?></textarea>
						</label>
						<div class="leagueflow-portal__step-actions"><button type="button" data-leagueflow-step-next><?php esc_html_e( 'Continue', 'leagueflow' ); ?></button></div>
					</fieldset>
				<?php endif; ?>

				<fieldset class="leagueflow-portal__onboarding-step" data-leagueflow-step="<?php echo esc_attr( (string) $team_step ); ?>">
					<legend class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Step %1$d: Team', 'leagueflow' ), $team_step ) ); ?></legend>
					<div class="leagueflow-portal__form-grid leagueflow-portal__form-grid--even">
						<label>
							<span><?php esc_html_e( 'Sport', 'leagueflow' ); ?></span>
							<select name="lf_sport_slug" required data-review-label="<?php echo esc_attr__( 'Sport', 'leagueflow' ); ?>">
								<option value=""><?php esc_html_e( 'Choose a sport', 'leagueflow' ); ?></option>
								<?php foreach ( $sports as $sport_slug => $sport ) : ?>
									<option value="<?php echo esc_attr( $sport_slug ); ?>" <?php selected( $draft_sport, $sport_slug ); ?>><?php echo esc_html( $sport['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'League level', 'leagueflow' ); ?></span>
							<select name="lf_league_level_id" required data-review-label="<?php echo esc_attr__( 'Level', 'leagueflow' ); ?>">
								<option value=""><?php esc_html_e( 'Choose a level', 'leagueflow' ); ?></option>
								<?php foreach ( $this->get_league_level_terms() as $level ) : ?>
									<option value="<?php echo esc_attr( (string) $level->term_id ); ?>" <?php selected( $draft_level, (int) $level->term_id ); ?>><?php echo esc_html( $level->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<label>
						<span><?php esc_html_e( 'Team name', 'leagueflow' ); ?></span>
						<input type="text" name="lf_team_name" value="<?php echo esc_attr( $draft_team ); ?>" required data-review-label="<?php echo esc_attr__( 'Team', 'leagueflow' ); ?>" />
					</label>
					<div class="leagueflow-portal__form-grid leagueflow-portal__form-grid--even">
						<label>
							<span><?php esc_html_e( 'Short name (optional)', 'leagueflow' ); ?></span>
							<input type="text" name="lf_short_name" value="<?php echo esc_attr( $draft_short ); ?>" data-review-label="<?php echo esc_attr__( 'Short name', 'leagueflow' ); ?>" />
						</label>
						<label>
							<span><?php esc_html_e( 'Team logo (optional)', 'leagueflow' ); ?></span>
							<input type="file" name="lf_team_logo" accept="image/jpeg,image/png,image/gif,image/webp" data-review-label="<?php echo esc_attr__( 'Team logo', 'leagueflow' ); ?>" />
						</label>
					</div>
					<label>
						<span><?php esc_html_e( 'Team description (optional)', 'leagueflow' ); ?></span>
					<textarea name="lf_team_description" rows="4" data-review-label="<?php echo esc_attr__( 'Team description', 'leagueflow' ); ?>"><?php echo esc_textarea( $draft_team_description ); ?></textarea>
					</label>
					<div class="leagueflow-portal__step-actions">
						<?php if ( ! $is_repeat ) : ?><button type="button" class="leagueflow-portal__button--secondary" data-leagueflow-step-back><?php esc_html_e( 'Back', 'leagueflow' ); ?></button><?php endif; ?>
						<button type="button" data-leagueflow-step-next><?php esc_html_e( 'Review', 'leagueflow' ); ?></button>
					</div>
				</fieldset>

				<fieldset class="leagueflow-portal__onboarding-step" data-leagueflow-step="<?php echo esc_attr( (string) $review_step ); ?>">
					<legend class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Step %1$d: Review', 'leagueflow' ), $review_step ) ); ?></legend>
					<h3><?php esc_html_e( 'Review your team', 'leagueflow' ); ?></h3>
					<div class="leagueflow-portal__review" data-leagueflow-onboarding-review><ul data-leagueflow-review-list></ul></div>
					<div class="leagueflow-portal__step-actions">
						<button type="button" class="leagueflow-portal__button--secondary" data-leagueflow-step-back><?php esc_html_e( 'Back', 'leagueflow' ); ?></button>
						<button type="submit"><?php echo esc_html( empty( $manager_teams ) ? __( 'Create team and finish', 'leagueflow' ) : __( 'Create team', 'leagueflow' ) ); ?></button>
					</div>
				</fieldset>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the accessible three-step onboarding progress indicator.
	 *
	 * @param array<int, string> $labels Step labels.
	 * @return string
	 */
	protected function render_onboarding_progress( $labels ) {
		ob_start();
		?>
		<ol class="leagueflow-portal__stepper" aria-label="<?php echo esc_attr__( 'Setup progress', 'leagueflow' ); ?>">
			<?php foreach ( $labels as $index => $label ) : ?>
				<li<?php echo 0 === $index ? ' class="is-current" aria-current="step"' : ''; ?> data-leagueflow-step-indicator="<?php echo esc_attr( (string) ( $index + 1 ) ); ?>">
					<span><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<strong><?php echo esc_html( $label ); ?></strong>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a manager team workspace.
	 *
	 * @param \WP_Post $team Team.
	 * @param int      $player_id Linked player ID.
	 * @return string
	 */
	protected function render_manager_team( $team, $player_id = 0 ) {
		$team_id    = (int) $team->ID;
		$team_logo  = get_post_image( $team_id, 'medium', 'leagueflow-portal__team-logo' );
		$players    = get_team_roster_player_posts( $team_id );
		$short_name = (string) get_post_meta( $team_id, 'lf_short_name', true );
		$requests   = $this->get_team_join_requests( $team_id );
		$sport      = $this->get_sport_label( get_post_primary_term_slug( $team_id, 'lf_sport' ) );
		$level      = get_post_league_level_label( $team_id );
		$detail     = $player_id && player_has_team( $player_id, $team_id ) ? get_player_team_detail( $player_id, $team_id ) : null;

		ob_start();
		?>
		<article class="leagueflow-portal-team">
			<header class="leagueflow-portal-team__header">
				<?php if ( ! empty( $team_logo ) ) : ?>
					<?php echo wp_kses_post( $team_logo ); ?>
				<?php endif; ?>
				<div>
					<h3><?php echo esc_html( get_the_title( $team ) ); ?></h3>
					<?php if ( ! empty( $short_name ) ) : ?><p><?php echo esc_html( $short_name ); ?></p><?php endif; ?>
					<p class="leagueflow-portal-team__meta"><?php echo esc_html( $sport ); ?> <span aria-hidden="true">/</span> <?php echo esc_html( $level ? $level : __( 'Level not specified', 'leagueflow' ) ); ?></p>
					<div class="leagueflow-portal__role-badges">
						<span><?php esc_html_e( 'Manager', 'leagueflow' ); ?></span>
						<?php if ( is_array( $detail ) ) : ?><span><?php echo ! empty( $detail['is_captain'] ) ? esc_html__( 'Playing captain', 'leagueflow' ) : esc_html__( 'Player', 'leagueflow' ); ?></span><?php endif; ?>
					</div>
				</div>
			</header>

			<div class="leagueflow-portal__columns">
				<form class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php echo $this->render_hidden_fields( 'update_team_profile' ); ?>
					<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
					<label>
						<span><?php esc_html_e( 'Team name', 'leagueflow' ); ?></span>
						<input type="text" name="lf_team_name" value="<?php echo esc_attr( get_the_title( $team ) ); ?>" required />
					</label>
					<label>
						<span><?php esc_html_e( 'Short name', 'leagueflow' ); ?></span>
						<input type="text" name="lf_short_name" value="<?php echo esc_attr( $short_name ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Team logo', 'leagueflow' ); ?></span>
						<input type="file" name="lf_team_logo" accept="image/*" />
					</label>
					<label>
						<span><?php esc_html_e( 'Team description', 'leagueflow' ); ?></span>
						<textarea name="lf_team_description" rows="6"><?php echo esc_textarea( $team->post_content ); ?></textarea>
					</label>
					<button type="submit"><?php esc_html_e( 'Save team', 'leagueflow' ); ?></button>
				</form>

				<?php if ( is_player_registration_enabled() ) : ?>
					<form class="leagueflow-portal__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php echo $this->render_hidden_fields( 'add_player' ); ?>
						<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
						<h4><?php esc_html_e( 'Add player', 'leagueflow' ); ?></h4>
						<label>
							<span><?php esc_html_e( 'Player email', 'leagueflow' ); ?></span>
							<input type="email" name="lf_email" />
						</label>
						<label>
							<span><?php esc_html_e( 'Name', 'leagueflow' ); ?></span>
							<input type="text" name="lf_player_name" required />
						</label>
						<div class="leagueflow-portal__form-grid">
							<label>
								<span><?php esc_html_e( 'No.', 'leagueflow' ); ?></span>
								<input type="number" min="0" name="lf_jersey_number" />
							</label>
							<label>
								<span><?php esc_html_e( 'Position', 'leagueflow' ); ?></span>
								<input type="text" name="lf_position" />
							</label>
						</div>
						<label class="leagueflow-portal__checkbox">
							<input type="checkbox" name="lf_is_captain" value="1" />
							<span><?php esc_html_e( 'Captain', 'leagueflow' ); ?></span>
						</label>
						<button type="submit"><?php esc_html_e( 'Add to roster', 'leagueflow' ); ?></button>
					</form>
				<?php else : ?>
					<div class="leagueflow-portal__form">
						<h4><?php esc_html_e( 'Add player', 'leagueflow' ); ?></h4>
						<p class="leagueflow-portal__form-help"><?php esc_html_e( 'Player registration is currently closed.', 'leagueflow' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h4><?php esc_html_e( 'Join Requests', 'leagueflow' ); ?></h4>
				<?php echo $this->render_team_join_requests( $team_id, $requests ); ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h4><?php esc_html_e( 'Roster', 'leagueflow' ); ?></h4>
				<?php if ( empty( $players ) ) : ?>
					<p><?php esc_html_e( 'This team has no players assigned yet.', 'leagueflow' ); ?></p>
				<?php else : ?>
					<div class="leagueflow-portal-roster">
						<?php foreach ( $players as $player ) : ?>
							<?php echo $this->render_roster_player_form( $team_id, $player ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="leagueflow-portal__subsection">
				<h4><?php esc_html_e( 'Upcoming Games', 'leagueflow' ); ?></h4>
				<?php echo $this->render_upcoming_matches( $team_id, 5 ); ?>
				<?php echo $this->render_team_availability_summary( $team_id, 5 ); ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the player's request-to-join form.
	 *
	 * @param int      $player_id Player ID.
	 * @param \WP_User $user Current user.
	 * @return string
	 */
	protected function render_join_request_form( $player_id, $user ) {
		if ( ! is_player_registration_enabled() ) {
			return '<p>' . esc_html__( 'Player registration is currently closed.', 'leagueflow' ) . '</p>';
		}

		$sport_groups = $this->get_requestable_sport_teams();

		if ( empty( $sport_groups ) ) {
			return '<p>' . esc_html__( 'No sports are available for player registration yet.', 'leagueflow' ) . '</p>';
		}

		$player = get_post( $player_id );
		$player_name = $player instanceof \WP_Post ? $player->post_title : get_the_title( $player_id );
		$team_select_id  = 'leagueflow-team-select-' . absint( $player_id );
		$level_select_id = 'leagueflow-level-select-' . absint( $player_id );
		$request_help_id = 'leagueflow-request-help-' . absint( $player_id );

		ob_start();
		?>
		<form class="leagueflow-portal__form leagueflow-portal__form--join-request" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php echo $this->render_hidden_fields( 'request_join_team' ); ?>
			<p id="<?php echo esc_attr( $request_help_id ); ?>" class="leagueflow-portal__form-help" data-leagueflow-team-help>
				<?php
				printf(
					/* translators: %s: current player profile name. */
					esc_html__( 'Requesting as %s. Choose a sport and level, then pick a team or ask staff to place you.', 'leagueflow' ),
					esc_html( $player_name )
				);
				?>
			</p>
			<div class="leagueflow-portal__form-grid leagueflow-portal__form-grid--three">
				<label>
					<span><?php esc_html_e( 'Sport', 'leagueflow' ); ?></span>
					<select name="lf_sport_slug" required data-leagueflow-sport-select data-team-target="<?php echo esc_attr( $team_select_id ); ?>" aria-describedby="<?php echo esc_attr( $request_help_id ); ?>">
						<option value=""><?php esc_html_e( 'Choose a sport', 'leagueflow' ); ?></option>
						<?php foreach ( $sport_groups as $sport_slug => $group ) : ?>
							<option value="<?php echo esc_attr( $sport_slug ); ?>"><?php echo esc_html( $group['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Level', 'leagueflow' ); ?></span>
					<select id="<?php echo esc_attr( $level_select_id ); ?>" name="lf_league_level_id" required disabled data-leagueflow-level-select aria-describedby="<?php echo esc_attr( $request_help_id ); ?>">
						<option value=""><?php esc_html_e( 'Choose a level', 'leagueflow' ); ?></option>
						<?php foreach ( $this->get_league_level_terms() as $level ) : ?>
							<option value="<?php echo esc_attr( (string) $level->term_id ); ?>"><?php echo esc_html( $level->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Team', 'leagueflow' ); ?></span>
					<select id="<?php echo esc_attr( $team_select_id ); ?>" name="lf_team_id" required data-leagueflow-team-select aria-describedby="<?php echo esc_attr( $request_help_id ); ?>">
						<option value=""><?php esc_html_e( 'Choose a sport first', 'leagueflow' ); ?></option>
						<option value="0" data-placement-option hidden disabled><?php esc_html_e( "I don't have a team yet - please place me", 'leagueflow' ); ?></option>
						<?php foreach ( $sport_groups as $sport_slug => $group ) : ?>
							<?php foreach ( $group['teams'] as $team ) : ?>
								<option value="<?php echo esc_attr( (string) $team->ID ); ?>" data-sport="<?php echo esc_attr( $sport_slug ); ?>" data-level-id="<?php echo esc_attr( (string) get_post_primary_term_id( $team->ID, 'lf_league_level' ) ); ?>"><?php echo esc_html( get_the_title( $team ) ); ?></option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<label>
				<span><?php esc_html_e( 'Message to team manager', 'leagueflow' ); ?></span>
				<textarea name="lf_request_note" rows="3" placeholder="<?php echo esc_attr__( 'Optional: share your availability, experience, or preferred role.', 'leagueflow' ); ?>"></textarea>
			</label>
			<button type="submit"><?php esc_html_e( 'Submit request', 'leagueflow' ); ?></button>
		</form>
		<?php
		unset( $user );
		return (string) ob_get_clean();
	}

	/**
	 * Render a player's submitted join requests.
	 *
	 * @param int $player_id Player ID.
	 * @return string
	 */
	protected function render_player_join_requests( $player_id ) {
		$requests = $this->get_player_join_requests( $player_id );

		if ( empty( $requests ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="leagueflow-portal-requests">
			<?php foreach ( $requests as $request ) : ?>
				<?php
				$team_id    = (int) get_post_meta( $request->ID, 'lf_team_id', true );
				$sport_slug = sanitize_key( (string) get_post_meta( $request->ID, 'lf_sport_slug', true ) );
				$status     = sanitize_key( (string) get_post_meta( $request->ID, 'lf_request_status', true ) );
				$level_id   = get_join_request_level_id( $request->ID );
				$level      = $level_id ? get_term( $level_id, 'lf_league_level' ) : null;
				$status     = $status ? $status : 'pending';
				$label      = $team_id ? get_the_title( $team_id ) : sprintf(
					/* translators: %s: sport label. */
					__( 'Placement request: %s', 'leagueflow' ),
					$this->get_sport_label( $sport_slug )
				);
				?>
				<article class="leagueflow-portal-request">
					<div>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( ucfirst( $status ) ); ?><?php echo $level && ! is_wp_error( $level ) ? ' - ' . esc_html( $level->name ) : ''; ?></span>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render pending join requests for a managed team.
	 *
	 * @param int             $team_id Team ID.
	 * @param array<\WP_Post> $requests Pending requests.
	 * @return string
	 */
	protected function render_team_join_requests( $team_id, $requests ) {
		if ( empty( $requests ) ) {
			return '<p>' . esc_html__( 'No pending join requests.', 'leagueflow' ) . '</p>';
		}

		ob_start();
		?>
		<div class="leagueflow-portal-requests">
			<?php foreach ( $requests as $request ) : ?>
				<?php
				$player_id            = (int) get_post_meta( $request->ID, 'lf_player_id', true );
				$request_team_id      = (int) get_post_meta( $request->ID, 'lf_team_id', true );
				$is_placement_request = ! $request_team_id;
				$note                 = (string) get_post_meta( $request->ID, 'lf_request_note', true );
				$email                = $player_id ? (string) get_post_meta( $player_id, 'lf_email', true ) : '';
				?>
				<article class="leagueflow-portal-request">
					<div>
						<strong><?php echo esc_html( $player_id ? get_the_title( $player_id ) : __( 'Player', 'leagueflow' ) ); ?></strong>
						<?php if ( $email ) : ?><span><?php echo esc_html( $email ); ?></span><?php endif; ?>
						<?php if ( $is_placement_request ) : ?><span><?php esc_html_e( 'Needs a team in this sport', 'leagueflow' ); ?></span><?php endif; ?>
						<?php if ( $note ) : ?><p><?php echo esc_html( $note ); ?></p><?php endif; ?>
					</div>
					<div class="leagueflow-portal-request__actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php echo $this->render_hidden_fields( 'review_join_request' ); ?>
							<input type="hidden" name="lf_join_request_id" value="<?php echo esc_attr( (string) $request->ID ); ?>" />
							<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
							<input type="hidden" name="lf_join_decision" value="approve" />
							<button type="submit"><?php echo esc_html( $is_placement_request ? __( 'Add to my roster', 'leagueflow' ) : __( 'Approve', 'leagueflow' ) ); ?></button>
						</form>
						<?php if ( ! $is_placement_request ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php echo $this->render_hidden_fields( 'review_join_request' ); ?>
								<input type="hidden" name="lf_join_request_id" value="<?php echo esc_attr( (string) $request->ID ); ?>" />
								<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
								<input type="hidden" name="lf_join_decision" value="decline" />
								<button type="submit"><?php esc_html_e( 'Decline', 'leagueflow' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a roster edit form for one player.
	 *
	 * @param int      $team_id Team ID.
	 * @param \WP_Post $player Player.
	 * @return string
	 */
	protected function render_roster_player_form( $team_id, $player ) {
		$player_id = (int) $player->ID;
		$detail    = get_player_team_detail( $player_id, $team_id );

		ob_start();
		?>
		<div class="leagueflow-portal-roster__card">
			<form class="leagueflow-portal-roster__item" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo $this->render_hidden_fields( 'update_roster_player' ); ?>
				<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
				<input type="hidden" name="lf_player_id" value="<?php echo esc_attr( (string) $player_id ); ?>" />
				<label>
					<span><?php esc_html_e( 'Name', 'leagueflow' ); ?></span>
					<input type="text" name="lf_player_name" value="<?php echo esc_attr( $player->post_title ); ?>" required />
				</label>
			<label>
				<span><?php esc_html_e( 'Player email', 'leagueflow' ); ?></span>
				<input type="email" name="lf_email" value="<?php echo esc_attr( (string) get_post_meta( $player_id, 'lf_email', true ) ); ?>" />
			</label>
				<div class="leagueflow-portal__form-grid">
					<label>
						<span><?php esc_html_e( 'No.', 'leagueflow' ); ?></span>
						<input type="number" min="0" name="lf_jersey_number" value="<?php echo esc_attr( (string) $detail['jersey_number'] ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Position', 'leagueflow' ); ?></span>
						<input type="text" name="lf_position" value="<?php echo esc_attr( (string) $detail['position'] ); ?>" />
					</label>
				</div>
				<div class="leagueflow-portal-roster__actions">
					<label class="leagueflow-portal__checkbox">
						<input type="checkbox" name="lf_is_captain" value="1" <?php checked( ! empty( $detail['is_captain'] ) ); ?> />
						<span><?php esc_html_e( 'Captain', 'leagueflow' ); ?></span>
					</label>
					<button type="submit"><?php esc_html_e( 'Save', 'leagueflow' ); ?></button>
				</div>
			</form>
			<form class="leagueflow-portal-roster__remove" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php echo $this->render_hidden_fields( 'remove_roster_player' ); ?>
				<input type="hidden" name="lf_team_id" value="<?php echo esc_attr( (string) $team_id ); ?>" />
				<input type="hidden" name="lf_player_id" value="<?php echo esc_attr( (string) $player_id ); ?>" />
				<button type="submit"><?php esc_html_e( 'Remove from team', 'leagueflow' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Complete first-time player setup and create one request per new sport.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_complete_player_onboarding( $user ) {
		$existing_player_id = $this->get_player_id_for_user( $user, false );
		$player_window_open = is_player_registration_enabled();

		if ( ! $player_window_open && ! $existing_player_id ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		if ( ! $existing_player_id && ! $this->user_can_begin_registration( $user ) ) {
			$this->redirect_with_notice( 'registration-email-denied' );
		}

		$player_id = $existing_player_id;
		$player    = $player_id ? get_post( $player_id ) : null;

		if ( $player_id && ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) ) {
			$this->redirect_with_notice( 'player-missing' );
		}

		$name        = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );
		$description = wp_kses_post( wp_unslash( $_POST['lf_player_description'] ?? '' ) );
		$preferences = $this->sanitize_player_sport_level_submission(
			$_POST['lf_player_sports'] ?? array(),
			$_POST['lf_player_sport_levels'] ?? array()
		);
		$submitted_levels = isset( $_POST['lf_player_sport_levels'] ) && is_array( $_POST['lf_player_sport_levels'] ) ? $_POST['lf_player_sport_levels'] : array();
		$choices     = isset( $_POST['lf_player_team_choices'] ) && is_array( $_POST['lf_player_team_choices'] ) ? wp_unslash( $_POST['lf_player_team_choices'] ) : array();
		$notes       = isset( $_POST['lf_player_request_notes'] ) && is_array( $_POST['lf_player_request_notes'] ) ? wp_unslash( $_POST['lf_player_request_notes'] ) : array();

		if ( '' === $name || $this->is_placeholder_player_name( $name, $user ) ) {
			$this->redirect_with_notice( 'name-setup-required' );
		}

		if ( empty( $preferences ) ) {
			$this->redirect_with_notice( 'sport-level-required' );
		}

		foreach ( $preferences as $sport_slug => $level_id ) {
			$submitted_level_id = isset( $submitted_levels[ $sport_slug ] ) && ! is_array( $submitted_levels[ $sport_slug ] ) ? absint( wp_unslash( $submitted_levels[ $sport_slug ] ) ) : 0;

			if ( $submitted_level_id !== absint( $level_id ) ) {
				$this->redirect_with_notice( 'invalid-request' );
			}
		}

		$states   = $player_id ? $this->get_player_registration_states( $player_id ) : array();
		$requests = array();

		foreach ( $preferences as $sport_slug => $level_id ) {
			$state = $states[ $sport_slug ] ?? array();

			if ( ! empty( $state['team_id'] ) || ! empty( $state['request_id'] ) ) {
				if ( ! empty( $state['level_id'] ) ) {
					$preferences[ $sport_slug ] = absint( $state['level_id'] );
				}
				continue;
			}

			$choice = isset( $choices[ $sport_slug ] ) && ! is_array( $choices[ $sport_slug ] ) ? sanitize_text_field( (string) $choices[ $sport_slug ] ) : '';
			$note   = isset( $notes[ $sport_slug ] ) && ! is_array( $notes[ $sport_slug ] ) ? sanitize_textarea_field( (string) $notes[ $sport_slug ] ) : '';

			if ( '' === $choice || ( $player_id && $this->find_pending_join_request_for_sport( $player_id, $sport_slug ) ) || $this->player_has_team_in_sport( $player_id, $sport_slug ) ) {
				$this->redirect_with_notice( 'sport-request-required' );
			}

			$is_placement = '0' === $choice;
			$team_id      = absint( $choice );

			if ( ! $is_placement ) {
				$team = get_post( $team_id );

				if (
					! $team instanceof \WP_Post ||
					'lf_team' !== $team->post_type ||
					'publish' !== $team->post_status ||
					get_post_primary_term_slug( $team_id, 'lf_sport' ) !== $sport_slug ||
					get_post_primary_term_id( $team_id, 'lf_league_level' ) !== absint( $level_id )
				) {
					$this->redirect_with_notice( 'team-level-mismatch' );
				}
			}

			$requests[] = array(
				'sport_slug'  => $sport_slug,
				'level_id'    => absint( $level_id ),
				'team_id'     => $is_placement ? 0 : $team_id,
				'is_placement' => $is_placement,
				'note'        => $note,
			);
		}

		if ( ! $player_window_open && ! empty( $requests ) ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$snapshot      = $player_id ? $this->snapshot_player_profile( $player_id ) : array();
		$is_new_player = ! $player_id;

		if ( $is_new_player ) {
			$player_id = wp_insert_post(
				array(
					'post_type'    => 'lf_player',
					'post_status'  => 'publish',
					'post_title'   => $name,
					'post_content' => $description,
					'post_author'  => (int) $user->ID,
				),
				true
			);

			if ( is_wp_error( $player_id ) || ! $player_id ) {
				$this->redirect_with_notice( 'onboarding-save-error' );
			}

			update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );
			update_post_meta( $player_id, 'lf_email', strtolower( sanitize_email( $user->user_email ) ) );
		} else {
			$updated = wp_update_post(
				array(
					'ID'           => $player_id,
					'post_title'   => $name,
					'post_content' => $description,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				$this->redirect_with_notice( 'onboarding-save-error' );
			}
		}

		$this->save_player_sport_level_preferences( $player_id, $preferences );
		$attachment_id = $this->save_uploaded_image( 'lf_player_photo', $player_id );

		if ( is_wp_error( $attachment_id ) ) {
			if ( $is_new_player ) {
				wp_delete_post( $player_id, true );
			} else {
				$this->restore_player_profile( $player_id, $snapshot );
			}
			$this->redirect_with_notice( 'upload-error' );
		}

		$created_requests = array();

		foreach ( $requests as $request_data ) {
			$request_id = $this->create_join_request( $user, $player_id, $name, $request_data );

			if ( is_wp_error( $request_id ) ) {
				foreach ( $created_requests as $created_request_id ) {
					wp_delete_post( $created_request_id, true );
				}

				if ( $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}

				if ( $is_new_player ) {
					wp_delete_post( $player_id, true );
				} else {
					$this->restore_player_profile( $player_id, $snapshot );
				}
				$this->redirect_with_notice( 'onboarding-save-error' );
			}

			$created_requests[] = absint( $request_id );
		}

		$this->sync_user_display_name( $user, $name );
		add_user_role_if_missing( $user->ID, 'leagueflow_player' );

		foreach ( $created_requests as $request_id ) {
			$this->notify_new_join_request( $request_id );
		}

		$this->redirect_with_notice( 'player-onboarding-complete' );
	}

	/**
	 * Handle player profile updates.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_update_player_profile( $user ) {
		$player_id = $this->get_player_id_for_user( $user, is_player_registration_enabled() );

		if ( ! $player_id ) {
			$this->redirect_with_notice( 'player-missing' );
		}

		$player             = get_post( $player_id );
		$needs_name_setup   = $this->player_name_needs_setup( $player, $user );
		$current_name       = $player instanceof \WP_Post ? (string) $player->post_title : '';
		$has_name_submission = array_key_exists( 'lf_player_name', $_POST );
		$submitted_name     = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );
		$description        = wp_kses_post( wp_unslash( $_POST['lf_player_description'] ?? '' ) );
		$sport_preferences  = $this->sanitize_player_sport_level_submission(
			$_POST['lf_player_sports'] ?? array(),
			$_POST['lf_player_sport_levels'] ?? array()
		);
		$profile_setup_done = false;

		if ( $needs_name_setup || $has_name_submission ) {
			if ( '' === $submitted_name || $this->is_placeholder_player_name( $submitted_name, $user ) ) {
				$this->redirect_with_notice( 'name-setup-required' );
			}

			$current_name       = $submitted_name;
			$profile_setup_done = true;
		}

		if ( $this->player_sport_level_fields_are_available() && empty( $sport_preferences ) ) {
			$this->redirect_with_notice( 'sport-level-required' );
		}

		$post_data = array(
			'ID'           => $player_id,
			'post_content' => $description,
		);

		if ( $profile_setup_done ) {
			$post_data['post_title'] = $current_name;
		}

		wp_update_post( $post_data );
		$this->save_player_sport_level_preferences( $player_id, $sport_preferences );

		if ( $profile_setup_done ) {
			$this->sync_user_display_name( $user, $current_name );
		}

		$this->maybe_save_uploaded_image( 'lf_player_photo', $player_id );
		$this->redirect_with_notice( $profile_setup_done ? 'profile-setup-complete' : 'profile-saved' );
	}

	/**
	 * Handle a player request to join an existing team.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_request_join_team( $user ) {
		if ( ! is_player_registration_enabled() ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$player_id = $this->get_player_id_for_user( $user, true );

		if ( ! $player_id ) {
			$this->redirect_with_notice( 'player-missing' );
		}

		$sport_slug    = sanitize_key( wp_unslash( $_POST['lf_sport_slug'] ?? '' ) );
		$team_id_value = isset( $_POST['lf_team_id'] ) && ! is_array( $_POST['lf_team_id'] ) ? sanitize_text_field( wp_unslash( $_POST['lf_team_id'] ) ) : '';
		$team_id       = absint( $team_id_value );
		$is_placement  = '0' === $team_id_value;
		$note          = sanitize_textarea_field( wp_unslash( $_POST['lf_request_note'] ?? '' ) );
		$player        = get_post( $player_id );
		$name          = $player instanceof \WP_Post ? $player->post_title : '';
		$level_id      = absint( wp_unslash( $_POST['lf_league_level_id'] ?? 0 ) );
		$level         = $level_id ? get_term( $level_id, 'lf_league_level' ) : null;

		if ( '' === trim( (string) $name ) || '' === $sport_slug || ! $level || is_wp_error( $level ) || ( '' === $team_id_value || ( ! $team_id && ! $is_placement ) ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$sports_manager = new Sports_Manager();

		if ( ! in_array( $sport_slug, $sports_manager->get_enabled_sport_slugs(), true ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		if ( $is_placement ) {
			if ( $this->player_has_team_in_sport( $player_id, $sport_slug ) ) {
				$this->redirect_with_notice( 'placement-request-member' );
			}
		} else {
			$team = get_post( $team_id );

			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || 'publish' !== $team->post_status ) {
				$this->redirect_with_notice( 'invalid-request' );
			}

			$team_sport_slug = get_post_primary_term_slug( $team_id, 'lf_sport' );

			if ( $team_sport_slug !== $sport_slug ) {
				$this->redirect_with_notice( 'invalid-request' );
			}

			if ( get_post_primary_term_id( $team_id, 'lf_league_level' ) !== $level_id ) {
				$this->redirect_with_notice( 'team-level-mismatch' );
			}

			if ( player_has_team( $player_id, $team_id ) ) {
				$this->redirect_with_notice( 'join-request-member' );
			}

		}

		if ( $this->find_pending_join_request_for_sport( $player_id, $sport_slug ) ) {
			$this->redirect_with_notice( $is_placement ? 'placement-request-exists' : 'join-request-exists' );
		}

		$request_id = $this->create_join_request(
			$user,
			$player_id,
			$name,
			array(
				'sport_slug'   => $sport_slug,
				'level_id'     => $level_id,
				'team_id'      => $is_placement ? 0 : $team_id,
				'is_placement' => $is_placement,
				'note'         => $note,
			)
		);

		if ( is_wp_error( $request_id ) || ! $request_id ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$this->notify_new_join_request( $request_id );

		$this->redirect_with_notice( $is_placement ? 'placement-request-sent' : 'join-request-sent' );
	}

	/**
	 * Create a validated team or placement request.
	 *
	 * @param \WP_User             $user User.
	 * @param int                  $player_id Player ID.
	 * @param string               $name Player name.
	 * @param array<string, mixed> $data Request data.
	 * @return int|\WP_Error
	 */
	protected function create_join_request( $user, $player_id, $name, $data ) {
		$sport_slug  = sanitize_key( $data['sport_slug'] ?? '' );
		$level_id    = absint( $data['level_id'] ?? 0 );
		$team_id     = absint( $data['team_id'] ?? 0 );
		$is_placement = ! empty( $data['is_placement'] );
		$note         = sanitize_textarea_field( $data['note'] ?? '' );

		if ( $is_placement ) {
			$title = sprintf(
				/* translators: 1: player name 2: sport label. */
				__( '%1$s needs a %2$s team', 'leagueflow' ),
				$name,
				$this->get_sport_label( $sport_slug )
			);
		} else {
			$title = sprintf(
				/* translators: 1: player name 2: team name. */
				__( '%1$s requested %2$s', 'leagueflow' ),
				$name,
				get_the_title( $team_id )
			);
		}

		$request_id = wp_insert_post(
			array(
				'post_type'   => 'lf_join_request',
				'post_status' => 'private',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $request_id ) || ! $request_id ) {
			return is_wp_error( $request_id ) ? $request_id : new \WP_Error( 'leagueflow_request_create_failed', __( 'The team request could not be created.', 'leagueflow' ) );
		}

		update_post_meta( $request_id, 'lf_player_id', absint( $player_id ) );
		update_post_meta( $request_id, 'lf_user_id', (int) $user->ID );
		update_post_meta( $request_id, 'lf_team_id', $is_placement ? 0 : $team_id );
		update_post_meta( $request_id, 'lf_league_level_id', $level_id );
		update_post_meta( $request_id, 'lf_sport_slug', $sport_slug );
		update_post_meta( $request_id, 'lf_request_status', 'pending' );
		update_post_meta( $request_id, 'lf_request_note', $note );
		update_post_meta( $request_id, 'lf_request_type', $is_placement ? 'placement' : 'team' );

		return absint( $request_id );
	}

	/**
	 * Notify the responsible people about a new request.
	 *
	 * @param int $request_id Join request ID.
	 * @return void
	 */
	protected function notify_new_join_request( $request_id ) {
		$player_id   = absint( get_post_meta( $request_id, 'lf_player_id', true ) );
		$team_id     = absint( get_post_meta( $request_id, 'lf_team_id', true ) );
		$sport_slug  = sanitize_key( (string) get_post_meta( $request_id, 'lf_sport_slug', true ) );
		$level_id    = get_join_request_level_id( $request_id );
		$level       = $level_id ? get_term( $level_id, 'lf_league_level' ) : null;
		$note        = (string) get_post_meta( $request_id, 'lf_request_note', true );
		$player_name = get_the_title( $player_id );
		$player_email = (string) get_post_meta( $player_id, 'lf_email', true );
		$is_placement = ! $team_id;
		$recipients   = $is_placement ? get_registration_email() : get_team_manager_emails( $team_id );
		$subject      = $is_placement
			? sprintf( __( '[UNBC Intramurals] New %s placement request', 'leagueflow' ), $this->get_sport_label( $sport_slug ) )
			: sprintf( __( '[UNBC Intramurals] New request for %s', 'leagueflow' ), get_the_title( $team_id ) );
		$destination = $is_placement ? admin_url( 'admin.php?page=leagueflow-placements' ) : $this->get_portal_url();
		$message      = implode(
			"\n",
			array_filter(
				array(
					sprintf( __( 'Player: %s', 'leagueflow' ), $player_name ),
					is_email( $player_email ) ? sprintf( __( 'Email: %s', 'leagueflow' ), $player_email ) : '',
					sprintf( __( 'Sport: %s', 'leagueflow' ), $this->get_sport_label( $sport_slug ) ),
					$level && ! is_wp_error( $level ) ? sprintf( __( 'Level: %s', 'leagueflow' ), $level->name ) : __( 'Level: Not specified', 'leagueflow' ),
					$note ? sprintf( __( 'Note: %s', 'leagueflow' ), $note ) : '',
					'',
					__( 'Review the request:', 'leagueflow' ) . ' ' . $destination,
				)
			)
		);

		send_registration_email( $recipients, $subject, $message );
	}

	/**
	 * Notify a player when a captain approves or declines a request.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $decision approved or declined.
	 * @param int    $team_id Team ID.
	 * @return void
	 */
	protected function notify_player_request_decision( $request_id, $decision, $team_id ) {
		$player_id = absint( get_post_meta( $request_id, 'lf_player_id', true ) );
		$email     = sanitize_email( (string) get_post_meta( $player_id, 'lf_email', true ) );

		if ( ! is_email( $email ) ) {
			return;
		}

		$approved = 'approved' === $decision;
		$subject  = $approved
			? sprintf( __( '[UNBC Intramurals] You joined %s', 'leagueflow' ), get_the_title( $team_id ) )
			: sprintf( __( '[UNBC Intramurals] Update from %s', 'leagueflow' ), get_the_title( $team_id ) );
		$message  = $approved
			? sprintf( __( 'Your request was approved and you are now on %1$s. View your portal: %2$s', 'leagueflow' ), get_the_title( $team_id ), $this->get_portal_url() )
			: sprintf( __( 'Your request to join %1$s was declined. You can choose another team from your portal: %2$s', 'leagueflow' ), get_the_title( $team_id ), $this->get_portal_url() );

		send_registration_email( $email, $subject, $message );
	}

	/**
	 * Handle captain team registration.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_complete_captain_onboarding( $user ) {
		if ( ! is_captain_registration_enabled() ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$existing_player_id = $this->get_player_id_for_user( $user, false );
		$manager_teams      = $this->get_manager_teams( $user->ID );
		$is_repeat          = ! empty( $manager_teams );
		$existing_player    = $existing_player_id ? get_post( $existing_player_id ) : null;
		$preserve_profile   = $is_repeat && $existing_player instanceof \WP_Post;
		$had_player_role    = in_array( 'leagueflow_player', (array) $user->roles, true );
		$user_snapshot      = array(
			'ID'           => (int) $user->ID,
			'display_name' => (string) $user->display_name,
			'nickname'     => (string) $user->nickname,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
		);

		if ( ! $existing_player_id && empty( $manager_teams ) && ! $this->user_can_begin_registration( $user ) ) {
			$this->redirect_with_notice( 'registration-email-denied' );
		}

		$sports_manager = new Sports_Manager();
		$sport_slug     = sanitize_key( wp_unslash( $_POST['lf_sport_slug'] ?? '' ) );
		$level_id       = absint( wp_unslash( $_POST['lf_league_level_id'] ?? 0 ) );
		$team_name      = sanitize_text_field( wp_unslash( $_POST['lf_team_name'] ?? '' ) );
		$short_name     = sanitize_text_field( wp_unslash( $_POST['lf_short_name'] ?? '' ) );
		$captain_name   = $preserve_profile ? $existing_player->post_title : sanitize_text_field( wp_unslash( $_POST['lf_captain_name'] ?? '' ) );
		$team_description = wp_kses_post( wp_unslash( $_POST['lf_team_description'] ?? '' ) );
		$player_description = $preserve_profile ? $existing_player->post_content : wp_kses_post( wp_unslash( $_POST['lf_player_description'] ?? '' ) );
		$enabled_sports = $sports_manager->get_enabled_sport_slugs();

		if ( '' === $sport_slug || ! in_array( $sport_slug, $enabled_sports, true ) || '' === $team_name || '' === $captain_name || $this->is_placeholder_player_name( $captain_name, $user ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$level = $level_id ? get_term( $level_id, 'lf_league_level' ) : null;

		if ( ! $level || is_wp_error( $level ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		foreach ( $manager_teams as $managed_team ) {
			if ( get_post_primary_term_slug( $managed_team->ID, 'lf_sport' ) === $sport_slug ) {
				$this->redirect_with_notice( 'captain-sport-exists' );
			}
		}

		if ( $existing_player_id && ( $this->player_has_team_in_sport( $existing_player_id, $sport_slug ) || $this->find_pending_join_request_for_sport( $existing_player_id, $sport_slug ) ) ) {
			$this->redirect_with_notice( 'player-sport-exists' );
		}

		$duplicate_team_id = $this->find_team_id_by_title_and_sport( $team_name, $sport_slug );

		if ( $duplicate_team_id ) {
			$this->redirect_with_notice( 'team-exists' );
		}

		$team_id = wp_insert_post(
			array(
				'post_type'    => 'lf_team',
				'post_status'  => 'publish',
				'post_title'   => $team_name,
				'post_content' => $team_description,
				'post_author'  => (int) $user->ID,
			),
			true
		);

		if ( is_wp_error( $team_id ) || ! $team_id ) {
			$this->redirect_with_notice( 'onboarding-save-error' );
		}

		update_post_meta( $team_id, 'lf_short_name', $short_name );
		update_post_meta( $team_id, 'lf_manager_user_ids', array( (int) $user->ID ) );

		$sport_term = get_term_by( 'slug', $sport_slug, 'lf_sport' );

		if ( $sport_term && ! is_wp_error( $sport_term ) ) {
			wp_set_object_terms( $team_id, array( (int) $sport_term->term_id ), 'lf_sport', false );
		}

		if ( $level_id ) {
			wp_set_object_terms( $team_id, array( $level_id ), 'lf_league_level', false );
		}

		$player_snapshot = $existing_player_id ? $this->snapshot_player_profile( $existing_player_id ) : array();
		$captain_id = $this->get_or_create_captain_player_id( $user, $captain_name );

		if ( ! $captain_id ) {
			wp_delete_post( $team_id, true );
			$this->redirect_with_notice( 'onboarding-save-error' );
		}

		if ( ! $preserve_profile ) {
			wp_update_post(
				array(
					'ID'           => $captain_id,
					'post_content' => $player_description,
				)
			);
		}

		if ( ! assign_player_to_team( $captain_id, (int) $team_id, array( 'is_captain' => 1 ) ) ) {
			wp_delete_post( $team_id, true );

			if ( $existing_player_id ) {
				$this->restore_player_profile( $existing_player_id, $player_snapshot );
			} else {
				wp_delete_post( $captain_id, true );

				if ( ! $had_player_role ) {
					$rollback_user = get_user_by( 'id', $user->ID );
					if ( $rollback_user instanceof \WP_User ) {
						$rollback_user->remove_role( 'leagueflow_player' );
					}
				}
			}

			wp_update_user( $user_snapshot );

			$this->redirect_with_notice( 'onboarding-save-error' );
		}

		$player_attachment_id = $preserve_profile ? 0 : $this->save_uploaded_image( 'lf_player_photo', $captain_id );
		$team_attachment_id   = $this->save_uploaded_image( 'lf_team_logo', $team_id );

		if ( is_wp_error( $player_attachment_id ) || is_wp_error( $team_attachment_id ) ) {
			if ( is_int( $player_attachment_id ) && $player_attachment_id ) {
				wp_delete_attachment( $player_attachment_id, true );
			}

			if ( is_int( $team_attachment_id ) && $team_attachment_id ) {
				wp_delete_attachment( $team_attachment_id, true );
			}

			wp_delete_post( $team_id, true );

			if ( $existing_player_id ) {
				$this->restore_player_profile( $existing_player_id, $player_snapshot );
			} else {
				wp_delete_post( $captain_id, true );

				if ( ! $had_player_role ) {
					$rollback_user = get_user_by( 'id', $user->ID );
					if ( $rollback_user instanceof \WP_User ) {
						$rollback_user->remove_role( 'leagueflow_player' );
					}
				}
			}

			wp_update_user( $user_snapshot );

			$this->redirect_with_notice( 'upload-error' );
		}

		add_user_role_if_missing( $user->ID, 'leagueflow_team_manager' );

		$this->redirect_with_notice(
			'team-registered',
			array(
				'leagueflow_view' => 'teams',
				'leagueflow_team' => (int) $team_id,
			),
			'team-workspace'
		);
	}

	/**
	 * Approve or decline a team join request.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_review_join_request( $user ) {
		$request_id = absint( wp_unslash( $_POST['lf_join_request_id'] ?? 0 ) );
		$decision   = sanitize_key( wp_unslash( $_POST['lf_join_decision'] ?? '' ) );
		$request    = get_post( $request_id );

		if ( ! $request instanceof \WP_Post || 'lf_join_request' !== $request->post_type ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$request_team_id      = (int) get_post_meta( $request_id, 'lf_team_id', true );
		$submitted_team_id    = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );
		$is_placement_request = ! $request_team_id;
		$team_id              = $is_placement_request ? $submitted_team_id : $request_team_id;
		$player_id            = (int) get_post_meta( $request_id, 'lf_player_id', true );
		$status               = sanitize_key( (string) get_post_meta( $request_id, 'lf_request_status', true ) );

		if ( $is_placement_request || 'pending' !== $status || ! $team_id || ! $player_id || ! $this->user_can_manage_team( $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		if ( ! $is_placement_request && $submitted_team_id && $submitted_team_id !== $request_team_id ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$team_sport_slug    = get_post_primary_term_slug( $team_id, 'lf_sport' );
		$request_sport_slug = sanitize_key( (string) get_post_meta( $request_id, 'lf_sport_slug', true ) );
		$request_sport_slug = $request_sport_slug ? $request_sport_slug : $team_sport_slug;
		$request_level_id   = get_join_request_level_id( $request_id );

		if (
			'' === $team_sport_slug ||
			$request_sport_slug !== $team_sport_slug ||
			( $request_level_id && $request_level_id !== get_post_primary_term_id( $team_id, 'lf_league_level' ) )
		) {
			$this->redirect_with_notice( 'team-denied' );
		}

		if ( 'approve' === $decision ) {
			if ( $this->player_has_other_team_in_sport( $player_id, $request_sport_slug, $team_id ) ) {
				$this->redirect_with_notice( 'player-assigned' );
			}

			if ( ! assign_player_to_team( $player_id, $team_id ) ) {
				$this->redirect_with_notice( 'invalid-request' );
			}

			update_post_meta( $request_id, 'lf_request_status', 'approved' );
			update_post_meta( $request_id, 'lf_team_id', $team_id );
			$this->notify_player_request_decision( $request_id, 'approved', $team_id );
			$this->redirect_with_notice( 'join-request-approved' );
		}

		if ( 'decline' === $decision ) {
			update_post_meta( $request_id, 'lf_request_status', 'declined' );
			$this->notify_player_request_decision( $request_id, 'declined', $team_id );
			$this->redirect_with_notice( 'join-request-declined' );
		}

		$this->redirect_with_notice( 'invalid-request' );
	}

	/**
	 * Handle team profile updates.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_update_team_profile( $user ) {
		$team_id = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_team( $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$team_name = sanitize_text_field( wp_unslash( $_POST['lf_team_name'] ?? '' ) );

		if ( '' === $team_name ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		wp_update_post(
			array(
				'ID'           => $team_id,
				'post_title'   => $team_name,
				'post_content' => wp_kses_post( wp_unslash( $_POST['lf_team_description'] ?? '' ) ),
			)
		);

		update_post_meta( $team_id, 'lf_short_name', sanitize_text_field( wp_unslash( $_POST['lf_short_name'] ?? '' ) ) );
		$this->maybe_save_uploaded_image( 'lf_team_logo', $team_id );
		$this->redirect_with_notice( 'team-saved' );
	}

	/**
	 * Handle adding a roster player.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_add_player( $user ) {
		if ( ! is_player_registration_enabled() ) {
			$this->redirect_with_notice( 'registration-closed' );
		}

		$team_id = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_team( $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$email = strtolower( sanitize_email( wp_unslash( $_POST['lf_email'] ?? '' ) ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$this->redirect_with_notice( 'invalid-email' );
		}

		$linked_user = get_user_by( 'email', $email );
		$name        = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );

		if ( '' === $name && $linked_user instanceof \WP_User ) {
			$name = $linked_user->display_name;
		}

		if ( '' === $name ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$player_id = $email ? $this->find_player_id_by_email( $email ) : 0;

		if ( ! $player_id && $linked_user instanceof \WP_User ) {
			$player_id = $this->find_player_id_by_user( $linked_user->ID );
		}

		if ( ! $player_id ) {
			$player_id = wp_insert_post(
				array(
					'post_type'   => 'lf_player',
					'post_status' => 'private',
					'post_title'  => $name,
					'post_author' => (int) $user->ID,
				)
			);
		}

		if ( ! $player_id || is_wp_error( $player_id ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		$team_sport_slug = get_post_primary_term_slug( $team_id, 'lf_sport' );

		if ( $this->player_has_other_team_in_sport( $player_id, $team_sport_slug, $team_id ) ) {
			$this->redirect_with_notice( 'player-assigned' );
		}

		$this->save_roster_player_fields( absint( $player_id ), $team_id, $email, $name );
		$this->redirect_with_notice( 'player-added' );
	}

	/**
	 * Handle roster player updates.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_update_roster_player( $user ) {
		$team_id   = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );
		$player_id = absint( wp_unslash( $_POST['lf_player_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_roster_player( $player_id, $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		$email = strtolower( sanitize_email( wp_unslash( $_POST['lf_email'] ?? '' ) ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$this->redirect_with_notice( 'invalid-email' );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) );
		$this->save_roster_player_fields( $player_id, $team_id, $email, $name );
		$this->redirect_with_notice( 'roster-saved' );
	}

	/**
	 * Handle roster removal.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_remove_roster_player( $user ) {
		$team_id   = absint( wp_unslash( $_POST['lf_team_id'] ?? 0 ) );
		$player_id = absint( wp_unslash( $_POST['lf_player_id'] ?? 0 ) );

		if ( ! $this->user_can_manage_roster_player( $player_id, $team_id, $user->ID ) ) {
			$this->redirect_with_notice( 'team-denied' );
		}

		remove_player_team_id( $player_id, $team_id );
		$this->redirect_with_notice( 'player-removed' );
	}

	/**
	 * Save manager-editable roster fields.
	 *
	 * @param int    $player_id Player ID.
	 * @param int    $team_id Team ID.
	 * @param string $email Player email.
	 * @param string $name Player name.
	 * @return void
	 */
	protected function save_roster_player_fields( $player_id, $team_id, $email, $name ) {
		if ( '' !== $name ) {
			wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => $name,
				)
			);
		}

		assign_player_to_team(
			$player_id,
			$team_id,
			array(
				'jersey_number' => sanitize_text_field( wp_unslash( $_POST['lf_jersey_number'] ?? '' ) ),
				'position'      => sanitize_text_field( wp_unslash( $_POST['lf_position'] ?? '' ) ),
				'is_captain'    => ! empty( $_POST['lf_is_captain'] ) ? 1 : 0,
			)
		);

		if ( '' !== $email ) {
			update_post_meta( $player_id, 'lf_email', $email );

			$linked_user = get_user_by( 'email', $email );

			if ( $linked_user instanceof \WP_User ) {
				update_post_meta( $player_id, 'lf_user_id', (int) $linked_user->ID );
				add_user_role_if_missing( (int) $linked_user->ID, 'leagueflow_player' );
			} else {
				delete_post_meta( $player_id, 'lf_user_id' );
			}
		}

		$this->assign_player_sport_from_team( $player_id, $team_id );
	}

	/**
	 * Save an uploaded image as a post thumbnail.
	 *
	 * @param string $field File input field.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	protected function maybe_save_uploaded_image( $field, $post_id ) {
		$result = $this->save_uploaded_image( $field, $post_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'upload-error' );
		}
	}

	/**
	 * Save an optional uploaded image and return the attachment or an error.
	 *
	 * @param string $field File input field.
	 * @param int    $post_id Post ID.
	 * @return int|\WP_Error
	 */
	protected function save_uploaded_image( $field, $post_id ) {
		if ( empty( $_FILES[ $field ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) {
			return 0;
		}

		if ( UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
			return new \WP_Error( 'leagueflow_upload_error', __( 'The uploaded image could not be read.', 'leagueflow' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload(
			$field,
			$post_id,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'gif'          => 'image/gif',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, absint( $attachment_id ) );

		return absint( $attachment_id );
	}

	/**
	 * Snapshot profile values that onboarding may change.
	 *
	 * @param int $player_id Player ID.
	 * @return array<string, mixed>
	 */
	protected function snapshot_player_profile( $player_id ) {
		$player = get_post( absint( $player_id ) );

		return array(
			'title'        => $player instanceof \WP_Post ? $player->post_title : '',
			'content'      => $player instanceof \WP_Post ? $player->post_content : '',
			'sport_terms'  => wp_get_object_terms( absint( $player_id ), 'lf_sport', array( 'fields' => 'ids' ) ),
			'level_terms'  => wp_get_object_terms( absint( $player_id ), 'lf_league_level', array( 'fields' => 'ids' ) ),
			'preferences'  => get_post_meta( absint( $player_id ), 'lf_player_sport_levels', true ),
			'team_ids'      => get_player_team_ids( absint( $player_id ) ),
			'team_details'  => get_player_team_details( absint( $player_id ) ),
			'is_captain'    => (int) get_post_meta( absint( $player_id ), 'lf_is_captain', true ),
			'thumbnail_id' => get_post_thumbnail_id( absint( $player_id ) ),
		);
	}

	/**
	 * Restore profile values after a failed combined onboarding submission.
	 *
	 * @param int                  $player_id Player ID.
	 * @param array<string, mixed> $snapshot Snapshot from snapshot_player_profile().
	 * @return void
	 */
	protected function restore_player_profile( $player_id, $snapshot ) {
		wp_update_post(
			array(
				'ID'           => absint( $player_id ),
				'post_title'   => (string) ( $snapshot['title'] ?? '' ),
				'post_content' => (string) ( $snapshot['content'] ?? '' ),
			)
		);

		$sport_terms = isset( $snapshot['sport_terms'] ) && is_array( $snapshot['sport_terms'] ) ? array_map( 'absint', $snapshot['sport_terms'] ) : array();
		$level_terms = isset( $snapshot['level_terms'] ) && is_array( $snapshot['level_terms'] ) ? array_map( 'absint', $snapshot['level_terms'] ) : array();
		wp_set_object_terms( absint( $player_id ), $sport_terms, 'lf_sport', false );
		wp_set_object_terms( absint( $player_id ), $level_terms, 'lf_league_level', false );

		if ( isset( $snapshot['preferences'] ) && is_array( $snapshot['preferences'] ) ) {
			update_post_meta( absint( $player_id ), 'lf_player_sport_levels', $snapshot['preferences'] );
		} else {
			delete_post_meta( absint( $player_id ), 'lf_player_sport_levels' );
		}

		set_player_team_ids( absint( $player_id ), isset( $snapshot['team_ids'] ) && is_array( $snapshot['team_ids'] ) ? $snapshot['team_ids'] : array() );
		update_post_meta( absint( $player_id ), 'lf_player_team_details', sanitize_player_team_details( $snapshot['team_details'] ?? array() ) );
		sync_player_legacy_team_meta( absint( $player_id ) );

		if ( ! empty( $snapshot['thumbnail_id'] ) ) {
			set_post_thumbnail( absint( $player_id ), absint( $snapshot['thumbnail_id'] ) );
		} else {
			delete_post_thumbnail( absint( $player_id ) );
		}
	}

	/**
	 * Check whether a player still appears to be using their login code as a name.
	 *
	 * @param int      $player_id Player ID.
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function player_needs_initial_setup( $player_id, $user ) {
		$player = $player_id ? get_post( $player_id ) : null;

		return $this->player_name_needs_setup( $player, $user ) || $this->player_sport_level_needs_setup( $player_id );
	}

	/**
	 * Check whether a player still appears to be using their login code as a name.
	 *
	 * @param \WP_Post $player Player post.
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function player_name_needs_setup( $player, $user ) {
		if ( ! $player instanceof \WP_Post || ! $user instanceof \WP_User ) {
			return false;
		}

		return $this->is_placeholder_player_name( (string) $player->post_title, $user );
	}

	/**
	 * Check whether a submitted profile name is still a login identifier.
	 *
	 * @param string   $name Submitted/player name.
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function is_placeholder_player_name( $name, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$name       = strtolower( trim( $name ) );
		$login      = strtolower( trim( (string) $user->user_login ) );
		$email      = strtolower( trim( (string) $user->user_email ) );
		$email_name = is_email( $email ) ? strtolower( strtok( $email, '@' ) ) : '';

		return '' === $name || $name === $login || ( '' !== $email_name && $name === $email_name );
	}

	/**
	 * Check whether player sport and level preferences still need setup.
	 *
	 * @param int $player_id Player ID.
	 * @return bool
	 */
	protected function player_sport_level_needs_setup( $player_id ) {
		if ( ! $player_id || ! $this->player_sport_level_fields_are_available() ) {
			return false;
		}

		return empty( $this->get_player_sport_level_preferences( $player_id ) );
	}

	/**
	 * Whether sport/level setup can be rendered and validated.
	 *
	 * @return bool
	 */
	protected function player_sport_level_fields_are_available() {
		return ! empty( $this->get_player_preference_sports() ) && ! empty( $this->get_league_level_terms() );
	}

	/**
	 * Render sport and level preference controls for a player profile form.
	 *
	 * @param int $player_id Player ID.
	 * @return string
	 */
	protected function render_player_sport_level_fields( $player_id ) {
		$sports = $this->get_player_preference_sports();
		$levels = $this->get_league_level_terms();

		if ( empty( $sports ) || empty( $levels ) ) {
			return '';
		}

		$preferences     = $this->get_player_sport_level_preferences( $player_id );
		$default_level_id = $this->get_default_league_level_id();
		$selected_slugs  = array_keys( $preferences );

		if ( empty( $selected_slugs ) && 1 === count( $sports ) ) {
			$sport_slugs    = array_keys( $sports );
			$selected_slugs = array( reset( $sport_slugs ) );
		}

		ob_start();
		?>
		<fieldset class="leagueflow-portal__sport-preferences" data-leagueflow-player-sport-preferences>
			<legend><?php esc_html_e( 'Sport sign-up preferences', 'leagueflow' ); ?></legend>
			<p class="leagueflow-portal__form-help"><?php esc_html_e( 'Select every sport you want to play, then choose the level you prefer for each selected sport.', 'leagueflow' ); ?></p>
			<div class="leagueflow-portal__sport-preference-list">
				<?php foreach ( $sports as $sport_slug => $sport ) : ?>
					<?php
					$is_selected = in_array( $sport_slug, $selected_slugs, true );
					$level_id    = isset( $preferences[ $sport_slug ]['level_id'] ) ? (int) $preferences[ $sport_slug ]['level_id'] : $default_level_id;
					$input_id    = 'leagueflow-player-sport-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
					$select_id   = 'leagueflow-player-level-' . sanitize_html_class( $sport_slug ) . '-' . absint( $player_id );
					?>
					<div class="leagueflow-portal__sport-preference-row">
						<label class="leagueflow-portal__sport-choice" for="<?php echo esc_attr( $input_id ); ?>">
							<input id="<?php echo esc_attr( $input_id ); ?>" type="checkbox" name="lf_player_sports[]" value="<?php echo esc_attr( $sport_slug ); ?>" <?php checked( $is_selected ); ?> data-leagueflow-player-sport-toggle />
							<span><?php echo esc_html( $sport['label'] ); ?></span>
						</label>
						<label class="leagueflow-portal__sport-level" for="<?php echo esc_attr( $select_id ); ?>">
							<span><?php esc_html_e( 'Level', 'leagueflow' ); ?></span>
							<select id="<?php echo esc_attr( $select_id ); ?>" name="lf_player_sport_levels[<?php echo esc_attr( $sport_slug ); ?>]" data-leagueflow-player-level-select>
								<?php foreach ( $levels as $level ) : ?>
									<option value="<?php echo esc_attr( (string) $level->term_id ); ?>" <?php selected( $level_id, (int) $level->term_id ); ?>><?php echo esc_html( $level->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sanitize posted sport and per-sport level preferences.
	 *
	 * @param mixed $sports Posted selected sport slugs.
	 * @param mixed $levels Posted level IDs keyed by sport slug.
	 * @return array<string, int>
	 */
	protected function sanitize_player_sport_level_submission( $sports, $levels ) {
		$sports         = is_array( $sports ) ? $sports : array();
		$levels         = is_array( $levels ) ? $levels : array();
		$enabled_sports = array_keys( $this->get_player_preference_sports() );
		$valid_levels   = array();

		foreach ( $this->get_league_level_terms() as $level ) {
			$valid_levels[] = (int) $level->term_id;
		}

		if ( empty( $enabled_sports ) || empty( $valid_levels ) ) {
			return array();
		}

		$default_level_id = $this->get_default_league_level_id();
		$preferences      = array();

		foreach ( $sports as $sport_slug ) {
			if ( is_array( $sport_slug ) ) {
				continue;
			}

			$sport_slug = sanitize_key( wp_unslash( (string) $sport_slug ) );

			if ( '' === $sport_slug || ! in_array( $sport_slug, $enabled_sports, true ) ) {
				continue;
			}

			$level_id = isset( $levels[ $sport_slug ] ) && ! is_array( $levels[ $sport_slug ] )
				? absint( wp_unslash( (string) $levels[ $sport_slug ] ) )
				: 0;

			if ( ! in_array( $level_id, $valid_levels, true ) ) {
				$level_id = $default_level_id;
			}

			if ( $level_id ) {
				$preferences[ $sport_slug ] = $level_id;
			}
		}

		return $preferences;
	}

	/**
	 * Save sport and level preferences onto player taxonomies and mapping meta.
	 *
	 * @param int                $player_id Player ID.
	 * @param array<string, int> $preferences Sport slug to level ID map.
	 * @return void
	 */
	protected function save_player_sport_level_preferences( $player_id, $preferences ) {
		if ( ! $this->player_sport_level_fields_are_available() ) {
			return;
		}

		$sport_term_ids = array();
		$level_ids      = array();
		$mapping        = array();

		foreach ( $preferences as $sport_slug => $level_id ) {
			$sport = get_term_by( 'slug', sanitize_key( $sport_slug ), 'lf_sport' );
			$level = get_term( absint( $level_id ), 'lf_league_level' );

			if ( ! $sport || is_wp_error( $sport ) || ! $level || is_wp_error( $level ) ) {
				continue;
			}

			$sport_term_ids[]       = (int) $sport->term_id;
			$level_ids[]            = (int) $level->term_id;
			$mapping[ $sport_slug ] = (int) $level->term_id;
		}

		if ( empty( $sport_term_ids ) || empty( $level_ids ) ) {
			return;
		}

		wp_set_object_terms( $player_id, array_values( array_unique( $sport_term_ids ) ), 'lf_sport', false );
		wp_set_object_terms( $player_id, array_values( array_unique( $level_ids ) ), 'lf_league_level', false );
		update_post_meta( $player_id, 'lf_player_sport_levels', $mapping );
	}

	/**
	 * Get enabled sports for player preference controls.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_player_preference_sports() {
		$sports_manager = new Sports_Manager();
		$sports_manager->ensure_enabled_terms();

		return $sports_manager->get_enabled_sports();
	}

	/**
	 * Get saved sport and level preferences for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return array<string, array{sport_label: string, level_id: int, level_label: string}>
	 */
	protected function get_player_sport_level_preferences( $player_id ) {
		$player_id = absint( $player_id );

		if ( ! $player_id ) {
			return array();
		}

		$sports       = $this->get_player_preference_sports();
		$levels       = $this->get_league_level_terms();
		$level_labels = array();

		foreach ( $levels as $level ) {
			$level_labels[ (int) $level->term_id ] = (string) $level->name;
		}

		$saved = get_post_meta( $player_id, 'lf_player_sport_levels', true );

		if ( is_array( $saved ) ) {
			$preferences = array();

			foreach ( $saved as $sport_slug => $level_id ) {
				$sport_slug = sanitize_key( $sport_slug );
				$level_id   = absint( $level_id );

				if ( ! isset( $sports[ $sport_slug ] ) || ! isset( $level_labels[ $level_id ] ) ) {
					continue;
				}

				$preferences[ $sport_slug ] = array(
					'sport_label' => (string) $sports[ $sport_slug ]['label'],
					'level_id'    => $level_id,
					'level_label' => $level_labels[ $level_id ],
				);
			}

			if ( ! empty( $preferences ) ) {
				return $preferences;
			}
		}

		return $this->get_player_sport_level_preferences_from_terms( $player_id, $sports, $level_labels );
	}

	/**
	 * Backfill preference display from existing player taxonomy terms.
	 *
	 * @param int                  $player_id Player ID.
	 * @param array<string, array> $sports Enabled sports keyed by slug.
	 * @param array<int, string>   $level_labels League level labels keyed by term ID.
	 * @return array<string, array{sport_label: string, level_id: int, level_label: string}>
	 */
	protected function get_player_sport_level_preferences_from_terms( $player_id, $sports, $level_labels ) {
		$sport_terms = wp_get_object_terms( $player_id, 'lf_sport' );

		if ( is_wp_error( $sport_terms ) || empty( $sport_terms ) ) {
			return array();
		}

		$level_terms = wp_get_object_terms( $player_id, 'lf_league_level' );
		$level_id    = 0;

		if ( ! is_wp_error( $level_terms ) && ! empty( $level_terms ) ) {
			$first_level = reset( $level_terms );
			$level_id    = $first_level instanceof \WP_Term ? (int) $first_level->term_id : 0;
		}

		if ( ! $level_id ) {
			$level_id = $this->get_default_league_level_id();
		}

		if ( ! isset( $level_labels[ $level_id ] ) ) {
			return array();
		}

		$preferences = array();

		foreach ( $sport_terms as $sport_term ) {
			if ( ! $sport_term instanceof \WP_Term || ! isset( $sports[ $sport_term->slug ] ) ) {
				continue;
			}

			$preferences[ $sport_term->slug ] = array(
				'sport_label' => (string) $sports[ $sport_term->slug ]['label'],
				'level_id'    => $level_id,
				'level_label' => $level_labels[ $level_id ],
			);
		}

		return $preferences;
	}

	/**
	 * Render player sport and level preference summary.
	 *
	 * @param int $player_id Player ID.
	 * @return string
	 */
	protected function render_player_sport_level_summary( $player_id ) {
		$preferences = $this->get_player_sport_level_preferences( $player_id );

		if ( empty( $preferences ) ) {
			return '';
		}

		$items = array();

		foreach ( $preferences as $preference ) {
			$items[] = sprintf(
				/* translators: 1: sport label, 2: league level label. */
				__( '%1$s (%2$s)', 'leagueflow' ),
				$preference['sport_label'],
				$preference['level_label']
			);
		}

		return implode( ', ', $items );
	}

	/**
	 * Keep the user's WordPress display name aligned with their portal profile.
	 *
	 * @param \WP_User $user User.
	 * @param string   $name Full name.
	 * @return void
	 */
	protected function sync_user_display_name( $user, $name ) {
		if ( ! $user instanceof \WP_User || '' === trim( (string) $name ) ) {
			return;
		}

		$name = trim( (string) $name );
		$name_parts    = preg_split( '/\s+/', $name );
		$first_name    = is_array( $name_parts ) ? array_shift( $name_parts ) : '';
		$last_name     = is_array( $name_parts ) ? implode( ' ', $name_parts ) : '';
		$display_name  = $name;
		$nickname      = $name;

		wp_update_user(
			array(
				'ID'           => $user->ID,
				'display_name' => $display_name,
				'nickname'     => $nickname,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			)
		);
	}

	/**
	 * Get or create the current user's player record.
	 *
	 * @param \WP_User $user User.
	 * @param bool     $create Whether to create a missing player.
	 * @return int
	 */
	protected function get_player_id_for_user( $user, $create = true ) {
		$player_id = $this->find_player_id_by_user( $user->ID );

		if ( $player_id ) {
			add_user_role_if_missing( $user->ID, 'leagueflow_player' );
			return $player_id;
		}

		$email = strtolower( sanitize_email( $user->user_email ) );

		$player_id = is_email( $email ) ? $this->find_player_id_by_email( $email ) : 0;

		if ( $player_id ) {
			update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );
			add_user_role_if_missing( $user->ID, 'leagueflow_player' );
			return $player_id;
		}

		if ( $create ) {
			/**
			 * Allow an integration to auto-create a player for a logged-in user
			 * who has no existing player record (e.g. Clerk self sign-up).
			 *
			 * Return a new published lf_player post ID to grant portal access,
			 * or 0 to leave the user without a profile (default).
			 *
			 * @param int      $player_id Default 0 (no auto-create).
			 * @param \WP_User $user      The logged-in user.
			 * @param string   $email     The user's sanitized email.
			 */
			$created = (int) apply_filters( 'leagueflow_autocreate_player_id', 0, $user, $email );

			if ( $created > 0 ) {
				add_user_role_if_missing( $user->ID, 'leagueflow_player' );
				return $created;
			}
		}

		return 0;
	}

	/**
	 * Find a player by linked user ID.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	protected function find_player_id_by_user( $user_id ) {
		$players = get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_user_id',
						'value' => absint( $user_id ),
					),
				),
			)
		);

		return empty( $players ) ? 0 : absint( $players[0] );
	}

	/**
	 * Find a player by email.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	protected function find_player_id_by_email( $email ) {
		$players = get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_email',
						'value' => strtolower( sanitize_email( $email ) ),
					),
				),
			)
		);

		return empty( $players ) ? 0 : absint( $players[0] );
	}

	/**
	 * Check portal access.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	protected function user_can_access_portal( $user, $player_id = 0, $manager_teams = array() ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $player_id ) {
			return true;
		}

		if ( ! empty( $manager_teams ) ) {
			return true;
		}

		if ( is_captain_registration_enabled() || is_player_registration_enabled() ) {
			return $this->user_can_begin_registration( $user );
		}

		return false;
	}

	/**
	 * Check if a user may manage a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	protected function user_can_manage_team( $team_id, $user_id ) {
		$team = get_post( $team_id );

		if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return in_array( absint( $user_id ), get_team_manager_user_ids( $team_id ), true );
	}

	/**
	 * Check if a manager may update a roster player.
	 *
	 * @param int $player_id Player ID.
	 * @param int $team_id Team ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	protected function user_can_manage_roster_player( $player_id, $team_id, $user_id ) {
		$player = get_post( $player_id );

		if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type ) {
			return false;
		}

		if ( ! player_has_team( $player_id, $team_id ) ) {
			return false;
		}

		return $this->user_can_manage_team( $team_id, $user_id );
	}

	/**
	 * Get sports in which the current user can still create and join a team.
	 *
	 * @param int                   $player_id Linked player ID.
	 * @param array<int, \WP_Post> $manager_teams Teams managed by the user.
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_eligible_captain_sports( $player_id, $manager_teams ) {
		$sports  = ( new Sports_Manager() )->get_enabled_sports();
		$blocked = array();

		foreach ( $manager_teams as $team ) {
			$sport_slug = get_post_primary_term_slug( $team->ID, 'lf_sport' );

			if ( $sport_slug ) {
				$blocked[ $sport_slug ] = true;
			}
		}

		if ( $player_id ) {
			foreach ( array_keys( $this->get_player_registration_states( $player_id ) ) as $sport_slug ) {
				$blocked[ sanitize_key( $sport_slug ) ] = true;
			}
		}

		foreach ( array_keys( $blocked ) as $sport_slug ) {
			unset( $sports[ $sport_slug ] );
		}

		return $sports;
	}

	/**
	 * Resolve a managed team selected for the focused workspace.
	 *
	 * @param array<int, \WP_Post> $teams Managed teams.
	 * @return int
	 */
	protected function get_requested_manager_team_id( $teams ) {
		$requested = absint( wp_unslash( $_GET['leagueflow_team'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( $teams as $team ) {
			if ( $team instanceof \WP_Post && (int) $team->ID === $requested ) {
				return $requested;
			}
		}

		return 0;
	}

	/**
	 * Get teams managed by a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_manager_teams( $user_id ) {
		$teams = get_posts(
			array(
				'post_type'      => 'lf_team',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( current_user_can( 'manage_options' ) ) {
			return $teams;
		}

		return array_values(
			array_filter(
				$teams,
				static function( $team ) use ( $user_id ) {
					return in_array( absint( $user_id ), get_team_manager_user_ids( $team->ID ), true );
				}
			)
		);
	}

	/**
	 * Get team player posts.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_team_player_posts( $team_id ) {
		return get_team_roster_player_posts( absint( $team_id ) );
	}

	/**
	 * Assign player sport from their team sport.
	 *
	 * @param int $player_id Player ID.
	 * @param int $team_id Team ID.
	 * @return void
	 */
	protected function assign_player_sport_from_team( $player_id, $team_id ) {
		$sport_id = get_post_primary_term_id( $team_id, 'lf_sport' );
		$level_id = get_post_primary_term_id( $team_id, 'lf_league_level' );

		if ( $sport_id ) {
			wp_set_object_terms( $player_id, array( $sport_id ), 'lf_sport', true );
		}

		if ( $level_id ) {
			wp_set_object_terms( $player_id, array( $level_id ), 'lf_league_level', true );
		}
	}

	/**
	 * Get league level terms.
	 *
	 * @return array<int, \WP_Term>
	 */
	protected function get_league_level_terms() {
		return get_league_level_terms();
	}

	/**
	 * Get the default recreational league level ID.
	 *
	 * @return int
	 */
	protected function get_default_league_level_id() {
		ensure_default_league_levels();

		$term = get_term_by( 'slug', 'recreational', 'lf_league_level' );

		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	/**
	 * Render team membership links for the player summary.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @return string
	 */
	protected function render_player_team_links( $team_ids ) {
		$links = array();

		foreach ( $team_ids as $team_id ) {
			$team = get_post( $team_id );

			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
				continue;
			}

			$permalink = get_permalink( $team_id );
			$links[]   = $permalink
				? '<a href="' . esc_url( $permalink ) . '">' . esc_html( get_the_title( $team_id ) ) . '</a>'
				: esc_html( get_the_title( $team_id ) );
		}

		return implode( ', ', $links );
	}

	/**
	 * Check whether a player already belongs to a team in a sport.
	 *
	 * @param int    $player_id Player ID.
	 * @param string $sport_slug Sport slug.
	 * @return bool
	 */
	protected function player_has_team_in_sport( $player_id, $sport_slug ) {
		$sport_slug = sanitize_key( $sport_slug );

		if ( ! $player_id || '' === $sport_slug ) {
			return false;
		}

		foreach ( get_player_team_ids( $player_id ) as $team_id ) {
			if ( get_post_primary_term_slug( $team_id, 'lf_sport' ) === $sport_slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a player belongs to another team in the same sport.
	 *
	 * @param int    $player_id Player ID.
	 * @param string $sport_slug Sport slug.
	 * @param int    $excluded_team_id Team that may be retained.
	 * @return bool
	 */
	protected function player_has_other_team_in_sport( $player_id, $sport_slug, $excluded_team_id = 0 ) {
		$sport_slug      = sanitize_key( $sport_slug );
		$excluded_team_id = absint( $excluded_team_id );

		if ( ! $player_id || '' === $sport_slug ) {
			return false;
		}

		foreach ( get_player_team_ids( absint( $player_id ) ) as $team_id ) {
			if ( absint( $team_id ) !== $excluded_team_id && get_post_primary_term_slug( $team_id, 'lf_sport' ) === $sport_slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a display label for a sport slug.
	 *
	 * @param string $sport_slug Sport slug.
	 * @return string
	 */
	protected function get_sport_label( $sport_slug ) {
		$sport_slug = sanitize_key( $sport_slug );

		if ( '' === $sport_slug ) {
			return __( 'Selected sport', 'leagueflow' );
		}

		$sports_manager = new Sports_Manager();
		$sport          = $sports_manager->get_definition( $sport_slug );

		return ! empty( $sport['label'] ) ? (string) $sport['label'] : ucwords( str_replace( '-', ' ', $sport_slug ) );
	}

	/**
	 * Get enabled sports and their teams for request forms.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_requestable_sport_teams() {
		$sports_manager = new Sports_Manager();
		$groups         = array();

		foreach ( $sports_manager->get_enabled_sports() as $sport_slug => $sport ) {
			$teams = get_posts(
				array(
					'post_type'      => 'lf_team',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'tax_query'      => array(
						array(
							'taxonomy' => 'lf_sport',
							'field'    => 'slug',
							'terms'    => array( $sport_slug ),
						),
					),
				)
			);

			$groups[ $sport_slug ] = array(
				'label' => (string) $sport['label'],
				'teams' => $teams,
			);
		}

		return $groups;
	}

	/**
	 * Resolve existing team memberships and pending requests by sport.
	 *
	 * @param int $player_id Player ID.
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_player_registration_states( $player_id ) {
		$states = array();

		foreach ( get_player_team_ids( absint( $player_id ) ) as $team_id ) {
			$sport_slug = get_post_primary_term_slug( $team_id, 'lf_sport' );

			if ( ! $sport_slug || isset( $states[ $sport_slug ] ) ) {
				continue;
			}

			$states[ $sport_slug ] = array(
				'team_id'    => absint( $team_id ),
				'team_name'  => get_the_title( $team_id ),
				'level_id'   => get_post_primary_term_id( $team_id, 'lf_league_level' ),
				'request_id' => 0,
			);
		}

		$requests = get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => 'lf_player_id',
						'value' => absint( $player_id ),
					),
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
				),
			)
		);

		foreach ( $requests as $request ) {
			$sport_slug = sanitize_key( (string) get_post_meta( $request->ID, 'lf_sport_slug', true ) );

			if ( ! $sport_slug || isset( $states[ $sport_slug ] ) ) {
				continue;
			}

			$team_id = absint( get_post_meta( $request->ID, 'lf_team_id', true ) );
			$states[ $sport_slug ] = array(
				'team_id'    => 0,
				'team_name'  => $team_id ? get_the_title( $team_id ) : '',
				'level_id'   => get_join_request_level_id( $request->ID ),
				'request_id' => (int) $request->ID,
			);
		}

		return $states;
	}

	/**
	 * Find a team with the same title in a sport.
	 *
	 * @param string $title Team title.
	 * @param string $sport_slug Sport slug.
	 * @return int
	 */
	protected function find_team_id_by_title_and_sport( $title, $sport_slug ) {
		$teams = get_posts(
			array(
				'post_type'      => 'lf_team',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				's'              => sanitize_text_field( $title ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'lf_sport',
						'field'    => 'slug',
						'terms'    => array( sanitize_key( $sport_slug ) ),
					),
				),
			)
		);

		foreach ( $teams as $team_id ) {
			if ( 0 === strcasecmp( (string) get_the_title( $team_id ), $title ) ) {
				return absint( $team_id );
			}
		}

		return 0;
	}

	/**
	 * Get or create the logged-in captain's player profile.
	 *
	 * @param \WP_User $user User.
	 * @param string   $captain_name Captain name.
	 * @return int
	 */
	protected function get_or_create_captain_player_id( $user, $captain_name ) {
		$player_id = $this->get_player_id_for_user( $user, false );
		$email     = strtolower( sanitize_email( $user->user_email ) );

		if ( $player_id ) {
			wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => $captain_name,
				)
			);
			$this->sync_user_display_name( $user, $captain_name );
			update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );

			if ( is_email( $email ) ) {
				update_post_meta( $player_id, 'lf_email', $email );
			}

			add_user_role_if_missing( $user->ID, 'leagueflow_player' );
			return $player_id;
		}

		$player_id = wp_insert_post(
			array(
				'post_type'   => 'lf_player',
				'post_status' => 'publish',
				'post_title'  => $captain_name,
				'post_author' => (int) $user->ID,
			),
			true
		);

		if ( is_wp_error( $player_id ) || ! $player_id ) {
			return 0;
		}

		update_post_meta( $player_id, 'lf_user_id', (int) $user->ID );

		if ( is_email( $email ) ) {
			update_post_meta( $player_id, 'lf_email', $email );
		}

		$this->sync_user_display_name( $user, $captain_name );
		add_user_role_if_missing( $user->ID, 'leagueflow_player' );

		return absint( $player_id );
	}

	/**
	 * Get a sensible default name for a logged-in user.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	protected function get_default_user_full_name( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );

		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}

		if ( '' === $name || false !== strpos( $name, '@' ) ) {
			$name = trim( (string) $user->user_login );
		}

		return $name;
	}

	/**
	 * Get join requests for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_player_join_requests( $player_id ) {
		return get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => 6,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'lf_player_id',
						'value' => absint( $player_id ),
					),
				),
			)
		);
	}

	/**
	 * Count pending join requests for a player.
	 *
	 * @param int $player_id Player ID.
	 * @return int
	 */
	protected function count_pending_join_requests( $player_id ) {
		$requests = get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_player_id',
						'value' => absint( $player_id ),
					),
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
				),
			)
		);

		return count( $requests );
	}

	/**
	 * Get pending join requests for a team.
	 *
	 * @param int $team_id Team ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_team_join_requests( $team_id ) {
		$team_id = absint( $team_id );

		return get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
					array(
						'key'     => 'lf_team_id',
						'value'   => $team_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
	}

	/**
	 * Find a player's pending request in a sport, regardless of destination.
	 *
	 * @param int    $player_id Player ID.
	 * @param string $sport_slug Sport slug.
	 * @return int
	 */
	protected function find_pending_join_request_for_sport( $player_id, $sport_slug ) {
		$requests = get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'lf_player_id',
						'value' => absint( $player_id ),
					),
					array(
						'key'   => 'lf_sport_slug',
						'value' => sanitize_key( $sport_slug ),
					),
					array(
						'key'   => 'lf_request_status',
						'value' => 'pending',
					),
				),
			)
		);

		return empty( $requests ) ? 0 : absint( $requests[0] );
	}

	/**
	 * Find an existing pending request for the same player/team.
	 *
	 * @param int $player_id Player ID.
	 * @param int $team_id Team ID.
	 * @param string $sport_slug Sport slug.
	 * @return int
	 */
	protected function find_pending_join_request( $player_id, $team_id, $sport_slug = '' ) {
		$team_id    = absint( $team_id );
		$sport_slug = sanitize_key( $sport_slug );
		$meta_query = array(
			array(
				'key'   => 'lf_player_id',
				'value' => absint( $player_id ),
			),
			array(
				'key'     => 'lf_team_id',
				'value'   => $team_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
			array(
				'key'   => 'lf_request_status',
				'value' => 'pending',
			),
		);

		if ( ! $team_id && $sport_slug ) {
			$meta_query[] = array(
				'key'   => 'lf_sport_slug',
				'value' => $sport_slug,
			);
		}

		$requests = get_posts(
			array(
				'post_type'      => 'lf_join_request',
				'post_status'    => array( 'private', 'publish', 'draft', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			)
		);

		return empty( $requests ) ? 0 : absint( $requests[0] );
	}

	/**
	 * Render upcoming matches across multiple team memberships.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @param int        $limit Limit.
	 * @return string
	 */
	protected function render_upcoming_matches_for_teams( $team_ids, $limit = 6 ) {
		$matches = $this->get_upcoming_matches_for_teams( $team_ids, $limit );

		if ( empty( $matches ) ) {
			return '<p>' . esc_html__( 'No upcoming games are scheduled yet.', 'leagueflow' ) . '</p>';
		}

		return $this->render_match_stack( $matches );
	}

	/**
	 * Get upcoming matches across multiple team memberships.
	 *
	 * @param array<int> $team_ids Team IDs.
	 * @param int        $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_upcoming_matches_for_teams( $team_ids, $limit = 6 ) {
		$matches = array();

		foreach ( $team_ids as $team_id ) {
			foreach ( $this->get_upcoming_matches( $team_id, $limit ) as $match ) {
				$matches[ (int) $match['id'] ] = $match;
			}
		}

		usort(
			$matches,
			static function( $a, $b ) {
				return strtotime( (string) $a['datetime_raw'] ) <=> strtotime( (string) $b['datetime_raw'] );
			}
		);

		return array_slice( array_values( $matches ), 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Render upcoming matches for a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $limit Limit.
	 * @return string
	 */
	protected function render_upcoming_matches( $team_id, $limit = 5 ) {
		$matches = $this->get_upcoming_matches( $team_id, $limit );

		if ( empty( $matches ) ) {
			return '<p>' . esc_html__( 'No upcoming games are scheduled yet.', 'leagueflow' ) . '</p>';
		}

		return $this->render_match_stack( $matches );
	}

	/**
	 * Render a stack of match rows.
	 *
	 * @param array<int, array<string, mixed>> $matches Matches.
	 * @return string
	 */
	protected function render_match_stack( $matches ) {
		ob_start();
		?>
		<div class="leagueflow-match-stack leagueflow-portal-matches">
			<?php foreach ( $matches as $match ) : ?>
				<article class="leagueflow-match-row">
					<header class="leagueflow-match-row__header">
						<strong><?php echo esc_html( $match['sport_label'] ); ?></strong>
						<?php if ( ! empty( $match['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time><?php endif; ?>
					</header>
					<div class="leagueflow-match-row__teams">
						<span><?php echo esc_html( $match['home_team'] ); ?></span>
						<span class="leagueflow-match-row__score"><?php esc_html_e( 'vs', 'leagueflow' ); ?></span>
						<span><?php echo esc_html( $match['away_team'] ); ?></span>
					</div>
					<?php if ( ! empty( $match['venue'] ) ) : ?><p class="leagueflow-match-row__venue"><?php echo esc_html( $match['venue'] ); ?></p><?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get upcoming scheduled matches for a team.
	 *
	 * @param int $team_id Team ID.
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_upcoming_matches( $team_id, $limit ) {
		$now     = current_time( 'timestamp' );
		$matches = $this->renderer->get_match_items(
			array(
				'team'   => absint( $team_id ),
				'status' => 'scheduled',
				'limit'  => -1,
			)
		);

		$matches = array_filter(
			$matches,
			static function( $match ) use ( $now ) {
				$timestamp = ! empty( $match['datetime_raw'] ) ? strtotime( $match['datetime_raw'] ) : 0;
				return $timestamp && $timestamp >= $now;
			}
		);

		return array_slice( array_values( $matches ), 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Handle a player setting their availability for a match.
	 *
	 * @param \WP_User $user Current user.
	 * @return void
	 */
	protected function handle_set_availability( $user ) {
		$player_id = $this->get_player_id_for_user( $user, false );

		if ( ! $player_id ) {
			$this->redirect_with_notice( 'player-missing' );
		}

		$match_id = absint( wp_unslash( $_POST['lf_match_id'] ?? 0 ) );
		$status   = sanitize_key( wp_unslash( $_POST['lf_availability_status'] ?? '' ) );

		if ( ! $match_id || ! isset( Availability::statuses()[ $status ] ) ) {
			$this->redirect_with_notice( 'invalid-request' );
		}

		if ( ! $this->player_can_rsvp_match( $player_id, $match_id ) ) {
			$this->redirect_with_notice( 'access-denied' );
		}

		Availability::set( $player_id, $match_id, $status );

		$this->redirect_with_notice( 'availability-saved' );
	}

	/**
	 * Whether a player may set availability for a match (on one of their teams).
	 *
	 * @param int $player_id Player ID.
	 * @param int $match_id Match ID.
	 * @return bool
	 */
	protected function player_can_rsvp_match( $player_id, $match_id ) {
		if ( 'lf_match' !== get_post_type( $match_id ) ) {
			return false;
		}

		$team_ids = get_player_team_ids( $player_id );

		if ( empty( $team_ids ) ) {
			return false;
		}

		$home = (int) get_post_meta( $match_id, 'lf_home_team_id', true );
		$away = (int) get_post_meta( $match_id, 'lf_away_team_id', true );

		return in_array( $home, $team_ids, true ) || in_array( $away, $team_ids, true );
	}

	/**
	 * Render upcoming matches for a player with an availability (RSVP) control.
	 *
	 * @param int        $player_id Player ID.
	 * @param array<int> $team_ids Team IDs.
	 * @param int        $limit Limit.
	 * @return string
	 */
	protected function render_player_rsvp_matches( $player_id, $team_ids, $limit = 6 ) {
		$matches = $this->get_upcoming_matches_for_teams( $team_ids, $limit );

		if ( empty( $matches ) ) {
			return '<p>' . esc_html__( 'No upcoming games are scheduled yet.', 'leagueflow' ) . '</p>';
		}

		$match_ids = array_map(
			static function( $match ) {
				return (int) $match['id'];
			},
			$matches
		);

		$statuses = Availability::statuses_for_player( $player_id, $match_ids );
		$labels   = Availability::statuses();

		ob_start();
		?>
		<div class="leagueflow-match-stack leagueflow-portal-matches">
			<?php foreach ( $matches as $match ) : ?>
				<?php $current = isset( $statuses[ (int) $match['id'] ] ) ? $statuses[ (int) $match['id'] ] : ''; ?>
				<article class="leagueflow-match-row">
					<header class="leagueflow-match-row__header">
						<strong><?php echo esc_html( $match['sport_label'] ); ?></strong>
						<?php if ( ! empty( $match['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time><?php endif; ?>
					</header>
					<div class="leagueflow-match-row__teams">
						<span><?php echo esc_html( $match['home_team'] ); ?></span>
						<span class="leagueflow-match-row__score"><?php esc_html_e( 'vs', 'leagueflow' ); ?></span>
						<span><?php echo esc_html( $match['away_team'] ); ?></span>
					</div>
					<?php if ( ! empty( $match['venue'] ) ) : ?><p class="leagueflow-match-row__venue"><?php echo esc_html( $match['venue'] ); ?></p><?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leagueflow-rsvp">
						<?php echo $this->render_hidden_fields( 'set_availability' ); ?>
						<input type="hidden" name="lf_match_id" value="<?php echo esc_attr( (string) $match['id'] ); ?>" />
						<span class="leagueflow-rsvp__label"><?php esc_html_e( 'Are you playing?', 'leagueflow' ); ?></span>
						<span class="leagueflow-rsvp__options">
							<?php foreach ( $labels as $status_key => $status_label ) : ?>
								<button type="submit" name="lf_availability_status" value="<?php echo esc_attr( $status_key ); ?>" class="leagueflow-rsvp__button leagueflow-rsvp__button--<?php echo esc_attr( $status_key ); ?><?php echo $current === $status_key ? ' is-active' : ''; ?>" aria-pressed="<?php echo $current === $status_key ? 'true' : 'false'; ?>">
									<?php echo esc_html( $status_label ); ?>
								</button>
							<?php endforeach; ?>
						</span>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render an availability roll-up for a team's upcoming matches (manager view).
	 *
	 * @param int $team_id Team ID.
	 * @param int $limit Limit.
	 * @return string
	 */
	protected function render_team_availability_summary( $team_id, $limit = 6 ) {
		$matches = $this->get_upcoming_matches( $team_id, $limit );

		if ( empty( $matches ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="leagueflow-availability-rollup">
			<h4><?php esc_html_e( 'Player availability', 'leagueflow' ); ?></h4>
			<ul>
				<?php foreach ( $matches as $match ) : ?>
					<?php $counts = Availability::counts( (int) $match['id'] ); ?>
					<li>
						<span class="leagueflow-availability-rollup__match"><?php echo esc_html( $match['home_team'] . ' vs ' . $match['away_team'] ); ?></span>
						<span class="leagueflow-availability-rollup__counts">
							<?php
							printf(
								/* translators: 1: available count, 2: maybe count, 3: out count. */
								esc_html__( '%1$d in, %2$d maybe, %3$d out', 'leagueflow' ),
								absint( $counts['available'] ),
								absint( $counts['maybe'] ),
								absint( $counts['out'] )
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render hidden form fields.
	 *
	 * @param string $portal_action Portal action.
	 * @return string
	 */
	protected function render_hidden_fields( $portal_action ) {
		ob_start();
		?>
		<input type="hidden" name="action" value="leagueflow_portal" />
		<input type="hidden" name="leagueflow_portal_action" value="<?php echo esc_attr( $portal_action ); ?>" />
		<input type="hidden" name="leagueflow_redirect_to" value="<?php echo esc_url( $this->get_portal_url() ); ?>" />
		<?php wp_nonce_field( 'leagueflow_portal_' . $portal_action, 'leagueflow_portal_nonce' ); ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Store non-file onboarding fields across a validation redirect.
	 *
	 * @param string $kind captain or player.
	 * @return void
	 */
	protected function store_onboarding_draft( $kind ) {
		$kind = sanitize_key( $kind );

		if ( ! in_array( $kind, array( 'captain', 'player' ), true ) || ! get_current_user_id() ) {
			return;
		}

		if ( 'captain' === $kind ) {
			$draft = array(
				'name'               => sanitize_text_field( wp_unslash( $_POST['lf_captain_name'] ?? '' ) ),
				'player_description' => wp_kses_post( wp_unslash( $_POST['lf_player_description'] ?? '' ) ),
				'sport_slug'         => sanitize_key( wp_unslash( $_POST['lf_sport_slug'] ?? '' ) ),
				'level_id'           => absint( wp_unslash( $_POST['lf_league_level_id'] ?? 0 ) ),
				'team_name'          => sanitize_text_field( wp_unslash( $_POST['lf_team_name'] ?? '' ) ),
				'short_name'         => sanitize_text_field( wp_unslash( $_POST['lf_short_name'] ?? '' ) ),
				'team_description'   => wp_kses_post( wp_unslash( $_POST['lf_team_description'] ?? '' ) ),
			);
		} else {
			$sports = array();
			$levels = array();
			$choices = array();
			$notes = array();

			foreach ( (array) wp_unslash( $_POST['lf_player_sports'] ?? array() ) as $sport_slug ) {
				if ( ! is_array( $sport_slug ) ) {
					$sports[] = sanitize_key( $sport_slug );
				}
			}

			foreach ( (array) wp_unslash( $_POST['lf_player_sport_levels'] ?? array() ) as $sport_slug => $level_id ) {
				if ( ! is_array( $level_id ) ) {
					$levels[ sanitize_key( $sport_slug ) ] = absint( $level_id );
				}
			}

			foreach ( (array) wp_unslash( $_POST['lf_player_team_choices'] ?? array() ) as $sport_slug => $choice ) {
				if ( ! is_array( $choice ) ) {
					$choices[ sanitize_key( $sport_slug ) ] = sanitize_text_field( $choice );
				}
			}

			foreach ( (array) wp_unslash( $_POST['lf_player_request_notes'] ?? array() ) as $sport_slug => $note ) {
				if ( ! is_array( $note ) ) {
					$notes[ sanitize_key( $sport_slug ) ] = sanitize_textarea_field( $note );
				}
			}
			$draft = array(
				'name'        => sanitize_text_field( wp_unslash( $_POST['lf_player_name'] ?? '' ) ),
				'description' => wp_kses_post( wp_unslash( $_POST['lf_player_description'] ?? '' ) ),
				'sports'      => array_values( array_filter( $sports ) ),
				'levels'      => $levels,
				'choices'     => $choices,
				'notes'       => $notes,
			);
		}

		set_transient( 'leagueflow_onboarding_draft_' . get_current_user_id() . '_' . $kind, $draft, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consume a user-scoped onboarding draft after a validation redirect.
	 *
	 * @param string $kind captain or player.
	 * @return array<string, mixed>
	 */
	protected function get_onboarding_draft( $kind ) {
		$key   = 'leagueflow_onboarding_draft_' . get_current_user_id() . '_' . sanitize_key( $kind );
		$draft = get_transient( $key );

		if ( is_array( $draft ) ) {
			delete_transient( $key );
			return $draft;
		}

		return array();
	}

	/**
	 * Render notice from query string.
	 *
	 * @return string
	 */
	protected function render_notice() {
		$notice = sanitize_key( wp_unslash( $_GET['leagueflow_notice'] ?? '' ) );

		if ( ! $notice ) {
			return '';
		}

		$messages = array(
			'profile-saved'  => __( 'Profile saved.', 'leagueflow' ),
			'profile-setup-complete' => __( 'Profile setup complete.', 'leagueflow' ),
			'player-onboarding-complete' => __( 'Your profile is ready and your team requests were submitted.', 'leagueflow' ),
			'team-saved'     => __( 'Team saved.', 'leagueflow' ),
			'team-registered' => __( 'Team registered. You are assigned as the team manager and captain.', 'leagueflow' ),
			'team-exists'    => __( 'A team with that name already exists for this sport.', 'leagueflow' ),
			'captain-sport-exists' => __( 'You already manage a team in that sport.', 'leagueflow' ),
			'player-sport-exists' => __( 'You already have a team or pending request in that sport.', 'leagueflow' ),
			'player-added'   => __( 'Player added to the roster.', 'leagueflow' ),
			'roster-saved'   => __( 'Roster player saved.', 'leagueflow' ),
			'player-removed' => __( 'Player removed from the team.', 'leagueflow' ),
			'player-assigned' => __( 'That player is already assigned to another team.', 'leagueflow' ),
			'join-request-sent' => __( 'Your team request was sent.', 'leagueflow' ),
			'placement-request-sent' => __( 'Your placement request was sent.', 'leagueflow' ),
			'join-request-approved' => __( 'Join request approved.', 'leagueflow' ),
			'join-request-declined' => __( 'Join request declined.', 'leagueflow' ),
			'availability-saved' => __( 'Availability updated.', 'leagueflow' ),
			'join-request-exists' => __( 'You already have a pending request for that team.', 'leagueflow' ),
			'placement-request-exists' => __( 'You already have a pending placement request for that sport.', 'leagueflow' ),
			'join-request-member' => __( 'You are already on that team.', 'leagueflow' ),
			'placement-request-member' => __( 'You are already assigned to a team for that sport.', 'leagueflow' ),
			'invalid-email'  => __( 'Please enter a valid email address or leave the email field blank.', 'leagueflow' ),
			'access-denied'  => __( 'You do not have access to the portal.', 'leagueflow' ),
			'team-denied'    => __( 'You do not have permission to manage that team.', 'leagueflow' ),
			'player-missing' => __( 'No player profile is linked to your account.', 'leagueflow' ),
			'name-setup-required' => __( 'Enter your full name before using the portal.', 'leagueflow' ),
			'sport-level-required' => __( 'Choose at least one sport and level before using the portal.', 'leagueflow' ),
			'sport-request-required' => __( 'Choose one team or staff placement for every new sport.', 'leagueflow' ),
			'team-level-mismatch' => __( 'A selected team is no longer available at that sport and level. Review your choices.', 'leagueflow' ),
			'registration-email-denied' => __( 'New registrations require a verified UNBC email address.', 'leagueflow' ),
			'onboarding-save-error' => __( 'Your registration could not be saved. No partial requests were kept.', 'leagueflow' ),
			'registration-closed' => __( 'That registration window is currently closed.', 'leagueflow' ),
			'upload-error'   => __( 'The image upload failed. Please try another image.', 'leagueflow' ),
			'invalid-request' => __( 'The portal request could not be verified.', 'leagueflow' ),
		);

		if ( empty( $messages[ $notice ] ) ) {
			return '';
		}

		$type = in_array( $notice, array( 'invalid-email', 'access-denied', 'team-denied', 'team-exists', 'captain-sport-exists', 'player-sport-exists', 'player-missing', 'player-assigned', 'join-request-exists', 'placement-request-exists', 'join-request-member', 'placement-request-member', 'name-setup-required', 'sport-level-required', 'sport-request-required', 'team-level-mismatch', 'registration-email-denied', 'onboarding-save-error', 'registration-closed', 'upload-error', 'invalid-request' ), true ) ? 'error' : 'success';

		return sprintf(
			'<div class="leagueflow-portal__notice leagueflow-portal__notice--%1$s">%2$s</div>',
			esc_attr( $type ),
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * Render a simple panel.
	 *
	 * @param string $title Title.
	 * @param string $content HTML content.
	 * @return string
	 */
	protected function render_panel( $title, $content ) {
		return sprintf(
			'<section class="leagueflow-portal__panel"><h2>%1$s</h2>%2$s</section>',
			esc_html( $title ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Get portal URL.
	 *
	 * @return string
	 */
	protected function get_portal_url() {
		$page_id = absint( get_option( 'leagueflow_portal_page_id' ) );

		if ( $page_id ) {
			$permalink = get_permalink( $page_id );

			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/portal/' );
	}

	/**
	 * Redirect to the portal with a notice.
	 *
	 * @param string               $notice Notice key.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @param string               $fragment Optional URL fragment without the hash.
	 * @return void
	 */
	protected function redirect_with_notice( $notice, $args = array(), $fragment = '' ) {
		$redirect = esc_url_raw( wp_unslash( $_POST['leagueflow_redirect_to'] ?? $this->get_portal_url() ) );
		$action   = sanitize_key( wp_unslash( $_POST['leagueflow_portal_action'] ?? '' ) );
		$error_notices = array( 'invalid-request', 'name-setup-required', 'sport-level-required', 'sport-request-required', 'team-level-mismatch', 'team-exists', 'captain-sport-exists', 'player-sport-exists', 'registration-email-denied', 'onboarding-save-error', 'registration-closed', 'upload-error' );

		if ( in_array( $notice, $error_notices, true ) ) {
			if ( 'complete_captain_onboarding' === $action ) {
				$this->store_onboarding_draft( 'captain' );
			} elseif ( 'complete_player_onboarding' === $action ) {
				$this->store_onboarding_draft( 'player' );
			}
		}

		$redirect = remove_query_arg( array( 'leagueflow_notice', 'leagueflow_path' ), $redirect );

		if ( 'complete_captain_onboarding' === $action && 'team-registered' !== $notice ) {
			$redirect = add_query_arg( 'leagueflow_path', 'captain', $redirect );
		} elseif ( 'complete_player_onboarding' === $action ) {
			$redirect = add_query_arg( 'leagueflow_path', 'player', $redirect );
		}

		$redirect = add_query_arg( 'leagueflow_notice', sanitize_key( $notice ), $redirect );

		if ( is_array( $args ) && ! empty( $args ) ) {
			$redirect = add_query_arg( $args, $redirect );
		}

		$fragment = sanitize_title( $fragment );
		if ( '' !== $fragment ) {
			$redirect .= '#' . $fragment;
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
