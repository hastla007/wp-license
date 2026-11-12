<?php
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

final class AuditRedactionIntegrationTest extends WP_UnitTestCase {
	public function test_audit_summaries_and_reasons_redact_credentials_and_contact_data(): void {
		global $wpdb;

		Schema::migrate();
		AuditLog::record(
			'privacy_redaction_test',
			'test',
			123,
			'Support note token=private-token-123 and owner jane@example.test used WPLC-ABCDEF1234567890',
			array(
				'license_key' => 'WPLC-SECRET-KEY-123456',
				'customer' => array(
					'email' => 'jane@example.test',
					'status' => 'active',
				),
			),
			array(
				'access_token' => 'private-token-456',
				'result' => 'ok',
			)
		);

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT reason,before_json,after_json FROM {$tables['audit_log']} WHERE action = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
				'privacy_redaction_test',
				123
			),
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$stored = implode( ' ', $row );
		$this->assertStringNotContainsString( 'private-token', $stored );
		$this->assertStringNotContainsString( 'WPLC-ABCDEF1234567890', $stored );
		$this->assertStringNotContainsString( 'WPLC-SECRET-KEY-123456', $stored );
		$this->assertStringNotContainsString( 'jane@example.test', $stored );
		$this->assertStringContainsString( '[REDACTED]', $stored );
	}
}
