#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/helphub-manifest-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/preview-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/push-helphub-drafts-lib.php';
require_once __DIR__ . '/lib/helphub-rest-lib.php';

/** Check a release's HelpHub version pages against the release's own artifacts. */

function helphub_usage(string $script): never {
	fail(
		"Usage: {$script} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> --checkout=<path> "
		. '(--pages=<dir> | --live --site=<url> --user=<login>) [--check-links] [--verbose] [--help]'
	);
}

$options = cli_options($argv, array(
	'release:', 'manifest:', 'checkout:', 'pages:', 'live', 'site:', 'user:', 'check-links', 'verbose', 'help',
));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> --checkout=<path> "
		. "(--pages=<dir> | --live --site=<url> --user=<login>) [--check-links] [--verbose] [--help]\n"
		. "\nLive mode reads the application password from HELPHUB_APP_PASSWORD.\n";
	exit(0);
}
set_verbose(isset($options['verbose']));
foreach (array('release', 'manifest', 'checkout') as $required) {
	if (!isset($options[$required]) || !is_string($options[$required]) || '' === $options[$required]) {
		helphub_usage($argv[0]);
	}
}
$live = isset($options['live']);
$has_pages = isset($options['pages']) && is_string($options['pages']) && '' !== $options['pages'];
if ($live === $has_pages) {
	fail('Choose exactly one of --pages or --live.', 2);
}
if ($live) {
	foreach (array('site', 'user') as $required) {
		if (!isset($options[$required]) || !is_string($options[$required]) || '' === $options[$required]) {
			fail("--live needs --{$required}.", 2);
		}
	}
}

