<?php
/**
 * Shared helper functions.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Default plugin settings.
 *
 * @return array<string, mixed>
 */
function defaults() {
	return array(
		'points_win'            => 3,
		'points_draw'           => 1,
		'points_loss'           => 0,
		'forfeit_score_winner'  => 3,
		'team_slug'             => 'teams',
		'match_slug'            => 'matches',
		'competition_slug'      => 'competition',
		'season_slug'           => 'season',
		'show_logos'            => 1,
		'show_player_photos'    => 1,
		'date_time_format'      => 'F j, Y g:i a',
		'tie_breakers'          => array( 'goal_difference', 'goals_for', 'wins', 'name' ),
		'min_rest_days'             => 0,
		'min_days_between_rematch'  => 0,
		'max_games_per_day_per_team' => 0,
		'captain_registration_open' => 1,
		'player_registration_open' => 0,
		'registration_email'    => '',
		'cleanup_on_uninstall'  => 0,
	);
}

/**
 * Default league level definitions.
 *
 * @return array<string, string>
 */
function league_level_definitions() {
	return array(
		'recreational' => __( 'Recreational', 'leagueflow' ),
		'competitive'  => __( 'Competitive', 'leagueflow' ),
	);
}

/**
 * Ensure bundled league level terms exist.
 *
 * @return void
 */
function ensure_default_league_levels() {
	foreach ( league_level_definitions() as $slug => $label ) {
		$term = get_term_by( 'slug', $slug, 'lf_league_level' );

		if ( $term && ! is_wp_error( $term ) ) {
			continue;
		}

		wp_insert_term(
			$label,
			'lf_league_level',
			array(
				'slug' => $slug,
			)
		);
	}
}

/**
 * Backfill existing records into the default league level once.
 *
 * @return void
 */
function ensure_default_league_level_assignments() {
	if ( get_option( 'leagueflow_league_level_migration_complete' ) ) {
		return;
	}

	ensure_default_league_levels();

	$term = get_term_by( 'slug', 'recreational', 'lf_league_level' );

	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	foreach ( array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ) as $post_type ) {
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'lf_league_level',
						'operator' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			wp_set_object_terms( (int) $post_id, array( (int) $term->term_id ), 'lf_league_level', false );
		}
	}

	update_option( 'leagueflow_league_level_migration_complete', 1 );
}

/**
 * Get league level terms with bundled levels first.
 *
 * @return array<int, \WP_Term>
 */
