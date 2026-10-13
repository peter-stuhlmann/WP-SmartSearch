<?php
/**
 * Result list with summary.
 *
 * Override by copying this file to smart-search/results.php in your theme.
 *
 * @package PrstSearch
 *
 * @var array{results: \PrstSearch\Search\SearchResults, summary: string, types_html: string, items_html: string, has_more: bool, more_url: string} $args
 */

defined( 'ABSPATH' ) || exit;

$prstsearch_results = $args['results'];
?>
<div class="prstsearch-results">
	<h2 class="screen-reader-text"><?php esc_html_e( 'Search results', 'smart-search' ); ?></h2>

	<p class="prstsearch-results__summary"><?php echo esc_html( $args['summary'] ); ?></p>

	<?php if ( '' !== $prstsearch_results->ranking->corrected_query ) : ?>
		<p class="prstsearch-results__correction">
			<?php
			/* translators: %s: corrected search phrase. */
			echo esc_html( sprintf( __( 'Nothing was found for your spelling. Showing results for “%s”.', 'smart-search' ), $prstsearch_results->ranking->corrected_query ) );
			?>
		</p>
	<?php endif; ?>

	<?php echo $args['types_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by the filter template. ?>

	<?php if ( 0 === $prstsearch_results->total ) : ?>
		<p class="prstsearch-results__hint">
			<?php esc_html_e( 'Try fewer or more general words, or check the spelling.', 'smart-search' ); ?>
		</p>
	<?php else : ?>
		<ol class="prstsearch-results__list" data-prstsearch-list>
			<?php echo $args['items_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by the result template. ?>
		</ol>

		<?php if ( $args['has_more'] ) : ?>
			<?php
			// A link to the next page of the search. Inside a live search the script loads that page into this list instead.
			?>
			<a class="prstsearch-results__more wp-element-button" href="<?php echo esc_url( $args['more_url'] ); ?>" data-prstsearch-more data-page="<?php echo esc_attr( (string) ( $prstsearch_results->page + 1 ) ); ?>">
				<?php esc_html_e( 'Show more results', 'smart-search' ); ?>
			</a>
		<?php endif; ?>
	<?php endif; ?>
</div>
