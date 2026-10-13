<?php
/**
 * Renders search results and answers.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Database\ChunkStore;
use PrstSearch\Search\Answer;
use PrstSearch\Search\Excerpt;
use PrstSearch\Search\SearchHit;
use PrstSearch\Search\SearchResults;
use PrstSearch\Settings\Settings;
use WeakMap;

/**
 * Prepares view data and passes it to the templates.
 *
 * The REST API and server-side rendering both go through this class, so
 * there is exactly one place that defines what a result looks like.
 */
final class ResultRenderer {

	/**
	 * Creates the renderer.
	 */
	public function __construct(
		private readonly TemplateLoader $templates,
		private readonly ChunkStore $chunks,
		private readonly Settings $settings
	) {
		$this->items = new WeakMap();
	}

	/**
	 * View data of the result pages rendered in this request.
	 *
	 * Kept by the page object itself and gone with it. An object ID would not
	 * do as a key: PHP hands the ID of a discarded object to the next one, which
	 * would then be shown the results of another search.
	 *
	 * @var WeakMap<SearchResults, array<int, array<string, mixed>>>
	 */
	private WeakMap $items;

	/**
	 * Returns the view data of all hits on a result page.
	 *
	 * @return array<int, array{id: int, title: string, url: string, type: string, excerpt: string, relevance: string, relevance_label: string, score: float, explanation: string}>
	 */
	public function items( SearchResults $results ): array {
		// Data and markup of a page are often needed together; the work is done once.
		$this->items[ $results ] ??= $this->build_items( $results );

		return $this->items[ $results ];
	}

	/**
	 * Returns the sentence that says how many results were found.
	 */
	public function summary( SearchResults $results ): string {
		if ( 0 === $results->total ) {
			/* translators: %s: search phrase. */
			return sprintf( __( 'No results for “%s”.', 'smart-search' ), $results->query );
		}

		return sprintf(
			/* translators: 1: number of results, 2: search phrase. */
			_n( '%1$s result for “%2$s”', '%1$s results for “%2$s”', $results->total, 'smart-search' ),
			number_format_i18n( $results->total ),
			$results->query
		);
	}

	/**
	 * Builds the view data of all hits on a result page.
	 *
	 * @return array<int, array{id: int, title: string, url: string, type: string, excerpt: string, relevance: string, relevance_label: string, score: float, explanation: string}>
	 */
	private function build_items( SearchResults $results ): array {
		$terms  = $results->ranking->terms->all();
		$chunks = $this->chunks->find( array_map( static fn ( SearchHit $hit ): int => $hit->chunk_id, $results->hits ) );
		$items  = array();

		_prime_post_caches( $results->post_ids(), false, false );

		foreach ( $results->hits as $hit ) {
			$post = get_post( $hit->post_id );

			if ( null === $post ) {
				continue;
			}

			$type    = get_post_type_object( $post->post_type );
			$content = $chunks[ $hit->chunk_id ]['content'] ?? '';
			$passage = Excerpt::around( $content, $terms );

			$items[] = array(
				'id'              => $post->ID,
				'title'           => get_the_title( $post ),
				'url'             => esc_url_raw( $this->url_of_passage( (string) get_permalink( $post ), Excerpt::fragment( $content, $terms, ! $hit->by_keyword ) ) ),
				'type'            => null === $type ? '' : $type->labels->singular_name,
				'excerpt'         => Excerpt::highlight( $passage, $terms ),
				// A recommendation says more than a level: somebody chose this page for this search.
				'relevance'       => $hit->is_recommended ? 'recommended' : $hit->relevance->value,
				'relevance_label' => $hit->is_recommended ? __( 'Recommended', 'smart-search' ) : $hit->relevance->label(),
				'score'           => round( $hit->score, 2 ),
				'explanation'     => $hit->explanation(),
			);
		}

		return $items;
	}

	/**
	 * Extends the address of a page so that the browser opens it at a passage.
	 *
	 * A "text fragment": browsers that know it scroll to the words and mark
	 * them, the others ignore it. If the words are not on the page any more,
	 * the page simply opens at the top.
	 *
	 * @param string $url   Address of the page.
	 * @param string $words A few consecutive words of the passage.
	 */
	private function url_of_passage( string $url, string $words ): string {
		if ( '' === $words || ! $this->settings->bool( 'jump_to_passage' ) || str_contains( $url, '#' ) ) {
			return $url;
		}

		// A hyphen separates the parts of a text fragment, so one in the words has to be encoded as well.
		return $url . '#:~:text=' . str_replace( '-', '%2D', rawurlencode( $words ) );
	}

