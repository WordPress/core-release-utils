#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/lib.php';
require_once __DIR__ . '/lib/checkout-lib.php';
require_once __DIR__ . '/lib/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/draft-helphub-version-pages-lib.php';
require_once __DIR__ . '/lib/push-helphub-drafts-lib.php';
require_once __DIR__ . '/lib/helphub-rest-lib.php';

/** Put generated version pages into HelpHub as drafts, and edit them in place. */

function helphub_push_usage(string $script): never {
	fail(
		"Usage: {$script} --site=<url> --user=<login> --release=<X.Y.Z> (--create --pages=<dir> | --replace=<text> --with=<text> [--published=<X.Y.Z,...>]) "
		. '[--only=<X.Y.Z>] [--execute] [--verbose] [--help]'
		. "\n\nThe application password is read from the HELPHUB_APP_PASSWORD environment variable."
	);
}

$options = cli_options($argv, array(
	'site:', 'user:', 'release:', 'pages:', 'only:', 'replace:', 'with:', 'published:', 'create', 'execute', 'verbose', 'help',
));
if (isset($options['help'])) {
	echo "Usage: {$argv[0]} --site=<url> --user=<login> --release=<X.Y.Z> (--create --pages=<dir> | --replace=<text> --with=<text> [--published=<X.Y.Z,...>]) "
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
if (isset($options['published']) && (!$replacing || !is_string($options['published']))) {
	fail('--published names the live versions to edit, and works only with --replace.', 2);
}

try {
	helphub_load_auth($options['user']);
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
}

$execute = isset($options['execute']);

try {
	$site = helphub_require_https($options['site']);
	$only = isset($options['only']) && is_string($options['only']) ? $options['only'] : null;
	$published = isset($options['published']) ? helphub_published_versions($options['published']) : null;

	echo ($execute ? 'Writing to' : 'DRY RUN against') . " {$site}\n";
	echo str_repeat('-', 66) . "\n";

	$done       = 0;
	$skipped    = array();
	$unverified = false;

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
	} elseif (null !== $published) {
		$search  = $options['replace'];
		$replace = $options['with'];
		foreach (helphub_published_plan($published, $only, $replace) as $version) {
			$matches = helphub_lookup_pages($site, $version);
			$refusal = helphub_published_refusal($version, $matches);
			if (null !== $refusal) {
				$skipped[] = $refusal;
				continue;
			}
			$page = $matches[0];
			$body = (string) ($page['content']['raw'] ?? '');
			try {
				$updated = helphub_apply_replacement($body, $search, $replace);
			} catch (RuntimeException $exception) {
				$skipped[] = "{$version}: " . $exception->getMessage();
				continue;
			}
			echo "  edit published {$page['slug']} (id {$page['id']})\n" . helphub_describe_replacement($search, $replace) . "\n";
			if (!$execute) {
				$done++;
				continue;
			}
			$current = helphub_lookup_pages($site, $version);
			if (null !== helphub_published_refusal($version, $current) || $page['id'] !== ($current[0]['id'] ?? null) || $body !== ($current[0]['content']['raw'] ?? null)) {
				$skipped[] = "{$version} changed while this ran. Nothing was written to it.";
				continue;
			}
			$write = helphub_request(
				helphub_rest_url($site, (int) $page['id']),
				'POST',
				helphub_content_payload($updated)
			);
			if (200 !== $write['code']) {
				throw new RuntimeException(
					"Edit failed for {$version} (HTTP {$write['code']}). " . substr($write['raw'], 0, 300)
				);
			}
			$after = helphub_lookup_pages($site, $version);
			$done++;
			if (null !== helphub_published_refusal($version, $after) || $page['id'] !== ($after[0]['id'] ?? null) || $updated !== ($after[0]['content']['raw'] ?? null)) {
				$skipped[]  = "{$version} was written but reads back differently. Check id {$page['id']} in the editor.";
				$unverified = true;
			}
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
	} elseif (null !== $published) {
		echo "\nOnly the named published pages were edited, and no status was sent.\n";
	} else {
		echo "\nEvery page is a draft. Publishing stays a human action in the editor.\n";
	}
	exit($unverified ? 3 : ($skipped ? 2 : 0));
} catch (InvalidArgumentException $exception) {
	fail($exception->getMessage(), 2);
} catch (RuntimeException $exception) {
	fail($exception->getMessage());
} catch (Throwable $exception) {
	// Keep unexpected exception traces from exposing credentials.
	fail('Unexpected ' . $exception::class . ': ' . $exception->getMessage());
}
