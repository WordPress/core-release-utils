#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for verify-localized-packages.php. No network. */

require dirname(__DIR__) . '/verify-localized-packages.php';

$failures   = array();
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void {
	++$GLOBALS['assertions'];
	if ($expected !== $actual) {
		$GLOBALS['failures'][] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
	}
}

function check_throws(string $label, string $class, callable $callback): void {
	++$GLOBALS['assertions'];
	try {
		$callback();
		$GLOBALS['failures'][] = "{$label}: expected {$class}";
	} catch (Throwable $error) {
		if (!$error instanceof $class) {
			$GLOBALS['failures'][] = "{$label}: expected {$class}, got " . $error::class;
		}
	}
}

/** A transport that answers 200 for the URLs in $present, 404 for the rest, and $translations for the API. */
function transport(array $present, array $translations = array()): callable {
	return static function (string $url, array $options) use ($present, $translations): array {
		if (str_starts_with($url, TRANSLATIONS_API)) {
			$body = json_encode(array('translations' => array_map(static fn(string $locale): array => array('language' => $locale), $translations)));
			return array('body' => $body, 'headers' => array('HTTP/1.1 200 OK'));
		}
		$status = in_array($url, $present, true) ? '200 OK' : '404 Not Found';
		return array('body' => '', 'headers' => array("HTTP/1.1 {$status}"));
	};
}

/** Every file for $locales at $version. */
function files(array $locales, string $version): array {
	$urls = array();
	foreach ($locales as $locale) {
		$urls[] = pack_url($locale, $version);
		$urls[] = zip_url($locale, $version);
	}
	return $urls;
}

/** Run main() with a stub transport and a fake clock. Returns array(exit code, output). */
function run_main(array $args, callable $transport): array {
	$clock = 0;
	ob_start();
	$code = main(array_merge(array('verify-localized-packages.php'), $args), array(
		'transport' => $transport,
		'now'       => static function () use (&$clock): float {
			return (float) $clock;
		},
		'sleep'     => static function (int $seconds) use (&$clock): void {
			$clock += $seconds;
		},
	));
	restore_error_handler();
	return array($code, ob_get_clean());
}

// Options.
check('minutes', 300, parse_duration('5m'));
check('hours', 10800, parse_duration('3h'));
check_throws('bad duration', InvalidArgumentException::class, static fn() => parse_duration('abc'));
check('hours keep zero minutes', '1h0m1s', format_duration(3601));
check('versions split and deduplicate', array('7.1.2', '7.0.6'), parse_versions('7.1.2, 7.0.6 7.1.2'));
check_throws('versions reject a path', InvalidArgumentException::class, static fn() => parse_versions('7.1.2/../x'));
check_throws('versions reject empty', InvalidArgumentException::class, static fn() => parse_versions(', '));
check('locales accept the usual forms', array('it_IT', 'de_DE_formal', 'zh_TW', 'fr', 'pt_PT_ao90'), parse_locales('it_IT,de_DE_formal,zh_TW,fr,pt_PT_ao90'));
check_throws('locales reject a path', InvalidArgumentException::class, static fn() => parse_locales('../it_IT'));