function get_league_level_terms() {
	ensure_default_league_levels();

	$terms = get_terms(
		array(
			'taxonomy'   => 'lf_league_level',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	$by_slug = array();

	foreach ( $terms as $term ) {
		$by_slug[ $term->slug ] = $term;
	}

	$ordered = array();

	foreach ( array_keys( league_level_definitions() ) as $slug ) {
		if ( isset( $by_slug[ $slug ] ) ) {
			$ordered[] = $by_slug[ $slug ];
			unset( $by_slug[ $slug ] );
		}
	}

	return array_merge( $ordered, array_values( $by_slug ) );
}

/**
 * Get bundled sport icon SVG path data.
 *
 * Most paths are static exports from Material Design Icons via Iconify. Pickleball
 * uses Material Symbols because MDI does not include a dedicated icon.
 *
 * @param string $sport_slug Sport slug.
 * @return array{body: string, viewBox: string}
 */
function sport_icon_definition( $sport_slug ) {
	$sport_slug = sanitize_key( (string) $sport_slug );
	$aliases    = array(
		'flag-football' => 'american-football',
		'football'      => 'american-football',
	);
	$sport_slug = $aliases[ $sport_slug ] ?? $sport_slug;

	$icons = array(
		'soccer'            => '<path fill="currentColor" d="m16.93 17.12l-.8-1.36l1.46-4.37l1.41-.47l1 .75v.14c0 .07.03.13.03.19c0 1.97-.66 3.71-1.97 5.21zM9.75 15l-1.37-4.03L12 8.43l3.62 2.54L14.25 15zM12 20.03c-.88 0-1.71-.14-2.5-.42l-.69-1.51l.66-1.1h5.11l.61 1.1l-.69 1.51c-.79.28-1.62.42-2.5.42m-6.06-2.82c-.53-.62-.99-1.45-1.38-2.46c-.39-1.02-.59-1.94-.59-2.75c0-.06.03-.12.03-.19v-.14l1-.75l1.41.47l1.46 4.37l-.8 1.36zM11 5.29v1.4L7 9.46l-1.34-.42l-.42-1.36C5.68 7 6.33 6.32 7.19 5.66s1.68-1.09 2.46-1.31zm3.35-.94c.78.22 1.6.65 2.46 1.31S18.32 7 18.76 7.68l-.42 1.36l-1.34.43l-4-2.77V5.29zm-9.42.58C3 6.89 2 9.25 2 12s1 5.11 2.93 7.07S9.25 22 12 22s5.11-1 7.07-2.93S22 14.75 22 12s-1-5.11-2.93-7.07S14.75 2 12 2S6.89 3 4.93 4.93"/>',
		'basketball'        => '<path fill="currentColor" d="M2.34 14.63c.6-.22 1.22-.33 1.88-.33q2.01 0 3.51 1.26L4.59 18.7a10.6 10.6 0 0 1-2.25-4.07M15.56 9.8c1.97 1.47 4.1 1.83 6.38 1.08c.03.21.06.59.06 1.12c0 1.03-.25 2.18-.72 3.45c-.47 1.26-1.05 2.28-1.73 3.05l-6.33-6.31zm-6.79 6.84c1.06 1.53 1.28 3.2.65 5.02c-1.42-.41-2.69-1.05-3.75-1.93zm3.42-3.42l6.31 6.33c-2.17 1.9-4.72 2.7-7.62 2.39c.21-.66.32-1.38.32-2.16c0-.62-.14-1.35-.42-2.18s-.61-1.51-.98-2.04zM8.81 14.5a6.7 6.7 0 0 0-3.23-1.59c-1.22-.23-2.39-.16-3.52.22c-.03-.22-.06-.6-.06-1.13c0-1.03.25-2.18.72-3.45c.47-1.26 1.05-2.28 1.73-3.05l6.66 6.69zm6.75-6.77c-1.34-1.65-1.65-3.45-.93-5.39c.62.16 1.33.46 2.13.92c.79.45 1.44.9 1.94 1.33zm6.1 1.65c-.6.21-1.22.32-1.88.32c-1.09 0-2.14-.32-3.14-.98l3.09-3.05c.88 1.1 1.52 2.33 1.93 3.71m-9.47 1.73L5.5 4.45c2.17-1.9 4.72-2.7 7.63-2.39q-.33.99-.33 2.16c0 .72.16 1.53.49 2.44c.33.9.71 1.62 1.21 2.15z"/>',
		'volleyball'        => '<path fill="currentColor" d="M19.04 4.85C17.34 3.2 15.33 2.25 13 2v3.62l9 5.18c-.28-2.3-1.27-4.3-2.96-5.95M12 22c3.44 0 6.16-1.38 8.17-4.14L17.06 16l-8.99 5.2c1.25.53 2.57.8 3.93.8m1-10.59l8.15 4.66c.44-.94.73-1.93.85-2.96l-9-5.18zm-9.12 6.4c.66.91 1.38 1.65 2.17 2.19l8.99-5.1L12 13.15zM11.04 2C10 2.09 9 2.36 8 2.8v10.35l3.04-1.74zM2 12c0 1.39.3 2.77.89 4.12L6 14.28V4c-2.67 2-4 4.65-4 8"/>',
		'american-football' => '<path fill="currentColor" d="M8.39 21L3 15.61c0 1.09.04 2.1.2 3.02c.15.92.3 1.47.51 1.66c.19.21.73.36 1.64.52s1.92.19 3.04.19M15.5 9.89L9.89 15.5L8.5 14.11l5.61-5.61zM3.29 13.08l7.63 7.63c2.78-.5 4.98-1.56 6.61-3.18c1.62-1.63 2.68-3.83 3.18-6.61l-7.63-7.63c-2.78.5-4.98 1.56-6.61 3.18s-2.68 3.83-3.18 6.61M15.61 3L21 8.39c0-1.09-.04-2.1-.19-3.02c-.16-.92-.31-1.47-.52-1.66c-.19-.21-.73-.36-1.64-.51S16.73 3 15.61 3"/>',
		'baseball'          => '<path fill="currentColor" d="M12 2c-2.5 0-4.75.9-6.5 2.4c.5.41.91.87 1.3 1.36l1.09-.63l1 1.74l-1 .57c.56 1.09.93 2.29 1.06 3.56H10v2H8.95c-.13 1.27-.5 2.47-1.06 3.56l1 .57l-1 1.74l-1.09-.63c-.39.49-.8.95-1.3 1.36c1.75 1.5 4 2.4 6.5 2.4s4.75-.9 6.5-2.4c-.5-.41-.91-.87-1.31-1.36l-1.08.63l-1-1.74l1-.58A9.9 9.9 0 0 1 15.05 13H14v-2h1.05c.13-1.27.5-2.47 1.06-3.55l-1-.58l1-1.74l1.08.63c.4-.49.81-.95 1.31-1.36C16.75 2.9 14.5 2 12 2M4.12 5.85A9.94 9.94 0 0 0 2 12c0 2.32.79 4.45 2.12 6.15c.34-.28.64-.6.93-.93l-.62-.35l1-1.74l.73.43c.39-.79.66-1.65.77-2.56H6v-2h.93c-.11-.91-.38-1.77-.77-2.56l-.73.43l-1-1.74l.62-.35c-.29-.33-.59-.65-.93-.93m15.76 0c-.34.28-.64.6-.93.93l.62.35l-1 1.74l-.73-.43c-.39.79-.66 1.65-.77 2.56H18v2h-.93c.11.91.38 1.77.77 2.56l.73-.43l1 1.74l-.62.35c.29.33.59.65.93.93A9.94 9.94 0 0 0 22 12c0-2.32-.79-4.45-2.12-6.15"/>',
		'hockey'            => '<path fill="currentColor" d="M17.68 4H14.3l-1.74 4c-.03.04-.11.22-.25.5s-.25.54-.31.69L9.7 4H6.32l4.09 8.84c.09.22.32.75.7 1.59c.39.85.67 1.48.89 1.92l1.41 3.09c.19.34.48.51.89.51L19 20v-4h-4l-1.4-3.16zm2.35 12v4H22v-3c0-.27-.09-.5-.28-.72c-.19-.2-.42-.28-.72-.28zM5 16v4l4.7-.05c.41 0 .7-.17.89-.51l.85-1.94l-1.6-3.44L9 16zm-3 4h1.97v-4H3c-.3 0-.53.08-.72.28c-.19.22-.28.45-.28.72z"/>',
		'cricket'           => '<path fill="currentColor" d="m14.34 17.77l1.41-1.41L20 20.58L18.56 22zM18.5 2A3.5 3.5 0 0 1 22 5.5A3.5 3.5 0 0 1 18.5 9A3.5 3.5 0 0 1 15 5.5A3.5 3.5 0 0 1 18.5 2M2.24 7.11l2.83-2.83a1.02 1.02 0 0 1 1.43 0l8.47 8.49c.39.39.39 1.02 0 1.41L12.14 17a.99.99 0 0 1-1.42 0L2.24 8.53c-.39-.4-.39-1.03 0-1.42"/>',
		'rugby'             => '<path fill="currentColor" d="M16.22 16.22c2.03-2.03 3.11-4.72 3.23-8.02c-1.09 2.41-2.64 4.61-4.64 6.61s-4.2 3.55-6.61 4.64c3.3-.09 5.96-1.17 8.02-3.23M7.78 7.78C5.75 9.81 4.67 12.5 4.55 15.8c.45-1 1.15-2.15 2.06-3.45c.89-1.3 1.77-2.35 2.58-3.16c2-2 4.2-3.55 6.61-4.64c-3.3.09-5.96 1.17-8.02 3.23M20.5 3.5c.5.55.84 1.61.97 3.2c.12 1.6-.12 3.46-.73 5.6c-.61 2.15-1.63 3.93-3.07 5.37C16.36 19 14.8 19.95 13 20.55c-1.79.61-3.56.92-5.31.92c-2.13 0-3.52-.33-4.19-.97c-.5-.55-.84-1.61-.97-3.2c-.12-1.6.12-3.46.73-5.6c.61-2.15 1.63-3.93 3.07-5.37C7.64 5 9.2 4.05 11 3.45c1.79-.61 3.56-.92 5.31-.92c2.13 0 3.52.33 4.19.97"/>',
		'curling'           => '<path fill="currentColor" d="M10 3v2c2.5 0 3.9.05 4.72.41c.54.24 1.01.8 1.53 1.59H5v2h14.62l-.73-1.45c-1.03-2.05-1.93-3.33-3.36-3.96C14.1 2.95 12.5 3 10 3m-4 8c-2.22 0-4 1.78-4 4v3c0 2.22 1.78 4 4 4h12c2.22 0 4-1.78 4-4v-3c0-2.22-1.78-4-4-4z"/>',
		'pickleball'        => '<path fill="currentColor" d="M18.575 22L12.7 16.125q-.725.65-1.612.95t-1.788.3q-1 0-1.937-.375t-1.688-1.125l-3.8-3.775q-.425-.425-.65-.987T1 9.975t.225-1.137t.65-.988L5.85 3.875q.425-.425.988-.65T7.974 3t1.138.225t.987.65l3.775 3.8q.75.75 1.125 1.688t.375 1.937q0 .9-.312 1.788T14.1 14.7l5.9 5.9zM19.5 9q-1.45 0-2.475-1.025T16 5.5t1.025-2.475T19.5 2t2.475 1.025T23 5.5t-1.025 2.475T19.5 9"/>',
		'dodgeball'         => '<path fill="currentColor" d="M12 2A10 10 0 0 0 2 12a10 10 0 0 0 10 10a10 10 0 0 0 10-10A10 10 0 0 0 12 2m0 7a3 3 0 0 1 3 3a3 3 0 0 1-3 3a3 3 0 0 1-3-3a3 3 0 0 1 3-3"/>',
		'spike-ball'        => '<path fill="currentColor" d="M12 2A10 10 0 0 0 2 12a10 10 0 0 0 10 10a10 10 0 0 0 10-10A10 10 0 0 0 12 2m0 2a8 8 0 0 1 8 8a8 8 0 0 1-8 8a8 8 0 0 1-8-8a8 8 0 0 1 8-8m0 2a6 6 0 0 0-6 6a6 6 0 0 0 6 6a6 6 0 0 0 6-6a6 6 0 0 0-6-6m0 2a4 4 0 0 1 4 4a4 4 0 0 1-4 4a4 4 0 0 1-4-4a4 4 0 0 1 4-4m0 2a2 2 0 0 0-2 2a2 2 0 0 0 2 2a2 2 0 0 0 2-2a2 2 0 0 0-2-2"/>',
	);

	return array(
		'body'    => $icons[ $sport_slug ] ?? $icons['soccer'],
		'viewBox' => '0 0 24 24',
	);
}

/**
 * Render a bundled sport icon SVG.
 *
 * @param string               $sport_slug Sport slug.
 * @param array<string, mixed> $args SVG options.
 * @return string
 */
function sport_icon_svg( $sport_slug, $args = array() ) {
	$definition = sport_icon_definition( $sport_slug );
	$args       = wp_parse_args(
		$args,
		array(
			'class'       => 'leagueflow-sport-icon',
			'width'       => '1em',
			'height'      => '1em',
			'aria_hidden' => true,
			'focusable'   => 'false',
		)
	);

	$attributes = array(
		'xmlns'     => 'http://www.w3.org/2000/svg',
		'viewBox'   => $definition['viewBox'],
		'width'     => $args['width'],
		'height'    => $args['height'],
		'class'     => $args['class'],
		'focusable' => $args['focusable'],
	);

	if ( $args['aria_hidden'] ) {
		$attributes['aria-hidden'] = 'true';
	}

	$markup = '<svg';
	foreach ( $attributes as $name => $value ) {
		if ( null === $value || '' === $value ) {
			continue;
		}
		$markup .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
	}
	$markup .= '>' . $definition['body'] . '</svg>';

	return $markup;
}

/**
 * Ensure the front-end portal roles and administrator capabilities exist.
 *
 * @return void
 */
function ensure_portal_roles() {
	add_role(
		'leagueflow_player',
		__( 'LeagueFlow Player', 'leagueflow' ),
		array(
			'read'                         => true,
			'leagueflow_manage_profile'    => true,
		)
	);

	add_role(
		'leagueflow_team_manager',
		__( 'LeagueFlow Team Manager', 'leagueflow' ),
		array(
			'read'                         => true,
			'leagueflow_manage_profile'    => true,
			'leagueflow_manage_team'       => true,
		)
	);

	foreach ( array( 'leagueflow_player', 'leagueflow_team_manager' ) as $role_name ) {
		$role = get_role( $role_name );

		if ( ! $role ) {
			continue;
		}

		$role->add_cap( 'read' );
		$role->add_cap( 'leagueflow_manage_profile' );

		if ( 'leagueflow_team_manager' === $role_name ) {
			$role->add_cap( 'leagueflow_manage_team' );
		}
	}

	$admin = get_role( 'administrator' );

	if ( $admin ) {
		$admin->add_cap( 'leagueflow_manage_profile' );
		$admin->add_cap( 'leagueflow_manage_team' );
		$admin->add_cap( 'leagueflow_manage_placements' );
		foreach ( array( 'leagueflow_manage_player_accounts', 'leagueflow_manage_field_availability', 'leagueflow_manage_schedule', 'leagueflow_overwrite_schedule', 'leagueflow_manage_fixtures' ) as $capability ) {
			if ( ! $admin->has_cap( $capability ) ) {
				$admin->add_cap( $capability );
			}
		}
	}
}

/** Player editing alone never grants WordPress account administration. */
function can_manage_player_accounts() {
	return current_user_can( 'leagueflow_manage_player_accounts' ) && current_user_can( 'edit_users' ) && current_user_can( 'promote_users' );
}

/** Only low-privilege portal identities may be linked to player records. */
function can_link_player_account( $user_id ) {
	$user = get_user_by( 'id', absint( $user_id ) );
	if ( ! can_manage_player_accounts() || ! $user instanceof \WP_User || $user->ID === get_current_user_id()
		|| ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'promote_user', $user->ID ) ) {
		return false;
	}
	return is_participant_account( $user );
}

/** True when an account holds only participant roles and capabilities. */
function is_participant_account( $user ) {
	// A referee is still a participant: the role only reads rosters and enters results.
	$roles = array( 'subscriber', 'leagueflow_player', 'leagueflow_team_manager', 'leagueflow_referee' );
	if ( ! $user instanceof \WP_User || empty( $user->roles ) || array_diff( $user->roles, $roles ) || is_super_admin( $user->ID ) ) {
		return false;
	}
	$allowed = array_merge( $roles, array( 'read', 'level_0', 'leagueflow_manage_profile', 'leagueflow_manage_team', 'leagueflow_view_assigned_rosters', 'leagueflow_submit_assigned_scores' ) );
	foreach ( $user->allcaps as $capability => $granted ) {
		if ( $granted && ! in_array( $capability, $allowed, true ) ) {
			return false;
		}
	}
	$capabilities = array_keys( $user->allcaps );
	foreach ( wp_roles()->roles as $role ) {
		$capabilities = array_merge( $capabilities, array_keys( $role['capabilities'] ) );
	}
	foreach ( array_diff( array_unique( $capabilities ), $allowed ) as $capability ) {
		if ( user_can( $user, $capability ) ) { return false; }
	}
	return true;
}

/** Server-controlled bounds for public collection requests. */
function public_collection_limit( $limit ) {
	return is_numeric( $limit ) && (int) $limit > 0 ? min( 100, (int) $limit ) : 20;
}
function public_collection_page( $page ) {
	return is_numeric( $page ) ? max( 1, min( 10000, (int) $page ) ) : 1;
}
function public_collection_date( $value ) {
	if ( ! is_scalar( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', (string) $value, $parts ) ) { return ''; }
	return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? (string) $value : '';
}


/**
 * Add a role to a user without replacing their existing roles.
 *
 * @param int    $user_id User ID.
 * @param string $role Role slug.
 * @return void
 */
function add_user_role_if_missing( $user_id, $role ) {
	$user = get_user_by( 'id', absint( $user_id ) );

	if ( ! $user instanceof \WP_User || in_array( $role, (array) $user->roles, true ) ) {
		return;
	}

	$user->add_role( $role );
}

/**
 * Sanitize a list of WordPress user IDs.
 *
 * @param mixed $value Raw value.
 * @return array<int, int>
 */
function sanitize_user_id_list( $value ) {
	$value = is_array( $value ) ? $value : array();
	$value = array_filter( array_unique( array_map( 'absint', $value ) ) );

	return array_values( $value );
}

/**
 * Get manager user IDs assigned to a team.
 *
 * @param int $team_id Team post ID.
 * @return array<int, int>
 */
function get_team_manager_user_ids( $team_id ) {
	return sanitize_user_id_list( get_post_meta( absint( $team_id ), 'lf_manager_user_ids', true ) );
}

/**
 * Normalize one player's team-specific roster fields.
 *
 * @param mixed $value Raw detail value.
 * @return array{is_captain: int, jersey_number: int|string, position: string}
 */
function sanitize_player_team_detail( $value ) {
	$value      = is_array( $value ) ? $value : array();
	$jersey_raw = isset( $value['jersey_number'] ) && ! is_array( $value['jersey_number'] )
		? trim( (string) $value['jersey_number'] )
		: '';
	$jersey     = '';

	if ( '' !== $jersey_raw && is_numeric( $jersey_raw ) && (int) $jersey_raw >= 0 ) {
		$jersey = absint( $jersey_raw );
	}

	return array(
		'is_captain'    => empty( $value['is_captain'] ) ? 0 : 1,
		'jersey_number' => $jersey,
		'position'      => sanitize_text_field( (string) ( $value['position'] ?? '' ) ),
	);
}

/**
 * Normalize team-specific roster details keyed by team post ID.
 *
 * @param mixed $value Raw details map.
 * @return array<int, array{is_captain: int, jersey_number: int|string, position: string}>
 */
function sanitize_player_team_details( $value ) {
	$value   = is_array( $value ) ? $value : array();
	$details = array();

	foreach ( $value as $team_id => $detail ) {
		$team_id = absint( $team_id );

		if ( ! $team_id || ! is_array( $detail ) ) {
			continue;
		}

		$details[ $team_id ] = sanitize_player_team_detail( $detail );
	}

	return $details;
}

/**
 * Get every team a player belongs to, including legacy primary-team meta.
 *
 * @param int $player_id Player post ID.
 * @return array<int, int>
 */
function get_player_team_ids( $player_id ) {
	$player_id = absint( $player_id );
	$team_ids  = get_post_meta( $player_id, 'lf_team_ids', true );
	$team_ids  = sanitize_user_id_list( is_array( $team_ids ) ? $team_ids : array() );
	$primary   = (int) get_post_meta( $player_id, 'lf_team_id', true );

	if ( $primary ) {
		array_unshift( $team_ids, $primary );
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $team_ids ) ) ) );
}

