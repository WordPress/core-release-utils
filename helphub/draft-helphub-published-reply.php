#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/lib.php';
require_once __DIR__ . '/lib/helphub-http-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/push-helphub-drafts-lib.php';
require_once __DIR__ . '/lib/draft-helphub-published-reply-lib.php';

/** Draft the "HelpHub pages are published" Slack reply, only once every page is live. */

$usage = '(--versions=<X.Y.Z,...> | --pages=<dir>) [--title=<text>] [--help]';
$options = cli_options($argv, array('versions:', 'pages:', 'title:', 'help'));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} {$usage}\n";
	exit(0);
}
$by_list = isset($options['versions']);
$by_dir  = isset($options['pages']);
if ($by_list === $by_dir || ($by_list && !is_string($options['versions'])) || ($by_dir && !is_string($options['pages']))
	|| (isset($options['title']) && !is_string($options['title']))) {
	fail("Usage: {$argv[0]} {$usage}\nName the versions with exactly one of --versions or --pages, each given once.", 2);
}

try {
	$title    = helphub_reply_title($options['title'] ?? null);
	$versions = helphub_published_versions($by_list
		? $options['versions']
		: implode(',', array_keys(helphub_load_pages($options['pages']))));
	if (HELPHUB_REPLY_MAX_VERSIONS < count($versions)) {
		throw new InvalidArgumentException('More than ' . HELPHUB_REPLY_MAX_VERSIONS . ' versions; split the reply.');
	}
	$url = helphub_rest_url('https://wordpress.org/documentation', null, array(
		'slug'     => implode(',', array_map('helphub_version_slug', $versions)),
		'status'   => 'publish',
		'per_page' => 100,
		'_fields'  => 'slug,link',
		'nocache'  => (string) time(),
	));
	$result = helphub_http_request($url);
	if (200 !== $result['code'] || !is_array($result['data']) || !array_is_list($result['data'])) {
		throw new RuntimeException("Version-page lookup failed (HTTP {$result['code']}).");
	}
	if (100 <= count($result['data'])) {
		throw new RuntimeException('Version-page lookup returned 100 results; refusing a possibly partial list.');
	}
	$public_status = static function (string $url): int {
		try {
			return helphub_http_request($url)['code'];
		} catch (RuntimeException $exception) {
			return 0;
		}
	};
	$problems = helphub_reply_problems($versions, $result['data'], $public_status);
	if ($problems) {
		fwrite(STDERR, sprintf("Not drafted: %d of %d page(s) are not live.\n", count($problems), count($versions)));
		foreach ($problems as $problem) {
			fwrite(STDERR, "  - {$problem}\n");
		}
		exit(2);
	}
	echo helphub_reply_body($versions, $title) . "\n";
	exit(0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
}
