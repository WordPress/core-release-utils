#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for check-inputs.php. No network, no subprocesses. */

require dirname(__DIR__) . '/check-inputs.php';

$failures   = array();
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void {
	++$GLOBALS['assertions'];
	if ($expected !== $actual) {
		$GLOBALS['failures'][] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
	}
}

function refusal(string $versions, string $timeout = '', array $options = array()): ?string {
	try {
		check_inputs($versions, $timeout, $options);
	} catch (InvalidArgumentException $error) {
		return $error->getMessage();
	}
	return null;
}

$x_y_0 = array('refuse-x-y-0' => false);
$tags = array('min-timeout' => '1s', 'max-timeout' => '50m');
$localized = array('max-timeout' => '3h');

check('Versions are trimmed and kept in order', array('7.1.5', '7.0.6'), check_inputs(' 7.1.5 , 7.0.6', '', array()));
check('Duplicates are dropped', array('7.1.5', '7.0.6'), check_inputs('7.1.5,7.0.6,7.1.5', '', array()));
check('A trailing comma is ignored', array('7.1.5'), check_inputs('7.1.5,', '', array()));
check('X.Y is a version', array('7.1'), check_inputs('7.1', '', array()));
check('Two-digit parts are versions', array('10.0.12'), check_inputs('10.0.12', '', $x_y_0));
check('No versions', 'No versions given.', refusal(''));
check('Only spaces', 'No versions given.', refusal('  '));
check('An empty item', "Not an X.Y.Z or X.Y version: ''.", refusal('7.1.5,,7.0.6'));
check('A space inside a version', "Not an X.Y.Z or X.Y version: '7.1 1'.", refusal('7.1 1'));
check('A leading zero', "Not an X.Y.Z or X.Y version: '7.1.04'.", refusal('7.1.04'));
check('Shell text', "Not an X.Y.Z or X.Y version: '7.1.4;rm'.", refusal('7.1.4;rm'));
check('New lines between versions', 'Use commas, not new lines, between versions.', refusal("7.1.5\n7.0.6"));
check('X.Y.0 is refused with --refuse-x-y-0', true, str_starts_with((string) refusal('7.1.0', '', $x_y_0), '7.1.0 is an X.Y.0 release.'));
check('X.Y is refused with --refuse-x-y-0', true, str_starts_with((string) refusal('7.1', '', $x_y_0), '7.1 is an X.Y.0 release.'));
check('X.Y.0 is accepted without --refuse-x-y-0', array('7.1.0'), check_inputs('7.1.0', '', array()));

check('The timeout is not read without a limit', array('7.1.4'), check_inputs('7.1.4', 'junk', array()));
check('A timeout at the limit passes', array('7.1.4'), check_inputs('7.1.4', '50m', $tags));
check('Leading zeros in a timeout are decimal', array('7.1.4'), check_inputs('7.1.4', '08m', $tags));
check('A timeout in seconds without a unit', array('7.1.4'), check_inputs('7.1.4', '3000', $tags));
check('A timeout over the limit', 'The timeout 51m is longer than 50m, so the job would stop first.', refusal('7.1.4', '51m', $tags));
check('A timeout in hours over the limit', 'The timeout 4h is longer than 3h, so the job would stop first.', refusal('7.1.4', '4h', $localized));
check('A zero timeout under the minimum', 'The timeout must be at least 1s.', refusal('7.1.4', '0', $tags));
check('A zero timeout without a minimum', array('7.1.4'), check_inputs('7.1.4', '0', $localized));
check('A timeout that is not a duration', "Bad timeout: 'abc'. Use a whole number with s, m or h.", refusal('7.1.4', 'abc', $localized));
check('An empty timeout with a limit', "Bad timeout: ''. Use a whole number with s, m or h.", refusal('7.1.4', '', $localized));
check('A huge timeout is refused', "Bad timeout: '99999999999999999999h'. Use a whole number with s, m or h.", refusal('7.1.4', '99999999999999999999h', $localized));

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
