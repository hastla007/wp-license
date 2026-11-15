<?php
/**
 * Create and publish releases from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Update\ReleaseRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for the release registry, so a build pipeline can ship a release without a browser (B-169).
 */
final class ReleaseCommand {
	/**
	 * Register the command when WP-CLI is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wplc release', self::class );
		}
	}

	/**
	 * Create a release from a ZIP file on this server.
	 *
	 * The ZIP is copied into the private package directory. It must contain
	 * exactly one top-level folder (the plugin folder of the product) and a
	 * plugin file whose "Version:" header equals --version.
	 *
	 * ## OPTIONS
	 *
	 * --product=<product_key>
	 * : Product key from the catalog.
	 *
	 * --version=<version>
	 * : Release version: 1.2.3, 1.2.3-alpha1, 1.2.3-beta1 or 1.2.3-rc1.
	 *
	 * --zip=<path>
	 * : Path of the package ZIP.
	 *
	 * [--channel=<channel>]
	 * : Release channel.
	 * ---
	 * default: stable
	 * options:
	 *   - stable
	 *   - beta
	 * ---
	 *
	 * [--requires-php=<version>]
	 * : Minimum PHP version. Default: "Requires PHP" header of the plugin.
	 *
	 * [--requires-wp=<version>]
	 * : Minimum WordPress version. Default: "Requires at least" header of the plugin.
	 *
	 * [--tested-wp=<version>]
	 * : WordPress version the release was tested with.
	 *
	 * [--requires-core=<version>]
	 * : Minimum version of the free core plugin.
	 *
	 * [--notes=<html>]
	 * : Release notes.
	 *
	 * [--notes-file=<path>]
	 * : File with the release notes (instead of --notes).
	 *
	 * [--publish]
	 * : Publish the release at once. Without it a draft is created.
	 *
	 * [--reason=<text>]
	 * : Reason for the release log when --publish is used.
	 *
	 * [--porcelain]
	 * : Print only the release ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc release create --product=voucher-captain-pro --version=1.4.0 --zip=build/voucher-captain-pro-1.4.0.zip --notes-file=CHANGES.html --publish
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function create( array $args, array $assoc_args ): void {
		$result = self::run_create( $assoc_args );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $result['release_id'] );
			return;
		}
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		\WP_CLI::success(
			$result['published']
				? sprintf( 'Release %d published.', $result['release_id'] )
				: sprintf( 'Draft release %d created. Publish it with "wp wplc release publish %d --reason=...".', $result['release_id'], $result['release_id'] )
		);
	}

	/**
	 * Publish a draft or withdrawn release.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Release ID.
	 *
	 * --reason=<text>
	 * : Reason for the release log.
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function publish( array $args, array $assoc_args ): void {
		$release_id = absint( $args[0] ?? 0 );
		$reason = ReleaseRegistry::normalize_reason( (string) ( $assoc_args['reason'] ?? '' ) );
		if ( '' === $reason ) {
			\WP_CLI::error( ReleaseRegistry::error_message( 'reason_required' ) );
		}
		$warning = ReleaseRegistry::publish_warning( $release_id );
		$result = ReleaseRegistry::publish( $release_id, 0, $reason );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( '' !== $warning ) {
			\WP_CLI::warning( $warning );
		}
		\WP_CLI::success( sprintf( 'Release %d published.', $release_id ) );
	}

	/**
	 * Withdraw a published release.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Release ID.
	 *
	 * --reason=<text>
	 * : Reason for the release log.
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function withdraw( array $args, array $assoc_args ): void {
		$release_id = absint( $args[0] ?? 0 );
		$reason = ReleaseRegistry::normalize_reason( (string) ( $assoc_args['reason'] ?? '' ) );
		if ( '' === $reason ) {
			\WP_CLI::error( ReleaseRegistry::error_message( 'reason_required' ) );
		}
		$result = ReleaseRegistry::withdraw( $release_id, $reason, 0 );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Release %d withdrawn.', $release_id ) );
	}

	/**
	 * Create (and optionally publish) a release. Kept free of WP_CLI calls so it can be tested.
	 *
	 * @param array<string,mixed> $assoc_args Options of `wp wplc release create`.
	 * @return array{release_id:int,published:bool,warnings:string[]}|\WP_Error
	 */
	public static function run_create( array $assoc_args ) {
		$zip = (string) ( $assoc_args['zip'] ?? '' );
		if ( '' === $zip || ! is_file( $zip ) || ! is_readable( $zip ) ) {
			return new \WP_Error( 'zip_not_found', sprintf( 'The ZIP file "%s" does not exist or is not readable.', $zip ) );
		}
		$notes = (string) ( $assoc_args['notes'] ?? '' );
		$notes_file = (string) ( $assoc_args['notes-file'] ?? '' );
		if ( '' !== $notes_file ) {
			if ( ! is_file( $notes_file ) || ! is_readable( $notes_file ) ) {
				return new \WP_Error( 'notes_not_found', sprintf( 'The notes file "%s" does not exist or is not readable.', $notes_file ) );
			}
			$notes = (string) file_get_contents( $notes_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named by the operator.
		}
		$publish = ! empty( $assoc_args['publish'] );
		$reason = ReleaseRegistry::normalize_reason( (string) ( $assoc_args['reason'] ?? '' ) );
		$channel = (string) ( $assoc_args['channel'] ?? ReleaseRegistry::DEFAULT_CHANNEL );
		if ( ! in_array( $channel, array( ReleaseRegistry::DEFAULT_CHANNEL, ReleaseRegistry::CHANNEL_BETA ), true ) ) {
			return ReleaseRegistry::error( 'invalid_channel' );
		}

		$release_id = ReleaseRegistry::create_with_package(
			array(
				'product_key' => sanitize_key( (string) ( $assoc_args['product'] ?? '' ) ),
				'version' => trim( (string) ( $assoc_args['version'] ?? '' ) ),
				'channel' => $channel,
				'requires_php' => trim( (string) ( $assoc_args['requires-php'] ?? '' ) ),
				'requires_wp' => trim( (string) ( $assoc_args['requires-wp'] ?? '' ) ),
				'tested_wp' => trim( (string) ( $assoc_args['tested-wp'] ?? '' ) ),
				'requires_core' => trim( (string) ( $assoc_args['requires-core'] ?? '' ) ),
				'release_notes' => $notes,
			),
			$zip,
			0
		);
		if ( is_wp_error( $release_id ) ) {
			return $release_id;
		}
		$warnings = array();
		if ( $publish ) {
			$warning = ReleaseRegistry::publish_warning( $release_id );
			$published = ReleaseRegistry::publish( $release_id, 0, '' !== $reason ? $reason : 'Published with WP-CLI.' );
			if ( is_wp_error( $published ) ) {
				// Leave nothing half-done behind: the pipeline can simply run again.
				ReleaseRegistry::delete_draft( $release_id, 0, 'Publishing with WP-CLI failed.' );
				return $published;
			}
			if ( '' !== $warning ) {
				$warnings[] = $warning;
			}
		}

		return array(
			'release_id' => (int) $release_id,
			'published' => $publish,
			'warnings' => $warnings,
		);
	}
}
