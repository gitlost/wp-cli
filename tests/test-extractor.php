<?php

use WP_CLI\Extractor;
use WP_CLI\Utils;

class Extractor_Test extends PHPUnit_Framework_TestCase {

	static $copy_overwrite_files_prefix = 'wp-cli-test-utils-copy-overwrite-files-';

	static $expected_top = array(
		'wordpress/',
		'wordpress/index1.php',
		'wordpress/license2.php',
		'wordpress/wp-admin/',
		'wordpress/wp-admin/about3.php',
		'wordpress/wp-admin/includes/',
		'wordpress/wp-admin/includes/file4.php',
		'wordpress/wp-admin/widgets5.php',
		'wordpress/wp-config6.php',
		'wordpress/wp-includes/',
		'wordpress/wp-includes/file7.php',
		'wordpress/xmlrpc8.php',
	);

	static $expected_wp = null;

	static $logger = null;
	static $prev_logger = null;

	public function setUp() {
		parent::setUp();

		// Save and set logger.
		$class_wp_cli_logger = new \ReflectionProperty( 'WP_CLI', 'logger' );
		$class_wp_cli_logger->setAccessible( true );
		self::$prev_logger = $class_wp_cli_logger->getValue();

		self::$logger = new \WP_CLI\Loggers\Execution;
		WP_CLI::set_logger( self::$logger );

		// Init expected_wp.
		if ( null === self::$expected_wp ) {
			self::$expected_wp = array_values( array_filter( array_map( function ( $v ) {
				return substr( $v, strlen( 'wordpress/' ) );
			}, self::$expected_top ) ) );
		}

		// Remove any failed tests detritus.
		$temp_dirs = Utils\get_temp_dir() . self::$copy_overwrite_files_prefix . '*';
		foreach ( glob( $temp_dirs ) as $temp_dir ) {
			Extractor::rmdir( $temp_dir );
		}
	}

	public function tearDown() {
		// Restore logger.
		WP_CLI::set_logger( self::$prev_logger );

		parent::tearDown();
	}

	public function test_rmdir() {
		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();

		$this->assertTrue( is_dir( $wp_dir ) );
		Extractor::rmdir( $wp_dir );
		$this->assertFalse( file_exists( $wp_dir ) );
	}

