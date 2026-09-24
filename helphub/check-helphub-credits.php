#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/helphub-manifest-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/check-helphub-index-lib.php';
require_once __DIR__ . '/lib/helphub-http-lib.php';
require_once __DIR__ . '/lib/push-helphub-drafts-lib.php';
require_once __DIR__ . '/lib/check-helphub-credits-lib.php';

/** Compare HelpHub credits or news-post reporters and advisories against the manifest. */

function helphub_credits_usage(string $script): never {
	fail(
		"Usage: {$script} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> [--site=<url>] [--user=<login>] "
		. '[--only=<X.Y.Z>] [--news-post=<URL or file>] [--verbose] [--help]'
		. "\n\nHelpHub mode reads the application password from the HELPHUB_APP_PASSWORD environment variable."
	);
}

/** Hold the credential in one place instead of threading it through calls, so */
function helphub_credits_auth(?string $set = null): string {
	static $auth = '';
	if (null !== $set) {
		$auth = $set;
	}

	return $auth;
}

/** Make one REST GET, authenticated when a credential has been loaded. */
function helphub_credits_request(string $url): array {
	if ('' === helphub_credits_auth()) {
		return helphub_http_request($url);
	}

	$options = array(
		'http' => array(
			'method'           => 'GET',
			'timeout'          => 30,
			'ignore_errors'    => true,
			'follow_location'  => 0,
			'max_redirects'    => 1,
			'protocol_version' => 1.1,
			'header'           => implode("\r\n", array(
				'Accept: application/json',
				'User-Agent: wp-security-helphub-credits',
				'Authorization: Basic ' . helphub_credits_auth(),
			)),
		),
	);

	$raw  = @file_get_contents($url, false, stream_context_create($options));
	$meta = helphub_http_response_meta($http_response_header ?? array());
	if (false === $raw) {
		throw new RuntimeException("Request failed: GET {$url}");
	}
	if (300 <= $meta['code'] && 400 > $meta['code']) {
		throw new RuntimeException(
			"Refusing to follow a redirect from {$url} (HTTP {$meta['code']}). "
			. 'Check --site points directly at the REST endpoint.'
		);
	}

	return array('code' => $meta['code'], 'data' => json_decode($raw, true), 'raw' => $raw, 'headers' => $meta['headers']);
}

/** Fetch every wordpress-versions post this caller can see. */
function helphub_credits_fetch_pages(string $site): array {
	$status = '' === helphub_credits_auth() ? 'publish' : 'any';
	$all    = array();
	for ($page = 1; $page <= 20; $page++) {
		$url = helphub_rest_url($site, null, array(
			'status'   => $status,
			'per_page' => 100,
			'page'     => $page,
			'orderby'  => 'id',
			'order'    => 'asc',
			'_fields'  => 'id,slug,link,status,content',
		));
		$result = helphub_credits_request($url);
		if (400 === $result['code'] && 1 < $page) {
			break; // Past the last page.
		}
		if (200 !== $result['code']) {
			throw new RuntimeException("Version-page listing failed on page {$page} (HTTP {$result['code']}).");
		}
		$batch = is_array($result['data']) ? $result['data'] : array();
		if (1 === $page && !$batch) {
			throw new RuntimeException('Version-page listing returned no pages; refusing to treat that as a check result.');
		}
		$all         = array_merge($all, $batch);
		$total_pages = isset($result['headers']['x-wp-totalpages']) ? (int) $result['headers']['x-wp-totalpages'] : null;
		if ((null !== $total_pages && $page >= $total_pages) || 100 > count($batch)) {
			return $all;
		}
		usleep(300000);
	}

	throw new RuntimeException('Version-page listing exceeded 20 pages; refusing a partial result.');
}

$options = cli_options($argv, array(
	'release:', 'manifest:', 'site:', 'user:', 'only:', 'news-post:', 'verbose', 'help',
));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --release=<X.Y.Z> --manifest=<helphub-manifest-X.Y.Z.json> [--site=<url>] [--user=<login>] "
		. "[--only=<X.Y.Z>] [--news-post=<URL or file>] [--verbose] [--help]\n"
		. "\nHelpHub mode reads the application password from the HELPHUB_APP_PASSWORD environment variable.\n";
	exit(0);
}
set_verbose(isset($options['verbose']));
$option_error = helphub_credits_news_option_error($options);
if (null !== $option_error) {
	if (null === $option_error['message']) {
		helphub_credits_usage($argv[0]);
	}
	fail($option_error['message'], $option_error['code']);
}
$news_post = array_key_exists('news-post', $options);

