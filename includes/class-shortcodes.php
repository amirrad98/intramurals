<?php
/**
 * Shortcode registration.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes.
 */
class Shortcodes {

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
		add_shortcode( 'league_table', array( $this, 'league_table' ) );
		add_shortcode( 'sport_standings', array( $this, 'sport_standings' ) );
		add_shortcode( 'team_list', array( $this, 'team_list' ) );
		add_shortcode( 'team_roster', array( $this, 'team_roster' ) );
		add_shortcode( 'team_page', array( $this, 'team_page' ) );
		add_shortcode( 'match_list', array( $this, 'match_list' ) );
		add_shortcode( 'match_calendar', array( $this, 'match_calendar' ) );
		add_shortcode( 'sports_calendar', array( $this, 'match_calendar' ) );
		add_shortcode( 'match_card', array( $this, 'match_card' ) );
		add_shortcode( 'knockout_bracket', array( $this, 'knockout_bracket' ) );
	}

	/**
	 * Render league table shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function league_table( $atts ) {
		return $this->renderer->render_league_table( (array) $atts );
	}

	/**
	 * Render sport standings shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function sport_standings( $atts ) {
		return $this->renderer->render_sport_standings( (array) $atts );
	}

	/**
	 * Render team list shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function team_list( $atts ) {
		return $this->renderer->render_team_list( (array) $atts );
	}

	/**
	 * Render roster shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function team_roster( $atts ) {
		return $this->renderer->render_team_roster( (array) $atts );
	}

	/**
	 * Render team profile shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function team_page( $atts ) {
		return $this->renderer->render_team_page( (array) $atts );
	}

	/**
	 * Render match list shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function match_list( $atts ) {
		return $this->renderer->render_match_list( (array) $atts );
	}

	/**
	 * Render match calendar shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function match_calendar( $atts ) {
		return $this->renderer->render_match_calendar( (array) $atts );
	}

	/**
	 * Render match card shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function match_card( $atts ) {
		return $this->renderer->render_match_card( (array) $atts );
	}

	/**
	 * Render bracket shortcode.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function knockout_bracket( $atts ) {
		return $this->renderer->render_knockout_bracket( (array) $atts );
	}
}
