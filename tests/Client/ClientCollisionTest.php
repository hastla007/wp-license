<?php
/**
 * B-73: several add-ons can carry the client library at the same time.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class ClientCollisionTest extends TestCase {

	/** @var string[] */
	private $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			array_map( 'unlink', (array) glob( $dir . '/*' ) );
			rmdir( $dir );
		}
	}

	private function temp_dir(): string {
		$dir = sys_get_temp_dir() . '/wplc-client-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir );
		$this->dirs[] = $dir;

		return $dir;
	}

	/**
	 * Run PHP code in a separate process and return its output.
	 *
	 * @param string $code Code after the opening tag.
	 * @return array{0:int,1:string}
	 */
	private function run_php( string $code ): array {
		$file = $this->temp_dir() . '/run.php';
		file_put_contents( $file, "<?php\ndefine( 'ABSPATH', '/' );\n" . $code );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

		return array( $status, implode( "\n", $output ) );
	}

	public function test_b73_two_unchanged_copies_do_not_collide_and_the_first_loaded_wins(): void {
		$source = dirname( __DIR__, 2 ) . '/src/Client';
		$copy   = $this->temp_dir();
		foreach ( (array) glob( $source . '/*.php' ) as $file ) {
			// The second add-on ships an older copy of the same classes.
			file_put_contents( $copy . '/' . basename( $file ), str_replace( "const VERSION = '2.0.0';", "const VERSION = '1.9.0';", (string) file_get_contents( $file ) ) );
		}

		list( $status, $output ) = $this->run_php(
			'require ' . var_export( $source . '/load.php', true ) . ";\n" .
			'require ' . var_export( $copy . '/load.php', true ) . ";\n" .
			'echo \WPLC\Client\LicenseClient::VERSION, " ", \WPLC\Client\LicenseManager::VERSION, " ", \WPLC\Client\Updater::VERSION, " ", \WPLC\Client\OptionStore::VERSION, " ", \WPLC\Client\Messages::VERSION, " ", \WPLC\Client\LicenseStatus::VERSION;'
		);
		$this->assertSame( 0, $status, $output );
		$this->assertSame( '2.0.0 2.0.0 2.0.0 2.0.0 2.0.0 2.0.0', $output );

		// Loaded in the other order the older copy wins: this is the documented limit of the guard.
		list( $status, $output ) = $this->run_php(
			'require ' . var_export( $copy . '/load.php', true ) . ";\n" .
			'require ' . var_export( $source . '/load.php', true ) . ";\n" .
			'echo \WPLC\Client\LicenseClient::VERSION;'
		);
		$this->assertSame( 0, $status, $output );
		$this->assertSame( '1.9.0', $output );
	}

	public function test_b73_export_script_gives_every_addon_its_own_namespace_and_text_domain(): void {
		$script = dirname( __DIR__, 2 ) . '/bin/export-client.php';
		$source = dirname( __DIR__, 2 ) . '/src/Client';
		$first  = $this->temp_dir();
		$second = $this->temp_dir();

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --target=' . escapeshellarg( $first ) . ' --namespace=' . escapeshellarg( 'VoucherCaptainPro\\Licensing' ) . ' --text-domain=voucher-captain-pro 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --target=' . escapeshellarg( $second ) . ' --namespace=PassCaptainPro\\\\Licensing --text-domain=pass-captain-pro 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$this->assertSame(
			array_map( 'basename', (array) glob( $source . '/*.php' ) ),
			array_map( 'basename', (array) glob( $first . '/*.php' ) )
		);
		foreach ( (array) glob( $first . '/*.php' ) as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertStringNotContainsString( 'WPLC\\Client', $code, basename( $file ) );
			$this->assertStringNotContainsString( "'wp-license'", $code, basename( $file ) );
		}
		$this->assertStringContainsString( "__( 'Please enter your license key.', 'voucher-captain-pro' )", (string) file_get_contents( $first . '/Messages.php' ) );
		$this->assertStringContainsString( 'namespace VoucherCaptainPro\\Licensing;', (string) file_get_contents( $first . '/LicenseManager.php' ) );

		// Both exports and the original are loaded into one process and stay separate classes.
		list( $status, $output ) = $this->run_php(
			'function __( $t, $d = "" ) { return $d . ":" . $t; }' . "\n" .
			'require ' . var_export( $first . '/load.php', true ) . ";\n" .
			'require ' . var_export( $second . '/load.php', true ) . ";\n" .
			'require ' . var_export( $source . '/load.php', true ) . ";\n" .
			'echo implode( " ", array( class_exists( "VoucherCaptainPro\\\\Licensing\\\\LicenseManager", false ), class_exists( "PassCaptainPro\\\\Licensing\\\\Updater", false ), class_exists( "WPLC\\\\Client\\\\LicenseClient", false ) ) ), "\n";' . "\n" .
			'echo ( new \VoucherCaptainPro\Licensing\Messages( "voucher-captain-pro" ) )->get( "ui.activate" ), "\n";' . "\n" .
			'echo ( new \PassCaptainPro\Licensing\Messages( "pass-captain-pro" ) )->get( "ui.activate" );'
		);
		$this->assertSame( 0, $status, $output );
		$this->assertSame( "1 1 1\nvoucher-captain-pro:Activate\npass-captain-pro:Activate", $output );

		// Wrong arguments end with an error instead of a half-written copy.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --target=' . escapeshellarg( $this->temp_dir() ) . ' --namespace=' . escapeshellarg( 'WPLC\\Client' ) . ' --text-domain=x 2>&1', $output, $status );
		$this->assertSame( 2, $status );
	}

	public function test_library_has_no_dependency_on_the_server_or_on_woocommerce(): void {
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/src/Client/*.php' ) as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertSame( 0, preg_match( '/\buse\s+WPLC\\\\(?!Client\\\\)/', $code ), basename( $file ) . ' imports a server class.' );
			$this->assertSame( 0, preg_match( '/\\\\WPLC\\\\(?!Client\\\\)|\bwc_[a-z_]+\s*\(|WC_[A-Za-z_]+|WooCommerce/', $code ), basename( $file ) . ' refers to the server or to WooCommerce.' );
			// PHP 7.4: none of the newer syntax.
			$this->assertSame( 0, preg_match( '/\bmatch\s*\(|\?->|\breadonly\b|\benum\s+[A-Z]|str_contains\s*\(|str_starts_with\s*\(|str_ends_with\s*\(/', $code ), basename( $file ) . ' uses syntax or functions newer than PHP 7.4.' );
		}
	}
}
