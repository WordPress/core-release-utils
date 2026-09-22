#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for svn-tags.php. No network, no SVN, no subprocesses. */

require dirname(__DIR__) . '/svn-tags.php';

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

function version_body(string $version): string {
	return "<?php\n\$wp_version = '{$version}';\n";
}

const NOW = 1800000000;
const ABSENT = "svn: warning: W170000: URL 'https://example.test/tags/6.5.12' non-existent in revision 63811\nsvn: E200009: Could not display info for all targets because some targets don't exist\n";

function fake_svn(array $versions = array('6.5' => '6.5.12-src'), array $existing = array(), int $age = 61): callable {
	return static function (array $args) use ($versions, $existing, $age): array {
		$url = $args[count($args) - 1];
		if ('info' === $args[0]) {
			$exists = in_array(basename($url), $existing, true);
			return array('code' => $exists ? 0 : 1, 'stdout' => $exists ? 'Path: tag' : '', 'stderr' => $exists ? '' : ABSENT);
		}
		if (1 !== preg_match('#/branches/(\d+\.\d+)(?:/|$)#', $url, $match) || !isset($versions[$match[1]])) {
			throw new RuntimeException('Unexpected fake read: ' . implode(' ', $args));
		}
		if ('cat' === $args[0]) {
			return array('code' => 0, 'stdout' => version_body($versions[$match[1]]), 'stderr' => '');
		}
		if (array('log', '--xml', '-q', '-l', '1') === array_slice($args, 0, 5)) {
			return array('code' => 0, 'stdout' => '<log><logentry revision="63811"><author>committer</author><date>' . gmdate('Y-m-d\TH:i:s.000000\Z', NOW - $age) . '</date></logentry></log>', 'stderr' => '');
		}
		throw new RuntimeException('Unexpected SVN operation.');
	};
}

function verify(string $input, ?callable $svn = null, array $options = array()): array {
	return run(array_merge(array('svn-tags.php', '--verify'), $options), $svn ?? fake_svn(), $input, NOW);
}

// Version parsing never evaluates the PHP body.
check('read src version exactly', '6.5.12-src', parse_wp_version(version_body('6.5.12-src')));
check('strip src', '6.5.12', release_tag(parse_wp_version(version_body('6.5.12-src'))));
check('refuse alpha with revision', null, release_tag(parse_wp_version(version_body('7.1.3-alpha-63811-src'))));
check('major release', '6.8', release_tag(parse_wp_version(version_body('6.8'))));
check('major release with src', '6.8', release_tag('6.8-src'));
foreach (array('6.5.12-alpha', '6.5.12-beta1-src', '6.5.12-RC1-src', '6.5.12-63811-src', '6.5.12-src-src', '6.5.12.1', "6.5.12\n") as $version) {
	check("refuse {$version}", null, release_tag($version));
}
check_throws('missing assignment', RuntimeException::class, static fn() => parse_wp_version('<?php $other = "6.8";'));
check_throws('comment is not assignment', RuntimeException::class, static fn() => parse_wp_version('<?php /* $wp_version = "6.8"; */'));
check_throws('expression is not literal', RuntimeException::class, static fn() => parse_wp_version('<?php $wp_version = "6.8" . "-src";'));
check('double quoted literal', '6.8', parse_wp_version('<?php $wp_version = "6.8";'));
check('comment before real version', '6.8', parse_wp_version('<?php // $wp_version = "0.0";' . "\n" . '$wp_version = "6.8";'));
$duplicate_version = version_body('6.5.12-src') . '$wp_version = "6.5.13-alpha-src";';
check_throws('second assignment rejected', RuntimeException::class, static fn() => parse_wp_version($duplicate_version));
check_throws('identical second assignment rejected', RuntimeException::class, static fn() => parse_wp_version(version_body('6.5.12-src') . '$wp_version = "6.5.12-src";'));

