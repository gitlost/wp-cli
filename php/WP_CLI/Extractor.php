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
		throw new \Exception( 'Extension not supported.' );
	}

	/**
	 * Extract a ZIP file to a specific destination.
	 *
	 * @param string $zipfile
	 * @param string $dest
	 */
	private static function extract_zip( $zipfile, $dest ) {
		if ( ! class_exists( 'ZipArchive' ) || getenv( 'WP_CLI_TEST_EXTRACTOR_ZIP_PHAR' ) ) {
			WP_CLI::warning( class_exists( 'PharData' ) ? 'ZipArchive not installed, trying PharData.' : 'ZipArchive not installed, trying shell \'unzip\' command.' );
			self::extract_tarball( $zipfile, $dest, true /*is_zip*/ );
			return;
		}

		$zip = new ZipArchive;
		if ( true !== ( $res = $zip->open( $zipfile ) ) ) {
			throw new \Exception( "ZipArchive failed to open '$zipfile': $res" );
		}

		$tempdir = Utils\get_temp_dir() . uniqid( 'wp-cli-extract-zip-', true );

		if ( ! $zip->extractTo( $tempdir ) ) {
			throw new \Exception( "ZipArchive failed to extract '$zipfile' to temporary directory '$tempdir'." );
		}

		if ( ! $zip->close() ) {
			throw new \Exception( "ZipArchive failed to close '$zipfile'." );
		}

		self::copy_overwrite_files( $tempdir, $dest, 1 /*strip_components*/ );

		self::rmdir( $tempdir );
	}

	/**
	 * Extract a tarball to a specific destination.
	 *
	 * @param string $tarball
	 * @param string $dest
	 * @param bool   $is_zip Optional. Whether zip file or not. Default false.
	 */
	private static function extract_tarball( $tarball, $dest, $is_zip = false ) {

		if ( class_exists( 'PharData' ) && ! getenv( 'WP_CLI_TEST_EXTRACTOR_SHELL' ) ) {
			try {
				// Check if PHP bug #75273 likely to be triggered and copy to temporary tar if so.
				$pre_tar = false;
				// Only checking Ubuntu (and derivatives) as unfortunately Debian doesn't sign its PHP build so not easy to check for it.
				if ( ! $is_zip && false !== strpos( PHP_VERSION, 'ubuntu' ) ) {
					$tar_size = filesize( $tarball );
					$pre_tar = $tar_size > 4 * 1024 * 1024 && $tar_size % ( 8 * 1024 ) < 10;
				}
				if ( $pre_tar ) {
					$temp_tar = Utils\get_temp_dir() . uniqid( 'wp-cli-extract-', true ) . '.tar';
					if ( false === ( $contents = file_get_contents( 'compress.zlib://' . $tarball ) ) || false === file_put_contents( $temp_tar, $contents ) ) {
						throw new \Exception( "Failed to create temporary tar '$temp_tar'." );
					}

					self::copy_overwrite_files( 'phar://' . $temp_tar, $dest, 1 /*strip_components*/ );

					unlink( $temp_tar );
				} else {

					self::copy_overwrite_files( 'phar://' . $tarball, $dest, 1 /*strip_components*/ );
				}
				return;
			} catch ( \Exception $e ) {
				// Can fail on Debian due to PHP bug #75273 so don't warn but fall through silently if message matches.
				if ( ! $pre_tar && sprintf( 'unable to decompress gzipped phar archive "%s" to temporary file', $tarball ) === $e->getMessage() ) {
					WP_CLI::warning( 'PharData failed, falling back to shell command (' . $e->getMessage() . ')' );
				}
				// Fall through to trying `tar xz` or `unzip` below.
			}
		}

		if ( $is_zip ) {
			$cmd = Utils\esc_cmd( 'unzip -o -qq %1$s -d %2$s', $tarball, $dest );
		} else {
			// Directory must exist for tar --directory to work.
			if ( ! file_exists( $dest ) ) {
				mkdir( $dest, 0777, true );
			}
			$cmd = Utils\esc_cmd( 'tar xz --strip-components=1 --directory=%2$s -f %1$s', $tarball, $dest );
		}

		$process_run = WP_CLI::launch( $cmd, false /*exit_on_error*/, true /*return_detailed*/ );

		if ( 0 !== $process_run->return_code ) {
			throw new \Exception( sprintf( "Shell command '%s' returned %d: %s", $process_run->command, $process_run->return_code, $process_run->stderr ) );
		}

		if ( $is_zip ) {
			$dest = Utils\trailingslashit( $dest );
			if ( file_exists( $dest . 'wordpress' ) ) {
				self::copy_overwrite_files( $dest . 'wordpress', $dest );
				self::rmdir( $dest . 'wordpress' );
			}
		}
	}

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

}