/**
 * Get all team-specific roster details, with a lazy legacy fallback.
 *
 * @param int $player_id Player post ID.
 * @return array<int, array{is_captain: int, jersey_number: int|string, position: string}>
 */
function get_player_team_details( $player_id ) {
	$player_id = absint( $player_id );
	$details   = sanitize_player_team_details( get_post_meta( $player_id, 'lf_player_team_details', true ) );
	$primary   = absint( get_post_meta( $player_id, 'lf_team_id', true ) );
	$user_id   = absint( get_post_meta( $player_id, 'lf_user_id', true ) );

	foreach ( get_player_team_ids( $player_id ) as $team_id ) {
		if ( isset( $details[ $team_id ] ) ) {
			continue;
		}

		$is_managed_team = $user_id && in_array( $user_id, get_team_manager_user_ids( $team_id ), true );
		$is_primary      = $team_id === $primary;

		$details[ $team_id ] = sanitize_player_team_detail(
			array(
				'is_captain'    => $is_managed_team || ( $is_primary && (bool) get_post_meta( $player_id, 'lf_is_captain', true ) ),
				'jersey_number' => $is_primary ? get_post_meta( $player_id, 'lf_jersey_number', true ) : '',
				'position'      => $is_primary ? get_post_meta( $player_id, 'lf_position', true ) : '',
			)
		);
	}

	return $details;
}

/**
 * Get a player's roster details for one team.
 *
 * @param int $player_id Player post ID.
 * @param int $team_id Team post ID.
 * @return array{is_captain: int, jersey_number: int|string, position: string}
 */
function get_player_team_detail( $player_id, $team_id ) {
	$details = get_player_team_details( absint( $player_id ) );
	$team_id = absint( $team_id );

	return isset( $details[ $team_id ] ) ? $details[ $team_id ] : sanitize_player_team_detail( array() );
}

/**
 * Keep legacy single-team roster fields synchronized for compatibility.
 *
 * @param int $player_id Player post ID.
 * @return void
 */
function sync_player_legacy_team_meta( $player_id ) {
	$player_id = absint( $player_id );
	$team_ids  = get_player_team_ids( $player_id );
	$primary   = absint( get_post_meta( $player_id, 'lf_team_id', true ) );

	if ( $primary && ! in_array( $primary, $team_ids, true ) ) {
		$primary = 0;
	}

	if ( ! $primary && ! empty( $team_ids ) ) {
		$primary = absint( $team_ids[0] );
		update_post_meta( $player_id, 'lf_team_id', $primary );
	}

	$details    = get_player_team_details( $player_id );
	$is_captain = false;

	foreach ( $team_ids as $team_id ) {
		if ( ! empty( $details[ $team_id ]['is_captain'] ) ) {
			$is_captain = true;
			break;
		}
	}

	update_post_meta( $player_id, 'lf_is_captain', $is_captain ? 1 : 0 );

	$primary_detail = $primary && isset( $details[ $primary ] ) ? $details[ $primary ] : sanitize_player_team_detail( array() );

	if ( '' === $primary_detail['jersey_number'] ) {
		delete_post_meta( $player_id, 'lf_jersey_number' );
	} else {
		update_post_meta( $player_id, 'lf_jersey_number', $primary_detail['jersey_number'] );
	}

	if ( '' === $primary_detail['position'] ) {
		delete_post_meta( $player_id, 'lf_position' );
	} else {
		update_post_meta( $player_id, 'lf_position', $primary_detail['position'] );
	}
}

/**
 * Save roster details for one team without affecting other memberships.
 *
 * @param int                  $player_id Player post ID.
 * @param int                  $team_id Team post ID.
 * @param array<string, mixed> $detail Roster detail updates.
 * @return bool
 */
