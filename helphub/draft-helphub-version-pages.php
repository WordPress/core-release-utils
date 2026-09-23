#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/helphub-manifest-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';

/** Draft a HelpHub version page for every release in a security wave. */

function helphub_draft_usage(string $script): never {
	fail(
		"Usage: {$script} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> --checkout=<path> "
		. '--versions=<X.Y.Z,...> --date=<YYYY-MM-DD> --out=<dir> [--verbose] [--help]'
	);
}

$options = cli_options($argv, array('release:', 'manifest:', 'checkout:', 'versions:', 'date:', 'out:', 'verbose', 'help'));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> --checkout=<path> "
		. "--versions=<X.Y.Z,...> --date=<YYYY-MM-DD> --out=<dir> [--verbose] [--help]\n";
	exit(0);
}
set_verbose(isset($options['verbose']));
foreach (array('release', 'manifest', 'checkout', 'versions', 'date', 'out') as $required) {
	if (!isset($options[$required]) || !is_string($options[$required]) || '' === $options[$required]) {
		helphub_draft_usage($argv[0]);
	}
}

try {
	verbose_log('Starting HelpHub version page draft');
	$manifest_json = @file_get_contents($options['manifest']);
	if (false === $manifest_json) {
		throw new InvalidArgumentException("Unable to read manifest: {$options['manifest']}");
	}
	$manifest = helphub_manifest_decode($manifest_json);
	$checkout = require_checkout($options['checkout']);
	if ($manifest['release'] !== $options['release']) {
		fail(
			"Manifest declares release {$manifest['release']}, but --release says {$options['release']}. "
			. 'Drafting one release from another release\'s manifest would document the wrong fixes.',
			2
		);
	}

	$versions = array();
	foreach (explode(',', $options['versions']) as $version) {
		$version = trim($version);
		if ('' === $version) {
			continue;
		}
		if (1 !== preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $version)) {
			fail("Not a version: {$version}", 2);
		}
		$versions[] = $version;
	}
	if (!$versions) {
		fail('--versions must name at least one released version.', 2);
	}
	$versions = helphub_sort_versions($versions);

	// Every released version must map to a targeted branch.
	foreach ($versions as $version) {
		$line = helphub_draft_line($version);
		if (!in_array($line, array_keys($manifest['targets']), true)) {
			fail("Version {$version} maps to branch {$line}, which the manifest does not target.", 2);
		}
	}

	$out_dir = $options['out'];
	if (!is_dir($out_dir) && !mkdir($out_dir, 0o755, true) && !is_dir($out_dir)) {
		fail("Unable to create output directory: {$out_dir}");
	}

	$written = array();
	$notes   = array();
	foreach ($versions as $version) {
		$line   = helphub_draft_line($version);
		$branch = helphub_backport_branch_files($checkout, $manifest, $line);
		if (null === $branch) {
			fail(
				"No backport branch for {$line} in the checkout. The file list must come from what "
				. 'the branch shipped, so drafting without it would document the wrong files. '
				. 'Fetch the checkout and try again.',
				2
			);
		}
		// An empty branch needs attention before drafting.
		$unusable = helphub_unusable_file_list($branch);
		if (null !== $unusable) {
			fail(
				"Cannot draft {$version}: {$unusable} A page for a security release always lists at "
				. 'least one revised file, so this would document a change that is not there. '
				. 'Check the branch carries the backport, then try again.',
				2
			);
		}
		$files = array_keys(helphub_documented_paths($branch['files']));
		sort($files);

		// Read release paths from the branch that supplied the file list.
		$release_paths = helphub_release_paths($checkout, $branch['branch']);

		// Read package changes from the recorded branch and base.
		$packages = helphub_revised_packages($checkout, $branch['branch'], $branch['base']);

		$page = helphub_draft_page(
			$version,
			$options['date'],
			$files,
			$manifest['fixes'],
			$manifest['credits'],
			$versions,
			array_keys($manifest['targets']),
			$release_paths,
			$packages
		);

		$path = rtrim($out_dir, '/') . "/{$version}.txt";
		if (false === file_put_contents($path, $page['body'])) {
			fail("Unable to write {$path}");
		}
		$written[$version] = array(
			'path'    => $path,
			'files'   => count($files),
			'source'  => "{$branch['branch']} at {$branch['commit']}",
			'missing' => $page['missing_credits'],
		);
		if ($page['missing_credits']) {
			$notes[] = sprintf(
				'%s: no authored credit for fix(es) %s. Each is a FILL IN marker in the draft.',
				$version,
				implode(', ', $page['missing_credits'])
			);
		}
	}

	echo "Drafted HelpHub version pages for WordPress {$manifest['release']}\n";
	echo str_repeat('-', 60) . "\n";
	foreach ($written as $version => $record) {
		printf("  %-10s %2d file(s)   from %s\n", $version, $record['files'], $record['source']);
	}
	// Stamp the release so the push tool can verify the draft directory.
	$stamp = rtrim($out_dir, '/') . '/.release';
	if (false === file_put_contents($stamp, $manifest['release'] . "\n")) {
		fail("Unable to write {$stamp}");
	}

	echo "\nWritten to {$out_dir}\n";
	echo "A stale checkout drafts a wrong file list quietly; the commits read are named above.\n";

	if ($notes) {
		echo "\nNeeds a human (" . count($notes) . "):\n";
		foreach ($notes as $note) {
			echo "  - {$note}\n";
		}
		echo "\nAuthor each credit line in the manifest's `credits` block, then re-run.\n";
		exit(2);
	}

	echo "\nEvery fix on every page carries an authored credit line.\n";
	echo "Review each draft, then paste it into the HelpHub editor. Nothing was published.\n";
	exit(0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