// Baseline.
check('first patch compares with the branch', array('7.1'), earlier_versions('7.1.1'));
check('later patches compare with the branch and every earlier patch, newest first', array('6.9', '6.9.8', '6.9.7', '6.9.6', '6.9.5', '6.9.4', '6.9.3', '6.9.2', '6.9.1'), earlier_versions('6.9.9'));
check_throws('a major release has no earlier version', InvalidArgumentException::class, static fn() => earlier_versions('7.1'));
check_throws('an X.Y.0 release has no earlier version', InvalidArgumentException::class, static fn() => earlier_versions('7.1.0'));
check('urls', array('https://downloads.wordpress.org/translation/core/7.1.2/it_IT.zip', 'https://downloads.wordpress.org/release/it_IT/wordpress-7.1.2.zip'), files(array('it_IT'), '7.1.2'));
$present = array(zip_url('it_IT', '7.1'), zip_url('fr_FR', '7.1.2'), zip_url('ar', '7.1.1'));
$exists  = static fn(string $url): bool => url_exists($url, transport($present));
check('a zip for 7.1 or any earlier patch makes a locale expected; no earlier zip does not', array('it_IT', 'fr_FR', 'ar'), baseline('7.1.3', array('it_IT', 'fr_FR', 'ar', 'bg_BG'), $exists));
check('candidates come from the translations API', array('it_IT', 'bg_BG'), candidate_locales('7.1.2', transport(array(), array('it_IT', 'bg_BG'))));
$odd = static fn(): array => array('body' => '{"translations":[{"language":"it_IT"},{"language":"../x"},{"language":5},{}]}', 'headers' => array('HTTP/1.1 200 OK'));
check('candidates drop values that are not locales', array('it_IT'), candidate_locales('7.1.2', $odd));
check_throws('an empty translations list is not an answer', RuntimeException::class, static fn() => candidate_locales('7.1.2', transport(array())));

// HTTP.
$status = static fn(string $line): callable => static fn(string $url, array $options): array => array('body' => '', 'headers' => array($line));
check('200 exists', true, url_exists('https://x/', $status('HTTP/1.1 200 OK')));
check('404 does not exist', false, url_exists('https://x/', $status('HTTP/1.1 404 Not Found')));
check_throws('503 is not an answer', RuntimeException::class, static fn() => url_exists('https://x/', $status('HTTP/1.1 503 X')));
check_throws('no response is not an answer', RuntimeException::class, static fn() => url_exists('https://x/', static fn(): array => array('body' => false, 'headers' => array())));
$sent = null;
url_exists('https://x/', static function (string $url, array $options) use (&$sent): array {
	$sent = $options['http'];
	return array('body' => '', 'headers' => array('HTTP/1.1 200 OK'));
});
check('sends HEAD with a User-Agent', array('HEAD', true), array($sent['method'], str_contains($sent['header'], 'User-Agent:')));

// Gate.
$baseline = array_merge(files(array('it_IT', 'fr_FR', 'ar'), '7.1'), array(zip_url('bg_BG', '7.0')));
$api      = array('it_IT', 'fr_FR', 'ar', 'bg_BG');
[$code, $output] = run_main(array('--versions=7.1.2', '--timeout=0'), transport(array_merge($baseline, files(array('it_IT', 'fr_FR', 'ar'), '7.1.2')), $api));
check('all present exits 0', 0, $code);
check('all present passes', true, str_contains($output, 'Result: PASS — all 6 files exist.'));
check('a locale without an earlier zip is listed as not expected', true, str_contains($output, '1 not expected: bg_BG.'));

[$code, $output] = run_main(array('--versions=7.1.2', '--timeout=0'), transport(array_merge($baseline, files(array('it_IT', 'fr_FR'), '7.1.2'), array(pack_url('ar', '7.1.2'))), $api));
check('zip missing exits 2', 2, $code);
check('zip missing names the locale', true, str_contains($output, "  7.1.2 localized zip missing: ar\nReport the missing files in #meta-i18n."));
check('zip missing shows the row', true, str_contains($output, sprintf(ROW, 'ar', '7.1.2', 'yes', 'MISSING')));

[$code, $output] = run_main(array('--versions=7.1.2', '--timeout=0'), transport(array_merge($baseline, files(array('it_IT', 'fr_FR'), '7.1.2'), array(zip_url('ar', '7.1.2'))), $api));
check('pack missing exits 2', 2, $code);
check('pack missing names the locale', true, str_contains($output, '  7.1.2 language pack missing: ar'));

[$code, $output] = run_main(array('--versions=7.1.2', '--locales=bg_BG', '--timeout=0'), transport(files(array('bg_BG'), '7.1.2')));
check('--locales overrides the baseline', array(0, true, false), array($code, str_contains($output, sprintf(ROW, 'bg_BG', '7.1.2', 'yes', 'yes')), str_contains($output, 'it_IT')));

