#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline HelpHub checks: no network, no git, no subprocesses or disk fixtures. */

foreach (glob(dirname(__DIR__) . '/lib/*.php') as $library) {
	require_once $library;
}

$failures   = array();
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void {
	++$GLOBALS['assertions'];
	if ($expected !== $actual) {
		$GLOBALS['failures'][] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
	}
}

function check_throws(string $label, string $class, callable $callback): void {
	++$GLOBALS['assertions'];
	try {
		$callback();
		$GLOBALS['failures'][] = "{$label}: expected {$class}";
	} catch (Throwable $error) {
		if (!$error instanceof $class) {
			$GLOBALS['failures'][] = "{$label}: expected {$class}, got " . $error::class;
		}
	}
}

// Planned rows look like shipped rows. A Changelog-only link and an adjacent date also defeated earlier href-counting and whole-row text parsers.
$index_html = <<<'HTML'
<table><tr><th>Version</th><th>Release Date</th></tr>
<tr><td><strong>7.0.2</strong></td><td>September 5, 2026</td><td><a href="/version-7-0-2/">Changelog</a></td></tr>
<tr><td>Notes</td><td>7.0.3</td></tr></table>
<table><tr><th>Version</th><th>Planned Release Date</th></tr>
<tr><td><a href="/version-7-2/">7.2</a></td><td>December 1, 2026</td></tr></table>
<table><tr><th>Version</th><th>Release Date</th></tr>
<tr><td><a href="/version-6-9-4/">6.9.4</a></td><td>September 6, 2026</td></tr></table>
HTML;
check('Only shipped first-cell versions enter the index', array('7.0.2', '6.9.4'), helphub_index_versions($index_html));

// The wave uses positive fix-set evidence, not date proximity. Older missing rows remain in the full reconciliation even when the lead is already indexed.
$wave_pages = array(
	'7.0.2' => array('date_key' => '2026-09-05', 'fix_set' => array('synthetic fix a')),
	'6.9.4' => array('date_key' => '2026-09-12', 'fix_set' => array('synthetic fix a')),
	'6.8.5' => array('date_key' => '2026-09-13', 'fix_set' => array('synthetic fix a')),
	'6.7.6' => array('date_key' => '2026-09-06', 'fix_set' => array('synthetic fix b')),
	'6.6.7' => array('date_key' => '2026-09-06', 'fix_set' => array()),
	'6.5.8' => array('date_key' => '2026-08-01', 'fix_set' => array('synthetic fix a')),
);
check('Wave includes the seven-day sibling but excludes unrelated and older pages', array('7.0.2', '6.9.4'), helphub_index_release_wave($wave_pages, '7.0.2'));
check('Full reconciliation retains older gaps and orphaned rows', array(
	'missing' => array('6.9.4', '6.5.8'), 'orphaned' => array('7.2'),
), helphub_index_reconcile(array_keys($wave_pages), array('7.0.2', '6.8.5', '6.7.6', '6.6.7', '7.2')));
$unknown_wave = array('7.0.2' => array('date_key' => '2026-09-05', 'fix_set' => array()));
check_throws('An empty fix set cannot prove a release wave', Throwable::class, static fn() => helphub_index_release_wave($unknown_wave, '7.0.2'));

// The index may already contain the lead. A missing sibling still needs that lead's announcement, while a nearer maintenance post cannot supply it.
$announcement_pages = array(
	'6.9.4' => array('date' => 'September 7, 2026', 'date_key' => '2026-09-07', 'fix_set' => array('synthetic fix a')),
	'7.0.3' => array('date' => 'September 6, 2026', 'date_key' => '2026-09-06', 'fix_set' => array('synthetic fix b')),
	'7.0.2' => array('date' => 'September 5, 2026', 'date_key' => '2026-09-05', 'fix_set' => array('synthetic fix a')),
);
$search = static fn(string $term): array => array(
	array('link' => 'https://example.test/maintenance', 'date' => '2026-09-06', 'title' => array('rendered' => 'WordPress 7.0.3')),
	array('link' => 'https://example.test/lead', 'date' => '2026-09-05', 'title' => array('rendered' => 'WordPress 7.0.2')),
);
$exact_cache = array();
$search_cache = array();
check('A missing sibling reuses the same-fix lead announcement', array('link' => 'https://example.test/lead', 'source' => 'wave:7.0.2'),
	helphub_index_announcement_for_page('6.9.4', $announcement_pages, $search, $exact_cache, $search_cache));

// A period ending a sentence is valid; digits extending a version are not.
foreach (array('Fixed in WordPress 7.0.2.' => true, 'WordPress 7.0.20' => false, 'WordPress 7.0.2.1' => false) as $title => $matches) {
	$post = array('link' => 'https://example.test/announcement', 'date' => '2026-09-05', 'title' => array('rendered' => $title));
	check('Announcement version boundary: ' . $title, $matches,
		null !== helphub_index_rank_announcement_posts('7.0.2', 'September 5, 2026', array($post)));
}

$title_post = array('link' => 'https://example.test/release', 'date' => '2026-09-05', 'title' => array('rendered' => 'WordPress 7.0.2'));
$body_mention = array('link' => 'https://example.test/mention', 'date' => '2026-09-05', 'content' => array('rendered' => 'See WordPress 7.0.2.'));
check('A release title outranks an earlier search result mentioning the version', $title_post['link'],
	helphub_index_rank_announcement_posts('7.0.2', 'September 5, 2026', array($body_mention, $title_post))['link']);
$title_post['date'] = '2026-09-04';
check('Even a matching title cannot announce a release before it shipped', null,
	helphub_index_rank_announcement_posts('7.0.2', 'September 5, 2026', array($title_post)));

// Two fixes, one that does not reach the branch: 7.0 gets only the reaching fix, markup stripped.
$fixes = array(
	1001 => array('7.1', '7.0'),
	1002 => array('7.1'),
);
$credits = array(
	1001 => 'A REST API confusion issue reported by <a href="https://example.test/a">Jane Doe</a>',
	1002 => 'An XSS in the block editor reported by John Smith',
);
$expected_71 = helphub_credits_expected_lines($fixes, $credits, '7.1');
check('Both fixes reach 7.1', array(
	'A REST API confusion issue reported by Jane Doe',
	'An XSS in the block editor reported by John Smith',
), $expected_71['lines']);
$expected_70 = helphub_credits_expected_lines($fixes, $credits, '7.0');
check('Only the reaching fix appears for 7.0', array(
	'A REST API confusion issue reported by Jane Doe',
), $expected_70['lines']);
check('Neither branch has a fill-in', array(), $expected_70['fill_ins']);

// Comparing as multisets: order never matters, but a real difference names both sides.
check('Matching multisets compare clean', array('missing' => array(), 'unexpected' => array()),
	helphub_credits_compare(array('A', 'B'), array('B', 'A')));
