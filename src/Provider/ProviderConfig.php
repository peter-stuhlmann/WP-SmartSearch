<?php
/**
 * The effective provider configuration.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

use PrstSearch\Search\SimilarityScale;
use PrstSearch\Security\SecretStore;
use PrstSearch\Settings\Settings;

/**
 * Resolves settings and provider defaults into the values actually used.
 */
final class ProviderConfig {

	/**
	 * Creates the config.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SecretStore $secrets
	) {}

	/**
	 * Returns the selected provider.
	 */
	public function definition(): ProviderDefinition {
		return ProviderCatalog::get( $this->settings->string( 'provider' ) );
	}

	/**
	 * Returns the API root without trailing slash.
	 */
	public function base_url(): string {
		$definition = $this->definition();

		return ProviderCatalog::CUSTOM === $definition->id ? $this->settings->string( 'base_url' ) : $definition->base_url;
	}

	/**
	 * Returns the API key.
	 */
	public function api_key(): string {
		return $this->secrets->get();
	}

	/**
	 * Returns the embedding model, the provider default unless overridden.
	 */
	public function embedding_model(): string {
		$model = $this->settings->string( 'embedding_model' );

		return '' !== $model ? $model : $this->definition()->embedding_model;
	}

	/**
	 * Returns the chat model, the provider default unless overridden.
	 */
	public function chat_model(): string {
		$model = $this->settings->string( 'chat_model' );

		return '' !== $model ? $model : $this->definition()->chat_model;
	}

	/**
	 * Returns the prices used for cost estimates.
	 */
	public function prices(): Prices {
		$defaults = $this->definition()->prices;

		return new Prices(
			$this->price( 'price_embedding', $defaults->embedding ),
			$this->price( 'price_input', $defaults->input ),
			$this->price( 'price_output', $defaults->output )
		);
	}

	/**
	 * Returns the scale for raw similarities of the embedding model. Used while the index is too small to centre.
	 */
	public function similarity_scale(): SimilarityScale {
		$definition = $this->definition();

		/**
		 * Filters the similarity thresholds, to tune relevance levels for a specific embedding model.
		 *
		 * @param array{high: float, medium: float} $thresholds Cosine similarity thresholds.
		 * @param string                            $model      The embedding model.
		 */
		$thresholds = (array) apply_filters(
			'prstsearch_similarity_thresholds',
			array(
				'high'   => $definition->similarity_high,
				'medium' => $definition->similarity_medium,
			),
			$this->embedding_model()
		);

		return new SimilarityScale(
			(float) ( $thresholds['high'] ?? $definition->similarity_high ),
			(float) ( $thresholds['medium'] ?? $definition->similarity_medium )
		);
	}

	/**
	 * Returns the scale for similarities measured relative to the index, see CenteredSimilarity.
	 *
	 * Centred scores are comparable across embedding models, so one set of
	 * thresholds serves all providers.
	 */
	public function centered_similarity_scale(): SimilarityScale {
		$defaults = array(
			'high'    => 0.35,
			'medium'  => 0.22,
			'minimum' => 0.20,
		);

		/**
		 * Filters the thresholds for centred similarities, to tune the relevance levels of semantic search.
		 *
		 * @param array{high: float, medium: float, minimum: float} $thresholds Similarity thresholds.
		 * @param string                                            $model      The embedding model.
		 */
		$thresholds = (array) apply_filters( 'prstsearch_centered_similarity_thresholds', $defaults, $this->embedding_model() ) + $defaults;

		return new SimilarityScale( (float) $thresholds['high'], (float) $thresholds['medium'], (float) $thresholds['minimum'] );
	}

	/**
	 * Tells whether the prices were changed in the settings.
	 */
	public function has_custom_prices(): bool {
		foreach ( array( 'price_embedding', 'price_input', 'price_output' ) as $key ) {
			if ( $this->settings->float( $key ) >= 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether embeddings can be requested.
	 */
	public function can_embed(): bool {
		return $this->is_reachable() && '' !== $this->embedding_model();
	}

	/**
	 * Tells whether answers can be generated.
	 */
	public function can_chat(): bool {
		return $this->is_reachable() && '' !== $this->chat_model();
	}

	/**
	 * Tells whether the provider can be asked for its models.
	 */
	public function can_list_models(): bool {
		return $this->is_reachable();
	}

	/**
	 * Tells whether the search mode uses embeddings and the provider can deliver them.
	 */
	public function embeddings_enabled(): bool {
		return in_array( $this->settings->string( 'mode' ), array( 'semantic', 'ai' ), true ) && $this->can_embed();
	}

	/**
	 * Tells whether the search mode includes AI answers and the provider can deliver them.
	 */
	public function answers_enabled(): bool {
		return 'ai' === $this->settings->string( 'mode' ) && $this->can_chat();
	}

	/**
	 * Tells whether URL and credentials are complete.
	 */
	private function is_reachable(): bool {
		if ( '' === $this->base_url() ) {
			return false;
		}

		return ! $this->definition()->requires_key || '' !== $this->api_key();
	}

	/**
	 * Returns a price setting or its default.
	 */
	private function price( string $key, float $default_price ): float {
		$price = $this->settings->float( $key );

		// A negative setting means "use the provider's list price".
		return $price < 0 ? $default_price : $price;
	}
}
