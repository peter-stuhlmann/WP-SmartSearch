<?php
/**
 * Tab "Display": how the search is integrated into the site.
 *
 * @package PrstSearch
 *
 * @var \PrstSearch\Admin\Fields $fields
 */

use PrstSearch\Admin\FormActions;
use PrstSearch\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$prstsearch_integrations = array(
	'replace'   => array(
		'title'       => __( 'Replace the engine only', 'smart-search' ),
		'description' => __( 'Search field and results page still come from your theme. Nothing changes visually.', 'smart-search' ),
		'note'        => __( 'Default · no own CSS', 'smart-search' ),
		'icon'        => 'swap',
	),
	'block'     => array(
		'title'       => __( 'Own search block', 'smart-search' ),
		'description' => __( 'A block for search field and results. It takes font and colours from the theme.', 'smart-search' ),
		'note'        => __( 'Adjustable in the editor', 'smart-search' ),
		'icon'        => 'block',
	),
	'templates' => array(
		'title'       => __( 'Own templates', 'smart-search' ),
		'description' => __( 'For developers: override the output in the theme or use the interface only.', 'smart-search' ),
		'note'        => __( 'Full control in code', 'smart-search' ),
		'icon'        => 'code',
	),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( FormActions::SAVE ); ?>" />
	<input type="hidden" name="prstsearch_tab" value="<?php echo esc_attr( SettingsSchema::TAB_DISPLAY ); ?>" />
	<?php wp_nonce_field( FormActions::SAVE ); ?>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-integration-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-integration-title"><?php esc_html_e( 'Integration', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'Choose how the search appears on your website. By default the look stays as it is.', 'smart-search' ); ?></p>

		<?php $fields->cards( 'integration', __( 'Integration', 'smart-search' ), $prstsearch_integrations ); ?>

		<div class="prstsearch-guide prstsearch-guide--replace">
			<h3 class="prstsearch-guide__title"><?php esc_html_e( 'Your theme decides the look', 'smart-search' ); ?></h3>
			<p><?php esc_html_e( 'The plugin only delivers the results. Search field and results page keep using the templates of your theme.', 'smart-search' ); ?></p>
		</div>

		<div class="prstsearch-guide prstsearch-guide--block">
			<h3 class="prstsearch-guide__title"><?php esc_html_e( 'How to use the block', 'smart-search' ); ?></h3>
			<p><?php esc_html_e( 'Insert the block “Smart Search” in the editor wherever the search should appear. In classic content these shortcodes do the same:', 'smart-search' ); ?></p>
			<ul class="prstsearch-guide__list">
				<li><code>[prstsearch]</code> – <?php esc_html_e( 'search field with results and AI answer on the same page', 'smart-search' ); ?></li>
				<li><code>[prstsearch_form]</code> – <?php esc_html_e( 'search field only, results appear on the search page', 'smart-search' ); ?></li>
				<li><code>[prstsearch_results]</code>, <code>[prstsearch_answer]</code> – <?php esc_html_e( 'results and AI answer for a search page built in the editor', 'smart-search' ); ?></li>
			</ul>
		</div>

		<div class="prstsearch-guide prstsearch-guide--templates">
			<h3 class="prstsearch-guide__title"><?php esc_html_e( 'How to use your own templates', 'smart-search' ); ?></h3>
			<p><?php esc_html_e( 'Copy a template from the plugin folder “templates” into a folder “smart-search” in your theme and change it there:', 'smart-search' ); ?></p>
			<ul class="prstsearch-guide__list">
				<li><code>search-form.php</code>, <code>results.php</code>, <code>result.php</code>, <code>answer.php</code>, <code>answer-placeholder.php</code></li>
			</ul>
			<p><?php esc_html_e( 'In theme files these functions print the parts:', 'smart-search' ); ?></p>
			<ul class="prstsearch-guide__list">
				<li><code>prstsearch_form()</code>, <code>prstsearch_results()</code>, <code>prstsearch_answer()</code></li>
				<li><code>prstsearch_get_results()</code> – <?php esc_html_e( 'the raw results for completely custom markup', 'smart-search' ); ?></li>
			</ul>
			<p>
				<?php
				printf(
					/* translators: %s: REST route. */
					esc_html__( 'For apps and headless front ends the search is available at %s.', 'smart-search' ),
					'<code>' . esc_html( rest_url( 'prstsearch/v1/search?q=…' ) ) . '</code>'
				);
				?>
			</p>
		</div>
	</section>

	<section class="prstsearch-panel" aria-labelledby="prstsearch-output-title">
		<h2 class="prstsearch-panel__title" id="prstsearch-output-title"><?php esc_html_e( 'What is shown', 'smart-search' ); ?></h2>
		<p class="prstsearch-panel__description"><?php esc_html_e( 'These options apply to the AI answer, the block and the plugin’s templates.', 'smart-search' ); ?></p>

		<div class="prstsearch-rows">
			<?php
			$fields->switch_row(
				'type_filter',
				__( 'Offer a filter by content type', 'smart-search' ),
				__( 'Above the results visitors can choose, for example, pages only or posts only. Shown as soon as the results are of more than one type.', 'smart-search' )
			);

			$fields->switch_row(
				'jump_to_passage',
				__( 'Open results at the passage that was found', 'smart-search' ),
				__( 'The browser scrolls to the matching text and marks it. Browsers that cannot do this open the page at the top as usual.', 'smart-search' )
			);

			$fields->switch_row(
				'stream_answers',
				__( 'Show the AI answer while it is written', 'smart-search' ),
				__( 'The text appears word by word instead of all at once. It does not get faster, but visitors see sooner that something is happening. Some servers hold the text back; the answer then appears in one piece as before.', 'smart-search' )
			);

			$fields->switch_row(
				'auto_answer',
				__( 'Show the AI answer above the theme’s results', 'smart-search' ),
				__( 'Only for “Replace the engine only” and the search mode “AI answer”. The answer takes font and colours from the theme.', 'smart-search' )
			);

			$fields->switch_row(
				'show_sources',
				__( 'List sources below the AI answer', 'smart-search' ),
				__( 'Visitors see which pages the answer is based on.', 'smart-search' )
			);

			$fields->select_row(
				'visitor_address',
				__( 'Address visitors', 'smart-search' ),
				__( 'How the texts of the search speak to visitors – for example “Please try again” informally or formally. “Automatic” follows the language of the website: “Deutsch (Sie)” is formal. Also tells the AI answer how to address the reader.', 'smart-search' ),
				array(
					'auto'     => __( 'Automatic, by the language of the website', 'smart-search' ),
					'informal' => __( 'Informal (German “du”)', 'smart-search' ),
					'formal'   => __( 'Formal (German “Sie”)', 'smart-search' ),
				)
			);

			$fields->switch_row(
				'show_relevance',
				__( 'Show the relevance of each result', 'smart-search' ),
				__( 'A label such as “Very relevant” next to each result of the block.', 'smart-search' )
			);
			?>
		</div>

		<div class="prstsearch-actions">
			<button type="submit" class="prstsearch-button prstsearch-button--primary"><?php esc_html_e( 'Save changes', 'smart-search' ); ?></button>
		</div>
	</section>
</form>
