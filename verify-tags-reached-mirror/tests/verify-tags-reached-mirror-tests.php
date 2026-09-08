#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for verify-tags-reached-mirror.php. No network, no git, no subprocesses. */

require dirname(__DIR__) . '/verify-tags-reached-mirror.php';

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

// Options.
check('plain seconds', 90, parse_duration('90'));
check('minutes', 300, parse_duration('5m'));
check('hours', 3600, parse_duration('1h'));
check_throws('bad duration', InvalidArgumentException::class, static fn() => parse_duration('abc'));
check_throws('absurd duration', InvalidArgumentException::class, static fn() => parse_duration('9999999999h'));
check('zero seconds', '0s', format_duration(0));
check('minutes and seconds', '3m12s', format_duration(192));
check('hours keep zero minutes', '1h0m1s', format_duration(3601));
check('tag list splits on commas and spaces, deduplicates', array('7.0.4', '6.8.5'), parse_tag_list('7.0.4, 6.8.5 7.0.4'));
check_throws('tag list rejects empty', InvalidArgumentException::class, static fn() => parse_tag_list(', '));
check_throws('tag list rejects a leading dash', InvalidArgumentException::class, static fn() => parse_tag_list('--help'));
check_throws('tag list rejects shell characters', InvalidArgumentException::class, static fn() => parse_tag_list('7.0.4;ls'));
check_throws('tag list rejects a double dot', InvalidArgumentException::class, static fn() => parse_tag_list('7.0..4'));
check_throws('tag list rejects a trailing dot', InvalidArgumentException::class, static fn() => parse_tag_list('7.0.4.'));
check_throws('tag list rejects a .lock suffix', InvalidArgumentException::class, static fn() => parse_tag_list('7.0.4.lock'));
check('svn url gains a trailing slash', 'https://core.svn.wordpress.org/tags/', parse_svn_url('https://core.svn.wordpress.org/tags'));
check_throws('svn url must be http(s)', InvalidArgumentException::class, static fn() => parse_svn_url('ftp://x/tags/'));
check('owner/name becomes a GitHub URL', 'https://github.com/WordPress/WordPress.git', parse_repo_url('WordPress/WordPress'));
check('git URL passes through', 'https://example.com/x.git', parse_repo_url('https://example.com/x.git'));
check_throws('repo rejects a bare word', InvalidArgumentException::class, static fn() => parse_repo_url('bad'));

// SVN side.
$multistatus = <<<'XML'
<?xml version="1.0"?><D:multistatus xmlns:D="DAV:" xmlns:lp1="DAV:">
<D:response><D:href>/tags/</D:href><D:propstat><D:prop><lp1:version-name>62518</lp1:version-name></D:prop></D:propstat></D:response>
<D:response><D:href>/tags/7.0.4/</D:href><D:propstat><D:prop><lp1:version-name>62417</lp1:version-name></D:prop></D:propstat></D:response>
<D:response><D:href>/tags/1.5/</D:href><D:propstat><D:prop><lp1:version-name>2541</lp1:version-name></D:prop></D:propstat></D:response>
</D:multistatus>
XML;
$expected  = array('7.0.4' => 62417, '1.5' => 2541);
$transport = static fn(int $status, string $body = '') => static fn(string $url, array $options): array => array('body' => $body, 'headers' => array("HTTP/1.1 {$status} X"));
check('multistatus maps tag directories to revisions', $expected, svn_tags_from_multistatus(svn_propfind('https://x/tags/', 1, $transport(207, $multistatus)), '/tags/'));
$nested = str_replace('/tags/', '/wordpress/tags/', $multistatus);
check('a response without propstat is skipped', array(), svn_tags_from_multistatus(svn_propfind('https://x/tags/', 1, $transport(207, '<D:multistatus xmlns:D="DAV:"><D:response><D:href>/tags/1.5/</D:href></D:response></D:multistatus>')), '/tags/'));
check('a response with an empty propstat is skipped', array(), svn_tags_from_multistatus(svn_propfind('https://x/tags/', 1, $transport(207, '<D:multistatus xmlns:D="DAV:"><D:response><D:href>/tags/1.5/</D:href><D:propstat/></D:response></D:multistatus>')), '/tags/'));
check('svn revision for a tag', 62417, svn_tag_revision('https://x/tags/', '7.0.4', $transport(207, $multistatus)));
check('hrefs outside the base path are ignored', array(), svn_tags_from_multistatus(svn_propfind('https://x/', 1, $transport(207, $nested)), '/tags/'));
check('a nested base path is honored', $expected, svn_tags_from_multistatus(svn_propfind('https://x/', 1, $transport(207, $nested)), '/wordpress/tags/'));
check('404 is null', null, svn_propfind('https://x/tags/nope/', 0, $transport(404)));
check_throws('a 207 without an entry for the tag is not an answer', RuntimeException::class, static fn() => svn_tag_revision('https://x/tags/', '9.9.9', $transport(207, $multistatus)));
check('a 404 for the tag is null', null, svn_tag_revision('https://x/tags/', '9.9.9', $transport(404)));
check_throws('a tags directory without tags is not an answer', RuntimeException::class, static fn() => svn_tag_revisions('https://x/tags/', $transport(207, '<D:multistatus xmlns:D="DAV:"/>')));
check_throws('anything but 207 or 404 is not an answer', RuntimeException::class, static fn() => svn_propfind('https://x/tags/', 1, $transport(403)));
check_throws('invalid XML is not an answer', RuntimeException::class, static fn() => svn_propfind('https://x/tags/', 1, $transport(207, '<broken')));

