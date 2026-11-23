<?php
/**
 * B-120: Austrian and Swiss German use the shipped German translation.
 *
 * @package WPLC
 */

use WPLC\Plugin;

final class TranslationFallbackIntegrationTest extends WP_UnitTestCase {

	/** @var array<int,string>|null Installed languages of the locale switcher before the test. */
	private $available = null;

	public function tear_down(): void {
		restore_previous_locale();
		if ( null !== $this->available ) {
			$property = new ReflectionProperty( $GLOBALS['wp_locale_switcher'], 'available_languages' );
			$property->setValue( $GLOBALS['wp_locale_switcher'], $this->available );
		}
		unload_textdomain( 'wp-license' );
		Plugin::load_textdomain();
		parent::tear_down();
	}

	public function test_de_at_and_de_ch_fall_back_to_the_german_file(): void {
		foreach ( array( 'de_AT', 'de_CH' ) as $locale ) {
			add_filter(
				'locale',
				$filter = static function () use ( $locale ) {
					return $locale;
				}
			);
			unload_textdomain( 'wp-license' );
			Plugin::load_textdomain();

			$this->assertSame( 'Lizenzen', __( 'Licenses', 'wp-license' ), $locale );
			remove_filter( 'locale', $filter );
		}
	}

	public function test_switching_to_austrian_german_reloads_the_translation(): void {
		// The test site has no core language packs; let the switcher accept de_AT as installed.
		$switcher  = $GLOBALS['wp_locale_switcher'];
		$property  = new ReflectionProperty( $switcher, 'available_languages' );
		$this->available = (array) $property->getValue( $switcher );
		$property->setValue( $switcher, array_merge( $this->available, array( 'de_AT' ) ) );

		$this->assertTrue( switch_to_locale( 'de_AT' ) );

		$this->assertSame( 'Lizenzen', __( 'Licenses', 'wp-license' ) );

		restore_previous_locale();
		$this->assertSame( 'Licenses', __( 'Licenses', 'wp-license' ), 'Back in English.' );
	}
}
