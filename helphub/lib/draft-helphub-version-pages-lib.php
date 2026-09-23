<?php

declare(strict_types=1);

/**
 * Page assembly for draft-helphub-version-pages.php.
 *
 * Separated from the CLI entry point so the test suite can exercise the
 * assembly directly, the way every other tool in this repository pairs a script
 * with its `-lib.php`.
 */

/**
 * The page slug wordpress.org uses for a version.
 *
 * `7.0.3` is published at `version-7-0-3`. Deriving it rather than accepting one
 * means a draft cannot be created at an address the checker would then fail to
 * find, and it keeps the links this file writes pointing at the pages the push
 * tool creates.
 */
function helphub_version_slug(string $version): string {
	if (1 !== preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $version)) {
		throw new InvalidArgumentException("Not a version: {$version}");
	}

	return 'version-' . str_replace('.', '-', $version);
}

/**
 * The public address of a version page.
 */
function helphub_version_url(string $version): string {
	return 'https://wordpress.org/documentation/wordpress-version/' . helphub_version_slug($version) . '/';
}

/**
 * Wrap one paragraph's inner HTML in the delimiters WordPress stores.
 *
 * A paragraph carries no class. `wp-block-paragraph` looks like a real core
 * class because every other block here keeps one, but it is not one WordPress
 * emits; a page that had it would be flagged by nothing, because nothing reads
 * class names, and would simply be a freeform block the day someone finally
 * checked. Verified against the published 7.0.2 page 2026-08-04.
 */
function helphub_paragraph(string $html): string {
	return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
}

/**
 * Wrap one heading's inner HTML in the delimiters WordPress stores.
 *
 * Level 2 carries no attribute comment; every other level states `{"level":N}`
 * because 2 is the block's own default and Gutenberg omits an attribute that
 * matches its default rather than writing it out. `class="wp-block-heading"`
 * is kept at every level — unlike the paragraph class, this one is real.
 */
function helphub_heading(string $html, int $level = 2): string {
	$attrs = 2 === $level ? '' : sprintf(' {"level":%d}', $level);

	return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">{$html}</h{$level}>\n<!-- /wp:heading -->";
}

/**
 * Wrap a preformatted block's inner HTML in the delimiters WordPress stores.
 *
 * This is the block a version page's file list actually needs: WordPress only
 * renders `core/preformatted`'s stylesheet and theme.json styles — the grey
 * bordered box in IBM Plex Mono every published page shows — when it finds
 * these delimiters. Without them the parser treats the markup as one freeform
 * block and the list renders as bare monospace with none of that styling.
 */
function helphub_preformatted(string $html): string {
	return "<!-- wp:preformatted -->\n<pre class=\"wp-block-preformatted\">{$html}</pre>\n<!-- /wp:preformatted -->";
}

/**
 * Wrap a set of `<li>...</li>` strings in the list delimiters WordPress stores.
 *
 * The whitespace here is exact, not cosmetic: the first `wp:list-item` comment
 * shares its line with the opening `<ul>`, the last shares its line with the
 * closing `</ul>`, and a blank line separates each item from the next. That is
 * the one place in this format where two comments share a content line, and
 * getting it wrong is invisible in a rendered preview — it only shows up as a
 * parse failure, which is exactly the failure mode this whole file exists to
 * stop reproducing by hand.
 *
 * @param string[] $items Each a complete `<li>...</li>` string.
 */
function helphub_list(array $items): string {
	if (!$items) {
		throw new InvalidArgumentException('A list needs at least one item.');
	}

	$items = array_values($items);
	$count = count($items);
	$lines = array('<!-- wp:list -->');
	foreach ($items as $index => $item) {
		if (0 < $index) {
			$lines[] = '';
		}
		$lines[] = 0 === $index ? '<ul class="wp-block-list"><!-- wp:list-item -->' : '<!-- wp:list-item -->';
		$lines[] = $item;
		$lines[] = $index === $count - 1 ? '<!-- /wp:list-item --></ul>' : '<!-- /wp:list-item -->';
	}
	$lines[] = '<!-- /wp:list -->';

	return implode("\n", $lines);
}

