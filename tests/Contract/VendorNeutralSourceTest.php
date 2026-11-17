<?php
use PHPUnit\Framework\TestCase;

/**
 * PROD-04: the server is sold to other vendors, so its code, templates and assets must not name
 * the owner's own add-ons ("Voucher Captain", `voucher-captain-pro`, `pass.waiting_room`, ...).
 * The plugin's own name ("WP License Captain", `wp-license-captain`) is allowed.
 *
 * What is scanned: every text file under src/, templates/ and assets/.
 *
 * What is deliberately not scanned:
 * - src/Client/: the client library that vendors copy into their add-ons (the export script renames
 *   its namespace); its doc comments use the owner's add-ons as examples and it is versioned and
 *   translated as its own package (docs/CLIENT-INTEGRATION.md, CHANGELOG-CLIENT.md).
 * - catalogs/*.json: data files of the owner's products, imported on the owner's installation;
 *   they are outside the scanned directories anyway. They are the source of the names searched for.
 *
 * Code that has to know a real product key for a documented reason gets an entry in ALLOWED
 * (file => needle => reason), never a skip of a whole file or directory. Today no such code exists.
 */
final class VendorNeutralSourceTest extends TestCase {

	/** Scanned directories, relative to the plugin root. */
	private const SCANNED = array( 'src', 'templates', 'assets' );

	/** Directories below the scanned ones that are skipped (see the class comment). */
	private const SKIPPED_DIRECTORIES = array( 'src/Client/' );

	/** Text file types that are read. */
	private const EXTENSIONS = array( 'php', 'js', 'css', 'html', 'json', 'svg', 'txt', 'md' );

	/**
	 * Explicit exceptions: relative file => array( needle (lower case) => reason ). Empty on purpose.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const ALLOWED = array();

	/**
	 * Patterns that name a Captain product, its customers or its typical examples, independent of the catalog files.
	 *
	 * @return array<string,string> Regex => what it stands for.
	 */
	private static function patterns(): array {
		return array(
			'/\b(?!license\b)[a-z]+[ _-]captain\b/i'                     => 'a Captain product other than License Captain',
			'/\bcaptain[ _-]?pro\b/i'                                    => 'the captain-pro suite',
			'/boating/i'                                                 => 'BoatingSchool / LearnBoating',
			'/voucher/i'                                                 => 'Voucher Captain',
			'/\b(voucher|pass|affiliate|learnboating)[._][a-z][a-z0-9_]*\b/' => 'an entitlement key of a Captain product',
			'/\b[VPA]CAP_[A-Z0-9_]+/'                                    => 'a constant of a Captain add-on',
		);
	}

	/**
	 * Literal names taken from the owner's catalog files: product keys, product names and entitlement keys.
	 *
	 * @return string[] Lower-case needles.
	 */
	private static function catalog_needles(): array {
		$needles = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/catalogs/*.json' ) ?: array() as $file ) {
			$catalog = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $catalog ) ) {
				continue;
			}
			$needles[] = (string) $catalog['product_key'];
			foreach ( (array) ( $catalog['name'] ?? array() ) as $name ) {
				$needles[] = (string) $name;
			}
			foreach ( (array) ( $catalog['entitlements'] ?? array() ) as $entitlement ) {
				$needles[] = (string) ( $entitlement['key'] ?? '' );
			}
		}
		$needles = array_map( 'strtolower', array_filter( $needles ) );

		// The plugin's own name is never a finding.
		return array_values( array_diff( array_unique( $needles ), array( 'wp-license-captain', 'license captain', 'wp license captain' ) ) );
	}

	/**
	 * Findings of one text: the matched texts.
	 *
	 * @param string   $text    File content.
	 * @param string[] $needles Literal needles.
	 * @return string[]
	 */
	private static function findings( string $text, array $needles ): array {
		$found = array();
		$lower = strtolower( $text );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				$found[] = $needle;
			}
		}
		foreach ( array_keys( self::patterns() ) as $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[0] as $match ) {
					$found[] = strtolower( $match );
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/** The catalog files really provide the names searched for (otherwise the scan would prove nothing). */
	public function test_the_catalog_files_supply_product_keys_names_and_entitlement_keys(): void {
		$needles = self::catalog_needles();

		$this->assertContains( 'voucher-captain-pro', $needles );
		$this->assertContains( 'affiliate captain pro', $needles );
		$this->assertContains( 'pass.waiting_room', $needles );
		$this->assertNotContains( 'wp-license-captain', $needles );
	}

	/** The scanner recognizes the kinds of examples PROD-04 removed and leaves neutral ones alone. */
	public function test_the_scanner_finds_captain_names_and_accepts_neutral_examples(): void {
		$needles = self::catalog_needles();

		foreach (
			array(
				"'placeholder' => 'voucher-captain-pro'",
				'(for example Pass Captain)',
				'{"affiliate.program_limit":3}',
				'learnboating-form-connector',
				'BoatingSchool',
				'hastla007/captain-pro',
				'VCAP_LICENSE_PUBLIC_KEY',
				'Review Captain',
			) as $bad
		) {
			$this->assertNotSame( array(), self::findings( $bad, $needles ), $bad );
		}
		foreach (
			array(
				'WP License Captain',
				'wp-license-captain',
				"'placeholder' => 'acme-pro'",
				'{"acme.project_limit":3}',
				'acme_reports.scheduled_exports',
				'password.reset form.submit() review.status',
				'Close without booking',
			) as $good
		) {
			$this->assertSame( array(), self::findings( $good, $needles ), $good );
		}
	}

	/** No file under src/, templates/ or assets/ names a Captain product (PROD-04). */
	public function test_code_templates_and_assets_name_no_captain_product(): void {
		$root     = dirname( __DIR__, 2 );
		$needles  = self::catalog_needles();
		$findings = array();
		$scanned  = 0;

		foreach ( self::SCANNED as $directory ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || ! in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true ) ) {
					continue;
				}
				$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 );
				foreach ( self::SKIPPED_DIRECTORIES as $skipped ) {
					if ( 0 === strpos( $relative, $skipped ) ) {
						continue 2;
					}
				}
				++$scanned;
				$allowed = self::ALLOWED[ $relative ] ?? array();
				foreach ( self::findings( (string) file_get_contents( $file->getPathname() ), $needles ) as $match ) {
					if ( ! isset( $allowed[ $match ] ) ) {
						$findings[] = $relative . ': ' . $match;
					}
				}
			}
		}

		$this->assertGreaterThan( 100, $scanned, 'the scan reads the plugin files' );
		$this->assertSame( array(), $findings, 'Use a neutral example (acme-pro, acme.reports, "Example Pro") or add a commented entry to ALLOWED.' );
	}
}
