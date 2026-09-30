#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check that the localized WordPress packages exist on downloads.wordpress.org after a core release.
 *
 * Usage:
 *   php verify-localized-packages.php --versions=7.1.2,7.0.6,6.9.9   poll until every package exists
 *
 * Options:
 *   --versions=X.Y.Z,...  the newest version on each branch in the release (required)
 *   --locales=it_IT,...   check these locales instead of the baseline; needed for an X.Y or X.Y.0 release
 *   --timeout=3h          give up after this long; 0 checks once
 *   --interval=5m         time between polls
 *   Durations: a whole number, with s (default), m or h. Examples: 90, 5m, 1h.
 *
 * Exit 0: every expected locale has a language pack and a localized zip for every version.
 * Exit 2: a file is missing or could not be checked. Report it in #meta-i18n.
 * Exit 1: the script broke, a version has no expected locales (check it, or pass --locales),
 *         or a lookup for the expected locales failed three times.
 *
 * A meta.wordpress.org job builds the files 1-3 hours after the en_US package, and it
 * fails silently for some locales. The expected locales for X.Y.Z are the locales on
 * the translations API that have a localized zip for an earlier version on the branch.
 * Locales below the translation thresholds never get a zip and are not expected. The
 * gate then HEADs translation/core/X.Y.Z/{locale}.zip and release/{locale}/wordpress-X.Y.Z.zip
 * until all return 200, and checks again only the files that are still missing.
 *
 * Do not change:
 *   - Accept a locale when any earlier version on the branch has a zip, not only the
 *     previous patch. A failed build can leave a patch missing for many locales.
 *   - Do not use the version-check API. It offers a localized package only for the
 *     newest release, and en_US for older branches.
 *   - Send a User-Agent.
 *
 * Needs PHP 8.1. Tests: php tests/verify-localized-packages-tests.php
 */

const DOWNLOADS        = 'https://downloads.wordpress.org/';
const TRANSLATIONS_API = 'https://api.wordpress.org/translations/core/1.0/?version=';
const LOCALE           = '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/';
const RETRIES          = 3;
const RETRY_DELAY      = 30;
const ROW              = "%-10s %-8s %-8s %s\n";
const RULE             = "------------------------------------------------------------------\n";

