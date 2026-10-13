<?php
/**
 * One rule in the list of recommended results.
 *
 * @package PrstSearch
 *
 * @var array{index: string, terms: string, page: string} $data
 */

defined( 'ABSPATH' ) || exit;

$prstsearch_name = 'prstsearch_recommendations[' . $data['index'] . ']';
$prstsearch_id   = 'prstsearch-rule-' . $data['index'];
?>
<li class="prstsearch-rule" data-prstsearch-rule>
	<div class="prstsearch-rule__field">
		<label class="prstsearch-rule__label" for="<?php echo esc_attr( $prstsearch_id ); ?>-terms"><?php esc_html_e( 'When someone searches for', 'smart-search' ); ?></label>
		<input
			class="prstsearch-input"
			type="text"
			id="<?php echo esc_attr( $prstsearch_id ); ?>-terms"
			name="<?php echo esc_attr( $prstsearch_name ); ?>[terms]"
			value="<?php echo esc_attr( $data['terms'] ); ?>"
			placeholder="<?php esc_attr_e( 'opening hours, open, when open', 'smart-search' ); ?>"
			aria-describedby="prstsearch-rules-hint"
		/>
	</div>

	<div class="prstsearch-rule__field">
		<label class="prstsearch-rule__label" for="<?php echo esc_attr( $prstsearch_id ); ?>-page"><?php esc_html_e( 'show this page first', 'smart-search' ); ?></label>
		<input
			class="prstsearch-input"
			type="text"
			id="<?php echo esc_attr( $prstsearch_id ); ?>-page"
			name="<?php echo esc_attr( $prstsearch_name ); ?>[page]"
			value="<?php echo esc_attr( $data['page'] ); ?>"
			list="prstsearch-rule-pages"
			autocomplete="off"
			placeholder="<?php esc_attr_e( 'Type part of the title', 'smart-search' ); ?>"
			aria-describedby="prstsearch-rules-hint"
			data-prstsearch-rule-page
		/>
	</div>

	<button type="button" class="prstsearch-button prstsearch-button--danger-text" data-prstsearch-rule-remove>
		<?php esc_html_e( 'Remove', 'smart-search' ); ?>
		<span class="screen-reader-text"><?php esc_html_e( 'this recommendation', 'smart-search' ); ?></span>
	</button>
</li>