/**
 * The Installation/Update Information block, identical on every version page.
 *
 * Reproduced from the published pages, which have carried it unchanged since at
 * least September 2025. It is emitted rather than copied, which is the point:
 * a block that is generated cannot pick up the edit-in-place damage that
 * duplicating the previous release's page produces.
 *
 * The links here are repaired rather than reproduced. Verified 2026-08-04:
 *
 *   - "WordPress Lessons" pointed at `/support/article/wordpress-lessons/`,
 *     which returns 404, as does the `/documentation/` path. That content moved
 *     to learn.wordpress.org. Every published version page carries the dead
 *     link, because each was copied from the one before it.
 *   - Four more links resolved only through a redirect. Each now points at
 *     where it actually lands: the release archive, the getting-started
 *     article, first steps, and the extended upgrade instructions.
 *
 * Improving these pages is the job, so the fix belongs here rather than in a
 * note for later. Re-check with `check-helphub-version-pages.php --check-links`,
 * which resolves every link on a page and reports any that do not lead
 * anywhere.
 *
 * A function rather than a `const`: assembling it from the same block helpers
 * every other block on the page uses means it cannot drift from their exact
 * delimiter shape the way a separately hand-typed string could.
 */
function helphub_install_boilerplate(): string {
	return implode("\n\n", array(
		helphub_heading('Installation/Update Information'),
		helphub_paragraph(
			'To get this version, update automatically from the Dashboard &gt; Updates menu in your site\'s '
			. 'admin area or visit <a href="https://wordpress.org/download/releases/">https://wordpress.org/download/releases/</a>.'
			. '<br>For step-by-step instructions on installing and updating WordPress:'
		),
		helphub_list(array(
			'<li><a href="https://wordpress.org/documentation/article/updating-wordpress/">Updating WordPress</a></li>',
		)),
		helphub_paragraph('If you are new to WordPress, we recommend that you begin with the following:'),
		helphub_list(array(
			'<li><a href="https://wordpress.org/documentation/article/get-started-with-wordpress/">Get Started With WordPress</a></li>',
			'<li><a href="https://wordpress.org/documentation/article/first-steps-with-wordpress-classic/">First Steps With WordPress</a> or '
				. '<a href="https://developer.wordpress.org/advanced-administration/upgrade/upgrading/">Upgrading WordPress Extended</a></li>',
			'<li><a href="https://learn.wordpress.org/courses/">WordPress Courses</a></li>',
		)),
	));
}

const HELPHUB_SECURITY_OPENING = 'This release features several security fixes. Because this is a security release, '
	. '<strong>it is recommended that you update your sites immediately.</strong>';

const HELPHUB_THANKS_LINE = 'The security team would like to thank the following people for '
	. '<a href="https://hackerone.com/wordpress?type=team">responsibly reporting vulnerabilities</a>, '
	. 'and allowing them to be fixed in this release:';

/** Marks a statement the generator cannot derive and refuses to guess. */
const HELPHUB_FILL_IN = '<!-- FILL IN: %s -->';

/**
 * The oldest branch WordPress still backports security fixes to. Every scope's
 * target list stops here by policy, so an empty list of branches below the
 * oldest released version means the wave reached this floor — it does not mean
 * older branches were checked and found unaffected.
 */
const HELPHUB_SECURITY_SUPPORT_FLOOR = '4.7';

/**
 * The line to publish when a release wave reaches the support floor.
 *
 * This sentence and the floor above move together: if the floor ever changes,
 * update both. The version just below a floor is not derivable from the floor
 * itself — the version before 5.0 is 4.9, not "5.0 minus 0.1" — so the wording
 * is stored as a literal rather than generated.
 */
const HELPHUB_SECURITY_SUPPORT_FLOOR_NOTICE = 'WordPress 4.6 and earlier no longer receive security updates.';

/**
 * Initialisms in this domain that are said as words, not letter by letter.
 *
 * The article a phrase takes follows how its first word sounds, not how it is
 * spelled. "REST" is said "rest", so it takes "a"; "XSS" is said "ex-ess-ess",
 * so it takes "an". Nothing in the spelling distinguishes them, so the ones
 * that are said as words are listed.
 */
const HELPHUB_SPOKEN_AS_WORDS = array('REST', 'AJAX', 'PHP', 'JSON', 'CSRF', 'DOM', 'CORS');

/** Initialism letters whose name begins with a vowel sound: ay, ee, ef, ell... */
const HELPHUB_VOWEL_SOUND_LETTERS = 'AEFHILMNORSX';

