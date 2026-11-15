<?php
/**
 * A-42: WordPress' update check for the plugin itself goes through the release notification.
 *
 * @package WPLC
 */

use WPLC\Infrastructure\SelfUpdateCheck;

final class SelfUpdateCheckIntegrationTest extends WP_UnitTestCase {

	/** @var int */
	private $requests = 0;

	public function set_up(): void {
		parent::set_up();
		delete_site_transient( SelfUpdateCheck::TRANSIENT );
		$this->requests = 0;
	}

	public function tear_down(): void {
		delete_site_transient( SelfUpdateCheck::TRANSIENT );
		parent::tear_down();
	}

	/** Answer the GitHub API with the given status and body. */
	private function fake_api( int $status, array $body ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $status, $body ) {
				if ( SelfUpdateCheck::RELEASE_API !== $url ) {
					return $pre;
				}
				++$this->requests;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array( 'code' => $status, 'message' => '' ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	public function test_a_newer_release_is_offered_without_a_package_and_cached(): void {
		$this->fake_api( 200, array( 'tag_name' => 'v99.0.0', 'html_url' => 'https://github.com/hastla007/wp-license/releases/tag/v99.0.0' ) );
		$file = plugin_basename( WPLC_FILE );

		$row = apply_filters( 'update_plugins_github.com', false, array(), $file, array() );

		$this->assertIsArray( $row );
		$this->assertSame( '99.0.0', $row['version'] );
		$this->assertSame( '', $row['package'] );
		$this->assertSame( 1, $this->requests );

		apply_filters( 'update_plugins_github.com', false, array(), $file, array() );
		$this->assertSame( 1, $this->requests, 'The answer is cached.' );
		$this->assertSame( '99.0.0', SelfUpdateCheck::latest( false )['version'] );
	}

	public function test_a_failed_check_changes_nothing_and_is_not_repeated_at_once(): void {
		$this->fake_api( 404, array( 'message' => 'Not Found' ) );
		$file = plugin_basename( WPLC_FILE );

		$this->assertFalse( apply_filters( 'update_plugins_github.com', false, array(), $file, array() ) );
		$this->assertFalse( apply_filters( 'update_plugins_github.com', false, array(), $file, array() ) );
		$this->assertSame( 1, $this->requests );
	}

	public function test_switched_off_no_request_is_made(): void {
		$this->fake_api( 200, array( 'tag_name' => 'v99.0.0' ) );
		add_filter( 'wplc_self_update_check_enabled', '__return_false' );

		$this->assertFalse( apply_filters( 'update_plugins_github.com', false, array(), plugin_basename( WPLC_FILE ), array() ) );
		$this->assertSame( 0, $this->requests );
	}
}
