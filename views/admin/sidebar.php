<?php
/**
 * Sidebar: index status and AI provider.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\ViewModel $view
 * @var \PrstSearch\Admin\Format    $format
 * @var array{hero: array<string, mixed>} $data
 */

use PrstSearch\Admin\AdminPage;
use PrstSearch\Admin\Figure;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_sidebar  = $view->sidebar();
$prstsearch_overview = $data['hero']['overview'];
$prstsearch_provider = $prstsearch_sidebar['provider'];
?>
<section class="prstsearch-panel" aria-labelledby="prstsearch-index-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-index-title"><?php esc_html_e( 'Index status', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of indexed items, 2: number of searchable items. */
				__( '%1$s of %2$s items are in the index.', 'smart-search' ),
				number_format_i18n( $prstsearch_overview['indexed'] ),
				number_format_i18n( $prstsearch_overview['searchable'] )
			)
		);
		?>
	</p>

	<?php if ( array() !== $prstsearch_sidebar['types'] ) : ?>
		<div class="prstsearch-distribution" aria-hidden="true">
			<?php foreach ( $prstsearch_sidebar['types'] as $prstsearch_index => $prstsearch_type ) : ?>
				<span class="prstsearch-distribution__segment prstsearch-distribution__segment--<?php echo esc_attr( (string) min( 3, $prstsearch_index + 1 ) ); ?>" style="flex-grow: <?php echo esc_attr( (string) max( 1, $prstsearch_type['count'] ) ); ?>"></span>
			<?php endforeach; ?>
		</div>

		<ul class="prstsearch-legend">
			<?php foreach ( $prstsearch_sidebar['types'] as $prstsearch_index => $prstsearch_type ) : ?>
				<li class="prstsearch-legend__item">
					<span class="prstsearch-legend__dot prstsearch-distribution__segment--<?php echo esc_attr( (string) min( 3, $prstsearch_index + 1 ) ); ?>" aria-hidden="true"></span>
					<span class="prstsearch-legend__label"><?php echo esc_html( $prstsearch_type['label'] ); ?></span>
					<span class="prstsearch-legend__value"><?php echo esc_html( number_format_i18n( $prstsearch_type['count'] ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $prstsearch_overview['pending_embeddings'] > 0 ) : ?>
		<p class="prstsearch-panel__hint">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of text passages. */
					_n( '%s text passage is still waiting for the AI provider.', '%s text passages are still waiting for the AI provider.', $prstsearch_overview['pending_embeddings'], 'smart-search' ),
					number_format_i18n( $prstsearch_overview['pending_embeddings'] )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<div class="prstsearch-rebuild" data-prstsearch-rebuild>
		<button type="button" class="prstsearch-button prstsearch-button--secondary prstsearch-button--wide" data-prstsearch-rebuild-start>
			<?php esc_html_e( 'Rebuild index', 'smart-search' ); ?>
		</button>
		<progress class="prstsearch-rebuild__progress" max="100" value="0" aria-label="<?php esc_attr_e( 'Progress of the index rebuild', 'smart-search' ); ?>" hidden data-prstsearch-rebuild-progress></progress>
		<p class="prstsearch-rebuild__status" data-prstsearch-rebuild-status></p>
	</div>
</section>

<section class="prstsearch-panel" aria-labelledby="prstsearch-provider-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-provider-title"><?php esc_html_e( 'AI provider', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description"><?php esc_html_e( 'For semantic search and AI answers.', 'smart-search' ); ?></p>

	<div class="prstsearch-provider-badge">
		<span class="prstsearch-provider-badge__logo" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $prstsearch_provider['name'], 0, 1 ) ) ); ?></span>
		<div>
			<p class="prstsearch-provider-badge__name"><?php echo esc_html( $prstsearch_provider['name'] ); ?></p>
			<p class="prstsearch-provider-badge__state prstsearch-provider-badge__state--<?php echo $prstsearch_provider['is_ready'] ? 'ready' : 'missing'; ?>">
				<?php
				if ( ! $prstsearch_provider['is_used'] ) {
					esc_html_e( 'Not needed for keyword search', 'smart-search' );
				} elseif ( $prstsearch_provider['is_ready'] ) {
					esc_html_e( 'Configured', 'smart-search' );
				} else {
					esc_html_e( 'Not configured yet', 'smart-search' );
				}
				?>
			</p>
		</div>
	</div>

	<div class="prstsearch-budget">
		<p class="prstsearch-budget__line">
			<span><?php esc_html_e( 'Costs this month', 'smart-search' ); ?></span>
			<strong>
				<?php
				if ( $prstsearch_provider['budget'] > 0 ) {
					echo wp_kses_post(
						Figure::sentence(
							/* translators: 1: costs so far, 2: monthly budget. */
							__( '%1$s of %2$s', 'smart-search' ),
							$format->money( $prstsearch_provider['month_cost'] ),
							$format->money( $prstsearch_provider['budget'] )
						)->html()
					);
				} else {
					echo wp_kses_post( $format->money( $prstsearch_provider['month_cost'] )->html() );
				}
				?>
			</strong>
		</p>

		<?php if ( $prstsearch_provider['budget'] > 0 ) : ?>
			<progress class="prstsearch-budget__bar" max="1" value="<?php echo esc_attr( (string) round( $prstsearch_provider['budget_share'], 3 ) ); ?>" aria-label="<?php esc_attr_e( 'Share of the monthly budget used', 'smart-search' ); ?>"></progress>
		<?php endif; ?>

		<?php if ( $prstsearch_provider['is_exhausted'] ) : ?>
			<p class="prstsearch-panel__hint prstsearch-panel__hint--warning"><?php esc_html_e( 'The budget is used up. Until next month the search runs as a keyword search.', 'smart-search' ); ?></p>
		<?php endif; ?>
	</div>

	<a class="prstsearch-link" href="<?php echo esc_url( AdminPage::url( SettingsSchema::TAB_PROVIDER ) ); ?>">
		<?php esc_html_e( 'Manage provider', 'smart-search' ); ?> <span aria-hidden="true">→</span>
	</a>
</section>
