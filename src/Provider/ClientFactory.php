<?php
/**
 * Creates the AI client for the current settings.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Builds provider clients.
 */
final class ClientFactory {

	/**
	 * Creates the factory.
	 */
	public function __construct(
		private readonly ProviderConfig $config,
		private readonly HttpTransport $transport
	) {}

	/**
	 * Creates a client for the configured provider.
	 */
	public function create(): AiClient {
		$client = new OpenAiCompatibleClient(
			$this->transport,
			$this->config->base_url(),
			$this->config->api_key(),
			$this->config->embedding_model(),
			$this->config->chat_model()
		);

		/**
		 * Filters the AI client, e.g. to plug in a provider with a different API.
		 *
		 * @param AiClient       $client The default client.
		 * @param ProviderConfig $config The effective provider configuration.
		 */
		$filtered = apply_filters( 'prstsearch_ai_client', $client, $this->config );

		return $filtered instanceof AiClient ? $filtered : $client;
	}
}
