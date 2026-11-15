<?php
/**
 * Lists the plugin's own actions and filters (A-134).
 *
 * usage: php bin/list-hooks.php            Markdown tables for docs/EXTENSION-POINTS.md
 *        php bin/list-hooks.php --names    one hook name per line
 *
 * Reads src/ (without the bundled client library, whose hooks carry the add-on's prefix)
 * and takes the summary from the docblock right above each call where there is one.
 *
 * @package WPLC
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Collect hooks: name => array{type:string,file:string,line:int,summary:string}.
 *
 * @param string $src Source directory.
 * @return array<string,array{type:string,file:string,line:int,summary:string}>
 */
function wplc_list_hooks( string $src ): array {
	$hooks    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
	$files    = array();
	foreach ( $iterator as $file ) {
		$path = str_replace( '\\', '/', (string) $file );
		if ( '.php' === substr( $path, -4 ) && false === strpos( $path, '/Client/' ) ) {
			$files[] = $path;
		}
	}
	sort( $files );
	foreach ( $files as $path ) {
		$lines = file( $path );
		foreach ( $lines as $index => $line ) {
			if ( ! preg_match_all( "/\\b(apply_filters|do_action)\\(\\s*'((?:wplc[_\\/])[^']+)'/", $line, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$name = $match[2];
				if ( isset( $hooks[ $name ] ) && '' !== $hooks[ $name ]['summary'] ) {
					continue;
				}
				$hooks[ $name ] = array(
					'type'    => 'apply_filters' === $match[1] ? 'filter' : 'action',
					'file'    => substr( $path, strlen( dirname( $src ) ) + 1 ),
					'line'    => $index + 1,
					'summary' => wplc_hook_summary( $lines, $index ),
				);
			}
		}
	}
	ksort( $hooks );

	return $hooks;
}

/**
 * First sentence of the docblock that ends at most 12 lines above the call.
 *
 * @param string[] $lines File lines.
 * @param int      $index Line of the call.
 * @return string
 */
function wplc_hook_summary( array $lines, int $index ): string {
	for ( $i = $index - 1; $i >= max( 0, $index - 12 ); $i-- ) {
		if ( false !== strpos( $lines[ $i ], '*/' ) ) {
			for ( $j = $i; $j >= max( 0, $i - 40 ); $j-- ) {
				if ( false !== strpos( $lines[ $j ], '/**' ) ) {
					if ( false !== strpos( $lines[ $j ], '*/' ) ) {
						// One-line docblock, usually "This filter is documented in …": let the documented call win.
						return '';
					}
					$text = trim( (string) preg_replace( '/^\s*\*\s?/', '', $lines[ $j + 1 ] ?? '' ) );
					return 0 === strpos( $text, '@' ) || 0 === strpos( $text, '*/' ) ? '' : $text;
				}
			}
			return '';
		}
		if ( preg_match( '/;\s*$|\{\s*$/', $lines[ $i ] ) && 0 !== strpos( trim( $lines[ $i ] ), '*' ) ) {
			return '';
		}
	}

	return '';
}

$hooks = wplc_list_hooks( dirname( __DIR__ ) . '/src' );
if ( in_array( '--names', $argv, true ) ) {
	echo implode( "\n", array_keys( $hooks ) ), "\n";
	exit( 0 );
}
foreach ( array( 'action' => 'Actions', 'filter' => 'Filters' ) as $type => $title ) {
	echo "## $title\n\n| Hook | Where | Summary |\n| --- | --- | --- |\n";
	foreach ( $hooks as $name => $hook ) {
		if ( $type === $hook['type'] ) {
			echo '| `' . $name . '` | `' . $hook['file'] . '` | ' . str_replace( '|', '\\|', $hook['summary'] ) . " |\n";
		}
	}
	echo "\n";
}