check('One missing and one unexpected line, both named', array(
	'missing'    => array('A REST API confusion issue reported by Jane Doe'),
	'unexpected' => array('A REST API confusion issue reported by John Q. Public'),
), helphub_credits_compare(
	array('A REST API confusion issue reported by Jane Doe'),
	array('A REST API confusion issue reported by John Q. Public')
));
check('A duplicated live bullet is unexpected once, not a pass', array(
	'missing'    => array(),
	'unexpected' => array('A (duplicate)'),
), helphub_credits_compare(array('A'), array('A', 'A')));
check('A missing second occurrence is reported once', array(
	'missing'    => array('A'),
	'unexpected' => array(),
), helphub_credits_compare(array('A', 'A'), array('A')));

// A FILL IN credit can never pass, even if a naive set comparison would call it OK.
$fixes_with_gap   = array(2001 => array('6.9'));
$credits_with_gap = array(); // No authored credit recorded for fix 2001.
$expected_gap = helphub_credits_expected_lines($fixes_with_gap, $credits_with_gap, '6.9');
check('An unauthored credit produces no comparable line', array(), $expected_gap['lines']);
check('An unauthored credit is named in fill_ins', array(2001), $expected_gap['fill_ins']);
check(
	'A set comparison alone would wrongly call this OK; fill_ins is what stops it',
	array('missing' => array(), 'unexpected' => array()),
	helphub_credits_compare($expected_gap['lines'], array()));

// A FILL IN marker split by inline markup or joined by &nbsp; still reads as unfilled once normalized.
$fixes_split_marker   = array(3001 => array('7.0'), 3002 => array('7.0'));
$credits_split_marker = array(
	3001 => 'An issue reported by Jane Doe, credit FILL&nbsp;IN',
	3002 => 'An issue reported by John Smith, credit <em>FILL</em> IN',
);
$expected_split_marker = helphub_credits_expected_lines($fixes_split_marker, $credits_split_marker, '7.0');
check('An &nbsp;-joined or markup-split FILL IN produces no comparable line', array(),
	$expected_split_marker['lines']);
check('Both fixes land in fill_ins once normalized', array(3001, 3002),
	$expected_split_marker['fill_ins']);

// Typographic punctuation normalizes the same on either side of a comparison.
check("A straight and a rendered curly apostrophe normalize the same",
	helphub_credits_normalize_line("An issue reported by O'Neil"),
	helphub_credits_normalize_line('An issue reported by O&#8217;Neil'));
check('An en dash and a hyphen normalize the same',
	helphub_credits_normalize_line('WordPress 6.7–6.9 are affected'),
	helphub_credits_normalize_line('WordPress 6.7-6.9 are affected'));

// A draft can be created with the same slug as its published sibling; refuse rather than guess which is live.
check_throws('Two posts sharing a slug refuse rather than pick one', Throwable::class, static function (): void {
	helphub_credits_slug_to_id(array(
		array('id' => 10, 'slug' => 'version-7-0-3', 'status' => 'publish'),
		array('id' => 11, 'slug' => 'version-7-0-3', 'status' => 'draft'),
	));
});
check('A single post per slug resolves normally', array('version-7-0-3' => 10),
	helphub_credits_slug_to_id(array(
		array('id' => 10, 'slug' => 'version-7-0-3', 'status' => 'publish'),
	)));

// A release wave should name one version per branch; two on the same line is ambiguous, not a pick-one.
check_throws('Two wave versions on the same branch refuse rather than pick one', Throwable::class, static function (): void {
	helphub_credits_version_by_line(array('7.0.4', '7.0.3'));
});
check('One version per branch resolves normally', array('7.0' => '7.0.4', '6.9' => '6.9.2'),
	helphub_credits_version_by_line(array('7.0.4', '6.9.2')));

// 1.6 has a published page with no index row by content decision, not a gap.
check('1.6 splits out as a known exception', array(
	'known' => array('1.6'),
	'rest'  => array('7.2', '6.9.4'),
), helphub_index_split_known(array('7.2', '1.6', '6.9.4'), HELPHUB_INDEX_KNOWN_EXCEPTIONS));
check('An empty known list splits nothing out', array('known' => array(), 'rest' => array('7.2')),
	helphub_index_split_known(array('7.2'), array()));

// A branch the wave did not reach always has older pages; only one dated within the wave window is a stray.
$wave_pages = array(
	'7.0.4'  => array('date_key' => '2026-09-16'),
	'6.9.3'  => array('date_key' => '2026-09-16'),
	'6.8.9'  => array('date_key' => '2026-09-15'),
	'6.8.8'  => array('date_key' => '2026-08-12'),
	'6.7.10' => array('date_key' => null),
	'6.6.13' => array('date_key' => '2026-08-12'),
);
$wave = array('7.0.4', '6.9.3');
check('A same-window page excluded from the wave is a stray', '6.8.9',
	helphub_credits_wave_stray($wave_pages, $wave, '6.8', '2026-09-16'));
check('An older page on the branch is not a stray', null,
	helphub_credits_wave_stray($wave_pages, $wave, '6.6', '2026-09-16'));
check('A page with no readable date is not a stray', null,
	helphub_credits_wave_stray($wave_pages, $wave, '6.7', '2026-09-16'));
check('A wave member is never a stray', null,
	helphub_credits_wave_stray($wave_pages, $wave, '6.9', '2026-09-16'));
check('No lead date means no stray verdict', null,
	helphub_credits_wave_stray($wave_pages, $wave, '6.8', null));

// HelpHub's pure request builders make publishing and broad replacement impossible.
check(
	'HelpHub payloads are always drafts',
	'draft',
	helphub_draft_payload('7.0.3', 'Release notes')['status']);
check_throws(
	'HelpHub refuses an empty draft', Throwable::class,
	static fn(): array => helphub_draft_payload('7.0.3', '   '));
check(
	'HelpHub replacement changes one exact anchor',
	'before new after',
	helphub_apply_replacement('before old after', 'old', 'new'));
$missing_anchor = null;
try {
	helphub_apply_replacement('body', 'absent', 'new');
} catch (Throwable $error) {
	$missing_anchor = $error;
}
check('HelpHub refuses a missing replacement anchor', true, $missing_anchor instanceof RuntimeException);
$ambiguous_anchor = null;
try {
	helphub_apply_replacement('old and old', 'old', 'new');
} catch (Throwable $error) {
	$ambiguous_anchor = $error;
}
check('HelpHub refuses an ambiguous replacement anchor', true, $ambiguous_anchor instanceof RuntimeException);
check_throws(
	'HelpHub refuses plaintext credential transport', Throwable::class,
	static fn(): string => helphub_require_https('http://wordpress.org'));
check(
	'HelpHub accepts and normalizes HTTPS sites',
	'https://wordpress.org',
	helphub_require_https('https://wordpress.org/'));
$unmatched_only = null;
try {
	helphub_select(array('7.0.3'), '7.0.4');
} catch (Throwable $error) {
	$unmatched_only = $error;
}
check('HelpHub refuses an unmatched page selector', true, $unmatched_only instanceof RuntimeException);

check(
	'HelpHub block markup retains preformatted delimiters',
	"<!-- wp:preformatted -->\n<pre class=\"wp-block-preformatted\">/a.php</pre>\n<!-- /wp:preformatted -->",
	helphub_preformatted('/a.php'));
