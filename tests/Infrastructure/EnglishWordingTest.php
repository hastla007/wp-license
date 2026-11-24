<?php
/**
 * P5-M11: English wording of the source texts ("email", "for example").
 *
 * Covers the templates and the webhook screens; extend $paths when other folders are cleaned up.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class EnglishWordingTest extends TestCase {

	public function test_templates_and_webhooks_write_email_and_for_example(): void {
		$root  = dirname( __DIR__, 2 );
		$paths = array( 'templates', 'src/Webhooks' );
		$found = array();
		foreach ( $paths as $path ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $path, FilesystemIterator::SKIP_DOTS ) );
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
}
