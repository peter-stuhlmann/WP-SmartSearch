<?php
/**
 * Contract for AI providers that can deliver an answer while it is written.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Provider;

/**
 * An optional ability of a client.
 *
 * It is an interface of its own so that clients plugged in from outside keep
 * working without it: their answers simply arrive in one piece.
 */
interface StreamingAiClient extends AiClient {

	/**
	 * Generates a text answer and hands over each piece as soon as it arrives.
	 *
	 * @param string                 $instructions System prompt.
	 * @param string                 $message      User message.
	 * @param callable(string): void $on_text      Receives the pieces of the answer, in order.
	 * @return ChatResult The complete answer, the same as chat() would return.
	 *
	 * @throws ProviderException If the provider call fails.
	 */
	public function chat_stream( string $instructions, string $message, callable $on_text ): ChatResult;
}
