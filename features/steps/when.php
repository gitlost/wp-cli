<?php

use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode,
    WP_CLI\Process;

function invoke_proc( $proc, $mode ) {
	if ( 'run' === $mode ) {
		return $proc->run_check_stderr();
	}
	if ( 'try' === $mode ) {
		return $proc->run();
	}
	if ( 'mistakenly try' === $mode ) {
		$return_code = 1;
		$stderr_empty = false;
		$stdout_empty = true;
	} else {
		$return_code = 0;
		$stderr_empty = false;
		$stdout_empty = null;
	}
	return $proc->run_check_full( $return_code, $stderr_empty, $stdout_empty );
}

function capture_email_sends( $stdout ) {
	$stdout = preg_replace( '#WP-CLI test suite: Sent email to.+\n?#', '', $stdout, -1, $email_sends );
	return array( $stdout, $email_sends );
}

$steps->When( '/^I launch in the background `([^`]+)`$/',
	function ( $world, $cmd ) {
		$world->background_proc( $cmd );
	}
);

$steps->When( '/^I (run|try|mistakenly try|dubiously try) `([^`]+)`$/',
	function ( $world, $mode, $cmd, $return_code = 0, $stdout_empty = '' ) {
		$cmd = $world->replace_variables( $cmd );
		$world->result = invoke_proc( $world->proc( $cmd ), $mode );
		list( $world->result->stdout, $world->email_sends ) = capture_email_sends( $world->result->stdout );
	}
);

$steps->When( "/^I (run|try) `([^`]+)` from '([^\s]+)'$/",
	function ( $world, $mode, $cmd, $subdir ) {
		$cmd = $world->replace_variables( $cmd );
		$world->result = invoke_proc( $world->proc( $cmd, array(), $subdir ), $mode );
		list( $world->result->stdout, $world->email_sends ) = capture_email_sends( $world->result->stdout );
	}
);

$steps->When( '/^I (run|try) the previous command again$/',
	function ( $world, $mode ) {
		if ( !isset( $world->result ) )
			throw new \Exception( 'No previous command.' );

		$proc = Process::create( $world->result->command, $world->result->cwd, $world->result->env );
		$world->result = invoke_proc( $proc, $mode );
		list( $world->result->stdout, $world->email_sends ) = capture_email_sends( $world->result->stdout );
	}
);

