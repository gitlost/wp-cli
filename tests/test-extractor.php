<?php

use WP_CLI\Extractor;

class Extractor_Test extends PHPUnit_Framework_TestCase {

	static $copy_overwrite_files_prefix = 'wp-cli-test-utils-copy-overwrite-files-';

	public function setUp() {
		parent::setUp();

		// Remove any failed tests detritus.
		$temp_dirs = sys_get_temp_dir() . '/' . self::$copy_overwrite_files_prefix . '*';
		foreach ( glob( $temp_dirs ) as $temp_dir ) {
			Extractor::rmdir( $temp_dir );
		}
	}

	public function testCopyOverwriteFiles() {
		$temp_dir = sys_get_temp_dir() . '/' . uniqid( self::$copy_overwrite_files_prefix, true );
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

		$dest_dir = $temp_dir . '/dest';

		// Top level src dir, no strip_components.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'wordpress/index1.php',
			'wordpress/license2.php',
			'wordpress/wp-admin/about3.php',
			'wordpress/wp-admin/includes/file4.php',
			'wordpress/wp-admin/widgets5.php',
			'wordpress/wp-config6.php',
			'wordpress/wp-includes/file7.php',
			'wordpress/xmlrpc8.php',
		);
		$this->assertSame( $expected, $files );
		Extractor::rmdir( $dest_dir );

		// Wordpress dir, no strip_components.

		Extractor::copy_overwrite_files( $wp_dir, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'index1.php',
			'license2.php',
			'wp-admin/about3.php',
			'wp-admin/includes/file4.php',
			'wp-admin/widgets5.php',
			'wp-config6.php',
			'wp-includes/file7.php',
			'xmlrpc8.php',
		);
		$this->assertSame( $expected, $files );
		Extractor::rmdir( $dest_dir );

		// Top level src dir, strip_components 1.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir, 1 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'index1.php',
			'license2.php',
			'wp-admin/about3.php',
			'wp-admin/includes/file4.php',
			'wp-admin/widgets5.php',
			'wp-config6.php',
			'wp-includes/file7.php',
			'xmlrpc8.php',
		);
		$this->assertSame( $expected, $files );
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

		// Top level src dir, strip_components 2.

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

		// Clean up.
		Extractor::rmdir( $temp_dir );
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
