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

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'save_post_lf_match', array( $this, 'handle_match_save' ), 30, 3 );
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
		unset( $post, $update );

		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! get_post_meta( $post_id, 'lf_is_knockout', true ) ) {
			return;
		}

		if ( 'finished' !== get_post_meta( $post_id, 'lf_status', true ) ) {
			return;
		}

		$this->advance_winner( $post_id );
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
		$matches = get_posts( $this->build_query_args( $competition_id, $season_id, $sport_id, $league_level_id ) );

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

			$rounds[ $round_label ]['matches'][] = array(
				'id'             => $match->ID,
				'title'          => $match->post_title,
				'permalink'      => get_permalink( $match->ID ),
				'home_team_id'   => (int) get_post_meta( $match->ID, 'lf_home_team_id', true ),
				'away_team_id'   => (int) get_post_meta( $match->ID, 'lf_away_team_id', true ),
				'home_team'      => get_the_title( (int) get_post_meta( $match->ID, 'lf_home_team_id', true ) ),
				'away_team'      => get_the_title( (int) get_post_meta( $match->ID, 'lf_away_team_id', true ) ),
				'home_score'     => get_post_meta( $match->ID, 'lf_home_score', true ),
				'away_score'     => get_post_meta( $match->ID, 'lf_away_score', true ),
				'status'         => get_post_meta( $match->ID, 'lf_status', true ),
				'datetime'       => format_match_datetime( (string) get_post_meta( $match->ID, 'lf_match_datetime', true ) ),
				'datetime_raw'   => (string) get_post_meta( $match->ID, 'lf_match_datetime', true ),
				'venue'          => get_post_meta( $match->ID, 'lf_venue', true ),
				'winner_team_id' => $this->determine_winner_team_id( $match->ID ),
			);
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
		$matches = get_posts( $this->build_query_args( $competition_id, $season_id, $sport_id, $league_level_id ) );

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

		return array(
			'id'             => (int) $match->ID,
			'title'          => $match->post_title,
			'permalink'      => get_permalink( $match->ID ),
			'round_label'    => (string) get_post_meta( $match->ID, 'lf_round_label', true ),
			'home_team_id'   => $home_team_id,
			'away_team_id'   => $away_team_id,
			'home_team'      => $home_team_id ? get_the_title( $home_team_id ) : '',
			'away_team'      => $away_team_id ? get_the_title( $away_team_id ) : '',
			'home_score'     => get_post_meta( $match->ID, 'lf_home_score', true ),
			'away_score'     => get_post_meta( $match->ID, 'lf_away_score', true ),
			'status'         => (string) get_post_meta( $match->ID, 'lf_status', true ),
			'datetime'       => format_match_datetime( (string) get_post_meta( $match->ID, 'lf_match_datetime', true ) ),
			'datetime_raw'   => (string) get_post_meta( $match->ID, 'lf_match_datetime', true ),
			'venue'          => get_post_meta( $match->ID, 'lf_venue', true ),
			'winner_team_id' => $this->determine_winner_team_id( $match->ID ),
			'is_bye'         => ( $home_team_id && ! $away_team_id ) || ( ! $home_team_id && $away_team_id ),
		);
	}

	/**
	 * Advance the winner into the next match.
	 *
	 * @param int $match_id Match ID.
	 * @return int
	 */
	public function advance_winner( $match_id ) {
		$winner_team_id = $this->determine_winner_team_id( $match_id );
		$next_match_id  = (int) get_post_meta( $match_id, 'lf_next_match_id', true );
		$slot           = get_post_meta( $match_id, 'lf_next_match_slot', true );

		if ( ! $winner_team_id || ! $next_match_id ) {
			return 0;
		}

		$slot = in_array( $slot, array( 'home', 'away' ), true ) ? $slot : 'home';
		update_post_meta( $next_match_id, 'lf_' . $slot . '_team_id', $winner_team_id );

		$home_team_id = (int) get_post_meta( $next_match_id, 'lf_home_team_id', true );
		$away_team_id = (int) get_post_meta( $next_match_id, 'lf_away_team_id', true );
		$datetime     = (string) get_post_meta( $next_match_id, 'lf_match_datetime', true );

		remove_action( 'save_post_lf_match', array( $this, 'handle_match_save' ), 30 );

		wp_update_post(
			array(
				'ID'         => $next_match_id,
				'post_title' => build_match_title( $home_team_id, $away_team_id, $datetime ),
			)
		);

		add_action( 'save_post_lf_match', array( $this, 'handle_match_save' ), 30, 3 );

		return $winner_team_id;
	}

	/**
	 * Determine the winner of a knockout match.
	 *
	 * @param int $match_id Match ID.
	 * @return int
	 */
	public function determine_winner_team_id( $match_id ) {
		$home_team_id = (int) get_post_meta( $match_id, 'lf_home_team_id', true );
		$away_team_id = (int) get_post_meta( $match_id, 'lf_away_team_id', true );
		$winner_id    = (int) get_post_meta( $match_id, 'lf_winner_team_id', true );

		if ( $winner_id && in_array( $winner_id, array( $home_team_id, $away_team_id ), true ) ) {
			return $winner_id;
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

		if ( 'finished' !== get_post_meta( $match_id, 'lf_status', true ) ) {
			return 0;
		}

		$home_score = get_post_meta( $match_id, 'lf_home_score', true );
		$away_score = get_post_meta( $match_id, 'lf_away_score', true );

		if ( ! has_score( $home_score ) || ! has_score( $away_score ) ) {
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
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
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