check(
	'HelpHub source paths are flagged instead of translating equal to themselves',
	1,
	count(helphub_source_path_findings(
		helphub_page_to_text('<h3>List of files revised</h3><p>/js/_enqueues/admin/edit.js</p>'),
		array('src/js/_enqueues/admin/edit.js' => '/wp-admin/js/edit.js'),
		'7.0.3'
	)));

$credit_fixes = array(101 => array('7.0'), 102 => array('6.9'));
foreach (array(
	'placeholder' => array('Fix #101 <!-- FILL IN: reported by ... -->', array(101)),
	'authored' => array('Fix #101 reported by Example Reporter.', array()),
	'absent' => array(null, array(101)),
) as $label => $case) {
	list($credit, $missing) = $case;
	$credits = array(101 => $credit);
	check(
		'HelpHub draft accounts for ' . $label . ' credits without changing the text',
		array('bullets' => array($credit ?? '<!-- FILL IN: credit line for fix #101 -->'), 'missing' => $missing),
		helphub_credit_lines($credit_fixes, $credits, '7.0'));
	check(
		'HelpHub preview shares the ' . $label . ' credit rule',
		$missing,
		helphub_missing_credits(array(101), $credits));
}

$release_tag_output = implode("\n", array_map(
	static fn(string $ref): string => str_repeat('a', 40) . "\t" . $ref,
	array(
		'refs/tags/3.9',
		'refs/tags/3.9^{}',
		'refs/tags/3.9.9',
		'refs/tags/3.9.12',
		'refs/tags/3.9.12^{}',
		'refs/tags/3.9.99^{}',
		'refs/tags/7.0.2',
		'refs/tags/7.0.3-alpha',
		'refs/tags/7.0.3-beta1',
		'refs/tags/7.0.3-RC1',
		'refs/tags/v3.9.13',
		'refs/tags/3.9.12.1',
		'refs/tags/3.9.13-extra',
		'refs/tags/6',
		'refs/tags/example',
		'refs/heads/3.9',
	)
)) . "\n";
check('Preview parses only X.Y and X.Y.N release tags, ignoring peeled refs',
	array('3.9', '3.9.9', '3.9.12', '7.0.2'), helphub_parse_release_tags($release_tag_output));
check('Preview parsing an empty tag read supplies no invented releases', array(), helphub_parse_release_tags(''));

$release_tags = array('3.9.12', '3.9.9', '3.9', '3.90.99', '3.10.99', '7.0.2', '5.9');
check('Preview selects 3.9.12 over 3.9.9 and confines tags to the exact branch line',
	array('tag' => '3.9.12', 'next' => '3.9.13'), helphub_latest_release_tag('3.9', $release_tags));
check('Preview selects the same newest tag regardless of input order',
	array('tag' => '3.9.12', 'next' => '3.9.13'), helphub_latest_release_tag('3.9', array_reverse($release_tags)));
$lagged_version = helphub_parse_version_string('3.9.11-src');
check('Preview flags a lagging version file that would repeat the newest public patch',
	'Branch 3.9 would be 3.9.12; newest public tag is 3.9.12, expected 3.9.13.',
	helphub_release_tag_check('3.9', $lagged_version['next'], helphub_latest_release_tag('3.9', $release_tags)));
check('Preview also flags a would-be version ahead of the expected patch',
	'Branch 3.9 would be 3.9.14; newest public tag is 3.9.12, expected 3.9.13.',
	helphub_release_tag_check('3.9', '3.9.14', helphub_latest_release_tag('3.9', $release_tags)));
check('Preview accepts the patch immediately after the newest public tag', null,
	helphub_release_tag_check('3.9', '3.9.13', helphub_latest_release_tag('3.9', $release_tags)));
$alpha_version = helphub_parse_version_string('7.0.3-alpha-62792-src');
check('Preview accepts an alpha already bumped to the patch after the newest public tag', null,
	helphub_release_tag_check('7.0', $alpha_version['next'], helphub_latest_release_tag('7.0', $release_tags)));
check('Preview has no newest tag for a line absent from public tags', null, helphub_latest_release_tag('6.8', $release_tags));
check('Preview cannot verify a line without a public tag',
	'Branch 6.8: no public 6.8 tag found (would be 6.8.1).',
	helphub_release_tag_check('6.8', '6.8.1', helphub_latest_release_tag('6.8', $release_tags)));
check('Preview derives the first patch from an X.Y-only public tag',
	array('tag' => '5.9', 'next' => '5.9.1'), helphub_latest_release_tag('5.9', $release_tags));
check('Preview accepts the first patch after an X.Y-only public tag', null,
	helphub_release_tag_check('5.9', '5.9.1', helphub_latest_release_tag('5.9', $release_tags)));


$manifest_entry = array('branch' => 'origin/release/wp-7.1.2-into-7.1', 'commit' => str_repeat('a', 40), 'base' => 'origin/7.1');
$manifest_expected = array(
	'format' => 1,
	'release' => '7.1.2',
	'generated_at' => '2026-09-22T12:00:00+00:00',
	'checkout_head' => str_repeat('b', 40),
	'targets' => array('7.1' => $manifest_entry, '7.0' => null, '6.9' => null),
	'fixes' => array(1332 => array('7.1', '7.0'), 1333 => array('6.9')),
	'credits' => array(1332 => 'Reviewed credit line.'),
	'advisories' => array(1332 => 'GHSA-7hp8-65ch-5whp', 1333 => null),
);
$manifest_built = $manifest_expected;
$manifest_json = json_encode($manifest_built, JSON_THROW_ON_ERROR);
check('HelpHub manifest decode round-trips a built manifest into associative maps', $manifest_expected, helphub_manifest_decode($manifest_json));
check('HelpHub manifest missing targets retain scope order', array('7.0', '6.9'), helphub_manifest_missing_targets($manifest_built));
check('HelpHub manifest complete targets have no gaps', array(), helphub_manifest_missing_targets(array('targets' => array('7.1' => $manifest_entry))));


