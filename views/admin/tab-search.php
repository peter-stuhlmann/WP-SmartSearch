<?php
/**
 * Tab "Search": mode, test field, behaviour.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\Fields $fields
 * @var array{recommendations: array<int, array{index: string, terms: string, page: string}>} $data
 */

use PrstSearch\Admin\FormActions;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_modes = array(
	'keyword'  => array(
		'title'       => __( 'Keyword', 'smart-search' ),
		'description' => __( 'Fast full-text search on your own index. Runs without external services.', 'smart-search' ),
		'note'        => __( 'Free · no API key', 'smart-search' ),
		'icon'        => 'search',
	),
	'semantic' => array(
		'title'       => __( 'Semantic', 'smart-search' ),
		'description' => __( 'Also finds content with the same meaning, even if the search word does not appear.', 'smart-search' ),
		'note'        => __( 'API key needed · very low cost', 'smart-search' ),
		'icon'        => 'semantic',
	),
	'ai'       => array(
		'title'       => __( 'AI answer', 'smart-search' ),
		'description' => __( 'Writes an answer from the results and links the sources.', 'smart-search' ),
		'note'        => __( 'API key needed · cost per search', 'smart-search' ),
		'icon'        => 'sparkle',
	),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
	<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_SEARCH ); ?>" />
	<?php wp_nonce_field( FormActions::SAVE ); ?>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-mode-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-mode-title"><?php esc_html_e( 'Search mode', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Choose how visitors get their results. You can switch at any time.', 'smart-search' ); ?></p>

		<?php $fields->cards( 'mode', __( 'Search mode', 'smart-search' ), $prstsearch_modes ); ?>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-behaviour-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-behaviour-title"><?php esc_html_e( 'Behaviour', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Fine-tuning for the search on your website.', 'smart-search' ); ?></p>

		<div class="prstsearch-rows">
			<?php
			$fields->switch_row(
				'replace_wp_search',
				__( 'Replace the default WordPress search', 'smart-search' ),
				__( 'The search field of your theme then uses this index automatically.', 'smart-search' )
			);

			$fields->input_row(
				'results_per_page',
				__( 'Results per page', 'smart-search' ),
				__( 'How many results are shown at once.', 'smart-search' ),
				array(
					'type'      => 'number',
					'min'       => 1,
					'max'       => 100,
					'step'      => 1,
					'row_class' => 'prstsearch-row--narrow-input',
				)
			);

			$fields->input_row(
				'max_results',
				__( 'Results per search', 'smart-search' ),
				__( 'How many results a search returns at most, over all pages. Every one of them is read and judged, so a higher number makes searches for common words slower.', 'smart-search' ),
				array(
					'type'      => 'number',
					'min'       => 10,
					'max'       => 2000,
					'step'      => 10,
					'row_class' => 'prstsearch-row--narrow-input',
				)
			);

			$fields->switch_row(
				'match_inside_words',
				__( 'Find text inside words', 'smart-search' ),
				__( 'If nothing contains the search words as words, pages are shown that contain them anywhere, as “hof” in “Bahnhofstraße” or “234” in “AB-12345”. This reads the whole index, as the default WordPress search always does.', 'smart-search' )
			);

			$fields->switch_row(
				'boost_title',
				__( 'Give titles more weight', 'smart-search' ),
				__( 'Matches in the title rank higher than matches in the text.', 'smart-search' )
			);

			$fields->switch_row(
				'ai_judge',
				__( 'Ask the language model when the search is unsure', 'smart-search' ),
				__( 'If no result is a clear match, the model for the AI answer picks the fitting pages from the nearest ones. This finds “poultry” although the page only says “chicken”. Needs the mode “Semantic” or “AI answer” and costs one small request per such search.', 'smart-search' )
			);

			$fields->switch_row(
				'hide_weak_results',
				__( 'Hide weak matches', 'smart-search' ),
				__( 'Results with the lowest relevance level are not shown.', 'smart-search' )
			);

			$fields->switch_row(
				'suggestions',
				__( 'Suggestions while typing', 'smart-search' ),
				__( 'The search block suggests matching pages below the field.', 'smart-search' )
			);
			?>
		</div>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
		</div>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-recommend-title" id="prstsearch-recommend">
		<h2 class="prstsearch-panel__title" id="prstsearch-recommend-title"><?php esc_html_e( 'Recommended results', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Name the page that answers a search, even if it does not use the words of the search. It is then listed first. The statistics show which searches found nothing.', 'smart-search' ); ?></p>

		<ul class="prstsearch-rules" data-prstsearch-rules>
			<?php
			foreach ( $data['recommendations'] as $prstsearch_row ) {
				$this->include_view( 'recommendation-row', $prstsearch_row );
			}
			?>
		</ul>

		<p class="prstsearch-rules__hint" id="prstsearch-rules-hint"><?php esc_html_e( 'Separate several search terms with commas. A term applies in every word form, and to longer searches that contain it. For the page, type part of its title and choose it from the list; the number after “#” identifies it.', 'smart-search' ); ?></p>

		<?php
		// What the script adds as a further row.
		?>
		<template data-prstsearch-rule-template>
			<?php
			$this->include_view(
				'recommendation-row',
				array(
					'index' => '__INDEX__',
					'terms' => '',
					'page'  => '',
				)
			);
			?>
		</template>
		<datalist id="prstsearch-rule-pages"></datalist>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
			<button type="button" class="prstsearch-button prstsearch-button--secondary" data-prstsearch-rule-add hidden><?php esc_html_e( 'Add a recommendation', 'smart-search' ); ?></button>
		</div>
	</section>
</form>

<section class="prstsearch-panel" aria-labelledby="prstsearch-test-title">
	<h2 class="prstsearch-panel__title" id="prstsearch-test-title"><?php esc_html_e( 'Try the search', 'smart-search' ); ?></h2>
	<p class="prstsearch-panel__description"><?php esc_html_e( 'Test a query with the saved settings. Below every result you see why it was found.', 'smart-search' ); ?></p>

	<form class="prstsearch-test" role="search" data-prstsearch-test>
		<label class="screen-reader-text" for="prstsearch-test-input"><?php esc_html_e( 'Test query', 'smart-search' ); ?></label>
		<div class="prstsearch-test__field">
			<input class="prstsearch-input prstsearch-input--large" type="search" id="prstsearch-test-input" placeholder="<?php esc_attr_e( 'For example: shipping costs abroad', 'smart-search' ); ?>" required />
			<button type="submit" class="prstsearch-button prstsearch-button--secondary"><?php esc_html_e( 'Search', 'smart-search' ); ?></button>
		</div>
		<p class="prstsearch-test__status" role="status" data-prstsearch-test-status></p>
		<ol class="prstsearch-test__results" data-prstsearch-test-results></ol>
	</form>
</section>
