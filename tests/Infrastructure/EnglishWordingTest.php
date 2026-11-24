<?php
/**
 * P5-M11: English wording of the source texts ("email", "for example").
 *
 * Covers the templates, the webhook screens, the privacy texts and the public cancellation page; extend $paths when other folders are cleaned up.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class EnglishWordingTest extends TestCase {

	public function test_templates_and_webhooks_write_email_and_for_example(): void {
		$root  = dirname( __DIR__, 2 );
		$paths = array( 'templates', 'src/Webhooks', 'src/Privacy/PersonalData.php', 'src/Account/PublicCancellation.php' );
		$found = array();
		foreach ( $paths as $path ) {
			$files = is_file( $root . '/' . $path )
				? array( new SplFileInfo( $root . '/' . $path ) )
				: new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				foreach ( (array) file( $file->getPathname() ) as $number => $line ) {
					if ( preg_match( '/\be-mail|\be\.g\./i', (string) $line ) ) {
						$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $number + 1 );
					}
				}
			}
		}
		$this->assertSame( array(), $found, 'Write "email" and "for example".' );
	}

	/**
	 * Translator comments of src/ write "for example"; src/ writes "email". The client library
	 * (src/Client) is left out: any change there needs a new library version (CLIENT-REPOS.md).
	 */
	public function test_src_writes_email_and_translator_comments_write_for_example(): void {
		$root    = dirname( __DIR__, 2 );
		$exclude = array(
			'src/Client/',
			// Cleaned up separately (P5-M11, other change set); remove once done.
			'src/Privacy/PersonalData.php',
			'src/Account/PublicCancellation.php',
		);
		$found   = array();
		$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( $exclude as $prefix ) {
				if ( 0 === strpos( $relative, $prefix ) ) {
					continue 2;
				}
			}
			foreach ( (array) file( $file->getPathname() ) as $number => $line ) {
				$line = (string) $line;
				if ( preg_match( '/\be-mail/i', $line ) || ( false !== stripos( $line, 'translators:' ) && preg_match( '/\be\.g\./i', $line ) ) ) {
					$found[] = $relative . ':' . ( $number + 1 );
				}
			}
		}
		$this->assertSame( array(), $found, 'Write "email", and "for example" in translator comments.' );
	}
}