// Command grammar and messages.
$line = command_line(DEFAULT_SVN, '6.5', '6.5.12');
$short = 'svn cp ^/branches/6.5 ^/tags/6.5.12 -m "Tagging WordPress 6.5.12."';
check('absolute branch', '6.5', parse_command($line)['branch']);
check('relative tag', '6.5.12', parse_command($short)['tag']);
check('relative message', 'Tagging WordPress 6.5.12.', parse_command($short)['message']);
foreach (array('Tag 6.5.12', 'Tag 6.5.12.', 'Tagging WordPress 6.5.12', 'Tagging WordPress 6.5.12.') as $message) {
	check("accept message {$message}", str_starts_with($message, 'Tagging') ? 'Tagging WordPress' : 'Tag', message_form(parse_command('svn cp ^/branches/6.5 ^/tags/6.5.12 -m "' . $message . '"')['message'], '6.5.12'));
}
check('single quoted message', 'Tag 6.5.12', parse_command("svn cp ^/branches/6.5 ^/tags/6.5.12 -m 'Tag 6.5.12'")['message']);
check('wrong message tag', null, message_form('Tag 6.5.13', '6.5.12'));
check('tabs separate command tokens', '6.5', parse_command(str_replace('svn cp ', "svn\tcp\t", $line))['branch']);
$vertical_tab = str_replace(' ^/tags/', "\v^/tags/", $short);
check_throws('vertical tab cannot separate paths', InvalidArgumentException::class, static fn() => parse_command($vertical_tab));
foreach (array('echo nope', 'svn copy ^/branches/6.5 ^/tags/6.5.12 -m "Tag 6.5.12"', 'svn cp ^/branches/6.5 ^/branches/6.4 -m "Tag 6.5.12"', 'svn cp ^/tags/6.5.11 ^/tags/6.5.12 -m "Tag 6.5.12"', 'svn cp ^/branches/6.5 ^/tags/6.5.12', $line . '; echo bad', str_replace('develop.svn.wordpress.org', 'other.test', $line)) as $bad) {
	check_throws('malformed command ' . $bad, InvalidArgumentException::class, static fn() => parse_command($bad));
}

// Missing paths are distinct from authentication, transport, and server errors.
check('normal missing info path', true, missing_path(ABSENT));
check('missing filesystem path', true, missing_path("svn: E160013: File not found: revision 63811, path '/tags/6.5.12'"));
check('error code in missing URL is ignored', true, missing_path(str_replace('example.test', 'example.test/E170001', ABSENT)));
check('filesystem absence with code in URL', true, missing_path("svn: E160013: URL 'https://example.test/E170001/tags/6.5.12' does not exist"));
check('bare absence code is not a diagnostic', false, missing_path('W170000: non-existent'));
check('absence code inside URL is not evidence', false, missing_path("svn: E200009: URL 'https://example.test/W170000:non-existent' failed"));
check('unrelated warning appended to absence fails', false, missing_path(ABSENT . 'svn: warning: W155010: Other problem'));
check('unrelated error appended to absence fails', false, missing_path(ABSENT . 'svn: E000013: Permission denied'));
foreach (array('svn: E170001: Authentication failed', 'svn: E175002: Server error', 'svn: E170013: Unable to connect', 'svn: E200009: Other error', ABSENT . 'svn: E170001: Authentication failed', 'svn: E170000: Wrong URL scheme') as $error) {
	check('not absence: ' . $error, false, missing_path($error));
}
check_throws('malformed log', RuntimeException::class, static fn() => parse_log('nothing'));
check_throws('malformed XML log', RuntimeException::class, static fn() => parse_log('<log><logentry revision="12"><date>2026-09-22T17:00:00Z</date></log>'));
check_throws('missing log date', RuntimeException::class, static fn() => parse_log('<log><logentry revision="12"><author>2020-01-01 00:00:00 +0000</author></logentry></log>'));
check_throws('invalid log date', RuntimeException::class, static fn() => parse_log('<log><logentry revision="12"><date>2026-99-99T00:00:00.000000Z</date></logentry></log>'));
check('log timezone honored', strtotime('2026-09-22T17:00:00Z'), parse_log('<log><logentry revision="12"><date>2026-09-22T10:00:00-07:00</date></logentry></log>')['time']);
check_throws('write operation refused before process starts', InvalidArgumentException::class, static fn() => run_svn(array('cp')));

