<?php
/**
 * A-133: one structured log writer with a correlation ID and a debug switch.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Infrastructure\Log;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

if ( ! class_exists( 'WPLC_Test_Logger' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- stand-in for the WooCommerce logger.
	final class WPLC_Test_Logger {
		/** @var array<int,array{0:string,1:string,2:array<string,mixed>}> */
		public $lines = array();

		public function log( $level, $message, $context = array() ): void {
			$this->lines[] = array( $level, $message, $context );
		}
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		if ( ! isset( $GLOBALS['wplc_test_logger'] ) ) {
			$GLOBALS['wplc_test_logger'] = new WPLC_Test_Logger();
		}
		return $GLOBALS['wplc_test_logger'];
	}
}

final class LogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wplc_test_logger'] = new WPLC_Test_Logger();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wplc_test_logger'], $GLOBALS['wplc_test_filters']['wplc_log_debug'] );
	}

	public function test_lines_carry_source_correlation_id_and_object_ids(): void {
		$id = Log::begin();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $id );

		$this->assertTrue( Log::write( 'warning', 'Charge failed', array( 'renewal_id' => 7, 'order_id' => 12, 'nested' => array( 'dropped' ) ) ) );
		Log::write( 'error', 'Second line' );

		$lines = $GLOBALS['wplc_test_logger']->lines;
		$this->assertCount( 2, $lines );
		$this->assertSame( 'warning', $lines[0][0] );
		$this->assertSame( array( 'renewal_id' => 7, 'order_id' => 12, 'source' => 'wp-license', 'request_id' => $id ), $lines[0][2] );
		$this->assertSame( $id, $lines[1][2]['request_id'], 'One request, one correlation ID.' );

		$next = Log::begin( 'evt_123' );
		$this->assertSame( 'evt_123', $next );
		$this->assertSame( 'evt_123', Log::request_id() );
		$this->assertSame( 'abc', Log::begin( 'a<b>c' ), 'Only safe characters are kept.' );
	}

	public function test_email_addresses_are_masked(): void {
		Log::write( 'error', 'Mail to jane@example.com failed', array( 'to' => 'jane@example.com' ) );

		$line = $GLOBALS['wplc_test_logger']->lines[0];
		$this->assertSame( 'Mail to [email] failed', $line[1] );
		$this->assertSame( '[email]', $line[2]['to'] );
	}

	public function test_debug_lines_need_the_switch(): void {
		$this->assertFalse( Log::write( 'debug', 'noise' ) );
		$this->assertSame( array(), $GLOBALS['wplc_test_logger']->lines );

		$GLOBALS['wplc_test_filters']['wplc_log_debug'] = array(
			static function () {
				return true;
			},
		);
		$this->assertTrue( Log::write( 'debug', 'detail' ) );
		$this->assertSame( 'debug', $GLOBALS['wplc_test_logger']->lines[0][0] );
	}

	public function test_unknown_levels_become_info(): void {
		Log::write( 'loud', 'x' );

		$this->assertSame( 'info', $GLOBALS['wplc_test_logger']->lines[0][0] );
	}
}