/**
 * Choose "A" or "An" for a phrase that is about to open a credit line.
 *
 * Published pages read "A REST API batch-route confusion…", "An XSS that
 * allows…", "An AJAX query-attachments authorization bypass…". All three follow
 * ordinary English, which means sound rather than spelling.
 */
function helphub_indefinite_article(string $phrase): string {
	$first = strtok(trim($phrase), " \t\n");
	if (false === $first || '' === $first) {
		return 'A';
	}
	$word = preg_replace('/[^A-Za-z]/', '', $first) ?? $first;
	if ('' === $word) {
		return 'A';
	}

	$is_initialism = 2 <= strlen($word)
		&& $word === strtoupper($word)
		&& !in_array(strtoupper($word), HELPHUB_SPOKEN_AS_WORDS, true);

	if ($is_initialism) {
		return false === strpos(HELPHUB_VOWEL_SOUND_LETTERS, strtoupper($word[0])) ? 'A' : 'An';
	}

	return 1 === preg_match('/^[aeiou]/i', $word) ? 'An' : 'A';
}

/**
 * Turn an advisory summary into the opening of a credit line.
 *
 * The advisory "REST API batch-route confusion and SQL injection issue leading
 * to Remote Code Execution" becomes "A REST API batch-route confusion and …",
 * which is the published 7.0.2 credit line word for word. A summary that
 * already opens with an article is left alone.
 */
function helphub_credit_opening(string $summary): string {
	$summary = trim($summary);
	if ('' === $summary) {
		throw new InvalidArgumentException('Advisory summary is empty.');
	}
	if (1 === preg_match('/^(a|an|the)\s/i', $summary)) {
		return $summary;
	}

	return helphub_indefinite_article($summary) . ' ' . $summary;
}

/**
 * Render the release date the way every published page writes it.
 *
 * Long-form US English, matching "On July 17, 2026, WordPress 7.0.2 was
 * released to the public."
 */
function helphub_release_date(string $iso): string {
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
	if (false === $date) {
		throw new InvalidArgumentException("Release date must be YYYY-MM-DD, got: {$iso}");
	}

	return $date->format('F j, Y');
}

/**
 * The branch line a released version belongs to. `6.9.6` belongs to `6.9`.
 */
function helphub_draft_line(string $version): string {
	$parts = explode('.', $version);
	if (2 > count($parts)) {
		throw new InvalidArgumentException("Version must be at least X.Y, got: {$version}");
	}

	return $parts[0] . '.' . $parts[1];
}

/**
 * Order versions newest first, comparing numerically rather than as strings.
 *
 * @param string[] $versions
 * @return string[]
 */
function helphub_sort_versions(array $versions): array {
	usort(
		$versions,
		static fn(string $a, string $b): int => version_compare($b, $a)
	);

	return $versions;
}

/**
 * The credit bullets for one branch, in the scope's own fix order.
 *
 * A fix that reached the branch but has no authored credit becomes a fill-in
 * rather than being dropped. A missing credit is a page that under-credits a
 * reporter, which is the error a human caught on the published 7.0.2 page; it
 * must be impossible to miss in a draft.
 *
 * @param array<int,string[]> $fixes   Fix number to its targets.
 * @param array<int,string>   $credits Fix number to its authored credit line.
 * @return array{bullets:string[],missing:int[]}
 */
function helphub_credit_lines(array $fixes, array $credits, string $line): array {
	$bullets = array();
	$missing = array();
	foreach ($fixes as $number => $targets) {
		if (!in_array($line, $targets, true)) {
			continue;
		}
		$credit = $credits[$number] ?? null;
		if (!helphub_credit_is_authored($credit)) {
			$missing[] = $number;
		}
		$bullets[] = $credit ?? sprintf(HELPHUB_FILL_IN, "credit line for fix #{$number}");
	}

	return array('bullets' => $bullets, 'missing' => $missing);
}

function helphub_credit_is_authored(?string $credit): bool {
	return null !== $credit && false === strpos($credit, 'FILL IN');
}

/**
 * How many of the release's fixes reached one branch.
 *
 * @param array<int,string[]> $fixes
 */
function helphub_fix_count(array $fixes, string $line): int {
	$count = 0;
	foreach ($fixes as $targets) {
		if (in_array($line, $targets, true)) {
			$count++;
		}
	}

	return $count;
}