	/**
	 * Returns the choices of the filter by content type, or nothing if there is no choice to make.
	 *
	 * @return array<int, array{type: string, label: string, count: int, url: string, is_current: bool}> "All" first, then the types by number of results.
	 */
	public function types( SearchResults $results ): array {
		if ( ! $this->settings->bool( 'type_filter' ) ) {
			return array();
		}

		// Counted over all results of the query, not only those of the type shown.
		$counts = array_count_values(
			array_filter( array_map( static fn ( SearchHit $hit ): string => (string) get_post_type( $hit->post_id ), $results->ranking->hits ) )
		);

		if ( count( $counts ) < 2 ) {
			return array();
		}

		arsort( $counts );

		$current = 1 === count( $results->post_types ) ? $results->post_types[0] : '';
		$types   = array( $this->type_choice( '', __( 'All', 'smart-search' ), array_sum( $counts ), $results->query, $current ) );

		foreach ( $counts as $post_type => $count ) {
			$object  = get_post_type_object( (string) $post_type );
			$types[] = $this->type_choice( (string) $post_type, null === $object ? (string) $post_type : $object->labels->name, $count, $results->query, $current );
		}

		return $types;
	}

	/**
	 * Builds one choice of the filter by content type.
	 *
	 * @return array{type: string, label: string, count: int, url: string, is_current: bool}
	 */
	private function type_choice( string $post_type, string $label, int $count, string $query, string $current ): array {
		return array(
			'type'       => $post_type,
			'label'      => $label,
			'count'      => $count,
			// The address of the ordinary search page, so the filter works without JavaScript too.
			'url'        => add_query_arg(
				array_filter(
					array(
						's'         => rawurlencode( $query ),
						'post_type' => $post_type,
					)
				),
				home_url( '/' )
			),
			'is_current' => $post_type === $current,
		);
	}

	/**
	 * Renders the filter by content type, or nothing if there is no choice to make.
	 */
	public function render_types( SearchResults $results ): string {
		$types = $this->types( $results );

		return array() === $types ? '' : $this->templates->render( 'type-filter', array( 'types' => $types ) );
	}

	/**
	 * Renders the hits of a result page as list items.
	 */
	public function render_items( SearchResults $results ): string {
		$html = '';

		foreach ( $this->items( $results ) as $item ) {
			$html .= $this->templates->render(
				'result',
				array(
					'item'           => $item,
					'show_relevance' => $this->settings->bool( 'show_relevance' ),
				)
			);
		}

		return $html;
	}

	/**
	 * Renders a result list with its summary line.
	 */
	public function render_results( SearchResults $results ): string {
		return $this->templates->render(
			'results',
			array(
				'results'    => $results,
				'summary'    => $this->summary( $results ),
				'types_html' => $this->render_types( $results ),
				'items_html' => $this->render_items( $results ),
				'has_more'   => $results->page < $results->page_count(),
			)
		);
	}

	/**
	 * Renders the region that the answer is loaded into.
	 */
	public function render_answer_placeholder( string $query, string $log_token ): string {
		return $this->templates->render(
			'answer-placeholder',
			array(
				'query'     => $query,
				'log_token' => $log_token,
			)
		);
	}

	/**
	 * Renders a generated answer.
	 */
	public function render_answer( Answer $answer ): string {
		$sources = array();

		foreach ( $answer->source_ids as $position => $post_id ) {
			$sources[ $position + 1 ] = array(
				'title' => get_the_title( $post_id ),
				'url'   => (string) get_permalink( $post_id ),
			);
		}

		return $this->templates->render(
			'answer',
			array(
				'paragraphs'   => $this->answer_paragraphs( $answer->text, $sources ),
				'sources'      => $sources,
				'show_sources' => $this->settings->bool( 'show_sources' ),
			)
		);
	}

	/**
	 * Splits the answer into paragraphs of safe HTML and links the citations.
	 *
	 * @param array<int, array{title: string, url: string}> $sources Sources by citation number.
	 * @return string[] Safe HTML per paragraph.
	 */
	private function answer_paragraphs( string $text, array $sources ): array {
		$paragraphs = preg_split( '/\R{2,}|\R/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return array_map(
			static function ( string $paragraph ) use ( $sources ): string {
				return (string) preg_replace_callback(
					'/\[(\d+)\]/',
					static function ( array $citation ) use ( $sources ): string {
						$source = $sources[ (int) $citation[1] ] ?? null;

						if ( null === $source ) {
							return $citation[0];
						}

						return sprintf(
							'<a class="prstsearch-answer__cite" href="%1$s" aria-label="%2$s">[%3$d]</a>',
							esc_url( $source['url'] ),
							/* translators: 1: citation number, 2: page title. */
							esc_attr( sprintf( __( 'Source %1$d: %2$s', 'smart-search' ), (int) $citation[1], $source['title'] ) ),
							(int) $citation[1]
						);
					},
					esc_html( $paragraph )
				);
			},
			$paragraphs ? $paragraphs : array()
		);
	}
}
