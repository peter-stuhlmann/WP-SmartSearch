<?php
/**
 * A single search result.
 *
 * Override by copying this file to smart-search/result.php in your theme.
 *
 * @package PrstSearch
 *
 * @var array{item: array{id: int, title: string, url: string, type: string, excerpt: string, relevance: string, relevance_label: string, score: float, explanation: string}, show_relevance: bool} $args
 */

defined( 'ABSPATH' ) || exit;

$prstsearch_item = $args['item'];
?>
<li class="prstsearch-result">
	<h3 class="prstsearch-result__title">
		<a href="<?php echo esc_url( $prstsearch_item['url'] ); ?>"><?php echo esc_html( $prstsearch_item['title'] ); ?></a>
	</h3>

	<p class="prstsearch-result__meta">
		<?php if ( '' !== $prstsearch_item['type'] ) : ?>
			<span class="prstsearch-result__type"><?php echo esc_html( $prstsearch_item['type'] ); ?></span>
		<?php endif; ?>

		<?php if ( $args['show_relevance'] || 'recommended' === $prstsearch_item['relevance'] ) : ?>
			<span class="prstsearch-result__relevance prstsearch-result__relevance--<?php echo esc_attr( $prstsearch_item['relevance'] ); ?>">
				<?php echo esc_html( $prstsearch_item['relevance_label'] ); ?>
			</span>
		<?php endif; ?>
	</p>

	<?php if ( '' !== $prstsearch_item['excerpt'] ) : ?>
		<p class="prstsearch-result__excerpt">
			<?php echo wp_kses( $prstsearch_item['excerpt'], array( 'mark' => array() ) ); ?>
		</p>
	<?php endif; ?>
</li>
