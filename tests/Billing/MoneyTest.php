<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\Money;

final class MoneyTest extends TestCase {
	public function test_formats_amounts_with_two_and_three_decimal_currencies(): void {
		$this->assertSame( '123.45', Money::to_decimal( 12345, 2 ) );
		$this->assertSame( '12.345', Money::to_decimal( 12345, 3 ) );
	}

	public function test_converts_decimal_prices_with_half_up_rounding(): void {
		$this->assertSame( 1234, Money::to_minor_units( '12.344', 2 ) );
		$this->assertSame( 1235, Money::to_minor_units( '12.345', 2 ) );
		$this->assertSame( 1000, Money::to_minor_units( '9.999', 2 ) );
		$this->assertSame( 13, Money::to_minor_units( '12.5', 0 ) );
		$this->assertSame( false, Money::to_minor_units( 'invalid', 2 ) );
	}

	public function test_formats_zero_decimal_and_negative_values(): void {
		$this->assertSame( '123', Money::to_decimal( 123, 0 ) );
		$this->assertSame( '0.01', Money::to_decimal( 1, 2 ) );
		$this->assertSame( '-0.05', Money::to_decimal( -5, 2 ) );
	}

	public function test_precision_is_clamped_to_supported_storage_range(): void {
		$this->assertSame( '0.000001', Money::to_decimal( 1, 10 ) );
		$this->assertSame( '1', Money::to_decimal( 1, -2 ) );
	}
}
