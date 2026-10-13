<?php
/**
 * The supported AI providers.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Lists the providers the plugin can talk to.
 *
 * All of them offer an OpenAI-compatible API, so one client covers them and a
 * new provider is a new entry here. "custom" also covers self-hosted models
 * such as Ollama.
 */
final class ProviderCatalog {

	public const CUSTOM = 'custom';

	/**
	 * Returns all providers keyed by ID.
	 *
	 * Prices are US dollars per one million tokens and can be overridden in
	 * the settings. The similarity thresholds differ because every embedding
	 * model spreads its scores over a different range. Prices and thresholds
	 * belong to the default models; the model lists are what the settings page
	 * offers, any other model name can still be typed in.
	 *
	 * @return array<string, ProviderDefinition>
	 */
	public static function all(): array {
		$providers = array(
			new ProviderDefinition(
				'openai',
				'OpenAI',
				'https://api.openai.com/v1',
				'text-embedding-3-small',
				'gpt-6-luna',
				new Prices( 0.02, 0.10, 0.50 ),
				embedding_models: array( 'text-embedding-3-small', 'text-embedding-3-large' ),
				chat_models: array( 'gpt-6-luna', 'gpt-4o-mini', 'gpt-4.1-nano', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-5-nano', 'gpt-5-mini' ),
				prices_as_of: '2026-10-12'
			),
			new ProviderDefinition(
				'gemini',
				'Google Gemini',
				'https://generativelanguage.googleapis.com/v1beta/openai',
				'gemini-embedding-001',
				'gemini-2.5-flash',
				new Prices( 0.15, 0.30, 2.50 ),
				0.72,
				0.62,
				embedding_models: array( 'gemini-embedding-001' ),
				chat_models: array( 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' ),
				prices_as_of: '2026-06-01'
			),
			new ProviderDefinition(
				'mistral',
				'Mistral',
				'https://api.mistral.ai/v1',
				'mistral-embed',
				'mistral-small-latest',
				new Prices( 0.10, 0.10, 0.30 ),
				0.80,
				0.72,
				embedding_models: array( 'mistral-embed' ),
				chat_models: array( 'mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest' ),
				prices_as_of: '2026-06-01'
			),
			new ProviderDefinition(
				self::CUSTOM,
				'Custom endpoint',
				'',
				'',
				'',
				new Prices(),
				requires_key: false
			),
		);

		$by_id = array();

		foreach ( $providers as $provider ) {
			$by_id[ $provider->id ] = $provider;
		}

		return $by_id;
	}

	/**
	 * Returns one provider, falling back to the first one for unknown IDs.
	 */
	public static function get( string $id ): ProviderDefinition {
		$providers = self::all();

		return $providers[ $id ] ?? reset( $providers );
	}
}
