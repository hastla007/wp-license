<?php
use PHPUnit\Framework\TestCase;
use WPLC\Admin\KeyConfigurationNotice;
use WPLC\License\LicenseKey;
use WPLC\Security\SecretCrypto;

final class KeyConfigurationTest extends TestCase {
	/** tests/bootstrap.php defines both key rings, so no fallback may be reported. */
	public function test_configured_key_rings_are_not_reported_as_fallback(): void {
		$this->assertFalse( LicenseKey::uses_fallback_secret() );
		$this->assertFalse( SecretCrypto::uses_fallback_key() );
		$this->assertFalse( KeyConfigurationNotice::is_fallback_active() );
	}

	public function test_example_contains_both_key_rings_with_the_fallback_key_ids(): void {
		$example = KeyConfigurationNotice::example( true, true, '2026-11' );

		$this->assertStringContainsString( "define( 'WPLC_LICENSE_KEY_SECRETS', array(", $example );
		$this->assertStringContainsString( "'legacy' => 'PASTE-CURRENT-AUTH_KEY-FOLLOWED-BY-AUTH_SALT'", $example );
		$this->assertStringContainsString( "define( 'WPLC_LICENSE_KEY_ID', '2026-11' );", $example );
		$this->assertStringContainsString( "define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array(", $example );
		$this->assertStringContainsString( "'wp-salt' => 'PASTE-CURRENT-SECURE_AUTH_KEY-FOLLOWED-BY-SECURE_AUTH_SALT'", $example );
		$this->assertStringContainsString( "define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '2026-11' );", $example );
	}

	public function test_example_only_lists_the_missing_constant(): void {
		$license_only = KeyConfigurationNotice::example( true, false, 'k2' );
		$this->assertStringContainsString( 'WPLC_LICENSE_KEY_SECRETS', $license_only );
		$this->assertStringNotContainsString( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', $license_only );

		$crypto_only = KeyConfigurationNotice::example( false, true, 'k2' );
		$this->assertStringNotContainsString( 'WPLC_LICENSE_KEY_SECRETS', $crypto_only );
		$this->assertStringContainsString( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', $crypto_only );
	}

	public function test_example_never_contains_configured_secrets_and_is_valid_php(): void {
		$example = KeyConfigurationNotice::example( true, true, "x'; evil();//" );

		foreach ( array( WPLC_LICENSE_KEY_SECRET, 'test-current-license-key', 'test-previous-license-key', 'test-current-encryption-key', 'test-previous-encryption-key' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $example );
		}
		$this->assertStringNotContainsString( 'evil', $example );
		$this->assertMatchesRegularExpression( "/define\\( 'WPLC_LICENSE_KEY_ID', '\\d{4}-\\d{2}' \\);/", $example );

		$tokens = token_get_all( "<?php\n" . $example, TOKEN_PARSE );
		$this->assertNotEmpty( $tokens );
	}
}
