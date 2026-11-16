<?php
/**
 * WFC-LIC-05: the read-only license facade returns only the documented fields and never writes.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\ReadFacade;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

/**
 * In-memory stand-in for wpdb that answers the SELECTs of ReadFacade and records every call.
 *
 * It honours the column list of a SELECT, so a facade that loaded `*` would see the secret
 * columns, and every writing method is recorded instead of executed.
 */
final class WPLC_Read_Facade_Test_Db {
	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $last_error = '';
	/** @var array<int,array<string,mixed>> */
	public $licenses = array();
	/** @var array<int,array<string,mixed>> */
	public $activations = array();
	/** @var array<int,array<string,mixed>> */
	public $entitlements = array();
	/** @var array<int,array<string,mixed>> */
	public $renewals = array();
	/** @var array<int,array<string,mixed>> */
	public $plans = array();
	/** @var string[] Every query that reached a read method. */
	public $queries = array();
	/** @var string[] Calls of writing methods. */
	public $writes = array();
	/** @var array<int,mixed> */
	private $args = array();

	public function prepare( $query, ...$args ) {
		$this->args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return $query;
	}

	public function get_row( $query, $output = null ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'wplc_licenses' ) || ( isset( $this->args[0] ) && 'wp_wplc_licenses' === $this->args[0] ) ) {
			$row = $this->licenses[ (int) $this->args[1] ] ?? null;
			return is_array( $row ) ? $this->columns( $query, $row ) : null;
		}
		if ( isset( $this->args[0] ) && 'wp_wplc_plans' === $this->args[0] ) {
			return null; // Lookup by purchased product: no WooCommerce order in unit tests.
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = $query;
		if ( isset( $this->args[0] ) && 'wp_wplc_activations' === $this->args[0] ) {
			$counts = array();
			foreach ( $this->activations as $row ) {
				if ( (int) $row['license_id'] === (int) $this->args[1] && $row['status'] === $this->args[2] ) {
					$counts[ $row['environment'] ] = ( $counts[ $row['environment'] ] ?? 0 ) + 1;
				}
			}
			$rows = array();
			foreach ( $counts as $environment => $count ) {
				$rows[] = array( 'environment' => $environment, 'activations' => (string) $count );
			}
			return $rows;
		}
		if ( isset( $this->args[0] ) && 'wp_wplc_plans' === $this->args[0] ) {
			return array_values(
				array_filter(
					$this->plans,
					function ( $plan ) {
						return $plan['product_key'] === $this->args[1] && $plan['plan_key'] === $this->args[2];
					}
				)
			);
		}
		return array();
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'wplc_entitlements' ) ) {
			foreach ( $this->entitlements as $row ) {
				if ( (int) $row['license_id'] === (int) $this->args[0] && $row['entitlement_key'] === $this->args[1] && $row['status'] === $this->args[2] ) {
					return $row['value_json'];
				}
			}
			return null;
		}
		if ( isset( $this->args[0] ) && 'wp_wplc_renewals' === $this->args[0] ) {
			$max = null;
			foreach ( $this->renewals as $row ) {
				if ( (int) $row['license_id'] === (int) $this->args[1] && $row['status'] === $this->args[2] && ( null === $max || $row['updated_at'] > $max ) ) {
					$max = $row['updated_at'];
				}
			}
			return $max;
		}
		return null;
	}

	public function get_col( $query ) {
		$this->queries[] = $query;
		$ids = array();
		foreach ( $this->licenses as $row ) {
			if ( (int) $row['customer_id'] === (int) $this->args[1] ) {
				$ids[] = (string) $row['id'];
			}
		}
		rsort( $ids );
		return array_slice( $ids, 0, (int) $this->args[2] );
	}

	public function query( $query ) {
		$this->writes[] = 'query: ' . $query;
		return 1;
	}

	public function insert( $table, $data = array(), $format = null ) {
		$this->writes[] = 'insert: ' . $table;
		return 1;
	}

	public function update( $table, $data = array(), $where = array(), $format = null, $where_format = null ) {
		$this->writes[] = 'update: ' . $table;
		return 1;
	}

	public function replace( $table, $data = array(), $format = null ) {
		$this->writes[] = 'replace: ' . $table;
		return 1;
	}

	public function delete( $table, $where = array(), $where_format = null ) {
		$this->writes[] = 'delete: ' . $table;
		return 1;
	}

	/**
	 * The columns of $row a SELECT names (all for `*`).
	 *
	 * @param string              $query Query.
	 * @param array<string,mixed> $row   Stored row.
	 * @return array<string,mixed>
	 */
	private function columns( string $query, array $row ): array {
		if ( ! preg_match( '/^SELECT\s+(.+?)\s+FROM/is', $query, $match ) || '*' === trim( $match[1] ) ) {
			return $row;
		}
		$selected = array();
		foreach ( array_map( 'trim', explode( ',', $match[1] ) ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$selected[ $column ] = $row[ $column ];
			}
		}
		return $selected;
	}
}

