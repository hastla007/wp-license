<?php
use PHPUnit\Framework\TestCase;
use WPLC\Infrastructure\Database\Schema;

final class SchemaMigrationStepsTest extends TestCase {
	public function test_steps_between_installed_and_target_run_in_version_order(): void {
		$this->assertSame(
			array( '9', '10', '11' ),
			Schema::pending_step_versions( '8', '11', array( '11', '9', '2', '10', '12' ) )
		);
	}

	public function test_versions_are_compared_numerically_not_as_strings(): void {
		// As strings "10" < "9"; a string sort would skip or reorder steps.
		$this->assertSame( array( '10' ), Schema::pending_step_versions( '9', '10', array( '9', '10' ) ) );
		$this->assertSame( array( '9', '10' ), Schema::pending_step_versions( '8', '10', array( '10', '9' ) ) );
	}

	public function test_fresh_install_runs_every_step_up_to_the_target(): void {
		$this->assertSame( array( '3', '10' ), Schema::pending_step_versions( '0', '10', array( 10, 3, 11 ) ) );
	}

	public function test_current_or_newer_install_runs_nothing(): void {
		$this->assertSame( array(), Schema::pending_step_versions( '10', '10', array( '9', '10' ) ) );
		$this->assertSame( array(), Schema::pending_step_versions( '11', '10', array( '9', '10', '11' ) ) );
		$this->assertSame( array(), Schema::pending_step_versions( '5', '10', array() ) );
	}

	public function test_duplicate_step_versions_run_once(): void {
		$this->assertSame( array( '10' ), Schema::pending_step_versions( '9', '10', array( '10', 10 ) ) );
	}

	public function test_every_registered_step_is_within_the_current_version(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();
		$this->assertNotEmpty( $steps );
		foreach ( array_keys( $steps ) as $version ) {
			$this->assertTrue( version_compare( (string) $version, Schema::VERSION, '<=' ), 'Step ' . $version . ' is newer than Schema::VERSION.' );
			$this->assertTrue( method_exists( Schema::class, $steps[ $version ][1] ), 'Missing step method for version ' . $version );
		}
		$this->assertSame(
			array_map( 'strval', array_keys( $steps ) ),
			Schema::pending_step_versions( '0', Schema::VERSION, array_keys( $steps ) ),
			'Steps must be registered in ascending version order.'
		);
	}

	public function test_schema_13_adds_the_renewal_product_step(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();
		$this->assertSame( '13', Schema::VERSION );
		$this->assertArrayHasKey( '13', $steps );
		$this->assertSame( 'step_13_renewal_product_column', $steps['13'][1] );
		// An install on schema 12 runs only the new step; one on 11 runs 12 and 13.
		$this->assertSame( array( '13' ), Schema::pending_step_versions( '12', Schema::VERSION, array_keys( $steps ) ) );
		$this->assertSame( array( '12', '13' ), Schema::pending_step_versions( '11', Schema::VERSION, array_keys( $steps ) ) );
	}

	public function test_each_successful_step_is_recorded_in_order(): void {
		$log = array();
		$steps = array(
			'9' => static function () use ( &$log ) {
				$log[] = 'run 9';
				return true;
			},
			'10' => static function () use ( &$log ) {
				$log[] = 'run 10';
				return true;
			},
		);
		$ok = Schema::run_steps(
			array( '9', '10' ),
			$steps,
			static function ( $version ) use ( &$log ) {
				$log[] = 'record ' . $version;
			}
		);
		$this->assertTrue( $ok );
		$this->assertSame( array( 'run 9', 'record 9', 'run 10', 'record 10' ), $log );
	}

