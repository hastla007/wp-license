<?php
/**
 * Lists the plugin's own actions and filters (A-134).
 *
 * usage: php bin/list-hooks.php            Markdown tables for docs/EXTENSION-POINTS.md
 *        php bin/list-hooks.php --names    one hook name per line
 *
 * Reads src/ (without the bundled client library, whose hooks carry the add-on's prefix) and
 * templates/ token by token, so a call whose hook name is on the next line is found as well,
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
 * @param string[] $dirs Directories to read (src/ and templates/).
 * @return array<string,array{type:string,file:string,line:int,summary:string}>
 */
function wplc_list_hooks( array $dirs ): array {
	$hooks = array();
	$files = array();
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( '.php' === substr( $path, -4 ) && false === strpos( $path, '/src/Client/' ) ) {
				$files[] = $path;
			}
		}
	}
	sort( $files );
	$root = str_replace( '\\', '/', dirname( __DIR__ ) );
	foreach ( $files as $path ) {
		$code  = (string) file_get_contents( $path );
		$lines = preg_split( '/(?<=\n)/', $code );
		foreach ( wplc_hook_calls( $code ) as $call ) {
			$name = $call['name'];
			if ( isset( $hooks[ $name ] ) && '' !== $hooks[ $name ]['summary'] ) {
				continue;
			}
			$hooks[ $name ] = array(
				'type'    => $call['type'],
				'file'    => 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : $path,
				'line'    => $call['line'],
				'summary' => wplc_hook_summary( $lines, $call['line'] - 1 ),
			);
		}
	}
	// Public actions that WP-Cron fires under a class constant (no do_action() call in the code).
	foreach ( array( 'wplc_daily_lifecycle' => 'src/Maintenance/DailyRunner.php' ) as $name => $file ) {
		if ( ! isset( $hooks[ $name ] ) && is_file( $root . '/' . $file ) && false !== strpos( (string) file_get_contents( $root . '/' . $file ), "'" . $name . "'" ) ) {
			$hooks[ $name ] = array(
				'type'    => 'action',
				'file'    => $file,
				'line'    => 0,
				'summary' => '',
			);
		}
	}
	ksort( $hooks );

	return $hooks;
}

/**
 * Calls of apply_filters() / do_action() (also the _ref_array and _deprecated forms) with a
 * hook name of the plugin, over the whole file: the name may stand on a later line than the call.
 *
 * @param string $code PHP source.
 * @return array<int,array{type:string,name:string,line:int}> Line of the function name.
 */
function wplc_hook_calls( string $code ): array {
	$functions = array(
		'apply_filters'            => 'filter',
		'apply_filters_ref_array'  => 'filter',
		'apply_filters_deprecated' => 'filter',
		'do_action'                => 'action',
		'do_action_ref_array'      => 'action',
		'do_action_deprecated'     => 'action',
	);
	$skip      = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
	$tokens    = token_get_all( $code );
	$count     = count( $tokens );
	$calls     = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ strtolower( $token[1] ) ] ) ) {
			continue;
		}
		// Not a method or a function declaration of the same name.
		$before = $i - 1;
		while ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], $skip, true ) ) {
			--$before;
		}
		if ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$next = $i + 1;
		while ( $next < $count && is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], $skip, true ) ) {
			++$next;
		}
		if ( $next >= $count || '(' !== $tokens[ $next ] ) {
			continue;
		}
		++$next;
		while ( $next < $count && is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], $skip, true ) ) {
			++$next;
		}
		if ( $next >= $count || ! is_array( $tokens[ $next ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $next ][0] ) {
			continue;
		}
		$name = substr( $tokens[ $next ][1], 1, -1 );
		if ( ! preg_match( '#^wplc[_/]#', $name ) ) {
			continue;
		}
		$calls[] = array(
			'type' => $functions[ strtolower( $token[1] ) ],
			'name' => $name,
			'line' => (int) $token[2],
		);
	}

	return $calls;
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

$hooks = wplc_list_hooks( array( dirname( __DIR__ ) . '/src', dirname( __DIR__ ) . '/templates' ) );
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
