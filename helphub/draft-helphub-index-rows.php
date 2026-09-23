#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/helphub-http-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/check-helphub-index-lib.php';
require_once __DIR__ . '/lib/draft-helphub-index-rows-lib.php';

/** Draft missing rows for the HelpHub versions indexes. */

function helphub_index_draft_usage(string $script): never {
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
	helphub_index_draft_usage($argv[0]);
}

try {
	$pages    = helphub_index_page_map(helphub_index_fetch_published_pages());
	$articles = helphub_index_fetch_articles();
	$indexed  = array();
	foreach ($articles as $content) {
		$indexed = array_merge($indexed, helphub_index_versions($content));
	}
	if (!$indexed) {
		throw new RuntimeException('Both index articles yielded no version rows; refusing to draft the entire history from an empty result.');
	}
	$reconcile = helphub_index_reconcile(array_keys($pages), $indexed);
	$targets   = $reconcile['missing'];
	if (!$audit) {
		$wave    = helphub_index_release_wave($pages, (string) $release);
		$targets = array_values(array_intersect($targets, $wave));
		$outside = array_values(array_diff($reconcile['missing'], $wave));
		if ($outside) {
			fwrite(
				STDERR,
				sprintf(
					"%d missing row(s) sit outside this wave and were not drafted. Run --audit to include them.\n",
					count($outside)
				)
			);
		}
	}
	if (!$targets) {
		fwrite(STDERR, "No missing rows in the selected scope.\n");
		exit(0);
	}

	fwrite(STDERR, sprintf("Drafting %d missing row(s)...\n", count($targets)));
	$search_cache = array();
	$exact_cache  = array();
	$notes        = array();
	$rows         = array();
	$wave_links   = 0;
	foreach ($targets as $version) {
		$page = $pages[$version];
		if (null === $page['date']) {
			$notes[] = "{$version}: its opening sentence does not name this version and release date; date left blank.";
		}
		if (null === $page['url']) {
			$notes[] = "{$version}: its REST record has no public link; version and Change Log links left blank.";
		}
		$announcement = helphub_index_announcement_for_page(
			$version,
			$pages,
			'helphub_index_search_news',
			$exact_cache,
			$search_cache
		);
		if (null === $announcement['link']) {
			$notes[] = "{$version}: no verified news post names it, and no published page with the same fix set supplies a wave post; Announcement left blank.";
		} elseif (str_starts_with((string) $announcement['source'], 'wave:')) {
			$wave_links++;
		}
		$rows[helphub_index_series($version)][] = helphub_index_draft_row($page, $announcement['link']);
	}

	uksort($rows, static fn(string $a, string $b): int => version_compare($b, $a));
	foreach ($rows as $series => $series_rows) {
		echo "\n<!-- Version {$series} table, newest first -->\n";
		echo implode("\n", $series_rows) . "\n";
	}

	fwrite(
		STDERR,
		sprintf(
			"\n%d row(s) drafted. %d announcement(s) used a fix-set-verified wave post.\n",
			count($targets),
			$wave_links
		)
	);
	if ($notes) {
		fwrite(STDERR, "\nLeft blank rather than guessed (" . count($notes) . "):\n");
		foreach ($notes as $note) {
			fwrite(STDERR, "  - {$note}\n");
		}
		exit(2);
	}
	exit(0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
