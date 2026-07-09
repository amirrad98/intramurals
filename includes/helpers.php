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
		'team_slug'             => 'teams',
		'match_slug'            => 'matches',
		'competition_slug'      => 'competition',
		'season_slug'           => 'season',
		'show_logos'            => 1,
		'show_player_photos'    => 1,
		'date_time_format'      => 'F j, Y g:i a',
		'tie_breakers'          => array( 'goal_difference', 'goals_for', 'wins', 'name' ),
		'captain_registration_open' => 1,
		'player_registration_open' => 0,
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
	}
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
 * Replace a player's team memberships while keeping legacy primary-team meta.
 *
 * @param int        $player_id Player post ID.
 * @param array<int> $team_ids Team IDs.
 * @return void
 */
function set_player_team_ids( $player_id, $team_ids ) {
	$team_ids = sanitize_user_id_list( $team_ids );

	update_post_meta( absint( $player_id ), 'lf_team_ids', $team_ids );
	update_post_meta( absint( $player_id ), 'lf_team_id', empty( $team_ids ) ? 0 : absint( $team_ids[0] ) );
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

	$timestamp = strtotime( $datetime );

	if ( ! $timestamp ) {
		return '';
	}

	return wp_date( (string) get_setting( 'date_time_format', 'F j, Y g:i a' ), $timestamp );
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
