<?php

declare(strict_types=1);

/**
 * Parsing and comparison helpers for check-helphub-version-pages.php.
 *
 * Separated from the CLI entry point so the test suite can exercise the
 * parsing and comparison directly, the way every other tool in this repository
 * pairs a script with its `-lib.php`.
 */

const HELPHUB_SECURITY_HEADING = 'Security updates';
const HELPHUB_FILES_HEADING    = 'List of files revised';
const HELPHUB_PACKAGES_HEADING = 'List of packages revised';
const HELPHUB_CHANGELOG_HEADING = 'Change log';

/**
 * Files every release touches that never appear in a page's revised-file list.
 *
 * The page lists the security change, not the release mechanics. Comparing
 * against a raw release diff instead of the fix references would flag all of
 * these on every page of every release.
 */
const HELPHUB_RELEASE_MECHANICS = array(
	'src/wp-includes/version.php',
	'src/wp-admin/about.php',
);

/**
 * Reduce a page body to plain text with list items and headings marked.
 *
 * A page body reaches this tool in one of two shapes: block markup pasted out
 * of the HelpHub editor, or rendered HTML dumped from the REST endpoint. Both
 * carry the same headings and the same list items, so flattening each to
 * marked text lets one parser read either. Block delimiters are HTML comments,
 * which is why comments are stripped before tags.
 */
function helphub_page_to_text(string $body): string {
	$text = preg_replace('/<!--.*?-->/s', '', $body) ?? $body;
	$text = preg_replace('~<(h[1-6])\b[^>]*>~i', "\n\x02", $text) ?? $text;
	$text = preg_replace('~</h[1-6]>~i', "\n", $text) ?? $text;
	$text = preg_replace('~<li\b[^>]*>~i', "\n\x01", $text) ?? $text;
	$text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
	$text = preg_replace('~</(p|ul|ol|li|div)>~i', "\n", $text) ?? $text;
	$text = strip_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	// Non-breaking spaces read as content but compare as different bytes.
	$text = str_replace("\xc2\xa0", ' ', $text);

	$lines = array();
	foreach (explode("\n", $text) as $line) {
		$lines[] = trim($line);
	}

	return implode("\n", $lines);
}

/**
 * Return the lines of one section, identified by its heading text.
 *
 * Headings are matched on their text rather than their level, because the
 * same section sits under `h2` on some pages and `h3` on others. The section
 * ends at the next heading of any level, or at the end of the page.
 *
 * @return string[]|null Null when the page has no such heading at all, which
 *                       is different from a section that is present but empty.
 */
function helphub_section_lines(string $text, string $heading): ?array {
	$lines   = explode("\n", $text);
	$started = false;
	$section = array();
	foreach ($lines as $line) {
		$is_heading = str_starts_with($line, "\x02");
		if (!$started) {
			if ($is_heading && 0 === strcasecmp(trim(substr($line, 1)), $heading)) {
				$started = true;
			}
			continue;
		}
		if ($is_heading) {
			break;
		}
		if ('' !== $line) {
			$section[] = $line;
		}
	}

	return $started ? $section : null;
}

/**
 * The credit bullets a page carries, one per reported vulnerability.
 *
 * Only list items count. The Security updates section also holds fixed
 * boilerplate and, on some releases, a paragraph about coordinating a fix with
 * an external library maintainer. Neither is a credit, and treating them as
 * one would make every page in a wave look divergent.
 *
 * @return string[]|null Null when the page has no Security updates section,
 *                       which is a bugfix or major release rather than a
 *                       security release.
 */
