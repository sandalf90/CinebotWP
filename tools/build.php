<?php
/**
 * Build the installable plugin archive.
 *
 * @package CinebotWp
 */

$project_root = dirname( __DIR__ );
$dist_dir     = $project_root . '/dist';
$archive_path = $dist_dir . '/cinebot-wp.zip';
$runtime      = array(
	'cinebot-wp.php',
	'uninstall.php',
	'includes',
	'assets',
	'templates',
	'languages',
	'README.md',
	'LICENSE',
);

if ( ! is_dir( $dist_dir ) && ! mkdir( $dist_dir, 0777, true ) && ! is_dir( $dist_dir ) ) {
	throw new RuntimeException( 'Unable to create the distribution directory.' );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'Unable to create the distribution archive.' );
}

$discard_archive = static function () use ( $archive, $archive_path ): bool {
	$archive->close();

	return ! is_file( $archive_path ) || unlink( $archive_path );
};

$add_file = static function ( string $source, string $destination ) use (
	$archive,
	$archive_path,
	$discard_archive
): void {
	if ( $archive->addFile( $source, $destination ) ) {
		return;
	}

	$cleanup_failed = ! $discard_archive();
	$message        = sprintf( 'Unable to add runtime file to archive: %s', $source );
	if ( $cleanup_failed ) {
		$message .= sprintf( '; incomplete archive could not be removed: %s', $archive_path );
	}

	throw new RuntimeException( $message );
};

$add_string = static function ( string $contents, string $destination ) use (
	$archive,
	$archive_path,
	$discard_archive
): void {
	if ( $archive->addFromString( $destination, $contents ) ) {
		return;
	}

	$cleanup_failed = ! $discard_archive();
	$message        = sprintf( 'Unable to add content to archive: %s', $destination );
	if ( $cleanup_failed ) {
		$message .= sprintf( '; incomplete archive could not be removed: %s', $archive_path );
	}

	throw new RuntimeException( $message );
};

if ( ! $archive->addEmptyDir( 'cinebot-wp' ) ) {
	$discard_archive();
	$message = sprintf( 'Unable to add archive root for source: %s', $project_root );
	throw new RuntimeException( $message );
}

foreach ( $runtime as $entry ) {
	$source = $project_root . '/' . $entry;
	if ( is_file( $source ) ) {
		if ( 'cinebot-wp.php' === $entry ) {
			// Direct file read for archive building.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $source );
			if ( false === $contents ) {
				$discard_archive();
				throw new RuntimeException( sprintf( 'Unable to read file: %s', $source ) );
			}
			$clean_contents = (string) preg_replace( '/[ \t]*\/\/[ \t]*x-release-please-version[^\r\n]*/i', '', $contents );
			$add_string( $clean_contents, 'cinebot-wp/' . $entry );
			continue;
		}

		$add_file( $source, 'cinebot-wp/' . $entry );
		continue;
	}

	if ( ! is_dir( $source ) ) {
		continue;
	}

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $files as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$relative_path = substr( $file->getPathname(), strlen( $project_root ) + 1 );
		$relative_path = str_replace( '\\', '/', $relative_path );
		$add_file( $file->getPathname(), 'cinebot-wp/' . $relative_path );
	}
}

if ( ! $archive->close() ) {
	if ( is_file( $archive_path ) ) {
		unlink( $archive_path );
	}
	throw new RuntimeException( 'Unable to finalize the distribution archive.' );
}

echo $archive_path . PHP_EOL;
