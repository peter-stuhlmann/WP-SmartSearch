<?php
/**
 * REST routes for the AI provider.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Rest;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Provider\ClientFactory;
use PrstSearch\Provider\ModelLister;
use PrstSearch\Provider\ProviderConfig;
use PrstSearch\Provider\ProviderException;
use PrstSearch\Provider\UsageLedger;
use PrstSearch\Security\Capabilities;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Checks that the saved provider settings work and lists the provider's models.
 */
final class ProviderRoutes implements Hookable {

	/**
	 * Creates the routes.
	 */
	public function __construct(
		private readonly ClientFactory $clients,
		private readonly ProviderConfig $config,
		private readonly UsageLedger $ledger,
		private readonly ModelLister $models
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/provider/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
			)
		);

		register_rest_route(
			SearchRoutes::ROUTE_NAMESPACE,
			'/provider/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'models' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
			)
		);
	}

	/**
	 * Returns the models of the saved provider, for the model selects of the settings page.
	 *
	 * @return WP_REST_Response Always 200. Empty lists mean "use the built-in list".
	 */
	public function models(): WP_REST_Response {
		try {
			$models = $this->models->models();
		} catch ( ProviderException ) {
			// Not configured or unreachable: the settings page keeps its built-in list.
			$models = array(
				'embedding' => array(),
				'chat'      => array(),
			);
		}

		return new WP_REST_Response(
			array(
				'provider'         => $this->config->definition()->id,
				'embedding_models' => $models['embedding'],
				'chat_models'      => $models['chat'],
			)
		);
	}

	/**
	 * Sends one tiny embedding and one tiny chat request.
	 *
	 * @return WP_REST_Response Always 200; the body tells which checks passed.
	 */
	public function test(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'embedding' => $this->check(
					$this->config->can_embed(),
					function (): void {
						$result = $this->clients->create()->embed( array( 'ping' ) );
						$this->ledger->record( UsageLedger::PURPOSE_INDEX, $result->usage );
					}
				),
				'chat'      => $this->check(
					$this->config->can_chat(),
					function (): void {
						$result = $this->clients->create()->chat( 'Reply with the single word OK.', 'ping' );
						$this->ledger->record( UsageLedger::PURPOSE_SEARCH, $result->usage );
					}
				),
			)
		);
	}

	/**
	 * Runs one check and reports its outcome.
	 *
	 * @param bool     $is_configured Whether the settings for this check are complete.
	 * @param callable $call          The provider call. Throws ProviderException on failure.
	 * @return array{ok: bool, message: string}
	 */
	private function check( bool $is_configured, callable $call ): array {
		if ( ! $is_configured ) {
			return array(
				'ok'      => false,
				'message' => __( 'Not configured yet: address, API key or model is missing.', 'smart-search' ),
			);
		}

		try {
			$call();
		} catch ( ProviderException $error ) {
			return array(
				'ok'      => false,
				'message' => $error->getMessage(),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Works.', 'smart-search' ),
		);
	}
}