// Verify the full decision path through a fake reader.
$result = verify($line);
check('matching tag passes', 0, $result['code']);
check('verification normalizes relative paths and preserves message', str_replace('^/', DEFAULT_SVN . '/', $short) . "\n", verify($short)['stdout']);
$normalized = verify("svn\tcp ^/branches/6.5/ ^/tags/6.5.12/ -m 'Tagging WordPress 6.5.12.'", null, array('--svn=https://example.test/svn/'));
check('relative paths use configured repository', 0, $normalized['code']);
check('accepted commands rebuilt with own message', 'svn cp https://example.test/svn/branches/6.5 https://example.test/svn/tags/6.5.12 -m "Tagging WordPress 6.5.12."' . "\n", $normalized['stdout']);
check('report has exact version', true, str_contains($result['stderr'], '6.5.12-src'));
check('report has revision', true, str_contains($result['stderr'], 'r63811'));
check('report has age', true, str_contains($result['stderr'], '61s'));
check('report names form', true, str_contains($result['stderr'], 'message form Tag.'));
check('comments and blanks ignored', 0, verify("# commands\n\n{$line}\n")['code']);
$result = verify($line, fake_svn(array('6.5' => '6.5.12-src'), array('6.5.12')));
check('existing tag fails', 2, $result['code']);
check('existing tag named', true, str_contains($result['stderr'], 'tag 6.5.12 already exists'));
check('existing tag suppresses stdout', '', $result['stdout']);
$result = verify(command_line(DEFAULT_SVN, '6.7', '6.9.8'), fake_svn(array('6.7' => '6.7.6-src')));
check('copy paste version mismatch fails', 2, $result['code']);
check('mismatch names read version', true, str_contains($result['stderr'], "'6.7.6-src' (expected 6.7.6)"));
$result = verify("{$line}\n{$line}");
check('duplicate branch fails', 2, $result['code']);
check('duplicate branch named', true, str_contains($result['stderr'], 'duplicate branch 6.5'));
check('duplicate tag named', true, str_contains($result['stderr'], 'duplicate tag 6.5.12'));
$result = verify($line, null, array('--branches=6.4'));
check('set mismatch fails', 2, $result['code']);
check('missing branch named', true, str_contains($result['stderr'], 'Missing branches: 6.4.'));
check('extra branch named', true, str_contains($result['stderr'], 'Extra branches: 6.5.'));
check('matching set passes', 0, verify($line, null, array('--branches=6.5'))['code']);
check('10 seconds fails', 2, verify($line, fake_svn(array('6.5' => '6.5.12-src'), array(), 10))['code']);
check('60 seconds passes', 0, verify($line, fake_svn(array('6.5' => '6.5.12-src'), array(), 60))['code']);
check('61 seconds passes', 0, verify($line, fake_svn())['code']);
check('future timestamp fails', 2, verify($line, fake_svn(array('6.5' => '6.5.12-src'), array(), -10))['code']);
check('custom age threshold', 2, verify($line, null, array('--min-age=2m'))['code']);
$spoofed_author = static function (array $args): array {
	$result = fake_svn(array('6.5' => '6.5.12-src'), array(), 10)($args);
	if ('log' === $args[0]) {
		$result['stdout'] = str_replace('committer', '2000-01-01 00:00:00 +0000 | spoof', $result['stdout']);
	}
	return $result;
};
$result = verify($line, $spoofed_author);
check('date-shaped author cannot spoof age', 2, $result['code']);
check('age uses XML date', true, str_contains($result['stderr'], 'r63811 is 10s old'));
check('spoofed author releases no commands', '', $result['stdout']);
$result = verify($line . "\n" . str_replace(array('6.5.12', '6.5'), array('6.4.9', '6.4'), $short), fake_svn(array('6.5' => '6.5.12-src', '6.4' => '6.4.9-src')));
check('mixed forms fail', 2, $result['code']);
check('mixed forms reported', true, str_contains($result['stderr'], 'List mixes message forms: Tag and Tagging WordPress.'));
check('second form reported', true, str_contains($result['stderr'], 'Line 2 (6.4): message form Tagging WordPress.'));
check('same form with period passes', 0, verify(str_replace('Tag 6.5.12', 'Tag 6.5.12.', $line))['code']);
check('wrong message fails', 2, verify(str_replace('Tag 6.5.12', 'Tag 6.5.13', $line))['code']);
check('unrecognized message fails', 2, verify(str_replace('Tag 6.5.12', 'release', $line))['code']);
check('unbumped verify fails', 2, verify($line, fake_svn(array('6.5' => '6.5.12-alpha-src')))['code']);

// Generation is all or nothing and preserves branch order.
$versions = array('6.5' => '6.5.12-src', '6.4' => '6.4.9-src', '6.3' => '6.3.8-src');
$args = array('svn-tags.php', '--generate', '--branches=6.5,6.4,6.3');
$result = run($args, fake_svn($versions), null, NOW);
check('three branches generate', 0, $result['code']);
check('three ordered commands', command_line(DEFAULT_SVN, '6.5', '6.5.12') . "\n" . command_line(DEFAULT_SVN, '6.4', '6.4.9') . "\n" . command_line(DEFAULT_SVN, '6.3', '6.3.8') . "\n", $result['stdout']);
$versions['6.4'] = '6.4.9-alpha-63811-src';
$result = run($args, fake_svn($versions), null, NOW);
check('unbumped generation exits 2', 2, $result['code']);
check('unbumped generation suppresses all commands', '', $result['stdout']);
check('unbumped report retains exact version', true, str_contains($result['stderr'], "unbumped version '6.4.9-alpha-63811-src'"));
check('later branches still checked', true, str_contains($result['stderr'], '6.3.8-src'));
check('generation also checks recent commits', 2, run(array('svn-tags.php', '--generate', '--branches=6.5'), fake_svn(array('6.5' => '6.5.12-src'), array(), 10), null, NOW)['code']);
check('generation also checks existing tags', 2, run(array('svn-tags.php', '--generate', '--branches=6.5'), fake_svn(array('6.5' => '6.5.12-src'), array('6.5.12')), null, NOW)['code']);
check('branch version belongs to its branch', 2, run(array('svn-tags.php', '--generate', '--branches=6.5'), fake_svn(array('6.5' => '6.9.8-src')), null, NOW)['code']);
$reassigned_version = static fn(array $args): array => 'cat' === $args[0] ? array('code' => 0, 'stdout' => $duplicate_version, 'stderr' => '') : fake_svn()($args);
$result = run(array('svn-tags.php', '--generate', '--branches=6.5'), $reassigned_version, null, NOW);
check('reassigned version prevents generation', 1, $result['code']);
check('reassigned version suppresses commands', '', $result['stdout']);
check('reassigned version reported', true, str_contains($result['stderr'], 'Multiple wp_version assignments'));

