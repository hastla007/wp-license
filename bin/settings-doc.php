<?php
/**
 * Prints the table of all settings for docs/manual/13-settings.md (SET-LIC-36).
 *
 * usage: php bin/settings-doc.php            the Markdown tables, one per group
 *        php bin/settings-doc.php --write    replace the part between the two markers in the chapter
 *
 * The data comes from the register (src/Settings); a unit test fails when the chapter is not current.
 *
 * @package WPLC
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( '__' ) ) {
	/**
	 * English source text (no WordPress here).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}
foreach ( array( 'Registry', 'Definitions', 'Texts' ) as $wplc_class ) {
	if ( ! class_exists( 'WPLC\\Settings\\' . $wplc_class ) ) {
		require dirname( __DIR__ ) . '/src/Settings/' . $wplc_class . '.php';
	}
}

/**
 * The generated part of the chapter.
 *
 * @return string
 */
function wplc_settings_doc(): string {
	$cell = static function ( string $text ): string {
		return str_replace( array( '|', "\n" ), array( '\\|', ' ' ), $text );
	};
	$out  = '';
	$name = \WPLC\Settings\Texts::groups();
	foreach ( \WPLC\Settings\Registry::GROUPS as $group ) {
		$keys = \WPLC\Settings\Registry::keys_of_group( $group );
		if ( ! $keys ) {
			continue;
		}
		$out .= '### ' . $name[ $group ] . "\n\n| Setting | Key | Value | Default | Filter (wins) | What it does |\n| --- | --- | --- | --- | --- | --- |\n";
		foreach ( $keys as $key ) {
			$definition = \WPLC\Settings\Registry::definition( $key );
			switch ( $definition['type'] ) {
				case 'int':
					$kind = 'whole number, ' . $definition['min'] . ' to ' . $definition['max'];
					break;
				case 'int_list':
					$kind = 'up to ' . ( $definition['max_items'] ?? 10 ) . ' whole numbers, each ' . $definition['min'] . ' to ' . $definition['max'];
					break;
				case 'bool':
					$kind = 'on or off';
					break;
				case 'choice':
					$kind = 'one of `' . implode( '`, `', $definition['choices'] ) . '`';
					break;
				case 'hosts':
					$kind = 'host names, one per line';
					break;
				case 'string_list':
					$kind = 'entries, one per line, at most ' . ( $definition['max_items'] ?? 50 );
					break;
				default:
					$kind = 'text, at most ' . ( $definition['max_length'] ?? 500 ) . ' characters';
			}
			$default = \WPLC\Settings\Registry::display( $key, $definition['default'] );
			if ( 'bool' === $definition['type'] ) {
				$default = $definition['default'] ? 'on' : 'off';
			}
			$help = \WPLC\Settings\Texts::help( $key ) . ( $definition['legal'] ? ' **Legal review.**' : '' ) . ( 'manage_settings' !== $definition['capability'] ? ' Administrators only.' : '' );
			$out .= '| ' . $cell( \WPLC\Settings\Texts::label( $key ) ) . ' | `' . $key . '` | ' . $cell( $kind ) . ' | ' . ( '' === $default ? '(empty)' : '`' . $cell( $default ) . '`' ) . ' | ' . ( '' === $definition['filter'] ? '–' : '`' . $definition['filter'] . '`' ) . ' | ' . $cell( $help ) . " |\n";
		}
		$out .= "\n";
	}

	return $out;
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$wplc_file = dirname( __DIR__ ) . '/docs/manual/13-settings.md';
	if ( in_array( '--write', $argv, true ) ) {
		$wplc_text = (string) file_get_contents( $wplc_file );
		$wplc_new  = preg_replace( '/(<!-- settings:start -->\n).*(<!-- settings:end -->)/s', '$1' . "\n" . addcslashes( wplc_settings_doc(), '\\$' ) . '$2', $wplc_text, 1, $wplc_count );
		if ( 1 !== $wplc_count ) {
			fwrite( STDERR, "Markers not found in docs/manual/13-settings.md.\n" );
			exit( 1 );
		}
		file_put_contents( $wplc_file, $wplc_new );
		echo "docs/manual/13-settings.md updated.\n";
	} else {
		echo wplc_settings_doc(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