function set_player_team_detail( $player_id, $team_id, $detail ) {
	$player_id = absint( $player_id );
	$team_id   = absint( $team_id );

	if ( ! $player_id || ! $team_id || 'lf_player' !== get_post_type( $player_id ) || 'lf_team' !== get_post_type( $team_id ) ) {
		return false;
	}

	$details            = get_player_team_details( $player_id );
	$current            = isset( $details[ $team_id ] ) ? $details[ $team_id ] : sanitize_player_team_detail( array() );
	$details[ $team_id ] = sanitize_player_team_detail( array_merge( $current, is_array( $detail ) ? $detail : array() ) );

	update_post_meta( $player_id, 'lf_player_team_details', sanitize_player_team_details( $details ) );
	sync_player_legacy_team_meta( $player_id );

	return true;
}

/**
 * Replace a player's team memberships while keeping legacy primary-team meta.
 *
 * @param int        $player_id Player post ID.
 * @param array<int> $team_ids Team IDs.
 * @return void
 */
function set_player_team_ids( $player_id, $team_ids ) {
	$player_id = absint( $player_id );
	$team_ids = sanitize_user_id_list( $team_ids );
	$details  = get_player_team_details( $player_id );
	$kept     = array();

	foreach ( $team_ids as $team_id ) {
		$kept[ $team_id ] = isset( $details[ $team_id ] ) ? $details[ $team_id ] : sanitize_player_team_detail( array() );
	}

	update_post_meta( $player_id, 'lf_team_ids', $team_ids );
	update_post_meta( $player_id, 'lf_team_id', empty( $team_ids ) ? 0 : absint( $team_ids[0] ) );
	update_post_meta( $player_id, 'lf_player_team_details', sanitize_player_team_details( $kept ) );
	sync_player_legacy_team_meta( $player_id );
}

/**
 * Report memberships an authorized person just added, so matching pending
 * join requests can be closed.
 *
 * Call this only from explicit, user-driven roster entry points (admin player
 * save, portal roster add, portal or inbox request review). The generic
 * membership setters never call it, so migrations, imports, demo data and
 * rollbacks never change requests.
 *
 * @param int        $player_id Player post ID.
 * @param array<int> $before_team_ids Team IDs before the change.
 * @param string     $source Entry point key (kept in request history).
 * @param int        $exclude_request_id Request already closed by this change.
 * @return array<int, int> Added team IDs.
 */
function reconcile_roster_additions( $player_id, $before_team_ids, $source, $exclude_request_id = 0 ) {
	$player_id = absint( $player_id );
	$added     = array_values( array_diff( get_player_team_ids( $player_id ), array_map( 'absint', (array) $before_team_ids ) ) );

	if ( $player_id && $added ) {
		/**
		 * Fires after an authorized person added roster memberships.
		 *
		 * @param int                  $player_id Player post ID.
		 * @param array<int>           $added Newly added team IDs.
		 * @param array<string, mixed> $context source and exclude_request_id.
		 */
		do_action(
			'leagueflow_roster_memberships_added',
			$player_id,
			$added,
			array(
				'source'             => sanitize_key( $source ),
				'exclude_request_id' => absint( $exclude_request_id ),
			)
		);
	}

	return $added;
}

/**
 * Add a team membership to a player.
 *
 * @param int $player_id Player post ID.
 * @param int $team_id Team post ID.
 * @return void
 */
function add_player_team_id( $player_id, $team_id ) {
	$team_ids   = get_player_team_ids( $player_id );
	$team_ids[] = absint( $team_id );

	set_player_team_ids( $player_id, $team_ids );
}

/**
 * Remove a team membership from a player.
 *
 * @param int $player_id Player post ID.
 * @param int $team_id Team post ID.
 * @return void
 */
function remove_player_team_id( $player_id, $team_id ) {
	$team_id  = absint( $team_id );
	$team_ids = array_values(
		array_filter(
			get_player_team_ids( $player_id ),
			static function( $candidate ) use ( $team_id ) {
				return absint( $candidate ) !== $team_id;
			}
		)
	);

	set_player_team_ids( $player_id, $team_ids );
}

/**
 * Check whether a player belongs to a team.
 *
 * @param int $player_id Player post ID.
 * @param int $team_id Team post ID.
 * @return bool
 */
function player_has_team( $player_id, $team_id ) {
	return in_array( absint( $team_id ), get_player_team_ids( $player_id ), true );
}

/**
 * IDs of players whose memberships include a team.
 *
 * A database pre-filter on the stored membership meta (primary team, or the
 * serialized team list as integer or string values) narrows the candidates;
 * player_has_team() then confirms each one, so a loose pre-filter match never
 * adds a player and no member is missed.
 *
 * @param int                $team_id Team ID.
 * @param string|array<int, string> $statuses Post statuses to include.
 * @return array<int, int>
 */
function get_team_member_player_ids( $team_id, $statuses = 'any' ) {
	$team_id = absint( $team_id );

	if ( ! $team_id ) {
		return array();
	}

	$candidates = get_posts(
		array(
			'post_type'              => 'lf_player',
			'post_status'            => $statuses,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				'relation' => 'OR',
				array(
					'key'   => 'lf_team_id',
					'value' => $team_id,
				),
				array(
					'key'     => 'lf_team_ids',
					'value'   => ';i:' . $team_id . ';',
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'lf_team_ids',
					'value'   => '"' . $team_id . '";',
					'compare' => 'LIKE',
				),
			),
		)
	);

	return array_values(
		array_filter(
			array_map( 'absint', $candidates ),
			static function ( $player_id ) use ( $team_id ) {
				return player_has_team( $player_id, $team_id );
			}
		)
	);
}

/**
 * Get every player on a team, including players without a jersey number.
 *
 * @param int $team_id Team post ID.
 * @return array<int, \WP_Post>
 */