function helphub_credit_bullets(string $text): ?array {
	$lines = helphub_section_lines($text, HELPHUB_SECURITY_HEADING);
	if (null === $lines) {
		return null;
	}
	$bullets = array();
	foreach ($lines as $line) {
		// The courtesy block sits in this same section and is also a list, but
		// its items describe branches rather than credit reporters. They read
		// almost identically to each other by design — "WordPress 6.7 is
		// affected by 7 of the 9 vulnerabilities", "WordPress 6.6 is affected by
		// 7 of the 9 vulnerabilities" — so treating them as credits makes every
		// pair look like one credit and a correction. Stop at the paragraph that
		// opens the block.
		if (str_starts_with(ltrim($line, "\x01\x02"), 'As a courtesy')) {
			break;
		}
		if (str_starts_with($line, "\x01")) {
			$bullet = trim(substr($line, 1));
			if ('' !== $bullet) {
				$bullets[] = $bullet;
			}
		}
	}

	return $bullets;
}

/**
 * The paths a page lists as revised, normalized to repository paths.
 *
 * Pages carry Subversion-rooted paths (`/wp-includes/foo.php`) and the
 * repository stores the same file under `src/`. Normalizing here means the
 * comparison in check 3 is a plain set difference rather than a per-entry
 * translation, so a malformed entry stays visible instead of being repaired
 * on the way through.
 *
 * A path the build relocates cannot be normalized by removing `src/`, so those
 * are translated back through the branch's own mapping. Anything absent from
 * that mapping still gets the plain treatment, which keeps a malformed entry
 * visible instead of repairing it on the way through.
 *
 * @param array<string,string> $release_paths Repository path to release path.
 * @return string[]|null Null when the page has no revised-file section.
 */
function helphub_revised_files(string $text, array $release_paths = array()): ?array {
	$lines = helphub_section_lines($text, HELPHUB_FILES_HEADING);
	if (null === $lines) {
		return null;
	}
	$repository_paths = array_flip($release_paths);
	$files            = array();
	foreach ($lines as $line) {
		$path = trim(str_replace(array("\x01", "\x02"), '', $line));
		if ('' === $path || str_starts_with($path, 'No file')) {
			continue;
		}
		$rooted  = '/' . ltrim($path, '/');
		$files[] = $repository_paths[$rooted] ?? 'src/' . ltrim($path, '/');
	}

	return $files;
}

/**
 * Read the page bodies for a wave, keyed by the version each one documents.
 *
 * The file name is the version: `7.0.3.txt` documents WordPress 7.0.3. That is
 * the whole mapping. Deriving a branch's released version from its history
 * would be a second source of truth for something the page itself already
 * states, and it would be wrong for a superseded release — 6.8.4 was renamed
 * to redirect to 6.8.5 mid-wave in March 2026.
 *
 * @return array<string,string> Version to page body.
 */
function helphub_load_pages(string $directory): array {
	$real = realpath($directory);
	if (false === $real || !is_dir($real)) {
		throw new InvalidArgumentException("Pages directory does not exist: {$directory}");
	}
	$entries = scandir($real);
	if (false === $entries) {
		throw new RuntimeException("Unable to read pages directory: {$directory}");
	}

	$pages = array();
	foreach ($entries as $entry) {
		if (str_starts_with($entry, '.')) {
			continue;
		}
		$path = $real . '/' . $entry;
		if (!is_file($path)) {
			continue;
		}
		$version = pathinfo($entry, PATHINFO_FILENAME);
		if (1 !== preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $version)) {
			throw new InvalidArgumentException(
				"Page file name must be a version, for example 7.0.3.txt: {$entry}"
			);
		}
		$body = file_get_contents($path);
		if (false === $body) {
			throw new RuntimeException("Unable to read page file: {$path}");
		}
		$pages[$version] = $body;
	}

	if (!$pages) {
		throw new InvalidArgumentException("No page files found in {$directory}");
	}

	return $pages;
}

/**
 * The branch line a page's version belongs to.
 *
 * `6.9.6` belongs to `6.9`. Prefix is enough: a wave publishes one page per
 * branch line, so the mapping cannot collide, and it holds even when the
 * released patch number is not the one a caller would have predicted.
 */
function helphub_version_line(string $version): string {
	$parts = explode('.', $version);

	return $parts[0] . '.' . ($parts[1] ?? '0');
}