// Tool failures never masquerade as findings or release partial commands.
$broken = static fn(array $args): array => array('code' => 1, 'stdout' => '', 'stderr' => 'svn: E170001: Authentication failed');
$result = verify($line, $broken);
check('tool failure exits 1', 1, $result['code']);
check('tool failure has error row', true, str_contains($result['stderr'], 'ERROR'));
check('tool failure has no commands', '', $result['stdout']);
$info_error = static fn(array $args): array => 'info' === $args[0] ? $broken($args) : fake_svn()($args);
check('info auth failure is not absence', 1, verify($line, $info_error)['code']);
$missing_version = static fn(array $args): array => 'cat' === $args[0] ? array('code' => 0, 'stdout' => '<?php', 'stderr' => '') : fake_svn()($args);
check('missing version is tool error', 1, verify($line, $missing_version)['code']);
foreach (array('<log><logentry', '<log><logentry revision="12"/></log>') as $bad_log) {
	$invalid_log = static fn(array $args): array => 'log' === $args[0] ? array('code' => 0, 'stdout' => $bad_log, 'stderr' => '') : fake_svn()($args);
	$result = verify($line, $invalid_log);
	check('invalid XML log is tool error', 1, $result['code']);
	check('invalid XML log suppresses commands', '', $result['stdout']);
}
$no_reads = static function (): never {
	throw new LogicException('Unexpected SVN read.');
};
$result = verify($line . "\nnot a command", $no_reads);
check('malformed line exits 1 before reading SVN', 1, $result['code']);
check('malformed line identifies location', true, str_contains($result['stderr'], 'Line 2: Expected svn cp'));
check('empty list exits 1', 1, verify("\n# none", $no_reads)['code']);
$result = verify($vertical_tab, $no_reads);
check('vertical tab fails before SVN reads', true, str_contains($result['stderr'], 'Line 1: Expected svn cp'));
check('vertical tab releases no commands', '', $result['stdout']);

// CLI options and local file input.
check('plain seconds', 90, parse_duration('90'));
check('minutes', 120, parse_duration('2m'));
check('hours', 3600, parse_duration('1h'));
check_throws('bad duration', InvalidArgumentException::class, static fn() => parse_duration('1d'));
check_throws('oversized duration', InvalidArgumentException::class, static fn() => parse_duration('9999999999h'));
check('branch list deduplicates', array('6.5', '6.4'), parse_branches('6.5,6.4,6.5'));
check_throws('empty branches', InvalidArgumentException::class, static fn() => parse_branches(''));
check_throws('invalid branch', InvalidArgumentException::class, static fn() => parse_branches('6.5.12'));
check('normalize repository URL', 'https://example.test/svn', parse_svn_url('https://example.test/svn/'));
check_throws('shell characters rejected', InvalidArgumentException::class, static fn() => parse_svn_url('https://example.test/$(whoami)'));
check('custom repository works', 0, verify(str_replace(DEFAULT_SVN, 'https://example.test/svn', $line), null, array('--svn=https://example.test/svn/'))['code']);
foreach (array(array(), array('--generate'), array('--generate', '--verify'), array('--verify', '--execute'), array('--generate', '--branches=6.5', '--file=x'), array('--verify', '--min-age=bad')) as $options) {
	check('invalid options ' . implode(' ', $options), 1, run(array_merge(array('svn-tags.php'), $options), $no_reads, $line, NOW)['code']);
}
check('help is offline', 0, run(array('svn-tags.php', '--help'), $no_reads)['code']);
$path = __DIR__ . '/commands-' . bin2hex(random_bytes(6)) . '.txt';
try {
	file_put_contents($path, $line . "\n");
	check('file input passes', 0, run(array('svn-tags.php', '--verify', '--file=' . $path), fake_svn(), null, NOW)['code']);
} finally {
	if (is_file($path)) {
		unlink($path);
	}
}
check('missing file exits 1', 1, run(array('svn-tags.php', '--verify', '--file=' . $path), $no_reads, null, NOW)['code']);

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}
echo "Passed {$assertions} assertions.\n";
