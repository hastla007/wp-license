<?php
/**
 * A-42: release notification for the plugin itself, never with a package.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Infrastructure\SelfUpdateCheck;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class SelfUpdateCheckTest extends TestCase {

	public function test_a_published_release_is_read(): void {
		$this->assertSame(
			array(
				'version' => '0.3.0',
				'url'     => 'https://github.com/hastla007/wp-license/releases/tag/v0.3.0',
			),
			SelfUpdateCheck::parse_release(
				array(
					'tag_name'   => 'v0.3.0',
					'html_url'   => 'https://github.com/hastla007/wp-license/releases/tag/v0.3.0',
					'draft'      => false,
					'prerelease' => false,
				)
			)
		);
	}

	public function test_drafts_prereleases_and_odd_tags_are_ignored(): void {
		$this->assertNull( SelfUpdateCheck::parse_release( array( 'tag_name' => 'v0.3.0', 'prerelease' => true ) ) );
		$this->assertNull( SelfUpdateCheck::parse_release( array( 'tag_name' => 'v0.3.0', 'draft' => true ) ) );
		$this->assertNull( SelfUpdateCheck::parse_release( array( 'tag_name' => 'v0.3.0-rc.1' ) ) );
		$this->assertNull( SelfUpdateCheck::parse_release( array( 'tag_name' => 'latest' ) ) );
		$this->assertNull( SelfUpdateCheck::parse_release( array() ) );
	}

	public function test_a_foreign_link_falls_back_to_the_release_list(): void {
		$release = SelfUpdateCheck::parse_release( array( 'tag_name' => '1.0.0', 'html_url' => 'https://evil.example/x' ) );

		$this->assertSame( 'https://github.com/hastla007/wp-license/releases', $release['url'] );
	}

	public function test_the_update_row_never_carries_a_package(): void {
		$release = array(
			'version' => '0.3.0',
			'url'     => 'https://github.com/hastla007/wp-license/releases/tag/v0.3.0',
		);

		$newer = SelfUpdateCheck::update_row( $release, '0.2.0', 'wp-license/wp-license.php' );
		$this->assertSame( '0.3.0', $newer['version'] );
		$this->assertSame( '', $newer['package'] );
		$this->assertSame( 'wp-license', $newer['slug'] );

		$same = SelfUpdateCheck::update_row( $release, '0.3.0', 'wp-license/wp-license.php' );
		$this->assertSame( '0.3.0', $same['version'], 'Not newer: WordPress records "no update".' );

		$older = SelfUpdateCheck::update_row( $release, '0.4.0', 'wp-license/wp-license.php' );
		$this->assertSame( '0.4.0', $older['version'] );
	}

	public function test_other_plugins_on_the_same_host_are_left_alone(): void {
		$previous = array( 'version' => '9.9.9' );

		$this->assertSame( $previous, SelfUpdateCheck::filter_update( $previous, array(), 'other/other.php' ) );
		$this->assertFalse( SelfUpdateCheck::filter_update( false, array(), 'other/other.php' ) );
	}

	public function test_the_check_can_be_switched_off(): void {
		$this->assertTrue( SelfUpdateCheck::enabled() );
		$GLOBALS['wplc_test_filters']['wplc_self_update_check_enabled'] = array(
			static function () {
				return false;
			},
		);
		try {
			$this->assertFalse( SelfUpdateCheck::enabled() );
		} finally {
			unset( $GLOBALS['wplc_test_filters']['wplc_self_update_check_enabled'] );
		}
	}
}