/**
 * Words that are legitimately written twice in a row.
 *
 * A doubled-word check with no exceptions fires on correct English, and a check
 * that fires on correct text stops being read.
 */
const HELPHUB_LEGITIMATE_DOUBLES = array('had', 'that');

/**
 * Misspellings seen on published version pages, and their corrections.
 *
 * A curated list rather than a dictionary, deliberately. A spellchecker on this
 * prose would flag every function name, file path, and researcher's name, and
 * this repository takes no external dependencies in any case. This list starts
 * from what duplication has actually produced and grows when something new gets
 * through.
 */
const HELPHUB_KNOWN_MISSPELLINGS = array(
	'overridding'   => 'overriding',
	'seperate'      => 'separate',
	'occured'       => 'occurred',
	'recieve'       => 'receive',
	'vulnerabilty'  => 'vulnerability',
	'vulnerabilites' => 'vulnerabilities',
	'authetication' => 'authentication',
	'priviledge'    => 'privilege',
	'succesfully'   => 'successfully',
);

/**
 * Prose defects a generated page cannot have but a hand-written one can.
 *
 * Only two kinds, both chosen because they have no false positives worth the
 * noise: a word repeated back to back, and a spelling this project has seen go
 * wrong before. This is not a spellchecker and must not be described as one.
 *
 * Note what it cannot do. "overridding" reached all 23 pages of the March 2026
 * release, so cross-page comparison is blind to it; only a list like the one
 * above catches a mistake that is perfectly consistent.
 *
 * @return string[] Findings.
 */
function helphub_prose_defects(string $version, string $text): array {
	$findings = array();
	$legitimate = array_flip(HELPHUB_LEGITIMATE_DOUBLES);

	if (preg_match_all('/\b([A-Za-z]{2,})\s+\1\b/i', $text, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			if (isset($legitimate[strtolower($match[1])])) {
				continue;
			}
			$findings[] = sprintf(
				'Page %s repeats a word: "%s". Published example: "this is is not" on version 6.9.3.',
				$version,
				trim($match[0])
			);
		}
	}

	foreach (HELPHUB_KNOWN_MISSPELLINGS as $wrong => $right) {
		if (preg_match('/\b' . preg_quote($wrong, '/') . '\b/i', $text)) {
			$findings[] = sprintf('Page %s misspells "%s"; should be "%s".', $version, $wrong, $right);
		}
	}

	return $findings;
}

/**
 * Every link a page points at.
 *
 * @return string[] Absolute URLs, de-duplicated.
 */
function helphub_extract_links(string $body): array {
	$urls = array();
	if (preg_match_all('~href=["\']([^"\']+)["\']~i', $body, $matches)) {
		foreach ($matches[1] as $url) {
			$url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
				$urls[$url] = true;
			}
		}
	}

	return array_keys($urls);
}

/**
 * Resolve each link and report the ones that do not lead anywhere.
 *
 * The fetcher is injected so the test suite stays offline, which is a hard rule
 * for this repository's tests, and so a caller can swap in a different client.
 * It receives a URL and returns an HTTP status code, or 0 when the request
 * could not be made at all.
 *
 * A request that fails outright is reported as unresolved rather than broken. A
 * network problem on release day is not evidence that a link is dead, and
 * telling a release manager to fix a working link wastes the one hour they do
 * not have.
 *
 * @param string[] $urls
 * @return array{broken:string[],unresolved:string[]}
 */
function helphub_check_links(array $urls, callable $fetcher): array {
	$broken     = array();
	$unresolved = array();
	foreach ($urls as $url) {
		$status = (int) $fetcher($url);
		if (0 === $status) {
			$unresolved[] = "{$url} (no response)";
			continue;
		}
		if (400 <= $status) {
			$broken[] = "{$url} ({$status})";
		}
	}

	return array('broken' => $broken, 'unresolved' => $unresolved);
}

/**
 * Ask a URL for its status without downloading the page.
 */
