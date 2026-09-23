<?php

declare(strict_types=1);

require_once __DIR__ . '/draft-helphub-version-pages-lib.php';

/**
 * Scoping helpers for preview-helphub-version-pages.php.
 *
 * Separated from the CLI entry point so the test suite can exercise them
 * directly, the way every other tool in this repository pairs a script with its
 * `-lib.php`.
 */

/**
 * Read a branch's `$wp_version`, which says where that branch sits.
 *
 * A branch carries one of two shapes, and they mean different things:
 *
 *   `6.9.5-src`               the last release cut from this branch was 6.9.5
 *   `7.0.3-alpha-62792-src`   this branch is already open for 7.0.3
 *
 * So a branch still on its last release would publish the next patch number,
 * while a branch already bumped to an alpha would publish the version named in
 * that alpha. Guessing one rule for both would be wrong half the time.
 *
 * @return array{raw:string,current:string,next:string,bumped:bool}|null Null
 *         when the branch or the file is not in the checkout.
 */
function helphub_branch_version(string $checkout, string $target): ?array {
	foreach (array("origin/{$target}", $target) as $candidate) {
		$result = run_command(
			array('git', 'show', "{$candidate}:src/wp-includes/version.php"),
			$checkout,
			true
		);
		if (0 !== $result['code']) {
			continue;
		}
		if (1 !== preg_match('/^\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/m', $result['stdout'], $match)) {
			return null;
		}

		return helphub_parse_version_string($match[1]);
	}

	return null;
}

/**
 * Split a `$wp_version` value into what it says about the branch.
 *
 * @return array{raw:string,current:string,next:string,bumped:bool}|null
 */
function helphub_parse_version_string(string $raw): ?array {
	if (1 !== preg_match('/^([0-9]+\.[0-9]+(?:\.[0-9]+)?)(.*)$/', $raw, $match)) {
		return null;
	}
	$version = $match[1];
	// The pre-release marker follows the version directly, as in
	// `7.0.3-alpha-62792-src`. Searching the whole suffix for "rc" instead would
	// match the "rc" inside "-src", which every released branch carries, and
	// quietly report each one as already open for a version it has shipped.
	// The marker is followed by a digit or a hyphen, as in `-RC1` and
	// `-alpha-62792`, so a word boundary would not match. The lookahead keeps a
	// longer word starting with the same letters from counting as one.
	$bumped = 1 === preg_match('/^-(alpha|beta|rc)(?![a-z])/i', $match[2]);

	$parts = explode('.', $version);
	if (2 === count($parts)) {
		$parts[] = '0';
	}

	if ($bumped) {
		// Already open for this version, so this is what it would publish.
		return array('raw' => $raw, 'current' => $version, 'next' => $version, 'bumped' => true);
	}

	$next = $parts[0] . '.' . $parts[1] . '.' . ((int) $parts[2] + 1);

	return array('raw' => $raw, 'current' => $version, 'next' => $next, 'bumped' => false);
}

/**
 * Which fixes in the scope reach one branch.
 *
 * @param array<int,string[]> $fixes
 * @return int[]
 */
function helphub_fixes_for_target(array $fixes, string $target): array {
	$reaching = array();
	foreach ($fixes as $number => $targets) {
		if (in_array($target, $targets, true)) {
			$reaching[] = $number;
		}
	}

	return $reaching;
}

/**
 * Which of a branch's fixes still have no authored credit line.
 *
 * @param int[]             $reaching
 * @param array<int,string> $credits
 * @return int[]
 */
function helphub_missing_credits(array $reaching, array $credits): array {
	$missing = array();
	foreach ($reaching as $number) {
		$credit = $credits[$number] ?? null;
		// A line still carrying its FILL IN marker is not authored yet. Counting
		// it as done would let a placeholder reach a published page.
		if (!helphub_credit_is_authored($credit)) {
			$missing[] = $number;
		}
	}

	return $missing;
}

/**
 * The versions that already have a published page, from the public REST API.
 *
 * Only published pages are visible, which is the point: this answers "did the
 * page that should exist get created", the question the release's real failure
 * mode hides. A draft is invisible here and that is correct — an unpublished
 * page has not reached anyone.
 *
 * @param string[] $versions
 * @return array<string,bool> Version to whether a published page exists.
 */
function helphub_published_pages(array $versions, callable $fetcher): array {
	$existing = array();
	foreach ($versions as $version) {
		$slug = 'version-' . str_replace('.', '-', $version);
		$existing[$version] = (bool) $fetcher($slug);
	}

	return $existing;
}

/**
 * Ask wordpress.org whether a version page exists.
 */
function helphub_page_exists(string $slug): bool {
	$url     = 'https://wordpress.org/documentation/wp-json/wp/v2/wordpress-versions?slug='
		. rawurlencode($slug) . '&_fields=slug';
	$context = stream_context_create(array(
		'http' => array('method' => 'GET', 'timeout' => 15, 'ignore_errors' => true),
	));
	$body = @file_get_contents($url, false, $context);
	if (false === $body) {
		return false;
	}
	$data = json_decode($body, true);

	return is_array($data) && array() !== $data;
}