try {
	verbose_log($news_post ? 'Starting news post credit check' : 'Starting HelpHub credit check');
	$manifest_json = @file_get_contents($options['manifest']);
	if (false === $manifest_json) {
		throw new InvalidArgumentException("Unable to read manifest: {$options['manifest']}");
	}
	$manifest = helphub_manifest_decode($manifest_json);
	$option_error = helphub_credits_news_option_error($options, $manifest);
	if (null !== $option_error) {
		fail($option_error['message'], $option_error['code']);
	}
	if ($news_post) {
		exit(helphub_credits_check_news($manifest, helphub_credits_read_news($options['news-post'])));
	}

	$user     = isset($options['user']) && is_string($options['user']) && '' !== $options['user'] ? $options['user'] : null;
	$password = getenv('HELPHUB_APP_PASSWORD');
	$password = is_string($password) ? trim($password) : '';
	if ((null !== $user) !== ('' !== $password)) {
		fail(
			'--user and HELPHUB_APP_PASSWORD must be given together, or not at all. '
			. 'A partial credential cannot authenticate anything.',
			2
		);
	}
	$authed = null !== $user && '' !== $password;
	if ($authed) {
		helphub_credits_auth(base64_encode($user . ':' . $password));
	}
	unset($password);

	$site = helphub_require_https(isset($options['site']) && is_string($options['site']) ? $options['site'] : 'https://wordpress.org/documentation');
	$only = isset($options['only']) && is_string($options['only']) ? $options['only'] : null;

	verbose_log('Fetching the site\'s version pages');
	$posts = helphub_credits_fetch_pages($site);
	try {
		helphub_credits_slug_to_id($posts); // Refuses when a draft shares a slug with a published page.
	} catch (InvalidArgumentException $exception) {
		fail($exception->getMessage());
	}
	$pages_map = helphub_index_page_map($posts);
	verbose_log(sprintf('Loaded %d version page(s) from the site', count($pages_map)));

	echo "HelpHub credits for WordPress {$manifest['release']}\n";
	echo str_repeat('-', 60) . "\n";

	// Authenticated and still not found anywhere is an operational failure, not a per-page one.
	if (!isset($pages_map[$manifest['release']]) && $authed) {
		fail(
			"No page found anywhere for release {$manifest['release']} (checked every status). "
			. 'Check that --release and --manifest agree with what was actually drafted.'
		);
	}

	$resolved      = array(); // Branch line => version, for manifest targets the wave actually reached.
	$missing_lines = array();
	$wave          = array();
	if (isset($pages_map[$manifest['release']])) {
		try {
			$wave = helphub_index_release_wave($pages_map, $manifest['release']);
		} catch (InvalidArgumentException $exception) {
			fail($exception->getMessage());
		}
		verbose_log('Release wave: ' . implode(', ', $wave));

		try {
			$version_by_line = helphub_credits_version_by_line($wave);
		} catch (InvalidArgumentException $exception) {
			fail($exception->getMessage());
		}
		foreach (array_keys($manifest['targets']) as $target) {
			if (isset($version_by_line[$target])) {
				$resolved[$target] = $version_by_line[$target];
			} else {
				$missing_lines[] = $target;
			}
		}
	} else {
		$missing_lines = array_keys($manifest['targets']);
	}

	if (null !== $only) {
		helphub_select(array_values($resolved), $only);
		$resolved      = array_filter($resolved, static fn(string $version): bool => $version === $only);
		$missing_lines = array();
	}

	$ok_count         = 0;
	$mismatch_count   = 0;
	$missing_count    = 0;
	$unreadable_count = 0;

	foreach ($resolved as $line => $version) {
		$facts = $pages_map[$version];
		$text  = helphub_page_to_text($facts['content']);
		$live  = helphub_credit_bullets($text);
		if (null !== $live) {
			$live = array_map('helphub_credits_normalize_line', $live);
		}
		$expected = helphub_credits_expected_lines($manifest['fixes'], $manifest['credits'], $line);

		if (null === $live) {
			echo "  {$version}: MISMATCH\n";
			echo "    Page has no \"Security updates\" section.\n";
			$mismatch_count++;
			continue;
		}

		$cmp = helphub_credits_compare($expected['lines'], $live);
		$ok  = !$expected['fill_ins'] && !$cmp['missing'] && !$cmp['unexpected'];

		if ($ok) {
			echo "  {$version}: OK (" . count($expected['lines']) . (1 === count($expected['lines']) ? ' credit' : ' credits') . ")\n";
			$ok_count++;
			continue;
		}

		echo "  {$version}: MISMATCH\n";
		foreach ($expected['fill_ins'] as $number) {
			echo "    Fix #{$number} has no authored credit in the manifest (FILL IN marker); cannot verify.\n";
		}
		foreach ($cmp['missing'] as $line_text) {
			echo "    missing:    {$line_text}\n";
		}
		foreach ($cmp['unexpected'] as $line_text) {
			echo "    unexpected: {$line_text}\n";
		}
		$mismatch_count++;
	}

	$lead_date_key = $pages_map[$manifest['release']]['date_key'] ?? null;
	foreach ($missing_lines as $line) {
		$stray = helphub_credits_wave_stray($pages_map, $wave, $line, $lead_date_key);
		if (null !== $stray) {
			echo "  {$line}: NOT IN WAVE (page {$stray} exists but its fix set does not match the lead page)\n";
			$mismatch_count++;
		} elseif ($authed) {
			// Unauthenticated absence is not evidence: it may just be a draft this run cannot see.
			echo "  {$line}: MISSING (no page found for this branch in the release wave)\n";
			$missing_count++;
		} else {
			echo "  {$line}: UNREADABLE (not readable without credentials; may be an unpublished draft)\n";
			$unreadable_count++;
		}
	}

	$total = $ok_count + $mismatch_count + $missing_count + $unreadable_count;
	printf(
		"\n%d page(s) checked: %d OK, %d mismatch(es), %d missing, %d unreadable.\n",
		$total,
		$ok_count,
		$mismatch_count,
		$missing_count,
		$unreadable_count
	);

	exit($mismatch_count || $missing_count || $unreadable_count ? 2 : 0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), helphub_credits_error_code($exception));
} catch (RuntimeException $exception) {
	fail($exception->getMessage(), helphub_credits_error_code($exception));
} catch (Throwable $exception) {
	// Never reach PHP's own handler: its trace can print a credential held in this process.
	fail('Unexpected ' . $exception::class . ': ' . $exception->getMessage(), helphub_credits_error_code($exception));
}
