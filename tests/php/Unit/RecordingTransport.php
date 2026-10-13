<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Provider\HttpTransport;

/**
 * Test double: remembers the last request and returns a canned response.
 */
final class RecordingTransport implements HttpTransport {

	public string $url = '';

	/**
	 * @var array<string, string>
	 */
	public array $headers = array();

	/**
	 * @var array<string, mixed>
	 */
	public array $payload = array();

	public function __construct(
		private readonly int $status,
		private readonly string $body
	) {}

	public function get( string $url, array $headers, int $timeout ): array {
		return $this->post_json( $url, $headers, array(), $timeout );
	}

	public function post_json( string $url, array $headers, array $payload, int $timeout ): array {
		$this->url     = $url;
		$this->headers = $headers;
		$this->payload = $payload;

		return array(
			'status' => $this->status,
			'body'   => $this->body,
		);
	}
}
