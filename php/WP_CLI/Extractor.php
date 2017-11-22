<?php

namespace WP_CLI;

use Exception;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_CLI;
use WP_CLI\Utils;
use ZipArchive;

/**
 * Extract a provided archive file.
 */
class Extractor {

	/**
	 * Extract the archive file to a specific destination.
	 *
	 * @param string $dest
	 */
	public static function extract( $tarball_or_zip, $dest ) {
		if ( preg_match( '/\.zip$/', $tarball_or_zip ) ) {
			return self::extract_zip( $tarball_or_zip, $dest );
		}

		if ( preg_match( '/\.tar\.gz$/', $tarball_or_zip ) ) {
			return self::extract_tarball( $tarball_or_zip, $dest );
		}
		throw new \Exception( "Extraction only supported for '.zip' and '.tar.gz' file types." );
	}

	/**
	 * Extract a ZIP file to a specific destination.
	 *
	 * @param string $zipfile
	 * @param string $dest
	 */
	private static function extract_zip( $zipfile, $dest ) {
		if ( class_exists( 'PharData' ) && ! getenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_ARCHIVE' ) ) {
			try {
				self::copy_overwrite_files( 'phar://' . $zipfile, $dest, 1 /*strip_components*/ );
				return;
			} catch ( \Exception $e ) {
				WP_CLI::warning( sprintf( 'Falling back to ZipArchive. PharData failed: %s.', $e->getMessage() ) );
			}
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \Exception( 'Extracting a zip file requires ZipArchive or PharData.' );
		}
		$zip = new ZipArchive();
		$res = $zip->open( $zipfile );
		if ( true !== $res ) {
			throw new \Exception( sprintf( "ZipArchive failed to open '%s': %s.", $zipfile, self::zip_error_msg( $res ) ) );
		}
		$tempdir = Utils\get_temp_dir() . uniqid( 'wp-cli-extract-zip-', true );

		if ( ! $zip->extractTo( $tempdir ) ) {
			throw new \Exception( sprintf( "ZipArchive failed to extract '%s' to temporary directory '%s'.", $zipfile, $tempdir ) );
		}
		if ( ! $zip->close() ) {
			throw new \Exception( sprintf( "ZipArchive failed to close '%s'.", $zipfile ) );
		}
		self::copy_overwrite_files( $tempdir, $dest, 1 /*strip_components*/ );
		self::rmdir( $tempdir );
	}

	/**
	 * Extract a tarball to a specific destination.
	 *
	 * @param string $tarball
	 * @param string $dest
	 */
	private static function extract_tarball( $tarball, $dest ) {

		if ( class_exists( 'PharData' ) && ! getenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' ) ) {
			try {
				// Check if PHP bug #75273 likely to be triggered and copy to temporary tar if so.
				$pre_tar = false;
				// Only checking Ubuntu (and derivatives) as unfortunately Debian doesn't sign its PHP build so not easy to check for it.
				if ( false !== strpos( PHP_VERSION, 'ubuntu' ) ) {
					$tar_size = filesize( $tarball );
					$pre_tar = $tar_size > 4 * 1024 * 1024 && $tar_size % ( 8 * 1024 ) < 10;
				}
				if ( $pre_tar ) {
					$temp_tar = Utils\get_temp_dir() . uniqid( 'wp-cli-extract-', true ) . '.tar';
					if ( false === ( $contents = file_get_contents( 'compress.zlib://' . $tarball ) ) || false === file_put_contents( $temp_tar, $contents ) ) {
						throw new \Exception( sprintf( "Failed to create temporary tar '%s'.", $temp_tar ) );
					}

					self::copy_overwrite_files( 'phar://' . $temp_tar, $dest, 1 /*strip_components*/ );
					unlink( $temp_tar );
				} else {

					self::copy_overwrite_files( 'phar://' . $tarball, $dest, 1 /*strip_components*/ );
				}
				return;
			} catch ( \Exception $e ) {
				// Can fail on Debian due to PHP bug #75273 so don't warn but fall through silently if message matches.
				if ( $pre_tar || sprintf( 'unable to decompress gzipped phar archive "%s" to temporary file', $tarball ) !== $e->getMessage() ) {
					WP_CLI::warning( sprintf( "Falling back to 'tar xz'. PharData failed: %s.", $e->getMessage() ) );
				}
				// Fall through to trying `tar xz` below
			}
		}

		// Directory must exist for tar --directory to work.
		if ( ! file_exists( $dest ) ) {
			mkdir( $dest, 0777, true );
		}
		$cmd = Utils\esc_cmd( 'tar xz --strip-components=1 --directory=%2$s -f %1$s', $tarball, $dest );
		$process_run = WP_CLI::launch( $cmd, false /*exit_on_error*/, true /*return_detailed*/ );
		if ( 0 !== $process_run->return_code ) {
			throw new \Exception( sprintf( 'Failed to execute `%s`: %s.', $cmd, self::tar_error_msg( $process_run ) ) );
		}
	}