function helphub_http_status(string $url): int {
	$context = stream_context_create(array(
		'http' => array(
			'method'        => 'HEAD',
			'timeout'       => 10,
			'ignore_errors' => true,
			'user_agent'    => 'wp-security-helphub-check',
		),
	));
	$headers = @get_headers($url, false, $context);
	if (false === $headers || !$headers) {
		return 0;
	}
	// Follow the response chain and report the final status.
	$status = 0;
	foreach ($headers as $header) {
		if (1 === preg_match('~^HTTP/[0-9.]+\s+([0-9]{3})~', $header, $match)) {
			$status = (int) $match[1];
		}
	}

	return $status;
}

/**
 * Two credit lines that differ only slightly are one line and a correction.
 *
 * A wave's pages share most of their credits verbatim, because they describe
 * the same vulnerabilities. Genuinely different credits are far apart; a
 * credit corrected on one page and not another is a handful of characters
 * apart. The threshold scales with length so a short line needs a
 * proportionally smaller difference to count as a near-match.
 *
 * Distance is capped at 255 bytes per argument by `levenshtein()`, so longer
 * lines fall back to a similarity percentage rather than silently returning a
 * distance computed from truncated input.
 */
function helphub_is_near_duplicate(string $a, string $b): bool {
	if ($a === $b) {
		return false;
	}
	$longest = max(strlen($a), strlen($b));
	if (255 < strlen($a) || 255 < strlen($b)) {
		$percent = 0.0;
		similar_text($a, $b, $percent);

		return 85.0 <= $percent;
	}
	$distance  = levenshtein($a, $b);
	$threshold = max(2, (int) floor(0.15 * $longest));

	return $distance <= $threshold;
}

/**
 * Check 1: credit lines shared across a wave must be identical everywhere.
 *
 * @param array<string,string[]> $credits Version to its credit bullets.
 * @return string[] Findings.
 */
function helphub_check_credit_consistency(array $credits): array {
	$occurrences = array();
	foreach ($credits as $version => $bullets) {
		foreach ($bullets as $bullet) {
			$occurrences[$bullet][] = $version;
		}
	}

	$bullets  = array_keys($occurrences);
	$findings = array();
	$count    = count($bullets);
	for ($i = 0; $i < $count; $i++) {
		for ($j = $i + 1; $j < $count; $j++) {
			if (!helphub_is_near_duplicate($bullets[$i], $bullets[$j])) {
				continue;
			}
			$findings[] = sprintf(
				"Credit lines differ between pages that should carry the same text.\n"
				. "    %s: %s\n"
				. '    %s: %s',
				implode(', ', $occurrences[$bullets[$i]]),
				$bullets[$i],
				implode(', ', $occurrences[$bullets[$j]]),
				$bullets[$j]
			);
		}
	}

	return $findings;
}

/**
 * Check 2: each target in the scope has at most one page, and no page is a stray.
 *
 * A scope target is a branch that received the backport. It is not a promise
 * that a release was cut from that branch, and the two genuinely differ: the
 * July 2026 wave prepared sixteen branches and released three, so thirteen of
 * its targets correctly have no page at all.
 *
 * So a target with no page is reported as unverified rather than as a finding.
 * It still reaches the reader — a page nobody created is the failure this check
 * exists for — but it is stated as something the tool could not check rather
 * than as a defect it found. Calling all thirteen a defect on release day is
 * how a checker gets switched off on release day.
 *
 * @param string[]               $targets
 * @param array<string,string[]> $pages_by_line Branch line to the versions found for it.
 * @return array{findings:string[],unverified:string[]}
 */
function helphub_check_pages_present(array $targets, array $pages_by_line): array {
	$findings   = array();
	$unverified = array();
	foreach ($targets as $target) {
		$found = $pages_by_line[$target] ?? array();
		if (!$found) {
			$unverified[] = "No page supplied for target {$target}. "
				. 'If that branch shipped a release, its page is missing.';
			continue;
		}
		if (1 < count($found)) {
			$findings[] = sprintf(
				'Target %s has more than one page: %s. A release publishes one page per branch.',
				$target,
				implode(', ', $found)
			);
		}
	}

	$declared = array_flip($targets);
	foreach (array_keys($pages_by_line) as $line) {
		if (!isset($declared[$line])) {
			$findings[] = "Page supplied for {$line}, which the scope does not list as a target.";
		}
	}

	return array('findings' => $findings, 'unverified' => $unverified);
}

