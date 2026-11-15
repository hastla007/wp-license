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
		$this->assertArrayHasKey( '13', $steps );
		$this->assertSame( 'step_13_renewal_product_column', $steps['13'][1] );
		// An install on schema 11 runs 12, 13 and every later step.
		$this->assertSame( array( '12', '13' ), Schema::pending_step_versions( '11', '13', array_keys( $steps ) ) );
	}

	public function test_schema_14_adds_the_renewal_review_step(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();
		$this->assertArrayHasKey( '14', $steps );
		$this->assertSame( 'step_14_renewal_review_columns', $steps['14'][1] );
		// An install on schema 13 runs step 14; one on 12 runs 13 and 14.
		$this->assertSame( array( '14' ), Schema::pending_step_versions( '13', '14', array_keys( $steps ) ) );
		$this->assertSame( array( '13', '14' ), Schema::pending_step_versions( '12', '14', array_keys( $steps ) ) );
	}

	public function test_schema_15_adds_the_list_index_step(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();
		$this->assertArrayHasKey( '15', $steps );
		$this->assertSame( 'step_15_list_indexes', $steps['15'][1] );
		// An install on schema 14 runs 15; one on 13 runs 14 and 15.
		$this->assertSame( array( '15' ), Schema::pending_step_versions( '14', '15', array_keys( $steps ) ) );
		$this->assertSame( array( '14', '15' ), Schema::pending_step_versions( '13', '15', array_keys( $steps ) ) );
	}

	public function test_schema_16_adds_the_grant_activation_step(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();
		$this->assertArrayHasKey( '16', $steps );
		$this->assertSame( 'step_16_grant_activation_column', $steps['16'][1] );
		$this->assertSame( array( '16' ), Schema::pending_step_versions( '15', '16', array_keys( $steps ) ) );
	}

	public function test_schema_17_adds_the_plan_change_step(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();
		$this->assertArrayHasKey( '17', $steps );
		$this->assertSame( 'step_17_plan_change_columns', $steps['17'][1] );
		$this->assertSame( array( '17' ), Schema::pending_step_versions( '16', '17', array_keys( $steps ) ) );
		$this->assertSame( array( '16', '17' ), Schema::pending_step_versions( '15', '17', array_keys( $steps ) ) );
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
			array( 'products', 'plans', 'licenses', 'activations', 'entitlements', 'renewals', 'download_tokens', 'event_inbox', 'request_nonces', 'request_limits', 'audit_log', 'releases', 'package_streams', 'consents', 'reminder_log' ),
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
				'status varchar(10) NOT NULL DEFAULT \'sent\',',
				'PRIMARY KEY  (id),',
				'UNIQUE KEY license_reminder (license_id,reminder_type,period_end),',
				'KEY sent_at (sent_at)',
			),
			$lines
		);
	}

	/**
	 * Both development lines used 12 to 20; step 21 runs every step of both again.
	 */
	public function test_schema_21_merges_both_development_lines(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();

		$this->assertSame( 'step_21_merged_schema', $steps['21'][1] );
		$this->assertSame( 'step_18_adopt_reminder_log', $steps['18'][1] );
		$this->assertSame( 'step_19_one_heartbeat_store', $steps['19'][1] );
		// An install recorded at 17 (line A) runs the steps of line B and the merge step.
		$this->assertSame( array( '18', '19', '21' ), Schema::pending_step_versions( '17', '21', array_keys( $steps ) ) );
		// An install recorded at 20 (line B) runs only the merge step, which repeats every data step.
		$this->assertSame( array( '21' ), Schema::pending_step_versions( '20', '21', array_keys( $steps ) ) );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/Schema.php' );
		$this->assertSame( 1, preg_match( '/function step_21_merged_schema\(\).*?\n\t}\n/s', $source, $match ) );
		foreach ( array_keys( $steps ) as $version ) {
			if ( version_compare( (string) $version, '21', '>=' ) ) {
				// Step 21 repeats the steps of both lines before the merge; later steps run after it.
				continue;
			}
			$this->assertStringContainsString( "'" . $steps[ $version ][1] . "'", $match[0], 'Step 21 does not repeat step ' . $version );
		}
	}

	/**
	 * P-31/P-53: schema 22 adds the payment-token and last-event indexes; installs on 21 run only step 22.
	 */
	public function test_schema_22_adds_the_lookup_index_step(): void {
		$property = new ReflectionProperty( Schema::class, 'steps' );
		$property->setAccessible( true );
		$steps = $property->getValue();

		$this->assertSame( '22', Schema::VERSION );
		$this->assertSame( 'step_22_lookup_indexes', $steps['22'][1] );
		$this->assertSame( array( '22' ), Schema::pending_step_versions( '21', Schema::VERSION, array_keys( $steps ) ) );
		// Installs of either development line run the merge step and step 22.
		$this->assertSame( array( '21', '22' ), Schema::pending_step_versions( '20', Schema::VERSION, array_keys( $steps ) ) );
		$this->assertSame( array( '18', '19', '21', '22' ), Schema::pending_step_versions( '17', Schema::VERSION, array_keys( $steps ) ) );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/Schema.php' );
		$this->assertStringContainsString( 'KEY payment_token (payment_token_id)', $source );
		$this->assertStringContainsString( 'KEY source_received (source,received_at)', $source );
	}

	public function test_create_statements_contain_the_columns_of_both_lines(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Database/Schema.php' );
		foreach ( array( 'renewal_product_id', 'pending_plan_key', 'pending_product_id', 'plan_order_id', 'review_reason', 'review_at', 'activation_id bigint', 'renewal_amount_includes_tax', 'gateway_customer_ref', 'gateway_method_ref', 'gateway_mode', 'last_error', 'last_attempt_at', 'requires_core', 'plugin_file', 'KEY source_order', 'KEY customer_created', 'KEY status_deactivated', 'KEY outcome_processed' ) as $needle ) {
			$this->assertStringContainsString( $needle, $source, $needle );
		}
	}
}