function get_team_roster_player_posts( $team_id ) {
	$team_id    = absint( $team_id );
	$player_ids = get_team_member_player_ids( $team_id, array( 'publish', 'draft', 'pending', 'future', 'private' ) );
	$players    = $player_ids
		? get_posts(
			array(
				'post_type'      => 'lf_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'post__in'       => $player_ids,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		)
		: array();

	usort(
		$players,
		static function( $first, $second ) use ( $team_id ) {
			$first_detail  = get_player_team_detail( $first->ID, $team_id );
			$second_detail = get_player_team_detail( $second->ID, $team_id );
			$first_has     = '' !== $first_detail['jersey_number'];
			$second_has    = '' !== $second_detail['jersey_number'];

			if ( $first_has !== $second_has ) {
				return $first_has ? -1 : 1;
			}

			if ( $first_has && (int) $first_detail['jersey_number'] !== (int) $second_detail['jersey_number'] ) {
				return (int) $first_detail['jersey_number'] <=> (int) $second_detail['jersey_number'];
			}

			return strcasecmp( $first->post_title, $second->post_title );
		}
	);

	return $players;
}

/**
 * Get all settings merged with defaults.
 *
 * @return array<string, mixed>
 */
function get_settings() {
	$settings = get_option( 'leagueflow_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args( $settings, defaults() );
}

/**
 * Get a single setting.
 *
 * @param string $key Setting key.
 * @param mixed  $fallback Fallback.
 * @return mixed
 */
function get_setting( $key, $fallback = null ) {
	$settings = get_settings();

	if ( array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}

	return $fallback;
}

/**
 * Whether captains can register teams from the portal.
 *
 * @return bool
 */
function is_captain_registration_enabled() {
	return 1 === bool_to_int( get_setting( 'captain_registration_open', 1 ) );
}

/**
 * Whether players can create profiles and request teams from the portal.
 *
 * @return bool
 */
function is_player_registration_enabled() {
	return 1 === bool_to_int( get_setting( 'player_registration_open', 0 ) );
}

/**
 * Resolve the inbox used for registration and placement notifications.
 *
 * @return string
 */
function get_registration_email() {
	$email = sanitize_email( (string) get_setting( 'registration_email', '' ) );

	if ( ! is_email( $email ) ) {
		$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
	}

	return is_email( $email ) ? $email : '';
}

/**
 * Whether an email may create a new captain or player registration.
 *
 * Existing linked profiles are checked separately and remain grandfathered.
 *
 * @param string $email Email address.
 * @return bool
 */
function is_registration_email_allowed( $email ) {
	$email   = strtolower( sanitize_email( $email ) );
	$domain  = is_email( $email ) ? substr( strrchr( $email, '@' ), 1 ) : '';
	$allowed = 'unbc.ca' === $domain || ( strlen( $domain ) > 8 && '.unbc.ca' === substr( $domain, -8 ) );

	/**
	 * Filter whether an email may self-register for LeagueFlow.
	 *
	 * @param bool   $allowed Whether the email is allowed.
	 * @param string $email   Sanitized lowercase email.
	 */
	return (bool) apply_filters( 'leagueflow_registration_email_allowed', $allowed, $email );
}

/**
 * Assign a player to a team and keep sport/level preferences synchronized.
 *
 * @param int                  $player_id Player ID.
 * @param int                  $team_id Team ID.
 * @param array<string, mixed> $details Optional team-specific roster fields.
 * @return bool
 */
function assign_player_to_team( $player_id, $team_id, $details = array() ) {
	$player = get_post( absint( $player_id ) );
	$team   = get_post( absint( $team_id ) );

	if ( ! $player instanceof \WP_Post || 'lf_player' !== $player->post_type || ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type ) {
		return false;
	}

	add_player_team_id( $player->ID, $team->ID );
	set_player_team_detail( $player->ID, $team->ID, is_array( $details ) ? $details : array() );

	$sport_id   = get_post_primary_term_id( $team->ID, 'lf_sport' );
	$sport_slug = get_post_primary_term_slug( $team->ID, 'lf_sport' );
	$level_id   = get_post_primary_term_id( $team->ID, 'lf_league_level' );

	if ( $sport_id ) {
		wp_set_object_terms( $player->ID, array( $sport_id ), 'lf_sport', true );
	}

	if ( $level_id ) {
		wp_set_object_terms( $player->ID, array( $level_id ), 'lf_league_level', true );
	}

	if ( $sport_slug && $level_id ) {
		$mapping = get_post_meta( $player->ID, 'lf_player_sport_levels', true );
		$mapping = is_array( $mapping ) ? $mapping : array();
		$mapping[ $sport_slug ] = $level_id;
		update_post_meta( $player->ID, 'lf_player_sport_levels', $mapping );
	}

	return true;
}

/**
 * Backfill team-specific roster details once while preserving legacy fields.
 *
 * @return void
 */
function ensure_player_team_details_migration() {
	$migration_version = 2;

	if ( (int) get_option( 'leagueflow_player_team_details_migration_complete' ) >= $migration_version ) {
		return;
	}

	$managed_teams_by_user = array();
	$team_ids              = get_posts(
		array(
			'post_type'      => 'lf_team',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $team_ids as $team_id ) {
		foreach ( get_team_manager_user_ids( $team_id ) as $manager_user_id ) {
			$managed_teams_by_user[ $manager_user_id ][] = absint( $team_id );
		}
	}

	$player_ids = get_posts(
		array(
			'post_type'      => 'lf_player',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $player_ids as $player_id ) {
		$player_id = absint( $player_id );
		$user_id   = absint( get_post_meta( $player_id, 'lf_user_id', true ) );
		$team_ids  = array_values( array_unique( array_merge( get_player_team_ids( $player_id ), $managed_teams_by_user[ $user_id ] ?? array() ) ) );

		if ( empty( $team_ids ) ) {
			continue;
		}

		$saved          = sanitize_player_team_details( get_post_meta( $player_id, 'lf_player_team_details', true ) );
		$primary        = absint( get_post_meta( $player_id, 'lf_team_id', true ) );
		$primary        = in_array( $primary, $team_ids, true ) ? $primary : absint( $team_ids[0] );
		$legacy_captain = (bool) get_post_meta( $player_id, 'lf_is_captain', true );
		$legacy_jersey  = get_post_meta( $player_id, 'lf_jersey_number', true );
		$legacy_position = get_post_meta( $player_id, 'lf_position', true );
		$details        = array();

		$team_ids = array_values( array_unique( array_merge( array( $primary ), $team_ids ) ) );
		update_post_meta( $player_id, 'lf_team_ids', $team_ids );
		update_post_meta( $player_id, 'lf_team_id', $primary );

		foreach ( $team_ids as $team_id ) {
			$is_primary = $team_id === $primary;
			$detail     = isset( $saved[ $team_id ] )
				? $saved[ $team_id ]
				: sanitize_player_team_detail(
					array(
						'is_captain'    => $is_primary && $legacy_captain,
						'jersey_number' => $is_primary ? $legacy_jersey : '',
						'position'      => $is_primary ? $legacy_position : '',
					)
				);

			if ( $user_id && in_array( $user_id, get_team_manager_user_ids( $team_id ), true ) ) {
				$detail['is_captain'] = 1;
			}

			$details[ $team_id ] = sanitize_player_team_detail( $detail );
		}

		update_post_meta( $player_id, 'lf_player_team_details', $details );

		foreach ( $team_ids as $team_id ) {
			assign_player_to_team( $player_id, $team_id, $details[ $team_id ] );
		}

		sync_player_legacy_team_meta( $player_id );
	}

	update_option( 'leagueflow_player_team_details_migration_complete', $migration_version );
}

/**
 * Get the level saved on a request, falling back to its selected team.
 *
 * @param int $request_id Join request ID.
 * @return int
 */
function get_join_request_level_id( $request_id ) {
	$level_id = absint( get_post_meta( absint( $request_id ), 'lf_league_level_id', true ) );

	if ( $level_id ) {
		return $level_id;
	}

	$team_id = absint( get_post_meta( absint( $request_id ), 'lf_team_id', true ) );

	return $team_id ? get_post_primary_term_id( $team_id, 'lf_league_level' ) : 0;
}

/**
 * Resolve team manager email recipients.
 *
 * @param int $team_id Team ID.
 * @return array<int, string>
 */
function get_team_manager_emails( $team_id ) {
	$emails = array();

	foreach ( get_team_manager_user_ids( absint( $team_id ) ) as $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User && is_email( $user->user_email ) ) {
			$emails[] = strtolower( sanitize_email( $user->user_email ) );
		}
	}

	return array_values( array_unique( $emails ) );
}

/**
 * Send a registration email without making the underlying workflow depend on mail.
 *
 * @param string|array<int, string> $recipients Email recipient or recipients.
 * @param string                    $subject Subject.
 * @param string                    $message Plain-text message.
 * @return bool
 */
function send_registration_email( $recipients, $subject, $message ) {
	$recipients = is_array( $recipients ) ? $recipients : array( $recipients );
	$recipients = array_values(
		array_unique(
			array_filter(
				array_map( 'sanitize_email', $recipients ),
				'is_email'
			)
		)
	);

	if ( empty( $recipients ) ) {
		error_log( 'LeagueFlow registration email failed: no valid recipients.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		set_transient( 'leagueflow_registration_mail_warning', 1, DAY_IN_SECONDS );
		return false;
	}

	$mail_exception = false;

	try {
		$sent = wp_mail(
			$recipients,
			sanitize_text_field( $subject ),
			wp_strip_all_tags( $message )
		);
	} catch ( \Throwable $exception ) {
		$sent           = false;
		$mail_exception = true;
		error_log( 'LeagueFlow registration email exception: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	if ( ! $sent ) {
		if ( ! $mail_exception ) {
			error_log( 'LeagueFlow registration email failed: ' . implode( ', ', $recipients ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		set_transient( 'leagueflow_registration_mail_warning', 1, DAY_IN_SECONDS );
	}

	return (bool) $sent;
}

/**
 * Normalize checkbox values.
 *
 * @param mixed $value Value.
 * @return int
 */
function bool_to_int( $value ) {
	return empty( $value ) ? 0 : 1;
}

/**
 * Resolve a post reference from ID or slug.
 *
 * @param mixed  $value Reference value.
 * @param string $post_type Post type.
 * @return int
 */
function resolve_post_id( $value, $post_type ) {
	if ( empty( $value ) ) {
		return 0;
	}

	if ( is_numeric( $value ) ) {
		$post = get_post( absint( $value ) );
		return ( $post && $post_type === $post->post_type ) ? (int) $post->ID : 0;
	}

	$post = get_page_by_path( sanitize_title( wp_unslash( (string) $value ) ), OBJECT, $post_type );

	if ( $post instanceof \WP_Post ) {
		return (int) $post->ID;
	}

	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			's'              => sanitize_text_field( wp_unslash( (string) $value ) ),
			'posts_per_page' => 1,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		)
	);

	if ( empty( $posts ) ) {
		return 0;
	}

	foreach ( $posts as $candidate ) {
		if ( 0 === strcasecmp( $candidate->post_title, sanitize_text_field( wp_unslash( (string) $value ) ) ) ) {
			return (int) $candidate->ID;
		}
	}

	return 0;
}

/**
 * Option storing optional scoring overrides per sport and per competition.
 */
const RULE_OVERRIDES_OPTION = 'leagueflow_rule_overrides';

/**
 * Scoring rules that a sport or competition may override.
 *
 * @return array<int, string>
 */
function rule_keys() {
	return array( 'points_win', 'points_draw', 'points_loss', 'forfeit_score_winner', 'tie_breakers' );
}

/**
 * Allowed tie-breaker keys.
 *
 * @return array<int, string>
 */
function tie_breaker_keys() {
	return array( 'goal_difference', 'goals_for', 'wins', 'goals_against', 'name' );
}

/**
 * Clean one override: keep only known keys with valid values.
 *
 * @param mixed $values Raw values.
 * @return array<string, mixed>
 */
function sanitize_rule_override( $values ) {
	$clean = array();

	if ( ! is_array( $values ) ) {
		return $clean;
	}

	foreach ( array( 'points_win', 'points_draw', 'points_loss', 'forfeit_score_winner' ) as $key ) {
		$value = isset( $values[ $key ] ) && is_string( $values[ $key ] ) ? trim( $values[ $key ] ) : ( $values[ $key ] ?? null );
		if ( Match_Status::is_valid_score( $value ) ) {
			$clean[ $key ] = (int) $value;
		}
	}

	if ( isset( $values['tie_breakers'] ) && is_array( $values['tie_breakers'] ) ) {
		$order = array();

		foreach ( $values['tie_breakers'] as $value ) {
			$value = is_string( $value ) ? sanitize_key( $value ) : '';

			if ( in_array( $value, tie_breaker_keys(), true ) && ! in_array( $value, $order, true ) ) {
				$order[] = $value;
			}
		}

		if ( $order ) {
			$clean['tie_breakers'] = array_slice( $order, 0, 4 );
		}
	}

	return $clean;
}

/**
 * Stored overrides: array( 'sport' => slug => values, 'competition' => id => values ).
 *
 * @return array<string, array<string|int, array<string, mixed>>>
 */
function get_rule_overrides() {
	$stored = get_option( RULE_OVERRIDES_OPTION, array() );
	$clean  = array( 'sport' => array(), 'competition' => array() );

	if ( ! is_array( $stored ) ) {
		return $clean;
	}

	foreach ( (array) ( $stored['sport'] ?? array() ) as $slug => $values ) {
		$values = sanitize_rule_override( $values );

		if ( $values && '' !== sanitize_key( (string) $slug ) ) {
			$clean['sport'][ sanitize_key( (string) $slug ) ] = $values;
		}
	}

	foreach ( (array) ( $stored['competition'] ?? array() ) as $term_id => $values ) {
		$values = sanitize_rule_override( $values );

		if ( $values && absint( $term_id ) ) {
			$clean['competition'][ absint( $term_id ) ] = $values;
		}
	}

	return $clean;
}

/**
 * Save or remove one override. An empty value list removes it, so the scope
 * inherits again.
 *
 * @param string $scope sport or competition.
 * @param mixed  $key Sport slug or competition term ID.
 * @param mixed  $values Values (empty = inherit).
 * @return void
 */
function set_rule_override( $scope, $key, $values ) {
	$overrides = get_rule_overrides();
	$key       = 'competition' === $scope ? absint( $key ) : sanitize_key( (string) $key );
	$values    = sanitize_rule_override( $values );

	if ( ! in_array( $scope, array( 'sport', 'competition' ), true ) || ! $key ) {
		return;
	}

	if ( $values ) {
		$overrides[ $scope ][ $key ] = $values;
	} else {
		unset( $overrides[ $scope ][ $key ] );
	}

	if ( ! $overrides['sport'] && ! $overrides['competition'] ) {
		delete_option( RULE_OVERRIDES_OPTION );
		return;
	}

	update_option( RULE_OVERRIDES_OPTION, $overrides, false );
}

/**
 * Effective scoring rules for a sport and competition.
 *
 * Global settings apply until a sport override sets a value; a competition
 * override (only for a competition of that sport) then wins for the values it
 * sets. With no overrides the result is exactly the global settings.
 *
 * @param string $sport_slug Sport ('' = none).
 * @param int    $competition_id Competition (0 = none).
 * @return array{values: array<string, mixed>, source: array<string, string>}
 */
function get_effective_rules( $sport_slug = '', $competition_id = 0 ) {
	$sport_slug     = sanitize_key( (string) $sport_slug );
	$competition_id = absint( $competition_id );
	$values         = array(
		'points_win'   => (int) get_setting( 'points_win', 3 ),
		'points_draw'  => (int) get_setting( 'points_draw', 1 ),
		'points_loss'  => (int) get_setting( 'points_loss', 0 ),
		'forfeit_score_winner' => (int) get_setting( 'forfeit_score_winner', 3 ),
		'tie_breakers' => (array) get_setting( 'tie_breakers', defaults()['tie_breakers'] ),
	);
	$source         = array_fill_keys( array_keys( $values ), 'global' );
	$overrides      = get_rule_overrides();

	if ( $competition_id && '' === $sport_slug ) {
		$sport_slug = get_term_sport_slug( $competition_id );
	}

	foreach ( $sport_slug ? (array) ( $overrides['sport'][ $sport_slug ] ?? array() ) : array() as $key => $value ) {
		$values[ $key ] = $value;
		$source[ $key ] = 'sport';
	}

	if ( $competition_id && ( '' === get_term_sport_slug( $competition_id ) || get_term_sport_slug( $competition_id ) === $sport_slug ) ) {
		foreach ( (array) ( $overrides['competition'][ $competition_id ] ?? array() ) as $key => $value ) {
			$values[ $key ] = $value;
			$source[ $key ] = 'competition';
		}
	}

	return array(
		'values' => $values,
		'source' => $source,
	);
}

/**
 * Option storing one current season per sport (sport slug => season term ID).
 */
const CURRENT_SEASONS_OPTION = 'leagueflow_current_seasons';

/**
 * Sport slug stored on a season or competition term ('' when none).
 *
 * @param int $term_id Term ID.
 * @return string
 */
function get_term_sport_slug( $term_id ) {
	return sanitize_key( (string) get_term_meta( absint( $term_id ), 'lf_sport_slug', true ) );
}

/**
 * Per-sport current seasons, keeping only entries whose season still exists
 * and still belongs to that sport.
 *
 * @return array<string, int>
 */
function get_current_season_map() {
	$stored = get_option( CURRENT_SEASONS_OPTION, array() );
	$map    = array();

	foreach ( is_array( $stored ) ? $stored : array() as $sport_slug => $term_id ) {
		$sport_slug = sanitize_key( (string) $sport_slug );
		$term       = get_term( absint( $term_id ), 'lf_season' );

		if ( '' !== $sport_slug && $term instanceof \WP_Term && get_term_sport_slug( $term->term_id ) === $sport_slug ) {
			$map[ $sport_slug ] = (int) $term->term_id;
		}
	}

	return $map;
}

/**
 * Seasons carrying the legacy site-wide "current" flag (normally zero or one).
 *
 * @return array<int, int>
 */
function get_legacy_current_season_ids() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'lf_season',
			'hide_empty' => false,
			'fields'     => 'ids',
			'orderby'    => 'term_id',
			'order'      => 'ASC',
			'meta_query' => array(
				array(
					'key'   => 'lf_is_current',
					'value' => '1',
				),
			),
		)
	);

	return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
}

/**
 * Forget cached current-season lookups (after any change).
 *
 * @return void
 */
function flush_current_season_cache() {
	$GLOBALS['leagueflow_current_season_cache'] = array();
}

/**
 * Get the current season, optionally for one sport.
 *
 * - With a sport that has its own current season, that season.
 * - Otherwise the legacy site-wide flagged season, exactly as before, as long
 *   as no sport has its own current season yet, or the flagged season has no
 *   sport or belongs to the requested sport. Once per-sport seasons are in
 *   use, one sport's legacy flag no longer leaks into another sport.
 * - Otherwise 0 (all seasons).
 *
 * @param string $sport_slug Sport slug ('' = no sport context).
 * @return int Season term ID, or 0 when none applies.
 */
function get_current_season_id( $sport_slug = '' ) {
	$sport_slug = sanitize_key( (string) $sport_slug );
	$cache      = isset( $GLOBALS['leagueflow_current_season_cache'] ) && is_array( $GLOBALS['leagueflow_current_season_cache'] ) ? $GLOBALS['leagueflow_current_season_cache'] : array();

	if ( isset( $cache[ $sport_slug ] ) ) {
		return $cache[ $sport_slug ];
	}

	$map    = get_current_season_map();
	$result = 0;

	if ( '' !== $sport_slug && isset( $map[ $sport_slug ] ) ) {
		$result = $map[ $sport_slug ];
	} else {
		$legacy = get_legacy_current_season_ids();
		$legacy = $legacy ? $legacy[0] : 0;

		if ( $legacy ) {
			$legacy_sport = get_term_sport_slug( $legacy );
			$result       = ( '' === $sport_slug || ! $map || '' === $legacy_sport || $legacy_sport === $sport_slug ) ? $legacy : 0;
		}
	}

	$cache[ $sport_slug ]                       = (int) $result;
	$GLOBALS['leagueflow_current_season_cache'] = $cache;

	return (int) $result;
}

/**
 * Make a season current.
 *
 * A season that belongs to a sport becomes that sport's current season only;
 * other sports keep theirs. A legacy site-wide flag on a season of the same
 * sport is cleared because the sport's own value now supersedes it. A season
 * without a sport keeps the legacy behaviour (one site-wide flagged season).
 * Passing 0 clears every current season (legacy behaviour).
 *
 * @param int $term_id Season term ID, or 0 to clear all.
 * @return bool Whether a season was made current.
 */
function set_current_season( $term_id ) {
	$term_id = absint( $term_id );

	if ( ! $term_id ) {
		foreach ( get_legacy_current_season_ids() as $existing_id ) {
			delete_term_meta( $existing_id, 'lf_is_current' );
		}

		delete_option( CURRENT_SEASONS_OPTION );
		flush_current_season_cache();
		return false;
	}

	$term = get_term( $term_id, 'lf_season' );

	if ( ! $term instanceof \WP_Term ) {
		return false;
	}

	$sport_slug = get_term_sport_slug( $term_id );

	if ( '' !== $sport_slug ) {
		$map                = get_current_season_map();
		$map[ $sport_slug ] = $term_id;
		update_option( CURRENT_SEASONS_OPTION, $map, false );

		foreach ( get_legacy_current_season_ids() as $existing_id ) {
			if ( $existing_id === $term_id || get_term_sport_slug( $existing_id ) === $sport_slug ) {
				delete_term_meta( $existing_id, 'lf_is_current' );
			}
		}
	} else {
		foreach ( get_legacy_current_season_ids() as $existing_id ) {
			if ( $existing_id !== $term_id && '' === get_term_sport_slug( $existing_id ) ) {
				delete_term_meta( $existing_id, 'lf_is_current' );
			}
		}

		update_term_meta( $term_id, 'lf_is_current', 1 );
	}

	flush_current_season_cache();

	return true;
}

/**
 * Stop a season being current (for its sport and as the legacy flag).
 *
 * @param int $term_id Season term ID.
 * @return void
 */
function clear_current_season( $term_id ) {
	$term_id = absint( $term_id );
	$stored  = get_option( CURRENT_SEASONS_OPTION, array() );
	$stored  = is_array( $stored ) ? $stored : array();
	$kept    = array_filter(
		$stored,
		static function ( $value ) use ( $term_id ) {
			return absint( $value ) !== $term_id;
		}
	);

	if ( count( $kept ) !== count( $stored ) ) {
		update_option( CURRENT_SEASONS_OPTION, $kept, false );
	}

	delete_term_meta( $term_id, 'lf_is_current' );
	flush_current_season_cache();
}

/**
 * Whether a season is current for its own sport (or via the legacy flag).
 *
 * @param int $term_id Season term ID.
 * @return bool
 */
function is_current_season( $term_id ) {
	$term_id    = absint( $term_id );
	$sport_slug = get_term_sport_slug( $term_id );

	return $term_id && $term_id === get_current_season_id( $sport_slug );
}

/**
 * Resolve a season reference, falling back to the current season.
 *
 * - "all" always means every season (0), so an explicit All choice is never
 *   turned into the current season.
 * - "current" means the current season (for the sport when one is given).
 * - An empty value keeps the long-standing block/shortcode default: the
 *   current season when one applies, otherwise all seasons. Sites that never
 *   designate a current season see no change.
 * - An ID or slug is honoured.
 *
 * @param mixed  $value Season reference (ID, slug, "current", "all", or empty).
 * @param string $sport_slug Sport context for the current season.
 * @return int Season term ID, or 0.
 */
function resolve_season_context( $value, $sport_slug = '' ) {
	$keyword = is_string( $value ) ? strtolower( trim( $value ) ) : '';

	if ( 'all' === $keyword ) {
		return 0;
	}

	if ( 'current' === $keyword || '' === $value || null === $value || 0 === $value || '0' === $value ) {
		return get_current_season_id( $sport_slug );
	}

	return resolve_term_id( $value, 'lf_season' );
}

/**
 * Read an admin season choice: "" or "all" = every season, "current" = the
 * sport's current season, otherwise one season ID/slug.
 *
 * @param mixed  $raw Raw request value.
 * @param string $sport_slug Sport context.
 * @return array{mode: string, id: int} mode all|current|selected and the season ID used (0 = all).
 */
function resolve_admin_season_choice( $raw, $sport_slug = '' ) {
	$raw = is_string( $raw ) ? sanitize_text_field( $raw ) : $raw;

	if ( 'current' === $raw ) {
		return array( 'mode' => 'current', 'id' => get_current_season_id( $sport_slug ) );
	}

	$id = ( '' === $raw || 'all' === $raw || null === $raw ) ? 0 : resolve_term_id( $raw, 'lf_season' );

	return array( 'mode' => $id ? 'selected' : 'all', 'id' => $id );
}

/**
 * Resolve a taxonomy term reference from ID or slug.
 *
 * @param mixed  $value Reference.
 * @param string $taxonomy Taxonomy.
 * @return int
 */
function resolve_term_id( $value, $taxonomy ) {
	if ( empty( $value ) ) {
		return 0;
	}

	if ( is_numeric( $value ) ) {
		$term = get_term( absint( $value ), $taxonomy );
		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	$sanitized = sanitize_title( wp_unslash( (string) $value ) );
	$term      = get_term_by( 'slug', $sanitized, $taxonomy );

	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term->term_id;
	}

	$term = get_term_by( 'name', sanitize_text_field( wp_unslash( (string) $value ) ), $taxonomy );

	return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
}

/**
 * Format a saved match date/time.
 *
 * @param string $datetime Datetime string.
 * @return string
 */
function format_match_datetime( $datetime ) {
	if ( empty( $datetime ) ) {
		return '';
	}

	// Saved match values are site wall-clock times, not PHP's default UTC.
	try {
		$date = new \DateTimeImmutable( $datetime, wp_timezone() );
	} catch ( \Exception $error ) {
		return '';
	}
	$errors = \DateTimeImmutable::getLastErrors();
	if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
		return '';
	}

	return wp_date( (string) get_setting( 'date_time_format', 'F j, Y g:i a' ), $date->getTimestamp(), wp_timezone() );
}

/**
 * Build a fixture label from team selections.
 *
 * @param int    $home_team_id Home team ID.
 * @param int    $away_team_id Away team ID.
 * @param string $datetime Match datetime.
 * @return string
 */
function build_match_title( $home_team_id, $away_team_id, $datetime = '' ) {
	$home = $home_team_id ? get_the_title( $home_team_id ) : __( 'Home team', 'leagueflow' );
	$away = $away_team_id ? get_the_title( $away_team_id ) : __( 'Away team', 'leagueflow' );

	$title = sprintf(
		/* translators: 1: home team 2: away team */
		__( '%1$s vs %2$s', 'leagueflow' ),
		$home,
		$away
	);

	if ( ! empty( $datetime ) ) {
		$title .= ' - ' . format_match_datetime( $datetime );
	}

	return $title;
}

/**
 * Get a post thumbnail if the post has one.
 *
 * @param int    $post_id Post ID.
 * @param string $size Image size.
 * @param string $class CSS class.
 * @return string
 */
function get_post_image( $post_id, $size = 'thumbnail', $class = 'leagueflow-image' ) {
	if ( ! has_post_thumbnail( $post_id ) ) {
		return '';
	}

	return get_the_post_thumbnail(
		$post_id,
		$size,
		array(
			'class'   => $class,
			'loading' => 'lazy',
		)
	);
}

/**
 * Get select options for a post type.
 *
 * @param string $post_type Post type.
 * @return array<int, string>
 */
function get_post_options( $post_type ) {
	$options = array();
	$posts   = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	foreach ( $posts as $post ) {
		$options[ $post->ID ] = $post->post_title;
	}

	return $options;
}

/**
 * Get select options for a taxonomy.
 *
 * @param string $taxonomy Taxonomy.
 * @return array<int, string>
 */
function get_term_options( $taxonomy ) {
	$options = array();
	$terms   = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return $options;
	}

	foreach ( $terms as $term ) {
		$options[ $term->term_id ] = $term->name;
	}

	return $options;
}

/**
 * Get the first term ID for a post and taxonomy.
 *
 * @param int    $post_id Post ID.
 * @param string $taxonomy Taxonomy.
 * @return int
 */
function get_post_primary_term_id( $post_id, $taxonomy ) {
	$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return 0;
	}

	return (int) $terms[0];
}

/**
 * Get the first term slug for a post and taxonomy.
 *
 * @param int    $post_id Post ID.
 * @param string $taxonomy Taxonomy.
 * @return string
 */
function get_post_primary_term_slug( $post_id, $taxonomy ) {
	$terms = wp_get_post_terms( $post_id, $taxonomy );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	return (string) $terms[0]->slug;
}

/**
 * Get a post's league level slug.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_post_league_level_slug( $post_id ) {
	$slug = get_post_primary_term_slug( $post_id, 'lf_league_level' );

	return $slug ? $slug : 'recreational';
}

/**
 * Get a post's league level label.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_post_league_level_label( $post_id ) {
	$term_id = get_post_primary_term_id( $post_id, 'lf_league_level' );

	if ( $term_id ) {
		$term = get_term( $term_id, 'lf_league_level' );

		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}
	}

	$defaults = league_level_definitions();

	return $defaults['recreational'];
}

/**
 * Get taxonomy terms whose sport meta matches a slug.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $sport_slug Sport slug.
 * @return array<int, int>
 */
function get_term_ids_by_sport( $taxonomy, $sport_slug ) {
	$args = array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	);

	if ( ! empty( $sport_slug ) ) {
		$args['meta_query'] = array(
			array(
				'key'   => 'lf_sport_slug',
				'value' => sanitize_key( $sport_slug ),
			),
		);
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	return array_map( 'absint', $terms );
}

/**
 * Read a match score as an integer.
 *
 * @param mixed $value Score value.
 * @return int
 */
function score_to_int( $value ) {
	return is_numeric( $value ) ? (int) $value : 0;
}

/**
 * Check whether a match score exists.
 *
 * @param mixed $value Score.
 * @return bool
 */
function has_score( $value ) {
	return '' !== (string) $value && null !== $value;
}

/**
 * Query statuses for public league data; staff can preview records they may read.
 *
 * @return array<string>
 */
function frontend_post_statuses() {
	return current_user_can( 'edit_posts' )
		? array( 'publish', 'future', 'draft', 'pending', 'private' )
		: array( 'publish' );
}

/**
 * Check a league record and, for fixtures, its referenced teams before rendering.
 *
 * @param int|\WP_Post $post Record.
 * @return bool
 */
function can_view_league_post( $post ) {
	$post = get_post( $post );
	if ( ! $post instanceof \WP_Post ) {
		return false;
	}
	$staff_can_read = current_user_can( 'edit_posts' ) && current_user_can( 'read_post', $post->ID );
	if ( ! $staff_can_read && ( 'publish' !== $post->post_status || post_password_required( $post ) ) ) {
		return false;
	}
	if ( 'lf_match' === $post->post_type ) {
		foreach ( array( 'lf_home_team_id', 'lf_away_team_id' ) as $key ) {
			$team_id = (int) get_post_meta( $post->ID, $key, true );
			if ( $team_id && ( 'lf_team' !== get_post_type( $team_id ) || ! can_view_league_post( $team_id ) ) ) {
				return false;
			}
		}
	}
	return true;
}

/** Team profile destination; themes can disable links when profiles are unavailable. */
function team_profile_url( $team_id ) {
	return (string) apply_filters( 'leagueflow_team_profile_url', get_permalink( $team_id ), $team_id );
}

/**
 * Name for a site-scoped database lock (MySQL/MariaDB limit: 64 characters).
 *
 * @param string $scope Short scope, such as "req", "fix" or "map".
 * @param string $key Key inside the scope.
 * @return string
 */
function db_lock_name( $scope, $key ) {
	global $wpdb;

	return substr( 'lf_' . sanitize_key( $scope ) . '_' . md5( $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $key ), 0, 64 );
}

/**
 * Take a named database lock. Fails closed: only an explicit "1" from
 * GET_LOCK counts as success. A timeout ("0"), an error or an unavailable
 * lock service (NULL) all return false, so callers change nothing.
 *
 * @param string $name Lock name from db_lock_name().
 * @param int    $timeout Seconds to wait.
 * @return bool
 */
function acquire_db_lock( $name, $timeout ) {
	global $wpdb;

	$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, (int) $timeout ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return null !== $result && '1' === (string) $result;
}

/**
 * Release a named database lock taken by acquire_db_lock().
 *
 * @param string $name Lock name.
 * @return void
 */
function release_db_lock( $name ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Whether a post really holds a term, read back from the database.
 *
 * wp_set_object_terms() does not check its relationship INSERT, so a refused
 * write can still return term IDs. Callers that must report what was saved
 * verify with this instead of trusting that return value.
 *
 * @param int    $object_id Post ID.
 * @param int    $term_id Term ID.
 * @param string $taxonomy Taxonomy.
 * @return bool
 */
function object_term_saved( $object_id, $term_id, $taxonomy ) {
	$object_id = absint( $object_id );
	clean_object_term_cache( $object_id, (string) get_post_type( $object_id ) );
	$ids = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids', 'update_term_meta_cache' => false ) );

	return ! is_wp_error( $ids ) && in_array( (int) $term_id, array_map( 'intval', (array) $ids ), true );
}

/**
 * Accent colour for a sport: the same colours the calendar uses.
 *
 * @param string $sport_slug Sport slug.
 * @return string
 */
function sport_accent_color( $sport_slug ) {
	$colors = apply_filters(
		'leagueflow_calendar_sport_colors',
		array(
			'soccer'            => '#2e7d4f',
			'basketball'        => '#c2571f',
			'volleyball'        => '#b08c2e',
			'hockey'            => '#2b5d8a',
			'baseball'          => '#8a3b3b',
			'american-football' => '#6b4f2e',
			'cricket'           => '#3f7a7a',
			'rugby'             => '#7a3b5e',
		)
	);
	$color = isset( $colors[ $sport_slug ] ) ? (string) $colors[ $sport_slug ] : '';
	return preg_match( '/^#[0-9a-f]{3,8}$/i', $color ) ? $color : '';
}

/**
 * One match as the shared event card, the same design the calendar uses:
 * accent pill, sport / level / round / date line, teams with the score
 * between them, then the result note and venue.
 *
 * @param array<string, mixed> $match Mapped match item (Renderer::map_match_item keys).
 * @param array<string, mixed> $args {
 *     @type bool   $link      Make the whole card a link to the match page. Default true.
 *     @type bool   $show_date Show the date as well as the time. Default true.
 *     @type string $after     Trusted HTML appended inside a card that is not a link (e.g. a form).
 * }
 * @return string
 */
function render_match_card( $match, $args = array() ) {
	$args      = array_merge( array( 'link' => true, 'show_date' => true, 'after' => '', 'badges' => array() ), $args );
	$permalink = (string) ( $match['permalink'] ?? '' );
	$is_link   = $args['link'] && '' !== $permalink && '' === $args['after'];
	$status    = (string) ( $match['status'] ?? '' );
	$raw       = (string) ( $match['datetime_raw'] ?? '' );
	$when      = '';
	if ( '' !== $raw ) {
		try {
			$date = new \DateTimeImmutable( $raw, wp_timezone() );
			$time = wp_date( (string) get_option( 'time_format', 'g:i a' ), $date->getTimestamp(), wp_timezone() );
			/* translators: date format for match cards, e.g. Fri, Oct 23 */
			$when = $args['show_date'] ? wp_date( __( 'D, M j', 'leagueflow' ), $date->getTimestamp(), wp_timezone() ) . ' · ' . $time : $time;
		} catch ( \Exception $error ) {
			$when = (string) ( $match['datetime'] ?? '' );
		}
	}
	$score = ! empty( $match['scoreline'] ) ? (string) $match['scoreline'] : ( ! empty( $match['result_label'] ) ? '—' : __( 'vs', 'leagueflow' ) );
	$color = sport_accent_color( (string) ( $match['sport_slug'] ?? '' ) );
	$tag   = $is_link ? 'a' : 'article';

	$html  = '<' . $tag . ' class="leagueflow-calendar__event leagueflow-match-card-row' . ( $is_link ? ' is-link' : '' ) . '"';
	$html .= $is_link ? ' href="' . esc_url( $permalink ) . '"' : '';
	$html .= $color ? ' style="--lf-cal-accent: ' . esc_attr( $color ) . ';"' : '';
	$html .= '>';
	$html .= '<div class="leagueflow-calendar__event-top">';
	if ( ! empty( $match['sport_label'] ) ) {
		$html .= '<span class="leagueflow-calendar__event-sport"><span class="leagueflow-calendar__event-dot"></span>' . esc_html( (string) $match['sport_label'] ) . '</span>';
	}
	foreach ( array( 'league_level_label', 'round_label' ) as $key ) {
		if ( ! empty( $match[ $key ] ) ) {
			$html .= '<span class="leagueflow-calendar__event-type">' . esc_html( (string) $match[ $key ] ) . '</span>';
		}
	}
	if ( '' !== $when ) {
		$html .= '<time class="leagueflow-calendar__event-time" datetime="' . esc_attr( $raw ) . '">' . esc_html( $when ) . '</time>';
	}
	if ( '' !== $status && 'scheduled' !== $status ) {
		$html .= '<span class="leagueflow-calendar__event-status is-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( (string) ( $match['status_label'] ?? $status ) ) . '</span>';
	}
	foreach ( (array) $args['badges'] as $badge ) {
		$html .= '<span class="leagueflow-calendar__event-status ' . esc_attr( sanitize_html_class( (string) ( $badge[1] ?? '' ) ) ) . '">' . esc_html( (string) ( $badge[0] ?? '' ) ) . '</span>';
	}
	$html .= '</div>';
	$html .= '<p class="leagueflow-calendar__event-teams"><span>' . esc_html( (string) ( $match['home_team'] ?? '' ) ) . '</span><strong class="leagueflow-calendar__event-score">' . esc_html( $score ) . '</strong><span>' . esc_html( (string) ( $match['away_team'] ?? '' ) ) . '</span></p>';
	if ( ! empty( $match['result_label'] ) ) {
		$html .= '<p class="leagueflow-calendar__event-result">' . esc_html( (string) $match['result_label'] ) . '</p>';
	}
	if ( ! empty( $match['venue'] ) ) {
		$html .= '<p class="leagueflow-calendar__event-meta">' . esc_html( (string) $match['venue'] ) . '</p>';
	}
	$html .= $is_link ? '' : (string) $args['after'];
	$html .= '</' . $tag . '>';
	return $html;
}
