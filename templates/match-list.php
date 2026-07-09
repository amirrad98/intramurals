<?php
/**
 * Match list template.
 *
 * @var array<int, array<string, mixed>> $matches
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-match-list">
	<?php if ( empty( $matches ) ) : ?>
		<p><?php esc_html_e( 'No fixtures were found for this view.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<div class="leagueflow-match-stack">
			<?php foreach ( $matches as $match ) : ?>
				<article class="leagueflow-match-row">
					<header class="leagueflow-match-row__header">
						<div>
							<?php if ( ! empty( $match['round_label'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $match['round_label'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $match['sport_label'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $match['sport_label'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $match['league_level_label'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $match['league_level_label'] ); ?></span><?php endif; ?>
							<strong><?php echo esc_html( $match['status_label'] ); ?></strong>
						</div>
						<?php if ( ! empty( $match['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time><?php endif; ?>
					</header>
					<div class="leagueflow-match-row__body">
						<?php
						// Inline children stay on one line: block-template rendering
						// autop-converts stray newlines into <br> grid items.
						?>
						<div class="leagueflow-match-row__teams"><span><?php echo esc_html( $match['home_team'] ); ?></span><?php if ( ! empty( $match['scoreline'] ) ) : ?><strong class="leagueflow-match-row__score"><?php echo esc_html( $match['scoreline'] ); ?></strong><?php else : ?><span class="leagueflow-match-row__score"><?php esc_html_e( 'vs', 'leagueflow' ); ?></span><?php endif; ?><span><?php echo esc_html( $match['away_team'] ); ?></span></div>
						<?php if ( ! empty( $match['venue'] ) ) : ?><p class="leagueflow-match-row__venue"><?php echo esc_html( $match['venue'] ); ?></p><?php endif; ?>
						<p><a href="<?php echo esc_url( $match['permalink'] ); ?>"><?php esc_html_e( 'View match', 'leagueflow' ); ?></a></p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
