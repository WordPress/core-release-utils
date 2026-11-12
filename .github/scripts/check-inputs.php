#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Checks the release check workflow inputs before a tool runs.
 *
 * Reads $VERSIONS (comma-separated X.Y.Z or X.Y) and, with a timeout limit, $TIMEOUT.
 * On success, writes list=<JSON array> to $GITHUB_OUTPUT and VERSIONS=<clean list> to
 * $GITHUB_ENV. On failure, writes the reason to $GITHUB_STEP_SUMMARY and exits 1.
 */

require dirname(__DIR__, 2) . '/lib/lib.php';

/** Same format as the tools' --timeout: a whole number with s (default), m or h. */
function check_inputs_seconds(string $text): int {
	if (1 !== preg_match('/^(\d{1,9})([smh]?)$/', $text, $match)) {
		throw new InvalidArgumentException("Bad timeout: '{$text}'. Use a whole number with s, m or h.");
	}
	return (int) $match[1] * array('' => 1, 's' => 1, 'm' => 60, 'h' => 3600)[$match[2]];
}

/**
 * @param array{refuse-x-y-0?: false, max-timeout?: string, min-timeout?: string} $options As parse_cli_options() returns them.
 * @return list<string> The versions, trimmed and without duplicates.
 */
function check_inputs(string $versions, string $timeout, array $options): array {
	if (str_contains($versions, "\n")) {
		throw new InvalidArgumentException('Use commas, not new lines, between versions.');
	}
	if ('' === trim($versions)) {
		throw new InvalidArgumentException('No versions given.');
	}
	$items = explode(',', $versions);
	if ('' === trim(end($items))) {
		array_pop($items);
	}
	$list = array();
	foreach ($items as $item) {
		$version = trim($item);
		if (1 !== preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)(\.(0|[1-9]\d*))?$/', $version)) {
			throw new InvalidArgumentException("Not an X.Y.Z or X.Y version: '{$item}'.");
		}
		if (isset($options['refuse-x-y-0']) && 1 !== preg_match('/^\d+\.\d+\.[1-9]\d*$/', $version)) {
			throw new InvalidArgumentException("{$version} is an X.Y.0 release. package-smoke needs --previous for it, which this workflow does not pass yet. Run the tools locally.");
		}
		$list[] = $version;
	}

	if (isset($options['min-timeout']) || isset($options['max-timeout'])) {
		$seconds = check_inputs_seconds($timeout);
		if (isset($options['min-timeout']) && $seconds < check_inputs_seconds($options['min-timeout'])) {
			throw new InvalidArgumentException("The timeout must be at least {$options['min-timeout']}.");
		}
		if (isset($options['max-timeout']) && $seconds > check_inputs_seconds($options['max-timeout'])) {
			throw new InvalidArgumentException("The timeout {$timeout} is longer than {$options['max-timeout']}, so the job would stop first.");
		}
	}
	return array_values(array_unique($list));
}

function check_inputs_append(string $variable, string $line): void {
	$path = getenv($variable);
	if (false === $path || '' === $path || false === file_put_contents($path, $line . "\n", FILE_APPEND)) {
		fail("Unable to write to \${$variable}.");
	}
}

function check_inputs_main(array $argv): int {
	$options = cli_options($argv, array('refuse-x-y-0', 'max-timeout:', 'min-timeout:'));
	try {
		$list = check_inputs((string) getenv('VERSIONS'), (string) getenv('TIMEOUT'), $options);
	} catch (InvalidArgumentException $error) {
		echo '::error::' . $error->getMessage() . "\n";
		check_inputs_append('GITHUB_STEP_SUMMARY', 'Refused: ' . $error->getMessage());
		return 1;
	}
	check_inputs_append('GITHUB_OUTPUT', 'list=' . json_encode($list, JSON_THROW_ON_ERROR));
	check_inputs_append('GITHUB_ENV', 'VERSIONS=' . implode(',', $list));
	return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	exit(check_inputs_main($argv));
}