	public function test_failed_step_stops_the_run_and_keeps_the_last_good_version(): void {
		$recorded = array();
		$ran = array();
		$steps = array(
			'9' => static function () use ( &$ran ) {
				$ran[] = '9';
				return true;
			},
			'10' => static function () use ( &$ran ) {
				$ran[] = '10';
				return false;
			},
			'11' => static function () use ( &$ran ) {
				$ran[] = '11';
				return true;
			},
		);
		$record = static function ( $version ) use ( &$recorded ) {
			$recorded[] = $version;
		};
		$this->assertFalse( Schema::run_steps( array( '9', '10', '11' ), $steps, $record ) );
		$this->assertSame( array( '9', '10' ), $ran );
		$this->assertSame( array( '9' ), $recorded );

		// Next request: only the failed step and its successors are selected again.
		$this->assertSame( array( '10', '11' ), Schema::pending_step_versions( '9', '11', array_keys( $steps ) ) );
	}

	public function test_throwing_step_counts_as_failure(): void {
		$recorded = array();
		$steps = array(
			'10' => static function () {
				throw new RuntimeException( 'boom' );
			},
		);
		$ok = Schema::run_steps(
			array( '10' ),
			$steps,
			static function ( $version ) use ( &$recorded ) {
				$recorded[] = $version;
			}
		);
		$this->assertFalse( $ok );
		$this->assertSame( array(), $recorded );
	}

	public function test_step_must_return_true_explicitly(): void {
		$steps = array(
			'10' => static function () {
				return null;
			},
		);
		$this->assertFalse( Schema::run_steps( array( '10' ), $steps, static function () {} ) );
		$this->assertFalse( Schema::run_steps( array( '12' ), $steps, static function () {} ) );
	}

	public function test_table_names_are_stable_per_site_prefix(): void {
		$tables = Schema::table_names_for_prefix( 'wp_2_' );
		$this->assertSame(
			array( 'products', 'plans', 'licenses', 'activations', 'entitlements', 'renewals', 'download_tokens', 'event_inbox', 'request_nonces', 'request_limits', 'audit_log', 'releases', 'package_streams', 'reminder_log' ),
			array_keys( $tables )
		);
		$this->assertSame( 'wp_2_wplc_licenses', $tables['licenses'] );
		$this->assertSame( 'wp_2_wplc_releases', $tables['releases'] );
		$this->assertSame( 'wp_2_wplc_package_streams', $tables['package_streams'] );
		// Same name ReminderLog used for its self-installed table up to schema 11.
		$this->assertSame( 'wp_2_wplc_reminder_log', $tables['reminder_log'] );
	}

	public function test_upgrade_from_schema_11_adopts_the_reminder_log(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();

		$this->assertArrayHasKey( '12', $steps );
		$this->assertSame( 'step_12_adopt_reminder_log', $steps['12'][1] );
		$this->assertContains( '12', Schema::pending_step_versions( '11', Schema::VERSION, array_keys( $steps ) ) );
		$this->assertSame( 'wplc_reminder_log_version', Schema::LEGACY_REMINDER_LOG_VERSION_OPTION );
	}

	public function test_every_table_has_a_create_statement(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/Schema.php' );
		foreach ( array_keys( Schema::table_names_for_prefix( 'wp_' ) ) as $key ) {
			$this->assertStringContainsString( "CREATE TABLE {\$tables['" . $key . "']} (", $source, 'No CREATE TABLE for ' . $key );
		}
	}

	public function test_reminder_log_layout_matches_the_former_self_installed_table(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/Schema.php' );
		$this->assertSame( 1, preg_match( "/CREATE TABLE \\{\\\$tables\\['reminder_log'\\]\\} \\((.*?)\\) \\{\\\$charset_collate\\};/s", $source, $match ) );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $match[1] ) ) ) );

		// dbDelta must find nothing to change on installs where ReminderLog created the table.
		$this->assertSame(
			array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,',
				'license_id bigint(20) unsigned NOT NULL,',
				'reminder_type varchar(40) NOT NULL,',
				'period_end datetime NOT NULL,',
				'sent_at datetime NOT NULL,',
				'PRIMARY KEY  (id),',
				'UNIQUE KEY license_reminder (license_id,reminder_type,period_end),',
				'KEY sent_at (sent_at)',
			),
			$lines
		);
	}
}
