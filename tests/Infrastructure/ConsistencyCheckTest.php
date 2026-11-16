<?php
/**
 * R-30: the facts bin/check-consistency.php compares are consistent in this repository,
 * and the script notices each kind of drift that happened before.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/check-consistency.php';

final class ConsistencyCheckTest extends TestCase {

	/** @var string[] Temporary directories. */
	private $temp = array();

	protected function tearDown(): void {
		foreach ( $this->temp as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $dir );
		}
		$this->temp = array();
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/** Copy of the files the script reads. */
	private function copy(): string {
		$dir          = sys_get_temp_dir() . '/wplc-consistency-' . bin2hex( random_bytes( 6 ) );
		$this->temp[] = $dir;
		foreach ( array( 'docs', '.github/workflows', 'languages' ) as $sub ) {
			mkdir( $dir . '/' . $sub, 0777, true );
		}
		$files = array_merge(
			array( 'wp-license.php', 'docs/TODO.md', 'docs/QUALITY-GATES.md' ),
			array_map(
				static function ( string $file ): string {
					return substr( $file, strlen( self::root() ) + 1 );
				},
				array_merge( (array) glob( self::root() . '/.github/workflows/*.yml' ), (array) glob( self::root() . '/languages/*' ) )
			)
		);
		foreach ( $files as $file ) {
			copy( self::root() . '/' . $file, $dir . '/' . $file );
		}

		return $dir;
	}

	private static function replace( string $file, string $search, string $replace ): void {
		$text = (string) file_get_contents( $file );
		self::assertStringContainsString( $search, $text, basename( $file ) );
		$position = strpos( $text, $search );
		file_put_contents( $file, substr( $text, 0, $position ) . $replace . substr( $text, $position + strlen( $search ) ) );
	}

	public function test_this_repository_is_consistent(): void {
		$this->assertSame( array(), wplc_consistency_check( self::root() ), 'Run php bin/check-consistency.php and bring the documents up to date.' );
	}

	public function test_the_copy_used_by_the_other_tests_is_consistent_and_the_script_exits_with_zero(): void {
		$copy = $this->copy();
		$this->assertSame( array(), wplc_consistency_check( $copy ) );

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::root() . '/bin/check-consistency.php' ) . ' --root=' . escapeshellarg( $copy ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		self::replace( $copy . '/docs/TODO.md', '- Zusammen: ', '- Zusammen: 9' );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::root() . '/bin/check-consistency.php' ) . ' --root=' . escapeshellarg( $copy ) . ' 2>&1', $failing, $status );
		$this->assertSame( 1, $status );
		$this->assertStringContainsString( 'in total', implode( "\n", $failing ) );
	}

	public function test_a_ticked_task_without_a_new_overview_is_noticed(): void {
		$todo  = (string) file_get_contents( self::root() . '/docs/TODO.md' );
		$tasks = wplc_consistency_tasks( $todo );
		$this->assertGreaterThan( 400, count( $tasks ) );

		// One open [WICHTIG] task gets ticked, the overview stays.
		$this->assertSame( 1, preg_match( '/^- \[ \] (\*\*[A-Z0-9-]+ \[WICHTIG\])/m', $todo, $match ) );
		$problems = implode( "\n", wplc_consistency_check_todo( str_replace( '- [ ] ' . $match[1], '- [x] ' . $match[1], $todo ) ) );
		$this->assertStringContainsString( 'overview says Wichtig', $problems );
		$this->assertStringContainsString( 'listed but not open', $problems );
		$this->assertStringContainsString( 'in total', $problems );

		// A new task that the overview does not know.
		$problems = implode( "\n", wplc_consistency_check_todo( $todo . "\n- [ ] **Z-99 [OPTIMIERUNG]** Neu.\n" ) );
		$this->assertStringContainsString( 'open but not listed: Z-99', $problems );

		// An open task needs a severity; an indented check box is a note, not a task.
		$this->assertStringContainsString( 'carry no severity tag', implode( "\n", wplc_consistency_check_todo( $todo . "\n- [ ] **Z-98** Neu.\n" ) ) );
		$this->assertSame( array(), wplc_consistency_check_todo( $todo . "\n  - [ ] **Z-97 [WICHTIG]** Zeile einer Arbeitsliste.\n" ) );
	}

	public function test_ranges_of_task_numbers_are_expanded(): void {
		$this->assertSame(
			array( 'T-12', 'FR-LIC-01', 'FR-LIC-02', 'FR-LIC-03', 'WFC-LIC-06', 'R-25' ),
			wplc_consistency_expand_ids( 'T-12, FR-LIC-01 bis -03, WFC-LIC-06 bis -06, R-25.' )
		);
	}

	public function test_a_changed_ci_matrix_is_noticed_in_both_documents(): void {
		$jobs = wplc_consistency_ci_jobs( self::root() . '/.github/workflows' );
		$this->assertArrayNotHasKey( 'build', $jobs, 'The release workflow runs on tags, not on pull requests.' );
		$this->assertGreaterThanOrEqual( 2, $jobs['unit'] );
		$this->assertGreaterThanOrEqual( 2, $jobs['integration'] );

		$copy = $this->copy();
		self::replace( $copy . '/.github/workflows/phpunit.yml', '        include:' . "\n" . '          # Declared WooCommerce minimum', '        include:' . "\n" . '          - { wp: "9.9", wc: "9.9.9", php: "9.9", hpos: "1" }' . "\n" . '          # Declared WooCommerce minimum' );
		$problems = implode( "\n", wplc_consistency_check_ci( $copy ) );
		$this->assertStringContainsString( 'docs/QUALITY-GATES.md: says ' . array_sum( $jobs ) . ' CI jobs; the workflows define ' . ( array_sum( $jobs ) + 1 ), $problems );
		$this->assertStringContainsString( 'docs/TODO.md: says ' . $jobs['integration'] . ' integration cells; the workflows define ' . ( $jobs['integration'] + 1 ), $problems );
		$this->assertStringContainsString( 'integration cells (table)', $problems );

		$copy = $this->copy();
		self::replace( $copy . '/.github/workflows/phpunit.yml', 'php: ["8.5"]', 'php: ["8.4", "8.5"]' );
		$problems = implode( "\n", wplc_consistency_check_ci( $copy ) );
		$this->assertStringContainsString( 'unit jobs; the workflows define ' . ( $jobs['unit'] + 1 ), $problems );

		// A new job in a pull request workflow counts as well.
		$copy = $this->copy();
		file_put_contents( $copy . '/.github/workflows/extra.yml', "name: Extra\n\non:\n  pull_request:\n\njobs:\n  extra:\n    runs-on: ubuntu-latest\n" );
		$this->assertStringContainsString( 'the workflows define ' . ( array_sum( $jobs ) + 1 ), implode( "\n", wplc_consistency_check_ci( $copy ) ) );
	}

	public function test_translations_that_do_not_match_the_template_are_noticed(): void {
		$copy = $this->copy();
		$po   = $copy . '/languages/wp-license-de_DE.po';

		file_put_contents( $copy . '/languages/wp-license.pot', "\n#: src/New.php:1\nmsgid \"A text added after the last run\"\nmsgstr \"\"\n", FILE_APPEND );
		$this->assertStringContainsString( 'wp-license-de_DE.po: 1 text(s) of the template are missing', implode( "\n", wplc_consistency_check_translations( $copy ) ) );

		file_put_contents( $po, "\n#: src/New.php:1\n#, fuzzy\nmsgid \"A text added after the last run\"\nmsgstr \"\"\n\n#~ msgid \"Gone\"\n#~ msgstr \"Weg\"\n", FILE_APPEND );
		$this->assertStringContainsString( 'wp-license-de_DE.po: 1 untranslated, 1 fuzzy and 1 obsolete entries.', implode( "\n", wplc_consistency_check_translations( $copy ) ) );

		$copy = $this->copy();
		self::replace( $copy . '/wp-license.php', 'Version:', 'Version: 9.9.9 was' );
		$problems = implode( "\n", wplc_consistency_check_translations( $copy ) );
		$this->assertStringContainsString( 'wp-license.pot: the header does not carry the plugin version 9.9.9', $problems );

		$copy = $this->copy();
		unlink( $copy . '/languages/wp-license-de_DE_formal.mo' );
		$this->assertStringContainsString( 'wp-license-de_DE_formal.po: no compiled .mo file', implode( "\n", wplc_consistency_check_translations( $copy ) ) );
	}
}
