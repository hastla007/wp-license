<?php
/**
 * Must-use site cleanup helper (P-32): table selection and install state.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Infrastructure\Database\SiteCleanupHelper;

final class SiteCleanupHelperTest extends TestCase {

	/** @var string */
	private $dir = '';

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/mu-plugins/wplc-site-cleanup.php';
	}

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wplc-mu-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	public function test_only_tables_with_exactly_the_site_prefix_and_wplc_are_selected(): void {
		$found = array(
			'wp_2_wplc_licenses',
			'wp_2_wplc_old_feature',
			'wp_22_wplc_licenses',
			'wp_2_posts',
			'wp_2_wplcx',
			'wp_wplc_licenses',
			'wp_3_wplc_licenses',
			'other_2_wplc_licenses',
			'wp_2_wplc_licenses',
			'wp_2_wplc_`; DROP',
			42,
		);

		$this->assertSame(
			array( 'wp_2_wplc_licenses', 'wp_2_wplc_old_feature' ),
			wplc_mu_site_cleanup_tables( $found, 'wp_2_', 'wp_' )
		);
	}

	public function test_the_main_site_prefix_and_an_empty_prefix_select_nothing(): void {
		$found = array( 'wp_wplc_licenses', 'wp_2_wplc_licenses' );

		$this->assertSame( array(), wplc_mu_site_cleanup_tables( $found, 'wp_', 'wp_' ) );
		$this->assertSame( array(), wplc_mu_site_cleanup_tables( $found, '', 'wp_' ) );
	}

	public function test_the_helper_has_no_dependency_on_the_plugin(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/mu-plugins/wplc-site-cleanup.php' );

		$this->assertStringNotContainsString( 'WPLC\\', $code, 'The helper must work without the plugin\'s classes.' );
		$this->assertStringNotContainsString( 'WPLC_', $code );
		$this->assertStringContainsString( SiteCleanupHelper::MARKER, $code );
		$this->assertSame( 'wplc-site-cleanup.php', basename( SiteCleanupHelper::source() ) );
		$this->assertFileExists( SiteCleanupHelper::source() );
	}

	public function test_status_distinguishes_missing_installed_outdated_and_foreign_files(): void {
		$target = $this->dir . '/' . SiteCleanupHelper::FILE_NAME;
		$source = SiteCleanupHelper::source();

		$this->assertSame( SiteCleanupHelper::STATUS_MISSING, SiteCleanupHelper::status( $target, $source ) );

		copy( $source, $target );
		$this->assertSame( SiteCleanupHelper::STATUS_INSTALLED, SiteCleanupHelper::status( $target, $source ) );

		file_put_contents( $target, "<?php\n// " . SiteCleanupHelper::MARKER . "\n// version 0\n" );
		$this->assertSame( SiteCleanupHelper::STATUS_OUTDATED, SiteCleanupHelper::status( $target, $source ) );

		file_put_contents( $target, "<?php\n// Some other must-use plugin.\n" );
		$this->assertSame( SiteCleanupHelper::STATUS_FOREIGN, SiteCleanupHelper::status( $target, $source ) );
	}

	public function test_install_copies_the_helper_and_replaces_an_older_copy(): void {
		$target = $this->dir . '/' . SiteCleanupHelper::FILE_NAME;

		$this->assertSame( '', SiteCleanupHelper::install( $target ) );
		$this->assertSame( SiteCleanupHelper::STATUS_INSTALLED, SiteCleanupHelper::status( $target ) );

		file_put_contents( $target, "<?php\n// " . SiteCleanupHelper::MARKER . "\n" );
		$this->assertSame( '', SiteCleanupHelper::install( $target ) );
		$this->assertFileEquals( SiteCleanupHelper::source(), $target );
	}
}