	public function test_err_rmdir() {
		$msg = '';
		try {
			Extractor::rmdir( 'no-such-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertTrue( false !== strpos( $msg, 'no-such-dir' ) );
		$this->assertTrue( empty( self::$logger->stderr ) );
	}

	public function test_copy_overwrite_files() {
		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();

		$dest_dir = $temp_dir . '/dest';

		// Top level src dir, no strip_components.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );

		$this->assertSame( self::$expected_top, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Wordpress dir, no strip_components.

		Extractor::copy_overwrite_files( $wp_dir, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );

		$this->assertSame( self::$expected_wp, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Top level src dir, strip_components 1.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir, 1 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$this->assertSame( self::$expected_wp, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Wordpress dir, strip_components 1.

		Extractor::copy_overwrite_files( $wp_dir, $dest_dir, 1 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'about3.php',
			'file7.php',
			'includes/',
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
			'includes/',
			'includes/file4.php',
			'widgets5.php',
		);
		$this->assertSame( $expected, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Top level src dir, strip_components 3.

		Extractor::copy_overwrite_files( $src_dir, $dest_dir, 3 /*strip_components*/ );

		$files = self::recursive_scandir( $dest_dir );

		$expected = array(
			'file4.php',
		);
		$this->assertSame( $expected, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Clean up.
		Extractor::rmdir( $temp_dir );
	}

	public function test_err_copy_overwrite_files() {
		$msg = '';
		try {
			Extractor::copy_overwrite_files( 'no-such-dir', 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertTrue( false !== strpos( $msg, 'no-such-dir' ) );
		$this->assertTrue( empty( self::$logger->stderr ) );
	}

	public function test_extract_tarball() {
		if ( ! exec( 'tar --version' ) ) {
			$this->markTestSkipped( 'tar not installed.' );
		}

		$extractor_shell = getenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' );

		list( $temp_dir, $src_dir, $wp_dir ) = self::create_test_directory_structure();

		$tarball = $temp_dir . '/test.tar.gz';
		$dest_dir = $temp_dir . '/dest';

		// Create test tarball.
		$output = array();
		$return_var = -1;
		exec( Utils\esc_cmd( 'tar czvf %1$s --directory=%2$s/src wordpress', $tarball, $temp_dir ), $output, $return_var );
		$this->assertSame( 0, $return_var );
		$this->assertFalse( empty( $output ) );
		sort( $output );
		$this->assertSame( self::recursive_scandir( $src_dir ), $output );

		// Test.
		putenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' );
		Extractor::extract( $tarball, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		putenv( 'WP_CLI_TEST_EXTRACTOR_SHELL=1' );
		Extractor::extract( $tarball, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Clean up.
		Extractor::rmdir( $temp_dir );

		putenv( false === $extractor_shell ? 'WP_CLI_TEST_EXTRACTOR_SHELL' : "WP_CLI_TEST_EXTRACTOR_SHELL=$extractor_shell" );
	}

	public function test_err_extract_tarball() {
		$extractor_shell = getenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' );

		putenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' );
		// Non-existent.
		$msg = '';
		try {
			Extractor::extract( 'no-such-tar.tar.gz', 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertTrue( false !== strpos( $msg, 'no-such-tar' ) );
		$this->assertTrue( 0 === strpos( self::$logger->stderr, "Warning: Falling back to 'tar xz'. PharData failed" ) );
		$this->assertTrue( false !== strpos( self::$logger->stderr, 'no-such-tar' ) );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		// Zero-length.
		$zero_tar = Utils\get_temp_dir() . 'zero-tar.tar.gz';
		touch( $zero_tar );
		$msg = '';
		try {
			Extractor::extract( $zero_tar, 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		unlink( $zero_tar );
		$this->assertTrue( false !== strpos( $msg, 'zero-tar' ) );
		$this->assertTrue( 0 === strpos( self::$logger->stderr, "Warning: Falling back to 'tar xz'. PharData failed" ) );
		$this->assertTrue( false !== strpos( self::$logger->stderr, 'zero-tar' ) );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		putenv( 'WP_CLI_TEST_EXTRACTOR_SHELL=1' );
		// Non-existent.
		$msg = '';
		try {
			Extractor::extract( 'no-such-tar.tar.gz', 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertTrue( false !== strpos( $msg, 'no-such-tar' ) );
		$this->assertTrue( empty( self::$logger->stderr ) );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		// Zero-length.
		$zero_tar = Utils\get_temp_dir() . 'zero-tar.tar.gz';
		touch( $zero_tar );
		$msg = '';
		try {
			Extractor::extract( $zero_tar, 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		unlink( $zero_tar );
		$this->assertTrue( false !== strpos( $msg, 'zero-tar' ) );
		$this->assertTrue( empty( self::$logger->stderr ) );

		putenv( false === $extractor_shell ? 'WP_CLI_TEST_EXTRACTOR_SHELL' : "WP_CLI_TEST_EXTRACTOR_SHELL=$extractor_shell" );
	}

	public function test_extract_zip() {
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
			if ( 0 === substr_compare( $file, '/', -1 ) ) {
				$result = $zip->addEmptyDir( $file );
			} else {
				$result = $zip->addFile( $src_dir . '/' . $file, $file );
			}
			$this->assertTrue( $result );
		}
		$result = $zip->close();
		$this->assertTrue( $result );

		// Test.
		putenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' );
		Extractor::extract( $zipfile, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		putenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE=1' );
		Extractor::extract( $zipfile, $dest_dir );

		$files = self::recursive_scandir( $dest_dir );
		$this->assertSame( self::$expected_wp, $files );
		$this->assertTrue( empty( self::$logger->stderr ) );
		Extractor::rmdir( $dest_dir );

		// Clean up.
		Extractor::rmdir( $temp_dir );
		putenv( false === $extractor_zip_archive ? 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' : "WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE=$extractor_zip_archive" );
	}

	public function test_err_extract_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive not installed.' );
		}

		$extractor_zip_archive = getenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' );

		putenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' );
		// Non-existent.
		$msg = '';
		try {
			Extractor::extract( 'no-such-zip.zip', 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertTrue( false !== strpos( $msg, 'no-such-zip' ) );
		$this->assertFalse( empty( self::$logger->stderr ) );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		// Zero-length - NO error surprisingly with PharData.
		$zero_zip = Utils\get_temp_dir() . 'zero-zip.zip';
		$dest_dir = Utils\get_temp_dir() . 'dest-dir';
		touch( $zero_zip );
		$msg = '';
		try {
			Extractor::extract( $zero_zip, $dest_dir );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		unlink( $zero_zip );
		$this->assertTrue( '' === $msg );
		$this->assertFalse( empty( self::$logger->stderr ) );
		$this->assertTrue( is_dir( $dest_dir ) );
		$this->assertSame( array(), self::recursive_scandir( $dest_dir ) );
		rmdir( $dest_dir );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		putenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE=1' );
		// Non-existent.
		$msg = '';
		try {
			Extractor::extract( 'no-such-zip.zip', 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertTrue( false !== strpos( $msg, 'no-such-zip' ) );
		$this->assertTrue( empty( self::$logger->stderr ) );

		self::$logger->stderr = self::$logger->stdout = ''; // Reset logger.

		// Zero-length - No error surprisingly with ZipArchive either.
		$zero_zip = Utils\get_temp_dir() . 'zero-zip.zip';
		$dest_dir = Utils\get_temp_dir() . 'dest-dir';
		touch( $zero_zip );
		$msg = '';
		try {
			Extractor::extract( $zero_zip, $dest_dir );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		unlink( $zero_zip );
		$this->assertTrue( '' === $msg );
		$this->assertTrue( empty( self::$logger->stderr ) );
		$this->assertTrue( is_dir( $dest_dir ) );
		$this->assertSame( array(), self::recursive_scandir( $dest_dir ) );
		rmdir( $dest_dir );

		putenv( false === $extractor_zip_archive ? 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' : "WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE=$extractor_zip_archive" );
	}

	public function test_err_extract() {
		$msg = '';
		try {
			Extractor::extract( 'not-supported.tar.xz', 'dest-dir' );
		} catch ( \Exception $e ) {
			$msg = $e->getMessage();
		}
		$this->assertSame( "Extraction only supported for '.zip' and '.tar.gz' file types.", $msg );
		$this->assertTrue( empty( self::$logger->stderr ) );
	}

	private function create_test_directory_structure() {
		$temp_dir = Utils\get_temp_dir() . uniqid( self::$copy_overwrite_files_prefix, true );
		mkdir( $temp_dir );

		$src_dir = $temp_dir . '/src';
		mkdir( $src_dir );

		$wp_dir = $src_dir . '/wordpress';

		foreach ( self::$expected_top as $file ) {
			if ( 0 === substr_compare( $file, '/', -1 ) ) {
				mkdir( $src_dir . '/' . $file );
			} else {
				touch( $src_dir . '/' . $file );
			}
		}

		return array( $temp_dir, $src_dir, $wp_dir );
	}

	private function recursive_scandir( $dir, $prefix_dir = '' ) {
		$ret = array();
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $file ) {
			if ( is_dir( $dir . '/' . $file ) ) {
				$ret[] = ( $prefix_dir ? ( $prefix_dir . '/'. $file ) : $file ) . '/';
				$ret = array_merge( $ret, self::recursive_scandir( $dir . '/' . $file, $prefix_dir ? ( $prefix_dir . '/' . $file ) : $file ) );
			} else {
				$ret[] = $prefix_dir ? ( $prefix_dir . '/'. $file ) : $file;
			}
		}
		return $ret;
	}
}
