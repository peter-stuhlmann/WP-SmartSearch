<?php
/**
 * A minimal dependency injection container.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Support;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Creates each service once and passes it to whoever asks for it in a constructor.
 *
 * Constructor parameters typed with a class are resolved automatically.
 * Anything else, such as strings or interfaces, needs a factory.
 */
final class Container {

	/**
	 * Services that were already created, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Factories for services that cannot be autowired.
	 *
	 * @var array<string, callable(self): object>
	 */
	private array $factories = array();

	/**
	 * Registers a factory for a class or interface.
	 *
	 * @param string                 $id      Class or interface name.
	 * @param callable(self): object $factory Creates the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Returns a service, creating it on first use.
	 *
	 * @template T of object
	 * @param class-string<T> $id Class or interface name.
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			$this->instances[ $id ] = isset( $this->factories[ $id ] ) ? ( $this->factories[ $id ] )( $this ) : $this->build( $id );
		}

		return $this->instances[ $id ];
	}

	/**
	 * Creates an object by resolving its constructor parameters.
	 *
	 * @param class-string $class_name Class to create.
	 *
	 * @throws LogicException If a parameter cannot be resolved.
	 */
	private function build( string $class_name ): object {
		$reflection  = new ReflectionClass( $class_name );
		$constructor = $reflection->getConstructor();
		$arguments   = array();

		foreach ( null === $constructor ? array() : $constructor->getParameters() as $parameter ) {
			$type      = $parameter->getType();
			$type_name = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : '';

			if ( class_exists( $type_name ) || interface_exists( $type_name ) ) {
				$arguments[] = $this->get( $type_name );
				continue;
			}

			if ( $parameter->isDefaultValueAvailable() ) {
				$arguments[] = $parameter->getDefaultValue();
				continue;
			}

			throw new LogicException(
				sprintf( 'Cannot resolve parameter $%s of %s. Register a factory for the class.', esc_html( $parameter->getName() ), esc_html( $class_name ) )
			);
		}

		return $reflection->newInstanceArgs( $arguments );
	}
}
