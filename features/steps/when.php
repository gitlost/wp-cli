<?php

use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode,
    WP_CLI\Process;

function invoke_proc( $proc, $mode ) {
	$map = array(
		'run' => array( 0, true, null ),
		'try' => array( null, null, null ),
		'mistakenly try' => array( 1, false, true ),
		'successfully try' => array( 0, false, false ),
	);
	$args = $map[ $mode ];

	return $proc->run_check_args( $args[0] /*return_code*/, $args[1] /*stderr_empty*/, $args[2] /*stdout_empty*/ );
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

$steps->When( '/^I (run|try|mistakenly try|successfully try) `([^`]+)`$/',
	function ( $world, $mode, $cmd, $return_code = 0, $stdout_empty = '' ) {
		$cmd = $world->replace_variables( $cmd );
		if ( Utils\is_windows() ) {
			$cmd = _wp_cli_esc_cmd_win( $cmd );
		}
		$world->result = invoke_proc( $world->proc( $cmd ), $mode );
		list( $world->result->stdout, $world->email_sends ) = capture_email_sends( $world->result->stdout );
	}
);

$steps->When( "/^I (run|try) `([^`]+)` from '([^\s]+)'$/",
	function ( $world, $mode, $cmd, $subdir ) {
		$cmd = $world->replace_variables( $cmd );
		if ( Utils\is_windows() ) {
			$cmd = _wp_cli_esc_cmd_win( $cmd );
		}
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

