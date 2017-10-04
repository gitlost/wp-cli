<?php

use WP_CLI\Extractor;
use WP_CLI\Utils;

class Extractor_Test extends PHPUnit_Framework_TestCase {

	static $copy_overwrite_files_prefix = 'wp-cli-test-utils-copy-overwrite-files-';

	static $expected_top = array(
		'wordpress/index1.php',
		'wordpress/license2.php',
		'wordpress/wp-admin/about3.php',
		'wordpress/wp-admin/includes/file4.php',
		'wordpress/wp-admin/widgets5.php',
		'wordpress/wp-config6.php',
		'wordpress/wp-includes/file7.php',
		'wordpress/xmlrpc8.php',
	);

	static $expected_wp = array(
		'index1.php',
		'license2.php',
		'wp-admin/about3.php',
		'wp-admin/includes/file4.php',
		'wp-admin/widgets5.php',
		'wp-config6.php',
		'wp-includes/file7.php',
		'xmlrpc8.php',
	);

	static $logger = null;

	public function setUp() {
		parent::setUp();

		if ( null === self::$logger ) {
			self::$logger = new \WP_CLI\Loggers\Quiet;
		}
		WP_CLI::set_logger( self::$logger );

		// Remove any failed tests detritus.
		$temp_dirs = Utils\get_temp_dir() . self::$copy_overwrite_files_prefix . '*';
		foreach ( glob( $temp_dirs ) as $temp_dir ) {
			Extractor::rmdir( $temp_dir );
		}
	}

	public function testRmdir() {
		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();
		$this->assertTrue( is_dir( $temp_dir ) );
		Extractor::rmdir( $temp_dir );
		$this->assertFalse( file_exists( $temp_dir ) );
	}

	public function testCopyOverwriteFiles() {
		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();

		$dest_dir = $temp_dir . '/dest';

		// Top level src dir, no strip_components.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );

		$this->assertSame( self::$expected_top, $files );
		Extractor::rmdir( $dest_dir );

		// Wordpress dir, no strip_components.

		Extractor::copy_overwrite_files( $wp_dir, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );

		$this->assertSame( self::$expected_wp, $files );
		Extractor::rmdir( $dest_dir );

		// Top level src dir, strip_components 1.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir, 1 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$this->assertSame( self::$expected_wp, $files );
		Extractor::rmdir( $dest_dir );

		// Wordpress dir, strip_components 1.

		Extractor::copy_overwrite_files( $wp_dir, $dest_dir, 1 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'about3.php',
			'file7.php',
			'includes/file4.php',
			'widgets5.php',
		);
		$this->assertSame( $expected, $files );

		// Top level src dir, strip_components 2 (same as Wordpress dir, strip_components 1).

		Extractor::copy_overwrite_files( $src_dir, $dest_dir, 2 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'about3.php',
			'file7.php',
			'includes/file4.php',
			'widgets5.php',
		);
		$this->assertSame( $expected, $files );
		Extractor::rmdir( $dest_dir );

		// Top level src dir, strip_components 3.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir, 3 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'file4.php',
		);
		$this->assertSame( $expected, $files );
		Extractor::rmdir( $dest_dir );

		// Clean up.
		Extractor::rmdir( $temp_dir );
	}

	public function testExtractTarball() {
		if ( ! exec( 'tar --version' ) ) {
			$this->markTestSkipped( 'tar not installed.' );
		}

		$extractor_shell = getenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' );

		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();

		$tarball = $temp_dir . '/test.tar.gz';
		$dest_dir = $temp_dir . '/dest';

		// Create test tarball.

		$output = array(); $return_var = -1;
		exec( Utils\esc_cmd( 'tar czvf %1$s --directory=%2$s/src wordpress', $tarball, $temp_dir ), $output, $return_var );
		$this->assertSame( 0, $return_var );
		$this->assertFalse( empty( $output ) );

		// Remove directory listings.
		$output = array_filter( $output, function ( $v ) {
			return '/' !== substr( $v, -1 );
		} );
		sort( $output );
		$this->assertSame( self::$expected_top, $output );

		putenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' );
		Extractor::extract( $tarball, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		Extractor::rmdir( $dest_dir );

		putenv( 'WP_CLI_TEST_EXTRACTOR_SHELL=1' );
		Extractor::extract( $tarball, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		Extractor::rmdir( $dest_dir );

		// Clean up.
		Extractor::rmdir( $temp_dir );

		putenv( false === $extractor_shell ? 'WP_CLI_TEST_EXTRACTOR_SHELL' : "WP_CLI_TEST_EXTRACTOR_SHELL=$extractor_shell" );
	}

	public function testExtractZip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not installed.' );
		}

		$extractor_zip_archive = getenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' );

		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();

		$zipfile = $temp_dir . '/test.zip';
		$dest_dir = $temp_dir . '/dest';

		// Create test zip.

		$zip = new ZipArchive;
		$result = $zip->open( $zipfile, ZipArchive::CREATE );
		$this->assertTrue( $result );
		$files = self::recursive_scandir( $src_dir );
		foreach ( $files as $file ) {
			$result = $zip->addFile( $src_dir . '/' . $file, $file );
			$this->assertTrue( $result );
		}
		$result = $zip->close();
		$this->assertTrue( $result );

		putenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' );
		Extractor::extract( $zipfile, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		Extractor::rmdir( $dest_dir );

		putenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE=1' );
		Extractor::extract( $zipfile, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		Extractor::rmdir( $dest_dir );

		// Clean up.
		Extractor::rmdir( $temp_dir );

		putenv( false === $extractor_zip_archive ? 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' : "WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE=$extractor_zip_archive" );
	}

	private function create_test_directory_structure() {
		$temp_dir = Utils\get_temp_dir() . uniqid( self::$copy_overwrite_files_prefix, true );
		mkdir( $temp_dir );

		$src_dir = $temp_dir . '/src';
		mkdir( $src_dir );

		/*
		 * Create test directory structure:
		 * src/wordpress/index1.php
		 *               license2.php
		 *               wp-admin/about3.php
		 *                        includes/file4.php
		 *                        widgets5.php
		 *               wp-config6.php
		 *               wp-includes/file7.php
		 *               xmlrpc8.php
		 */

		mkdir( $wp_dir = $src_dir . '/wordpress' );
		mkdir( $wp_admin_dir = $wp_dir . '/wp-admin' );
		mkdir( $wp_admin_includes_dir = $wp_admin_dir . '/includes' );
		mkdir( $wp_includes_dir = $wp_dir . '/wp-includes' );

		touch( $wp_dir . '/index1.php' );
		touch( $wp_dir . '/license2.php' );
		touch( $wp_admin_dir . '/about3.php' );
		touch( $wp_admin_includes_dir . '/file4.php' );
		touch( $wp_admin_dir . '/widgets5.php' );
		touch( $wp_dir . '/wp-config6.php' );
		touch( $wp_includes_dir . '/file7.php' );
		touch( $wp_dir . '/xmlrpc8.php' );

		return array( $temp_dir, $src_dir, $wp_dir );
	}

	private function recursive_scandir( $dir, $prefix_dir = '' ) {
		$ret = array();
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $file ) {
			if ( is_dir( $dir . '/' . $file ) ) {
				$ret = array_merge( $ret, self::recursive_scandir( $dir . '/' . $file, $prefix_dir ? ( $prefix_dir . '/' . $file ) : $file ) );
			} else {
				$ret[] = $prefix_dir ? ( $prefix_dir . '/'. $file ) : $file;
			}
		}
		return $ret;
	}
}
