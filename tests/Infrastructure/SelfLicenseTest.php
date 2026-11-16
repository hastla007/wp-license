<?php
/**
 * E8b: the plugin's own update path is off unless configured, and never points at itself.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Infrastructure\SelfLicense;

final class SelfLicenseTest extends TestCase {

	private function config( array $overrides = array() ): array {
		return array_merge(
			array(
				'server_url'  => 'https://licenses.vendor.example',
				'product_key' => 'license-server',
				'public_keys' => array( 'k1' => 'cHVibGljLWtleQ==' ),
				'account_url' => '',
			),
			$overrides
		);
	}

	public function test_nothing_configured_is_off(): void {
		$empty = array( 'server_url' => '', 'product_key' => '', 'public_keys' => array(), 'account_url' => '' );
		$this->assertSame( SelfLicense::STATE_OFF, SelfLicense::state_for( $empty, array( 'shop.example' ) ) );
		// An account address alone configures nothing.
		$this->assertSame( SelfLicense::STATE_OFF, SelfLicense::state_for( array_merge( $empty, array( 'account_url' => 'https://vendor.example/account' ) ), array( 'shop.example' ) ) );
	}

	public function test_an_incomplete_or_insecure_configuration_does_not_switch_it_on(): void {
		$own = array( 'shop.example' );
		$this->assertSame( SelfLicense::STATE_ON, SelfLicense::state_for( $this->config(), $own ) );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state_for( $this->config( array( 'server_url' => 'http://licenses.vendor.example' ) ), $own ), 'HTTPS only.' );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state_for( $this->config( array( 'server_url' => 'licenses.vendor.example' ) ), $own ) );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state_for( $this->config( array( 'product_key' => '' ) ), $own ) );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state_for( $this->config( array( 'product_key' => 'Not A Key' ) ), $own ) );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state_for( $this->config( array( 'public_keys' => array() ) ), $own ), 'Answers must be verifiable.' );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state_for( $this->config( array( 'server_url' => '' ) ), $own ) );
	}

	public function test_a_server_never_updates_itself_from_itself(): void {
		foreach ( array( 'licenses.vendor.example', 'www.licenses.vendor.example', 'LICENSES.vendor.example' ) as $own ) {
			$this->assertSame( SelfLicense::STATE_LOOP, SelfLicense::state_for( $this->config(), array( 'other.example', strtolower( $own ) ) ), $own );
		}
		$this->assertSame( SelfLicense::STATE_LOOP, SelfLicense::state_for( $this->config( array( 'server_url' => 'https://www.shop.example/wp-json/' ) ), array( 'shop.example' ) ) );
		$this->assertSame( SelfLicense::STATE_LOOP, SelfLicense::state_for( $this->config( array( 'server_url' => 'https://SHOP.example.:443/' ) ), array( 'shop.example' ) ) );
		// A different host of the same domain is another installation.
		$this->assertSame( SelfLicense::STATE_ON, SelfLicense::state_for( $this->config(), array( 'vendor.example', 'shop.vendor.example' ) ) );

		$this->assertTrue( SelfLicense::same_host( 'www.a.example', 'a.example' ) );
		$this->assertFalse( SelfLicense::same_host( 'b.a.example', 'a.example' ) );
		$this->assertSame( 'a.example', SelfLicense::host( 'https://A.example./path?x=1' ) );
		$this->assertSame( '', SelfLicense::host( 'not a url' ) );
	}
}
