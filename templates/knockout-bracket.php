<?php
/**
 * Knockout bracket template.
 *
 * @var array<int, array<string, mixed>> $rounds
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-bracket-wrap">
	<?php if ( empty( $rounds ) ) : ?>
		<p><?php esc_html_e( 'No knockout fixtures are available yet.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<div class="leagueflow-bracket">
			<?php foreach ( $rounds as $round ) : ?>
				<section class="leagueflow-bracket__round">
					<h3><?php echo esc_html( $round['label'] ); ?></h3>
					<?php foreach ( $round['matches'] as $match ) : ?>
						<article class="leagueflow-bracket__match">
							<?php if ( ! empty( $match['datetime'] ) ) : ?><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time><?php endif; ?>
							<div class="leagueflow-bracket__team<?php echo (int) $match['winner_team_id'] === (int) $match['home_team_id'] ? ' is-winner' : ''; ?>">
								<span><?php echo esc_html( $match['home_team'] ? $match['home_team'] : __( 'TBD', 'leagueflow' ) ); ?></span>
								<strong><?php echo has_score( $match['home_score'] ) ? esc_html( (string) score_to_int( $match['home_score'] ) ) : '&mdash;'; ?></strong>
							</div>
							<div class="leagueflow-bracket__team<?php echo (int) $match['winner_team_id'] === (int) $match['away_team_id'] ? ' is-winner' : ''; ?>">
								<span><?php echo esc_html( $match['away_team'] ? $match['away_team'] : __( 'TBD', 'leagueflow' ) ); ?></span>
								<strong><?php echo has_score( $match['away_score'] ) ? esc_html( (string) score_to_int( $match['away_score'] ) ) : '&mdash;'; ?></strong>
							</div>
							<p><a href="<?php echo esc_url( $match['permalink'] ); ?>"><?php esc_html_e( 'View match', 'leagueflow' ); ?></a></p>
						</article>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
