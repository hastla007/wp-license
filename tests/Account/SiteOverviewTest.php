<?php
/**
 * Pure helpers of the site overview in the customer account.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Account\SiteOverview;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class SiteOverviewTest extends TestCase {

	/** 2026-11-14 12:00:00 UTC. */
	private const NOW = 1794657600;

	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'activation_id'        => 1,
				'site_url'             => 'https://shop.example',
				'environment'          => 'production',
				'product_key'          => 'voucher-captain-pro',
				'product_version'      => '1.2.0',
				'license_status'       => 'active',
				'paid_until'           => gmdate( 'Y-m-d H:i:s', self::NOW + 200 * 86400 ),
				'renewal_enabled'      => 1,
				'cancel_at_period_end' => 0,
				'activated_at'         => gmdate( 'Y-m-d H:i:s', self::NOW - 90 * 86400 ),
				'last_seen_at'         => gmdate( 'Y-m-d H:i:s', self::NOW - 3600 ),
			),
			$overrides
		);
	}

	public function test_a_healthy_activation_has_no_warning(): void {
		$this->assertSame( array(), SiteOverview::warnings( $this->row(), '1.2.0', self::NOW ) );
		$this->assertSame( array(), SiteOverview::warnings( $this->row(), null, self::NOW ), 'No published release: nothing to compare.' );
		$this->assertSame( array(), SiteOverview::warnings( $this->row( array( 'product_version' => '1.10.0' ) ), '1.9.0', self::NOW ), 'Versions compare numerically.' );
	}

	public function test_each_warning(): void {
		$this->assertSame( array( 'license_inactive' ), SiteOverview::warnings( $this->row( array( 'license_status' => 'expired' ) ), null, self::NOW ) );
		$this->assertSame( array( 'license_inactive' ), SiteOverview::warnings( $this->row( array( 'license_status' => 'revoked' ) ), null, self::NOW ) );
		$this->assertSame( array( 'payment_due' ), SiteOverview::warnings( $this->row( array( 'license_status' => 'past_due' ) ), null, self::NOW ) );
		$this->assertSame( array( 'update_available' ), SiteOverview::warnings( $this->row(), '1.3.0', self::NOW ) );
		$this->assertSame( array( 'not_seen' ), SiteOverview::warnings( $this->row( array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 15 * 86400 ) ) ), null, self::NOW ) );
		$this->assertSame( array( 'not_seen' ), SiteOverview::warnings( $this->row( array( 'last_seen_at' => null ) ), null, self::NOW ), 'Never seen: the activation date counts.' );
		$this->assertSame(
			array(),
			SiteOverview::warnings(
				$this->row(
					array(
						'last_seen_at' => null,
						'activated_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 86400 ),
					)
				),
				null,
				self::NOW
			),
			'Activated yesterday, not seen yet: no warning.'
		);
	}

	public function test_expiry_warning_only_without_renewal_and_within_the_window(): void {
		$soon = gmdate( 'Y-m-d H:i:s', self::NOW + 10 * 86400 );
		$late = gmdate( 'Y-m-d H:i:s', self::NOW + 40 * 86400 );

		$this->assertSame(
			array( 'expires_soon' ),
			SiteOverview::warnings(
				$this->row(
					array(
						'paid_until' => $soon,
						'renewal_enabled' => 0,
					)
				),
				null,
				self::NOW
			)
		);
		$this->assertSame(
			array( 'expires_soon' ),
			SiteOverview::warnings(
				$this->row(
					array(
						'paid_until' => $soon,
						'cancel_at_period_end' => 1,
					)
				),
				null,
				self::NOW
			)
		);
		$this->assertSame( array(), SiteOverview::warnings( $this->row( array( 'paid_until' => $soon ) ), null, self::NOW ), 'Renews automatically.' );
		$this->assertSame(
			array(),
			SiteOverview::warnings(
				$this->row(
					array(
						'paid_until' => $late,
						'renewal_enabled' => 0,
					)
				),
				null,
				self::NOW
			),
			'Outside the window.'
		);
		$this->assertSame(
			array(),
			SiteOverview::warnings(
				$this->row(
					array(
						'paid_until' => null,
						'renewal_enabled' => 0,
					)
				),
				null,
				self::NOW
			),
			'No end date.'
		);
	}

	public function test_several_warnings_keep_the_documented_order(): void {
		$row = $this->row(
			array(
				'license_status' => 'expired',
				'last_seen_at'   => gmdate( 'Y-m-d H:i:s', self::NOW - 30 * 86400 ),
			)
		);

		$this->assertSame( array( 'license_inactive', 'update_available', 'not_seen' ), SiteOverview::warnings( $row, '2.0.0', self::NOW ) );
		foreach ( SiteOverview::warnings( $row, '2.0.0', self::NOW ) as $code ) {
			$this->assertContains( $code, SiteOverview::WARNINGS );
		}
	}

	public function test_rows_are_grouped_by_site_in_order(): void {
		$rows = array(
			$this->row(
				array(
					'activation_id' => 1,
					'site_url' => 'https://a.example',
					'product_key' => 'pass-captain-pro',
				)
			),
			$this->row(
				array(
					'activation_id' => 2,
					'site_url' => 'https://a.example',
					'product_key' => 'voucher-captain-pro',
				)
			),
			$this->row(
				array(
					'activation_id' => 3,
					'site_url' => 'https://b.example',
				)
			),
			array( 'activation_id' => 9 ),
			'broken',
		);

		$sites = SiteOverview::group_by_site( $rows );

		$this->assertSame( array( 'https://a.example', 'https://b.example' ), array_keys( $sites ) );
		$this->assertSame( array( 1, 2 ), array_column( $sites['https://a.example']['rows'], 'activation_id' ) );
		$this->assertSame( array(), SiteOverview::group_by_site( null ) );
	}

	public function test_filter_and_summary(): void {
		$sites    = SiteOverview::group_by_site(
			array(
				$this->row(
					array(
						'activation_id' => 1,
						'site_url' => 'https://kunde-a.example',
					)
				),
				$this->row(
					array(
						'activation_id' => 2,
						'site_url' => 'https://kunde-a.example',
						'environment' => 'staging',
					)
				),
				$this->row(
					array(
						'activation_id' => 3,
						'site_url' => 'https://kunde-b.example',
						'environment' => 'staging',
					)
				),
				$this->row(
					array(
						'activation_id' => 4,
						'site_url' => 'https://kunde-c.example',
					)
				),
			)
		);
		$warnings = array(
			1 => array(),
			2 => array( 'update_available' ),
			3 => array( 'not_seen' ),
			4 => array(),
		);

		$this->assertSame( array( 'https://kunde-a.example', 'https://kunde-b.example', 'https://kunde-c.example' ), array_keys( SiteOverview::filter_sites( $sites, $warnings, 'all', '' ) ) );
		$this->assertSame( array( 'https://kunde-a.example', 'https://kunde-b.example' ), array_keys( SiteOverview::filter_sites( $sites, $warnings, 'attention', '' ) ) );
		$this->assertSame( array( 'https://kunde-a.example' ), array_keys( SiteOverview::filter_sites( $sites, $warnings, 'updates', '' ) ) );
		$this->assertSame( array( 'https://kunde-b.example' ), array_keys( SiteOverview::filter_sites( $sites, $warnings, 'all', 'KUNDE-B' ) ), 'Search ignores case.' );
		$this->assertSame(
			array(
				'sites'     => 3,
				'live'      => 2,
				'attention' => 2,
				'updates'   => 1,
			),
			SiteOverview::summary( $sites, $warnings )
		);
	}

	public function test_csv_cells_cannot_run_as_formulas(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", SiteOverview::csv_cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'+1", SiteOverview::csv_cell( '+1' ) );
		$this->assertSame( "'-1", SiteOverview::csv_cell( '-1' ) );
		$this->assertSame( "'@SUM(A1)", SiteOverview::csv_cell( '@SUM(A1)' ) );
		$this->assertSame( 'https://shop.example', SiteOverview::csv_cell( 'https://shop.example' ) );
		$this->assertSame( 'a b', SiteOverview::csv_cell( "a\nb" ) );

		$this->assertSame(
			"\xEF\xBB\xBF\"Site\",\"Note\"\r\n\"https://a.example\",\"say \"\"hi\"\"\"\r\n\"'=1+1\",\"\"\r\n",
			SiteOverview::csv_document(
				array(
					array( 'Site', 'Note' ),
					array( 'https://a.example', 'say "hi"' ),
					array( '=1+1', '' ),
				)
			)
		);
	}

	public function test_warning_texts_exist(): void {
		foreach ( array( 'license_inactive', 'payment_due', 'update_available', 'not_seen' ) as $code ) {
			$this->assertNotSame( $code, SiteOverview::warning_label( $code, $this->row( array( 'license_status' => 'expired' ) ), '2.0.0' ), $code );
		}
		$this->assertStringContainsString( '2.0.0', SiteOverview::warning_label( 'update_available', $this->row(), '2.0.0' ) );
	}
}
