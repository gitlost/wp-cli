<?php

use WP_CLI\FileCache;
use WP_CLI\Utils;
use Symfony\Component\Finder\Finder;

require_once dirname( __DIR__ ) . '/php/class-wp-cli.php';

class FileCacheTest extends PHPUnit_Framework_TestCase {

	/**
	 * Test that no new classes are loaded in clean() as this can cause problems if it's called in a register_shutdown_function.
	 */
	public function testFinderLoaded() {
		$max_size = 32;
		$ttl = 60;

		$cache_dir = Utils\get_temp_dir() . '/' . uniqid( "wp-cli-test-file-cache", TRUE );

		$cache = new FileCache( $cache_dir, $ttl, $max_size );

		$after_construct_classes = get_declared_classes();

		// Less than time to live file.
		$cache->write( 'ttl', 'ttl' );
		touch( $cache_dir . '/ttl', time() - ( $ttl + 1 ) );

		// Greater than max size file.
		$cache->write( 'max_size', str_repeat( 'm', $max_size + 1 ) );

		// Check no change in loaded classes.
		$after_write_classes = get_declared_classes();
		$after_write_diff = array_diff( $after_write_classes, $after_construct_classes );
		$this->assertEmpty( $after_write_diff );

		$cache->clean();

		// Should be no change in loaded classes.
		$after_clean_classes = get_declared_classes();
		$after_clean_diff = array_diff( $after_clean_classes, $after_write_classes );
		$this->assertEmpty( $after_clean_diff );

		$this->assertFalse( file_exists( $cache_dir . '/max_size' ) );
		$this->assertFalse( file_exists( $cache_dir . '/ttl' ) );

		rmdir( $cache_dir );
	}

	/**
	 * Test get_root() deals with backslashed directory.
	 */
	public function testGetRoot() {
		$max_size = 32;
		$ttl = 60;

		$cache_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-test-file-cache', true );

		$cache = new FileCache( $cache_dir, $ttl, $max_size );
		$this->assertSame( $cache_dir . '/', $cache->get_root() );
		unset( $cache );

		$cache = new FileCache( $cache_dir . '/', $ttl, $max_size );
		$this->assertSame( $cache_dir . '/', $cache->get_root() );
		unset( $cache );

		$cache = new FileCache( $cache_dir . '\\', $ttl, $max_size );
		$this->assertSame( $cache_dir . '/', $cache->get_root() );
		unset( $cache );

		rmdir( $cache_dir );
	}

	public function test_ensure_dir_exists() {
		$class_wp_cli_logger = new ReflectionProperty( 'WP_CLI', 'logger' );
		$class_wp_cli_logger->setAccessible( true );
		$prev_logger = $class_wp_cli_logger->getValue();

		$logger = new WP_CLI\Loggers\Execution;
		WP_CLI::set_logger( $logger );

		$max_size = 32;
		$ttl = 60;
		$cache_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-test-file-cache', true );

		$cache = new FileCache( $cache_dir, $ttl, $max_size );
		$test_class = new ReflectionClass( $cache );
		$method = $test_class->getMethod( 'ensure_dir_exists' );
		$method->setAccessible( true );

		// Cache directory should be created.
		$result = $method->invokeArgs( $cache, array( $cache_dir . '/test1' ) );
		$this->assertTrue( $result );
		$this->assertTrue( is_dir( $cache_dir . '/test1' ) );

		// Try to create the same directory again. it should return true.
		$result = $method->invokeArgs( $cache, array( $cache_dir . '/test1' ) );
		$this->assertTrue( $result );

		// It should be failed because permission denied.
		$logger->stderr = '';
		chmod( $cache_dir . '/test1', 0000 );
		$result = $method->invokeArgs( $cache, array( $cache_dir . '/test1/error' ) );
		$expected = "/^Warning: Failed to create directory '.+': mkdir\(\): Permission denied\.$/";
		$this->assertRegexp( $expected, $logger->stderr );

		// It should be failed because file exists.
		$logger->stderr = '';
		file_put_contents( $cache_dir . '/test2', '' );
		$result = $method->invokeArgs( $cache, array( $cache_dir . '/test2' ) );
		$expected = "/^Warning: Failed to create directory '.+': mkdir\(\): File exists\.$/";
		$this->assertRegexp( $expected, $logger->stderr );

		// Restore
		chmod( $cache_dir . '/test1', 0755 );
		rmdir( $cache_dir . '/test1' );
		unlink( $cache_dir . '/test2' );
		rmdir( $cache_dir );
		$class_wp_cli_logger->setValue( $prev_logger );
	}
}
