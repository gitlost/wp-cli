<?php

namespace WP_CLI\Dispatcher;

/**
 * Get the path to a command, e.g. "core download"
 *
 * @param CompositeCommand $command
 * @return array
 */
function get_path( $command ) {
	$path = array();

	do {
		array_unshift( $path, $command->get_name() );
	} while ( $command = $command->get_parent() );

	return $path;
}

