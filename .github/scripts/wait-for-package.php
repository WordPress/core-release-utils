#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/lib/lib.php';

function wait_for_package_duration(string $text): int {
	if (1 !== preg_match('/^(\d{1,9})([smh]?)$/', $text, $match)) {
		throw new InvalidArgumentException("Bad duration: '{$text}'. Use a whole number with an optional unit: s (default), m, or h.");
	}
	return (int) $match[1] * array('' => 1, 's' => 1, 'm' => 60, 'h' => 3600)[$match[2]];
}

function wait_for_package_url(string $version): string {
	return "https://wordpress.org/wordpress-{$version}.zip";
}

/** Return null when the zip is ready, or the URL and result that should be retried. */
function check_package_ready(string $version, string $directory, callable $fetch): ?array {
	$zip_url = wait_for_package_url($version);
	$md5_url = $zip_url . '.md5';
	$zip      = $directory . '/wordpress.zip';
	$md5      = $directory . '/wordpress.zip.md5';
	foreach (array($zip, $md5) as $path) {
		if (is_file($path)) {
			unlink($path);
		}
	}
	foreach (array($zip_url => $zip, $md5_url => $md5) as $url => $destination) {
		try {
			$fetch($url, $destination);
		} catch (RuntimeException $error) {
			return array($url, $error->getMessage());
		}
	}
	$published = @file_get_contents($md5);
	if (false === $published || 1 !== preg_match('/^\s*([a-f0-9]{32})(?:\s|$)/i', $published, $match)) {
		return array($md5_url, 'the published md5 was missing or invalid');
	}
	$downloaded = md5_file($zip);
	if (false === $downloaded) {
		return array($zip_url, 'the downloaded zip could not be hashed');
	}
	if (strtolower($match[1]) !== strtolower($downloaded)) {
		return array($zip_url, "md5 mismatch: downloaded {$downloaded}, published " . strtolower($match[1]));
	}
	return null;
}

function wait_for_package(string $version, int $timeout, int $interval, array $io = array()): void {
	$io += array(
		'fetch'    => static fn(string $url, string $destination): mixed => copy_package($url, $destination, 1, 0),
		'now'      => static fn(): float => microtime(true),
		'sleep'    => 'sleep',
		'progress' => static fn(string $line): int => print($line . "\n"),
	);
	$directory = temporary_worktree('wait-for-package');
	$started   = $io['now']();
	try {
		while (true) {
			$result = check_package_ready($version, $directory, $io['fetch']);
			if (null === $result) {
				$io['progress']("Package {$version} is ready: " . wait_for_package_url($version));
				return;
			}
			$elapsed = $io['now']() - $started;
			if ($elapsed >= $timeout) {
				throw new RuntimeException("Package {$version} was not ready at {$result[0]}. Last result: {$result[1]}.");
			}
			$wait = min($interval, max(1, (int) ceil($timeout - $elapsed)));
			$io['progress']("Package {$version} is not ready at {$result[0]}: {$result[1]}. Retrying in {$wait}s.");
			$io['sleep']($wait);
		}
	} finally {
		remove_temporary_directory($directory, 'wait-for-package-');
	}
}

function wait_for_package_main(array $argv): int {
	$options = parse_cli_options($argv, array('version:', 'timeout:', 'interval:'));
	$version = $options['version'] ?? '';
	if (1 !== preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version)) {
		throw new InvalidArgumentException("Bad version: '{$version}'. Use X.Y.Z.");
	}
	$timeout  = wait_for_package_duration($options['timeout'] ?? '3h');
	$interval = wait_for_package_duration($options['interval'] ?? '60s');
	if ($interval < 1) {
		throw new InvalidArgumentException('--interval must be at least 1s.');
	}
	wait_for_package($version, $timeout, $interval);
	return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	try {
		exit(wait_for_package_main($argv));
	} catch (Throwable $error) {
		fail($error->getMessage());
	}
}
