<?php
/**
 * Match archive wrapper.
 *
 * @package LeagueFlow
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main leagueflow-site-main">
	<div class="leagueflow leagueflow-archive">
		<header class="leagueflow-archive__header">
			<h1><?php post_type_archive_title(); ?></h1>
		</header>
		<?php echo leagueflow()->renderer()->render_match_list( array( 'limit' => -1 ) ); ?>
	</div>
</main>
<?php
get_footer();
