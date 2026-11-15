<?php
/**
 * Order and cursor of the paid-order pass of the gap reconciliation (P-29).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Maintenance\GapReconciler;

final class GapReconcilerCursorTest extends TestCase {

	public function test_first_run_checks_every_order_newest_first(): void {
		$this->assertSame( array( 50, 40, 30, 20, 10 ), GapReconciler::paid_order_queue( array( 50, 40, 30, 20, 10 ), 0, 0 ) );
	}

	public function test_new_orders_come_first_then_the_unfinished_pass_continues_below_the_cursor(): void {
		// The last run saw orders up to 50 and stopped after checking 30.
		$this->assertSame( array( 70, 60, 20, 10 ), GapReconciler::paid_order_queue( array( 70, 60, 50, 40, 30, 20, 10 ), 50, 30 ) );
	}

	public function test_after_a_finished_pass_the_older_orders_are_checked_again_after_the_new_ones(): void {
		// An order paid late (bank transfer) keeps its old ID; a finished pass starts over below the new ones.
		$this->assertSame( array( 70, 60, 50, 40 ), GapReconciler::paid_order_queue( array( 70, 60, 50, 40 ), 50, 0 ) );
	}

	public function test_a_run_that_ran_out_of_time_remembers_where_it_stopped(): void {
		$this->assertSame( array( 'top' => 70, 'below' => 30 ), GapReconciler::next_cursor( array( 70, 60, 50, 40, 30, 20 ), 50, 0, 30, false ) );
	}

	public function test_a_run_that_stopped_among_the_new_orders_continues_below_the_last_one_it_checked(): void {
		// 60 was not reached; it is below 70 and therefore part of the next pass.
		$next = GapReconciler::next_cursor( array( 80, 70, 60, 50, 40 ), 50, 30, 70, false );
		$this->assertSame( array( 'top' => 80, 'below' => 70 ), $next );
		$this->assertSame( array( 60, 50, 40 ), GapReconciler::paid_order_queue( array( 80, 70, 60, 50, 40 ), $next['top'], $next['below'] ) );
	}

	public function test_a_finished_run_clears_the_cursor_and_a_run_without_progress_keeps_it(): void {
		$this->assertSame( array( 'top' => 70, 'below' => 0 ), GapReconciler::next_cursor( array( 70, 60 ), 50, 30, 60, true ) );
		$this->assertSame( array( 'top' => 50, 'below' => 30 ), GapReconciler::next_cursor( array( 70, 60 ), 50, 30, 0, false ) );
		$this->assertSame( array( 'top' => 50, 'below' => 0 ), GapReconciler::next_cursor( array(), 50, 0, 0, true ), 'An empty window keeps the top.' );
	}

	public function test_newest_orders_are_reached_every_day_even_when_the_budget_is_always_short(): void {
		// Ten orders per day, budget for four orders per run: every new order is checked on its first day,
		// and the pass over the older ones still advances.
		$ids     = array();
		$cursor  = array( 'top' => 0, 'below' => 0 );
		$checked = array();
		for ( $day = 1; $day <= 5; ++$day ) {
			for ( $i = 1; $i <= 2; ++$i ) {
				array_unshift( $ids, $day * 100 + $i );
			}
			$queue = GapReconciler::paid_order_queue( $ids, $cursor['top'], $cursor['below'] );
			$run   = array_slice( $queue, 0, 4 );
			foreach ( array( $day * 100 + 1, $day * 100 + 2 ) as $new ) {
				$this->assertContains( $new, $run, 'Day ' . $day . ': new order ' . $new . ' checked on its first day.' );
			}
			$checked = array_merge( $checked, $run );
			$cursor  = GapReconciler::next_cursor( $ids, $cursor['top'], $cursor['below'], (int) end( $run ), count( $run ) === count( $queue ) );
		}
		$this->assertSame( array(), array_diff( $ids, $checked ), 'Every order was checked at least once.' );
	}
}
