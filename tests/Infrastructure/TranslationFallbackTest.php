<?php
/**
 * B-120: which shipped translation a German variant without its own file uses.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Plugin;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class TranslationFallbackTest extends TestCase {

	public function test_german_variants_map_to_the_shipped_files(): void {
		$this->assertSame( 'de_DE_formal', Plugin::fallback_locale( 'de_AT' ) );
		$this->assertSame( 'de_DE_formal', Plugin::fallback_locale( 'de_CH' ) );
		$this->assertSame( 'de_DE', Plugin::fallback_locale( 'de_CH_informal' ) );
		$this->assertSame( '', Plugin::fallback_locale( 'de_DE' ), 'Shipped locales load their own file.' );
		$this->assertSame( '', Plugin::fallback_locale( 'fr_FR' ) );
		$this->assertSame( '', Plugin::fallback_locale( 'en_US' ) );
	}

	public function test_the_fallback_is_filterable_and_validated(): void {
		$GLOBALS['wplc_test_filters']['wplc_translation_fallback_locale'] = array(
			static function ( $fallback, $locale ) {
				return 'fr_BE' === $locale ? 'fr_FR' : '../../evil';
			},
		);
		try {
			$this->assertSame( 'fr_FR', Plugin::fallback_locale( 'fr_BE' ) );
			$this->assertSame( '', Plugin::fallback_locale( 'de_AT' ), 'A path is never accepted.' );
		} finally {
			unset( $GLOBALS['wplc_test_filters']['wplc_translation_fallback_locale'] );
		}
	}
}