final class ReadFacadeTest extends TestCase {

	private const KEY      = 'WPLC-SECRET-KEY-MARKER-0001';
	private const KEY_HASH = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';
	private const UUID     = '0d9b6c1e-1111-4222-8333-444455556666';
	private const PREFIX   = 'WPLC-SECRETPF';
	private const EMAIL    = 'marker.customer@example.invalid';
	private const SITE     = 'https://marker-site.example.invalid';
	private const INSTALL  = '9f8e7d6c-aaaa-4bbb-8ccc-dddd00001111';
	private const TOKEN    = 'pi_marker_payment_ref';

	/** @var WPLC_Read_Facade_Test_Db */
	private $db;

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new WPLC_Read_Facade_Test_Db();
		$GLOBALS['wpdb']     = $this->db;
		$GLOBALS['wplc_test_filters'] = array();

		$this->db->licenses[7] = array(
			'id'                    => '7',
			'license_uuid'          => self::UUID,
			'license_key_hash'      => self::KEY_HASH,
			'license_key_secret_id' => 'test-2026',
			'key_prefix'            => self::PREFIX,
			'license_key'           => self::KEY, // Never stored; here to prove it would not leak.
			'customer_email'        => self::EMAIL, // Not a column; here to prove it would not leak.
			'customer_id'           => '42',
			'product_key'           => 'workflow-captain-pro',
			'plan_key'              => 'business',
			'status'                => 'active',
			'status_reason'         => 'Support note about ' . self::EMAIL,
			'payment_gateway_id'    => 'stripe',
			'gateway_customer_ref'  => 'cus_marker',
			'gateway_method_ref'    => self::TOKEN,
			'billing_interval'      => 'year',
			'starts_at'             => '2026-01-15 10:00:00',
			'paid_until'            => '2027-01-15 10:00:00',
			'source_order_id'       => '1001',
			'source_order_item_id'  => '5',
			'created_at'            => '2026-01-15 10:00:00',
			'updated_at'            => '2026-01-15 10:00:00',
		);
		$this->db->licenses[8] = array(
			'id'                   => '8',
			'license_uuid'         => '0d9b6c1e-2222-4222-8333-444455556666',
			'license_key_hash'     => str_repeat( 'b', 64 ),
			'key_prefix'           => 'WPLC-OTHERPF',
			'customer_id'          => '0',
			'product_key'          => 'pass-captain-pro',
			'plan_key'             => 'single',
			'status'               => 'pending',
			'billing_interval'     => 'year',
			'paid_until'           => null,
			'source_order_id'      => null,
			'source_order_item_id' => null,
		);
		$this->db->licenses[9] = array_merge( $this->db->licenses[8], array( 'id' => '9', 'customer_id' => '42' ) );

