<?php
/**
 * B-24: diagnostics recognise read/write-splitting database drop-ins.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Diagnostics;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- small stand-ins for database classes.
if ( ! class_exists( 'WPLC_Test_Plain_Db' ) ) {
	class WPLC_Test_Plain_Db {
	}
	class WPLC_Test_Logging_Db extends WPLC_Test_Plain_Db {
		public function log_query(): void {
		}
	}
	class WPLC_Test_Splitting_Db {
		public function add_database( array $server ): void {
		}
		public function send_reads_to_master(): void {
		}
	}
	class WPLC_Test_Splitting_Without_Switch_Db {
		public function add_database( array $server ): void {
		}
	}
}

final class DiagnosticsDatabaseLayerTest extends TestCase {

	public function test_the_standard_class_is_not_flagged(): void {
		$layer = Diagnostics::database_layer( new WPLC_Test_Plain_Db(), false );

		$this->assertFalse( $layer['splitting'] );
		$this->assertFalse( $layer['warning'] );
		$this->assertFalse( $layer['drop_in'] );
	}

	public function test_a_drop_in_without_replicas_is_reported_but_not_flagged(): void {
		// Query Monitor and similar tools install db.php without splitting reads.
		$layer = Diagnostics::database_layer( new WPLC_Test_Logging_Db(), true );

		$this->assertTrue( $layer['drop_in'] );
		$this->assertFalse( $layer['warning'] );
		$this->assertSame( 'WPLC_Test_Logging_Db', $layer['class'] );
	}

	public function test_a_splitting_layer_is_flagged_and_its_primary_switch_detected(): void {
		$layer = Diagnostics::database_layer( new WPLC_Test_Splitting_Db(), true );
		$this->assertTrue( $layer['splitting'] );
		$this->assertTrue( $layer['primary_switch'] );
		$this->assertTrue( $layer['warning'] );

		$layer = Diagnostics::database_layer( new WPLC_Test_Splitting_Without_Switch_Db(), true );
		$this->assertTrue( $layer['warning'] );
		$this->assertFalse( $layer['primary_switch'] );
	}
}
