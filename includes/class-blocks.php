<?php
/**
 * Gutenberg block registration.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic block loader.
 */
class Blocks {

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
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
	}

	/**
	 * Register block types from metadata.
	 *
	 * @return void
	 */
	public function register_blocks() {
		$blocks = array(
			'league-table'     => array( $this->renderer, 'render_league_table' ),
			'sport-standings'  => array( $this->renderer, 'render_sport_standings' ),
			'team-list'        => array( $this->renderer, 'render_team_list' ),
			'team-roster'      => array( $this->renderer, 'render_team_roster' ),
			'match-list'       => array( $this->renderer, 'render_match_list' ),
			'match-calendar'   => array( $this->renderer, 'render_match_calendar' ),
			'match-card'       => array( $this->renderer, 'render_match_card' ),
			'knockout-bracket' => array( $this->renderer, 'render_knockout_bracket' ),
		);

		foreach ( $blocks as $directory => $callback ) {
			register_block_type_from_metadata(
				LEAGUEFLOW_PATH . 'blocks/' . $directory,
				array(
					'render_callback' => $callback,
				)
			);
		}
	}
}
