<?php
use PHPUnit\Framework\TestCase;
use WPLC\Update\ReleaseRegistry;

final class ReleaseRegistryTest extends TestCase {
	private static function row( int $id, string $version, string $status = 'published' ): array {
		return array(
			'id' => $id,
			'version' => $version,
			'status' => $status,
		);
	}

	private static function valid_input(): array {
		return array(
			'product_key' => 'voucher-captain',
			'version' => '1.2.3',
			'package_url' => 'https://downloads.example.test/voucher-captain-1.2.3.zip',
			'sha256' => str_repeat( 'a', 64 ),
			'requires_php' => '7.4',
			'requires_wp' => '6.5',
			'tested_wp' => '6.8',
			'release_notes' => 'Fixes.',
		);
	}

	public function test_latest_is_chosen_by_version_not_by_id(): void {
		$latest = ReleaseRegistry::select_latest(
			array(
				self::row( 3, '1.9.0' ),
				self::row( 1, '1.10.0' ),
				self::row( 2, '1.2.0' ),
			)
		);
		$this->assertSame( 1, $latest['id'] );
	}

	public function test_stable_version_beats_its_pre_releases(): void {
		$latest = ReleaseRegistry::select_latest(
			array(
				self::row( 1, '2.0.0' ),
				self::row( 2, '2.0.0-rc1' ),
				self::row( 3, '2.0.0-beta2' ),
				self::row( 4, '2.0.0-alpha' ),
			)
		);
		$this->assertSame( '2.0.0', $latest['version'] );
	}

	public function test_pre_releases_are_ordered_alpha_beta_rc(): void {
		$sorted = ReleaseRegistry::sort_by_version_desc(
			array(
				self::row( 1, '2.0.0-beta1' ),
				self::row( 2, '1.9.9' ),
				self::row( 3, '2.0.0-rc1' ),
				self::row( 4, '2.0.0-alpha1' ),
				self::row( 5, '2.0.0-beta2' ),
				self::row( 6, '2.0.0' ),
			)
		);
		$this->assertSame(
			array( '2.0.0', '2.0.0-rc1', '2.0.0-beta2', '2.0.0-beta1', '2.0.0-alpha1', '1.9.9' ),
			array_column( $sorted, 'version' )
		);
	}

	public function test_pre_release_of_a_newer_version_beats_older_stable(): void {
		$latest = ReleaseRegistry::select_latest( array( self::row( 1, '1.9.9' ), self::row( 2, '2.0.0-beta1' ) ) );
		$this->assertSame( 2, $latest['id'] );
	}

	public function test_drafts_and_withdrawn_releases_are_never_latest(): void {
		$latest = ReleaseRegistry::select_latest(
			array(
				self::row( 1, '1.0.0' ),
				self::row( 2, '3.0.0', 'draft' ),
				self::row( 3, '2.0.0', 'withdrawn' ),
			)
		);
		$this->assertSame( 1, $latest['id'] );
		$this->assertNull( ReleaseRegistry::select_latest( array( self::row( 2, '3.0.0', 'draft' ) ) ) );
		$this->assertNull( ReleaseRegistry::select_latest( array() ) );
	}

	public function test_draft_can_change_everything(): void {
		$existing = array_merge( self::valid_input(), array( 'status' => 'draft', 'published_at' => null ) );
		$this->assertSame( array(), ReleaseRegistry::immutable_violations( $existing, array( 'version' => '9.9.9', 'sha256' => str_repeat( 'b', 64 ), 'package_url' => 'https://x.example.test/a.zip' ) ) );
	}

