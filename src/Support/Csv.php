<?php
/**
 * Helpers for CSV exports.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

/**
 * Makes values safe to open in a spreadsheet.
 */
final class Csv {

	/**
	 * Neutralises a cell a spreadsheet would run as a formula.
	 *
	 * Exported text can come from visitors, for example a search phrase. A
	 * leading apostrophe makes spreadsheets show such a cell as text.
	 */
	public static function cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}
}
