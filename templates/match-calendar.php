<?php
/**
 * Match calendar template.
 *
 * @var array<string, mixed>             $payload    Calendar data for the frontend script.
 * @var array<int, array<string, mixed>> $sports     Sports present in the schedule.
 * @var array<int, array<string, mixed>> $types      Event types present in the schedule.
 * @var bool                             $show_chips Whether to render the sport filter.
 * @var bool                             $show_types Whether to render the type filter.
 * @var array<int, array<string, mixed>> $items      Flat schedule list for the no-JS fallback.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="leagueflow leagueflow-calendar" data-leagueflow-calendar>
	<div class="leagueflow-calendar__toolbar">
		<div class="leagueflow-calendar__view-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Calendar view', 'leagueflow' ); ?>">
			<button type="button" class="leagueflow-calendar__view is-active" data-calendar-view="month" aria-pressed="true"><?php esc_html_e( 'Month', 'leagueflow' ); ?></button>
			<button type="button" class="leagueflow-calendar__view" data-calendar-view="list" aria-pressed="false"><?php esc_html_e( 'List', 'leagueflow' ); ?></button>
			<?php if ( ! empty( $payload['config']['showWeek'] ) ) : ?>
				<button type="button" class="leagueflow-calendar__view" data-calendar-view="week" aria-pressed="false"><?php esc_html_e( 'Week', 'leagueflow' ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $payload['config']['showDay'] ) ) : ?>
				<button type="button" class="leagueflow-calendar__view" data-calendar-view="day" aria-pressed="false"><?php esc_html_e( 'Day', 'leagueflow' ); ?></button>
			<?php endif; ?>
		</div>

		<label class="leagueflow-calendar__search">
			<span class="screen-reader-text"><?php esc_html_e( 'Search schedule', 'leagueflow' ); ?></span>
			<input type="search" data-calendar-search placeholder="<?php esc_attr_e( 'Search schedule', 'leagueflow' ); ?>" />
		</label>
	</div>

	<div class="leagueflow-calendar__filters">
		<?php if ( $show_chips && count( $sports ) > 1 ) : ?>
			<div class="leagueflow-calendar__chips" role="group" aria-label="<?php esc_attr_e( 'Filter schedule by sport', 'leagueflow' ); ?>">
				<button type="button" class="leagueflow-calendar__chip is-active" data-sport="" aria-pressed="true">
					<?php esc_html_e( 'All sports', 'leagueflow' ); ?>
				</button>
				<?php foreach ( $sports as $sport ) : ?>
					<button type="button" class="leagueflow-calendar__chip" data-sport="<?php echo esc_attr( $sport['slug'] ); ?>" aria-pressed="false" style="--lf-cal-accent: <?php echo esc_attr( $sport['color'] ); ?>;">
						<span class="leagueflow-calendar__chip-dot" aria-hidden="true"></span>
						<?php echo esc_html( $sport['label'] ); ?>
						<span class="leagueflow-calendar__chip-count"><?php echo esc_html( (string) $sport['count'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $show_types ) : ?>
			<div class="leagueflow-calendar__chips leagueflow-calendar__chips--types" role="group" aria-label="<?php esc_attr_e( 'Filter schedule by type', 'leagueflow' ); ?>">
				<button type="button" class="leagueflow-calendar__chip is-active" data-type="" aria-pressed="true">
					<?php esc_html_e( 'All types', 'leagueflow' ); ?>
				</button>
				<?php foreach ( $types as $type ) : ?>
					<button type="button" class="leagueflow-calendar__chip" data-type="<?php echo esc_attr( $type['slug'] ); ?>" aria-pressed="false">
						<?php echo esc_html( $type['label'] ); ?>
						<span class="leagueflow-calendar__chip-count"><?php echo esc_html( (string) $type['count'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="leagueflow-calendar__layout">
		<section class="leagueflow-calendar__board">
			<header class="leagueflow-calendar__header">
				<h3 class="leagueflow-calendar__month" data-calendar-range-label>&nbsp;</h3>
				<div class="leagueflow-calendar__nav">
					<button type="button" class="leagueflow-calendar__nav-btn" data-calendar-today><?php esc_html_e( 'Today', 'leagueflow' ); ?></button>
					<button type="button" class="leagueflow-calendar__nav-btn" data-calendar-prev aria-label="<?php esc_attr_e( 'Previous', 'leagueflow' ); ?>">&lsaquo;</button>
					<button type="button" class="leagueflow-calendar__nav-btn" data-calendar-next aria-label="<?php esc_attr_e( 'Next', 'leagueflow' ); ?>">&rsaquo;</button>
				</div>
			</header>
			<div class="leagueflow-calendar__stage" data-calendar-stage></div>
		</section>
		<aside class="leagueflow-calendar__panel" data-calendar-panel>
			<div class="leagueflow-calendar__panel-head">
				<h3 class="leagueflow-calendar__panel-title" data-calendar-panel-title>&nbsp;</h3>
				<button type="button" class="leagueflow-calendar__panel-clear" data-calendar-clear hidden><?php esc_html_e( 'Show full month', 'leagueflow' ); ?></button>
			</div>
			<div class="leagueflow-calendar__events" data-calendar-events aria-live="polite"></div>
		</aside>
	</div>

	<div class="leagueflow-calendar__dialog" data-calendar-dialog hidden>
		<div class="leagueflow-calendar__dialog-backdrop" data-calendar-dialog-close></div>
		<article class="leagueflow-calendar__dialog-card" role="dialog" aria-modal="true" aria-labelledby="leagueflow-calendar-dialog-title">
			<button type="button" class="leagueflow-calendar__dialog-close" data-calendar-dialog-close aria-label="<?php esc_attr_e( 'Close', 'leagueflow' ); ?>">&times;</button>
			<div data-calendar-dialog-content></div>
		</article>
	</div>

	<noscript>
		<div class="leagueflow-calendar__fallback">
			<p><?php esc_html_e( 'The interactive calendar needs JavaScript. Here is the full schedule:', 'leagueflow' ); ?></p>
			<div class="leagueflow-match-stack">
				<?php foreach ( $items as $item ) : ?>
					<article class="leagueflow-match-row">
						<header class="leagueflow-match-row__header">
							<div>
								<?php if ( ! empty( $item['sportLabel'] ) ) : ?><span class="leagueflow-badge"><?php echo esc_html( $item['sportLabel'] ); ?></span><?php endif; ?>
								<strong><?php echo esc_html( $item['statusLabel'] ); ?></strong>
							</div>
							<time datetime="<?php echo esc_attr( $item['start'] ); ?>"><?php echo esc_html( $item['day'] . ' ' . $item['time'] ); ?></time>
						</header>
						<div class="leagueflow-match-row__body">
							<?php if ( 'match' === $item['source'] ) : ?>
								<div class="leagueflow-match-row__teams">
									<span><?php echo esc_html( $item['home'] ); ?></span>
									<?php if ( ! empty( $item['scoreline'] ) ) : ?>
										<strong class="leagueflow-match-row__score"><?php echo esc_html( $item['scoreline'] ); ?></strong>
									<?php else : ?>
										<span class="leagueflow-match-row__score"><?php esc_html_e( 'vs', 'leagueflow' ); ?></span>
									<?php endif; ?>
									<span><?php echo esc_html( $item['away'] ); ?></span>
								</div>
							<?php else : ?>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</noscript>

	<script type="application/json" data-calendar-data><?php echo wp_json_encode( $payload ); ?></script>
</div>
