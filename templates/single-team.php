<?php
/**
 * Single team wrapper.
 *
 * @package LeagueFlow
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main leagueflow-site-main">
	<?php
	while ( have_posts() ) :
		the_post();
		echo leagueflow()->renderer()->render_team_single( get_the_ID() );
	endwhile;
	?>
</main>
<?php
get_footer();
