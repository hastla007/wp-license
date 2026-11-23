<?php
/**
 * Security audit of 14 Nov 2026 (SA-12): the GitHub workflows name every action by a commit
 * hash, and only the job that publishes a release may write to the repository.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class WorkflowHardeningTest extends TestCase {

	/**
	 * Workflow files.
	 *
	 * @return array<string,string> File name => content.
	 */
	private static function workflows(): array {
		$files = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/.github/workflows/*.yml' ) as $file ) {
			$files[ basename( (string) $file ) ] = (string) file_get_contents( (string) $file );
		}

		return $files;
	}

	public function test_every_action_is_pinned_to_a_commit_with_its_version_as_comment(): void {
		$count = 0;
		foreach ( self::workflows() as $name => $content ) {
			preg_match_all( '/^\s*(?:-\s*)?uses:\s*(.+)$/m', $content, $matches );
			foreach ( $matches[1] as $uses ) {
				++$count;
				$this->assertMatchesRegularExpression(
					'~^[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40} # v?[0-9]+\.[0-9]+\.[0-9]+$~',
					trim( $uses ),
					$name . ': a tag such as @v4 can be moved to other code; name the commit and keep the version as a comment.'
				);
			}
		}
		$this->assertGreaterThan( 15, $count, 'The scan found the actions.' );
	}

	public function test_only_the_publishing_job_may_write_and_it_runs_no_code_of_the_repository(): void {
		foreach ( self::workflows() as $name => $content ) {
			if ( 'release.yml' === $name ) {
				continue;
			}
			$this->assertStringNotContainsString( ': write', $content, $name . ' needs read access only.' );
		}

		$release = self::workflows()['release.yml'];
		$this->assertSame( 1, substr_count( $release, ': write' ) );
		$this->assertMatchesRegularExpression( '/^permissions:\n  contents: read$/m', $release, 'Default of the workflow: read.' );
		$this->assertSame( 1, preg_match( '/^  publish:\n(.*)\z/ms', $release, $match ), 'The release has a job of its own for publishing.' );
		$publish = $match[1];
		$this->assertStringContainsString( 'contents: write', $publish );
		$this->assertStringContainsString( 'needs: build', $publish );
		$this->assertStringNotContainsString( 'actions/checkout', $publish, 'The job with write access does not check the repository out.' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*(?:-\s*)?run:/m', $publish, 'The job with write access runs no script.' );
		$this->assertStringContainsString( 'actions/download-artifact@', $publish );

		// Tests and the check "the tag is on main" run before anything is built.
		$this->assertMatchesRegularExpression( '/^  build:\n(?:.*\n)*?    needs: test$/m', $release );
		$this->assertStringContainsString( 'git merge-base --is-ancestor "${GITHUB_SHA}" refs/remotes/origin/main', $release );
	}

	public function test_the_package_build_runs_no_composer_plugin_or_script(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/bin/build-zip.sh' );
		preg_match_all( '/^\s*composer (?:install|dump-autoload|update) .*$/m', $script, $matches );
		$this->assertNotEmpty( $matches[0] );
		foreach ( $matches[0] as $line ) {
			$this->assertStringContainsString( '--no-plugins --no-scripts', $line );
		}
	}
}
