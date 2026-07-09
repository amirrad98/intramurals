<?php
/**
 * Team list template.
 *
 * @var array<int, array<string, mixed>> $teams
 * @var bool                             $show_logos
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-team-list">
	<?php if ( empty( $teams ) ) : ?>
		<p><?php esc_html_e( 'No teams were found for this view.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<div class="leagueflow-team-grid">
			<?php foreach ( $teams as $team ) : ?>
				<article class="leagueflow-team-card">
					<?php if ( $show_logos && ! empty( $team['logo'] ) ) : ?>
						<div class="leagueflow-team-card__media"><?php echo wp_kses_post( $team['logo'] ); ?></div>
					<?php endif; ?>
					<div class="leagueflow-team-card__content">
						<h3><a href="<?php echo esc_url( $team['permalink'] ); ?>"><?php echo esc_html( $team['name'] ); ?></a></h3>
						<ul class="leagueflow-meta-list">
							<?php if ( ! empty( $team['sport'] ) ) : ?><li><strong><?php esc_html_e( 'Sport:', 'leagueflow' ); ?></strong> <?php echo esc_html( $team['sport'] ); ?></li><?php endif; ?>
							<?php if ( ! empty( $team['league_level'] ) ) : ?><li><strong><?php esc_html_e( 'Level:', 'leagueflow' ); ?></strong> <?php echo esc_html( $team['league_level'] ); ?></li><?php endif; ?>
							<?php if ( ! empty( $team['short_name'] ) ) : ?><li><strong><?php esc_html_e( 'Short name:', 'leagueflow' ); ?></strong> <?php echo esc_html( $team['short_name'] ); ?></li><?php endif; ?>
							<?php if ( ! empty( $team['city'] ) ) : ?><li><strong><?php esc_html_e( 'City:', 'leagueflow' ); ?></strong> <?php echo esc_html( $team['city'] ); ?></li><?php endif; ?>
							<?php if ( ! empty( $team['coach'] ) ) : ?><li><strong><?php esc_html_e( 'Coach:', 'leagueflow' ); ?></strong> <?php echo esc_html( $team['coach'] ); ?></li><?php endif; ?>
						</ul>
						<?php if ( ! empty( $team['description'] ) ) : ?>
							<p><?php echo esc_html( $team['description'] ); ?></p>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
