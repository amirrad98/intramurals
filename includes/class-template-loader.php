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
		add_action( 'init', array( $this, 'register_block_templates' ), 25 );

		if ( ! function_exists( 'register_block_template' ) ) {
			add_filter( 'get_block_templates', array( $this, 'add_legacy_block_templates' ), 10, 3 );
			add_filter( 'get_block_template', array( $this, 'get_legacy_block_template' ), 10, 3 );
			add_filter( 'get_block_file_template', array( $this, 'get_legacy_block_template' ), 10, 3 );
		}
	}

	/**
	 * Register portable block templates for block themes.
	 *
	 * Themes can override these by shipping templates with the same slug, and
	 * users can override them from the Site Editor.
	 *
	 * @return void
	 */
	public function register_block_templates() {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		foreach ( $this->get_block_template_definitions() as $slug => $definition ) {
			register_block_template(
				'leagueflow//' . $slug,
				array(
					'title'       => $definition['title'],
					'description' => $definition['description'],
					'content'     => $this->get_block_template_content( $slug ),
					'post_types'  => $definition['post_types'],
					'plugin'      => 'leagueflow',
				)
			);
		}
	}

	/**
	 * Swap templates for supported post types.
	 *
	 * @param string $template Current template.
	 * @return string
	 */
	public function template_include( $template ) {
		// Block themes ship their own lf_* block templates with the site
		// header/footer parts; plugin block templates are registered separately.
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

	/**
	 * Add plugin block templates on WordPress versions before register_block_template().
	 *
	 * @param array<int, \WP_Block_Template> $templates Current templates.
	 * @param array<string, mixed>           $query Query args.
	 * @param string                         $template_type Template type.
	 * @return array<int, \WP_Block_Template>
	 */
	public function add_legacy_block_templates( $templates, $query, $template_type ) {
		if ( 'wp_template' !== $template_type || ! $this->should_use_block_templates() ) {
			return $templates;
		}

		$requested_slugs = isset( $query['slug__in'] ) ? array_map( 'sanitize_key', (array) $query['slug__in'] ) : array();
		$existing_slugs  = wp_list_pluck( $templates, 'slug' );

		foreach ( array_keys( $this->get_block_template_definitions() ) as $slug ) {
			if ( ! empty( $requested_slugs ) && ! in_array( $slug, $requested_slugs, true ) ) {
				continue;
			}

			if ( in_array( $slug, $existing_slugs, true ) ) {
				continue;
			}

			$templates[] = $this->build_legacy_block_template( $slug );
		}

		return $templates;
	}

	/**
	 * Return a legacy plugin block template when no DB/theme template exists.
	 *
	 * @param \WP_Block_Template|null $template Current template.
	 * @param string                  $id Template ID.
	 * @param string                  $template_type Template type.
	 * @return \WP_Block_Template|null
	 */
	public function get_legacy_block_template( $template, $id, $template_type ) {
		if ( null !== $template || 'wp_template' !== $template_type || ! $this->should_use_block_templates() ) {
			return $template;
		}

		$parts = explode( '//', (string) $id );
		$slug  = sanitize_key( end( $parts ) );

		if ( ! isset( $this->get_block_template_definitions()[ $slug ] ) ) {
			return $template;
		}

		return $this->build_legacy_block_template( $slug );
	}

	/**
	 * Whether LeagueFlow should provide block-theme templates.
	 *
	 * @return bool
	 */
	protected function should_use_block_templates() {
		return function_exists( 'wp_is_block_theme' )
			&& wp_is_block_theme()
			&& apply_filters( 'leagueflow_use_block_templates', true );
	}

	/**
	 * Get plugin block template definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_block_template_definitions() {
		return array(
			'single-lf_team'   => array(
				'title'       => __( 'LeagueFlow Team', 'leagueflow' ),
				'description' => __( 'Displays a LeagueFlow team profile.', 'leagueflow' ),
				'post_types'  => array( 'lf_team' ),
			),
			'single-lf_match'  => array(
				'title'       => __( 'LeagueFlow Match', 'leagueflow' ),
				'description' => __( 'Displays a LeagueFlow match centre page.', 'leagueflow' ),
				'post_types'  => array( 'lf_match' ),
			),
			'archive-lf_match' => array(
				'title'       => __( 'LeagueFlow Match Archive', 'leagueflow' ),
				'description' => __( 'Displays all LeagueFlow matches.', 'leagueflow' ),
				'post_types'  => array( 'lf_match' ),
			),
		);
	}

	/**
	 * Build a WP_Block_Template object for legacy WordPress versions.
	 *
	 * @param string $slug Template slug.
	 * @return \WP_Block_Template
	 */
	protected function build_legacy_block_template( $slug ) {
		$definition = $this->get_block_template_definitions()[ $slug ];
		$template   = new \WP_Block_Template();

		$template->id             = get_stylesheet() . '//' . $slug;
		$template->theme          = get_stylesheet();
		$template->content        = $this->get_block_template_content( $slug );
		$template->slug           = $slug;
		$template->source         = 'plugin';
		$template->type           = 'wp_template';
		$template->title          = $definition['title'];
		$template->description    = $definition['description'];
		$template->status         = 'publish';
		$template->has_theme_file = false;
		$template->is_custom      = false;
		$template->plugin         = 'leagueflow';
		$template->post_types     = $definition['post_types'];

		return $template;
	}

	/**
	 * Read a bundled block template.
	 *
	 * @param string $slug Template slug.
	 * @return string
	 */
	protected function get_block_template_content( $slug ) {
		$path = LEAGUEFLOW_PATH . 'templates/block/' . sanitize_file_name( $slug ) . '.html';

		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}
}