/**
 * Where the build puts each source file it relocates.
 *
 * A version page lists the paths a reader finds in the release they downloaded,
 * not the paths this repository stores. For most files those are the same thing
 * with `src/` removed. They are not the same for anything under
 * `src/js/_enqueues/`, which the build both moves and, in one case, renames:
 * `lib/emoji-loader.js` ships as `wp-includes/js/wp-emoji-loader.js`.
 *
 * Publishing a source path documents a file the reader cannot find. Verified
 * 2026-08-04 across the 400 published version pages: `_enqueues` appears on
 * exactly one of them, and every other page lists release paths.
 *
 * The mapping is read from the branch's own Gruntfile rather than kept here.
 * It differs by branch and changes between releases, and a table in this file
 * would be a second source of truth that goes stale silently.
 *
 * A failed read is an error, not an empty mapping. Every branch this tooling
 * supports carries a Gruntfile, including the oldest: verified 2026-08-04
 * across all 24 branches of the 7.0.3 wave. So `git show` failing means a bad
 * ref or a moved checkout, and returning an empty mapping there would republish
 * the exact source paths this function exists to prevent, under a run that
 * reported success. Branches older than 5.1 read fine and simply contain no
 * such block, which the parse reports as an empty mapping — the one case where
 * empty is the right answer.
 *
 * @return array<string,string> Repository path to release path.
 */
function helphub_release_paths(string $checkout, string $ref): array {
	$result = run_command(array('git', 'show', "{$ref}:Gruntfile.js"), $checkout, true);
	if (0 !== $result['code']) {
		throw new RuntimeException(
			"Unable to read Gruntfile.js from {$ref}: " . trim($result['stderr'])
			. '. Without it a page cannot name the paths the release ships.'
		);
	}

	return helphub_parse_release_paths($result['stdout']);
}

/**
 * The relocation mapping held in a Gruntfile's copy task.
 *
 * Separate from reading it so the parse can be exercised without a checkout.
 * Each entry reads:
 *
 *   [ WORKING_DIR + 'wp-admin/js/inline-edit-post.js' ]: [ './src/js/_enqueues/admin/inline-edit-post.js' ],
 *
 * @return array<string,string> Repository path to release path.
 */
function helphub_parse_release_paths(string $gruntfile): array {
	$map = array();
	preg_match_all(
		"~\[\s*WORKING_DIR\s*\+\s*'([^']+)'\s*\]\s*:\s*\[\s*'\./(src/[^']+)'~",
		$gruntfile,
		$matches,
		PREG_SET_ORDER
	);
	foreach ($matches as $match) {
		$map[$match[2]] = '/' . ltrim($match[1], '/');
	}

	return $map;
}

/**
 * Decode a `package.json` body into its shipped `@wordpress/*` pins.
 *
 * Two filters, and both matter.
 *
 * Only `@wordpress/*` entries: `package.json` pins plenty that has nothing to
 * do with a WordPress release, and this section names what core's build ships.
 *
 * Only `dependencies`, never `devDependencies`. A version page is read by
 * someone deciding whether to update their site, and build tooling is a
 * release task rather than something that ships to them. The published record
 * agrees: `version-6-9-1` lists 30 packages and not one of the five
 * `@wordpress/*` devDependencies on that branch.
 *
 * `gutenberg.sha` is out of scope for the same reason. It sits outside both
 * blocks, so this never sees it, and that is correct rather than incidental.
 *
 * Malformed JSON and a missing dependencies block both return an empty map
 * rather than throwing. A branch that predates Gutenberg-as-a-package has no
 * such entries at all, and that absence is a real answer, not a parse error —
 * the same rule the file list already follows.
 *
 * @return array<string,string> Package name to pinned version.
 */
