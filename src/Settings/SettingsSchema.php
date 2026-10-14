<?php
/**
 * Declares every plugin setting in one place.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Settings;

/**
 * Single source of truth for setting keys, types, defaults and the tab they are edited on.
 */
final class SettingsSchema {

	public const TYPE_BOOL   = 'bool';
	public const TYPE_INT    = 'int';
	public const TYPE_FLOAT  = 'float';
	public const TYPE_STRING = 'string';
	public const TYPE_TEXT   = 'text';
	public const TYPE_URL    = 'url';
	public const TYPE_CHOICE = 'choice';
	public const TYPE_LIST   = 'list';

	public const TAB_CONTENT  = 'content';
	public const TAB_SEARCH   = 'search';
	public const TAB_PROVIDER = 'provider';
	public const TAB_DISPLAY  = 'display';
	public const TAB_STATS    = 'stats';
	public const TAB_SECURITY = 'security';
	public const TAB_TOOLS    = 'tools';

	/**
	 * Returns the field definitions keyed by setting name.
	 *
	 * Supported keys per field: type, default, tab, and depending on the type
	 * choices (choice), min/max (int, float). Lists are validated against
	 * choices that are only known at runtime, see SettingsSanitizer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			// Content.
			'post_types'               => self::field( self::TYPE_LIST, array( 'post', 'page' ), self::TAB_CONTENT ),
			'auto_post_types'          => self::field( self::TYPE_BOOL, true, self::TAB_CONTENT ),
			'known_post_types'         => self::field( self::TYPE_LIST, array(), self::TAB_CONTENT ),
			'index_comments'           => self::field( self::TYPE_BOOL, true, self::TAB_CONTENT ),
			'index_terms'              => self::field( self::TYPE_BOOL, true, self::TAB_CONTENT ),
			'index_excerpt'            => self::field( self::TYPE_BOOL, false, self::TAB_CONTENT ),
			'custom_fields'            => self::field( self::TYPE_STRING, '', self::TAB_CONTENT ),
			'fetch_fallback'           => self::field( self::TYPE_BOOL, false, self::TAB_CONTENT ),

			// Search.
			'mode'                     => self::choice( 'keyword', array( 'keyword', 'semantic', 'ai' ), self::TAB_SEARCH ),
			'replace_wp_search'        => self::field( self::TYPE_BOOL, true, self::TAB_SEARCH ),
			'boost_title'              => self::field( self::TYPE_BOOL, true, self::TAB_SEARCH ),
			'ai_judge'                 => self::field( self::TYPE_BOOL, true, self::TAB_SEARCH ),
			'results_per_page'         => self::number( self::TYPE_INT, 10, self::TAB_SEARCH, 1, 100 ),
			'max_results'              => self::number( self::TYPE_INT, 200, self::TAB_SEARCH, 10, 2000 ),
			'match_inside_words'       => self::field( self::TYPE_BOOL, true, self::TAB_SEARCH ),
			'hide_weak_results'        => self::field( self::TYPE_BOOL, false, self::TAB_SEARCH ),
			'suggestions'              => self::field( self::TYPE_BOOL, true, self::TAB_SEARCH ),

			// AI provider.
			'provider'                 => self::choice( 'openai', array( 'openai', 'gemini', 'mistral', 'custom' ), self::TAB_PROVIDER ),
			'base_url'                 => self::field( self::TYPE_URL, '', self::TAB_PROVIDER ),
			'embedding_model'          => self::field( self::TYPE_STRING, '', self::TAB_PROVIDER ),
			'chat_model'               => self::field( self::TYPE_STRING, '', self::TAB_PROVIDER ),
			'price_embedding'          => self::number( self::TYPE_FLOAT, -1.0, self::TAB_PROVIDER, -1.0, 10000.0 ),
			'price_input'              => self::number( self::TYPE_FLOAT, -1.0, self::TAB_PROVIDER, -1.0, 10000.0 ),
			'price_output'             => self::number( self::TYPE_FLOAT, -1.0, self::TAB_PROVIDER, -1.0, 10000.0 ),
			'prices_updated'           => self::field( self::TYPE_STRING, '', self::TAB_PROVIDER ),
			'currency'                 => self::choice( 'USD', array( 'USD', 'EUR' ), self::TAB_PROVIDER ),
			'monthly_budget'           => self::number( self::TYPE_FLOAT, 0.0, self::TAB_PROVIDER, 0.0, 1000000.0 ),
			'cache_enabled'            => self::field( self::TYPE_BOOL, true, self::TAB_PROVIDER ),
			'answer_instructions'      => self::field( self::TYPE_TEXT, '', self::TAB_PROVIDER ),

			// Display.
			'integration'              => self::choice( 'replace', array( 'replace', 'block', 'templates' ), self::TAB_DISPLAY ),
			'auto_answer'              => self::field( self::TYPE_BOOL, true, self::TAB_DISPLAY ),
			'stream_answers'           => self::field( self::TYPE_BOOL, false, self::TAB_DISPLAY ),
			'type_filter'              => self::field( self::TYPE_BOOL, true, self::TAB_DISPLAY ),
			'jump_to_passage'          => self::field( self::TYPE_BOOL, true, self::TAB_DISPLAY ),
			'show_sources'             => self::field( self::TYPE_BOOL, true, self::TAB_DISPLAY ),
			'show_relevance'           => self::field( self::TYPE_BOOL, true, self::TAB_DISPLAY ),
			'visitor_address'          => self::choice( 'auto', array( 'auto', 'informal', 'formal' ), self::TAB_DISPLAY ),

			// Statistics.
			'tracking_enabled'         => self::field( self::TYPE_BOOL, true, self::TAB_STATS ),
			'track_usage'              => self::field( self::TYPE_BOOL, true, self::TAB_STATS ),
			'track_clicks'             => self::field( self::TYPE_BOOL, false, self::TAB_STATS ),
			'track_duration'           => self::field( self::TYPE_BOOL, false, self::TAB_STATS ),
			'retention_days'           => self::number( self::TYPE_INT, 90, self::TAB_STATS, 1, 3650 ),

			// Security.
			'search_limit'             => self::number( self::TYPE_INT, 20, self::TAB_SECURITY, 0, 100000 ),
			'search_limit_window'      => self::choice( 'minute', array( 'minute', 'hour', 'day' ), self::TAB_SECURITY ),
			'search_limit_site'        => self::number( self::TYPE_INT, 0, self::TAB_SECURITY, 0, 1000000 ),
			'search_limit_action'      => self::choice( 'basic', array( 'basic', 'block' ), self::TAB_SECURITY ),
			'search_limit_message'     => self::field( self::TYPE_TEXT, '', self::TAB_SECURITY ),
			'honeypot'                 => self::field( self::TYPE_BOOL, false, self::TAB_SECURITY ),

			// Tools.
			'roles_manage'             => self::field( self::TYPE_LIST, array( 'administrator' ), self::TAB_TOOLS ),
			'roles_stats'              => self::field( self::TYPE_LIST, array( 'administrator', 'editor' ), self::TAB_TOOLS ),
			'delete_data_on_uninstall' => self::field( self::TYPE_BOOL, false, self::TAB_TOOLS ),
		);
	}

	/**
	 * Returns the default value of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array_map(
			static fn ( array $field ): mixed => $field['default'],
			self::fields()
		);
	}

	/**
	 * Builds a plain field definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function field( string $type, mixed $default_value, string $tab ): array {
		return array(
			'type'    => $type,
			'default' => $default_value,
			'tab'     => $tab,
		);
	}

	/**
	 * Builds a field limited to a fixed set of values.
	 *
	 * @param string[] $choices Allowed values.
	 * @return array<string, mixed>
	 */
	private static function choice( string $default_value, array $choices, string $tab ): array {
		return self::field( self::TYPE_CHOICE, $default_value, $tab ) + array( 'choices' => $choices );
	}

	/**
	 * Builds a numeric field with bounds.
	 *
	 * @return array<string, mixed>
	 */
	private static function number( string $type, int|float $default_value, string $tab, int|float $min, int|float $max ): array {
		return self::field( $type, $default_value, $tab ) + array(
			'min' => $min,
			'max' => $max,
		);
	}
}
