#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for wait-for-package.php. No network or subprocesses. */

require dirname(__DIR__) . '/wait-for-package.php';

$failures   = array();
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void {
	++$GLOBALS['assertions'];
	if ($expected !== $actual) {
		$GLOBALS['failures'][] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
	}
}

function package_fetcher(array $results, int &$attempts): callable {
	$zip_url = wait_for_package_url('7.1.5');
	return static function (string $url, string $destination) use (&$results, &$attempts, $zip_url): void {
		$result = $results[$attempts] ?? end($results);
		if ($url === $zip_url) {
			++$attempts;
		}
		if ('404' === $result) {
			throw new RuntimeException('HTTP 404');
		}
		$content = 'mismatch' === $result ? 'old zip' : 'release zip';
		file_put_contents($destination, str_ends_with($url, '.md5') ? md5('release zip') . "\n" : $content);
	};
}

function run_wait(array $results, int $timeout = 120): array {
	$clock    = 0;
	$attempts = 0;
	$lines    = array();
	$error    = null;
	try {
		wait_for_package('7.1.5', $timeout, 60, array(
			'fetch'    => package_fetcher($results, $attempts),
			'now'      => static function () use (&$clock): float {
				return (float) $clock;
			},
			'sleep'    => static function (int $seconds) use (&$clock): void {
				$clock += $seconds;
			},
			'progress' => static function (string $line) use (&$lines): void {
				$lines[] = $line;
			},
		));
	} catch (RuntimeException $caught) {
		$error = $caught->getMessage();
	}
	return array($attempts, $lines, $error);
}

$ready = run_wait(array('ready'));
check('Ready on the first try', array(1, null), array($ready[0], $ready[2]));
$after_404 = run_wait(array('404', 'ready'));
check('A 404 is retried until ready', array(2, null, true), array($after_404[0], $after_404[2], str_contains($after_404[1][0], 'HTTP 404')));
$after_mismatch = run_wait(array('mismatch', 'ready'));
check('An md5 mismatch is retried until ready', array(2, null, true), array($after_mismatch[0], $after_mismatch[2], str_contains($after_mismatch[1][0], 'md5 mismatch')));
$timeout = run_wait(array('404'), 120);
check('A timeout names the version URL and last result', array(3, true, true, true), array($timeout[0], str_contains((string) $timeout[2], '7.1.5'), str_contains((string) $timeout[2], wait_for_package_url('7.1.5')), str_contains((string) $timeout[2], 'Last result: HTTP 404')));
$once = run_wait(array('404'), 0);
check('Timeout zero checks once', array(1, 0, true), array($once[0], count($once[1]), null !== $once[2]));

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
