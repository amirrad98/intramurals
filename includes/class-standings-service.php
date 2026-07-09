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
				'points'          => 0,
				'permalink'       => get_permalink( $team_id ),
			);
		}

		foreach ( $matches as $match ) {
			$home_id    = (int) get_post_meta( $match->ID, 'lf_home_team_id', true );
			$away_id    = (int) get_post_meta( $match->ID, 'lf_away_team_id', true );
			$home_score = get_post_meta( $match->ID, 'lf_home_score', true );
			$away_score = get_post_meta( $match->ID, 'lf_away_score', true );

			if ( ! $home_id || ! $away_id || ! has_score( $home_score ) || ! has_score( $away_score ) ) {
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
		}

		$rows = array_values( $rows );

		usort( $rows, array( $this, 'sort_rows' ) );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['position'] = $index + 1;
		}

		return $rows;
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