/**
 * The courtesy block, which only the release's lead page carries.
 *
 * Published pages state this two ways. March 2026 put one generic sentence on
 * all 23 pages; July 2026 enumerated each older release on the lead page and
 * left it off the others. This follows July, which is both newer and derivable.
 *
 * Counts are stated rather than ordinals. "Affected by the first vulnerability"
 * requires knowing which bullet is first on a page the reader has not seen; "two
 * of the three" is true regardless of order.
 *
 * The closing line depends on what the target list can actually prove.
 *
 * A wave that reached the support floor gets the floor's own sentence, never
 * the "not affected" claim. Nothing below the floor is ever a target, so its
 * absence from the scope proves nothing about those branches.
 *
 * Above the floor, a non-empty list of lower branches that no fix reached is
 * real evidence: they were targeted and none were touched, so "versions prior
 * to X are not affected" holds. Anything else is a fill-in — a fix reaching a
 * branch that got no page, or a target list that stops short of the floor for
 * a reason the generator cannot see.
 *
 * @param array<int,string[]> $fixes
 * @param string[]            $versions All released versions, newest first.
 * @return string[] Block lines.
 */
function helphub_courtesy_block(array $fixes, array $versions, array $targets): array {
	$total = count($fixes);
	$lines   = array();
	$lines[] = helphub_paragraph(
		'As a courtesy, these fixes are also available in older '
		. 'affected branches of WordPress. As a reminder, <strong>only the most recent version of '
		. 'WordPress is actively supported.</strong>'
	);
	$lines[] = '';

	$items      = array();
	$companions = array_slice($versions, 1);
	foreach ($companions as $version) {
		$line  = helphub_draft_line($version);
		$count = helphub_fix_count($fixes, $line);
		// The version names its own page. This is the only route a reader has
		// from the release they are on to the one that fixes their branch, and
		// the published lead pages link it, so an unlinked bullet is a dead end
		// the generator would otherwise reproduce two dozen times.
		$link = sprintf(
			'<a href="%s">Version %s</a>',
			helphub_version_url($version),
			$version
		);
		// A one-fix release is not a smaller version of a twelve-fix one. "All 1
		// vulnerabilities" is wrong three ways at once — the count, the noun, and
		// "all of them" — and it would read that way on every companion bullet of
		// the lead page. The enumerated block only ever ran on multi-fix waves
		// before 7.0.4, so no published page shows the singular.
		if ($count === $total) {
			$text = 1 === $total
				? sprintf(
					'WordPress %s is affected by this vulnerability. %s has been released containing a fix.',
					$line,
					$link
				)
				: sprintf(
					'WordPress %s is affected by all %d vulnerabilities. %s has been released containing fixes for all of them.',
					$line,
					$total,
					$link
				);
		} else {
			$text = sprintf(
				'WordPress %s is affected by %d of the %d %s. %s has been released containing fixes.',
				$line,
				$count,
				$total,
				1 === $total ? 'vulnerability' : 'vulnerabilities',
				$link
			);
		}
		$items[] = "<li>{$text}</li>";
	}

	// Only claim older branches are unaffected when the target list proves it.
	$oldest      = end($versions);
	$oldest_line = helphub_draft_line((string) $oldest);
	$below       = array();
	foreach ($targets as $target) {
		if (0 > version_compare($target, $oldest_line)) {
			$below[] = $target;
		}
	}
	$reached_below = false;
	foreach ($fixes as $fix_targets) {
		if (array_intersect($below, $fix_targets)) {
			$reached_below = true;
			break;
		}
	}
	if (0 >= version_compare($oldest_line, HELPHUB_SECURITY_SUPPORT_FLOOR)) {
		// The wave reached the support floor, so nothing below it was ever a
		// target. That is the floor showing, not evidence older branches were
		// checked and found unaffected.
		$items[] = '<li>' . HELPHUB_SECURITY_SUPPORT_FLOOR_NOTICE . '</li>';
	} elseif ($below && !$reached_below) {
		$items[] = sprintf(
			'<li>Versions of WordPress prior to %s are not affected.</li>',
			$oldest_line
		);
	} else {
		$items[] = '<li>' . sprintf(
			HELPHUB_FILL_IN,
			$below
				? 'this release also reached ' . implode(', ', $below)
					. ', which published no page. State what is true for older branches.'
				: 'no branch below ' . $oldest_line . ' was targeted, and the wave '
					. 'stopped above the support floor. State what is true for older branches.'
		) . '</li>';
	}
	$lines[] = helphub_list($items);

	return $lines;
}

