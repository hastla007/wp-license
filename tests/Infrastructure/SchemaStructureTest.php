<?php
/**
 * Structure verification helpers of the schema (B-12, A-106, B-118).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Infrastructure\Database\Schema;

final class SchemaStructureTest extends TestCase {

	private const STATEMENT = "CREATE TABLE wp_wplc_demo (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				key_prefix varchar(32) NOT NULL,
				status varchar(30) NOT NULL DEFAULT 'scheduled',
				currency char(3) NOT NULL DEFAULT 'EUR',
				index_hint INTEGER NOT NULL DEFAULT 0,
				notes longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY status_key (status),
				KEY created_at (created_at)
			) DEFAULT CHARSET=utf8mb4;";

	// B-12: the CREATE TABLE statements are the single source of the expected structure.
	public function test_columns_are_read_from_the_create_statement_and_keys_are_ignored(): void {
		$parsed = Schema::expected_columns( self::STATEMENT );

		$this->assertSame( 'wp_wplc_demo', $parsed['table'] );
		$this->assertSame(
			array( 'id', 'key_prefix', 'status', 'currency', 'index_hint', 'notes', 'created_at' ),
			array_keys( $parsed['columns'] ),
			'Columns whose name starts with "key" or "index" are columns, the KEY lines are not.'
		);
		$this->assertSame( array( 'type' => 'varchar', 'length' => 30 ), $parsed['columns']['status'] );
		$this->assertSame( array( 'type' => 'char', 'length' => 3 ), $parsed['columns']['currency'] );
		$this->assertSame( 'int', $parsed['columns']['index_hint']['type'] );
		$this->assertSame( 'longtext', $parsed['columns']['notes']['type'] );
	}

	public function test_a_statement_without_create_table_yields_nothing(): void {
		$this->assertSame( array( 'table' => '', 'columns' => array() ), Schema::expected_columns( 'ALTER TABLE x ADD y int' ) );
	}

	// B-12: a failed ALTER leaves the narrow column; that must not count as migrated.
	public function test_narrow_missing_and_wrongly_typed_columns_are_problems(): void {
		$expected = Schema::expected_columns( self::STATEMENT )['columns'];
		$actual   = array(
			'id'         => array( 'type' => 'bigint', 'length' => 0 ),
			'key_prefix' => array( 'type' => 'varchar', 'length' => 64 ),
			'status'     => array( 'type' => 'varchar', 'length' => 20 ),
			'currency'   => array( 'type' => 'char', 'length' => 3 ),
			'index_hint' => array( 'type' => 'int', 'length' => 0 ),
			'notes'      => array( 'type' => 'text', 'length' => 65535 ),
		);

		$this->assertSame(
			array(
				'wp_wplc_demo.status: varchar(20), expected varchar(30)',
				'wp_wplc_demo.notes: text, expected longtext',
				'wp_wplc_demo.created_at: column is missing',
			),
			Schema::column_problems( 'wp_wplc_demo', $expected, $actual )
		);
	}

	public function test_a_complete_table_has_no_problems_and_a_wider_column_is_accepted(): void {
		$expected = Schema::expected_columns( self::STATEMENT )['columns'];
		$actual   = array();
		foreach ( $expected as $name => $definition ) {
			$actual[ $name ] = array( 'type' => strtoupper( $definition['type'] ), 'length' => $definition['length'] + 10 );
		}

		$this->assertSame( array(), Schema::column_problems( 'wp_wplc_demo', $expected, $actual ) );
	}

	public function test_a_missing_table_is_one_problem(): void {
		$expected = Schema::expected_columns( self::STATEMENT )['columns'];

		$this->assertSame( array( 'wp_wplc_demo: table is missing' ), Schema::column_problems( 'wp_wplc_demo', $expected, array() ) );
	}

	// Self-healing: an up-to-date schema is re-checked once per interval, not per request.
	public function test_structure_check_is_due_once_per_interval_and_after_a_version_change(): void {
		$now = 1800000000;

		$this->assertTrue( Schema::verification_due( '', '18', $now, 86400 ) );
		$this->assertTrue( Schema::verification_due( 'garbage', '18', $now, 86400 ) );
		$this->assertTrue( Schema::verification_due( '17|' . $now, '18', $now, 86400 ), 'Marker of another version.' );
		$this->assertFalse( Schema::verification_due( '18|' . ( $now - 100 ), '18', $now, 86400 ) );
		$this->assertTrue( Schema::verification_due( '18|' . ( $now - 86400 ), '18', $now, 86400 ) );
		$this->assertTrue( Schema::verification_due( '18|' . ( $now + 500 ), '18', $now, 86400 ), 'A marker from the future (clock change) does not block the check.' );
	}

	// B-103 / T-144: the reminder log is a schema table and therefore dropped with a deleted site.
	public function test_reminder_log_is_a_schema_table(): void {
		$tables = Schema::table_names_for_prefix( 'wp_7_' );

		$this->assertSame( 'wp_7_wplc_reminder_log', $tables['reminder_log'] );
	}
}
