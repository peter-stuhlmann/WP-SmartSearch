<?php
/**
 * Sorts a provider's model list by purpose.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Tells embedding models and text models apart by their names.
 *
 * Providers list everything an account can use, including image, audio and
 * moderation models. The OpenAI-compatible API does not say what a model is
 * for, so the names are the only clue. A model that is filtered out by
 * mistake can still be typed in by hand on the settings page.
 */
final class ModelClassifier {

	/**
	 * Name parts of models that neither embed nor answer in text.
	 */
	private const NOT_FOR_TEXT = array(
		'audio',
		'babbage',
		'computer-use',
		'dall-e',
		'davinci',
		'guard',
		'image',
		'imagen',
		'instruct',
		'moderation',
		'ocr',
		'realtime',
		'rerank',
		'sora',
		'speech',
		'transcribe',
		'tts',
		'veo',
		'voxtral',
		'whisper',
	);

	/**
	 * Returns all models without judging their purpose.
	 *
	 * @param string[] $models Model names.
	 * @return string[] Sorted alphabetically.
	 */
	public static function all( array $models ): array {
		return self::sorted( $models );
	}

	/**
	 * Returns the models that create embeddings.
	 *
	 * @param string[] $models Model names.
	 * @return string[] Sorted alphabetically.
	 */
	public static function embedding( array $models ): array {
		return self::sorted( array_filter( $models, array( self::class, 'is_embedding' ) ) );
	}

	/**
	 * Returns the models that answer in text.
	 *
	 * @param string[] $models Model names.
	 * @return string[] Sorted alphabetically.
	 */
	public static function chat( array $models ): array {
		return self::sorted(
			array_filter(
				$models,
				static fn ( string $model ): bool => ! self::is_embedding( $model ) && ! self::contains_any( $model, self::NOT_FOR_TEXT )
			)
		);
	}

	/**
	 * Tells whether a model creates embeddings.
	 */
	private static function is_embedding( string $model ): bool {
		return str_contains( strtolower( $model ), 'embed' );
	}

	/**
	 * Tells whether a model name contains one of the given parts.
	 *
	 * @param string[] $parts Name parts.
	 */
	private static function contains_any( string $model, array $parts ): bool {
		$model = strtolower( $model );

		foreach ( $parts as $part ) {
			if ( str_contains( $model, $part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns unique names in natural order, so "gpt-10" follows "gpt-9".
	 *
	 * @param string[] $models Model names.
	 * @return string[]
	 */
	private static function sorted( array $models ): array {
		$models = array_values( array_unique( $models ) );

		natcasesort( $models );

		return array_values( $models );
	}
}
