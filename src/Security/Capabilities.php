<?php
/**
 * Plugin capabilities.
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Security;

use PrstSearch\Contracts\Hookable;
use PrstSearch\Settings\Settings;
use WP_User;

/**
 * Grants the plugin capabilities to the roles chosen in the settings.
 *
 * The capabilities are granted at runtime instead of being written to the
 * roles, so nothing has to be cleaned up when the plugin is removed.
 */
final class Capabilities implements Hookable {

	public const MANAGE     = 'prstsearch_manage';
	public const VIEW_STATS = 'prstsearch_view_stats';

	/**
	 * Creates the service.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 4 );
	}

	/**
	 * Adds the plugin capabilities to a user's capability list.
	 *
	 * @param array<string, bool> $allcaps All capabilities of the user.
	 * @param string[]            $caps    Required primitive capabilities.
	 * @param array<int, mixed>   $args    Requested capability, user ID and optional object ID.
	 * @param WP_User             $user    The user being checked.
	 * @return array<string, bool>
	 */
	public function grant( array $allcaps, array $caps, array $args, WP_User $user ): array {
		$requested = array_intersect( $caps, array( self::MANAGE, self::VIEW_STATS ) );

		if ( array() === $requested ) {
			return $allcaps;
		}

		$is_admin   = ! empty( $allcaps['manage_options'] );
		$can_manage = $is_admin || $this->has_role( $user, $this->settings->list( 'roles_manage' ) );
		$can_view   = $can_manage || $this->has_role( $user, $this->settings->list( 'roles_stats' ) );

		$allcaps[ self::MANAGE ]     = $can_manage;
		$allcaps[ self::VIEW_STATS ] = $can_view;

		return $allcaps;
	}

	/**
	 * Tells whether the user has one of the given roles.
	 *
	 * @param string[] $roles Role slugs.
	 */
	private function has_role( WP_User $user, array $roles ): bool {
		return array() !== array_intersect( $user->roles, $roles );
	}
}