try {
	verbose_log('Starting HelpHub version page check');
	$manifest_json = @file_get_contents($options['manifest']);
	if (false === $manifest_json) {
		throw new InvalidArgumentException("Unable to read manifest: {$options['manifest']}");
	}
	$manifest = helphub_manifest_decode($manifest_json);
	$checkout = require_checkout($options['checkout']);
	if ($manifest['release'] !== $options['release']) {
		fail(
			"Manifest declares release {$manifest['release']}, but --release says {$options['release']}. "
			. 'Checking one release against another release\'s manifest would compare the wrong fixes.',
			2
		);
	}
	$source_unverified = array();
	$present_versions  = array();
	if ($live) {
		helphub_load_auth($options['user']);
		$site = helphub_require_https($options['site']);
		$targets = helphub_live_target_versions($manifest, $checkout);
		$source_unverified = $targets['unverified'];
		$live_pages = helphub_load_live_pages($site, $targets['versions']);
		$pages = $live_pages['pages'];
		$present_versions = $live_pages['found'];
		$source_unverified = array_merge($source_unverified, $live_pages['unverified']);
	} else {
		$pages = helphub_load_pages($options['pages']);
		$present_versions = array_keys($pages);
	}
	verbose_log(sprintf('Loaded %d page(s) for release %s', count($pages), $manifest['release']));

	$texts         = array();
	$credits       = array();
	$pages_by_line = array();
	$unverified    = $source_unverified;
	$provenance    = array();
	foreach ($present_versions as $version) {
		$pages_by_line[helphub_version_line($version)][] = $version;
	}
	foreach ($pages as $version => $body) {
		$text                          = helphub_page_to_text($body);
		$texts[$version]               = $text;

		$bullets = helphub_credit_bullets($text);
		if (null === $bullets) {
			$unverified[] = "Page {$version} has no \"" . HELPHUB_SECURITY_HEADING
				. '" section, so its credits were not checked.';
			continue;
		}
		$credits[$version] = $bullets;
	}

	$findings = helphub_check_credit_consistency($credits);
	$presence = helphub_check_pages_present(array_keys($manifest['targets']), $pages_by_line);
	$findings = array_merge($findings, $presence['findings']);
	foreach ($pages as $version => $body) {
		if (!helphub_credit_is_authored($body)) {
			$findings[] = "Page {$version} still carries a FILL IN placeholder.";
		}
	}
	$unverified = array_merge($unverified, $presence['unverified']);

	// Check prose defects that cross-page comparisons cannot detect.
	foreach ($texts as $version => $text) {
		$findings = array_merge($findings, helphub_prose_defects($version, $text));
	}

	// Check links only when requested.
	if (isset($options['check-links'])) {
		$urls = array();
		foreach ($pages as $body) {
			foreach (helphub_extract_links($body) as $url) {
				$urls[$url] = true;
			}
		}
		verbose_log(sprintf('Checking %d link(s)', count($urls)));
		$links = helphub_check_links(array_keys($urls), 'helphub_http_status');
		foreach ($links['broken'] as $broken) {
			$findings[] = "Link does not resolve: {$broken}";
		}
		foreach ($links['unresolved'] as $unresolved_link) {
			$unverified[] = "Link could not be checked: {$unresolved_link}";
		}
	}

	foreach ($pages as $version => $body) {
		$line = helphub_version_line($version);
		if (!in_array($line, array_keys($manifest['targets']), true)) {
			continue;
		}
		try {
			$expected = helphub_expected_files($checkout, $manifest, $line, $version);
		} catch (HelphubMissingTagException $exception) {
			$unverified[] = "Page {$version} file list could not be checked: {$exception->getMessage()} Run: {$exception->fetchCommand}";
			continue;
		} catch (HelphubBranchBehindException | InvalidArgumentException $exception) {
			$unverified[] = "Page {$version} file list could not be checked: {$exception->getMessage()}.";
			continue;
		}
		if (null === $expected['ref']) {
			$unverified[] = "Page {$version} file list could not be checked: the {$line} branch is not in the checkout. "
				. 'Fetch the checkout or regenerate the manifest.';
			continue;
		}
		// Translate release paths using the branch that supplied the expected list.
		$release_paths = null === $expected['ref']
			? array()
			: helphub_release_paths($checkout, $expected['ref']);
		foreach (helphub_source_path_findings($texts[$version], $release_paths, $version) as $finding) {
			$findings[] = $finding;
		}
		$listed_files = helphub_revised_files($texts[$version], $release_paths);
		if (null === $listed_files) {
			$unverified[] = "Page {$version} has no \"" . HELPHUB_FILES_HEADING
				. '" section, so its file list was not checked.';
		} else {
			if (null !== $expected['problem']) {
				$findings[] = sprintf(
					'Page %s cannot be checked against its branch: %s',
					$version,
					$expected['problem']
				);
			}

			$findings = array_merge(
				$findings,
				helphub_check_file_list($version, $listed_files, $expected['files'])
			);
		}

		$provenance[$version] = $expected['read_from'];
		$listed_packages = helphub_revised_package_lines($texts[$version]);
		if (null === $listed_packages) {
			$package_check = helphub_check_package_section($version, null);
			$unverified = array_merge($unverified, $package_check['unverified']);
			continue;
		}
		try {
			$packages = helphub_revised_packages($checkout, $expected['ref'], "refs/tags/{$expected['base']}");
		} catch (RuntimeException $exception) {
			$unverified[] = "Page {$version} package list could not be checked: {$exception->getMessage()}";
			continue;
		}
		$package_check = helphub_check_package_section($version, $listed_packages, helphub_package_lines($packages));
		$findings = array_merge($findings, $package_check['findings']);
		$unverified = array_merge($unverified, $package_check['unverified']);
	}

	echo "HelpHub version pages for WordPress {$manifest['release']}\n";
	echo str_repeat('-', 60) . "\n";
	echo sprintf("Pages checked: %d\n", count($pages));

	// Report the branch and commit used for each comparison.
	if ($provenance) {
		echo "\nFile and package lists compared against:\n";
		foreach ($provenance as $version => $read_from) {
			echo "  {$version}: {$read_from}\n";
		}
		echo "  Branch tips matched the manifest; regenerate it if the release build changes.\n";
	}

	if ($unverified) {
		echo "\nUnverified (" . count($unverified) . "):\n";
		foreach ($unverified as $note) {
			echo "  - {$note}\n";
		}
	}

	if (!$findings) {
		echo "\nNo mismatches found.\n";
		echo "\nThis check compares pages against each other and against what each\n";
		echo "branch shipped. It does not spell-check: an error copied identically\n";
		echo "onto every page of a release is consistent, and consistency is all it sees.\n";
		exit($unverified ? 2 : 0);
	}

	echo "\nFindings (" . count($findings) . "):\n";
	foreach ($findings as $finding) {
		echo "  - {$finding}\n";
	}
	echo "\nEach finding needs a human decision. Nothing here was changed.\n";
	exit(2);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
