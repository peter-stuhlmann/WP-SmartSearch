<?php
/**
 * Describes one AI provider.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Static facts about a provider: where its API lives and its default models.
 */
final class ProviderDefinition {

	/**
	 * Creates the definition.
	 *
	 * @param string   $id                Setting value.
	 * @param string   $name              Brand name, not translated.
	 * @param string   $base_url          Root of the OpenAI-compatible API, without trailing slash.
	 * @param string   $embedding_model   Default embedding model.
	 * @param string   $chat_model        Default chat model.
	 * @param Prices   $prices            List prices of the default models.
	 * @param float    $similarity_high   Cosine similarity from which a match counts as very relevant.
	 * @param float    $similarity_medium Cosine similarity from which a match counts as relevant.
	 * @param bool     $requires_key      Whether the API needs a key.
	 * @param string[] $embedding_models  Embedding models offered for selection, the default included.
	 * @param string[] $chat_models       Chat models offered for selection, the default included.
	 * @param string   $prices_as_of      Date the list prices were last checked (Y-m-d).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $base_url,
		public readonly string $embedding_model,
		public readonly string $chat_model,
		public readonly Prices $prices,
		public readonly float $similarity_high = 0.45,
		public readonly float $similarity_medium = 0.30,
		public readonly bool $requires_key = true,
		public readonly array $embedding_models = array(),
		public readonly array $chat_models = array(),
		public readonly string $prices_as_of = ''
	) {}
}
