<?php
/**
 * Block and shortcodes.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

use PrstSearch\Contracts\Hookable;

/**
 * Makes the search available in the editor and in classic content.
 */
final class Blocks implements Hookable {

	/**
	 * Creates the service.
	 *
	 * @param SearchUi $ui        Renders the search.
	 * @param string   $block_dir Directory containing the built block.json.
	 */
	public function __construct(
		private readonly SearchUi $ui,
		private readonly string $block_dir
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );

		add_shortcode( 'prstsearch', array( $this, 'render_search_shortcode' ) );
		add_shortcode( 'prstsearch_form', array( $this, 'render_form_shortcode' ) );
		add_shortcode( 'prstsearch_results', array( $this->ui, 'results' ) );
		add_shortcode( 'prstsearch_answer', array( $this->ui, 'answer' ) );
	}

	/**
	 * Registers the search block.
	 */
	public function register_block(): void {
		if ( ! is_readable( $this->block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$this->block_dir,
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * Renders the search block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_block( array $attributes ): string {
		$options = array(
			'label'        => (string) ( $attributes['label'] ?? '' ),
			'placeholder'  => (string) ( $attributes['placeholder'] ?? '' ),
			'button_label' => (string) ( $attributes['buttonLabel'] ?? '' ),
			'show_answer'  => (bool) ( $attributes['showAnswer'] ?? true ),
		);

		$html = empty( $attributes['showResults'] ) ? $this->ui->form( $options ) : $this->ui->live_search( $options );

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Renders [prstsearch]: form, answer and live results.
	 *
	 * @param array<string, string>|string $attributes Shortcode attributes.
	 */
	public function render_search_shortcode( $attributes ): string {
		return $this->ui->live_search( self::shortcode_options( $attributes ) );
	}

	/**
	 * Renders [prstsearch_form]: the form only, results appear on the search page.
	 *
	 * @param array<string, string>|string $attributes Shortcode attributes.
	 */
	public function render_form_shortcode( $attributes ): string {
		return $this->ui->form( self::shortcode_options( $attributes ) );
	}

	/**
	 * Normalises shortcode attributes.
	 *
	 * @param array<string, string>|string $attributes Shortcode attributes.
	 * @return array{label: string, placeholder: string, button_label: string, show_answer: bool}
	 */
	private static function shortcode_options( $attributes ): array {
		$attributes = shortcode_atts(
			array(
				'label'       => '',
				'placeholder' => '',
				'button'      => '',
				'answer'      => 'yes',
			),
			is_array( $attributes ) ? $attributes : array()
		);

		return array(
			'label'        => $attributes['label'],
			'placeholder'  => $attributes['placeholder'],
			'button_label' => $attributes['button'],
			'show_answer'  => 'no' !== strtolower( $attributes['answer'] ),
		);
	}
}
