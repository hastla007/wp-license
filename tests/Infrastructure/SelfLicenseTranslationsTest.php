<?php
/**
 * The server shows its own license screen with the client library (Infrastructure\SelfLicense,
 * text domain wp-license). The texts of the library are therefore part of the translation
 * catalog of the server; without them that screen stays English on a German site.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class SelfLicenseTranslationsTest extends TestCase {

	/**
	 * Translatable texts of the library (first argument of __() with the text domain of this plugin).
	 *
	 * @return string[]
	 */
	private static function library_texts(): array {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Client/Messages.php' );
		preg_match_all( "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*'wp-license'\\s*\\)/", $code, $matches );

		return array_values( array_unique( array_map( 'stripslashes', $matches[1] ) ) );
	}

	/**
	 * Translations of a .po file.
	 *
	 * @param string $file File name below languages/.
	 * @return array<string,string> msgid => msgstr.
	 */
	private static function translations( string $file ): array {
		$entries = array();
		foreach ( explode( "\n\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/languages/' . $file ) ) as $block ) {
			if ( 1 !== preg_match( '/^msgid ((?:".*"\n?)+)/m', $block, $id ) || 1 !== preg_match( '/^msgstr ((?:".*"\n?)+)/m', $block, $text ) ) {
				continue;
			}
			$join = static function ( string $lines ): string {
				preg_match_all( '/"(.*)"/', $lines, $parts );
				return stripcslashes( implode( '', $parts[1] ) );
			};
			$entries[ $join( $id[1] ) ] = $join( $text[1] );
		}

		return $entries;
	}

	public function test_every_text_of_the_library_is_translated_in_both_german_catalogs(): void {
		$texts = self::library_texts();
		$this->assertGreaterThan( 120, count( $texts ), 'The scan found the texts of Messages.php.' );

		foreach ( array( 'wp-license-de_DE.po', 'wp-license-de_DE_formal.po' ) as $file ) {
			$translations = self::translations( $file );
			$missing      = array();
			foreach ( $texts as $text ) {
				if ( '' === ( $translations[ $text ] ?? '' ) ) {
					$missing[] = $text;
				}
			}
			$this->assertSame( array(), $missing, $file . ': run bin/make-pot.sh and translate.' );
		}

		// "du" in de_DE, "Sie" in de_DE_formal.
		$this->assertSame( 'Bitte gib deinen Lizenzschlüssel ein.', self::translations( 'wp-license-de_DE.po' )['Please enter your license key.'] );
		$this->assertSame( 'Bitte geben Sie Ihren Lizenzschlüssel ein.', self::translations( 'wp-license-de_DE_formal.po' )['Please enter your license key.'] );
	}

	public function test_the_template_script_includes_the_library(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/bin/make-pot.sh' );
		$this->assertSame( 1, preg_match( '/--exclude=(\S+)/', $script, $match ) );
		$this->assertNotContains( 'src/Client', explode( ',', $match[1] ) );
	}
}
