<?php
/**
 * Template overrides for public content.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Template loader.
 */
class Template_Loader {

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
		add_filter( 'template_include', array( $this, 'template_include' ) );
	}

	/**
	 * Swap templates for supported post types.
	 *
	 * @param string $template Current template.
	 * @return string
	 */
	public function template_include( $template ) {
		// Block themes ship their own lf_* block templates with the site
		// header/footer parts; these classic PHP wrappers would drop them.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && apply_filters( 'leagueflow_use_block_templates', true ) ) {
			return $template;
		}

		if ( is_singular( 'lf_team' ) ) {
			return LEAGUEFLOW_PATH . 'templates/single-team.php';
		}

		if ( is_post_type_archive( 'lf_match' ) ) {
			return LEAGUEFLOW_PATH . 'templates/archive-match.php';
		}

		if ( is_singular( 'lf_match' ) ) {
			return LEAGUEFLOW_PATH . 'templates/single-match.php';
		}

		return $template;
	}
}
