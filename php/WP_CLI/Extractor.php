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
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'Extracting a zip file requires ZipArchive.' );
		}
		$zip = new ZipArchive();
		$res = $zip->open( $zipfile );
		if ( true === $res ) {
			$tempdir = implode(
				DIRECTORY_SEPARATOR,
				array(
					dirname( $zipfile ),
					Utils\basename( $zipfile, '.zip' ),
					$zip->getNameIndex( 0 ),
				)
			);

			$zip->extractTo( dirname( $tempdir ) );
			$zip->close();

			self::copy_overwrite_files( $tempdir, $dest );
			self::rmdir( dirname( $tempdir ) );
		} else {
			throw Exception( $res );
		}
	}

	/**
	 * Extract a tarball to a specific destination.
	 *
	 * @param string $tarball
	 * @param string $dest
	 */
	private static function extract_tarball( $tarball, $dest ) {

		if ( class_exists( 'PharData' ) ) {
			try {
				// If on Ubuntu (or derivative) check if PHP bug #75273 likely to be triggered and copy to temporary tar if so.
				$pre_tar = false;
				if ( false !== strpos( PHP_VERSION, 'ubuntu' ) ) { // Unfortunately Debian doesn't sign its PHP build so not easy to check for it.
					$tar_size = filesize( $tarball );
					$pre_tar = $tar_size > 4 * 1024 * 1024 && $tar_size % ( 8 * 1024 ) < 10;
				}
				if ( $pre_tar ) {
					$temp_tar = Utils\get_temp_dir() . uniqid( 'wp-cli-extract-', true ) . '.tar';
					if ( false === ( $contents = file_get_contents( 'compress.zlib://' . $tarball ) ) || false === file_put_contents( $temp_tar, $contents ) ) {
						throw new \Exception( "Error creating temporary tar '$temp_tar'." );
					}

					self::copy_overwrite_files( 'phar://' . $temp_tar, $dest );

					unlink( $temp_tar );
				} else {
					self::copy_overwrite_files( 'phar://' . $tarball, $dest );
				}
				return;
			} catch ( \Exception $e ) {
				WP_CLI::warning( "PharData failed, falling back to 'tar gz' (" . $e->getMessage() . ')' );
				// Fall through to trying `tar xz` below
			}
		}
		$cmd = "tar xz --strip-components=1 --directory=%s -f $tarball";
		WP_CLI::launch( Utils\esc_cmd( $cmd, $dest ) );
	}

	public static function copy_overwrite_files( $source, $dest ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$error = 0;

		if ( ! is_dir( $dest ) ) {
			mkdir( $dest, 0777, true );
		}

		foreach ( $iterator as $item ) {

			$dest_path = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

			if ( $item->isDir() ) {
				if ( ! is_dir( $dest_path ) ) {
					mkdir( $dest_path );
				}
			} else {
				if ( file_exists( $dest_path ) && is_writable( $dest_path ) ) {
					copy( $item, $dest_path );
				} elseif ( ! file_exists( $dest_path ) ) {
					copy( $item, $dest_path );
				} else {
					$error = 1;
					WP_CLI::warning( "Unable to copy '" . $iterator->getSubPathName() . "' to current directory." );
				}
			}
		}

		if ( $error ) {
			throw new Exception( 'There was an error overwriting existing files.' );
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
