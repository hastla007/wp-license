<?php
/**
 * T-106: the audit log redacts license keys of both formats.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\AuditLog;
use WPLC\License\LicenseKey;

final class LicenseKeyRedactionTest extends TestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public function data_keys(): array {
		$uuid  = '0f8fad5b-d9cb-469f-a165-70867728950e';
		$short = LicenseKey::for_uuid( $uuid );

		return array(
			'short key'                 => array( $short ),
			'short key, lower case'     => array( strtolower( $short ) ),
			'short key without hyphens' => array( str_replace( '-', '', $short ) ),
			'short key with spaces'     => array( str_replace( '-', ' ', $short ) ),
			'legacy 106-character key'  => array( LicenseKey::for_uuid( $uuid, null, LicenseKey::FORMAT_LONG ) ),
		);
	}

	/**
	 * @dataProvider data_keys
	 *
	 * @param string $key Key as written in a support note.
	 */
	public function test_key_in_a_reason_is_redacted( string $key ): void {
		$text = AuditLog::redact_text_for_display( 'Customer wrote: my key ' . $key . ' fails.' );

		$this->assertStringContainsString( '[REDACTED-LICENSE-KEY]', $text );
		$this->assertStringNotContainsString( substr( str_replace( array( '-', ' ' ), '', $key ), 4, 12 ), str_replace( array( '-', ' ' ), '', $text ) );
		$this->assertStringEndsWith( ' fails.', $text );
	}

	public function test_text_without_a_key_stays(): void {
		$this->assertSame( 'Plan WPLC business renewed.', AuditLog::redact_text_for_display( 'Plan WPLC business renewed.' ) );
	}
}
