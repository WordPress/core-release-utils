#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/push-helphub-drafts-lib.php';

/** Put generated version pages into HelpHub as drafts, and edit them in place. */

function helphub_push_usage(string $script): never {
	fail(
		"Usage: {$script} --site=<url> --user=<login> --release=<X.Y.Z> (--create --pages=<dir> | --replace=<text> --with=<text>) "
		. '[--only=<X.Y.Z>] [--execute] [--verbose] [--help]'
		. "\n\nThe application password is read from the HELPHUB_APP_PASSWORD environment variable."
	);
}

/** Hold the credential in one place instead of threading it through calls. */
function helphub_auth(?string $set = null): string {
	static $auth = '';
	if (null !== $set) {
		$auth = $set;
	}
	if ('' === $auth) {
		throw new RuntimeException('No credential loaded.');
	}

	return $auth;
}

/** Make one authenticated REST request. */
function helphub_request(string $url, string $method, ?array $body = null): array {
	$options = array(
		'http' => array(
			'method'          => $method,
			'timeout'         => 30,
			'ignore_errors'   => true,
			'follow_location' => 0,
			'max_redirects'   => 1,
			'protocol_version' => 1.1,
			'header'          => array(
				'Authorization: Basic ' . helphub_auth(),
				'Accept: application/json',
				'User-Agent: wp-security-helphub-push',
			),
		),
	);
	if (null !== $body) {
		$encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (false === $encoded) {
			throw new RuntimeException('Unable to encode the request body.');
		}
		$options['http']['header'][] = 'Content-Type: application/json';
		$options['http']['content']  = $encoded;
	}
	$options['http']['header'] = implode("\r\n", $options['http']['header']);

	$raw = @file_get_contents($url, false, stream_context_create($options));
	$code = 0;
	foreach ($http_response_header ?? array() as $header) {
		if (1 === preg_match('~^HTTP/[0-9.]+\s+([0-9]{3})~', $header, $match)) {
			$code = (int) $match[1];
		}
	}
	if (false === $raw) {
		throw new RuntimeException("Request failed: {$method} {$url}");
	}
	// Refuse unexpected endpoint responses.
	if (300 <= $code && 400 > $code) {
		throw new RuntimeException(
			"Refusing to follow a redirect from {$url} (HTTP {$code}). "
			. 'Check --site points directly at the REST endpoint.'
		);
	}

	return array('code' => $code, 'data' => json_decode($raw, true), 'raw' => $raw);
}

/** Find an existing page for a version, in any status. */
function helphub_find_page(string $site, string $version): ?array {
	$url = helphub_rest_url($site, null, array(
		'slug'    => helphub_version_slug($version),
		'status'  => 'publish,draft,pending,private,future',
		'_fields' => 'id,slug,status,link',
	));
	$result = helphub_request($url, 'GET');
	if (200 !== $result['code']) {
		throw new RuntimeException(
			"Lookup failed for {$version} (HTTP {$result['code']}). " . substr($result['raw'], 0, 200)
		);
	}

	return is_array($result['data']) && $result['data'] ? $result['data'][0] : null;
}

/** Name a page for a skipped-line message. */
function helphub_page_label(array $page): string {
	$slug = isset($page['slug']) && is_string($page['slug']) ? trim($page['slug']) : '';
	$id   = isset($page['id']) ? (int) $page['id'] : 0;
	if ('' !== $slug) {
		return 0 !== $id ? "{$slug} (id {$id})" : $slug;
	}

	return 0 !== $id ? "id {$id}" : 'a page with no id or slug';
}

$options = cli_options($argv, array(
	'site:', 'user:', 'release:', 'pages:', 'only:', 'replace:', 'with:', 'create', 'execute', 'verbose', 'help',
));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --site=<url> --user=<login> --release=<X.Y.Z> (--create --pages=<dir> | --replace=<text> --with=<text>) "
		. "[--only=<X.Y.Z>] [--execute] [--verbose] [--help]\n"
		. "\nThe application password is read from the HELPHUB_APP_PASSWORD environment variable.\n";
	exit(0);
}
set_verbose(isset($options['verbose']));
foreach (array('site', 'user', 'release') as $required) {
	if (!isset($options[$required]) || !is_string($options[$required]) || '' === $options[$required]) {
		helphub_push_usage($argv[0]);
	}
}
$creating  = isset($options['create']);
$replacing = isset($options['replace']);
if ($creating === $replacing) {
	fail('Choose exactly one of --create or --replace.', 2);
}
if ($creating && (!isset($options['pages']) || !is_string($options['pages']))) {
	fail('--create needs --pages pointing at the generated drafts.', 2);
}
if ($replacing && (!isset($options['with']) || !is_string($options['with']))) {
	fail('--replace needs --with, even if it is an empty string.', 2);
}
if ($replacing && (!is_string($options['replace']) || '' === $options['replace'])) {
	fail('--replace needs a non-empty anchor to look for.', 2);
}

$password = getenv('HELPHUB_APP_PASSWORD');
$password = is_string($password) ? trim($password) : '';
if ('' === $password) {
	fail(
		'Set HELPHUB_APP_PASSWORD to a wordpress.org application password. '
		. 'It is read from the environment on purpose: a password passed as an argument '
		. 'reaches your shell history and every process listing on this machine.',
		2
	);
}
helphub_auth(base64_encode($options['user'] . ':' . $password));
unset($password);

$execute = isset($options['execute']);

