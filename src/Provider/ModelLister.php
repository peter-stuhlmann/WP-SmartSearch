<?php
/**
 * Lists the models of the configured provider.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

use PrstSearch\Support\Cache;

/**
 * Asks the provider which models the account can use, and remembers the answer for a while.
 *
 * A live list is the only one that stays correct: providers release and
 * retire models faster than a plugin is updated.
 */
final class ModelLister {

	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Creates the lister.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly ProviderConfig $config,
		private readonly Cache $cache
	) {}

	/**
	 * Returns the provider's models, sorted by purpose.
	 *
	 * @return array{embedding: string[], chat: string[]}
	 *
	 * @throws ProviderException If the provider is not configured or the call fails.
	 */
	public function models(): array {
		if ( ! $this->config->can_list_models() ) {
			throw new ProviderException( 'The provider is not configured yet.' );
		}

		$all = $this->all_models();

		// A self-hosted server names its models as it likes, so guessing their
		// purpose from the name would hide the right one. Offer everything.
		if ( ProviderCatalog::CUSTOM === $this->config->definition()->id ) {
			return array(
				'embedding' => ModelClassifier::all( $all ),
				'chat'      => ModelClassifier::all( $all ),
			);
		}

		return array(
			'embedding' => ModelClassifier::embedding( $all ),
			'chat'      => ModelClassifier::chat( $all ),
		);
	}

	/**
	 * Returns every model the provider reports, from the cache if possible.
	 *
	 * @return string[]
	 *
	 * @throws ProviderException If the call fails.
	 */
	private function all_models(): array {
		$cache_key = Cache::key( 'models', $this->config->base_url(), $this->config->api_key() );
		$cached    = $this->cache->get( $cache_key );

		if ( is_array( $cached ) && array_is_list( $cached ) ) {
			return $cached;
		}

		$models = $this->clients->create()->models();

		// An empty answer is more likely a hiccup than the truth, so it is asked for again next time.
		if ( array() !== $models ) {
			$this->cache->set( $cache_key, $models, self::CACHE_TTL );
		}

		return $models;
	}
}