function fail(string $message, int $code = 1): never {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function parse_duration(string $text): int {
	if (1 !== preg_match('/^(\d{1,9})([smh]?)$/', $text, $match)) {
		throw new InvalidArgumentException("Bad duration: '{$text}'. Use a whole number with an optional unit: s (default), m, or h. Examples: 90, 90s, 5m, 1h.");
	}
	return (int) $match[1] * array('' => 1, 's' => 1, 'm' => 60, 'h' => 3600)[$match[2]];
}

function format_duration(float $seconds): string {
	$seconds = (int) $seconds;
	$hours   = intdiv($seconds, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	return ($hours ? "{$hours}h" : '') . ($hours || $minutes ? "{$minutes}m" : '') . ($seconds % 60) . 's';
}

/** Split a comma or whitespace list into unique items that match $pattern. */
function parse_list(string $option, string $text, string $pattern): array {
	$items = array_values(array_unique(preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY)));
	if (!$items) {
		throw new InvalidArgumentException("--{$option} is empty.");
	}
	foreach ($items as $item) {
		if (1 !== preg_match($pattern, $item)) {
			throw new InvalidArgumentException("Bad --{$option} item: '{$item}'.");
		}
	}
	return $items;
}

function parse_versions(string $text): array {
	return parse_list('versions', $text, '/^\d+\.\d+(\.\d+)?$/');
}

function parse_locales(string $text): array {
	return parse_list('locales', $text, LOCALE);
}

/** Every earlier version on the branch: X.Y, then the patches from newest to oldest. */
function earlier_versions(string $version): array {
	$parts = explode('.', $version);
	if (!isset($parts[2]) || '0' === $parts[2]) {
		throw new InvalidArgumentException("{$version} has no earlier version on its branch. Pass --locales.");
	}
	$branch   = "{$parts[0]}.{$parts[1]}";
	$versions = array($branch);
	for ($patch = (int) $parts[2] - 1; $patch > 0; --$patch) {
		$versions[] = "{$branch}.{$patch}";
	}
	return $versions;
}

function pack_url(string $locale, string $version): string {
	return DOWNLOADS . "translation/core/{$version}/{$locale}.zip";
}

function zip_url(string $locale, string $version): string {
	return DOWNLOADS . "release/{$locale}/wordpress-{$version}.zip";
}

/**
 * Send one request. Returns array(status, body).
 * The transport is injectable so the tests run without a network.
 */
function http_request(string $url, string $method, ?callable $transport = null): array {
	$transport ??= static function (string $request_url, array $options): array {
		$handle = fopen($request_url, 'r', false, stream_context_create($options));
		if (false === $handle) {
			return array('body' => false, 'headers' => array());
		}
		$headers = stream_get_meta_data($handle)['wrapper_data'];
		$body    = stream_get_contents($handle);
		fclose($handle);
		return array('body' => $body, 'headers' => $headers);
	};
	$options  = array(
		'http' => array(
			'method'          => $method,
			'header'          => "User-Agent: verify-localized-packages\r\n",
			'timeout'         => 60,
			'ignore_errors'   => true,
			'follow_location' => 0,
		),
	);
	$response = $transport($url, $options);
	$status   = 1 === preg_match('#^HTTP/\S+ (\d+)#', $response['headers'][0] ?? '', $match) ? (int) $match[1] : 0;
	return array($status, $response['body']);
}

/** True on 200, false on 404. Anything else is not an answer. */
function url_exists(string $url, ?callable $transport = null): bool {
	[$status] = http_request($url, 'HEAD', $transport);
	return match ($status) {
		200     => true,
		404     => false,
		default => throw new RuntimeException("HEAD {$url} returned HTTP {$status}."),
	};
}

/** Every valid locale the translations API lists for $version. */
function candidate_locales(string $version, ?callable $transport = null): array {
	$url            = TRANSLATIONS_API . rawurlencode($version);
	[$status, $body] = http_request($url, 'GET', $transport);
	$data           = 200 === $status ? json_decode((string) $body, true) : null;
	$locales        = array_values(array_filter(array_column($data['translations'] ?? array(), 'language'), static fn(mixed $locale): bool => is_string($locale) && 1 === preg_match(LOCALE, $locale)));
	if (!$locales) {
		throw new RuntimeException("{$url} returned HTTP {$status} without a list of translations.");
	}
	return $locales;
}

/** Call $check, and try again after an error, so a brief outage before the gate starts does not end the run. */
function with_retries(callable $check, callable $sleep): mixed {
	for ($try = 1; ; ++$try) {
		try {
			return $check();
		} catch (RuntimeException $error) {
			if ($try >= RETRIES) {
				throw $error;
			}
			$sleep(RETRY_DELAY);
		}
	}
}

/** The candidates that have a localized zip for an earlier version on the branch of $version. */
function baseline(string $version, array $candidates, callable $exists): array {
	$earlier = earlier_versions($version);
	return array_values(array_filter($candidates, static function (string $locale) use ($earlier, $exists): bool {
		foreach ($earlier as $version) {
			if ($exists(zip_url($locale, $version))) {
				return true;
			}
		}
		return false;
	}));
}

/**
 * Poll until every file exists or the timeout ends. $targets maps version => locales.
 * Returns version => locale => array('pack' => status, 'zip' => status), where status is
 * 'yes', 'MISSING' or 'ERROR'. Only files that are not 'yes' are checked again.
 * $io carries the existence check, clock, sleeper and progress printer so the tests can stub them.
 */
function gate(array $targets, int $timeout, int $interval, array $io): array {
	$io     += array('now' => static fn(): float => microtime(true), 'sleep' => 'sleep', 'progress' => static function (string $line): void {
		echo $line, "\n";
	});
	$started = $io['now']();
	$state   = array();
	foreach ($targets as $version => $locales) {
		foreach ($locales as $locale) {
			$state[$version][$locale] = array('pack' => 'MISSING', 'zip' => 'MISSING');
		}
	}
	while (true) {
		$missing = 0;
		foreach ($state as $version => $rows) {
			foreach ($rows as $locale => $row) {
				$urls = array('pack' => pack_url((string) $locale, (string) $version), 'zip' => zip_url((string) $locale, (string) $version));
				foreach ($urls as $kind => $url) {
					if ('yes' === $row[$kind]) {
						continue;
					}
					try {
						$status = $io['exists']($url) ? 'yes' : 'MISSING';
					} catch (RuntimeException $error) {
						$status = 'ERROR';
						$io['progress']("RETRY {$error->getMessage()}");
					}
					$state[$version][$locale][$kind] = $status;
					$missing                        += 'yes' === $status ? 0 : 1;
				}
			}
		}
		$elapsed = $io['now']() - $started;
		if (!$missing || $elapsed >= $timeout) {
			return $state;
		}
		$wait = min($interval, max(1, (int) ceil($timeout - $elapsed)));
		$io['progress']('+' . format_duration($elapsed) . ": {$missing} files missing, next check in " . format_duration($wait) . '.');
		$io['sleep']($wait);
	}
}

/** The result lines for the final state of gate(). Returns array(passed, lines). */
function result(array $state): array {
	$total    = 0;
	$problems = array();
	foreach ($state as $version => $rows) {
		foreach ($rows as $locale => $row) {
			foreach ($row as $kind => $status) {
				++$total;
				if ('yes' !== $status) {
					$problems[$version][$kind][$status][] = $locale;
				}
			}
		}
	}
	$bad = 0;
	foreach ($problems as $kinds) {
		foreach ($kinds as $statuses) {
			foreach ($statuses as $locales) {
				$bad += count($locales);
			}
		}
	}
	if (!$bad) {
		return array(true, array("Result: PASS — all {$total} files exist."));
	}
	$lines = array("Result: FAIL — {$bad} of {$total} files are missing or could not be checked.");
	$names = array('pack' => 'language pack', 'zip' => 'localized zip');
	foreach ($problems as $version => $kinds) {
		foreach ($kinds as $kind => $statuses) {
			foreach ($statuses as $status => $locales) {
				$lines[] = "  {$version} {$names[$kind]} " . ('ERROR' === $status ? 'could not be checked' : 'missing') . ': ' . implode(', ', $locales);
			}
		}
	}
	$lines[] = 'Report the missing files in #meta-i18n.';
	return array(false, $lines);
}

function main(array $argv, array $io = array()): int {
	$usage     = "Usage: {$argv[0]} --versions=<X.Y.Z,...> [--locales=<xx_XX,...>] [--timeout=3h] [--interval=5m]";
	$options   = array('versions' => null, 'locales' => null, 'timeout' => '3h', 'interval' => '5m');
	$transport = $io['transport'] ?? null;
	foreach (array_slice($argv, 1) as $arg) {
		if ('--help' === $arg) {
			echo $usage, "\n";
			return 0;
		}
		if (1 === preg_match('/^--(versions|locales|timeout|interval)=(.*)$/', $arg, $match)) {
			$options[$match[1]] = $match[2];
		} else {
			fail("Unknown argument: {$arg}\n{$usage}");
		}
	}
	if (null === $options['versions']) {
		fail($usage);
	}
	$versions = parse_versions($options['versions']);
	$locales  = null === $options['locales'] ? null : parse_locales($options['locales']);
	$timeout  = parse_duration($options['timeout']);
	$interval = parse_duration($options['interval']);
	if ($interval < 1) {
		throw new InvalidArgumentException('--interval must be at least 1s.');
	}
	if (null === $locales) {
		array_map('earlier_versions', $versions);
	}
	if (null === $transport && !ini_get('allow_url_fopen')) {
		throw new RuntimeException('allow_url_fopen is off; the HTTP checks need it.');
	}
	// Stream warnings become exceptions so a refused connection is retried by
	// the gate instead of printed between report lines.
	set_error_handler(static function (int $severity, string $message): never {
		throw new RuntimeException($message);
	}, E_WARNING);

	$sleep   = $io['sleep'] ?? 'sleep';
	$exists  = static fn(string $url): bool => url_exists($url, $transport);
	$targets = array();
	echo "Localized packages gate: {$options['versions']}\n" . RULE;
	foreach ($versions as $version) {
		if (null !== $locales) {
			$targets[$version] = $locales;
			continue;
		}
		$candidates        = with_retries(static fn(): array => candidate_locales($version, $transport), $sleep);
		$targets[$version] = baseline($version, $candidates, static fn(string $url): bool => with_retries(static fn(): bool => $exists($url), $sleep));
		if (!$targets[$version]) {
			throw new InvalidArgumentException("{$version}: no locale has a localized zip for an earlier " . earlier_versions($version)[0] . ' version. Check the version, or pass --locales.');
		}
		$skipped           = array_diff($candidates, $targets[$version]);
		echo "{$version}: " . count($targets[$version]) . ' locales expected, with a zip for an earlier ' . earlier_versions($version)[0] . ' version. '
			. count($skipped) . ' not expected' . ($skipped ? ': ' . implode(', ', $skipped) : '') . ".\n";
	}

	$state = gate($targets, $timeout, $interval, $io + array('exists' => $exists));
	echo RULE;
	printf(ROW, 'Locale', 'Version', 'Pack', 'Zip');
	foreach ($state as $version => $rows) {
		foreach ($rows as $locale => $row) {
			printf(ROW, $locale, $version, $row['pack'], $row['zip']);
		}
	}
	[$passed, $lines] = result($state);
	echo RULE . implode("\n", $lines) . "\n";
	return $passed ? 0 : 2;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	try {
		exit(main($argv));
	} catch (Throwable $error) {
		fail($error->getMessage());
	}
}