		$activation = array(
			'license_id'                    => 7,
			'installation_uuid'             => self::INSTALL,
			'installation_secret_hash'      => self::KEY_HASH,
			'installation_secret_encrypted' => 'encrypted-marker',
			'site_url'                      => self::SITE,
			'status'                        => 'active',
		);
		$this->db->activations = array(
			$activation + array( 'environment' => 'production' ),
			$activation + array( 'environment' => 'production' ),
			$activation + array( 'environment' => 'staging' ),
			$activation + array( 'environment' => 'development' ),
			array_merge( $activation, array( 'environment' => 'production', 'status' => 'inactive' ) ),
			array_merge( $activation, array( 'environment' => 'production', 'license_id' => 8 ) ),
		);
		$this->db->entitlements = array(
			array( 'license_id' => 7, 'entitlement_key' => 'sites.live_limit', 'value_json' => '5', 'status' => 'active' ),
		);
		$this->db->renewals = array(
			array( 'license_id' => 7, 'status' => 'paid', 'updated_at' => '2026-01-15 10:05:00' ),
			array( 'license_id' => 7, 'status' => 'paid', 'updated_at' => '2026-12-20 08:30:00' ),
			array( 'license_id' => 7, 'status' => 'failed', 'updated_at' => '2027-01-02 00:00:00' ),
		);
		$this->db->plans = array(
			array( 'id' => 3, 'product_key' => 'pass-captain-pro', 'plan_key' => 'single', 'billing_interval' => 'year', 'price_minor' => 4900, 'currency' => 'EUR', 'live_site_limit' => '1', 'active' => 1 ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']              = $this->previous_wpdb;
		$GLOBALS['wplc_test_filters'] = array();
	}

	public function test_returns_exactly_the_documented_fields(): void {
		$license = ReadFacade::get( 7 );

		$this->assertIsArray( $license );
		$this->assertSame( ReadFacade::KEYS, array_keys( $license ) );
		$this->assertSame(
			array(
				'license_id'             => 7,
				'product_key'            => 'workflow-captain-pro',
				'plan_key'               => 'business',
				'status'                 => 'active',
				'expires_at'             => '2027-01-15T10:00:00Z',
				'renewed_at'             => '2026-12-20T08:30:00Z',
				'live_site_count'        => 2,
				'live_site_limit'        => 5,
				'staging_site_count'     => 1,
				'development_site_count' => 1,
				'development_site_limit' => 10,
				'order_id'               => 1001,
				'customer_user_id'       => 42,
			),
			$license
		);
	}

	public function test_never_returns_key_hash_uuid_email_sites_or_payment_references(): void {
		foreach ( array( 7, 8 ) as $id ) {
			$encoded = (string) json_encode( ReadFacade::get( $id ) );
			foreach ( array( self::KEY, self::KEY_HASH, self::UUID, self::PREFIX, 'WPLC-OTHERPF', self::EMAIL, self::SITE, self::INSTALL, self::TOKEN, 'cus_marker', 'encrypted-marker', str_repeat( 'b', 64 ) ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $encoded, 'License ' . $id );
			}
			foreach ( array( 'license_key', 'license_key_hash', 'key_hash', 'license_uuid', 'key_prefix', 'email', 'site_url', 'installation_uuid', 'status_reason' ) as $forbidden ) {
				$this->assertStringNotContainsString( '"' . $forbidden . '"', $encoded );
			}
		}
	}

	public function test_the_license_row_is_read_by_an_explicit_column_list(): void {
		ReadFacade::get( 7 );

		$selects = implode( "\n", $this->db->queries );
		$this->assertStringNotContainsString( 'SELECT *', $selects, 'ReadFacade reads no whole row.' );
		foreach ( array( 'license_key_hash', 'license_uuid', 'key_prefix', 'gateway_', 'site_url', 'installation_' ) as $column ) {
			$this->assertStringNotContainsString( $column, $selects );
		}
	}

	public function test_issues_no_writing_query(): void {
		ReadFacade::get( 7 );
		ReadFacade::get( 8 );
		ReadFacade::get( 404 );
		ReadFacade::license_ids_for_customer( 42 );

		$this->assertSame( array(), $this->db->writes );
		$this->assertNotEmpty( $this->db->queries );
		foreach ( $this->db->queries as $query ) {
			$this->assertMatchesRegularExpression( '/^\s*SELECT\s/i', $query );
			$this->assertDoesNotMatchRegularExpression( '/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|GET_LOCK)\b/i', $query );
		}
	}

	public function test_unknown_and_invalid_ids_return_null(): void {
		$this->assertNull( ReadFacade::get( 404 ) );
		$this->assertNull( ReadFacade::get( 0 ) );
		$this->assertNull( ReadFacade::get( -7 ) );
		$this->assertSame( array(), ReadFacade::license_ids_for_customer( 0 ) );
		$this->assertSame( array(), ReadFacade::license_ids_for_customer( 999 ) );
	}

	public function test_empty_values_are_null_and_the_limit_falls_back_to_the_plan(): void {
		$license = ReadFacade::get( 8 );

		$this->assertIsArray( $license );
		$this->assertSame( ReadFacade::KEYS, array_keys( $license ) );
		$this->assertNull( $license['expires_at'] );
		$this->assertNull( $license['renewed_at'] );
		$this->assertNull( $license['order_id'] );
		$this->assertNull( $license['customer_user_id'] );
		$this->assertSame( 1, $license['live_site_count'] );
		$this->assertSame( 1, $license['live_site_limit'] );
		$this->assertSame( 5, $license['development_site_limit'] );
	}

	public function test_licenses_of_a_customer_are_ids_newest_first(): void {
		$this->assertSame( array( 9, 7 ), ReadFacade::license_ids_for_customer( 42 ) );
	}

	public function test_the_development_limit_filter_gets_no_secret_column(): void {
		$seen = array();
		$GLOBALS['wplc_test_filters']['wplc_development_site_limit'][] = static function ( $limit, $license ) use ( &$seen ) {
			$seen = $license;
			return 3;
		};

		$this->assertSame( 3, ReadFacade::get( 7 )['development_site_limit'] );
		$this->assertArrayNotHasKey( 'license_key_hash', $seen );
		$this->assertArrayNotHasKey( 'license_uuid', $seen );
		$this->assertArrayNotHasKey( 'key_prefix', $seen );
	}

	public function test_the_facade_is_documented(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/EXTENSION-POINTS.md' );

		$this->assertStringContainsString( 'WPLC\License\ReadFacade', $doc );
		foreach ( ReadFacade::KEYS as $key ) {
			$this->assertStringContainsString( '`' . $key . '`', $doc );
		}
	}
}
