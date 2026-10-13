<?php
/**
 * Tab "Statistics": searches, costs and recording settings.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\AdminPage $this
 * @var \PrstSearch\Admin\Fields    $fields
 * @var array{stats: array<string, mixed>, can_manage: bool} $data
 */

use PrstSearch\Admin\AdminPage;
use PrstSearch\Admin\FormActions;
use PrstSearch\Settings\SettingsSchema;
use PrstSearch\Stats\DateRange;

defined( 'ABSPATH' ) || exit;

$prstsearch_stats = $data['stats'];
$prstsearch_range = $prstsearch_stats['range'];
$prstsearch_table = $prstsearch_stats['table'];
?>
<div class="prstsearch-toolbar">
	<nav aria-label="<?php esc_attr_e( 'Period', 'smart-search' ); ?>">
		<ul class="prstsearch-segments">
			<?php foreach ( $prstsearch_stats['presets'] as $prstsearch_preset => $prstsearch_label ) : ?>
				<li>
					<a
						class="prstsearch-segments__link"
						href="<?php echo esc_url( AdminPage::url( SettingsSchema::TAB_STATS, array( 'range' => $prstsearch_preset ) ) ); ?>"
						<?php echo $prstsearch_preset === $prstsearch_range->preset ? 'aria-current="true"' : ''; ?>
					>
						<?php echo esc_html( $prstsearch_label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<form class="prstsearch-toolbar__custom" method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( AdminPage::SLUG ); ?>" />
		<input type="hidden" name="tab" value="<?php echo esc_attr( SettingsSchema::TAB_STATS ); ?>" />
		<input type="hidden" name="range" value="<?php echo esc_attr( DateRange::CUSTOM ); ?>" />

		<label for="prstsearch-from"><?php esc_html_e( 'From', 'smart-search' ); ?></label>
		<input class="prstsearch-input" type="date" id="prstsearch-from" name="from" value="<?php echo esc_attr( $prstsearch_range->start->format( 'Y-m-d' ) ); ?>" required />
		<label for="prstsearch-to"><?php esc_html_e( 'to', 'smart-search' ); ?></label>
		<input class="prstsearch-input" type="date" id="prstsearch-to" name="to" value="<?php echo esc_attr( $prstsearch_range->last_day()->format( 'Y-m-d' ) ); ?>" required />
		<button type="submit" class="prstsearch-button prstsearch-button--neutral"><?php esc_html_e( 'Show', 'smart-search' ); ?></button>
	</form>

	<a class="prstsearch-button prstsearch-button--secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( $prstsearch_stats['range_args'], admin_url( 'admin-post.php?action=' . FormActions::EXPORT_CSV ) ), FormActions::EXPORT_CSV ) ); ?>">
		<?php esc_html_e( 'Export CSV', 'smart-search' ); ?>
	</a>
</div>

<ul class="prstsearch-tiles">
	<?php foreach ( $prstsearch_stats['tiles'] as $prstsearch_tile ) : ?>
		<li class="prstsearch-tile">
			<p class="prstsearch-tile__label"><?php echo esc_html( $prstsearch_tile['label'] ); ?></p>
			<p class="prstsearch-tile__value"><?php echo wp_kses_post( $prstsearch_tile['value']->html() ); ?></p>
			<p class="prstsearch-tile__note"><?php echo wp_kses_post( $prstsearch_tile['note']->html() ); ?></p>
		</li>
	<?php endforeach; ?>
</ul>

<div class="prstsearch-grid">
	<?php
	$this->include_view(
		'chart',
		array(
			'id'    => 'prstsearch-chart-searches',
			'title' => __( 'Searches per day', 'smart-search' ),
			'note'  => '',
			'chart' => $prstsearch_stats['charts']['searches'],
		)
	);

	$this->include_view(
		'chart',
		array(
			'id'    => 'prstsearch-chart-costs',
			'title' => __( 'AI costs per day', 'smart-search' ),
			'note'  => $prstsearch_stats['price_note'],
			'chart' => $prstsearch_stats['charts']['costs'],
		)
	);
	?>
</div>

<div class="prstsearch-grid">
	<?php
	$prstsearch_lists = array(
		'top'             => array( __( 'Most frequent search phrases', 'smart-search' ), __( 'No searches in this period.', 'smart-search' ) ),
		'without_results' => array( __( 'Searches without results', 'smart-search' ), __( 'Every search found something.', 'smart-search' ) ),
	);
	?>
	<?php foreach ( $prstsearch_lists as $prstsearch_key => $prstsearch_texts ) : ?>
		<section class="prstsearch-panel" aria-labelledby="prstsearch-ranking-<?php echo esc_attr( $prstsearch_key ); ?>">
			<h2 class="prstsearch-panel__title" id="prstsearch-ranking-<?php echo esc_attr( $prstsearch_key ); ?>"><?php echo esc_html( $prstsearch_texts[0] ); ?></h2>

			<?php if ( array() === $prstsearch_stats['rankings'][ $prstsearch_key ] ) : ?>
				<p class="prstsearch-empty"><?php echo esc_html( $prstsearch_texts[1] ); ?></p>
			<?php else : ?>
				<ol class="prstsearch-ranking">
					<?php foreach ( $prstsearch_stats['rankings'][ $prstsearch_key ] as $prstsearch_row ) : ?>
						<li class="prstsearch-ranking__item">
							<span class="prstsearch-ranking__query"><?php echo esc_html( $prstsearch_row['query'] ); ?></span>
							<?php if ( 'without_results' === $prstsearch_key && $data['can_manage'] ) : ?>
								<a class="prstsearch-ranking__action" href="<?php echo esc_url( AdminPage::url( SettingsSchema::TAB_SEARCH, array( 'recommend' => rawurlencode( $prstsearch_row['query'] ) ) ) . '#prstsearch-recommend' ); ?>">
									<?php esc_html_e( 'Recommend a page', 'smart-search' ); ?>
									<span class="screen-reader-text">
										<?php
										/* translators: %s: search phrase. */
										echo esc_html( sprintf( __( 'for “%s”', 'smart-search' ), $prstsearch_row['query'] ) );
										?>
									</span>
								</a>
							<?php endif; ?>
							<span class="prstsearch-ranking__count">
								<?php echo esc_html( number_format_i18n( $prstsearch_row['searches'] ) ); ?>
								<span class="screen-reader-text"><?php esc_html_e( 'searches', 'smart-search' ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>

<section class="prstsearch-panel" aria-labelledby="prstsearch-all-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-all-title"><?php esc_html_e( 'All searches', 'smart-search' ); ?></h2>

	<form class="prstsearch-filter" method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( AdminPage::SLUG ); ?>" />
		<input type="hidden" name="tab" value="<?php echo esc_attr( SettingsSchema::TAB_STATS ); ?>" />
		<?php foreach ( $prstsearch_stats['range_args'] as $prstsearch_name => $prstsearch_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $prstsearch_name ); ?>" value="<?php echo esc_attr( $prstsearch_value ); ?>" />
		<?php endforeach; ?>

		<label class="screen-reader-text" for="prstsearch-filter-query"><?php esc_html_e( 'Filter by search phrase', 'smart-search' ); ?></label>
		<input class="prstsearch-input" type="search" id="prstsearch-filter-query" name="q" value="<?php echo esc_attr( $prstsearch_table['filter']['search'] ); ?>" placeholder="<?php esc_attr_e( 'Filter by search phrase', 'smart-search' ); ?>" />

		<label class="screen-reader-text" for="prstsearch-filter-mode"><?php esc_html_e( 'Filter by mode', 'smart-search' ); ?></label>
		<select class="prstsearch-input" id="prstsearch-filter-mode" name="mode">
			<option value=""><?php esc_html_e( 'All modes', 'smart-search' ); ?></option>
			<?php foreach ( $prstsearch_table['modes'] as $prstsearch_mode => $prstsearch_label ) : ?>
				<option value="<?php echo esc_attr( $prstsearch_mode ); ?>" <?php selected( $prstsearch_table['filter']['mode'], $prstsearch_mode ); ?>><?php echo esc_html( $prstsearch_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="prstsearch-button prstsearch-button--neutral"><?php esc_html_e( 'Filter', 'smart-search' ); ?></button>
	</form>

	<?php if ( array() === $prstsearch_table['rows'] ) : ?>
		<p class="prstsearch-empty"><?php esc_html_e( 'No searches match. As soon as visitors search, their queries appear here.', 'smart-search' ); ?></p>
	<?php else : ?>
		<div class="prstsearch-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'All searches, table', 'smart-search' ); ?>">
			<table class="prstsearch-table">
				<thead>
					<tr>
						<?php foreach ( $prstsearch_stats['headers'] as $prstsearch_key => $prstsearch_label ) : ?>
							<th scope="col" class="<?php echo in_array( $prstsearch_key, $prstsearch_stats['numeric_columns'], true ) ? 'prstsearch-table__number' : ''; ?>"><?php echo esc_html( $prstsearch_label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $prstsearch_table['rows'] as $prstsearch_row ) : ?>
						<tr>
							<?php foreach ( $prstsearch_stats['headers'] as $prstsearch_key => $prstsearch_label ) : ?>
								<td class="<?php echo in_array( $prstsearch_key, $prstsearch_stats['numeric_columns'], true ) ? 'prstsearch-table__number' : ''; ?>"><?php echo wp_kses_post( $prstsearch_row[ $prstsearch_key ]->html() ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<nav class="prstsearch-pagination" aria-label="<?php esc_attr_e( 'Pages of the search list', 'smart-search' ); ?>">
			<p class="prstsearch-pagination__info">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: current page, 2: number of pages, 3: number of entries. */
						__( 'Page %1$s of %2$s · %3$s entries', 'smart-search' ),
						number_format_i18n( $prstsearch_table['page'] ),
						number_format_i18n( $prstsearch_table['pages'] ),
						number_format_i18n( $prstsearch_table['total'] )
					)
				);
				?>
			</p>

			<?php if ( $prstsearch_table['page'] > 1 ) : ?>
				<a class="prstsearch-link" href="<?php echo esc_url( AdminPage::url( SettingsSchema::TAB_STATS, $prstsearch_table['page_args'] + array( 'paged' => $prstsearch_table['page'] - 1 ) ) ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'Previous', 'smart-search' ); ?></a>
			<?php endif; ?>
			<?php if ( $prstsearch_table['page'] < $prstsearch_table['pages'] ) : ?>
				<a class="prstsearch-link" href="<?php echo esc_url( AdminPage::url( SettingsSchema::TAB_STATS, $prstsearch_table['page_args'] + array( 'paged' => $prstsearch_table['page'] + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'smart-search' ); ?> <span aria-hidden="true">→</span></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</section>

<?php if ( $data['can_manage'] ) : ?>
	<?php
	// Deleting changes data, so it is a POST form. The button below refers to it by its id.
	?>
	<form id="prstsearch-clear-stats" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::CLEAR_STATS ); ?>" />
		<?php wp_nonce_field( FormActions::CLEAR_STATS ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
		<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_STATS ); ?>" />
		<?php wp_nonce_field( FormActions::SAVE ); ?>

		<section class="prstsearch-panel" aria-labelledby="prstsearch-recording-title">
			<h2 class="prstsearch-panel__title" id="prstsearch-recording-title"><?php esc_html_e( 'Recording', 'smart-search' ); ?></h2>
			<p class="prstsearch-panel__description"><?php esc_html_e( 'Choose what is stored for the statistics. IP addresses and users are never stored.', 'smart-search' ); ?></p>

			<div class="prstsearch-rows">
				<?php
				$fields->switch_row(
					'tracking_enabled',
					__( 'Search phrases', 'smart-search' ),
					__( 'Phrase, time, mode and number of results. Basis for the rankings and the table. Without it nothing is recorded.', 'smart-search' )
				);

				$fields->switch_row(
					'track_usage',
					__( 'AI usage', 'smart-search' ),
					__( 'Input and output tokens with costs per search.', 'smart-search' )
				);

				$fields->switch_row(
					'track_clicks',
					__( 'Clicks on results', 'smart-search' ),
					__( 'Which result was opened. Loads a small script on the front end.', 'smart-search' )
				);

				$fields->switch_row(
					'track_duration',
					__( 'Response time', 'smart-search' ),
					__( 'How long a search took.', 'smart-search' )
				);

				$fields->input_row(
					'retention_days',
					__( 'Retention in days', 'smart-search' ),
					__( 'Older entries are deleted automatically.', 'smart-search' ),
					array(
						'type'      => 'number',
						'min'       => 1,
						'max'       => 3650,
						'step'      => 1,
						'row_class' => 'prstsearch-row--narrow-input',
					)
				);
				?>
			</div>

			<div class="prstsearch-actions">
				<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
				<button
					type="submit"
					form="prstsearch-clear-stats"
					class="prstsearch-button prstsearch-button--danger-text"
					data-prstsearch-confirm="<?php esc_attr_e( 'All recorded searches are deleted permanently.', 'smart-search' ); ?>"
					data-prstsearch-confirm-title="<?php esc_attr_e( 'Delete the statistics?', 'smart-search' ); ?>"
					data-prstsearch-confirm-label="<?php esc_attr_e( 'Delete statistics', 'smart-search' ); ?>"
				>
					<?php esc_html_e( 'Delete statistics', 'smart-search' ); ?>
				</button>
			</div>
		</section>
	</form>
<?php endif; ?>
