<?php
/**
 * Contextual help of the settings page.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Admin;

/**
 * Fills the "Help" panel WordPress offers at the top of every admin screen.
 */
final class HelpTabs {

	/**
	 * Adds the help tabs to the current screen.
	 */
	public function add(): void {
		$screen = get_current_screen();

		if ( null === $screen ) {
			return;
		}

		foreach ( $this->tabs() as $id => $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'prstsearch-' . $id,
					'title'   => $tab['title'],
					'content' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $tab['paragraphs'] ) ) . '</p>',
				)
			);
		}
	}

	/**
	 * Returns the help texts.
	 *
	 * @return array<string, array{title: string, paragraphs: string[]}>
	 */
	private function tabs(): array {
		return array(
			'overview' => array(
				'title'      => __( 'How it works', 'smart-search' ),
				'paragraphs' => array(
					__( 'Smart Search keeps its own index of your content. Whenever you save something, the index is updated, so the search always knows the current version.', 'smart-search' ),
					__( 'The index is built from the finished page as visitors see it. That is why it works with every editor and page builder.', 'smart-search' ),
				),
			),
			'modes'    => array(
				'title'      => __( 'Search modes', 'smart-search' ),
				'paragraphs' => array(
					__( 'Keyword: finds content that contains the words searched for. Fast, free and without any external service.', 'smart-search' ),
					__( 'Semantic: additionally finds content with the same meaning, even if other words are used. Needs an AI provider and costs very little.', 'smart-search' ),
					__( 'AI answer: additionally writes a short answer from the best results and names its sources. Costs a small amount per search.', 'smart-search' ),
					__( 'If the AI provider is unreachable or the monthly budget is used up, the search continues as a keyword search. Visitors always get results.', 'smart-search' ),
				),
			),
			'status'   => array(
				'title'      => __( 'Index status', 'smart-search' ),
				'paragraphs' => array(
					__( 'Indexed (green): the search finds the current version.', 'smart-search' ),
					__( 'Outdated (yellow): the content changed and is being updated in the background. Until then the previous version is found.', 'smart-search' ),
					__( 'Error (red): the content could not be read. The Tools tab lists the reason.', 'smart-search' ),
					__( 'Excluded (grey): the content does not appear in the search, for example drafts, password-protected pages or pages you excluded in the editor.', 'smart-search' ),
				),
			),
			'privacy'  => array(
				'title'      => __( 'Privacy', 'smart-search' ),
				'paragraphs' => array(
					__( 'The statistics store the search phrase, the time, the mode, the number of results and, if enabled, tokens, costs, response time and the clicked result. IP addresses and user accounts are never stored.', 'smart-search' ),
					__( 'With semantic search or AI answers, search phrases and excerpts of your public content are sent to the AI provider you chose.', 'smart-search' ),
				),
			),
		);
	}
}
