<?php
/**
 * Loads front-end templates.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Frontend;

/**
 * Renders plugin templates and lets themes override them.
 *
 * A theme overrides a template by providing a file of the same name in a
 * "smart-search" folder, e.g. smart-search/result.php.
 */
final class TemplateLoader {

	private const THEME_FOLDER = 'smart-search';

	/**
	 * Creates the loader.
	 *
	 * @param string $plugin_templates Directory of the default templates, with trailing slash.
	 */
	public function __construct( private readonly string $plugin_templates ) {}

	/**
	 * Renders a template and returns its output.
	 *
	 * @param string               $name Template name without extension.
	 * @param array<string, mixed> $args Variables for the template, available as $args.
	 */
	public function render( string $name, array $args = array() ): string {
		$file = $this->locate( $name );

		if ( '' === $file ) {
			return '';
		}

		ob_start();
		load_template( $file, false, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Finds the template file, preferring the theme.
	 */
	private function locate( string $name ): string {
		$name = sanitize_file_name( $name ) . '.php';
		$file = locate_template( self::THEME_FOLDER . '/' . $name );

		if ( '' === $file ) {
			$file = $this->plugin_templates . $name;
		}

		/**
		 * Filters the path of a template.
		 *
		 * @param string $file Absolute path.
		 * @param string $name File name of the template.
		 */
		$file = (string) apply_filters( 'prstsearch_template', $file, $name );

		return is_readable( $file ) ? $file : '';
	}
}
