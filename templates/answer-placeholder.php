<?php
/**
 * Region the AI answer is loaded into.
 *
 * The answer is requested by JavaScript after the page has rendered, so the
 * results never wait for the AI. Without JavaScript the region stays hidden.
 *
 * Override by copying this file to smart-search/answer-placeholder.php in your theme.
 *
 * @package PrstSearch
 *
 * @var array{query: string, log_token: string} $args
 */

defined( 'ABSPATH' ) || exit;

$prstsearch_heading_id = wp_unique_id( 'prstsearch-answer-' );
?>
<section
	class="prstsearch-answer"
	aria-labelledby="<?php echo esc_attr( $prstsearch_heading_id ); ?>"
	data-prstsearch-answer
	data-query="<?php echo esc_attr( $args['query'] ); ?>"
	data-token="<?php echo esc_attr( $args['log_token'] ); ?>"
	hidden
>
	<h2 class="prstsearch-answer__title" id="<?php echo esc_attr( $prstsearch_heading_id ); ?>">
		<?php esc_html_e( 'AI answer', 'smart-search' ); ?>
	</h2>

	<div class="prstsearch-answer__body" aria-live="polite" aria-busy="true" data-prstsearch-answer-body>
		<p class="prstsearch-answer__loading"><?php esc_html_e( 'Writing an answer …', 'smart-search' ); ?></p>
	</div>
</section>
