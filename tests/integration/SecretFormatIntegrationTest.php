<?php
/**
 * UEB-12 (2): stored secrets are bound to purpose and row; legacy values keep working and move to v2.
 *
 * @package WPLC
 */

use WPLC\Security\ResponseSigner;
use WPLC\Security\SecretCrypto;
use WPLC\Security\SecretMigration;
use WPLC\Webhooks\Endpoints;
use WPLC\Webhooks\UrlGuard;


final class SecretFormatIntegrationTest extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SecretMigration::STATE_OPTION );
		UrlGuard::set_resolver(
			static function ( string $host ): array {
				return 'hooks.example.com' === $host ? array( '93.184.215.14' ) : array();
			}
		);
	}

	public function tear_down(): void {
		UrlGuard::set_resolver( null );
		delete_option( Endpoints::INDEX_OPTION );
		parent::tear_down();
	}

	/**
	 * A v1 envelope as versions before UEB-12 wrote it (integration key ring, tests/integration-bootstrap.php).
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	private function v1_envelope( string $plaintext ): string {
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', hash( 'sha256', 'integration-only-encryption-secret', true ), OPENSSL_RAW_DATA, $nonce, $tag, 'wplc-secret-v1:integration-2026', 16 );

		return 'v1:integration-2026:' . base64_encode( $nonce . $tag . $ciphertext );
	}

	/**
	 * Seed an activation whose secret is stored as a v1 envelope.
	 *
	 * @param int $license_id License ID.
	 * @return array{id:int,uuid:string,secret:string}
	 */
	private function seed_v1_activation( int $license_id ): array {
		$secret = 'legacy-installation-secret-' . wp_generate_password( 24, false );
		$seeded = $this->seed_activation(
			$license_id,
			array(
				'installation_secret_hash'      => hash( 'sha256', $secret ),
				'installation_secret_encrypted' => $this->v1_envelope( $secret ),
			)
		);
		$seeded['secret'] = $secret;

		return $seeded;
	}

	public function test_a_secret_copied_into_another_row_no_longer_authenticates(): void {
		global $wpdb;
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 5 ) );
		$first   = $this->seed_activation( $license['id'] );
		$second  = $this->seed_activation( $license['id'] );
		$this->assertStringStartsWith( SecretCrypto::current_prefix(), $this->activation( $first['id'] )['installation_secret_encrypted'] );

		$ok = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $first ) );
		$this->assertSame( 200, $ok->get_status() );

		// Somebody with write access to the table copies the first row's credential into the second row.
		$row = $this->activation( $first['id'] );
		$wpdb->update(
			$this->tables()['activations'],
			array(
				'installation_secret_encrypted' => $row['installation_secret_encrypted'],
				'installation_secret_hash'      => $row['installation_secret_hash'],
			),
			array( 'id' => $second['id'] )
		);
		$copied = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), array( 'uuid' => $second['uuid'], 'secret' => $first['secret'] ) ) );
		$this->assertSame( 401, $copied->get_status() );
		$this->assertSame( 'installation_secret_invalid', $copied->get_data()['code'] );
	}

	public function test_a_v1_secret_still_authenticates_and_is_upgraded_on_use(): void {
		$license = $this->seed_license();
		$site    = $this->seed_v1_activation( $license['id'] );

		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );
		$this->assertSame( 200, $response->get_status() );

		$stored = $this->activation( $site['id'] )['installation_secret_encrypted'];
		$this->assertStringStartsWith( SecretCrypto::current_prefix(), $stored );
		$this->assertSame( $site['secret'], SecretCrypto::decrypt( $stored, SecretCrypto::PURPOSE_INSTALLATION, $site['uuid'] ) );
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) )->get_status() );
	}

	public function test_the_batch_moves_every_legacy_value_once_and_leaves_undecryptable_ones_alone(): void {
		global $wpdb;
		$tables = $this->tables();
		// Counts below are exact: start without rows other tests may have left (multisite runs keep data).
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables['activations'] ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables['webhook_endpoints'] ) );
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 5 ) );
		$legacy  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$legacy[] = $this->seed_v1_activation( $license['id'] );
		}
		$current        = $this->seed_activation( $license['id'] );
		$current_stored = $this->activation( $current['id'] )['installation_secret_encrypted'];
		$broken         = $this->seed_activation( $license['id'], array( 'installation_secret_encrypted' => 'v1:retired-key:' . base64_encode( random_bytes( 60 ) ) ) );

		// A webhook endpoint and the prepared signing key from before the update.
		$created = Endpoints::create( 'Legacy', 'https://hooks.example.com/in', array( 'license.issued' ), true, 0 );
		$this->assertTrue( $created['ok'], (string) $created['error'] );
		$wpdb->update( $tables['webhook_endpoints'], array( 'secret_encrypted' => $this->v1_envelope( $created['secret'] ) ), array( 'id' => $created['id'] ) );
		// The current signing key starts fresh (v2); the prepared one is a v1 envelope.
		delete_option( ResponseSigner::OPTION );
		delete_option( ResponseSigner::KEY_ID_OPTION );
		ResponseSigner::reset();
		$this->assertNotSame( '', ResponseSigner::public_key() );
		$signing = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x0b", 32 ) ) );
		update_option( ResponseSigner::NEXT_OPTION, $this->v1_envelope( base64_encode( (string) $signing ) ), false );
		$this->assertSame( 6, SecretMigration::legacy_count() );

		// Limited run: continues with the next one.
		$part = SecretMigration::run( 2 );
		$this->assertSame( array( 'continues', 2, 0 ), array( $part['status'], $part['migrated'], $part['failed'] ) );
		$this->assertSame( 1, $part['webhooks'] );
		$this->assertSame( $legacy[1]['id'], SecretMigration::state()['after_id'] );

		$rest = SecretMigration::run( 100 );
		$this->assertSame( array( 'finished', 1, 1 ), array( $rest['status'], $rest['migrated'], $rest['failed'] ) );
		$this->assertTrue( SecretMigration::state()['done'] );
		$this->assertSame( 1, SecretMigration::legacy_count(), 'Only the undecryptable row is left.' );

		foreach ( $legacy as $site ) {
			$stored = $this->activation( $site['id'] )['installation_secret_encrypted'];
			$this->assertStringStartsWith( SecretCrypto::current_prefix(), $stored );
			$this->assertSame( $site['secret'], SecretCrypto::decrypt( $stored, SecretCrypto::PURPOSE_INSTALLATION, $site['uuid'] ) );
			$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) )->get_status() );
		}
		$this->assertSame( $current_stored, $this->activation( $current['id'] )['installation_secret_encrypted'], 'A current value is not rewritten.' );
		$this->assertStringStartsWith( 'v1:retired-key:', $this->activation( $broken['id'] )['installation_secret_encrypted'] );
		$this->assertStringStartsWith( SecretCrypto::current_prefix(), (string) $wpdb->get_var( $wpdb->prepare( 'SELECT secret_encrypted FROM %i WHERE id = %d', $tables['webhook_endpoints'], $created['id'] ) ) );
		$this->assertSame( $created['secret'], Endpoints::secret( $created['id'] ) );
		$this->assertStringStartsWith( SecretCrypto::current_prefix(), (string) get_option( ResponseSigner::NEXT_OPTION ) );
		$this->assertSame( $signing, ResponseSigner::next_secret_key() );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s", 'secret_format_migrated' ) ) );

		// Done: the next hourly run reads one option and changes nothing.
		$this->assertSame( 'done', SecretMigration::run( 100 )['status'] );
		do_action( 'wplc_cleanup_security_rows' );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE action = %s", 'secret_format_migrated' ) ) );
	}

	public function test_the_hourly_job_runs_the_batch(): void {
		$license = $this->seed_license();
		$site    = $this->seed_v1_activation( $license['id'] );

		do_action( 'wplc_cleanup_security_rows' );

		$this->assertStringStartsWith( SecretCrypto::current_prefix(), $this->activation( $site['id'] )['installation_secret_encrypted'] );
		$this->assertTrue( SecretMigration::state()['done'] );
	}

	public function test_a_webhook_secret_is_bound_to_its_endpoint(): void {
		global $wpdb;
		$table = $this->tables()['webhook_endpoints'];
		$first  = Endpoints::create( 'First', 'https://hooks.example.com/a', array( 'license.issued' ), true, 0 );
		$second = Endpoints::create( 'Second', 'https://hooks.example.com/b', array( 'license.issued' ), true, 0 );
		$this->assertTrue( $first['ok'] && $second['ok'] );
		$this->assertSame( $first['secret'], Endpoints::secret( $first['id'] ) );
		$this->assertTrue( Endpoints::get( $first['id'] )['active'] );

		$wpdb->update( $table, array( 'secret_encrypted' => $wpdb->get_var( $wpdb->prepare( 'SELECT secret_encrypted FROM %i WHERE id = %d', $table, $first['id'] ) ) ), array( 'id' => $second['id'] ) );
		$this->assertSame( '', Endpoints::secret( $second['id'] ), 'A secret copied to another endpoint must not decrypt.' );
	}

	public function test_rotation_command_also_covers_webhook_secrets(): void {
		global $wpdb;
		$table   = $this->tables()['webhook_endpoints'];
		$created = Endpoints::create( 'Legacy', 'https://hooks.example.com/in', array( 'license.issued' ), true, 0 );
		$wpdb->update( $table, array( 'secret_encrypted' => $this->v1_envelope( $created['secret'] ) ), array( 'id' => $created['id'] ) );

		$dry = \WPLC\CLI\RotateInstallationSecretsCommand::run();
		$this->assertSame( 1, $dry['webhooks'] );
		$this->assertGreaterThanOrEqual( 1, $dry['remaining'] );
		$applied = \WPLC\CLI\RotateInstallationSecretsCommand::run( array( 'apply' => true ) );
		$this->assertSame( 1, $applied['webhooks'] );
		$this->assertStringStartsWith( SecretCrypto::current_prefix(), (string) $wpdb->get_var( $wpdb->prepare( 'SELECT secret_encrypted FROM %i WHERE id = %d', $table, $created['id'] ) ) );
		$this->assertSame( 0, \WPLC\CLI\RotateInstallationSecretsCommand::run()['webhooks'] );
	}
}
