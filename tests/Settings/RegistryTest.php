<?php
/**
 * SET-LIC-01: the one register of all settings.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Settings;
use WPLC\Settings\Definitions;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class RegistryTest extends TestCase {

	private const TYPES = array( 'int', 'bool', 'string', 'text', 'int_list', 'string_list', 'hosts', 'choice' );

	public function test_every_definition_is_complete_and_its_default_is_valid(): void {
		$definitions = Registry::definitions();
		$this->assertNotEmpty( $definitions );

		foreach ( $definitions as $key => $definition ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]{2,40}$/', (string) $key );
			$this->assertContains( $definition['type'], self::TYPES, $key );
			$this->assertContains( $definition['group'], Registry::GROUPS, $key );
			$this->assertContains( $definition['capability'], array( 'manage_settings', 'manage_package_hosts' ), $key );
			$this->assertIsBool( $definition['exportable'], $key );
			$this->assertIsBool( $definition['secret'], $key );
			$this->assertFalse( $definition['secret'] && $definition['exportable'], "$key: a secret is never exportable." );
			if ( in_array( $definition['type'], array( 'int', 'int_list' ), true ) ) {
				$this->assertArrayHasKey( 'min', $definition, $key );
				$this->assertArrayHasKey( 'max', $definition, $key );
				$this->assertLessThanOrEqual( $definition['max'], $definition['min'], $key );
			}
			if ( 'choice' === $definition['type'] ) {
				$this->assertNotEmpty( $definition['choices'], $key );
				$this->assertSame( array_values( $definition['choices'] ), array_keys( Texts::choices( $key ) ), "$key: every choice has a label." );
			}
			if ( '' !== $definition['filter'] ) {
				$this->assertMatchesRegularExpression( '/^wplc_[a-z0-9_]+$/', $definition['filter'], $key );
			}

			$result = Registry::validate( $key, $definition['default'] );
			$this->assertTrue( $result['ok'], "$key: the default must pass its own check ({$result['error']})." );
			$this->assertSame( $definition['default'], $result['value'], "$key: the default is stored in its normal form." );

			$this->assertNotSame( '', Texts::label( $key ), "$key has no label." );
			$this->assertGreaterThan( 20, strlen( Texts::help( $key ) ), "$key has no help text." );
		}

		$this->assertSame( array_keys( $definitions ), array_keys( Texts::all() ), 'Texts and definitions list the same settings in the same order.' );
		$this->assertSame( Registry::GROUPS, array_keys( Texts::groups() ) );
	}

	public function test_every_documented_filter_exists_in_the_code(): void {
		$root   = dirname( __DIR__, 2 );
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/bin/list-hooks.php' ) . ' --names', $output, $code );
		$this->assertSame( 0, $code );
		foreach ( Registry::definitions() as $key => $definition ) {
			if ( '' !== $definition['filter'] ) {
				$this->assertContains( $definition['filter'], $output, "$key names a filter that the code does not apply." );
			}
		}
	}

	/** No class reads the option behind the back of the register. */
	public function test_only_the_register_reads_the_option(): void {
		$root     = dirname( __DIR__, 2 ) . '/src';
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$found    = array();
		foreach ( $iterator as $file ) {
			$path = str_replace( $root . '/', '', (string) $file );
			if ( 'php' !== $file->getExtension() || 'Settings/Registry.php' === $path || 0 === strpos( $path, 'Client/' ) ) {
				continue;
			}
			$code = (string) file_get_contents( (string) $file );
			$own = 'Admin/Settings.php' === $path ? '|self::OPTION' : '';
			if ( preg_match( '/get_option\(\s*(\'wplc_settings\'|Settings::OPTION|Registry::OPTION' . $own . ')/', $code ) ) {
				$found[] = $path;
			}
		}
		$this->assertSame( array(), $found, 'Read settings with Settings\\Registry::get().' );
	}

	public function test_the_settings_that_existed_before_keep_key_default_and_limits(): void {
		$expected = array(
			'package_hosts'             => array(),
			'reminder_days'             => '30,7',
			'renewal_notice_days'       => 7,
			'price_change_notice_days'  => \WPLC\Billing\PriceChange::DEFAULT_NOTICE_DAYS,
			'catalog_auto_import'       => 1,
			'combined_renewals'         => 0,
			'suspended_ends_after_days' => 0,
			'max_quantity_per_line'     => \WPLC\Catalog\QuantityLimit::DEFAULT_MAX,
			'sender_note'               => '',
		);
		foreach ( $expected as $key => $default ) {
			$this->assertSame( $default, Registry::defaults()[ $key ], $key );
			$this->assertSame( $default, Settings::defaults()[ $key ], $key );
		}
		$this->assertSame( \WPLC\Billing\PriceChange::MIN_NOTICE_DAYS, Registry::definition( 'price_change_notice_days' )['min'] );
		$this->assertSame( \WPLC\Billing\PriceChange::MAX_NOTICE_DAYS, Registry::definition( 'price_change_notice_days' )['max'] );
		$this->assertSame( \WPLC\Catalog\QuantityLimit::MAX_SETTING, Registry::definition( 'max_quantity_per_line' )['max'] );
		$this->assertSame( \WPLC\License\EndedLicenses::MAX_DAYS, Registry::definition( 'suspended_ends_after_days' )['max'] );
		$this->assertSame( 'manage_package_hosts', Registry::definition( 'package_hosts' )['capability'] );
	}

	public function test_validation_reports_instead_of_silently_correcting(): void {
		$this->assertSame( 'unknown', Registry::validate( 'no_such_setting', 1 )['code'] );

		// Numbers.
		$this->assertSame( 'not_a_number', Registry::validate( 'renewal_notice_days', 'soon' )['code'] );
		$this->assertSame( 'not_a_number', Registry::validate( 'renewal_notice_days', '-3' )['code'] );
		$this->assertSame( 'not_a_number', Registry::validate( 'renewal_notice_days', '2.5' )['code'] );
		$this->assertSame( 'not_a_number', Registry::validate( 'renewal_notice_days', '' )['code'] );
		$this->assertSame( 'not_a_number', Registry::validate( 'renewal_notice_days', array( 3 ) )['code'] );
		$this->assertSame( 9, Registry::validate( 'renewal_notice_days', ' 9 ' )['value'] );
		// The settings from before the register keep their behaviour: out of range moves to the bound.
		$this->assertSame( 60, Registry::validate( 'renewal_notice_days', '999' )['value'] );
		$this->assertSame( 1, Registry::validate( 'renewal_notice_days', 0 )['value'] );

		// Switches.
		$this->assertSame( 1, Registry::validate( 'combined_renewals', 'yes' )['value'] );
		$this->assertSame( 0, Registry::validate( 'combined_renewals', '0' )['value'] );
		$this->assertSame( 0, Registry::validate( 'combined_renewals', false )['value'] );
		$this->assertSame( 'not_a_switch', Registry::validate( 'combined_renewals', 'maybe' )['code'] );

		// Hosts.
		$this->assertSame( array( 'cdn.example.org' ), Registry::validate( 'package_hosts', "CDN.example.org\n" )['value'] );
		$this->assertSame( 'invalid_hosts', Registry::validate( 'package_hosts', 'https://evil.example/x' )['code'] );

		// Lists.
		$this->assertSame( '30,14,7', Registry::validate( 'reminder_days', array( 7, 30, 14 ) )['value'] );
		$this->assertSame( '30,7', Registry::validate( 'reminder_days', '' )['value'] );
	}

	public function test_typed_readers_never_return_a_value_outside_the_limits(): void {
		$this->assertSame( 7, Registry::int( 'renewal_notice_days' ) );
		$this->assertFalse( Registry::bool( 'combined_renewals' ) );
		$this->assertTrue( Registry::bool( 'catalog_auto_import' ) );
		$this->assertSame( array( 30, 7 ), Registry::list( 'reminder_days' ) );
		$this->assertSame( array(), Registry::list( 'package_hosts' ) );
		$this->assertNull( Registry::get( 'no_such_setting' ) );
		$this->assertSame( 'x', Registry::get( 'sender_note', 'x' ) );
	}

	public function test_display_shortens_text_and_joins_lists(): void {
		$this->assertSame( 'a.example.com, b.example.com', Registry::display( 'package_hosts', array( 'a.example.com', 'b.example.com' ) ) );
		$this->assertSame( '1', Registry::display( 'combined_renewals', 1 ) );
		$this->assertSame( '0', Registry::display( 'combined_renewals', '' ) );
		$this->assertSame( 'a b', Registry::display( 'sender_note', "a\n b" ) );
		$this->assertSame( Registry::PREVIEW_LENGTH + 1, mb_strlen( Registry::display( 'sender_note', str_repeat( 'x', 500 ) ) ) );
	}

	public function test_a_rejected_value_keeps_what_is_stored_and_other_keys(): void {
		$clean = Settings::sanitize(
			array(
				'renewal_notice_days'   => 'soon',
				'max_quantity_per_line' => '25',
			)
		);
		$this->assertSame( 7, $clean['renewal_notice_days'], 'The stored value (here the default) stays.' );
		$this->assertSame( 25, $clean['max_quantity_per_line'] );
		$this->assertSame( array_keys( Definitions::all() ), array_keys( $clean ) );
	}

	public function test_every_problem_has_a_message_that_names_the_setting(): void {
		foreach ( array( 'not_a_number', 'out_of_range', 'too_many', 'too_long', 'not_an_email', 'not_a_url', 'invalid_entries', 'other' ) as $code ) {
			$this->assertStringContainsString( Texts::label( 'renewal_notice_days' ), Texts::problem( 'renewal_notice_days', $code ), $code );
		}
	}

	/** SET-LIC-36: the manual lists every setting; the tables come from the register. */
	public function test_the_manual_chapter_is_current(): void {
		$root = dirname( __DIR__, 2 );
		require_once $root . '/bin/settings-doc.php';
		$chapter = (string) file_get_contents( $root . '/docs/manual/13-settings.md' );
		$this->assertSame( 1, preg_match( '/<!-- settings:start -->\n\n(.*)<!-- settings:end -->/s', $chapter, $match ), 'Markers missing.' );
		$this->assertSame( wplc_settings_doc(), $match[1], 'Run php bin/settings-doc.php --write.' );
		foreach ( array_keys( Registry::definitions() ) as $key ) {
			$this->assertStringContainsString( '| `' . $key . '` |', $chapter );
		}
	}

	/** SET-LIC-02, -03, -16: the renewal policy. */
	public function test_renewal_policy_settings_have_today_s_values_as_defaults(): void {
		$this->assertSame( \WPLC\License\LicenseLifecycle::GRACE_DAYS, Registry::defaults()['renewal_grace_days'] );
		$this->assertSame( implode( ',', \WPLC\Billing\RenewalProcessor::DEFAULT_RETRY_DAYS ), Registry::defaults()['renewal_retry_days'] );
		$this->assertSame( \WPLC\License\LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS, Registry::defaults()['manual_renewal_days_before'] );
		$this->assertSame( \WPLC\License\LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS, Registry::defaults()['manual_renewal_days_after'] );

		$this->assertSame( 'out_of_range', Registry::validate( 'renewal_grace_days', '0' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'renewal_grace_days', '91' )['code'] );
		$this->assertSame( '', Registry::validate( 'renewal_retry_days', '' )['value'], 'Empty = no retry.' );
		$this->assertSame( '2,5,9', Registry::validate( 'renewal_retry_days', '9, 2;5' )['value'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'renewal_retry_days', '1,61' )['code'] );
		$this->assertSame( 'too_many', Registry::validate( 'renewal_retry_days', '1,2,3,4,5,6,7' )['code'] );

		$this->assertSame( array(), Settings::combination_warnings( Registry::defaults() ) );
		$this->assertArrayHasKey( 'wplc_grace_before_last_retry', Settings::combination_warnings( array( 'renewal_grace_days' => 7, 'renewal_retry_days' => '1,3,7' ) ) );
		$this->assertSame( array(), Settings::combination_warnings( array( 'renewal_grace_days' => 7, 'renewal_retry_days' => '' ) ) );
	}

	public function test_manual_renewal_window_filter_wins_and_is_kept_in_range(): void {
		$this->assertSame( array( 'before' => 30, 'after' => 30 ), \WPLC\License\LicenseStateMachine::manual_renewal_window() );
		$GLOBALS['wplc_test_filters']['wplc_manual_renewal_window_days'] = array(
			static function ( $window ) {
				return array( 'before' => 7, 'after' => -5, 'other' => 1 );
			},
		);
		try {
			$this->assertSame( array( 'before' => 7, 'after' => 0 ), \WPLC\License\LicenseStateMachine::manual_renewal_window() );
		} finally {
			unset( $GLOBALS['wplc_test_filters']['wplc_manual_renewal_window_days'] );
		}
	}
}
