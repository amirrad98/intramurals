<?php
/**
 * Standings calculation.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Calculates standings from finished matches.
 */
class Standings_Service {

	/**
	 * Rules used by the calculation in progress (sort callback reads them).
	 *
	 * @var array<string, mixed>
	 */
	protected $rules = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Intentionally empty. Standings are calculated on demand.
	}

	/**
	 * Get standings rows.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_rows( $competition_id = 0, $season_id = 0, $sport_id = 0, $league_level_id = 0 ) {
		$sport_term  = $sport_id ? get_term( $sport_id, 'lf_sport' ) : null;
		$this->rules = get_effective_rules( $sport_term instanceof \WP_Term ? $sport_term->slug : '', $competition_id )['values'];
		$matches     = $this->get_finished_matches( $competition_id, $season_id, $sport_id, $league_level_id );
		$team_ids    = $this->get_context_team_ids( $competition_id, $season_id, $sport_id, $league_level_id );

		foreach ( $matches as $match ) {
			$team_ids[] = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
			$team_ids[] = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );
		}

		$team_ids = array_values( array_filter( array_unique( array_map( 'absint', $team_ids ) ) ) );

		$rows = array();

		foreach ( $team_ids as $team_id ) {
			if ( ! can_view_league_post( $team_id ) ) {
				continue;
			}
			$rows[ $team_id ] = array(
				'team_id'         => $team_id,
				'name'            => get_the_title( $team_id ),
				'short_name'      => get_post_meta( $team_id, 'lf_short_name', true ),
				'logo'            => get_post_image( $team_id, 'thumbnail', 'leagueflow-table__logo' ),
				'played'          => 0,
				'wins'            => 0,
				'draws'           => 0,
				'losses'          => 0,
				'goals_for'       => 0,
				'goals_against'   => 0,
				'goal_difference' => 0,
				'points_base'     => 0,
				'adjustment'      => 0,
				'adjustment_note' => '',
				'points'          => 0,
				'permalink'       => team_profile_url( $team_id ),
			);
		}

		foreach ( $matches as $match ) {
			$home_id = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
			$away_id = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );

			if ( ! $home_id || ! $away_id || $home_id === $away_id || empty( $rows[ $home_id ] ) || empty( $rows[ $away_id ] ) ) {
				continue;
			}

			// Sport-wide tables can include more than one competition. Award
			// points using each fixture's rules; use the selected table context
			// only for the tie-breaker order applied after aggregation.
			$match_sport = get_post_primary_term_id( $match->ID, 'lf_sport' );
			$match_sport = $match_sport ? get_term( $match_sport, 'lf_sport' ) : null;
			$match_cup   = $competition_id ? $competition_id : get_post_primary_term_id( $match->ID, 'lf_competition' );
			$match_rules = get_effective_rules( $match_sport instanceof \WP_Term ? $match_sport->slug : '', $match_cup )['values'];
			$outcome = sanitize_key( (string) get_post_meta( $match->ID, 'lf_outcome', true ) );

			if ( in_array( $outcome, array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true ) ) {
				$this->apply_forfeit( $rows, $home_id, $away_id, $outcome, $match_rules );
				continue;
			}

			$home_score = get_post_meta( $match->ID, 'lf_home_score', true );
			$away_score = get_post_meta( $match->ID, 'lf_away_score', true );

			if ( ! Match_Status::is_valid_score( $home_score ) || ! Match_Status::is_valid_score( $away_score ) ) {
				continue;
			}

			$home_score = score_to_int( $home_score );
			$away_score = score_to_int( $away_score );

			$rows[ $home_id ]['played']++;
			$rows[ $away_id ]['played']++;

			$rows[ $home_id ]['goals_for']     += $home_score;
			$rows[ $home_id ]['goals_against'] += $away_score;
			$rows[ $away_id ]['goals_for']     += $away_score;
			$rows[ $away_id ]['goals_against'] += $home_score;

			if ( $home_score > $away_score ) {
				$rows[ $home_id ]['wins']++;
				$rows[ $away_id ]['losses']++;
				$rows[ $home_id ]['points'] += (int) $match_rules['points_win'];
				$rows[ $away_id ]['points'] += (int) $match_rules['points_loss'];
			} elseif ( $away_score > $home_score ) {
				$rows[ $away_id ]['wins']++;
				$rows[ $home_id ]['losses']++;
				$rows[ $away_id ]['points'] += (int) $match_rules['points_win'];
				$rows[ $home_id ]['points'] += (int) $match_rules['points_loss'];
			} else {
				$rows[ $home_id ]['draws']++;
				$rows[ $away_id ]['draws']++;
				$rows[ $home_id ]['points'] += (int) $match_rules['points_draw'];
				$rows[ $away_id ]['points'] += (int) $match_rules['points_draw'];
			}
		}

		foreach ( $rows as $team_id => $row ) {
			$rows[ $team_id ]['goal_difference'] = $row['goals_for'] - $row['goals_against'];

			// Standings stay derived; a signed manual delta only ever adjusts
			// the computed total (deductions, bonuses) without replacing it.
			$adjustment                          = (int) get_post_meta( $team_id, 'lf_points_adjustment', true );
			$rows[ $team_id ]['points_base']     = $row['points'];
			$rows[ $team_id ]['adjustment']      = $adjustment;
			$rows[ $team_id ]['adjustment_note'] = (string) get_post_meta( $team_id, 'lf_adjustment_note', true );
			$rows[ $team_id ]['points']          = $row['points'] + $adjustment;
		}

		$rows = array_values( $rows );

		usort( $rows, array( $this, 'sort_rows' ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['position'] = $index + 1;
		}

		return $rows;
	}

	/**
	 * Explain why records in a staff member's selected context do not count.
	 *
	 * No dates are required: a result remains a result when its schedule is
	 * incomplete. Return only counts and a few editable/visible example IDs;
	 * callers can provide repair links without exposing private roster data.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function get_diagnostics( $competition_id = 0, $season_id = 0, $sport_id = 0, $league_level_id = 0 ) {
		$keys = array( 'counted', 'scored_not_finished', 'unfinished', 'incomplete', 'unpublished', 'knockout', 'invalid_teams' );
		$result = array(
			'counts'   => array_fill_keys( $keys, 0 ),
			'examples' => array_fill_keys( $keys, array() ),
		);

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $result;
		}

		$args = $this->build_match_query_args( $competition_id, $season_id, $sport_id, $league_level_id );
		$args['post_status'] = frontend_post_statuses();
		unset( $args['meta_query'] );

		foreach ( get_posts( $args ) as $match ) {
			// Editable malformed references should be diagnosable, but a match
			// the viewer cannot read must never contribute a count or example.
			if ( ! can_view_league_post( $match ) && ! current_user_can( 'edit_post', $match->ID ) ) {
				continue;
			}
			$reason = $this->get_exclusion_reason( $match );
			$key    = $reason ? $reason : 'counted';
			$result['counts'][ $key ]++;
			if ( count( $result['examples'][ $key ] ) < 5 ) {
				$result['examples'][ $key ][] = (int) $match->ID;
			}
		}

		return $result;
	}

	/**
	 * One eligibility rule shared by the table and staff diagnostics.
	 *
	 * @param \WP_Post $match Match post.
	 * @return string Empty when the result counts; otherwise a diagnostic key.
	 */
	protected function get_exclusion_reason( $match ) {
		if ( 'publish' !== $match->post_status ) {
			return 'unpublished';
		}
		if ( get_post_meta( $match->ID, 'lf_is_knockout', true ) ) {
			return 'knockout';
		}
		$home = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
		$away = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );
		if ( ! $home || ! $away || $home === $away || 'lf_team' !== get_post_type( $home ) || 'lf_team' !== get_post_type( $away ) || 'publish' !== get_post_status( $home ) || 'publish' !== get_post_status( $away ) ) {
			return 'invalid_teams';
		}
		$home_score = get_post_meta( $match->ID, 'lf_home_score', true );
		$away_score = get_post_meta( $match->ID, 'lf_away_score', true );
		$has_scores = Match_Status::is_valid_score( $home_score ) && Match_Status::is_valid_score( $away_score );
		$forfeit    = in_array( get_post_meta( $match->ID, 'lf_outcome', true ), array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true );
		if ( 'finished' !== get_post_meta( $match->ID, 'lf_status', true ) ) {
			return ( $has_scores || $forfeit ) ? 'scored_not_finished' : 'unfinished';
		}
		return ( $has_scores || $forfeit ) ? '' : 'incomplete';
	}

	/**
	 * Apply a forfeit outcome to the standings rows.
	 *
	 * A forfeit is decided regardless of any recorded score. The winning team
	 * is credited a configurable walkover scoreline (default 3-0) so goal
	 * difference stays sensible; a double forfeit gives both sides a loss.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows keyed by team ID (by reference).
	 * @param int                              $home_id Home team ID.
	 * @param int                              $away_id Away team ID.
	 * @param string                           $outcome Forfeit outcome.
	 * @param array<string, mixed>             $rules Rules for this fixture.
	 * @return void
	 */
	protected function apply_forfeit( &$rows, $home_id, $away_id, $outcome, $rules ) {
		$walkover = max( 0, (int) ( $rules['forfeit_score_winner'] ?? get_setting( 'forfeit_score_winner', 3 ) ) );

		$rows[ $home_id ]['played']++;
		$rows[ $away_id ]['played']++;

		if ( 'double_forfeit' === $outcome ) {
			$rows[ $home_id ]['losses']++;
			$rows[ $away_id ]['losses']++;
			$rows[ $home_id ]['points'] += (int) $rules['points_loss'];
			$rows[ $away_id ]['points'] += (int) $rules['points_loss'];
			return;
		}

		if ( 'forfeit_home' === $outcome ) {
			$winner_id = $away_id;
			$loser_id  = $home_id;
		} else {
			$winner_id = $home_id;
			$loser_id  = $away_id;
		}

		$rows[ $winner_id ]['wins']++;
		$rows[ $loser_id ]['losses']++;
		$rows[ $winner_id ]['goals_for']     += $walkover;
		$rows[ $loser_id ]['goals_against']  += $walkover;
		$rows[ $winner_id ]['points']        += (int) $rules['points_win'];
		$rows[ $loser_id ]['points']         += (int) $rules['points_loss'];
	}

	/**
	 * Get finished matches for a context.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<int, \WP_Post>
	 */
	protected function get_finished_matches( $competition_id, $season_id, $sport_id, $league_level_id ) {
		$args = $this->build_match_query_args( $competition_id, $season_id, $sport_id, $league_level_id );

		return array_values( array_filter( get_posts( $args ), function( $match ) {
			return can_view_league_post( $match ) && '' === $this->get_exclusion_reason( $match );
		} ) );
	}

	/** Build the shared query without requiring schedule metadata to exist. */
	protected function build_match_query_args( $competition_id, $season_id, $sport_id, $league_level_id ) {
		$args = array(
			'post_type'      => 'lf_match',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => 'lf_status',
					'value' => 'finished',
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
	 * Get teams within a competition/season context.
	 *
	 * @param int $competition_id Competition term ID.
	 * @param int $season_id Season term ID.
	 * @param int $sport_id Sport term ID.
	 * @param int $league_level_id League level term ID.
	 * @return array<int, int>
	 */
	protected function get_context_team_ids( $competition_id, $season_id, $sport_id, $league_level_id ) {
		$args = array(
			'post_type'      => 'lf_team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
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

		return array_map( 'absint', get_posts( $args ) );
	}

	/**
	 * Sort standings rows.
	 *
	 * @param array<string, mixed> $left Left row.
	 * @param array<string, mixed> $right Right row.
	 * @return int
	 */
	protected function sort_rows( $left, $right ) {
		if ( $left['points'] !== $right['points'] ) {
			return $right['points'] <=> $left['points'];
		}

		$rules = ! empty( $this->rules['tie_breakers'] ) ? (array) $this->rules['tie_breakers'] : get_setting( 'tie_breakers', defaults()['tie_breakers'] );

		foreach ( $rules as $rule ) {
			switch ( $rule ) {
				case 'goal_difference':
					if ( $left['goal_difference'] !== $right['goal_difference'] ) {
						return $right['goal_difference'] <=> $left['goal_difference'];
					}
					break;
				case 'goals_for':
					if ( $left['goals_for'] !== $right['goals_for'] ) {
						return $right['goals_for'] <=> $left['goals_for'];
					}
					break;
				case 'wins':
					if ( $left['wins'] !== $right['wins'] ) {
						return $right['wins'] <=> $left['wins'];
					}
					break;
				case 'goals_against':
					if ( $left['goals_against'] !== $right['goals_against'] ) {
						return $left['goals_against'] <=> $right['goals_against'];
					}
					break;
				case 'name':
					$compare = strcasecmp( (string) $left['name'], (string) $right['name'] );
					if ( 0 !== $compare ) {
						return $compare;
					}
					break;
			}
		}

		return strcasecmp( (string) $left['name'], (string) $right['name'] ) ?: ( $left['team_id'] <=> $right['team_id'] );
	}
}
