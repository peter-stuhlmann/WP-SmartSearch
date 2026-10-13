<?php
/**
 * Reads a streamed chat completion.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * Turns the server-sent events of an OpenAI-compatible API into pieces of text.
 *
 * The network delivers the stream in arbitrary portions: an event can arrive
 * in two halves, and several events at once. What is incomplete is kept until
 * the rest arrives.
 */
final class ChatStream {

	/**
	 * Data received that does not end with a complete line yet.
	 */
	private string $pending = '';

	/**
	 * The text received so far.
	 */
	private string $text = '';

	/**
	 * Tokens reported by the provider, if it reports them.
	 */
	private ?TokenUsage $usage = null;

	/**
	 * Takes the next portion of the stream.
	 *
	 * @return string[] The pieces of text this portion completed, in order.
	 */
	public function feed( string $data ): array {
		$lines = explode( "\n", $this->pending . $data );

		// Without a line break at its end, the last line is still being written.
		$this->pending = (string) array_pop( $lines );

		$pieces = array();

		foreach ( $lines as $line ) {
			$piece = $this->read_line( $line );

			if ( '' !== $piece ) {
				$pieces[]    = $piece;
				$this->text .= $piece;
			}
		}

		return $pieces;
	}

	/**
	 * Returns the whole text received so far.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Returns the tokens the provider reported, or null if it reported none.
	 */
	public function usage(): ?TokenUsage {
		return $this->usage;
	}

	/**
	 * Reads one line of the stream and returns the text it carries, if any.
	 */
	private function read_line( string $line ): string {
		$line = trim( $line );

		// Only "data:" lines carry content. Comments, event names and the final "[DONE]" do not.
		if ( ! str_starts_with( $line, 'data:' ) ) {
			return '';
		}

		$event = json_decode( trim( substr( $line, 5 ) ), true );

		if ( ! is_array( $event ) ) {
			return '';
		}

		if ( is_array( $event['usage'] ?? null ) ) {
			$this->usage = new TokenUsage(
				input: (int) ( $event['usage']['prompt_tokens'] ?? 0 ),
				output: (int) ( $event['usage']['completion_tokens'] ?? 0 )
			);
		}

		$piece = $event['choices'][0]['delta']['content'] ?? '';

		return is_string( $piece ) ? $piece : '';
	}
}
