<?php
/**
 * Tab "Content": what is searched.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\ViewModel $view
 * @var \PrstSearch\Admin\Fields    $fields
 */

use PrstSearch\Admin\FormActions;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_excluded = $view->excluded_posts();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
	<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_CONTENT ); ?>" />
	<?php wp_nonce_field( FormActions::SAVE ); ?>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-types-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-types-title"><?php esc_html_e( 'Content types', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Choose what the search covers. Every public content type of this website is listed, including those of plugins.', 'smart-search' ); ?></p>

		<?php $fields->cards( 'post_types', __( 'Content types', 'smart-search' ), $view->post_type_cards(), true, $view->enabled_post_types() ); ?>

		<div class="prstsearch-rows prstsearch-rows--after-cards">
			<?php
			$fields->switch_row(
				'auto_post_types',
				__( 'Search new content types automatically', 'smart-search' ),
				__( 'Content types that a plugin or theme adds later, such as products, books or news, are searched without you having to tick them here.', 'smart-search' )
			);
			?>
		</div>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-fields-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-fields-title"><?php esc_html_e( 'Additional fields', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'What is searched besides title and content.', 'smart-search' ); ?></p>

		<div class="prstsearch-rows">
			<?php
			$fields->switch_row(
				'index_comments',
				__( 'Comments', 'smart-search' ),
				__( 'Approved comments are searched with the content they belong to. The result is that content, not the single comment. AI answers can quote them too, so switch this off if comments appear without being checked.', 'smart-search' )
			);

			$fields->switch_row(
				'index_terms',
				__( 'Categories and tags', 'smart-search' ),
				__( 'Assigned terms are searched as well.', 'smart-search' )
			);

			$fields->input_row(
				'custom_fields',
				__( 'Custom fields', 'smart-search' ),
				__( 'Field names separated by commas, for example from ACF.', 'smart-search' ),
				array( 'placeholder' => __( 'subtitle, summary', 'smart-search' ) )
			);

			$fields->switch_row(
				'index_excerpt',
				__( 'Search the excerpt', 'smart-search' ),
				__( 'The manual excerpt counts in addition to the content.', 'smart-search' )
			);

			$fields->switch_row(
				'fetch_fallback',
				__( 'Read the public page if the content is empty', 'smart-search' ),
				__( 'For page builders that only output their content on the finished page. Slower, so only used when little text was found.', 'smart-search' )
			);
			?>
		</div>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
			<p class="prstsearch-actions__note"><?php esc_html_e( 'After a change the index is updated in the background.', 'smart-search' ); ?></p>
		</div>
	</section>
</form>

<section class="prstsearch-panel" aria-labelledby="prstsearch-excluded-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-excluded-title"><?php esc_html_e( 'Excluded content', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description"><?php esc_html_e( 'This content does not appear in the search. You can exclude content directly in the editor.', 'smart-search' ); ?></p>

	<?php if ( array() === $prstsearch_excluded ) : ?>
		<p class="prstsearch-empty"><?php esc_html_e( 'Nothing is excluded.', 'smart-search' ); ?></p>
	<?php else : ?>
		<ul class="prstsearch-rows">
			<?php foreach ( $prstsearch_excluded as $prstsearch_post ) : ?>
				<li class="prstsearch-row">
					<div class="prstsearch-row__text">
						<a class="prstsearch-row__label" href="<?php echo esc_url( $prstsearch_post['edit_url'] ); ?>"><?php echo esc_html( $prstsearch_post['title'] ); ?></a>
						<p class="prstsearch-row__description"><?php echo esc_html( $prstsearch_post['type'] ); ?></p>
					</div>
					<?php
					// Changing data is a POST request, so it cannot be triggered by following a link.
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::INCLUDE_POST ); ?>" />
						<input type="hidden" name="post" value="<?php echo esc_attr( (string) $prstsearch_post['id'] ); ?>" />
						<?php wp_nonce_field( FormActions::INCLUDE_POST ); ?>
						<button type="submit" class="prstsearch-button prstsearch-button--neutral">
							<?php esc_html_e( 'Include again', 'smart-search' ); ?>
							<span class="screen-reader-text">: <?php echo esc_html( $prstsearch_post['title'] ); ?></span>
						</button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