function helphub_package_pins(string $json): array {
	$decoded = json_decode($json, true);
	if (!is_array($decoded) || !isset($decoded['dependencies']) || !is_array($decoded['dependencies'])) {
		return array();
	}

	$pins = array();
	foreach ($decoded['dependencies'] as $name => $version) {
		if (is_string($name) && is_string($version) && str_starts_with($name, '@wordpress/')) {
			$pins[$name] = $version;
		}
	}

	return $pins;
}

/**
 * Which `@wordpress/*` pins changed between two `package.json` snapshots.
 *
 * Factored out from helphub_revised_packages() so the comparison itself can be
 * exercised without a checkout. An added pin and a changed pin both belong on
 * the page, at the version the branch now ships — the "after" value, not the
 * one it replaced. An unchanged pin does not belong, and neither does anything
 * outside `@wordpress/*`, which helphub_package_pins() has already filtered out
 * before either map reaches here.
 *
 * @param array<string,string> $base_pins   Package name to version, before.
 * @param array<string,string> $branch_pins Package name to version, after.
 * @return array<string,string> Package name to the version the branch ships,
 *         for entries added or changed. Sorted by name.
 */
function helphub_diff_package_pins(array $base_pins, array $branch_pins): array {
	$revised = array();
	foreach ($branch_pins as $name => $version) {
		if (!isset($base_pins[$name]) || $base_pins[$name] !== $version) {
			$revised[$name] = $version;
		}
	}
	ksort($revised);

	return $revised;
}

/**
 * The `@wordpress/*` package pins a branch's backport revised.
 *
 * Reads `package.json` at the base a branch was cut from and at the branch
 * itself — the same pair helphub_backport_branch_files() resolves for the file
 * list — and reports the pins that changed. This is what makes the "List of
 * packages revised" section derived rather than asserted: a Gutenberg security
 * fix delivered as a package bump revises these pins, and the page has to say
 * so the way it already does for files.
 *
 * A missing `package.json` at either ref is a real answer, not an error: it
 * means the branch, or the base it was cut from, predates
 * Gutenberg-as-a-package, and an empty map is exactly what those branches
 * should publish.
 *
 * A ref that does not resolve is not that answer. Both failures exit 128, and
 * treating them alike would let a typo publish "No package was revised." on
 * every page, which is the mistake this function exists to stop making.
 *
 * **The base is read where the branch forked from it, not at its tip.** The
 * file list already compares `base...branch`, which is merge-base relative and
 * answers "what did this branch change". Reading `package.json` at the base tip
 * asks a different question — how do the two differ right now — and the two
 * answers separate the moment the base moves ahead.
 *
 * Measured on 2026-08-11, drafting 7.0.4: every backport branch sat five
 * commits behind its base, because 7.0.3 had shipped since they were cut. The
 * tip comparison reported 7.0.3's own Gutenberg bump as 7.0.4 revising the pin,
 * on twelve of twenty-four pages, naming a version *older* than the one that
 * branch already ships. A page would have told readers a security release
 * downgraded a package it never touched.
 *
 * @return array<string,string> Package name to shipped version. Sorted by name.
 */
function helphub_revised_packages(string $checkout, string $branch, string $base): array {
	$fork = unique_merge_base(
		$checkout,
		$base,
		$branch,
		"Unable to find a single merge base for {$branch} and {$base}, so the packages "
		. 'a page lists could not be read from the point the branch was cut.'
	);

	return helphub_diff_package_pins(
		helphub_package_pins(helphub_package_json($checkout, $fork)),
		helphub_package_pins(helphub_package_json($checkout, $branch))
	);
}

/**
 * A ref's `package.json`, or an empty string when it genuinely has none.
 *
 * Git reports a missing path and an unresolvable ref with the same exit code
 * and distinguishes them only in its message, so the message is what this
 * reads. Anything other than a missing path is an operational failure and
 * throws, rather than becoming a quiet empty answer on 24 pages.
 */
