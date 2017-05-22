<?php

// Debug helpers when running in behat.

if ( ! function_exists( '_error_log' ) ) {
	// Put error messages to PHP's 'error_log' when running shelled out with captured STDERR.
	function _error_log( $msg ) {
		error_log( sprintf( "[%s] %s\n", date( 'r' ), $msg ), 3, ini_get( 'error_log' ) );
	}
}

if ( ! function_exists( '_var_dump' ) ) {
	// Wrapper around PHP's var_dump to return a string rather than interfere with captured STDOUT.
	// Fixes up __FILE__:__LINE__: prefix for PHP >= 7.
	function _var_dump( $var ) {
		$ret = '';
		if ( $php_ver_7 = version_compare( PHP_VERSION, '7', '>=' ) ) {
			$bt = debug_backtrace( 0, 1 );
			$file_line = isset( $bt[0] ) ? "{$bt[0]['file']}:{$bt[0]['line']}:\n" : '';
		}
		for ( $i = 0, $cnt = func_num_args(); $i < $cnt; $i++ ) {
			ob_start();
			var_dump( func_get_arg( $i ) );
			$v = ob_get_clean();
			$ret .= $php_ver_7 ? preg_replace( '/^.*?:\n/', $file_line, $v ) : $v;
		}
		return $ret;
	}
}
