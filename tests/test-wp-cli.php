<?php

class WP_CLI_Test extends PHPUnit_Framework_TestCase {

	public function testLaunchProcDisabled() {
		$err_msg = 'Error: Cannot do \'launch\': The PHP functions `proc_open()` and/or `proc_close()` are disabled';

		$cmd = "WP_CLI_PHP_ARGS='-ddisable_functions=proc_open' bin/wp eval 'WP_CLI::launch( null );' --skip-wordpress 2>&1";
		$output = array();
		exec( $cmd, $output );
		$this->assertTrue( 1 === count( $output ) );
		$this->assertTrue( false !== strpos( trim( $output[0] ), $err_msg ) );

		$cmd = "WP_CLI_PHP_ARGS='-ddisable_functions=proc_close' bin/wp eval 'WP_CLI::launch( null );' --skip-wordpress 2>&1";
		$output = array();
		exec( $cmd, $output );
		$this->assertTrue( 1 === count( $output ) );
		$this->assertTrue( false !== strpos( trim( $output[0] ), $err_msg ) );
	}

	public function testRuncommandLaunchProcDisabled() {
		$err_msg = 'Error: Cannot do \'launch option\': The PHP functions `proc_open()` and/or `proc_close()` are disabled';

		$cmd = "WP_CLI_PHP_ARGS='-ddisable_functions=proc_open' bin/wp eval 'WP_CLI::runcommand( null, array( \"launch\" => 1 ) );' --skip-wordpress 2>&1";
		$output = array();
		exec( $cmd, $output );
		$this->assertTrue( 1 === count( $output ) );
		$this->assertTrue( false !== strpos( trim( $output[0] ), $err_msg ) );

		$cmd = "WP_CLI_PHP_ARGS='-ddisable_functions=proc_close' bin/wp eval 'WP_CLI::runcommand( null, array( \"launch\" => 1 ) );' --skip-wordpress 2>&1";
		$output = array();
		exec( $cmd, $output );
		$this->assertTrue( 1 === count( $output ) );
		$this->assertTrue( false !== strpos( trim( $output[0] ), $err_msg ) );
	}

	public function testGetPhpBinary() {
		$env_php_used = getenv( 'WP_CLI_PHP_USED' );
		$env_php = getenv( 'WP_CLI_PHP' );

		putenv( 'WP_CLI_PHP_USED' );
		putenv( 'WP_CLI_PHP' );

		$php_binary = WP_CLI::get_php_binary();

		$output = array();
		$return_var = -1;
		exec( $php_binary . ' --version', $output, $return_var );
		$this->assertSame( 0, $return_var );
		$this->assertTrue( ! empty( $output ) );
		$this->assertTrue( false !== stripos( $output[0], 'php' ) );

		putenv( false === $env_php_used ? 'WP_CLI_PHP_USED' : "WP_CLI_PHP_USED=$env_php_used" );
		putenv( false === $env_php ? 'WP_CLI_PHP' : "WP_CLI_PHP=$env_php" );
	}
}
