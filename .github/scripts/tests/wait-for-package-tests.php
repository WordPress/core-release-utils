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

function package_fetcher(array $results, array &$trace): callable {
	$md5_suffix = '.md5';
	return static function (string $url, string $destination) use (&$results, &$trace, $md5_suffix): void {
		if (++$trace['calls'] > 20) {
			throw new LogicException('The fake fetcher was called more than 20 times.');
		}
		$is_md5 = str_ends_with($url, $md5_suffix);
		if ($is_md5) {
			++$trace['attempts'];
		} else {
			++$trace['zip_fetches'];
		}
		$result = $results[$trace['attempts'] - 1] ?? end($results);
		if ('404' === $result && $is_md5) {
			throw new RuntimeException('HTTP 404');
		}
		if ('badmd5' === $result && $is_md5) {
			file_put_contents($destination, "not a checksum\n");
			return;
		}
		file_put_contents($destination, $is_md5 ? md5('release zip') . "\n" : ('mismatch' === $result ? 'old zip' : 'release zip'));
	};
}

function run_wait(array $results, int $timeout = 120, int $interval = 60, mixed &$trace = null): array {
	$clock = 0;
	$lines = array();
	$error = null;
	$trace = array('calls' => 0, 'attempts' => 0, 'zip_fetches' => 0, 'sleeps' => array(), 'clock_reads' => 0);
	try {
		wait_for_package('7.1.5', $timeout, $interval, array(
			'fetch'    => package_fetcher($results, $trace),
			'now'      => static function () use (&$clock, &$trace): float {
				if (++$trace['clock_reads'] > 20) {
					throw new LogicException('The fake clock was read more than 20 times.');
				}
				return (float) $clock;
			},
			'sleep'    => static function (int $seconds) use (&$clock, &$trace): void {
				$trace['sleeps'][] = $seconds;
				$clock += $seconds;
			},
			'progress' => static function (string $line) use (&$lines): void {
				$lines[] = $line;
			},
		));
	} catch (RuntimeException $caught) {
		$error = $caught->getMessage();
	}
	return array($trace['attempts'], $lines, $error);
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

$uneven = run_wait(array('404'), 90, 60, $uneven_trace);
check('A timeout that is not a multiple of the interval ends with a shorter sleep', array(60, 30), $uneven_trace['sleeps']);
$bad_md5 = run_wait(array('badmd5', 'ready'), 120, 60, $bad_md5_trace);
check('A published md5 that is not 32 hex characters is retried without fetching the zip', array(2, 1, null, true), array($bad_md5[0], $bad_md5_trace['zip_fetches'], $bad_md5[2], str_contains($bad_md5[1][0], 'the published md5 was missing or invalid')));

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
