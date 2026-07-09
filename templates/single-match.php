<?php
/**
 * Single match wrapper.
 *
 * @package LeagueFlow
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main leagueflow-site-main">
	<div class="leagueflow leagueflow-single-match">
		<?php
		while ( have_posts() ) :
			the_post();
			echo leagueflow()->renderer()->render_match_card( array( 'match' => get_the_ID() ) );
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
