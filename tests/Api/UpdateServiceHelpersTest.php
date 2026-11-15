<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\UpdateService;

/**
 * Pure helpers of the update API.
 */
final class UpdateServiceHelpersTest extends TestCase {
	// B-140: the reason a client shows when a version exists but the license gives no access.
	public function test_inactive_reason_is_derived_from_the_license_status(): void {
		$this->assertSame( 'license_expired', UpdateService::inactive_reason( array( 'status' => 'expired' ) ) );
		// Still "active" in the database, but the paid period is over (the daily job has not run yet).
		$this->assertSame( 'license_expired', UpdateService::inactive_reason( array( 'status' => 'active' ) ) );
		$this->assertSame( 'license_expired', UpdateService::inactive_reason( array( 'status' => 'past_due' ) ) );
		$this->assertSame( 'license_suspended', UpdateService::inactive_reason( array( 'status' => 'suspended' ) ) );
		$this->assertSame( 'license_revoked', UpdateService::inactive_reason( array( 'status' => 'revoked' ) ) );
		$this->assertSame( 'license_pending', UpdateService::inactive_reason( array( 'status' => 'pending' ) ) );
		$this->assertSame( 'license_inactive', UpdateService::inactive_reason( array() ) );
	}

	// B-141: one section per skipped version, highest first, version and date escaped.
	public function test_changelog_html_lists_every_version(): void {
		$html = UpdateService::changelog_html(
			array(
				array(
					'version' => '1.2.0',
					'date'    => '2026-11-02 10:00:00',
					'notes'   => '<p>Second</p>',
				),
				array(
					'version' => '1.1.0<b>',
					'date'    => '',
					'notes'   => '<p>First</p>',
				),
			)
		);
		$this->assertSame( '<h4>1.2.0 &ndash; 2026-11-02</h4><p>Second</p><h4>1.1.0&lt;b&gt;</h4><p>First</p>', $html );
		$this->assertSame( '', UpdateService::changelog_html( array() ) );
	}
}
