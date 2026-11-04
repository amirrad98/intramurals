<?php
/**
 * Single team content template.
 *
 * @var \WP_Post                            $team
 * @var string                              $team_logo
 * @var string                              $short_name
 * @var string                              $city
 * @var string                              $coach
 * @var string                              $founded_year
 * @var string                              $sport_label
 * @var string                              $league_level_label
 * @var array<int, array<string, mixed>>    $players
 * @var array<int, array<string, mixed>>    $recent_matches
 */

defined( 'ABSPATH' ) || exit;
?>
<article class="leagueflow leagueflow-team-page">
	<header class="leagueflow-team-header">
		<?php if ( ! empty( $team_logo ) ) : ?><div class="leagueflow-team-header__media"><?php echo wp_kses_post( $team_logo ); ?></div><?php endif; ?>
		<div class="leagueflow-team-header__content">
			<h1><?php echo esc_html( get_the_title( $team ) ); ?></h1>
			<ul class="leagueflow-meta-list">
				<?php if ( ! empty( $sport_label ) ) : ?><li><strong><?php esc_html_e( 'Sport:', 'leagueflow' ); ?></strong> <?php echo esc_html( $sport_label ); ?></li><?php endif; ?>
				<?php if ( ! empty( $league_level_label ) ) : ?><li><strong><?php esc_html_e( 'Level:', 'leagueflow' ); ?></strong> <?php echo esc_html( $league_level_label ); ?></li><?php endif; ?>
				<?php if ( ! empty( $short_name ) ) : ?><li><strong><?php esc_html_e( 'Short name:', 'leagueflow' ); ?></strong> <?php echo esc_html( $short_name ); ?></li><?php endif; ?>
				<?php if ( ! empty( $city ) ) : ?><li><strong><?php esc_html_e( 'City:', 'leagueflow' ); ?></strong> <?php echo esc_html( $city ); ?></li><?php endif; ?>
				<?php if ( ! empty( $coach ) ) : ?><li><strong><?php esc_html_e( 'Coach:', 'leagueflow' ); ?></strong> <?php echo esc_html( $coach ); ?></li><?php endif; ?>
				<?php if ( ! empty( $founded_year ) ) : ?><li><strong><?php esc_html_e( 'Founded:', 'leagueflow' ); ?></strong> <?php echo esc_html( $founded_year ); ?></li><?php endif; ?>
			</ul>
		</div>
	</header>

	<?php if ( ! empty( $team->post_content ) ) : ?>
		<div class="leagueflow-team-page__description">
			<?php echo wp_kses_post( wpautop( $team->post_content ) ); ?>
		</div>
	<?php endif; ?>

	<section class="leagueflow-team-page__section">
		<h2><?php esc_html_e( 'Roster', 'leagueflow' ); ?></h2>
		<?php echo leagueflow()->renderer()->render_team_roster( array( 'team' => $team->ID ) ); ?>
	</section>

	<section class="leagueflow-team-page__section">
		<h2><?php esc_html_e( 'Recent Matches', 'leagueflow' ); ?></h2>
		<?php if ( empty( $recent_matches ) ) : ?>
			<p><?php esc_html_e( 'No matches are available for this team yet.', 'leagueflow' ); ?></p>
		<?php else : ?>
			<div class="leagueflow-match-stack">
				<?php foreach ( $recent_matches as $match ) : ?>
					<?php echo \LeagueFlow\render_match_card( $match ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by render_match_card(). ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
</article>