function helphub_package_json(string $checkout, string $ref): string {
	$result = run_command(array('git', 'show', "{$ref}:package.json"), $checkout, true);
	if (0 === $result['code']) {
		return $result['stdout'];
	}

	$stderr = trim($result['stderr']);
	if (str_contains($stderr, 'does not exist in')) {
		return '';
	}

	throw new RuntimeException(
		"Unable to read package.json at {$ref}: {$stderr}. Without it the packages "
		. 'section cannot be derived, and guessing it empty would publish a claim '
		. 'nobody checked.'
	);
}

/**
 * Page entries that name a source path instead of the path the release ships.
 *
 * Check 3 alone cannot catch these. It translates a page's paths back to
 * repository paths before comparing, and a source path translates to itself, so
 * the comparison succeeds on a page that documents a file no reader can find.
 * The mistake has to be named directly rather than inferred from a set
 * difference.
 *
 * @param array<string,string> $release_paths Repository path to release path.
 * @return string[] One finding per offending entry.
 */
function helphub_source_path_findings(string $text, array $release_paths, string $version): array {
	$lines = helphub_section_lines($text, HELPHUB_FILES_HEADING);
	if (null === $lines || !$release_paths) {
		return array();
	}
	$findings = array();
	foreach ($lines as $line) {
		$path = trim(str_replace(array("\x01", "\x02"), '', $line));
		if ('' === $path || str_starts_with($path, 'No file')) {
			continue;
		}
		$repository_path = 'src/' . ltrim($path, '/');
		if (!isset($release_paths[$repository_path])) {
			continue;
		}
		$findings[] = sprintf(
			'Page %s lists %s, which is where the repository keeps that file, not where the '
			. 'release puts it. It ships as %s.',
			$version,
			$path,
			$release_paths[$repository_path]
		);
	}

	return $findings;
}

/**
 * Keep only the production files a version page would list.
 *
 * Tests ride along with a fix but are never documented, and the two
 * release-mechanics files change on every release for reasons the page does not
 * describe.
 *
 * @param string[] $paths
 * @return array<string,bool> Path to true, for cheap merging.
 */
function helphub_documented_paths(array $paths): array {
	$mechanics  = array_flip(HELPHUB_RELEASE_MECHANICS);
	$documented = array();
	foreach ($paths as $path) {
		$path = trim($path);
		if ('' === $path || !str_starts_with($path, 'src/') || isset($mechanics[$path])) {
			continue;
		}
		$documented[$path] = true;
	}

	return $documented;
}

/** Refuse a checkout tip that differs from the recorded build. */
function helphub_manifest_branch_check(array $entry, string $checkout_commit): void {
	if (0 !== strcasecmp($entry['commit'], $checkout_commit)) {
		throw new RuntimeException(
			"Branch {$entry['branch']} is at {$checkout_commit}, but the manifest records {$entry['commit']}. "
			. 'Fetch the checkout or regenerate the manifest before documenting this build.'
		);
	}
}

function helphub_backport_branch_files(string $checkout, array $manifest, string $target): ?array {
	$entry = $manifest['targets'][$target];
	if (null === $entry) {
		return null;
	}
	$candidate = $entry['branch'];
	$branch_check = run_command(
		array('git', 'rev-parse', '--verify', '--quiet', $candidate . '^{commit}'),
		$checkout,
		true
	);
	if (0 !== $branch_check['code']) {
		return null;
	}
	$checkout_commit = trim($branch_check['stdout']);
	helphub_manifest_branch_check($entry, $checkout_commit);
	$commit = substr($checkout_commit, 0, 10);
	$base = $entry['base'];
	$base_check = run_command(
		array('git', 'rev-parse', '--verify', '--quiet', $base . '^{commit}'),
		$checkout,
		true
	);
	if (0 !== $base_check['code']) {
		return null;
	}
	$base_commit = trim($base_check['stdout']);

	$result = run_command(
		array('git', 'diff', '--no-renames', '--name-only', "{$base_commit}...{$checkout_commit}"),
		$checkout,
		true
	);
	if (0 !== $result['code']) {
		throw new RuntimeException(
			"Unable to diff {$candidate}: " . trim($result['stderr'])
		);
	}

	return array(
		'files'  => explode("\n", trim($result['stdout'])),
		'branch' => $candidate,
		'commit' => $commit,
		'base'   => $base,
	);
}

