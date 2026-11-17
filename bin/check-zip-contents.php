#!/usr/bin/env php
<?php
/**
 * Checks the entry list of the plugin ZIP that bin/build-zip.sh built.
 *
 * Usage:
 *   php bin/check-zip-contents.php <contents.txt> [--manual-dir=docs/manual] [--require-mo] [--require-catalogs]
 *
 * <contents.txt> is the output of `unzip -Z1` (one entry per line). The rules:
 * - every runtime file the plugin needs is there, and so is the vendor manual: README.md and every
 *   chapter of --manual-dir (PROD-10), plus docs/catalog-file.schema.json, which the manual links to;
 * - with --require-mo, at least one compiled translation (languages/*.mo); with --require-catalogs, at
 *   least one shipped catalog file (catalogs/*.json, imported by schema step 24);
 * - everything lies in the one top-level folder wp-license/;
 * - no development files (tests, tools, bin, dotfiles, Composer, PHPUnit, PHPStan, PHPCS, README.md,
 *   CHANGELOG.md of the repository);
 * - no internal document: below docs/ only the manual and the schema are allowed, and a file named
 *   like an internal document (to-do list, launch evidence, release readiness, plans, reviews,
 *   audits) is refused anywhere in the package.
 *
 * Prints one `::error::` line per problem and ends with exit code 1, or prints a summary and ends
 * with 0. The script needs PHP only; tests/Infrastructure/ReleasePackageTest.php runs it.
 *
 * @package WPLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- command line script without WordPress.

if ( ! function_exists( 'wplc_zip_contents_problems' ) ) {

	/**
	 * Problems of a package entry list.
	 *
	 * @param string[]                                                        $entries Entry names as `unzip -Z1` prints them.
	 * @param array{manual?:string[],require_mo?:bool,require_catalogs?:bool} $options `manual`: file names of the
	 *        manual chapters that must be in docs/manual/ (and nothing else may be there); `require_mo`: a compiled
	 *        translation must be in languages/; `require_catalogs`: a catalog file must be in catalogs/.
	 * @return string[] One message per problem, empty when the list is fine.
	 */
	function wplc_zip_contents_problems( array $entries, array $options = array() ): array {
		$entries  = array_values( array_filter( array_map( 'trim', $entries ), 'strlen' ) );
		$manual   = isset( $options['manual'] ) ? array_values( $options['manual'] ) : array();
		$problems = array();

		$required = array(
			'wp-license/wp-license.php',
			'wp-license/uninstall.php',
			'wp-license/readme.txt',
			'wp-license/vendor/autoload.php',
			'wp-license/src/Plugin.php',
			'wp-license/mu-plugins/wplc-site-cleanup.php',
			'wp-license/docs/manual/README.md',
			'wp-license/docs/catalog-file.schema.json',
		);
		foreach ( $manual as $chapter ) {
			$required[] = 'wp-license/docs/manual/' . $chapter;
		}
		foreach ( array_unique( $required ) as $file ) {
			if ( ! in_array( $file, $entries, true ) ) {
				$problems[] = 'The ZIP is missing ' . $file . '.';
			}
		}
		if ( ! empty( $options['require_mo'] ) && ! preg_grep( '#^wp-license/languages/[^/]+\.mo$#', $entries ) ) {
			$problems[] = 'The ZIP contains no compiled translations (languages/*.mo).';
		}
		if ( ! empty( $options['require_catalogs'] ) && ! preg_grep( '#^wp-license/catalogs/[^/]+\.json$#', $entries ) ) {
			$problems[] = 'The ZIP contains no catalog files (catalogs/*.json).';
		}

		$internal = '#(^|/)(TODO|LAUNCH-EVIDENCE|RELEASE-READINESS|PRODUCT-PLAN|IMPLEMENTATION-PLAN|QUALITY-GATES|BROWSER-ACCEPTANCE|STRIPE-TEST-PLAN|CLIENT-REPOS|PRODUCT-COMPATIBILITY|REVIEW[^/]*|AUDIT[^/]*)\.md$#i';
		$docs_ok  = array( 'wp-license/docs/', 'wp-license/docs/manual/', 'wp-license/docs/catalog-file.schema.json' );
		foreach ( $entries as $entry ) {
			if ( 0 !== strpos( $entry, 'wp-license/' ) ) {
				$problems[] = 'The ZIP contains an entry outside the wp-license/ folder: ' . $entry;
				continue;
			}
			if ( preg_match( '#^wp-license/(tests|tools|bin)/|/\.[^/]+(/|$)|^wp-license/(composer\.(json|lock)|phpunit[^/]*|phpstan[^/]*|phpcs[^/]*|README\.md|CHANGELOG\.md)$#', $entry ) ) {
				$problems[] = 'The ZIP contains a development file: ' . $entry;
				continue;
			}
			if ( preg_match( $internal, $entry ) ) {
				$problems[] = 'The ZIP contains an internal document: ' . $entry;
				continue;
			}
			if ( 0 === strpos( $entry, 'wp-license/docs/' ) && ! in_array( $entry, $docs_ok, true ) ) {
				$chapter = preg_match( '#^wp-license/docs/manual/([A-Za-z0-9._-]+\.md)$#', $entry, $match ) ? $match[1] : '';
				if ( '' === $chapter || ( array() !== $manual && ! in_array( $chapter, $manual, true ) ) ) {
					$problems[] = 'The ZIP contains a document that is not part of the vendor manual: ' . $entry;
				}
			}
		}

		return $problems;
	}

	/**
	 * File names of the manual chapters in a directory.
	 *
	 * @param string $dir Directory, for example docs/manual.
	 * @return string[]
	 */
	function wplc_zip_manual_chapters( string $dir ): array {
		$files = glob( rtrim( $dir, '/' ) . '/*.md' );
		$names = array_map( 'basename', false === $files ? array() : $files );
		sort( $names );

		return $names;
	}
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$wplc_list    = '';
	$wplc_options = array();
	foreach ( array_slice( $argv, 1 ) as $wplc_arg ) {
		if ( 0 === strpos( $wplc_arg, '--manual-dir=' ) ) {
			$wplc_options['manual'] = wplc_zip_manual_chapters( substr( $wplc_arg, 13 ) );
			if ( array() === $wplc_options['manual'] ) {
				fwrite( STDERR, "::error::No manual chapters in {$wplc_arg}.\n" );
				exit( 1 );
			}
		} elseif ( '--require-mo' === $wplc_arg ) {
			$wplc_options['require_mo'] = true;
		} elseif ( '--require-catalogs' === $wplc_arg ) {
			$wplc_options['require_catalogs'] = true;
		} else {
			$wplc_list = $wplc_arg;
		}
	}
	if ( '' === $wplc_list || ! is_readable( $wplc_list ) ) {
		fwrite( STDERR, "Usage: php bin/check-zip-contents.php <contents.txt> [--manual-dir=docs/manual] [--require-mo] [--require-catalogs]\n" );
		exit( 2 );
	}
	$wplc_entries  = (array) file( $wplc_list, FILE_IGNORE_NEW_LINES );
	$wplc_problems = wplc_zip_contents_problems( $wplc_entries, $wplc_options );
	foreach ( $wplc_problems as $wplc_problem ) {
		echo '::error::' . $wplc_problem . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- command line output.
	}
	if ( array() !== $wplc_problems ) {
		exit( 1 );
	}
	echo 'Package contents OK (' . count( $wplc_entries ) . ' entries, ' . count( $wplc_options['manual'] ?? array() ) . " manual chapters)\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- command line output.
}
