<?php
/**
 * Build the installable plugin archive.
 *
 * @package CinebotWp
 */

$projectRoot = dirname(__DIR__);
$distDir     = $projectRoot . '/dist';
$archivePath = $distDir . '/cinebot-wp.zip';
$runtime     = array(
	'cinebot-wp.php',
	'uninstall.php',
	'includes',
	'assets',
	'templates',
	'languages',
	'README.md',
	'LICENSE',
);

if (! is_dir($distDir) && ! mkdir($distDir, 0777, true) && ! is_dir($distDir)) {
	throw new RuntimeException('Unable to create the distribution directory.');
}

$archive = new ZipArchive();
if (true !== $archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
	throw new RuntimeException('Unable to create the distribution archive.');
}

foreach ($runtime as $entry) {
	$source = $projectRoot . '/' . $entry;
	if (is_file($source)) {
		$archive->addFile($source, 'cinebot-wp/' . $entry);
		continue;
	}

	if (! is_dir($source)) {
		continue;
	}

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ($files as $file) {
		if (! $file->isFile()) {
			continue;
		}

		$relativePath = substr($file->getPathname(), strlen($projectRoot) + 1);
		$relativePath = str_replace('\\', '/', $relativePath);
		$archive->addFile($file->getPathname(), 'cinebot-wp/' . $relativePath);
	}
}

if (! $archive->close()) {
	throw new RuntimeException('Unable to finalize the distribution archive.');
}

echo $archivePath . PHP_EOL;
