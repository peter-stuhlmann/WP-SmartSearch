<?php
/**
 * Contract for AI providers.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * What the plugin needs from a provider: embeddings, text answers and the list of its models.
 */
interface AiClient {

	/**
	 * Creates embeddings for a batch of texts.
	 *
	 * @param string[] $texts Texts to embed.
	 * @return EmbeddingResult Vectors in the order of the input.
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function embed( array $texts ): EmbeddingResult;

	/**
	 * Generates a text answer.
	 *
	 * @param string $instructions System prompt.
	 * @param string $message      User message.
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function chat( string $instructions, string $message ): ChatResult;

	/**
	 * Lists the models the account can use.
	 *
	 * @return string[] Model names, unfiltered.
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function models(): array;
}
