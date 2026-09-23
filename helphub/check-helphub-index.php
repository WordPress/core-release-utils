#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/helphub-http-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/check-helphub-index-lib.php';

/** Reconcile published HelpHub version pages with the two version indexes. */

function helphub_index_check_usage(string $script): never {
	fail("Usage: {$script} (--release=<X.Y.Z> | --audit) [--help]");
}

$options = cli_options($argv, array('release:', 'audit', 'help'));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} (--release=<X.Y.Z> | --audit) [--help]\n";
	exit(0);
}
$audit   = isset($options['audit']);
$release = isset($options['release']) && is_string($options['release']) ? $options['release'] : null;
if ($audit === (null !== $release)) {
	helphub_index_check_usage($argv[0]);
}

try {
	$pages    = helphub_index_page_map(helphub_index_fetch_published_pages());
	$articles = helphub_index_fetch_articles();
	$indexed  = array();
	foreach ($articles as $content) {
		$indexed = array_merge($indexed, helphub_index_versions($content));
	}
	if (!$indexed) {
		throw new RuntimeException('Both index articles yielded no version rows; refusing to report every published page as missing.');
	}
	$result = helphub_index_reconcile(array_keys($pages), $indexed);
	// A known content decision (see HELPHUB_INDEX_KNOWN_EXCEPTIONS), not a gap.
	$known_split       = helphub_index_split_known($result['missing'], HELPHUB_INDEX_KNOWN_EXCEPTIONS);
	$result['missing'] = $known_split['rest'];

	echo $audit
		? "HelpHub versions index audit\n"
		: "HelpHub versions index check for WordPress {$release}\n";
	echo str_repeat('-', 66) . "\n";
	printf("Published pages: %d | Indexed versions: %d\n", count($pages), count(array_unique($indexed)));
	if ($known_split['known']) {
		echo "\nKnown, not counted (" . count($known_split['known']) . "):\n";
		foreach ($known_split['known'] as $version) {
			echo "  - {$version}\n";
		}
	}

	$wave_missing = array();
	if ($audit) {
		if ($result['missing']) {
			echo "\nPublished pages missing from the index (" . count($result['missing']) . "):\n";
			foreach ($result['missing'] as $version) {
				echo "  - {$version}\n";
			}
		}
	} else {
		$wave         = helphub_index_release_wave($pages, (string) $release);
		$wave_missing = array_values(array_intersect($result['missing'], $wave));
		$other        = array_values(array_diff($result['missing'], $wave));
		echo "\nRelease wave (" . count($wave) . "): " . implode(', ', $wave) . "\n";
		if ($wave_missing) {
			echo "\nWave pages missing from the index (" . count($wave_missing) . "):\n";
			foreach ($wave_missing as $version) {
				echo "  - {$version}\n";
			}
		} else {
			echo "\nEvery published page in this wave is indexed.\n";
		}
		if ($other) {
			echo "\nDrift outside this wave (" . count($other) . "):\n";
			foreach ($other as $version) {
				echo "  - {$version}\n";
			}
			echo "  Run --audit to reconcile the full history.\n";
		} else {
			echo "\nNo missing rows outside this wave.\n";
		}
	}

	if ($result['orphaned']) {
		echo "\nIndex rows with no published version page (" . count($result['orphaned']) . "):\n";
		foreach ($result['orphaned'] as $version) {
			echo "  - {$version}\n";
		}
	}

	if (!$result['missing'] && !$result['orphaned']) {
		echo "\nThe published pages and indexes match.\n";
		exit(0);
	}

	// Only an incomplete wave fails; older drift is still reported.
	$blocking = $audit ? ($result['missing'] || $result['orphaned']) : (bool) $wave_missing;
	if (!$blocking) {
		echo "\nThis wave is fully indexed. The findings above are older drift; run --audit to reconcile.\n";
		exit(0);
	}

	echo "\nNothing was changed. Resolve the findings before the news post publishes.\n";
	exit(2);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
