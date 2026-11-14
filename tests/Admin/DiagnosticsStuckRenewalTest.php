<?php
/**
 * Which renewal rows the `overdue_renewals` metric counts as stuck.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Diagnostics;

final class DiagnosticsStuckRenewalTest extends TestCase {

	private const NOW = 1800000000;

	private static function time_at( int $offset ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW + $offset );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public function rows(): array {
		$day = 86400;
		$grace_future = self::time_at( 5 * $day );
		$grace_past = self::time_at( -2 * 3600 );

		return array(
			'scheduled, due in the future' => array( array( 'status' => 'scheduled', 'retry_at' => self::time_at( 600 ), 'grace_ends_at' => $grace_future ), false ),
			'scheduled, due minutes ago (job about to run)' => array( array( 'status' => 'scheduled', 'retry_at' => self::time_at( -600 ), 'grace_ends_at' => $grace_future ), false ),
			'scheduled, due two hours ago (job lost)' => array( array( 'status' => 'scheduled', 'retry_at' => self::time_at( -7200 ), 'grace_ends_at' => $grace_future ), true ),
			'failed, next retry planned' => array( array( 'status' => 'failed', 'retry_at' => self::time_at( 2 * $day ), 'grace_ends_at' => $grace_future ), false ),
			'failed, retry two hours overdue' => array( array( 'status' => 'failed', 'retry_at' => self::time_at( -7200 ), 'grace_ends_at' => $grace_future ), true ),
			'failed, no retry left, waiting for grace end' => array( array( 'status' => 'failed', 'retry_at' => null, 'period_start' => self::time_at( -9 * $day ), 'grace_ends_at' => $grace_future ), false ),
			'failed, no retry left, grace ended unresolved' => array( array( 'status' => 'failed', 'retry_at' => null, 'grace_ends_at' => $grace_past ), true ),
			'failed, grace ended minutes ago' => array( array( 'status' => 'failed', 'retry_at' => null, 'grace_ends_at' => self::time_at( -600 ) ), false ),
			'failed, legacy row without grace end, within grace' => array( array( 'status' => 'failed', 'retry_at' => null, 'grace_ends_at' => null, 'period_start' => self::time_at( -3 * $day ) ), false ),
			'failed, legacy row without grace end, after grace' => array( array( 'status' => 'failed', 'retry_at' => null, 'grace_ends_at' => null, 'period_start' => self::time_at( -15 * $day ) ), true ),
			'reconciling, next hourly check pending' => array( array( 'status' => 'reconciling', 'retry_at' => self::time_at( 1800 ), 'grace_ends_at' => $grace_future ), false ),
			'reconciling, check two hours overdue' => array( array( 'status' => 'reconciling', 'retry_at' => self::time_at( -7200 ), 'grace_ends_at' => $grace_future ), true ),
			'processing, just started' => array( array( 'status' => 'processing', 'updated_at' => self::time_at( -300 ) ), false ),
			'processing, two hours old' => array( array( 'status' => 'processing', 'updated_at' => self::time_at( -7200 ) ), true ),
			'manual action, before grace end' => array( array( 'status' => 'manual_action_required', 'retry_at' => null, 'grace_ends_at' => $grace_future ), false ),
			'manual action, grace ended unresolved' => array( array( 'status' => 'manual_action_required', 'retry_at' => null, 'grace_ends_at' => $grace_past ), true ),
			'review required has its own metric' => array( array( 'status' => 'review_required', 'retry_at' => null, 'grace_ends_at' => $grace_past ), false ),
			'manual renewal waits for the customer' => array( array( 'status' => 'manual', 'retry_at' => null, 'grace_ends_at' => $grace_past ), false ),
			'paid' => array( array( 'status' => 'paid', 'retry_at' => self::time_at( -9 * $day ) ), false ),
			'cancelled' => array( array( 'status' => 'cancelled', 'retry_at' => null, 'grace_ends_at' => $grace_past ), false ),
		);
	}

	/**
	 * @dataProvider rows
	 * @param array<string,mixed> $row Renewal row.
	 */
	public function test_stuck_classification( array $row, bool $expected ): void {
		$this->assertSame( $expected, Diagnostics::is_stuck_renewal( $row, self::NOW ) );
	}
}