/**
 * Why a branch's file list cannot be trusted, or null when it can.
 *
 * A branch that is not in the checkout is loud: helphub_backport_branch_files()
 * returns null and every caller stops. A branch that exists and carries nothing
 * is silent, and it produces a page whose revised-file section is empty. For a
 * security release that is never true — a fix changes a file by definition.
 *
 * Measured on 2026-08-10, preparing 7.0.4: a grouped branch set held 24
 * branches, and 17 of them had been created from their base with the fix never
 * applied. The generator reported a file count of zero for each, exited 0, and
 * would have written seventeen pages documenting no changes at all.
 *
 * Both cases below produce that same empty section, so both fail. They are
 * different diagnoses and the caller repeats whichever one it got, because
 * "the backport is missing" and "the backport touched nothing a page lists"
 * send whoever reads the message to different places.
 *
 * @param array{files:string[],branch:string,commit:string,base:string} $branch
 * @return string|null Reason the list is unusable, null when it is fine.
 */
function helphub_unusable_file_list(array $branch): ?string {
	$changed = array();
	foreach ($branch['files'] as $path) {
		$path = trim($path);
		if ('' !== $path) {
			$changed[] = $path;
		}
	}

	if (!$changed) {
		return sprintf(
			'%s at %s is identical to %s, so the backport is not on the branch.',
			$branch['branch'],
			$branch['commit'],
			$branch['base']
		);
	}

	if (!helphub_documented_paths($changed)) {
		return sprintf(
			'%s at %s changes only files a version page never lists (%s), so it has nothing to document.',
			$branch['branch'],
			$branch['commit'],
			implode(', ', $changed)
		);
	}

	return null;
}

/** Read the recorded branch files, or report reaching fixes as unverified when unavailable. */
function helphub_expected_files(
	string $checkout,
	array $manifest,
	string $target
): array {
	$branch = helphub_backport_branch_files($checkout, $manifest, $target);
	if (null !== $branch) {
		$files = array_keys(helphub_documented_paths($branch['files']));
		sort($files);

		return array(
			'files'     => $files,
			'unpinned'  => array(),
			'source'    => 'branch',
			'ref'       => $branch['branch'],
			'read_from' => "{$branch['branch']} at {$branch['commit']}",
			'problem'   => helphub_unusable_file_list($branch),
		);
	}

	$expected = array();
	$unpinned = array();
	foreach ($manifest['fixes'] as $number => $targets) {
		if (!in_array($target, $targets, true)) {
			continue;
		}
		$unpinned[] = $number;
	}

	$files = array_keys($expected);
	sort($files);

	return array(
		'files'     => $files,
		'unpinned'  => $unpinned,
		'source'    => 'references',
		'ref'       => null,
		'read_from' => 'pinned fix references',
		'problem'   => null,
	);
}

/**
 * Check 3: a page's revised-file list matches the fixes that reached its branch.
 *
 * @param string[] $listed
 * @param string[] $expected
 * @return string[] Findings.
 */
function helphub_check_file_list(string $version, array $listed, array $expected): array {
	$missing = array_diff($expected, $listed);
	$extra   = array_diff($listed, $expected);

	$findings = array();
	if ($missing) {
		$findings[] = sprintf(
			"Page %s omits files the fixes for this branch changed:\n    %s",
			$version,
			implode("\n    ", $missing)
		);
	}
	if ($extra) {
		$findings[] = sprintf(
			"Page %s lists files no fix for this branch changed:\n    %s",
			$version,
			implode("\n    ", $extra)
		);
	}

	return $findings;
}
