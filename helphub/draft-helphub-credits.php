#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/helphub-manifest-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';

/** Draft the credits block a release's version pages need, from its advisories. */

function helphub_credits_usage(string $script): never {
	fail(
		"Usage: {$script} --manifest=<helphub-manifest-X.Y.Z.json> [--advisory-repo=<owner/name>] [--verbose] [--help]"
	);
}

/** Read one advisory's summary. */
function helphub_advisory_summary(string $repository, string $ghsa): ?string {
	$result = run_command(
		array('gh', 'api', "repos/{$repository}/security-advisories/{$ghsa}", '--jq', '.summary'),
		null,
		true
	);
	if (0 !== $result['code']) {
		return null;
	}
	$summary = trim($result['stdout']);

	return '' === $summary || 'null' === $summary ? null : $summary;
}

$options = cli_options($argv, array('manifest:', 'advisory-repo:', 'verbose', 'help'));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --manifest=<helphub-manifest-X.Y.Z.json> [--advisory-repo=<owner/name>] [--verbose] [--help]\n";
	exit(0);
}
set_verbose(isset($options['verbose']));
if (!isset($options['manifest']) || !is_string($options['manifest']) || '' === $options['manifest']) {
	helphub_credits_usage($argv[0]);
}
$repository = $options['advisory-repo'] ?? 'WordPress/wordpress-develop';
if (!is_string($repository) || 1 !== preg_match('~\A[^/\s]+/[^/\s]+\z~', $repository)) {
	fail("--advisory-repo must be in owner/name form: {$repository}", 2);
}

try {
	verbose_log('Drafting HelpHub credit lines from advisories');
	$manifest_json = @file_get_contents($options['manifest']);
	if (false === $manifest_json) {
		throw new InvalidArgumentException("Unable to read manifest: {$options['manifest']}");
	}
	$manifest = helphub_manifest_decode($manifest_json);

	$entries    = array();
	$no_advisory = array();
	$unreadable = array();
	foreach (array_keys($manifest['fixes']) as $number) {
		$ghsa = $manifest['advisories'][$number] ?? null;
		if (null === $ghsa) {
			$no_advisory[] = $number;
			$entries[$number] = sprintf(
				'<!-- FILL IN: description and attribution for fix #%d; no advisory recorded in the manifest -->',
				$number
			);
			continue;
		}
		verbose_log("Reading {$ghsa}");
		$summary = helphub_advisory_summary($repository, $ghsa);
		if (null === $summary) {
			$unreadable[] = "#{$number} ({$ghsa})";
			$entries[$number] = sprintf(
				'<!-- FILL IN: %s could not be read; description and attribution for fix #%d -->',
				$ghsa,
				$number
			);
			continue;
		}
		$entries[$number] = helphub_credit_opening($summary)
			. ' <!-- FILL IN: reported by ... -->';
	}

	echo "Credit lines for WordPress {$manifest['release']}\n";
	echo str_repeat('-', 60) . "\n";
	echo "Paste this into the scope's credits, replace each FILL IN with the reporter\n";
	echo "attribution as it should read on the page, then export the manifest again.\n\n";

	$lines = array();
	foreach ($entries as $number => $credit) {
		$lines[] = sprintf('    "%d": %s', $number, json_encode($credit, JSON_UNESCAPED_SLASHES));
	}
	echo "  \"credits\": {\n" . implode(",\n", $lines) . "\n  }\n";

	$attention = array();
	if ($no_advisory) {
		$attention[] = 'No advisory recorded for fix(es) ' . implode(', ', $no_advisory)
			. '. Record each identifier in the scope, export the manifest again, then re-run.';
	}
	if ($unreadable) {
		$attention[] = 'Could not read advisory for ' . implode(', ', $unreadable)
			. '. Check the identifier and that the account can see a draft advisory.';
	}

	echo "\nEvery line still needs its attribution written. Nothing here invents one:\n";
	echo "whether reporters worked as a team or found an issue independently is a\n";
	echo "claim about people, and the advisories do not record it.\n";

	if ($attention) {
		echo "\nNeeds attention (" . count($attention) . "):\n";
		foreach ($attention as $note) {
			echo "  - {$note}\n";
		}
		exit(2);
	}
	exit(0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