	public function test_published_release_freezes_version_package_and_checksum(): void {
		$existing = array_merge( self::valid_input(), array( 'status' => 'published', 'published_at' => '2026-01-01 00:00:00', 'storage' => 'url', 'channel' => 'stable' ) );
		$this->assertSame( array( 'version' ), ReleaseRegistry::immutable_violations( $existing, array( 'version' => '1.2.4' ) ) );
		$this->assertSame( array( 'package_url' ), ReleaseRegistry::immutable_violations( $existing, array( 'package_url' => 'https://evil.example.test/a.zip' ) ) );
		$this->assertSame( array( 'sha256' ), ReleaseRegistry::immutable_violations( $existing, array( 'sha256' => str_repeat( 'b', 64 ) ) ) );
		$this->assertSame( array( 'storage' ), ReleaseRegistry::immutable_violations( $existing, array( 'storage' => 'local' ) ) );
	}

	public function test_published_release_may_still_change_notes_and_requirements(): void {
		$existing = array_merge( self::valid_input(), array( 'status' => 'published', 'published_at' => '2026-01-01 00:00:00' ) );
		$changes = array(
			'release_notes' => 'Corrected typo.',
			'tested_wp' => '6.9',
			'version' => '1.2.3',
			'sha256' => strtoupper( str_repeat( 'a', 64 ) ),
		);
		$this->assertSame( array(), ReleaseRegistry::immutable_violations( $existing, $changes ) );
	}

	public function test_withdrawn_release_stays_frozen(): void {
		$existing = array_merge( self::valid_input(), array( 'status' => 'withdrawn', 'published_at' => '2026-01-01 00:00:00' ) );
		$this->assertSame( array( 'sha256' ), ReleaseRegistry::immutable_violations( $existing, array( 'sha256' => str_repeat( 'c', 64 ) ) ) );
	}

	public function test_status_transitions(): void {
		$this->assertTrue( ReleaseRegistry::can_transition( 'draft', 'published' ) );
		$this->assertTrue( ReleaseRegistry::can_transition( 'published', 'withdrawn' ) );
		$this->assertTrue( ReleaseRegistry::can_transition( 'withdrawn', 'published' ) );
		$this->assertFalse( ReleaseRegistry::can_transition( 'draft', 'withdrawn' ) );
		$this->assertFalse( ReleaseRegistry::can_transition( 'published', 'draft' ) );
		$this->assertFalse( ReleaseRegistry::can_transition( 'published', 'published' ) );
		$this->assertFalse( ReleaseRegistry::can_transition( 'unknown', 'published' ) );
	}