try {
	$site = helphub_require_https($options['site']);
	$only = isset($options['only']) && is_string($options['only']) ? $options['only'] : null;

	echo ($execute ? 'Writing to' : 'DRY RUN against') . " {$site}\n";
	echo str_repeat('-', 66) . "\n";

	$done    = 0;
	$skipped = array();

	if ($creating) {
		helphub_verify_release_stamp($options['pages'], $options['release']);
		$pages    = helphub_load_pages($options['pages']);
		$versions = helphub_select(helphub_sort_versions(array_keys($pages)), $only);
		foreach ($versions as $version) {
			$existing = helphub_find_page($site, $version);
			if (null !== $existing) {
				$skipped[] = sprintf(
					'%s already has a page (%s, id %d). Nothing was changed.',
					$version,
					$existing['status'],
					$existing['id']
				);
				continue;
			}
			$payload = helphub_draft_payload($version, $pages[$version]);
			echo '  ' . helphub_describe_create($version, $payload) . "\n";
			if (!$execute) {
				$done++;
				continue;
			}
			$result = helphub_request(helphub_rest_url($site), 'POST', $payload);
			if (201 !== $result['code'] && 200 !== $result['code']) {
				throw new RuntimeException(
					"Create failed for {$version} (HTTP {$result['code']}). " . substr($result['raw'], 0, 300)
				);
			}
			$id = $result['data']['id'] ?? 0;
			// Detect a duplicate created between the existence check and the write.
			$got = $result['data']['slug'] ?? '';
			if ($got !== $payload['slug']) {
				$skipped[] = sprintf(
					'%s was created at slug %s, not %s. Something else claimed that slug first. '
					. 'Review and delete id %d before re-running.',
					$version,
					$got,
					$payload['slug'],
					$id
				);
			}
			echo "    created id {$id}, edit at {$site}/wp-admin/post.php?post={$id}&action=edit\n";
			$done++;
		}
	} else {
		$search  = $options['replace'];
		$replace = $options['with'];
		// Read every result page before selecting drafts.
		$drafts = array();
		for ($page_number = 1; $page_number <= 50; $page_number++) {
			$url = helphub_rest_url($site, null, array(
				'status'   => HELPHUB_DRAFT_STATUS,
				'per_page' => 100,
				'page'     => $page_number,
				'_fields'  => 'id,slug,status,content',
				'context'  => 'edit',
			));
			$result = helphub_request($url, 'GET');
			if (400 === $result['code']) {
				break; // Past the last page.
			}
			if (200 !== $result['code']) {
				throw new RuntimeException("Listing drafts failed (HTTP {$result['code']}).");
			}
			$batch = (array) $result['data'];
			$drafts = array_merge($drafts, $batch);
			if (100 > count($batch)) {
				break;
			}
		}
		verbose_log(sprintf('Listed %d draft(s)', count($drafts)));

		$slugs = array();
		foreach ($drafts as $page) {
			if (isset($page['slug'])) {
				$slugs[] = $page['slug'];
			}
		}
		$wanted = null === $only ? null : helphub_version_slug($only);
		if (null !== $wanted) {
			helphub_select($slugs, $wanted);
		}

		foreach ($drafts as $page) {
			$slug = $page['slug'] ?? '';
			if (null !== $wanted && $wanted !== $slug) {
				continue;
			}
			// Refuse to edit a page published since the listing.
			if (HELPHUB_DRAFT_STATUS !== ($page['status'] ?? '')) {
				$skipped[] = helphub_page_label($page) . ": no longer a draft ({$page['status']}). Left alone.";
				continue;
			}
			$body = $page['content']['raw'] ?? '';
			try {
				$updated = helphub_apply_replacement($body, $search, $replace);
			} catch (RuntimeException $exception) {
				$skipped[] = helphub_page_label($page) . ': ' . $exception->getMessage();
				continue;
			}
			echo "  edit {$slug} (id {$page['id']})\n";
			if (!$execute) {
				$done++;
				continue;
			}
			$current = helphub_request(
				helphub_rest_url($site, (int) $page['id'], array('_fields' => 'status')),
				'GET'
			);
			if (HELPHUB_DRAFT_STATUS !== ($current['data']['status'] ?? '')) {
				$skipped[] = sprintf(
					'%s changed to %s while this ran. Nothing was written to it.',
					helphub_page_label($page),
					$current['data']['status'] ?? 'unknown'
				);
				continue;
			}
			$write = helphub_request(
				helphub_rest_url($site, (int) $page['id']),
				'POST',
				array('content' => $updated)
			);
			if (200 !== $write['code']) {
				throw new RuntimeException(
					"Edit failed for {$slug} (HTTP {$write['code']}). " . substr($write['raw'], 0, 300)
				);
			}
			$done++;
		}
	}

	echo "\n" . ($execute ? "Wrote {$done} page(s)." : "Would touch {$done} page(s).") . "\n";
	if ($skipped) {
		echo "\nSkipped (" . count($skipped) . "):\n";
		foreach ($skipped as $note) {
			echo "  - {$note}\n";
		}
	}
	if (!$execute) {
		echo "\nNothing was written. Re-run with --execute when the above looks right.\n";
	} else {
		echo "\nEvery page is a draft. Publishing stays a human action in the editor.\n";
	}
	exit($skipped ? 2 : 0);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
} catch (Throwable $exception) {
	// Keep unexpected exception traces from exposing credentials.
	fail('Unexpected ' . $exception::class . ': ' . $exception->getMessage());
}
