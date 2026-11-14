<?php
/**
 * Fixture access and common test setup.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium\Tests\Support;

use Vendor\Product\Premium\Client\LicenseClient;
use Vendor\Product\Premium\License\LicenseService;

/**
 * Helpers shared by the unit tests.
 */
final class Fixtures {
	public const CREDENTIALS = 'starter_test_license';
	public const STATE       = 'starter_test_license_state';
	public const UPDATE      = 'starter_test_update';

	/**
	 * Decoded JSON fixture.
	 *
	 * @param string $name File in tests/fixtures.
	 * @return array<string,mixed>
	 */
	public static function json( string $name ): array {
		return json_decode( (string) file_get_contents( self::path( $name ) ), true );
	}

	/** Absolute path of a fixture. */
	public static function path( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/' . $name;
	}

	/** Unix time of the fixtures' server_time plus an offset. */
	public static function time( int $offset = 0 ): int {
		return (int) strtotime( (string) self::json( 'keys.json' )['server_time'] . ' UTC' ) + $offset;
	}

	/** Store the credentials of the fixture installation, as LicenseClient::activate() writes them. */
	public static function store_credentials(): void {
		$keys = self::json( 'keys.json' );
		update_option(
			self::CREDENTIALS,
			array(
				'installation_id'     => $keys['installation_id'],
				'installation_secret' => $keys['installation_secret'],
				'activation_id'       => $keys['activation_id'],
				'license_key_hash'    => hash( 'sha256', 'STARTER-KEY-1234' ),
				'signed_payload'      => 1,
			)
		);
	}

	/**
	 * Put a fixture envelope into the cache option.
	 *
	 * @param string $name Fixture file.
	 */
	public static function cache( string $name ): void {
		update_option( self::STATE, array( 'envelope' => self::json( $name ), 'environment' => 'production' ) );
	}

	/**
	 * Client pinned to the fixture server key (or the given keys).
	 *
	 * @param string[]|null $keys Pinned keys.
	 */
	public static function client( ?array $keys = null, string $version = '1.0.0' ): LicenseClient {
		$fixture = self::json( 'keys.json' );
		return new LicenseClient( (string) $fixture['server_url'], (string) $fixture['product_key'], $version, self::CREDENTIALS, $keys ?? array( (string) $fixture['server_public_key'] ) );
	}

	/**
	 * Service with a fixed clock.
	 *
	 * @param int                $now    Current time.
	 * @param LicenseClient|null $client Client.
	 */
	public static function service( int $now, ?LicenseClient $client = null ): LicenseService {
		return new LicenseService(
			$client ?? self::client(),
			self::CREDENTIALS,
			self::STATE,
			(string) self::json( 'keys.json' )['entitlement_prefix'],
			1209600,
			static function () use ( $now ): int {
				return $now;
			}
		);
	}
}
