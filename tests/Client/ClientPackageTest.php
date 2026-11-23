<?php
/**
 * PROD-08: the library package for vendors (bin/package-client.php) and its contract test
 * (bin/client-contract-test.php), offline: argument parsing, response parsing and the flow
 * against the fake server of the client test kit. The contract test never runs against a
 * network here.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Client\LicenseClient;

require_once __DIR__ . '/client-test-kit.php';
require_once dirname( __DIR__, 2 ) . '/bin/client-contract-test.php';

final class ClientPackageTest extends TestCase {

	private const KEY = 'WPLC-AAAA-BBBB-CCCC-DDDD';

	/** @var string[] Directories to remove after the test. */
	private $temp = array();

	protected function tearDown(): void {
		foreach ( $this->temp as $dir ) {
			exec( 'rm -rf ' . escapeshellarg( $dir ) );
		}
	}

	private function temp_dir(): string {
		$dir = sys_get_temp_dir() . '/wplc-package-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0755, true );
		$this->temp[] = $dir;

		return $dir;
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/** Valid arguments for parse_args(). */
	private function argv( array $extra = array() ): array {
		$kit = new WPLC_Client_Test_Kit();

		return array_merge(
			array( '--server=https://license.example', '--product=test-addon', '--key=' . self::KEY, '--public-key=' . $kit->server->public_key ),
			$extra
		);
	}

	public function test_the_package_holds_the_library_the_scripts_and_the_documents(): void {
		$out = $this->temp_dir();
		exec( 'php ' . escapeshellarg( self::root() . '/bin/package-client.php' ) . ' --out=' . escapeshellarg( $out ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$version = LicenseClient::VERSION;
		$zip     = $out . '/license-client-' . $version . '.zip';
		$this->assertFileExists( $zip );
		$this->assertSame( hash_file( 'sha256', $zip ) . '  license-client-' . $version . ".zip\n", file_get_contents( $zip . '.sha256' ) );

		$archive = new ZipArchive();
		$this->assertTrue( $archive->open( $zip ) );
		$entries = array();
		for ( $i = 0; $i < $archive->numFiles; $i++ ) {
			$entries[] = (string) $archive->getNameIndex( $i );
		}
		$prefix   = 'license-client-' . $version . '/';
		$expected = array( 'CHANGELOG-CLIENT.md', 'README.md', 'SOURCE.json', 'bin/client-contract-test.php', 'bin/export-client.php' );
		foreach ( glob( self::root() . '/src/Client/*.php' ) as $file ) {
			$expected[] = 'src/Client/' . basename( $file );
		}
		sort( $expected );
		$this->assertSame(
			array_map(
				static function ( string $path ) use ( $prefix ): string {
					return $prefix . $path;
				},
				$expected
			),
			$entries,
			'One top-level folder, nothing else (no tests, no server code).'
		);
		$this->assertSame( file_get_contents( self::root() . '/src/Client/LicenseManager.php' ), $archive->getFromName( $prefix . 'src/Client/LicenseManager.php' ), 'The library is unchanged.' );
		$readme = (string) $archive->getFromName( $prefix . 'README.md' );
		$this->assertStringContainsString( '# License client library ' . $version, $readme );
		$this->assertStringContainsString( 'works with **every server that answers API v1**', $readme );
		$this->assertStringContainsString( '## ' . $version, (string) $archive->getFromName( $prefix . 'CHANGELOG-CLIENT.md' ) );

		// The export script works from the unpacked package (its default source is ../src/Client).
		$unpacked = $this->temp_dir();
		$this->assertTrue( $archive->extractTo( $unpacked ) );
		$archive->close();
		$target = $unpacked . '/addon/licensing';
		exec( 'php ' . escapeshellarg( $unpacked . '/' . $prefix . 'bin/export-client.php' ) . ' --target=' . escapeshellarg( $target ) . " --namespace='Acme\\Licensing' --text-domain=acme-addon 2>&1", $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertStringContainsString( 'namespace Acme\\Licensing;', (string) file_get_contents( $target . '/LicenseManager.php' ) );
		$this->assertStringContainsString( "'acme-addon'", (string) file_get_contents( $target . '/Messages.php' ) );
	}

	public function test_the_package_needs_a_changelog_section_and_one_version(): void {
		$root = $this->temp_dir();
		mkdir( $root . '/bin' );
		mkdir( $root . '/src/Client', 0755, true );
		mkdir( $root . '/packaging/client', 0755, true );
		foreach ( array( 'package-client.php', 'export-client.php', 'client-contract-test.php' ) as $script ) {
			copy( self::root() . '/bin/' . $script, $root . '/bin/' . $script );
		}
		foreach ( glob( self::root() . '/src/Client/*.php' ) as $file ) {
			copy( $file, $root . '/src/Client/' . basename( $file ) );
		}
		copy( self::root() . '/packaging/client/README.md', $root . '/packaging/client/README.md' );
		file_put_contents( $root . '/packaging/client/CHANGELOG-CLIENT.md', "# Changelog\n\n## 1.0.0\n\n- Old.\n" );

		$run = static function () use ( $root ): array {
			exec( 'php ' . escapeshellarg( $root . '/bin/package-client.php' ) . ' --out=' . escapeshellarg( $root . '/dist' ) . ' 2>&1', $output, $status );
			return array( $status, implode( "\n", $output ) );
		};
		list( $status, $output ) = $run();
		$this->assertSame( 1, $status );
		$this->assertStringContainsString( 'has no section "## ' . LicenseClient::VERSION . '"', $output );

		file_put_contents( $root . '/packaging/client/CHANGELOG-CLIENT.md', "# Changelog\n\n## " . LicenseClient::VERSION . "\n\n- New.\n" );
		$file = $root . '/src/Client/Updater.php';
		file_put_contents( $file, str_replace( "const VERSION = '" . LicenseClient::VERSION . "';", "const VERSION = '9.9.9';", (string) file_get_contents( $file ) ) );
		list( $status, $output ) = $run();
		$this->assertSame( 1, $status );
		$this->assertStringContainsString( 'do not share one VERSION', $output );
		$this->assertDirectoryDoesNotExist( $root . '/dist' );
	}

	/**
	 * Checksum of the library content, as bin/export-client.php and bin/package-client.php compute it.
	 *
	 * @param string $dir Directory with the unchanged library files.
	 */
	private static function content_sha256( string $dir ): string {
		$lines = '';
		$files = (array) glob( $dir . '/*.php' );
		sort( $files, SORT_STRING );
		foreach ( $files as $file ) {
			$lines .= basename( $file ) . "\0" . hash_file( 'sha256', $file ) . "\n";
		}

		return hash( 'sha256', $lines );
	}

	public function test_pk06_content_and_version_number_belong_together(): void {
		$checksums = json_decode( (string) file_get_contents( self::root() . '/packaging/client/checksums.json' ), true );
		$this->assertIsArray( $checksums );
		$this->assertArrayHasKey(
			LicenseClient::VERSION,
			$checksums,
			'packaging/client/checksums.json has no line for the VERSION of src/Client. Add one with the new version.'
		);
		$this->assertSame(
			$checksums[ LicenseClient::VERSION ],
			self::content_sha256( self::root() . '/src/Client' ),
			'src/Client changed but VERSION is still ' . LicenseClient::VERSION . '. A published version never gets a second content: raise VERSION in every class, add a section to packaging/client/CHANGELOG-CLIENT.md and a NEW line to packaging/client/checksums.json (keep the old lines).'
		);
		foreach ( $checksums as $version => $checksum ) {
			$this->assertMatchesRegularExpression( '/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $version );
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $checksum );
		}
		$this->assertSame( count( $checksums ), count( array_unique( $checksums ) ), 'Two versions never share one content.' );
	}

	public function test_pk06_an_export_names_its_version_source_commit_and_checksums(): void {
		$commit = str_repeat( 'ab12', 10 );
		$target = $this->temp_dir();
		exec( 'php ' . escapeshellarg( self::root() . '/bin/export-client.php' ) . ' --target=' . escapeshellarg( $target ) . " --namespace='Acme\\Licensing' --text-domain=acme-addon --source-commit=" . $commit . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$record = json_decode( (string) file_get_contents( $target . '/wplc-client-source.json' ), true );
		$this->assertSame( array( 'library', 'version', 'source_commit', 'source_modified', 'content_sha256', 'namespace', 'text_domain', 'files' ), array_keys( $record ) );
		$this->assertSame( LicenseClient::VERSION, $record['version'] );
		$this->assertSame( $commit, $record['source_commit'] );
		$this->assertFalse( $record['source_modified'] );
		$this->assertSame( self::content_sha256( self::root() . '/src/Client' ), $record['content_sha256'] );
		$this->assertSame( array( 'Acme\\Licensing', 'acme-addon' ), array( $record['namespace'], $record['text_domain'] ) );
		$this->assertCount( 7, $record['files'] );
		foreach ( $record['files'] as $name => $hash ) {
			$this->assertSame( hash_file( 'sha256', $target . '/' . $name ), $hash, $name . ': the receiver can check that the copy is unchanged.' );
		}

		// Without the option the commit comes from the Git checkout of the license server.
		exec( 'git -C ' . escapeshellarg( self::root() ) . ' rev-parse HEAD 2>/dev/null', $head, $git_status );
		$other = $this->temp_dir();
		exec( 'php ' . escapeshellarg( self::root() . '/bin/export-client.php' ) . ' --target=' . escapeshellarg( $other ) . " --namespace='Acme\\Licensing' --text-domain=acme-addon 2>&1", $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$record = json_decode( (string) file_get_contents( $other . '/wplc-client-source.json' ), true );
		if ( 0 === $git_status && 1 === preg_match( '/^[0-9a-f]{40,64}$/', (string) ( $head[0] ?? '' ) ) ) {
			$this->assertSame( $head[0], $record['source_commit'] );
		} else {
			$this->assertSame( 'unknown', $record['source_commit'], 'No Git here: the export says so instead of guessing.' );
		}

		// A copy of src/Client inside some other repository is not taken for a checkout of the server.
		$foreign = $this->temp_dir();
		mkdir( $foreign . '/lib/Client', 0755, true );
		foreach ( glob( self::root() . '/src/Client/*.php' ) as $file ) {
			copy( $file, $foreign . '/lib/Client/' . basename( $file ) );
		}
		exec( 'php ' . escapeshellarg( self::root() . '/bin/export-client.php' ) . ' --source=' . escapeshellarg( $foreign . '/lib/Client' ) . ' --target=' . escapeshellarg( $foreign . '/out' ) . " --namespace='Acme\\Licensing' --text-domain=acme-addon 2>&1", $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( 'unknown', json_decode( (string) file_get_contents( $foreign . '/out/wplc-client-source.json' ), true )['source_commit'] );

		exec( 'php ' . escapeshellarg( self::root() . '/bin/export-client.php' ) . ' --target=' . escapeshellarg( $this->temp_dir() ) . " --namespace='Acme\\Licensing' --text-domain=acme-addon --source-commit=main 2>&1", $output, $status );
		$this->assertSame( 2, $status, 'A branch name is not a commit.' );
	}

	public function test_pk06_the_package_carries_its_source_into_every_export(): void {
		$commit = str_repeat( 'cd34', 10 );
		$out    = $this->temp_dir();
		exec( 'php ' . escapeshellarg( self::root() . '/bin/package-client.php' ) . ' --out=' . escapeshellarg( $out ) . ' --source-commit=' . $commit . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$archive = new ZipArchive();
		$this->assertTrue( $archive->open( $out . '/license-client-' . LicenseClient::VERSION . '.zip' ) );
		$unpacked = $this->temp_dir();
		$this->assertTrue( $archive->extractTo( $unpacked ) );
		$archive->close();
		$package = $unpacked . '/license-client-' . LicenseClient::VERSION;
		$source  = json_decode( (string) file_get_contents( $package . '/SOURCE.json' ), true );
		$this->assertSame( array( 'library', 'version', 'source_commit', 'source_modified', 'content_sha256', 'files' ), array_keys( $source ) );
		$this->assertSame( array( LicenseClient::VERSION, $commit, false ), array( $source['version'], $source['source_commit'], $source['source_modified'] ) );
		$this->assertSame( self::content_sha256( self::root() . '/src/Client' ), $source['content_sha256'] );

		$export = static function ( string $target ) use ( $package ): array {
			exec( 'php ' . escapeshellarg( $package . '/bin/export-client.php' ) . ' --target=' . escapeshellarg( $target ) . " --namespace='Acme\\Licensing' --text-domain=acme-addon 2>&1", $output, $status );
			return array( $status, json_decode( (string) @file_get_contents( $target . '/wplc-client-source.json' ), true ) );
		};
		list( $status, $record ) = $export( $unpacked . '/addon-a' );
		$this->assertSame( 0, $status );
		$this->assertSame( array( $commit, false, $source['content_sha256'] ), array( $record['source_commit'], $record['source_modified'], $record['content_sha256'] ) );

		// Somebody edits the unpacked library: the export still names the commit, and says that the content differs.
		file_put_contents( $package . '/src/Client/Updater.php', "\n// local patch\n", FILE_APPEND );
		list( $status, $record ) = $export( $unpacked . '/addon-b' );
		$this->assertSame( 0, $status );
		$this->assertSame( array( $commit, true ), array( $record['source_commit'], $record['source_modified'] ) );
		$this->assertNotSame( $source['content_sha256'], $record['content_sha256'] );
	}

	public function test_pk06_the_package_refuses_a_changed_library_under_the_same_version(): void {
		$root = $this->temp_dir();
		mkdir( $root . '/bin' );
		mkdir( $root . '/src/Client', 0755, true );
		mkdir( $root . '/packaging/client', 0755, true );
		foreach ( array( 'package-client.php', 'export-client.php', 'client-contract-test.php' ) as $script ) {
			copy( self::root() . '/bin/' . $script, $root . '/bin/' . $script );
		}
		foreach ( glob( self::root() . '/src/Client/*.php' ) as $file ) {
			copy( $file, $root . '/src/Client/' . basename( $file ) );
		}
		foreach ( array( 'README.md', 'CHANGELOG-CLIENT.md', 'checksums.json' ) as $file ) {
			copy( self::root() . '/packaging/client/' . $file, $root . '/packaging/client/' . $file );
		}
		$run = static function () use ( $root ): array {
			exec( 'php ' . escapeshellarg( $root . '/bin/package-client.php' ) . ' --out=' . escapeshellarg( $root . '/dist' ) . ' 2>&1', $output, $status );
			return array( $status, implode( "\n", $output ) );
		};

		list( $status, $output ) = $run();
		$this->assertSame( 0, $status, $output );
		$this->assertStringContainsString( 'the source commit is unknown', $output, 'A copy outside the Git checkout does not borrow a commit.' );
		$archive = new ZipArchive();
		$this->assertTrue( $archive->open( $root . '/dist/license-client-' . LicenseClient::VERSION . '.zip' ) );
		$this->assertSame( 'unknown', json_decode( (string) $archive->getFromName( 'license-client-' . LicenseClient::VERSION . '/SOURCE.json' ), true )['source_commit'] );
		$archive->close();
		unlink( $root . '/dist/license-client-' . LicenseClient::VERSION . '.zip' );

		file_put_contents( $root . '/src/Client/Updater.php', "\n// a change without a new version\n", FILE_APPEND );
		list( $status, $output ) = $run();
		$this->assertSame( 1, $status );
		$this->assertStringContainsString( 'checksums.json does not list this content of src/Client under ' . LicenseClient::VERSION, $output );
		$this->assertFileDoesNotExist( $root . '/dist/license-client-' . LicenseClient::VERSION . '.zip' );
	}

	public function test_contract_test_arguments(): void {
		$parsed = WPLC_Client_Contract_Test::parse_args( $this->argv( array( '--public-key=' . base64_encode( str_repeat( "\x02", 32 ) ), '--timeout=30' ) ) );
		$this->assertSame( array(), $parsed['errors'] );
		$this->assertSame( 'https://license.example', $parsed['options']['server'] );
		$this->assertSame( 'test-addon', $parsed['options']['product'] );
		$this->assertCount( 2, $parsed['options']['public_keys'], '--public-key can be repeated (key rotation).' );
		$this->assertSame( 'https://contract-test.local', $parsed['options']['site'], 'Default: a local development address, no live site slot.' );
		$this->assertSame( 'development', $parsed['options']['environment'] );
		$this->assertSame( 30, $parsed['options']['timeout'] );

		$this->assertTrue( WPLC_Client_Contract_Test::parse_args( array( '--help' ) )['options']['help'] );

		$errors = WPLC_Client_Contract_Test::parse_args( array( '--server=http://license.example', '--product=Bad Key', '--public-key=abc', '--environment=live', '--timeout=0', '--colour=red', 'stray' ) )['errors'];
		$this->assertSame(
			array(
				'Unknown argument --colour=red.',
				'Unknown argument stray.',
				'--server must be an https address (--allow-insecure for a local http server).',
				'--product must be the product key of the catalog (a-z, 0-9, - and _).',
				'--key (the license key) is required.',
				'--public-key abc is not a base64 Ed25519 public key (32 bytes).',
				'--environment must be production, staging or development.',
				'--timeout must be 1 to 120 seconds.',
			),
			$errors
		);
		$this->assertSame( array(), WPLC_Client_Contract_Test::parse_args( $this->argv( array( '--allow-insecure', '--server=http://localhost:8080' ) ) )['errors'] );
	}

	public function test_contract_test_reads_raw_http_responses(): void {
		$response = WPLC_Client_Contract_Test::response( "HTTP/1.1 100 Continue\r\n\r\nHTTP/2 409 Conflict\r\nContent-Type: application/json\r\nRetry-After: 30", '{"code":"site_mismatch"}' );
		$this->assertSame( 409, $response['response']['code'] );
		$this->assertSame( 'Conflict', $response['response']['message'] );
		$this->assertSame( array( 'content-type' => 'application/json', 'retry-after' => '30' ), $response['headers'] );
		$this->assertSame( '{"code":"site_mismatch"}', $response['body'] );
	}

	/** The whole flow against the fake server that signs like the real one. */
	private function run_contract( WPLC_Client_Test_Kit $kit, array $argv = array() ): array {
		$parsed = WPLC_Client_Contract_Test::parse_args( $this->argv( $argv ) );
		$parsed['options']['public_keys'] = array( $kit->server->public_key );
		$lines  = array();
		$test   = new WPLC_Client_Contract_Test(
			$parsed['options'],
			array(
				'transport' => $kit->server->transport(),
				'clock'     => static function () use ( $kit ) {
					return $kit->now;
				},
			),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		return array( $test->run(), $lines );
	}

	public function test_contract_test_passes_against_a_server_that_follows_api_v1(): void {
		$kit                   = new WPLC_Client_Test_Kit();
		list( $code, $lines )  = $this->run_contract( $kit );

		$this->assertSame( 0, $code, implode( "\n", $lines ) );
		$this->assertSame( array( 'PASS activate', 'PASS check', 'PASS signature', 'PASS update check', 'PASS deactivate', 'Result' ), array_map( static function ( string $line ): string {
			return explode( ':', $line )[0];
		}, array_slice( $lines, 1 ) ) );
		$this->assertSame( array( '/wplc/v1/activations', '/wplc/v1/entitlements/check', '/wplc/v1/updates/check', '/wplc/v1/activations/41' ), array_column( $kit->server->requests, 'route' ) );
		$this->assertSame( 'development', $kit->server->requests[0]['body']['environment'] );
		$this->assertSame( 'https://contract-test.local', $kit->server->requests[0]['body']['site_url'] );
		$this->assertFalse( $kit->server->requests[2]['body']['download'], 'No download grant is requested.' );
		$this->assertStringNotContainsString( self::KEY, implode( "\n", $lines ), 'The key is never printed.' );
	}

	public function test_contract_test_fails_on_unsigned_answers_and_still_releases_the_activation(): void {
		// Answers without the Ed25519 signature: the activation is refused like in an add-on.
		$kit = new WPLC_Client_Test_Kit();
		$kit->server->queue[] = static function ( array $request, WPLC_Test_Server $server ) {
			$server->secrets[ $request['body']['installation_id'] ] = $request['body']['installation_secret'];
			return WPLC_Test_Server::json( 200, $server->envelope( $request, $server->entitlement(), array( 'strip' => array( 'payload_signature_ed25519', 'signature_ed25519' ) ) ) );
		};
		list( $code, $lines ) = $this->run_contract( $kit );
		$this->assertSame( 1, $code );
		$this->assertStringStartsWith( 'FAIL activate: wplc_', $lines[1] );
		$this->assertCount( 2, $lines, 'Nothing else runs without an activation.' );

		// A failing check: reported, and the activation is still released at the end.
		$kit = new WPLC_Client_Test_Kit();
		$kit->server->queue = array( 'ok', WPLC_Test_Server::error( 500, 'internal_server_error' ) );
		list( $code, $lines ) = $this->run_contract( $kit );
		$this->assertSame( 1, $code );
		$this->assertStringStartsWith( 'FAIL check: ', $lines[2] );
		$this->assertSame( 'DELETE', end( $kit->server->requests )['method'] );
		$this->assertStringStartsWith( 'PASS deactivate', $lines[ count( $lines ) - 2 ] );
		$this->assertSame( 'Result: FAILED', end( $lines ) );

		// An update answer for another product.
		$kit = new WPLC_Client_Test_Kit();
		$kit->server->queue = array( 'ok', 'ok', $kit->server->signed( array( 'update_available' => false, 'product_key' => 'other-addon' ) ) );
		list( $code, $lines ) = $this->run_contract( $kit );
		$this->assertSame( 1, $code );
		$this->assertContains( 'FAIL update check: wplc_product_mismatch', $lines, implode( "\n", $lines ) );
	}
}
