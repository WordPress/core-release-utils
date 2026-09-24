#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline HelpHub checks: no network or git; news CLI checks use temporary files. */

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


$news_manifest_file = tempnam(sys_get_temp_dir(), 'helphub-manifest-');
$news_post_file     = tempnam(sys_get_temp_dir(), 'helphub-news-');
try {
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
	$news_command = array(PHP_BINARY, '-d', 'allow_url_fopen=0', dirname(__DIR__) . '/check-helphub-credits.php', '--release=7.1.2', '--manifest=' . $news_manifest_file);
	foreach ($news_cases as $label => [$credit, $post, $advisory, $code, $output]) {
		$news_manifest = array_replace($manifest_expected, array(
			'credits' => array(1332 => $credit),
			'advisories' => array(1332 => $advisory, 1333 => null),
		));
		file_put_contents($news_manifest_file, json_encode($news_manifest, JSON_THROW_ON_ERROR));
		file_put_contents($news_post_file, $post);
		$result = run_command(array_merge($news_command, array('--news-post=' . $news_post_file)), null, true);
		check("News: {$label} exit", $code, $result['code']);
		check("News: {$label} output", true, str_contains($result['stdout'], 'Fix #1332 ' . $output));
		check("News: {$label} summary", true, str_contains($result['stdout'], '3 check(s):'));
	}
	foreach (array('only' => '7.1.2', 'user' => 'unused') as $option => $value) {
		$result = run_command(array_merge($news_command, array('--news-post=' . $news_post_file, "--{$option}={$value}")), null, true);
		check("News: refuses --{$option} exit", 1, $result['code']);
		check("News: refuses --{$option} reason", true, str_contains($result['stderr'], "--news-post cannot be used with --{$option}"));
	}
	foreach (array('https://example.com/news/post', 'https://wordpress.org.evil.test/news/post', 'http://wordpress.org/news/post', 'https://wordpress.org/documentation/post', 'https://wordpress.org@evil.test/news/post', 'file:///tmp/post') as $url) {
		$result = run_command(array_merge($news_command, array('--news-post=' . $url)), null, true);
		check("News: refuses URL {$url} exit", 2, $result['code']);
		check("News: refuses URL {$url} reason", true, str_contains($result['stderr'], '--news-post URL must be https://wordpress.org/news/...'));
	}
	$result = run_command(array_merge($news_command, array('--news-post=' . $news_post_file . '-missing')), null, true);
	check('News: unreadable file exit', 1, $result['code']);
	check('News: unreadable file reason', true, str_contains($result['stderr'], 'Unable to read news post:'));
	$result = run_command(array_merge($news_command, array('--news-post=')), null, true);
	check('News: empty source exit', 1, $result['code']);
	foreach (array('release', 'manifest') as $required) {
		$command = array_values(array_filter($news_command, static fn(string $arg): bool => !str_starts_with($arg, "--{$required}=")));
		$result = run_command(array_merge($command, array('--news-post=' . $news_post_file)), null, true);
		check("News: requires --{$required}", 1, $result['code']);
	}
	$news_manifest['release'] = '7.1.1';
	file_put_contents($news_manifest_file, json_encode($news_manifest, JSON_THROW_ON_ERROR));
	$result = run_command(array_merge($news_command, array('--news-post=' . $news_post_file)), null, true);
	check('News: release mismatch exit', 2, $result['code']);
	check('News: release mismatch reason', true, str_contains($result['stderr'], 'Manifest declares release 7.1.1'));
	$news_manifest['release'] = '7.1.2';
	file_put_contents($news_manifest_file, json_encode($news_manifest, JSON_THROW_ON_ERROR));
	$with_password = array_merge(array_slice($news_command, 0, 3), array('-r', 'array_shift($argv); putenv("HELPHUB_APP_PASSWORD=unused-test-value"); require $argv[0];'), array_slice($news_command, 3), array('--news-post=' . $news_post_file));
	$result = run_command($with_password, null, true);
	check('News: ignores existing password environment variable', 0, $result['code']);
	check('News: summary counts', true, str_contains($result['stdout'], '3 check(s): 1 PASS, 0 MISSING, 0 UNCHECKED, 2 SKIPPED.'));
	file_put_contents($news_manifest_file, '{');
	$result = run_command(array_merge($news_command, array('--news-post=' . $news_post_file)), null, true);
	check('News: invalid manifest exit', 2, $result['code']);
	$result = run_command(array(PHP_BINARY, dirname(__DIR__) . '/check-helphub-credits.php', '--release=7.1.2', '--manifest=' . $news_manifest_file . '-missing', '--news-post=' . $news_post_file), null, true);
	check('News: unreadable manifest exit', 2, $result['code']);
} finally {
	unlink($news_manifest_file);
	unlink($news_post_file);
}

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

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}
echo "Passed {$assertions} assertions.\n";
