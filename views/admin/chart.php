<?php
/**
 * A chart with legend and table alternative.
 *
 * @package PrstSearch
 *
 * @var array{id: string, title: string, note: string, chart: array{svg: string, headers: string[], rows: array<int, string[]>, legend?: array<string, string>}} $data
 */

defined( 'ABSPATH' ) || exit;

$prstsearch_chart = $data['chart'];
?>
<section class="prstsearch-panel prstsearch-chart" aria-labelledby="<?php echo esc_attr( $data['id'] ); ?>">
	<div class="prstsearch-chart__head">
		<h2 class="prstsearch-panel__title" id="<?php echo esc_attr( $data['id'] ); ?>"><?php echo esc_html( $data['title'] ); ?></h2>

		<?php if ( isset( $prstsearch_chart['legend'] ) ) : ?>
			<ul class="prstsearch-chart__legend">
				<?php foreach ( $prstsearch_chart['legend'] as $prstsearch_slug => $prstsearch_name ) : ?>
					<li><span class="prstsearch-chart__swatch prstsearch-chart__swatch--<?php echo esc_attr( $prstsearch_slug ); ?>" aria-hidden="true"></span><?php echo esc_html( $prstsearch_name ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<?php echo $prstsearch_chart['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built and escaped by BarChart. ?>

	<?php if ( '' !== $data['note'] ) : ?>
		<p class="prstsearch-chart__note"><?php echo esc_html( $data['note'] ); ?></p>
	<?php endif; ?>

	<details class="prstsearch-chart__data">
		<summary><?php esc_html_e( 'Show as table', 'smart-search' ); ?></summary>
		<?php /* translators: %s: title of a chart. */ ?>
		<div class="prstsearch-table-wrap" tabindex="0" role="region" aria-label="<?php echo esc_attr( sprintf( __( '%s, table', 'smart-search' ), $data['title'] ) ); ?>">
			<table class="prstsearch-table">
				<thead>
					<tr>
						<?php foreach ( $prstsearch_chart['headers'] as $prstsearch_index => $prstsearch_header ) : ?>
							<th scope="col" class="<?php echo $prstsearch_index > 0 ? 'prstsearch-table__number' : ''; ?>"><?php echo esc_html( $prstsearch_header ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $prstsearch_chart['rows'] as $prstsearch_row ) : ?>
						<tr>
							<?php foreach ( $prstsearch_row as $prstsearch_index => $prstsearch_cell ) : ?>
								<?php if ( 0 === $prstsearch_index ) : ?>
									<th scope="row"><?php echo esc_html( $prstsearch_cell ); ?></th>
								<?php else : ?>
									<td class="prstsearch-table__number"><?php echo esc_html( $prstsearch_cell ); ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</details>
</section>
