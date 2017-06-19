<?php

namespace WP_CLI;

/**
 * Run a system process, and learn what happened.
 */
class Process {

	/**
	 * @param string $command Command to execute.
	 * @param string $cwd Directory to execute the command in.
	 * @param array $env Environment variables to set when running the command.
	 */
	public static function create( $command, $cwd = null, $env = array() ) {
		$proc = new self;

		$proc->command = $command;
		$proc->cwd = $cwd;
		$proc->env = $env;

		return $proc;
	}

	private $command, $cwd, $env;
	private static $descriptors = array(
		0 => STDIN,
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	static $run_times = array();

	private function __construct() {}

	/**
	 * Run the command.
	 *
	 * @return ProcessRun
	 */
	public function run() {
		$start_time = microtime( true );

		$proc = proc_open( $this->command, self::$descriptors, $pipes, $this->cwd, $this->env );

		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$return_code = proc_close( $proc );

		$run_time = microtime( true ) - $start_time;

		if ( getenv( 'WP_CLI_TEST_PROCESS_RUN_TIMES' ) ) {
			if ( ! isset( self::$run_times[ $this->command ] ) ) {
				self::$run_times[ $this->command ] = 0;
			}
			self::$run_times[ $this->command ] += $run_time;
		}

		return new ProcessRun( array(
			'stdout' => $stdout,
			'stderr' => $stderr,
			'return_code' => $return_code,
			'command' => $this->command,
			'cwd' => $this->cwd,
			'env' => $this->env,
			'run_time' => $run_time,
		) );
	}

	/**
	 * Run the command, but throw an Exception on error.
	 *
	 * @return ProcessRun
	 */
	public function run_check() {
		$r = $this->run();

		if ( $r->return_code || !empty( $r->STDERR ) ) {
			throw new \RuntimeException( $r );
		}

		return $r;
	}
}