// Mirror side.
$old = "Tagging WordPress 1.5\n\ngit-svn-id: http://svn.automattic.com/wordpress/tags/1.5@2541 1a063a9b-81f0-0310-95a4-ce76da25c4cd";
$new = "Tag 7.0.4\nBuilt from https://develop.svn.wordpress.org/tags/7.0.4@63224\n\n\ngit-svn-id: http://core.svn.wordpress.org/tags/7.0.4@62417 1a063a9b-81f0-0310-95a4-ce76da25c4cd";
check('old host parses', 2541, revision_from_message('1.5', $old));
check('new host parses and ignores the Built from line', 62417, revision_from_message('7.0.4', $new));
check('another tag does not match', null, revision_from_message('7.0.4', $old));
check('trunk is not a tag', null, revision_from_message('5.3', 'git-svn-id: http://core.svn.wordpress.org/trunk@46762 x '));
check('null message', null, revision_from_message('1.5', null));
$refs = "1.5\x00commit\x00Tagging 1.5\n\ngit-svn-id: http://svn.automattic.com/wordpress/tags/1.5@2541 x\n\x00\x01\n"
	. "7.0.4\x00tag\x00annotated tag message\n\x00Tag 7.0.4\n\ngit-svn-id: http://core.svn.wordpress.org/tags/7.0.4@62417 x\n\x01\n";
$parsed = parse_tag_refs($refs);
check('for-each-ref records are keyed by tag', array('1.5', '7.0.4'), array_keys($parsed));
check('lightweight tag uses its own message', 2541, revision_from_message('1.5', $parsed['1.5']));
check('annotated tag uses the peeled commit message', 62417, revision_from_message('7.0.4', $parsed['7.0.4']));

// Verdicts.
check('same revision passes', 'PASS', verdict('7.0.4', 62417, 62417));
check('missing on the mirror is absent', 'ABSENT', verdict('7.0.4', 62417, null));
check('other revision mismatches', 'MISMATCH', verdict('7.0.4', 62417, 62418));
check('no git-svn-id line mismatches', 'MISMATCH', verdict('7.0.4', 62417, 0));
check('allowlisted pair is known', 'KNOWN', verdict('3.1.3', 18288, 18044));
check('allowlist needs both revisions', 'MISMATCH', verdict('3.1.3', 18289, 18044));

// Gate, with a fake clock that advances one minute per tick. Lines are collected instead of printed.
function run_gate_final(array $tags, int $timeout, callable $mirror, ?callable $svn = null): array {
	$tick  = 0;
	$lines = array();
	$io    = array(
		'now'    => static function () use (&$tick): float {
			return 60.0 * $tick++;
		},
		'sleep'  => static function (int $seconds): void {
		},
		'svn'    => $svn ?? static fn(string $tag): int => 1,
		'mirror' => $mirror,
		'report' => static function (string $tag, ?int $svn, ?int $mirror, string $verdict, string $detail) use (&$lines): void {
			$lines[] = "{$tag} {$verdict} {$detail}";
		},
	);
	$final = gate($tags, $timeout, 60, $io);
	return array(passed(array_column($final, 2)), $lines, $final);
}
function run_gate(array $tags, int $timeout, callable $mirror, ?callable $svn = null): array {
	return array_slice(run_gate_final($tags, $timeout, $mirror, $svn), 0, 2);
}
function after(int $calls, mixed $then, mixed $before = null): callable {
	$count = 0;
	return static function () use (&$count, $calls, $then, $before): ?int {
		if (++$count <= $calls) {
			if ($before instanceof Throwable) {
				throw $before;
			}
			return $before;
		}
		return $then;
	};
}

