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

function refusal(string $versions, array $options = array()): ?string {
	try {
		check_inputs($versions, $options);
	} catch (InvalidArgumentException $error) {
		return $error->getMessage();
	}
	return null;
}

$x_y_0 = array('refuse-x-y-0' => false);

check('Versions are trimmed and kept in order', array('7.1.5', '7.0.6'), check_inputs(' 7.1.5 , 7.0.6', array()));
check('Duplicates are dropped', array('7.1.5', '7.0.6'), check_inputs('7.1.5,7.0.6,7.1.5', array()));
check('A trailing comma is ignored', array('7.1.5'), check_inputs('7.1.5,', array()));
check('X.Y is a version', array('7.1'), check_inputs('7.1', array()));
check('Two-digit parts are versions', array('10.0.12'), check_inputs('10.0.12', $x_y_0));
check('No versions', 'No versions given.', refusal(''));
check('Only spaces', 'No versions given.', refusal('  '));
check('An empty item', "Not an X.Y.Z or X.Y version: ''.", refusal('7.1.5,,7.0.6'));
check('A space inside a version', "Not an X.Y.Z or X.Y version: '7.1 1'.", refusal('7.1 1'));
check('A leading zero', "Not an X.Y.Z or X.Y version: '7.1.04'.", refusal('7.1.04'));
check('Shell text', "Not an X.Y.Z or X.Y version: '7.1.4;rm'.", refusal('7.1.4;rm'));
check('New lines between versions', 'Use commas, not new lines, between versions.', refusal("7.1.5\n7.0.6"));
check('X.Y.0 is refused with --refuse-x-y-0', true, str_starts_with((string) refusal('7.1.0', $x_y_0), '7.1.0 is an X.Y.0 release.'));
check('X.Y is refused with --refuse-x-y-0', true, str_starts_with((string) refusal('7.1', $x_y_0), '7.1 is an X.Y.0 release.'));
check('X.Y.0 is accepted without --refuse-x-y-0', array('7.1.0'), check_inputs('7.1.0', array()));

check('Valid wait inputs', null, check_wait_inputs('3h', '60s', array('max-timeout' => '3h', 'min-interval' => '1s', 'max-interval' => '3h')));
check('Timeout zero checks once', null, check_wait_inputs('0', '60s', array('max-timeout' => '3h', 'min-interval' => '1s')));
check('Bad timeout format', "Bad timeout: 'later'. Use a whole number with s, m or h.", (static function (): ?string {
	try {
		check_wait_inputs('later', '60s', array('max-timeout' => '3h'));
	} catch (InvalidArgumentException $error) {
		return $error->getMessage();
	}
	return null;
})());
check('Timeout over the job limit', 'The timeout 181m is longer than 3h, so the job would stop first.', (static function (): ?string {
	try {
		check_wait_inputs('181m', '60s', array('max-timeout' => '3h'));
	} catch (InvalidArgumentException $error) {
		return $error->getMessage();
	}
	return null;
})());
check('Bad interval format', "Bad interval: 'often'. Use a whole number with s, m or h.", (static function (): ?string {
	try {
		check_wait_inputs('3h', 'often', array('min-interval' => '1s'));
	} catch (InvalidArgumentException $error) {
		return $error->getMessage();
	}
	return null;
})());
check('Zero interval is refused', 'The interval must be at least 1s.', (static function (): ?string {
	try {
		check_wait_inputs('3h', '0', array('min-interval' => '1s'));
	} catch (InvalidArgumentException $error) {
		return $error->getMessage();
	}
	return null;
})());

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
