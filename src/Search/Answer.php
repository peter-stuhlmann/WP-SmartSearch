<?php
/**
 * A generated answer.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Search;

/**
 * Answer text and the posts it is based on.
 */
final class Answer {

	/**
	 * Creates the answer.
	 *
	 * @param string $text       Plain text. Paragraphs are separated by line breaks, sources are cited as [1], [2] …
	 * @param int[]  $source_ids Post IDs of the sources, in citation order.
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $source_ids
	) {}
}
