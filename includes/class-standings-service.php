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
		$matches  = $this->get_finished_matches( $competition_id, $season_id, $sport_id, $league_level_id );
		$team_ids = $this->get_context_team_ids( $competition_id, $season_id, $sport_id, $league_level_id );

		foreach ( $matches as $match ) {
			$team_ids[] = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
			$team_ids[] = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );
		}

		$team_ids = array_values( array_filter( array_unique( array_map( 'absint', $team_ids ) ) ) );

		$rows = array();

		foreach ( $team_ids as $team_id ) {
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
				'permalink'       => get_permalink( $team_id ),
			);
		}

		foreach ( $matches as $match ) {
			$home_id = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
			$away_id = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );

			if ( ! $home_id || ! $away_id || empty( $rows[ $home_id ] ) || empty( $rows[ $away_id ] ) ) {
				continue;
			}

			$outcome = sanitize_key( (string) get_post_meta( $match->ID, 'lf_outcome', true ) );

			if ( in_array( $outcome, array( 'forfeit_home', 'forfeit_away', 'double_forfeit' ), true ) ) {
				$this->apply_forfeit( $rows, $home_id, $away_id, $outcome );
				continue;
			}

			$home_score = get_post_meta( $match->ID, 'lf_home_score', true );
			$away_score = get_post_meta( $match->ID, 'lf_away_score', true );

			if ( ! has_score( $home_score ) || ! has_score( $away_score ) ) {
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
				$rows[ $home_id ]['points'] += (int) get_setting( 'points_win', 3 );
				$rows[ $away_id ]['points'] += (int) get_setting( 'points_loss', 0 );
			} elseif ( $away_score > $home_score ) {
				$rows[ $away_id ]['wins']++;
				$rows[ $home_id ]['losses']++;
				$rows[ $away_id ]['points'] += (int) get_setting( 'points_win', 3 );
				$rows[ $home_id ]['points'] += (int) get_setting( 'points_loss', 0 );
			} else {
				$rows[ $home_id ]['draws']++;
				$rows[ $away_id ]['draws']++;
				$rows[ $home_id ]['points'] += (int) get_setting( 'points_draw', 1 );
				$rows[ $away_id ]['points'] += (int) get_setting( 'points_draw', 1 );
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
	 * @return void
	 */
	protected function apply_forfeit( &$rows, $home_id, $away_id, $outcome ) {
		$walkover = max( 0, (int) get_setting( 'forfeit_score_winner', 3 ) );

		$rows[ $home_id ]['played']++;
		$rows[ $away_id ]['played']++;

		if ( 'double_forfeit' === $outcome ) {
			$rows[ $home_id ]['losses']++;
			$rows[ $away_id ]['losses']++;
			$rows[ $home_id ]['points'] += (int) get_setting( 'points_loss', 0 );
			$rows[ $away_id ]['points'] += (int) get_setting( 'points_loss', 0 );
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
		$rows[ $winner_id ]['points']        += (int) get_setting( 'points_win', 3 );
		$rows[ $loser_id ]['points']         += (int) get_setting( 'points_loss', 0 );
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
		$args = array(
			'post_type'      => 'lf_match',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => 'lf_match_datetime',
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

		return get_posts( $args );
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
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
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

		$rules = get_setting( 'tie_breakers', defaults()['tie_breakers'] );

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

		return strcasecmp( (string) $left['name'], (string) $right['name'] );
	}
}
