<?php
/**
 * Sender note in the license emails (T-140).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

// Guarded stubs close enough to WordPress for the two small partials.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text ) {
		$text = str_replace( "\r\n", "\n", trim( (string) $text ) );
		$out  = '';
		foreach ( preg_split( "/\n\s*\n/", $text ) as $paragraph ) {
			$out .= '<p>' . str_replace( "\n", "<br />\n", $paragraph ) . "</p>\n";
		}
		return $out;
	}
}

final class SenderNoteTemplateTest extends TestCase {

	private const TEMPLATES = __DIR__ . '/../../templates/emails/';

	/**
	 * Render one partial with the given note.
	 *
	 * @param string $template    Path below templates/emails/.
	 * @param mixed  $sender_note Value handed to the template.
	 */
	private function render( string $template, $sender_note ): string {
		ob_start();
		( static function ( string $file, $sender_note ) {
			include $file;
		} )( self::TEMPLATES . $template, $sender_note );
		return (string) ob_get_clean();
	}

	public function test_html_note_is_escaped_and_keeps_line_breaks(): void {
		$html = $this->render( 'wplc-sender-note.php', "Questions? support@shop.test\n<script>x</script> & more" );

		$this->assertStringContainsString( 'class="wplc-sender-note"', $html );
		$this->assertStringContainsString( 'support@shop.test<br />', $html );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt; &amp; more', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_plain_note_has_no_markup(): void {
		$plain = $this->render( 'plain/wplc-sender-note.php', "Call us: <b>0123</b>\nMon-Fri" );

		$this->assertSame( "Call us: 0123\nMon-Fri\n\n", $plain );
	}

	/**
	 * @dataProvider empty_notes
	 *
	 * @param mixed $note Empty or unusable value.
	 */
	public function test_empty_note_renders_nothing( $note ): void {
		$this->assertSame( '', $this->render( 'wplc-sender-note.php', $note ) );
		$this->assertSame( '', $this->render( 'plain/wplc-sender-note.php', $note ) );
	}

	public function empty_notes(): array {
		return array(
			'empty' => array( '' ),
			'whitespace' => array( " \n\t " ),
			'null' => array( null ),
			'array' => array( array( 'x' ) ),
		);
	}

	/**
	 * Every license email, HTML and plain, must include the partial.
	 */
	public function test_every_license_email_template_includes_the_note(): void {
		$templates = array_merge( glob( self::TEMPLATES . 'wplc-*.php' ), glob( self::TEMPLATES . 'plain/wplc-*.php' ) );
		$checked   = 0;

		foreach ( $templates as $file ) {
			if ( in_array( basename( $file ), array( 'wplc-license-details.php', 'wplc-sender-note.php' ), true ) ) {
				continue;
			}
			$source  = (string) file_get_contents( $file );
			$partial = false !== strpos( $file, '/plain/' ) ? "'emails/plain/wplc-sender-note.php'" : "'emails/wplc-sender-note.php'";
			$this->assertStringContainsString( $partial, $source, $file );
			$this->assertStringContainsString( "'sender_note' => isset( \$sender_note )", $source, $file );
			++$checked;
		}

		$this->assertSame( 12, $checked );
	}
}
