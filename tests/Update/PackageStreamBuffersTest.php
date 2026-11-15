<?php
/**
 * B-104: removing output buffers before a download ends even when one cannot be removed.
 *
 * The non-removable buffer runs in a child PHP process: PHPUnit itself loops
 * forever on such a buffer when it cleans up after a test.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class PackageStreamBuffersTest extends TestCase {

	/** @var string */
	private $script = '';

	protected function tearDown(): void {
		if ( '' !== $this->script && is_file( $this->script ) ) {
			unlink( $this->script );
		}
	}

	/**
	 * Run a script in a child process with a time limit and return its output.
	 *
	 * @param string $body PHP code after the bootstrap.
	 * @return array{0:int,1:string} Exit code and standard output.
	 */
	private function run_child( string $body ): array {
		$this->script = (string) tempnam( sys_get_temp_dir(), 'wplc-b104' );
		$source       = dirname( __DIR__, 2 ) . '/src/Update/PackageStream.php';
		file_put_contents(
			$this->script,
			"<?php\ndefine( 'ABSPATH', '/' );\nrequire " . var_export( $source, true ) . ";\nset_time_limit( 10 );\n" . $body
		);
		$command = escapeshellarg( PHP_BINARY ) . ' -d max_execution_time=10 ' . escapeshellarg( $this->script ) . ' 2>/dev/null';
		$output  = array();
		exec( $command, $output, $code );

		return array( $code, implode( "\n", $output ) );
	}

	public function test_b104_a_non_removable_buffer_stops_the_loop(): void {
		$started = microtime( true );
		list( $code, $output ) = $this->run_child(
			"ob_start( null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE );\n"
			. "ob_start();\n"
			. "echo 'buffered';\n"
			. "\$removed = \\WPLC\\Update\\PackageStream::drop_output_buffers();\n"
			. "fwrite( STDOUT, 'removed=' . \$removed . ' level=' . ob_get_level() . \"\\n\" );\n"
		);

		$this->assertSame( 0, $code, 'The child process did not finish: ' . $output );
		$this->assertStringContainsString( 'removed=1 level=1', $output, 'The removable inner buffer goes; the outer one stays.' );
		$this->assertLessThan( 8, microtime( true ) - $started );
	}

	public function test_b104_removable_buffers_are_all_removed(): void {
		list( $code, $output ) = $this->run_child(
			"ob_start();\nob_start();\nob_start();\necho 'x';\n"
			. "\$removed = \\WPLC\\Update\\PackageStream::drop_output_buffers();\n"
			. "fwrite( STDOUT, 'removed=' . \$removed . ' level=' . ob_get_level() . \"\\n\" );\n"
		);

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'removed=3 level=0', $output );
	}
}