/**
 * Turn repository paths into the Subversion-rooted paths a page lists.
 *
 * @param string[] $paths
 * @return string[]
 */
function helphub_page_paths(array $paths, array $release_paths = array()): array {
	$page_paths = array();
	foreach ($paths as $path) {
		// A file the build relocates ships somewhere other than where the
		// repository keeps it, so removing `src/` would name a path the reader
		// cannot find in the release they downloaded.
		$page_paths[] = $release_paths[$path] ?? '/' . preg_replace('~^src/~', '', $path);
	}
	sort($page_paths);

	return $page_paths;
}

/**
 * Assemble one version page.
 *
 * @param string[]             $files         Repository paths the branch changed.
 * @param array<int,string[]>  $fixes
 * @param array<int,string>    $credits
 * @param string[]             $versions      All released versions, newest first.
 * @param string[]             $targets       Every branch in the release scope.
 * @param array<string,string> $release_paths Repository path to release path.
 * @param array<string,string> $packages      Package name to shipped version,
 *                                            from helphub_revised_packages().
 * @return array{body:string,missing_credits:int[]}
 */
function helphub_draft_page(
	string $version,
	string $date,
	array $files,
	array $fixes,
	array $credits,
	array $versions,
	array $targets,
	array $release_paths = array(),
	array $packages = array()
): array {
	$line    = helphub_draft_line($version);
	$credit  = helphub_credit_lines($fixes, $credits, $line);
	$is_lead = $version === ($versions[0] ?? $version);

	$out   = array();
	$out[] = helphub_paragraph(
		sprintf('On %s, WordPress %s was released to the public.', helphub_release_date($date), $version)
	);
	$out[] = '';
	$out[] = helphub_install_boilerplate();
	$out[] = '';
	$out[] = helphub_heading('Summary');
	$out[] = '';
	$out[] = helphub_heading('Security updates', 3);
	$out[] = '';
	$out[] = helphub_paragraph(HELPHUB_SECURITY_OPENING . '<br>' . HELPHUB_THANKS_LINE);
	$out[] = '';
	$out[] = helphub_list(array_map(
		static fn(string $bullet): string => "<li>{$bullet}</li>",
		$credit['bullets']
	));

	if ($is_lead && 1 < count($versions)) {
		$out[] = '';
		foreach (helphub_courtesy_block($fixes, $versions, $targets) as $courtesy) {
			$out[] = $courtesy;
		}
	}

	$out[] = '';
	$out[] = helphub_heading('Change log');
	$out[] = '';
	$out[] = helphub_heading('List of files revised', 3);
	$out[] = '';
	// Preformatted, not a paragraph. Every published page renders this list in
	// monospace, which is what keeps a column of file paths readable. Verified
	// 2026-08-04 across 7.0.2, 6.9.5, 6.8.6, 6.7.5 and 6.6.5.
	//
	// The separator is a bare `<br>`. Inside `<pre>` a newline is content, so
	// `<br>\n` would render as a break plus a blank line and double-space the
	// whole column. A paragraph collapses that whitespace and hides the
	// mistake; this element does not.
	$out[] = helphub_preformatted(implode('<br>', helphub_page_paths($files, $release_paths)));
	$out[] = '';
	$out[] = helphub_heading('List of packages revised', 3);
	$out[] = '';
	if ($packages) {
		// Preformatted, same as the file list above, and for the same reason:
		// every published page renders this list in monospace, and the same bare
		// `<br>` rule applies — a newline here is content inside `<pre>`, and
		// `<br>\n` would double-space the column.
		$sorted = $packages;
		ksort($sorted);
		$lines = array();
		foreach ($sorted as $name => $shipped_version) {
			$lines[] = "{$name} - {$shipped_version}";
		}
		$out[] = helphub_preformatted(implode('<br>', $lines));
	} else {
		// "No package was revised" is now a derived conclusion, not an assertion:
		// helphub_revised_packages() found no changed @wordpress/* pin between
		// this branch and the base it was cut from. The sentence is kept
		// byte-identical to every published page.
		$out[] = helphub_paragraph('No package was revised.');
	}

	return array(
		'body'            => implode("\n", $out) . "\n",
		'missing_credits' => $credit['missing'],
	);
}
