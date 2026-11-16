#!/usr/bin/env php
<?php
/**
 * Checks facts that are written down in more than one place and went stale before.
 *
 * Usage:
 *   php bin/check-consistency.php            # exit 1 and one line per problem when something differs
 *   php bin/check-consistency.php --root=DIR # check another copy of the repository (used by the unit test)
 *
 * What it compares:
 * - docs/TODO.md: the numbers of open and done tasks per severity in the overview at the top, the lists of
 *   open task numbers and the totals, against the task lines of the file (`- [ ]` / `- [x]` at the start
 *   of a line; indented check boxes are notes, not tasks);
 * - docs/TODO.md and docs/QUALITY-GATES.md: the number of CI jobs, unit jobs and integration cells, against
 *   the workflows under .github/workflows that run on pull requests;
 * - languages/: the template carries the plugin version, and every .po file has exactly the texts of the
 *   template, all translated, none marked fuzzy or obsolete, with a compiled .mo next to it.
 *
 * The script needs PHP only. tests/Infrastructure/ConsistencyCheckTest.php runs it in CI.
 *
 * @package WPLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions -- command line script without WordPress.

if ( ! function_exists( 'wplc_consistency_tasks' ) ) {

	/**
	 * Content of a file, empty when it does not exist.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	function wplc_consistency_read( string $file ): string {
		return is_file( $file ) ? (string) file_get_contents( $file ) : '';
	}

	/**
	 * Task lines of the to-do list.
	 *
	 * @param string $todo Content of docs/TODO.md.
	 * @return array<int,array{id:string,open:bool,severity:string}> Severity is KRITISCH, WICHTIG, OPTIMIERUNG or '' (none).
	 */
	function wplc_consistency_tasks( string $todo ): array {
		$tasks = array();
		foreach ( explode( "\n", $todo ) as $line ) {
			if ( 1 !== preg_match( '/^- \[( |x)\] (.*)$/', $line, $match ) ) {
				continue;
			}
			$id = 1 === preg_match( '/^\*\*([A-Z]+(?:-[A-Z]+)?-\d+)\b/', $match[2], $found ) ? $found[1] : '';
			// The first tag counts: a note may quote the tag of another task.
			$severity = 1 === preg_match( '/\[(KRITISCH|WICHTIG|OPTIMIERUNG)\]/', $match[2], $found ) ? $found[1] : '';
			$tasks[]  = array(
				'id'       => $id,
				'open'     => ' ' === $match[1],
				'severity' => $severity,
			);
		}

		return $tasks;
	}

	/**
	 * Expand a list like "T-12, FR-LIC-01 bis -05" into task numbers.
	 *
	 * @param string $text List from the overview.
	 * @return string[]
	 */
	function wplc_consistency_expand_ids( string $text ): array {
		$ids = array();
		foreach ( explode( ',', $text ) as $part ) {
			$part = trim( $part, " .\t" );
			if ( 1 === preg_match( '/^([A-Z]+(?:-[A-Z]+)?)-(\d+) bis -(\d+)$/', $part, $match ) ) {
				for ( $number = (int) $match[2]; $number <= (int) $match[3]; $number++ ) {
					$ids[] = $match[1] . '-' . str_pad( (string) $number, strlen( $match[2] ), '0', STR_PAD_LEFT );
				}
			} elseif ( '' !== $part ) {
				$ids[] = $part;
			}
		}

		return $ids;
	}

	/**
	 * Compare the overview at the top of docs/TODO.md with its task lines.
	 *
	 * @param string $todo Content of docs/TODO.md.
	 * @return string[] Problems.
	 */
	function wplc_consistency_check_todo( string $todo ): array {
		$problems = array();
		$counts   = array();
		$open_ids = array();
		foreach ( wplc_consistency_tasks( $todo ) as $task ) {
			$state = $task['open'] ? 'open' : 'done';
			$counts[ $task['severity'] ][ $state ] = ( $counts[ $task['severity'] ][ $state ] ?? 0 ) + 1;
			if ( $task['open'] ) {
				$open_ids[ $task['severity'] ][] = $task['id'];
			}
		}

		$labels     = array(
			'Kritisch'    => 'KRITISCH',
			'Wichtig'     => 'WICHTIG',
			'Optimierung' => 'OPTIMIERUNG',
		);
		$total_open = 0;
		$total_done = 0;
		foreach ( $labels as $label => $severity ) {
			$open        = (int) ( $counts[ $severity ]['open'] ?? 0 );
			$done        = (int) ( $counts[ $severity ]['done'] ?? 0 );
			$total_open += $open;
			$total_done += $done;
			if ( 1 !== preg_match( '/^- ' . $label . ': (\d+) offen, (\d+) erledigt\.(?: Offen: (.*))?$/m', $todo, $match ) ) {
				$problems[] = "docs/TODO.md: the overview has no line \"- {$label}: N offen, M erledigt. Offen: …\".";
				continue;
			}
			if ( (int) $match[1] !== $open || (int) $match[2] !== $done ) {
				$problems[] = "docs/TODO.md: overview says {$label} {$match[1]} offen, {$match[2]} erledigt; the file has {$open} open and {$done} done.";
			}
			$listed = wplc_consistency_expand_ids( (string) ( $match[3] ?? '' ) );
			$real   = $open_ids[ $severity ] ?? array();
			$extra  = array_diff( $listed, $real );
			$absent = array_diff( $real, $listed );
			if ( array() !== $extra || array() !== $absent || count( $listed ) !== count( array_unique( $listed ) ) ) {
				$problems[] = "docs/TODO.md: the list of open {$label} tasks differs (listed but not open: " . implode( ', ', $extra ) . '; open but not listed: ' . implode( ', ', $absent ) . ').';
			}
		}

		$unrated_open = (int) ( $counts['']['open'] ?? 0 );
		$unrated_done = (int) ( $counts['']['done'] ?? 0 );
		$total_open  += $unrated_open;
		$total_done  += $unrated_done;
		if ( 0 !== $unrated_open ) {
			$problems[] = "docs/TODO.md: {$unrated_open} open task(s) carry no severity tag.";
		}
		if ( 1 !== preg_match( '/^- Ohne Einstufung: (\d+) erledigte/m', $todo, $match ) ) {
			$problems[] = 'docs/TODO.md: the overview has no line "- Ohne Einstufung: N erledigte …".';
		} elseif ( (int) $match[1] !== $unrated_done ) {
			$problems[] = "docs/TODO.md: overview says {$match[1]} done tasks without severity; the file has {$unrated_done}.";
		}
		if ( 1 !== preg_match( '/^- Zusammen: (\d+) offen, (\d+) erledigt\.$/m', $todo, $match ) ) {
			$problems[] = 'docs/TODO.md: the overview has no line "- Zusammen: N offen, M erledigt.".';
		} elseif ( (int) $match[1] !== $total_open || (int) $match[2] !== $total_done ) {
			$problems[] = "docs/TODO.md: overview says {$match[1]} offen, {$match[2]} erledigt in total; the file has {$total_open} open and {$total_done} done.";
		}
		if ( 1 === preg_match( '/^- Von den (\d+) offenen Aufgaben/m', $todo, $match ) && (int) $match[1] !== $total_open ) {
			$problems[] = "docs/TODO.md: \"Von den {$match[1]} offenen Aufgaben\" does not match {$total_open} open tasks.";
		}

		return $problems;
	}

	/**
	 * Jobs that a pull request starts, read from the workflow files.
	 *
	 * Understands what the workflows of this repository use: jobs as keys two
	 * blanks deep under `jobs:`, a matrix as one inline list (`php: [..]`) or
	 * as an `include:` list of inline maps. Anything else is reported as a
	 * problem by wplc_consistency_check_ci() through a count that does not match.
	 *
	 * @param string $dir Directory with the workflow files.
	 * @return array<string,int> Job key => number of jobs it expands to.
	 */
	function wplc_consistency_ci_jobs( string $dir ): array {
		$jobs = array();
		foreach ( (array) glob( $dir . '/*.yml' ) as $file ) {
			$text = (string) file_get_contents( $file );
			if ( 1 !== preg_match( '/^on:\n(?:[ \t]+.*\n|\n)*?[ \t]+pull_request:/m', $text ) ) {
				continue; // Not started by a pull request (the release workflow runs on tags).
			}
			$position = strpos( $text, "\njobs:\n" );
			if ( false === $position ) {
				continue;
			}
			$current = '';
			$in      = '';
			foreach ( explode( "\n", substr( $text, $position + 7 ) ) as $line ) {
				if ( 1 === preg_match( '/^  ([A-Za-z0-9_-]+):\s*$/', $line, $match ) ) {
					$current          = $match[1];
					$jobs[ $current ] = 1;
					$in               = '';
					continue;
				}
				if ( '' === $current ) {
					continue;
				}
				if ( 1 === preg_match( '/^      matrix:\s*$/', $line ) ) {
					$in               = 'matrix';
					$jobs[ $current ] = 0;
					continue;
				}
				if ( 'matrix' === $in || 'include' === $in ) {
					if ( 1 === preg_match( '/^        include:\s*$/', $line ) ) {
						$in = 'include';
					} elseif ( 'include' === $in && 1 === preg_match( '/^          - \{/', $line ) ) {
						++$jobs[ $current ];
					} elseif ( 'matrix' === $in && 1 === preg_match( '/^        [A-Za-z0-9_-]+: \[(.*)\]\s*$/', $line, $match ) ) {
						$jobs[ $current ] = max( 1, $jobs[ $current ] ) * count( explode( ',', $match[1] ) );
					} elseif ( 1 === preg_match( '/^ {0,6}\S/', $line ) && 1 !== preg_match( '/^\s*#/', $line ) ) {
						$in = '';
					}
				}
			}
		}

		return $jobs;
	}

	/**
	 * Compare the CI numbers in the documents with the workflows.
	 *
	 * @param string $root Repository root.
	 * @return string[] Problems.
	 */
	function wplc_consistency_check_ci( string $root ): array {
		$jobs        = wplc_consistency_ci_jobs( $root . '/.github/workflows' );
		$total       = array_sum( $jobs );
		$unit        = (int) ( $jobs['unit'] ?? 0 );
		$integration = (int) ( $jobs['integration'] ?? 0 );
		$problems    = array();
		if ( 0 === $unit || 0 === $integration ) {
			return array( '.github/workflows: no "unit" or no "integration" job with a matrix found; update bin/check-consistency.php together with the workflows.' );
		}

		$expect = static function ( string $file, string $pattern, int $value, string $what ) use ( $root, &$problems ): void {
			$text = wplc_consistency_read( $root . '/' . $file );
			if ( 1 !== preg_match( $pattern, $text, $match ) ) {
				$problems[] = "{$file}: the sentence with the number of {$what} was not found (pattern {$pattern}).";
			} elseif ( (int) $match[1] !== $value ) {
				$problems[] = "{$file}: says {$match[1]} {$what}; the workflows define {$value}.";
			}
		};
		$expect( 'docs/QUALITY-GATES.md', '/starts (\d+) jobs:/', $total, 'CI jobs' );
		$expect( 'docs/QUALITY-GATES.md', '/starts \d+ jobs: (\d+) unit,/', $unit, 'unit jobs' );
		$expect( 'docs/QUALITY-GATES.md', '/starts \d+ jobs: \d+ unit, (\d+) integration cells/', $integration, 'integration cells' );
		$expect( 'docs/QUALITY-GATES.md', '/\((\d+) cells:/', $integration, 'integration cells (table)' );
		$expect( 'docs/TODO.md', '/^- \*\*In CI[^\n]*?:\*\* (\d+) Jobs:/m', $total, 'CI jobs' );
		$expect( 'docs/TODO.md', '/^- \*\*In CI[^\n]*? (\d+) × Unit/m', $unit, 'unit jobs' );
		$expect( 'docs/TODO.md', '/^- \*\*In CI[^\n]*? (\d+) Integrationszellen/m', $integration, 'integration cells' );
		$expect( 'docs/TODO.md', '/in CI (\d+) Kombinationen/', $integration, 'integration cells (rules)' );

		return $problems;
	}

	/**
	 * Entries of a gettext file.
	 *
	 * @param string $text Content of a .po or .pot file.
	 * @return array{entries:array<string,array{translated:bool,fuzzy:bool}>,obsolete:int,header:string}
	 */
	function wplc_consistency_parse_po( string $text ): array {
		$entries  = array();
		$header   = '';
		$obsolete = 0;
		foreach ( preg_split( '/\n\s*\n/', str_replace( "\r", '', $text ) ) as $block ) {
			if ( 1 === preg_match( '/^#~ /m', $block ) ) {
				++$obsolete;
				continue;
			}
			$fields = array();
			$field  = '';
			foreach ( explode( "\n", $block ) as $line ) {
				if ( 1 === preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $match ) ) {
					$field            = $match[1];
					$fields[ $field ] = $match[2];
				} elseif ( '' !== $field && 1 === preg_match( '/^"(.*)"$/', $line, $match ) ) {
					$fields[ $field ] .= $match[1];
				}
			}
			if ( ! isset( $fields['msgid'] ) ) {
				continue;
			}
			if ( '' === $fields['msgid'] && ! isset( $fields['msgctxt'] ) ) {
				$header = (string) ( $fields['msgstr'] ?? '' );
				continue;
			}
			$translated = true;
			foreach ( $fields as $name => $value ) {
				if ( 0 === strpos( $name, 'msgstr' ) && '' === $value ) {
					$translated = false;
				}
			}
			$key             = ( $fields['msgctxt'] ?? '' ) . "\x04" . $fields['msgid'] . "\x04" . ( $fields['msgid_plural'] ?? '' );
			$entries[ $key ] = array(
				'translated' => $translated,
				'fuzzy'      => 1 === preg_match( '/^#,.*\bfuzzy\b/m', $block ),
			);
		}

		return array(
			'entries'  => $entries,
			'obsolete' => $obsolete,
			'header'   => $header,
		);
	}

	/**
	 * Compare the translations with the template and the template with the plugin version.
	 *
	 * This cannot see a text that changed in the code after the last run of
	 * bin/make-pot.sh (that needs WP-CLI); it sees a template that was renewed
	 * without the translations, and a release with a template of an older version.
	 *
	 * @param string $root Repository root.
	 * @return string[] Problems.
	 */
	function wplc_consistency_check_translations( string $root ): array {
		$problems = array();
		$pot_file = $root . '/languages/wp-license.pot';
		if ( ! is_file( $pot_file ) ) {
			return array( 'languages/wp-license.pot is missing.' );
		}
		$pot     = wplc_consistency_parse_po( (string) file_get_contents( $pot_file ) );
		$version = 1 === preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', wplc_consistency_read( $root . '/wp-license.php' ), $match ) ? $match[1] : '';
		if ( '' === $version || false === strpos( $pot['header'], 'Project-Id-Version: WP License Captain ' . $version . '\n' ) ) {
			$problems[] = "languages/wp-license.pot: the header does not carry the plugin version {$version}; run bin/make-pot.sh.";
		}
		$files = (array) glob( $root . '/languages/*.po' );
		if ( array() === $files ) {
			$problems[] = 'languages/: no .po file found.';
		}
		foreach ( $files as $file ) {
			$name    = 'languages/' . basename( $file );
			$po      = wplc_consistency_parse_po( (string) file_get_contents( $file ) );
			$missing = count( array_diff_key( $pot['entries'], $po['entries'] ) );
			$extra   = count( array_diff_key( $po['entries'], $pot['entries'] ) );
			$empty   = count(
				array_filter(
					$po['entries'],
					static function ( array $entry ): bool {
						return ! $entry['translated'];
					}
				)
			);
			$fuzzy   = count(
				array_filter(
					$po['entries'],
					static function ( array $entry ): bool {
						return $entry['fuzzy'];
					}
				)
			);
			if ( 0 !== $missing || 0 !== $extra ) {
				$problems[] = "{$name}: {$missing} text(s) of the template are missing and {$extra} are not in the template; run bin/make-pot.sh.";
			}
			if ( 0 !== $empty || 0 !== $fuzzy || 0 !== $po['obsolete'] ) {
				$problems[] = "{$name}: {$empty} untranslated, {$fuzzy} fuzzy and {$po['obsolete']} obsolete entries.";
			}
			if ( false === strpos( $po['header'], 'Project-Id-Version: WP License Captain ' . $version . '\n' ) ) {
				$problems[] = "{$name}: the header does not carry the plugin version {$version}; run bin/make-pot.sh.";
			}
			if ( ! is_file( substr( $file, 0, -3 ) . '.mo' ) ) {
				$problems[] = "{$name}: no compiled .mo file next to it.";
			}
		}

		return $problems;
	}

	/**
	 * Run every check.
	 *
	 * @param string $root Repository root.
	 * @return string[] Problems.
	 */
	function wplc_consistency_check( string $root ): array {
		$todo = wplc_consistency_read( $root . '/docs/TODO.md' );
		if ( '' === $todo ) {
			return array( 'docs/TODO.md is missing or empty.' );
		}

		return array_merge(
			wplc_consistency_check_todo( $todo ),
			wplc_consistency_check_ci( $root ),
			wplc_consistency_check_translations( $root )
		);
	}
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$wplc_options  = getopt( '', array( 'root::' ) );
	$wplc_root     = isset( $wplc_options['root'] ) && is_string( $wplc_options['root'] ) ? rtrim( $wplc_options['root'], '/' ) : dirname( __DIR__ );
	$wplc_problems = wplc_consistency_check( $wplc_root );
	foreach ( $wplc_problems as $wplc_problem ) {
		fwrite( STDERR, $wplc_problem . "\n" );
	}
	if ( array() === $wplc_problems ) {
		echo "Consistent: TODO overview, CI numbers and translations.\n";
	}
	exit( array() === $wplc_problems ? 0 : 1 );
}
