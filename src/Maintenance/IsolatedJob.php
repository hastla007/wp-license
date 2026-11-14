<?php
/**
 * Wrapper around one hooked job callback.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the wrapped callback unless billing is paused, and optionally contains its errors.
 *
 * Created by {@see DailyRunner}; not meant to be instantiated elsewhere.
 */
final class IsolatedJob {

	/**
	 * Original callback.
	 *
	 * @var callable
	 */
	private $callback;

	/**
	 * Job name for logs and heartbeats.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Whether a \Throwable is logged and swallowed so the following jobs still run.
	 *
	 * @var bool
	 */
	private $contain_errors;

	/**
	 * Constructor.
	 *
	 * @param callable $callback       Original callback.
	 * @param string   $name           Job name.
	 * @param bool     $contain_errors Log and continue instead of rethrowing.
	 */
	public function __construct( callable $callback, string $name, bool $contain_errors ) {
		$this->callback       = $callback;
		$this->name           = $name;
		$this->contain_errors = $contain_errors;
	}

	/**
	 * Original callback (used to restore or inspect the hook).
	 *
	 * @return callable
	 */
	public function callback(): callable {
		return $this->callback;
	}

	/**
	 * Job name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Run the job.
	 *
	 * @param mixed ...$args Hook arguments.
	 * @return void
	 */
	public function __invoke( ...$args ): void {
		if ( ! DailyRunner::may_run( $this->name, $this->callback ) ) {
			return;
		}

		if ( ! $this->contain_errors ) {
			call_user_func_array( $this->callback, $args );
			return;
		}

		DailyRunner::run_job( $this->name, $this->callback, $args );
	}
}
