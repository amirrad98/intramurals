<?php
/**
 * Main plugin container.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Registered modules.
	 *
	 * @var array<int, object>
	 */
	protected $modules = array();

	/**
	 * Renderer instance.
	 *
	 * @var Renderer
	 */
	protected $renderer;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$sports    = new Sports_Manager();
		$settings  = new Settings();
		$post_type = new Post_Types();
		$taxonomy  = new Taxonomies();
		$assets    = new Assets();

		$standings = new Standings_Service();
		$knockout  = new Knockout_Service();
		$exporter  = new Exporter( $sports );
		$fields    = new Field_Availability_Manager();

		$this->renderer = new Renderer( $standings, $knockout, $sports );

		$seeder    = new Seeder();
		$admin     = new Admin( $standings, $knockout, $this->renderer, $seeder, $sports, $exporter, $fields );
		$portal    = new Portal( $this->renderer );
		$rest      = new Rest_Controller( $standings, $knockout, $this->renderer );
		$shortcode = new Shortcodes( $this->renderer );
		$blocks    = new Blocks( $this->renderer );
		$templates = new Template_Loader( $this->renderer );

		$this->modules = array(
			$sports,
			$settings,
			$post_type,
			$taxonomy,
			$assets,
			$standings,
			$knockout,
			$fields,
			$admin,
			$portal,
			$rest,
			$shortcode,
			$blocks,
			$templates,
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		load_plugin_textdomain( 'leagueflow', false, dirname( plugin_basename( LEAGUEFLOW_FILE ) ) . '/languages' );

		foreach ( $this->modules as $module ) {
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}
	}

	/**
	 * Get the shared renderer.
	 *
	 * @return Renderer
	 */
	public function renderer() {
		return $this->renderer;
	}
}