	/**
	 * Copy files from source directory to destination directory. Source directory must exist.
	 *
	 * @param string $source
	 * @param string $dest
	 */
	public static function copy_overwrite_files( $source, $dest, $strip_components = 0 ) {
		if ( false === ( $files = scandir( $source ) ) ) {
			$error = error_get_last();
			throw new \Exception( sprintf( "Failed to scan source directory '%s': %s", $source, $error['message'] ) );
		}

		if ( ! file_exists( $dest ) && ! mkdir( $dest, 0777, true ) ) {
			$error = error_get_last();
			throw new \Exception( sprintf( "Failed to create destination directory '%s': %s", $dest, $error['message'] ) );
		}

		foreach ( array_diff( $files, array( '.', '..' ) ) as $file ) {

			$source_file = $source . '/' . $file;
			$dest_file = $dest . '/' . $file;

			if ( $strip_components > 0 ) {
				if ( is_dir( $source_file ) ) {
					self::copy_overwrite_files( $source_file, $dest, $strip_components - 1 );
				}
			} else {

				if ( is_dir( $source_file ) ) {
					self::copy_overwrite_files( $source_file, $dest_file );
				} else {
					if ( ! copy( $source_file, $dest_file ) ) {
						$error = error_get_last();
						throw new \Exception( sprintf( "Failed to copy '%s' to target '%s': %s.", $source_file, $dest_file, $error['message'] ) );
					}
				}
			}
		}
	}

	/**
	 * Delete all files and directories recursively from directory. Directory must exist.
	 *
	 * @param string $dir
	 */
	public static function rmdir( $dir ) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $fileinfo ) {
			$todo = $fileinfo->isDir() ? 'rmdir' : 'unlink';
			$todo( $fileinfo->getRealPath() );
		}
		rmdir( $dir );
	}

	/**
	 * Return formatted ZipArchive error message from error code.
	 *
	 * @param int $error_code
	 * @return string
	 */
	public static function zip_error_msg( $error_code ) {
		// From https://github.com/php/php-src/blob/php-5.3.0/ext/zip/php_zip.c#L2623-L2646
		static $zip_err_msgs = array(
			ZipArchive::ER_OK => 'No error',
			ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
			ZipArchive::ER_RENAME => 'Renaming temporary file failed',
			ZipArchive::ER_CLOSE => 'Closing zip archive failed',
			ZipArchive::ER_SEEK => 'Seek error',
			ZipArchive::ER_READ => 'Read error',
			ZipArchive::ER_WRITE => 'Write error',
			ZipArchive::ER_CRC => 'CRC error',
			ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
			ZipArchive::ER_NOENT => 'No such file',
			ZipArchive::ER_EXISTS => 'File already exists',
			ZipArchive::ER_OPEN => 'Can\'t open file',
			ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
			ZipArchive::ER_ZLIB => 'Zlib error',
			ZipArchive::ER_MEMORY => 'Malloc failure',
			ZipArchive::ER_CHANGED => 'Entry has been changed',
			ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
			ZipArchive::ER_EOF => 'Premature EOF',
			ZipArchive::ER_INVAL => 'Invalid argument',
			ZipArchive::ER_NOZIP => 'Not a zip archive',
			ZipArchive::ER_INTERNAL => 'Internal error',
			ZipArchive::ER_INCONS => 'Zip archive inconsistent',
			ZipArchive::ER_REMOVE => 'Can\'t remove file',
			ZipArchive::ER_DELETED => 'Entry has been deleted',
		);

		if ( isset( $zip_err_msgs[ $error_code ] ) ) {
			return sprintf( '%s (%d)', $zip_err_msgs[ $error_code ], $error_code );
		}
		return $error_code;
	}

	/**
	 * Return formatted error message from ProcessRun of tar command.
	 *
	 * @param Processrun $process_run
	 * @return string
	 */
	public static function tar_error_msg( $process_run ) {
		$stderr = trim( $process_run->stderr );
		if ( false !== ( $nl_pos = strpos( $stderr, "\n" ) ) ) {
			$stderr = trim( substr( $stderr, 0, $nl_pos ) );
		}
		if ( $stderr ) {
			return sprintf( '%s (%d)', $stderr, $process_run->return_code );
		}
		return $process_run->return_code;
	}
}
