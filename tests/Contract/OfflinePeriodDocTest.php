<?php
/**
 * P6-M3: the API documentation describes grace and offline periods as settings, not as fixed 14 days.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class OfflinePeriodDocTest extends TestCase {

	/**
	 * Contents of a documentation file.
	 *
	 * @param string $name File in docs/.
	 * @return string
	 */
	private function doc( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/' . $name );
	}

	public function test_api_v1_describes_settings_plan_rule_filter_and_the_access_end(): void {
		$doc = $this->doc( 'API-V1.md' );
		$this->assertStringNotContainsString( '`server_time` + 14 days', $doc );
		$this->assertStringNotContainsString( '(14 days after `paid_until`)', $doc );
		$this->assertStringContainsString( 'the rule "Offline period (days)" of the license\'s plan', $doc );
		$this->assertStringContainsString( '"Offline period of customer sites" (default 14 days, 2 to 90)', $doc );
		$this->assertStringContainsString( '`wplc_offline_cache_days` receives that value and wins (clamped to 2 to 90 days)', $doc );
		$this->assertStringContainsString( '`cache_expires_at` never reaches beyond `access_until`', $doc );
		$this->assertStringContainsString( '"Grace period after the paid period" (default 14 days, 1 to 90)', $doc );
	}

	public function test_openapi_describes_the_offline_period_as_a_setting(): void {
		$spec  = json_decode( $this->doc( 'openapi-v1.json' ), true );
		$found = array();
		array_walk_recursive(
			$spec,
			static function ( $value, $key ) use ( &$found ): void {
				if ( 'description' === $key && is_string( $value ) && false !== strpos( $value, 'server_time plus' ) ) {
					$found[] = $value;
				}
			}
		);
		$this->assertNotEmpty( $found );
		foreach ( $found as $text ) {
			$this->assertStringNotContainsString( 'server_time plus 14 days', $text );
			$this->assertStringContainsString( '2 to 90 days', $text );
			$this->assertStringContainsString( 'never later than access_until', $text );
		}
	}
}