[$code, $output] = run_main(array('--versions=7.1.2', '--locales=it_IT', '--timeout=15m', '--interval=5m'), transport(array()));
check('timeout exits 2', 2, $code);
check('timeout polls until the end', 3, substr_count($output, 'files missing, next check in 5m0s.'));

[$code, $output] = run_main(array('--versions=7.1.2', '--locales=it_IT', '--timeout=1m', '--interval=5m'), transport(array()));
check('the last wait stops at the timeout', array(2, 1, 1), array($code, substr_count($output, 'files missing, next check in'), substr_count($output, 'next check in 1m0s.')));

$flaky = static function (int $failures, callable $inner): callable {
	return static function (string $url, array $options) use (&$failures, $inner): array {
		if (str_starts_with($url, DOWNLOADS . 'release/') && $failures-- > 0) {
			return array('body' => '', 'headers' => array('HTTP/1.1 503 Service Unavailable'));
		}
		return $inner($url, $options);
	};
};
[$code, $output] = run_main(array('--versions=7.1.2', '--timeout=0'), $flaky(2, transport(array_merge($baseline, files(array('it_IT', 'fr_FR', 'ar'), '7.1.2')), $api)));
check('baseline retries a brief outage', array(0, true), array($code, str_contains($output, 'Result: PASS')));
check_throws('baseline gives up after three failures', RuntimeException::class, static fn() => run_main(array('--versions=7.1.2', '--timeout=0'), $flaky(3, transport($baseline, $api))));
restore_error_handler();
ob_end_clean();

try {
	run_main(array('--versions=99.1.1', '--timeout=0'), transport(array(), $api));
	$message = 'no error';
} catch (InvalidArgumentException $error) {
	$message = $error->getMessage();
	restore_error_handler();
	ob_end_clean();
}
check('an empty baseline is an error that names the version and --locales, not a PASS', array(true, true), array(str_contains($message, '99.1.1'), str_contains($message, '--locales')));
check_throws('an empty baseline for one version fails a mixed run', InvalidArgumentException::class, static fn() => run_main(array('--versions=7.1.2,99.1.1', '--timeout=0'), transport(array_merge($baseline, files(array('it_IT', 'fr_FR', 'ar'), '7.1.2')), $api)));
restore_error_handler();
ob_end_clean();

$called = false;
check_throws('a major release without --locales fails before any request', InvalidArgumentException::class, static function () use (&$called): void {
	main(array('x', '--versions=7.1.2,7.2'), array('transport' => static function () use (&$called): array {
		$called = true;
		return array('body' => '', 'headers' => array('HTTP/1.1 200 OK'));
	}));
});
check('no request was sent', false, $called);

$calls = array();
$state = gate(array('7.1.2' => array('it_IT')), 600, 60, array(
	'now'      => static function () use (&$calls): float {
		return (float) count($calls) * 30;
	},
	'sleep'    => static function (): void {
	},
	'progress' => static function (): void {
	},
	'exists'   => static function (string $url) use (&$calls): bool {
		$calls[] = $url;
		if (count($calls) === 1) {
			throw new RuntimeException('down');
		}
		return count($calls) > 2;
	},
));
check('gate retries errors and checks only missing files again', array(pack_url('it_IT', '7.1.2'), zip_url('it_IT', '7.1.2'), pack_url('it_IT', '7.1.2'), zip_url('it_IT', '7.1.2')), $calls);
check('gate ends when every file exists', array('7.1.2' => array('it_IT' => array('pack' => 'yes', 'zip' => 'yes'))), $state);
check('an error at the end is reported apart', array(false, array('Result: FAIL — 1 of 2 files are missing or could not be checked.', '  7.1.2 localized zip could not be checked: it_IT', 'Report the missing files in #meta-i18n.')), result(array('7.1.2' => array('it_IT' => array('pack' => 'yes', 'zip' => 'ERROR')))));

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}
echo "Passed {$assertions} assertions.\n";
