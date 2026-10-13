<?php
/**
 * @package PrstSearch
 */

declare( strict_types=1 );

namespace PrstSearch\Tests\Unit;

use PrstSearch\Security\Capabilities;
use PrstSearch\Settings\Settings;
use WP_User;

/**
 * Who may change the settings and who may see the statistics.
 */
final class CapabilitiesTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->options[ Settings::OPTION ] = array(
			'roles_manage' => array( 'editor' ),
			'roles_stats'  => array( 'author' ),
		);
	}

	public function test_administrators_may_do_everything(): void {
		$caps = $this->granted( 'administrator', array( 'manage_options' => true ) );

		self::assertTrue( $caps[ Capabilities::MANAGE ] );
		self::assertTrue( $caps[ Capabilities::VIEW_STATS ] );
	}

	public function test_a_role_that_may_manage_also_sees_the_statistics(): void {
		$caps = $this->granted( 'editor' );

		self::assertTrue( $caps[ Capabilities::MANAGE ] );
		self::assertTrue( $caps[ Capabilities::VIEW_STATS ] );
	}

	public function test_a_statistics_role_cannot_change_settings(): void {
		$caps = $this->granted( 'author' );

		self::assertFalse( $caps[ Capabilities::MANAGE ] );
		self::assertTrue( $caps[ Capabilities::VIEW_STATS ] );
	}

	public function test_other_roles_get_nothing(): void {
		$caps = $this->granted( 'subscriber' );

		self::assertFalse( $caps[ Capabilities::MANAGE ] );
		self::assertFalse( $caps[ Capabilities::VIEW_STATS ] );
	}

	public function test_a_capability_someone_else_handed_out_is_taken_back(): void {
		$caps = $this->granted( 'subscriber', array( Capabilities::MANAGE => true ) );

		self::assertFalse( $caps[ Capabilities::MANAGE ] );
	}

	public function test_checks_for_other_capabilities_are_left_alone(): void {
		$user        = new WP_User();
		$user->roles = array( 'editor' );
		$all         = array( 'edit_posts' => true );

		self::assertSame( $all, ( new Capabilities( new Settings() ) )->grant( $all, array( 'edit_posts' ), array(), $user ) );
	}

	/**
	 * @param array<string, bool> $existing Capabilities the user already has.
	 * @return array<string, bool>
	 */
	private function granted( string $role, array $existing = array() ): array {
		$user        = new WP_User();
		$user->roles = array( $role );

		return ( new Capabilities( new Settings() ) )->grant( $existing, array( Capabilities::MANAGE ), array(), $user );
	}
}