	public function test_valid_release_input_is_normalized(): void {
		$input = self::valid_input();
		$input['sha256'] = strtoupper( $input['sha256'] );
		$result = ReleaseRegistry::validate( $input );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['data']['sha256'] );
		$this->assertSame( 'stable', $result['data']['channel'] );
		$this->assertSame( 'url', $result['data']['storage'] );
	}

	/**
	 * @dataProvider invalid_fields
	 */
	public function test_invalid_release_input_is_rejected( string $field, string $value, string $error ): void {
		$input = self::valid_input();
		$input[ $field ] = $value;
		$this->assertContains( $error, ReleaseRegistry::validate( $input )['errors'] );
	}

	public function invalid_fields(): array {
		return array(
			'http url' => array( 'package_url', 'http://downloads.example.test/a.zip', 'invalid_package_url' ),
			'url with credentials' => array( 'package_url', 'https://user:pass@downloads.example.test/a.zip', 'invalid_package_url' ),
			'url with fragment' => array( 'package_url', 'https://downloads.example.test/a.zip#x', 'invalid_package_url' ),
			'javascript url' => array( 'package_url', 'javascript:alert(1)', 'invalid_package_url' ),
			'empty url' => array( 'package_url', '', 'invalid_package_url' ),
			'short checksum' => array( 'sha256', str_repeat( 'a', 63 ), 'invalid_sha256' ),
			'non-hex checksum' => array( 'sha256', str_repeat( 'g', 64 ), 'invalid_sha256' ),
			'empty checksum' => array( 'sha256', '', 'invalid_sha256' ),
			'two-part version' => array( 'version', '1.2', 'invalid_version' ),
			'version with space' => array( 'version', '1.2.3 beta', 'invalid_version' ),
			'version with path' => array( 'version', '1.2.3-../../x', 'invalid_version' ),
			'uppercase product' => array( 'product_key', 'Voucher', 'invalid_product_key' ),
			'product with slash' => array( 'product_key', 'a/b', 'invalid_product_key' ),
			'bad php version' => array( 'requires_php', '7.4-dev', 'invalid_requires_php' ),
			'bad wp version' => array( 'requires_wp', 'six', 'invalid_requires_wp' ),
			'bad tested version' => array( 'tested_wp', '6.8.1.2', 'invalid_tested_wp' ),
			'bad storage' => array( 'storage', 's3', 'invalid_storage' ),
		);
	}

	public function test_local_storage_requires_a_storage_key_not_a_path(): void {
		$input = self::valid_input();
		$input['storage'] = 'local';
		$input['package_url'] = '../../wp-config.php';
		$this->assertContains( 'invalid_package_key', ReleaseRegistry::validate( $input )['errors'] );

		$input['package_url'] = 'voucher-captain-1.2.3-' . str_repeat( '0a', 16 ) . '.zip';
		$this->assertSame( array(), ReleaseRegistry::validate( $input )['errors'] );
	}

	public function test_manifest_from_release_tool_is_accepted(): void {
		$manifest = array_merge(
			self::valid_input(),
			array(
				'package_file' => 'voucher-captain-1.2.3.zip',
				'generated_at_utc' => '2026-01-01T00:00:00+00:00',
			)
		);
		$result = ReleaseRegistry::validate_manifest( $manifest );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'url', $result['data']['storage'] );
		$this->assertSame( '1.2.3', $result['data']['version'] );
		$this->assertArrayNotHasKey( 'package_file', $result['data'] );
	}

	public function test_manifest_cannot_select_local_storage(): void {
		$manifest = self::valid_input();
		$manifest['storage'] = 'local';
		$manifest['package_url'] = 'voucher-captain-1.2.3-' . str_repeat( '0a', 16 ) . '.zip';
		$result = ReleaseRegistry::validate_manifest( $manifest );
		$this->assertContains( 'invalid_package_url', $result['errors'] );
	}

	public function test_manifest_reports_missing_and_malformed_fields(): void {
		$this->assertSame( array( 'invalid_manifest' ), ReleaseRegistry::validate_manifest( null )['errors'] );
		$this->assertSame( array( 'invalid_manifest' ), ReleaseRegistry::validate_manifest( 'text' )['errors'] );

		$manifest = self::valid_input();
		unset( $manifest['sha256'] );
		$manifest['version'] = array( '1.2.3' );
		$errors = ReleaseRegistry::validate_manifest( $manifest )['errors'];
		$this->assertContains( 'missing_sha256', $errors );
		$this->assertNotContains( 'invalid_sha256', $errors );
		$this->assertContains( 'invalid_version', $errors );

		$manifest = self::valid_input();
		$manifest['sha256'] = 'not-a-checksum';
		$this->assertSame( array( 'invalid_sha256' ), ReleaseRegistry::validate_manifest( $manifest )['errors'] );
	}

	public function test_update_package_shape_matches_the_update_api(): void {
		$release = array_merge( self::valid_input(), array( 'id' => 7, 'sha256' => strtoupper( str_repeat( 'a', 64 ) ) ) );
		$package = ReleaseRegistry::to_update_package( $release, 'https://shop.example.test/x.zip' );
		$this->assertSame(
			array( 'version', 'package_url', 'sha256', 'requires_php', 'requires_wp', 'tested_wp', 'release_notes' ),
			array_keys( $package )
		);
		$this->assertSame( 'https://shop.example.test/x.zip', $package['package_url'] );
		$this->assertSame( str_repeat( 'a', 64 ), $package['sha256'] );
	}
}
