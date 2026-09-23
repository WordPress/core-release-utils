#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/helphub-manifest-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/preview-helphub-version-pages-lib.php';

/** Manifest a release's HelpHub version pages before any of them are written. */

function helphub_preview_usage(string $script): never {
	fail(
		"Usage: {$script} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> --checkout=<path> "
		. '[--check-published] [--verbose] [--help]'
	);
}

$options = cli_options($argv, array('release:', 'manifest:', 'checkout:', 'check-published', 'verbose', 'help'));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> --checkout=<path> "
		. "[--check-published] [--verbose] [--help]\n";
	exit(0);
}
set_verbose(isset($options['verbose']));
foreach (array('release', 'manifest', 'checkout') as $required) {
	if (!isset($options[$required]) || !is_string($options[$required]) || '' === $options[$required]) {
		helphub_preview_usage($argv[0]);
	}
}

try {
	verbose_log('Scoping HelpHub version pages');
	$manifest_json = @file_get_contents($options['manifest']);
	if (false === $manifest_json) {
		throw new InvalidArgumentException("Unable to read manifest: {$options['manifest']}");
	}
	$manifest = helphub_manifest_decode($manifest_json);
	$checkout = require_checkout($options['checkout']);
	if ($manifest['release'] !== $options['release']) {
		fail(
			"Manifest declares release {$manifest['release']}, but --release says {$options['release']}.",
			2
		);
	}

	$rows       = array();
	$unverified = array();
	foreach (array_keys($manifest['targets']) as $target) {
		$reaching = helphub_fixes_for_target($manifest['fixes'], $target);
		$version  = helphub_branch_version($checkout, $target);
		$branch   = helphub_backport_branch_files($checkout, $manifest, $target);

		if (null === $version) {
			$unverified[] = "Branch {$target} is not in the checkout, so its version could not be read.";
		}
		if (null === $branch) {
			$unverified[] = "No backport branch for {$target} in the checkout, so its file count is unknown.";
		}

		// An unavailable branch cannot establish a file count.
		$unusable = null === $branch ? null : helphub_unusable_file_list($branch);
		if (null !== $unusable) {
			$unverified[] = "Branch {$target} has no file list to preview: {$unusable}";
		}

		$files = null === $branch || null !== $unusable
			? null
			: count(helphub_documented_paths($branch['files']));
		$rows[$target] = array(
			'version' => $version,
			'fixes'   => count($reaching),
			'files'   => $files,
			'missing' => helphub_missing_credits($reaching, $manifest['credits']),
		);
	}

	echo "HelpHub version pages needed for WordPress {$manifest['release']}\n";
	echo str_repeat('-', 72) . "\n";
	printf("  %-7s %-12s %-6s %-7s %s\n", 'branch', 'would be', 'fixes', 'files', 'credits to write');
	foreach ($rows as $target => $row) {
		printf(
			"  %-7s %-12s %-6s %-7s %s\n",
			$target,
			null === $row['version'] ? '?' : $row['version']['next'],
			$row['fixes'],
			null === $row['files'] ? '?' : $row['files'],
			$row['missing'] ? implode(', ', $row['missing']) : '-'
		);
	}

	// An alpha already names the next version; a released version needs its patch incremented.
	$bumped = array();
	foreach ($rows as $target => $row) {
		if (null !== $row['version'] && $row['version']['bumped']) {
			$bumped[] = "{$target} ({$row['version']['raw']})";
		}
	}
	if ($bumped) {
		echo "\nAlready open for their next version: " . implode(', ', $bumped) . "\n";
		echo "Every other branch would publish the patch after its last release.\n";
	}

	$candidates = array();
	foreach ($rows as $row) {
		if (null !== $row['version'] && 0 < $row['fixes']) {
			$candidates[] = $row['version']['next'];
		}
	}
	$candidates = helphub_sort_versions($candidates);

	echo "\nEvery branch carrying a fix, as the generator wants it:\n";
	echo '  --versions=' . implode(',', $candidates) . "\n";
	echo "\nTrim this to the versions the release actually publishes. Receiving a\n";
	echo "backport is not the same as shipping a release: the July 2026 wave\n";
	echo "prepared sixteen branches and published three.\n";

	$outstanding = array();
	foreach ($rows as $target => $row) {
		foreach ($row['missing'] as $number) {
			$outstanding[$number] = true;
		}
	}
	if ($outstanding) {
		$numbers = array_keys($outstanding);
		sort($numbers);
		echo "\nCredit lines still to write, for fix(es): " . implode(', ', $numbers) . "\n";
		echo "Draft their descriptions with draft-helphub-credits.php, then write\n";
		echo "each attribution. No page can be finished until every one is authored.\n";
	}

	if (isset($options['check-published'])) {
		verbose_log('Asking wordpress.org which pages exist');
		$published = helphub_published_pages($candidates, 'helphub_page_exists');
		$missing   = array();
		$present   = array();
		foreach ($published as $version => $exists) {
			$exists ? $present[] = $version : $missing[] = $version;
		}
		echo "\nPublished pages\n";
		echo '  exist:   ' . ($present ? implode(', ', $present) : 'none') . "\n";
		echo '  missing: ' . ($missing ? implode(', ', $missing) : 'none') . "\n";
		echo "  A version that shipped without a page is the failure this catches.\n";
		echo "  Before release every page is missing, and that is expected.\n";
	}

	if ($unverified) {
		echo "\nUnverified (" . count($unverified) . "):\n";
		foreach ($unverified as $note) {
			echo "  - {$note}\n";
		}
		echo "\nFetch the checkout and re-run; regenerate the manifest if its recorded build has changed.\n";
		exit(2);
	}

	echo "\nNothing was written. Fetch the checkout before trusting these numbers.\n";
	exit($outstanding ? 2 : 0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
