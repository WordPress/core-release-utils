<?php

declare(strict_types=1);

require_once __DIR__ . '/check-helphub-version-pages-lib.php';
require_once __DIR__ . '/draft-helphub-version-pages-lib.php';

/**
 * Parsing and comparison helpers for check-helphub-credits.php.
 *
 * Separated from the CLI entry point so the test suite can exercise the pure
 * parts directly, the way every other tool in this repository pairs a script
 * with its `-lib.php`.
 */

/**
 * Reduce one credit line to the plain text a live page's bullet reduces to.
 *
 * Curly quotes and dashes are normalized so a straight-quoted scope credit
 * still matches a page rendered with typographic punctuation, and vice versa.
 */
function helphub_credits_normalize_line(string $line): string {
	$text = strip_tags($line);
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = str_replace(
		array("\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\xc2\xa0"),
		array("'", "'", '"', '"', '-', '-', ' '),
		$text
	);
	$text = preg_replace('/\s+/', ' ', $text) ?? $text;

	return trim($text);
}

/**
 * Whether a normalized line still carries an unfilled FILL IN marker.
 *
 * Checked after normalization, not on the raw credit string, so a marker
 * split by inline markup or joined by &nbsp; is still caught.
 */
function helphub_credits_is_fill_in(string $normalized): bool {
	return str_contains($normalized, 'FILL IN');
}

/**
 * The fix numbers that reach one branch, in the scope's own order.
 *
 * @param array<int,string[]> $fixes
 * @return int[]
 */
function helphub_credits_reaching_fixes(array $fixes, string $line): array {
	$numbers = array();
	foreach ($fixes as $number => $targets) {
		if (in_array($line, $targets, true)) {
			$numbers[] = $number;
		}
	}

	return $numbers;
}

/**
 * The credit lines a version's page should carry.
 *
 * Reuses helphub_credit_lines() (draft-helphub-version-pages-lib.php) so the
 * expected text matches the draft generator. A fix with no authored credit,
 * or one whose normalized text still reads FILL IN, comes back in `fill_ins`
 * instead of a phantom comparable line.
 *
 * @param array<int,string[]> $fixes
 * @param array<int,string>   $credits
 * @return array{lines:string[],fill_ins:int[]}
 */
function helphub_credits_expected_lines(array $fixes, array $credits, string $line): array {
	$result   = helphub_credit_lines($fixes, $credits, $line);
	$numbers  = helphub_credits_reaching_fixes($fixes, $line);
	$fill_ins = array_flip($result['missing']);

	$lines = array();
	foreach ($numbers as $index => $number) {
		if (isset($fill_ins[$number])) {
			continue;
		}
		$normalized = helphub_credits_normalize_line($result['bullets'][$index]);
		if (helphub_credits_is_fill_in($normalized)) {
			$fill_ins[$number] = true;
			continue;
		}
		$lines[] = $normalized;
	}

	return array('lines' => $lines, 'fill_ins' => array_keys($fill_ins));
}

/**
 * Compare a page's live credit bullets against what the scope expects, as
 * multisets rather than sets: a duplicated bullet is not the same as a
 * present one, and a missing second occurrence is not the same as a present
 * first one.
 *
 * @param string[] $expected
 * @param string[] $live
 * @return array{missing:string[],unexpected:string[]}
 */
function helphub_credits_compare(array $expected, array $live): array {
	$expected_counts = array_count_values($expected);
	$live_counts     = array_count_values($live);

	$missing = array();
	foreach ($expected_counts as $line => $count) {
		$short = $count - ($live_counts[$line] ?? 0);
		for ($i = 0; $i < $short; $i++) {
			$missing[] = $line;
		}
	}

	$unexpected = array();
	foreach ($live_counts as $line => $count) {
		$wanted = $expected_counts[$line] ?? 0;
		$extra  = $count - $wanted;
		for ($i = 0; $i < $extra; $i++) {
			$unexpected[] = $wanted > 0 ? "{$line} (duplicate)" : $line;
		}
	}

	return array('missing' => $missing, 'unexpected' => $unexpected);
}

/**
 * The post id for each slug, refusing when two posts share one.
 *
 * A draft can be created with the same slug as its already-published sibling;
 * picking one silently (last write wins) risks fixing the wrong post.
 *
 * @param array<int,array<string,mixed>> $posts
 * @return array<string,int>
 */
function helphub_credits_slug_to_id(array $posts): array {
	$groups = array();
	foreach ($posts as $post) {
		if (isset($post['slug']) && is_string($post['slug'])) {
			$groups[$post['slug']][] = $post;
		}
	}

	$id_by_slug = array();
	foreach ($groups as $slug => $group) {
		if (1 < count($group)) {
			throw new InvalidArgumentException(
				"Slug \"{$slug}\" is shared by more than one post: " . helphub_credits_describe_posts($group) . '.'
			);
		}
		$id_by_slug[$slug] = (int) ($group[0]['id'] ?? 0);
	}

	return $id_by_slug;
}

/**
 * Render "id 123 (publish), id 456 (draft)" for an ambiguity error.
 *
 * @param array<int,array<string,mixed>> $posts
 */
function helphub_credits_describe_posts(array $posts): string {
	$named = array();
	foreach ($posts as $post) {
		$id     = $post['id'] ?? '?';
		$status = $post['status'] ?? '?';
		$named[] = "id {$id} ({$status})";
	}

	return implode(', ', $named);
}

/**
 * The current version for each branch line in a wave, refusing when two wave
 * versions share a line — e.g. 7.0.4 and 7.0.3 both resolving to "7.0".
 *
 * @param string[] $wave
 * @return array<string,string>
 */
function helphub_credits_version_by_line(array $wave): array {
	$by_line = array();
	foreach ($wave as $version) {
		$by_line[helphub_version_line($version)][] = $version;
	}

	$result = array();
	foreach ($by_line as $line => $versions) {
		if (1 < count($versions)) {
			throw new InvalidArgumentException(
				"Branch {$line} has more than one version in this release wave: " . implode(', ', $versions) . '.'
			);
		}
		$result[$line] = $versions[0];
	}

	return $result;
}

/**
 * The version of a page on a branch the wave did not reach, dated within the
 * same seven-day window the wave derivation uses, or null when the branch has
 * no such page.
 *
 * Older pages on the same branch exist for every release, so a version alone
 * is not evidence; the date is what separates "published this release but
 * excluded from the wave" from "last release's page, still there".
 *
 * @param array<string,array{date_key:?string}> $pages
 * @param string[] $wave
 */
function helphub_credits_wave_stray(array $pages, array $wave, string $line, ?string $lead_date_key): ?string {
	if (null === $lead_date_key) {
		return null;
	}
	$lead_day = new DateTimeImmutable($lead_date_key, new DateTimeZone('UTC'));
	$in_wave  = array_flip($wave);
	foreach ($pages as $version => $page) {
		if (isset($in_wave[$version]) || helphub_version_line($version) !== $line || null === $page['date_key']) {
			continue;
		}
		$days = abs((int) $lead_day->diff(new DateTimeImmutable($page['date_key'], new DateTimeZone('UTC')))->format('%r%a'));
		if (7 >= $days) {
			return $version;
		}
	}

	return null;
}
