<?php
/**
 * Match card template.
 *
 * @var array<string, mixed> $match
 */

defined( 'ABSPATH' ) || exit;
?>
<article class="leagueflow leagueflow-match-card">
	<header class="leagueflow-match-card__header">
		<?php if ( ! empty( $match['round_label'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $match['round_label'] ); ?></span><?php endif; ?>
		<?php if ( ! empty( $match['sport_label'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $match['sport_label'] ); ?></span><?php endif; ?>
		<?php if ( ! empty( $match['league_level_label'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $match['league_level_label'] ); ?></span><?php endif; ?>
		<h2 class="leagueflow-match-card__status"><?php echo esc_html( $match['status_label'] ); ?></h2>
		<?php if ( ! empty( $match['datetime'] ) ) : ?><p><time datetime="<?php echo esc_attr( $match['datetime_raw'] ); ?>"><?php echo esc_html( $match['datetime'] ); ?></time></p><?php endif; ?>
		<?php if ( ! empty( $match['venue'] ) ) : ?><p><?php echo esc_html( $match['venue'] ); ?></p><?php endif; ?>
	</header>

	<div class="leagueflow-match-card__teams">
		<div class="leagueflow-match-card__team">
			<?php if ( ! empty( $match['home_logo'] ) ) : ?><div class="leagueflow-match-card__logo"><?php echo wp_kses_post( $match['home_logo'] ); ?></div><?php endif; ?>
			<h3><?php echo esc_html( $match['home_team'] ); ?></h3>
		</div>
		<div class="leagueflow-match-card__score">
			<?php if ( ! empty( $match['scoreline'] ) ) : ?>
				<strong><?php echo esc_html( $match['scoreline'] ); ?></strong>
			<?php else : ?>
				<strong><?php esc_html_e( 'vs', 'leagueflow' ); ?></strong>
			<?php endif; ?>
		</div>
		<div class="leagueflow-match-card__team">
			<?php if ( ! empty( $match['away_logo'] ) ) : ?><div class="leagueflow-match-card__logo"><?php echo wp_kses_post( $match['away_logo'] ); ?></div><?php endif; ?>
			<h3><?php echo esc_html( $match['away_team'] ); ?></h3>
		</div>
	</div>

	<?php if ( ! empty( $match['sport_fields'] ) ) : ?>
		<div class="leagueflow-match-card__details">
			<?php foreach ( $match['sport_fields'] as $field ) : ?>
				<section>
					<h4><?php echo esc_html( $field['label'] ); ?></h4>
					<p><?php echo wp_kses_post( nl2br( esc_html( (string) $field['value'] ) ) ); ?></p>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</article>
