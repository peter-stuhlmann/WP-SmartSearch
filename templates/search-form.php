<?php
/**
 * Search form.
 *
 * Override by copying this file to smart-search/search-form.php in your theme.
 *
 * @package PrstSearch
 *
 * @var array{id: string, action: string, query: string, label: string, placeholder: string, button_label: string, suggestions: bool} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<form role="search" method="get" class="prstsearch-form" action="<?php echo esc_url( $args['action'] ); ?>" data-prstsearch-form>
	<label class="prstsearch-form__label" for="<?php echo esc_attr( $args['id'] ); ?>">
		<?php echo esc_html( $args['label'] ); ?>
	</label>

	<div class="prstsearch-form__row">
		<div class="prstsearch-form__field"<?php echo $args['suggestions'] ? ' data-prstsearch-combobox' : ''; ?>>
			<input
				class="prstsearch-form__input"
				type="search"
				id="<?php echo esc_attr( $args['id'] ); ?>"
				name="s"
				value="<?php echo esc_attr( $args['query'] ); ?>"
				placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				autocomplete="off"
				required
			/>
			<?php if ( $args['suggestions'] ) : ?>
				<ul class="prstsearch-form__suggestions" id="<?php echo esc_attr( $args['id'] ); ?>-suggestions" role="listbox" aria-label="<?php esc_attr_e( 'Suggestions', 'smart-search' ); ?>" hidden></ul>
			<?php endif; ?>
		</div>

		<button type="submit" class="prstsearch-form__submit wp-element-button">
			<?php echo esc_html( $args['button_label'] ); ?>
		</button>
	</div>

	<?php if ( $args['suggestions'] ) : ?>
		<p class="screen-reader-text" role="status" data-prstsearch-suggestion-status></p>
	<?php endif; ?>
</form>
