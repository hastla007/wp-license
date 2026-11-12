<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\Money;

final class MoneyTest extends TestCase {
	public function test_formats_amounts_with_two_and_three_decimal_currencies(): void {
		$this->assertSame( '123.45', Money::to_decimal( 12345, 2 ) );
		$this->assertSame( '12.345', Money::to_decimal( 12345, 3 ) );
	}

	public function test_formats_zero_decimal_and_negative_values(): void {
		$this->assertSame( '123', Money::to_decimal( 123, 0 ) );
		$this->assertSame( '0.01', Money::to_decimal( 1, 2 ) );
		$this->assertSame( '-0.05', Money::to_decimal( -5, 2 ) );
	}

	public function test_precision_is_clamped_to_supported_storage_range(): void {
		$this->assertSame( '0.000000', Money::to_decimal( 1, 10 ) );
		$this->assertSame( '1', Money::to_decimal( 1, -2 ) );
	}
}
