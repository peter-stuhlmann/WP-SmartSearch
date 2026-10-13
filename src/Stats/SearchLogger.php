<?php
/**
 * Records searches for the statistics.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Stats;

use PrstSearch\Database\SearchLog;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Provider\TokenUsage;
use PrstSearch\Search\Ranking;
use PrstSearch\Settings\Settings;

/**
 * Writes to the search log, honouring the recording settings.
 *
 * No IP address, user ID or other visitor data is ever stored.
 */
final class SearchLogger {

	/**
	 * Creates the logger.
	 */
	public function __construct(
		private readonly SearchLog $log,
		private readonly Settings $settings,
		private readonly ProviderConfig $config
	) {}

	/**
	 * Logs a search.
	 *
	 * @param string  $query       The search phrase.
	 * @param Ranking $ranking     The result of the search.
	 * @param int     $duration_ms How long the search took.
	 * @return string Token referring to the entry, empty if nothing was logged.
	 */
	public function log_search( string $query, Ranking $ranking, int $duration_ms ): string {
		if ( ! $this->settings->bool( 'tracking_enabled' ) ) {
			return '';
		}

		$usage = $this->settings->bool( 'track_usage' ) ? $ranking->usage : new TokenUsage();
		$costs = $this->costs( $usage );

		$log_id = $this->log->insert(
			array(
				'query'            => $query,
				'mode'             => $ranking->mode->value,
				'results'          => count( $ranking->hits ),
				'embedding_tokens' => $usage->embedding,
				'input_tokens'     => $usage->input,
				'output_tokens'    => $usage->output,
				'input_cost'       => $costs['input'],
				'output_cost'      => $costs['output'],
				'duration_ms'      => $this->settings->bool( 'track_duration' ) ? $duration_ms : null,
			)
		);

		return LogToken::issue( $log_id );
	}

	/**
	 * Adds the usage of a generated answer to the search it belongs to.
	 */
	public function log_answer_usage( string $token, TokenUsage $usage ): void {
		$log_id = LogToken::verify( $token );

		if ( 0 === $log_id || $usage->is_empty() || ! $this->is_enabled( 'track_usage' ) ) {
			return;
		}

		$costs = $this->costs( $usage );

		$this->log->add_answer_usage( $log_id, $usage->input, $usage->output, $costs['input'], $costs['output'] );
	}

	/**
	 * Records that a result was opened.
	 */
	public function log_click( string $token, int $post_id ): void {
		$log_id = LogToken::verify( $token );

		if ( 0 === $log_id || $post_id <= 0 || ! $this->is_enabled( 'track_clicks' ) ) {
			return;
		}

		$this->log->set_clicked_post( $log_id, $post_id );
	}

	/**
	 * Splits what a usage costs at the current prices into input and output.
	 *
	 * Embedding tokens are input: they are what is sent to the provider.
	 *
	 * @return array{input: float, output: float}
	 */
	private function costs( TokenUsage $usage ): array {
		$prices = $this->config->prices();

		return array(
			'input'  => $prices->cost_of( new TokenUsage( $usage->embedding, $usage->input ) ),
			'output' => $prices->cost_of( new TokenUsage( output: $usage->output ) ),
		);
	}

	/**
	 * Tells whether recording is on in general and for the given aspect.
	 */
	private function is_enabled( string $aspect ): bool {
		return $this->settings->bool( 'tracking_enabled' ) && $this->settings->bool( $aspect );
	}
}