check('gate passes on match', array(true, array('7.0.4 PASS +0s')), run_gate(array('7.0.4'), 600, static fn() => 1));
check('gate fails on mismatch', array(false, array('7.0.4 MISMATCH +0s')), run_gate(array('7.0.4'), 600, static fn() => 2));
check('gate warns on every poll while absent', array(false, array('7.0.4 WAITING +0s', '7.0.4 WAITING +1m0s', '7.0.4 WAITING +2m0s', '7.0.4 TIMEOUT +3m0s')), run_gate(array('7.0.4'), 180, static fn() => null));
check('svn errors retry then time out from the start', array(false, array('7.0.4 RETRY svn down', '7.0.4 TIMEOUT svn down')), run_gate(array('7.0.4'), 120, static fn() => 1, after(99, null, new RuntimeException('svn down'))));
check('gate retries errors until the timeout', array(false, array('7.0.4 RETRY git down', '7.0.4 RETRY git down', '7.0.4 TIMEOUT git down')), run_gate(array('7.0.4'), 120, after(99, null, new RuntimeException('git down'))));
check('errors before the mirror answers count toward the latency', array(true, array('7.0.4 RETRY git down', '7.0.4 RETRY git down', '7.0.4 PASS +2m0s')), run_gate(array('7.0.4'), 600, after(2, 1, new RuntimeException('git down'))));
check('gate passes after warnings', array(true, array('7.0.4 WAITING +0s', '7.0.4 WAITING +1m0s', '7.0.4 PASS +2m0s')), run_gate(array('7.0.4'), 600, after(2, 1)));
check('never on svn warns then is timed out from the start', array(false, array('7.0.4 WAITING not on SVN after 1m0s', '7.0.4 TIMEOUT not on SVN after 2m0s')), run_gate(array('7.0.4'), 120, static fn() => 1, static fn() => null));
check('a tag late on svn is timed from its first sight there', array(false, array('7.0.4 WAITING not on SVN after 1m0s', '7.0.4 WAITING +0s', '7.0.4 WAITING +1m0s', '7.0.4 TIMEOUT +2m0s')), run_gate(array('7.0.4'), 120, static fn() => null, after(1, 1)));
check('gate returns one final line per tag in the given order', array(
	'7.0.4' => array(1, 1, 'PASS', '+0s', null),
	'5.3'   => array(1, null, 'TIMEOUT', '+2m0s', 'mirror'),
	'3.1.3' => array(null, null, 'TIMEOUT', 'not on SVN after 3m0s', 'svn'),
), run_gate_final(array('7.0.4', '5.3', '3.1.3'), 120, static fn(string $tag): ?int => '7.0.4' === $tag ? 1 : null, static fn(string $tag): ?int => '3.1.3' === $tag ? null : 1)[2]);
check('a failing check keeps the last SVN revision and its reason', array(1, null, 'TIMEOUT', 'git down', 'check'), run_gate_final(array('7.0.4'), 120, after(99, null, new RuntimeException('git down')))[2]['7.0.4']);
check('gate summary counts the three outcomes', '3 tags checked, 1 missing from SVN, 1 missing from the mirror', gate_summary(array(
	'7.0.4' => array(1, 1, 'PASS', '+0s', null),
	'5.3'   => array(1, null, 'TIMEOUT', '+2m0s', 'mirror'),
	'3.1.3' => array(null, null, 'TIMEOUT', 'not on SVN after 3m0s', 'svn'),
)));
check('gate summary adds mismatches and failing checks only when present', '2 tags checked, 0 missing from SVN, 0 missing from the mirror, 1 on another revision, 1 with failing checks', gate_summary(array(
	'7.0.4' => array(1, 2, 'MISMATCH', '+0s', null),
	'5.3'   => array(1, null, 'TIMEOUT', 'git fetch failed', 'check'),
)));
check('passed needs only PASS and KNOWN', true, passed(array('PASS', 'KNOWN')));
check('passed rejects a timeout', false, passed(array('PASS', 'TIMEOUT')));

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}
echo "Passed {$assertions} assertions.\n";