$news_cases = array(
	'List-style post' => array('Stored XSS reported by Jeremy Felt of the WordPress Security Team.', '<h2>Security updates included in this release</h2>' . '<ul><li>Stored XSS, reported by <a>Jeremy Felt</a> of the WordPress Security Team.</li></ul>' . '<h2>Thank you to these WordPress contributors</h2><p>John Blackbourn</p>', null, 0, 'reporter: PASS'),
	'Prose-style post' => array('Issue reported by Robert Ressl.', '<h2>Security update included in this release</h2><p>The security team would like to thank Robert Ressl for responsibly disclosing that issue.</p><h2>CVE and GHSA references</h2><p>CVE-2026-87902 / GHSA-7hp8-65ch-5whp</p><h2>Thank you to these WordPress contributors</h2><p>John Blackbourn</p>', null, 0, 'reporter: PASS'),
	'Viridis substring' => array('Issue reported by viridis.', "Security update included in this release\n" . 'Comments, including notes, can be reparented by any authenticated user, reported by Justin Hart, Viridis Security.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: PASS (viridis)'),
	'Fallback before of' => array('Issue reported by Ben Bidner of the WordPress Security Team.', "Security update included in this release\n" . 'Thank you Ben Bidner.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: PASS'),
	'Fallback before parenthesis' => array('Issue reported by Reporter (Team).', "Security update included in this release\n" . 'Thank you Reporter.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: PASS'),
	'Fallback uses first delimiter' => array('Issue reported by Reporter (Team) of Organization.', "Security update included in this release\n" . 'Thank you Reporter.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: PASS'),
	'Wrong reporter' => array('Issue reported by Ben Bidner.', "Security update included in this release\n" . 'Thank you Jeremy Felt.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 2, 'reporter: MISSING (Ben Bidner)'),
	'GHSA present' => array('Issue reported by Robert Ressl.', "Security update included in this release\n" . "Robert Ressl.\nCVE and GHSA references\nCVE-2026-87902 / ghsa-7hp8-65ch-5whp." . "\nThank you to these WordPress contributors\nJohn Blackbourn", 'GHSA-7hp8-65ch-5whp', 0, 'advisory: PASS (GHSA-7hp8-65ch-5whp)'),
	'GHSA absent' => array('Issue reported by Robert Ressl.', "Security update included in this release\n" . 'Robert Ressl: CVE-2026-87902.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", 'GHSA-7hp8-65ch-5whp', 2, 'advisory: MISSING (GHSA-7hp8-65ch-5whp)'),
	'Null advisory skipped' => array('Issue reported by Reporter.', "Security update included in this release\n" . 'Reporter' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'advisory: SKIPPED (no advisory in manifest; nothing to check)'),
	'Credit without reported by' => array('Reviewed credit line.', "Security update included in this release\n" . 'Unrelated post' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: UNCHECKED (credit has no "reported by")'),
	'Last reported by wins' => array('Originally reported by Wrong, later REPORTED BY Right.', "Security update included in this release\n" . 'Thank you RIGHT.' . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: PASS (Right)'),
	'Quotes dashes entities and whitespace' => array("Issue reported by O'Neil-Smith.", '<h2>Security updates included in this release</h2>' . '<p>Thank you <b>O&#8217;Neil&ndash;Smith</b>&nbsp;for reporting.</p>' . '<h2>Thank you to these WordPress contributors</h2><p>John Blackbourn</p>', null, 0, "reporter: PASS (O'Neil-Smith)"),
	'HTML contributors cannot satisfy reporter' => array('Issue reported by John Blackbourn of the WordPress Security Team.', '<h2>Security updates included in this release</h2><ul><li>Issue reported by Jakub Herman.</li></ul><h2>Thank you to these WordPress contributors</h2><p>John Blackbourn</p>', null, 2, 'reporter: MISSING (John Blackbourn of the WordPress Security Team)'),
	'HTML missing security heading' => array('Issue reported by John Blackbourn.', '<p>Security updates included in this release: John Blackbourn.</p><h2>Thank you to these WordPress contributors</h2><p>John Blackbourn</p>', null, 2, 'reporter: MISSING (security section not found)'),
	'Plain text missing security heading' => array('Issue reported by John Blackbourn.', "Thank you to these WordPress contributors\nJohn Blackbourn", null, 2, 'reporter: MISSING (security section not found)'),
	'Missing section overrides unchecked credit' => array('Reviewed credit line.', '<p>Unrelated post</p>', null, 2, 'reporter: MISSING (security section not found)'),
	'HTML lower heading ends section' => array('Issue reported by Robert Ressl.', '<h2 class="heading">Security <em>update</em> included in this release</h2><h3>Details</h3><p>Robert Ressl</p><h2>Backports</h2>', null, 2, 'reporter: MISSING (Robert Ressl)'),
	'HTML higher heading ends section' => array('Issue reported by John Blackbourn.', '<h2>Security update included in this release</h2><p>Jakub Herman</p><h1>Contributors</h1><p>John Blackbourn</p>', null, 2, 'reporter: MISSING (John Blackbourn)'),
	'HTML GHSA outside security section' => array('Issue reported by Robert Ressl.', '<h2>Security update included in this release</h2><p>Robert Ressl</p><h2>CVE and GHSA references</h2><p>GHSA-7hp8-65ch-5whp</p>', 'GHSA-7hp8-65ch-5whp', 0, 'advisory: PASS (GHSA-7hp8-65ch-5whp)'),
	'Plain text stops at Thank you to these WordPress contributors' => array('Issue reported by John Blackbourn.', "Security updates included in this release\nIssue reported by Jakub Herman.\nThank you to these WordPress contributors\nJohn Blackbourn", null, 2, 'reporter: MISSING (John Blackbourn)'),
	'Plain text stops at CVE and GHSA references' => array('Issue reported by John Blackbourn.', "Security updates included in this release\nIssue reported by Jakub Herman.\nCVE and GHSA references\nJohn Blackbourn", null, 2, 'reporter: MISSING (John Blackbourn)'),
	'Plain text stops at Backports' => array('Issue reported by John Blackbourn.', "Security updates included in this release\nIssue reported by Jakub Herman.\nBackports\nJohn Blackbourn", null, 2, 'reporter: MISSING (John Blackbourn)'),
	"Name Ann does not match Joanne" => array("Issue reported by Ann.", "<h2>Security updates included in this release</h2><p>Joanne</p>", null, 2, "reporter: MISSING (Ann)"),
	"Fallback Alice does not match Malice" => array("Issue reported by Alice of Example.", "<h2>Security updates included in this release</h2><p>Malice</p>", null, 2, "reporter: MISSING (Alice of Example)"),
	"Parenthetical fallback does not match Malice" => array("Issue reported by Alice (Example).", "<h2>Security updates included in this release</h2><p>Malice</p>", null, 2, "reporter: MISSING (Alice (Example))"),
	"Unicode letters and digits bound names" => array("Issue reported by Ann.", "<h2>Security updates included in this release</h2><p>éAnn Ann中 ١Ann Ann٢</p>", null, 2, "reporter: MISSING (Ann)"),
	"Unicode case insensitive name" => array("Issue reported by Élodie.", "<h2>Security updates included in this release</h2><p>Reported by éLODIE.</p>", null, 0, "reporter: PASS (Élodie)"),
	"Reporter regex punctuation is literal" => array("Issue reported by A.B.", "<h2>Security updates included in this release</h2><p>AxB</p>", null, 2, "reporter: MISSING (A.B)"),
	"Anthropic followed by period" => array("Issue reported by Anthropic.", "<h2>Security updates included in this release</h2><p>reported by Anthropic.</p>", null, 0, "reporter: PASS (Anthropic)"),
	"HTML h3 contributors cannot satisfy reporter" => array("Issue reported by John Blackbourn.", "<h2>Security updates included in this release</h2><p>Jakub Herman</p><h3>Thank you to these WordPress contributors</h3><p>John Blackbourn</p>", null, 2, "reporter: MISSING (John Blackbourn)"),
	"HTML article excludes footer" => array("Issue reported by John Blackbourn.", "<html><body><article><h3>Security update included in this release</h3><p>Jakub Herman</p></article><footer>John Blackbourn</footer></body></html>", null, 2, "reporter: MISSING (John Blackbourn)"),
	"HTML entry-content excludes article footer" => array("Issue reported by John Blackbourn.", "<article><div class=\"post entry-content\"><h3>Security update included in this release</h3><div><p>Jakub Herman</p></div></div><footer>John Blackbourn</footer></article>", null, 2, "reporter: MISSING (John Blackbourn)"),
	"HTML nested entry-content retains credit" => array("Issue reported by Élodie.", "<article><div class=\"post entry-content\"><h2>Security updates included in this release</h2><div><p>Other text</p></div>\n<p>Élodie</p></div><footer>Other text</footer></article>", null, 0, "reporter: PASS (Élodie)"),
	"HTML start marker must match release heading" => array("Issue reported by Ann.", "<h2>Security update</h2><p>Ann</p>", null, 2, "reporter: MISSING (security section not found)"),
	"Plain prose is not security heading" => array("Issue reported by Ann.", "This release has no security updates.\nAnn", null, 2, "reporter: MISSING (security section not found)"),
	"Long plain line is not security heading" => array("Issue reported by Ann.", "Security updates included in this release are described in prose rather than a heading.\nAnn", null, 2, "reporter: MISSING (security section not found)"),
	"Markdown contributors end section" => array("Issue reported by Ann.", "## Security updates included in this release:\nJoanne\n## Thank you to these WordPress contributors\nAnn", null, 2, "reporter: MISSING (Ann)"),
	"Markdown arbitrary heading ends section" => array("Issue reported by Ann.", "## Security update included in this release\nJoanne\n### Other people\nAnn", null, 2, "reporter: MISSING (Ann)"),
	"Plain How to contribute ends section" => array("Issue reported by Ann.", "Security update included in this release:\nJoanne\nHow to contribute!\nAnn", null, 2, "reporter: MISSING (Ann)"),
	"Markdown security heading passes" => array("Issue reported by Ann.", "## Security update included in this release:\nReported by Ann.\n## Backports", null, 0, "reporter: PASS (Ann)"),
	'Whitespace in reporter' => array('Issue reported by Robert Ressl.', "Security update included in this release\n" . "Thank you Robert\n\tRessl." . "\nThank you to these WordPress contributors\nJohn Blackbourn", null, 0, 'reporter: PASS'),
);
foreach ($news_cases as $label => [$credit, $post, $advisory, $code, $output]) {
	$news_manifest = array_replace($manifest_expected, array(
		'credits' => array(1332 => $credit),
		'advisories' => array(1332 => $advisory, 1333 => null),
	));
	check("News: {$label} section found", !str_contains($output, 'security section not found'),
		null !== helphub_credits_news_security_section($post));
	ob_start();
	try {
		$result_code = helphub_credits_check_news($news_manifest, $post);
	} finally {
		$result_output = ob_get_clean();
	}
	check("News: {$label} exit", $code, $result_code);
	check("News: {$label} output", true, str_contains($result_output, 'Fix #1332 ' . $output));
	check("News: {$label} summary", true, str_contains($result_output, '3 check(s):'));
}
check('News: summary counts', true, str_contains($result_output, '3 check(s): 1 PASS, 0 MISSING, 0 UNCHECKED, 2 SKIPPED.'));

$news_options = array('release' => '7.1.2', 'manifest' => 'manifest.json', 'news-post' => 'post.html');
check('News: valid options', null, helphub_credits_news_option_error($news_options));
foreach (array('only' => '7.1.2', 'user' => 'unused') as $option => $value) {
	$error = helphub_credits_news_option_error($news_options + array($option => $value));
	check("News: refuses --{$option} exit", 1, $error['code']);
	check("News: refuses --{$option} reason", "--news-post cannot be used with --{$option}.", $error['message']);
}
foreach (array('https://example.com/news/post', 'https://wordpress.org.evil.test/news/post', 'http://wordpress.org/news/post', 'https://wordpress.org/documentation/post', 'https://wordpress.org@evil.test/news/post', 'file:///tmp/post') as $url) {
	$error = null;
	try {
		helphub_credits_read_news($url);
	} catch (InvalidArgumentException $exception) {
		$error = $exception;
	}
	check("News: refuses URL {$url}", true, $error instanceof InvalidArgumentException);
	if (null !== $error) {
		check("News: refuses URL {$url} exit", 2, helphub_credits_error_code($error));
		check("News: refuses URL {$url} reason", '--news-post URL must be https://wordpress.org/news/...', $error->getMessage());
	}
}
$error = helphub_credits_news_option_error(array_replace($news_options, array('news-post' => '')));
check('News: empty source exit', 1, $error['code']);
check('News: empty source reason', '--news-post requires a URL or file path.', $error['message']);
foreach (array('release', 'manifest') as $required) {
	$options = $news_options;
	unset($options[$required]);
	$error = helphub_credits_news_option_error($options);
	check("News: requires --{$required}", 1, $error['code']);
	check("News: missing --{$required} requests usage", null, $error['message']);
}
$news_manifest['release'] = '7.1.1';
$error = helphub_credits_news_option_error($news_options, $news_manifest);
check('News: release mismatch exit', 2, $error['code']);
check('News: release mismatch reason', true, str_contains($error['message'], 'Manifest declares release 7.1.1'));
$news_manifest['release'] = '7.1.2';
check('News: matching release', null, helphub_credits_news_option_error($news_options, $news_manifest));
$password_environment = getenv('HELPHUB_APP_PASSWORD');
putenv('HELPHUB_APP_PASSWORD=unused-test-value');
ob_start();
try {
	check('News: ignores existing password environment variable', 0, helphub_credits_check_news($news_manifest, $post));
	check('News: options ignore password environment variable', null, helphub_credits_news_option_error($news_options));
} finally {
	ob_end_clean();
	putenv(false === $password_environment ? 'HELPHUB_APP_PASSWORD' : 'HELPHUB_APP_PASSWORD=' . $password_environment);
	unset($password_environment);
}
$error = null;
try {
	helphub_manifest_decode('{');
} catch (InvalidArgumentException $exception) {
	$error = $exception;
}
check('News: invalid manifest rejected', true, $error instanceof InvalidArgumentException);
if (null !== $error) {
	check('News: invalid manifest exit', 2, helphub_credits_error_code($error));
}
check('News: unreadable file exit', 1, helphub_credits_error_code(new RuntimeException('Unable to read news post: post.html')));
check('News: unreadable manifest exit', 2, helphub_credits_error_code(new InvalidArgumentException('Unable to read manifest: manifest.json')));
check('News: unexpected error exit', 1, helphub_credits_error_code(new Error('Unexpected failure')));

$manifest_no_credits = array_replace($manifest_built, array('credits' => (object) array(), 'advisories' => array(1332 => null, 1333 => null)));
$manifest_no_credits_json = json_encode($manifest_no_credits, JSON_THROW_ON_ERROR);
check('HelpHub manifest accepts empty credit objects and null advisories', array(), helphub_manifest_decode($manifest_no_credits_json)['credits']);
check('HelpHub manifest fills every absent advisory', array(1332 => null, 1333 => null), helphub_manifest_decode($manifest_no_credits_json)['advisories']);

$manifest_invalid = array(
	'wrong format' => array('format', 2, 'format'),
	'string format' => array('format', '1', 'format'),
	'missing release' => array('release', null, 'release'),
	'bad release' => array('release', '7', 'release'),
	'empty targets' => array('targets', (object) array(), 'targets'),
	'target list' => array('targets', array($manifest_entry), 'targets'),
	'bad target key' => array('targets', (object) array('trunk' => null), 'trunk'),
	'target scalar' => array('targets', array('7.1' => false), '7.1'),
	'target array' => array('targets', array('7.1' => array()), '7.1'),
	'short commit' => array('targets', array('7.1' => array_replace($manifest_entry, array('commit' => 'abc123'))), 'commit'),
	'non-hex commit' => array('targets', array('7.1' => array_replace($manifest_entry, array('commit' => str_repeat('g', 40)))), 'commit'),
	'empty branch' => array('targets', array('7.1' => array_replace($manifest_entry, array('branch' => ' '))), 'branch'),
	'non-string base' => array('targets', array('7.1' => array_replace($manifest_entry, array('base' => 7))), 'base'),
	'missing base' => array('targets', array('7.1' => array_diff_key($manifest_entry, array('base' => true))), 'base'),
	'fix list' => array('fixes', array(), 'fixes'),
	'bad fix number' => array('fixes', (object) array('0' => array('7.1')), 'fix number'),
	'empty fix targets' => array('fixes', array(1332 => array()), 'non-empty list'),
	'fix target object' => array('fixes', array(1332 => (object) array('0' => '7.1')), 'non-empty list'),
	'unknown fix target' => array('fixes', array(1332 => array('6.8')), 'absent from targets'),
	'non-string fix target' => array('fixes', array(1332 => array(7)), 'absent from targets'),
	'credit list' => array('credits', array(), 'credits'),
	'unknown credit fix' => array('credits', array(9999 => 'Credit.'), 'unknown fix'),
	'bad credit key' => array('credits', array('wrong' => 'Credit.'), 'fix number'),
	'empty credit' => array('credits', array(1332 => ' '), 'non-empty string'),
	'non-string credit' => array('credits', array(1332 => 1), 'non-empty string'),
	'advisory list' => array('advisories', array(), 'advisories'),
	'unknown advisory fix' => array('advisories', array(9999 => null), 'unknown fix'),
	'bad advisory key' => array('advisories', array('wrong' => null), 'fix number'),
	'bad advisory id' => array('advisories', array(1332 => 'GHSA-short'), 'GHSA identifier'),
	'non-string advisory' => array('advisories', array(1332 => false), 'GHSA identifier'),
	'unknown top-level key' => array('private', true, 'Unknown manifest field'),
	'unknown target field' => array('targets', array('7.1' => $manifest_entry + array('pr' => 1)), 'unknown field'),
	'duplicate fix target' => array('fixes', array(1332 => array('7.1', '7.1')), 'twice'),
	'advisory map missing a fix' => array('advisories', array(1332 => null), 'must name every fix'),
	'release with a suffix' => array('release', '7.1.2-rc1', 'release'),
);
foreach ($manifest_invalid as $manifest_label => [$manifest_field, $manifest_value, $manifest_problem]) {
	$manifest_bad = $manifest_built;
	if ('missing release' === $manifest_label) {
		unset($manifest_bad['release']);
	} else {
		$manifest_bad[$manifest_field] = $manifest_value;
	}
	$manifest_bad_json = json_encode($manifest_bad, JSON_THROW_ON_ERROR);
	$manifest_error = null;
	try {
		helphub_manifest_decode($manifest_bad_json);
	} catch (Throwable $error) {
		$manifest_error = $error;
	}
	check("HelpHub manifest rejects {$manifest_label}", true, $manifest_error instanceof InvalidArgumentException);
	check("HelpHub manifest names {$manifest_label}", true, str_contains($manifest_error?->getMessage() ?? '', $manifest_problem));
}
foreach (array('invalid JSON' => '{', 'top-level array' => '[]', 'top-level null' => 'null') as $manifest_label => $manifest_bad_json) {
	check_throws("HelpHub manifest rejects {$manifest_label}", Throwable::class, static fn() => helphub_manifest_decode($manifest_bad_json));
}
$issue_manifest = array(
	'format' => 1,
	'release' => '9.9.1',
	'targets' => array('9.9' => null, '9.8' => null, '9.7' => null, '9.6' => null),
	'fixes' => array(91001 => array('9.9'), 91002 => array('9.8'), 91003 => array('9.9', '9.8', '9.7')),
	'credits' => array(91001 => 'Shared issue reported by Example.', 91003 => 'Three issues reported by Sample.'),
	'advisories' => array(91001 => null, 91002 => null, 91003 => null),
	'issues' => array(
		91001 => array('example/repo#1'),
		91002 => array('example/repo#1'),
		91003 => array('example/repo#2', 'example/repo#3', 'example/repo#4'),
	),
);
check('Issues round-trip without changing manifest format or adding entries', $issue_manifest,
	helphub_manifest_decode(json_encode($issue_manifest, JSON_THROW_ON_ERROR)));
$issue_invalid = array(
	'null map' => array('issues', null, 'issues must be an object'),
	'list map' => array('issues', array(), 'issues must be an object'),
	'scalar map' => array('issues', 'example/repo#1', 'issues must be an object'),
	'empty map' => array('issues', (object) array(), 'missing 91001, 91002, 91003'),
	'missing fix' => array('issues', array(91001 => array('example/repo#1')), 'missing 91002, 91003'),
	'unknown fix' => array('issues', $issue_manifest['issues'] + array(91004 => array('example/repo#5')), 'unknown fix 91004'),
	'bad key' => array('issues', array('091001' => array('example/repo#1')), 'key 091001 must be a fix number'),
	'empty list' => array('issues', array(91001 => array()), 'fix 91001 must contain a non-empty list'),
	'object list' => array('issues', array(91001 => (object) array('0' => 'example/repo#1')), 'non-empty list'),
	'scalar list' => array('issues', array(91001 => 'example/repo#1'), 'non-empty list'),
	'non-string reference' => array('issues', array(91001 => array(1)), 'owner/repo#N'),
	'duplicate reference' => array('issues', array(91001 => array('example/repo#1', 'example/repo#1')), 'fix 91001 names an issue twice'),
	'joined credit' => array('credits', $issue_manifest['credits'] + array(91002 => 'Another credit.'),
		"fix 91002 joins lead fix 91001 and must not carry a credit; lead fix 91001's credit line covers both"),
);
foreach (array('example/repo#0', 'example/repo#01', 'example/repo#-1', 'example/repo#1.0', 'example/repo',
	'example/repo/extra#1', ' example/repo#1', "example/repo#1\n", 'example/repo#1 trailing') as $bad_reference) {
	$issue_invalid['bad reference ' . json_encode($bad_reference)] = array('issues', array(91001 => array($bad_reference)), 'owner/repo#N');
}
foreach ($issue_invalid as $label => [$field, $value, $message]) {
	$error = null;
	try {
		helphub_manifest_decode(json_encode(array_replace($issue_manifest, array($field => $value)), JSON_THROW_ON_ERROR));
	} catch (InvalidArgumentException $exception) {
		$error = $exception;
	}
	check('Issues reject ' . $label, true, $error instanceof InvalidArgumentException);
	check('Issues explain ' . $label, true, str_contains($error?->getMessage() ?? '', $message));
}
$issue_fixes = $issue_manifest['fixes'];
$issue_credits = $issue_manifest['credits'];
$issues = $issue_manifest['issues'];
$issue_targets = array_keys($issue_manifest['targets']);
$issue_versions = array('9.9.1', '9.8.1', '9.7.1');
$issue_entries = helphub_manifest_entries($issue_fixes, $issues);
check('Shared issue pair folds into its first fix', array(91001, 91003), array_keys($issue_entries));
check('Shared issue entry unions targets', array('9.9', '9.8'), $issue_entries[91001]['targets']);
check('Shared issue entry keeps both fixes', array(91001, 91002), $issue_entries[91001]['fixes']);
check('Shared issue entry counts one reference', array('example/repo#1'), $issue_entries[91001]['issues']);
check('Shared issue credit reaches the joined target', array('bullets' => array_values($issue_credits), 'missing' => array()),
	helphub_credit_lines($issue_fixes, $issue_credits, '9.8', $issues));
check('Missing credit names only the lead', array(
	'bullets' => array('<!-- FILL IN: credit line for fix #91001 -->', $issue_credits[91003]), 'missing' => array(91001),
), helphub_credit_lines($issue_fixes, array(91003 => $issue_credits[91003]), '9.8', $issues));
check('One fix closing three issues counts three', 3, helphub_fix_count($issue_fixes, '9.7', $issues));
check('Distinct total counts four issues across three fixes', 4, helphub_fix_count($issue_fixes, null, $issues));
check('Joined target counts all reaching issues', 4, helphub_fix_count($issue_fixes, '9.8', $issues));
check('Unreached target counts zero issues', 0, helphub_fix_count($issue_fixes, '9.6', $issues));
$courtesy = implode("\n", helphub_courtesy_block($issue_fixes, $issue_versions, $issue_targets, $issues));
check('Courtesy counts all distinct issues on joined branch', true, str_contains($courtesy, 'WordPress 9.8 is affected by all 4 vulnerabilities.'));
check('Courtesy counts three of four on narrower branch', true, str_contains($courtesy, 'WordPress 9.7 is affected by 3 of the 4 vulnerabilities.'));
$pair_fixes = array_intersect_key($issue_fixes, array(91001 => true, 91002 => true));
$pair_issues = array_intersect_key($issues, $pair_fixes);
$pair_courtesy = implode("\n", helphub_courtesy_block($pair_fixes, array('9.9.1', '9.8.1'), $issue_targets, $pair_issues));
check('Shared issue pair uses singular courtesy wording', true, str_contains($pair_courtesy, 'WordPress 9.8 is affected by this vulnerability.'));
check('Shared issue pair uses singular fix wording', true, str_contains($pair_courtesy, 'has been released containing a fix.'));
$reached_below = implode("\n", helphub_courtesy_block($pair_fixes, array('9.9.1'), $issue_targets, $pair_issues));
check('Joined target below the wave requires a fill-in', true, str_contains($reached_below, 'FILL IN: this release also reached'));
check('Joined target below the wave is never declared unaffected', false, str_contains($reached_below, 'are not affected'));
$pair_page = helphub_draft_page('9.8.1', '2000-01-01', array('src/example.php'), $pair_fixes,
	array(91001 => $issue_credits[91001]), array('9.9.1', '9.8.1'), $issue_targets, array(), array(), $pair_issues);
check('Joined branch page has one credit bullet', array($issue_credits[91001]), helphub_credit_bullets(helphub_page_to_text($pair_page['body'])));
check('Joined branch page has no missing credit', array(), $pair_page['missing_credits']);
$issue_page = helphub_draft_page('9.9.1', '2000-01-01', array('src/example.php'), $issue_fixes,
	$issue_credits, $issue_versions, $issue_targets, array(), array(), $issues);
check('Prebuilt entries produce the same page', $issue_page, helphub_draft_page('9.9.1', '2000-01-01', array('src/example.php'),
	$issue_fixes, $issue_credits, $issue_versions, $issue_targets, array(), array(), $issues, $issue_entries));
check('Lead page uses issue counts in courtesy block', true, str_contains($issue_page['body'], '3 of the 4 vulnerabilities'));
$legacy_page = helphub_draft_page('9.9.1', '2000-01-01', array('src/example.php'), $issue_fixes,
	$issue_credits, $issue_versions, $issue_targets);
check('Absent issues preserves original page bytes', '831cd7e4f03cd760c1812af83d33bd81946f0bfa3a80573c8c40b30a8503c459', hash('sha256', $legacy_page['body']));
check('Empty issues map preserves original page', $legacy_page, helphub_draft_page('9.9.1', '2000-01-01', array('src/example.php'),
	$issue_fixes, $issue_credits, $issue_versions, $issue_targets, array(), array(), array()));
check('Absent issues still counts fixes', 3, helphub_fix_count($issue_fixes));
check('Checker pairs lead credits after skipping joined fix', array('lines' => array_values($issue_credits), 'fill_ins' => array()),
	helphub_credits_expected_lines($issue_fixes, $issue_credits, '9.8', $issues));
check('Checker accepts prebuilt entries', helphub_credits_expected_lines($issue_fixes, $issue_credits, '9.8', $issues),
	helphub_credits_expected_lines($issue_fixes, $issue_credits, '9.8', $issues, $issue_entries));
check('Checker reports only lead fill-ins', array('lines' => array(), 'fill_ins' => array(91001, 91003)),
	helphub_credits_expected_lines($issue_fixes, array(), '9.8', $issues));
ob_start();
try {
	$issue_news_code = helphub_credits_check_news($issue_manifest, "Security updates\nExample and Sample\n");
	$issue_news_output = ob_get_contents();
} finally {
	ob_end_clean();
}
check('News reporter check accepts grouped credits without changes', 0, $issue_news_code);
check('News reporter check skips joined fix', false, str_contains($issue_news_output, 'Fix #91002 reporter:'));
check('News advisory check still covers joined fix', true, str_contains($issue_news_output, 'Fix #91002 advisory: SKIPPED'));

$bridge_fixes = array(91009 => array('9.9'), 91008 => array('9.8'), 91007 => array('9.7'), 91006 => array('9.6'), 91005 => array('9.5'));
$bridge_issues = array(
	91009 => array('example/repo#1'),
	91008 => array('example/repo#2'),
	91007 => array('example/repo#1', 'example/repo#2', 'example/repo#3'),
	91006 => array('example/repo#3'),
	91005 => array('example/repo#11'),
);
$bridge_entries = helphub_manifest_entries($bridge_fixes, $bridge_issues);
check('Bridge merges earlier entries and later transitive member in manifest order', array(91009, 91005), array_keys($bridge_entries));
check('Bridge unions all distinct issues', 3, count($bridge_entries[91009]['issues']));
check('Bridge unions all member targets', array(), array_diff(array('9.9', '9.8', '9.7', '9.6'), $bridge_entries[91009]['targets']));
check('Bridge issues all reach a joined branch', 3, helphub_fix_count($bridge_fixes, '9.6', $bridge_issues));
check('Different full references remain distinct', 4, helphub_fix_count($bridge_fixes, null, $bridge_issues));
check('Bridge missing credit names earliest fix', array('bullets' => array('<!-- FILL IN: credit line for fix #91009 -->'), 'missing' => array(91009)),
	helphub_credit_lines($bridge_fixes, array(), '9.6', $bridge_issues));
$bridge_manifest = array_replace($issue_manifest, array(
	'fixes' => $bridge_fixes,
	'targets' => array_fill_keys(array('9.9', '9.8', '9.7', '9.6', '9.5'), null),
	'issues' => $bridge_issues,
	'credits' => array(91008 => 'Credit from an entry later joined.'),
	'advisories' => array_fill_keys(array_keys($bridge_fixes), null),
));
$bridge_error = null;
try {
	helphub_manifest_decode(json_encode($bridge_manifest, JSON_THROW_ON_ERROR));
} catch (InvalidArgumentException $exception) {
	$bridge_error = $exception->getMessage();
}
check('A bridge rejects a former lead credit with the final lead named',
	"Manifest fix 91008 joins lead fix 91009 and must not carry a credit; lead fix 91009's credit line covers both.", $bridge_error);
check('Announced count accepts distinct issue total', null, helphub_security_issues_check($issue_entries, '4'));
foreach (array('', '0', '-1', '1.5', '4x', '04', ' 4', "4\n", array('4'), false) as $bad_count) {
	check_throws('Announced issue count must be a positive integer: ' . json_encode($bad_count), InvalidArgumentException::class,
		static fn() => helphub_security_issues_check($issue_entries, $bad_count));
}
$count_error = null;
try {
	helphub_security_issues_check($issue_entries, '5');
} catch (InvalidArgumentException $exception) {
	$count_error = $exception->getMessage();
}
check('Announced count mismatch explains how to repair the manifest',
	"Manifest issues add up to 4 distinct issue(s), but --security-issues says 5. Add each fix's missing issue to its `issues` list, then re-export the manifest.", $count_error);

$manifest_major = json_encode(array_replace($manifest_built, array('release' => '7.2')), JSON_THROW_ON_ERROR);
check('HelpHub manifest accepts an X.Y release', '7.2', helphub_manifest_decode($manifest_major)['release']);

// The recorded build must match before any file list is read.
$branch_entry = array('branch' => 'origin/release/wp-7.1.2-into-7.1', 'commit' => str_repeat('a', 40), 'base' => 'origin/7.1');
check('Manifest branch match passes', null, helphub_manifest_branch_check($branch_entry, str_repeat('a', 40)));
check('Manifest branch hex comparison is case-insensitive', null, helphub_manifest_branch_check($branch_entry, str_repeat('A', 40)));
check_throws('Manifest branch mismatch refuses another build', RuntimeException::class,
	static fn() => helphub_manifest_branch_check($branch_entry, str_repeat('b', 40)));
$branch_error = '';
try {
	helphub_manifest_branch_check($branch_entry, str_repeat('b', 40));
} catch (RuntimeException $error) {
	$branch_error = $error->getMessage();
}
check('Mismatch names recorded commit', true, str_contains($branch_error, $branch_entry['commit']));
check('Mismatch names checkout commit', true, str_contains($branch_error, str_repeat('b', 40)));
check('Mismatch tells operator to fetch or regenerate', true, str_contains($branch_error, 'Fetch the checkout or regenerate the manifest'));
check('Missing manifest branch needs no checkout access', null,
	helphub_backport_branch_files('unused', array('targets' => array('7.1' => null)), '7.1'));
$missing_files = helphub_expected_files('unused', array('targets' => array('7.1' => null), 'fixes' => array(1332 => array('7.1'), 1333 => array('7.0'))), '7.1');
check('Missing branch reports reaching fixes unverified without private references', array(1332), $missing_files['unpinned']);
check('Missing branch provides no invented files', array(), $missing_files['files']);

// Exhausted HTTP retries name GET without reading an undefined method variable.
$http_error = '';
try {
	helphub_http_request(
		'https://example.test/unavailable',
		static function (int $seconds): void {},
		static fn(string $url, array $options): array => array('body' => false, 'headers' => array())
	);
} catch (RuntimeException $error) {
	$http_error = $error->getMessage();
}
check('HTTP retry exhaustion names the literal request method',
	'Request did not produce an answer after ' . HELPHUB_HTTP_ATTEMPTS . ' attempts: GET https://example.test/unavailable (last HTTP 0).',
	$http_error);

// A page copied from the previous release keeps that release's version and fix count.
$copied_page = static fn(string $opening, string $count, int $credits): string => helphub_page_to_text(
	'<p>On October 6, 2026, WordPress ' . $opening . ' was released to the public.</p>'
	. '<h3>Security updates</h3><p>This release features ' . $count . '. Because this is a security release, update now.</p><ul>'
	. str_repeat('<li>An issue, reported by Ann</li>', $credits)
	. '</ul><p>As a courtesy, these fixes are also available in older affected branches.</p>'
);
check('A matching opening and count are clean', array(), helphub_prose_defects('6.4.13', $copied_page('6.4.13', 'six security fixes', 6)));
check('An opening that names another version is a finding',
	array('Page 6.4.13 says "WordPress 6.4.12 was released". Published example: the 6.4.13 page named 6.4.12.'),
	helphub_prose_defects('6.4.13', $copied_page('6.4.12', 'six security fixes', 6)));
check('A count that disagrees with the credits is a finding',
	array('Page 4.7.38 says it features one security fix(es) but credits 6. Published example: 24 pages of 7.1.3 said "one".'),
	helphub_prose_defects('4.7.38', $copied_page('4.7.38', 'one security fix', 6)));
check('A numeral count is read', 1, count(helphub_prose_defects('7.1.3', $copied_page('7.1.3', '8 security fixes', 7))));
check('"Several" claims no count', array(), helphub_prose_defects('7.1.3', $copied_page('7.1.3', 'several security fixes', 7)));
check('A four-part version matches itself', array(), helphub_prose_defects('4.2.26.2', $copied_page('4.2.26.2', 'one security fix', 1)));

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}
echo "Passed {$assertions} assertions.\n";
