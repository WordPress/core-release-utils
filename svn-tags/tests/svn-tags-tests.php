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

function fake_audit(array $tags = array('6.5.12' => array()), string $url = DEFAULT_SVN): callable {
	$reads = array();
	foreach ($tags as $tag => $data) {
		$data += array('branch' => '6.5', 'source' => '/branches/6.5', 'copy' => '1210', 'created' => '1211', 'last' => '1200', 'bump' => '1200', 'version' => $tag . '-src', 'message' => "Tag {$tag}", 'action' => 'A', 'path' => "/tags/{$tag}", 'extra' => '', 'later' => '');
		$paths = '<path action="' . $data['action'] . '" copyfrom-path="' . $data['source'] . '" copyfrom-rev="' . $data['copy'] . '">' . $data['path'] . '</path>' . $data['extra'];
		$log = $data['log'] ?? '<log>' . $data['later'] . '<logentry revision="' . $data['created'] . '"><paths>' . $paths . '</paths><msg>' . htmlspecialchars($data['message'], ENT_XML1) . '</msg></logentry></log>';
		$reads["log --xml -v --stop-on-copy {$url}/tags/{$tag}"] = isset($data['missing'])
			? array('code' => 1, 'stdout' => '', 'stderr' => "svn: E160013: File not found: revision 1211, path '/tags/{$tag}'")
			: array('code' => 0, 'stdout' => $log, 'stderr' => '');
		$reads["cat {$url}/tags/{$tag}/src/wp-includes/version.php@{$data['created']}"] = array('code' => 0, 'stdout' => version_body($data['version']), 'stderr' => '');
		foreach (array('' => 'last', '/src/wp-includes/version.php' => 'bump') as $path => $key) {
			$reads["log --xml -q -l 1 {$url}/branches/{$data['branch']}{$path}@{$data['copy']}"] = array('code' => 0, 'stdout' => '<log><logentry revision="' . $data[$key] . '"><date>2026-10-01T10:00:00Z</date></logentry></log>', 'stderr' => '');
		}
		$start = (int) $data['copy'] + 1;
		$post  = $data['post'] ?? null;
		$log   = '<log/>';
		if (null !== $post) {
			$post += array('revision' => '1220', 'paths' => array('src/wp-includes/version.php' => 'M', 'composer.json' => 'M', 'package.json' => 'M', 'package-lock.json' => 'M'), 'before' => array(), 'after' => array());
			$paths = '';
			foreach ($post['paths'] as $file => $action) {
				$paths .= '<path action="' . $action . '">/branches/' . $data['branch'] . '/' . $file . '</path>';
			}
			$log = '<log><logentry revision="' . $post['revision'] . '"><paths>' . $paths . '</paths></logentry></log>';
			$parts = explode('.', $tag);
			$next  = $parts[0] . '.' . $parts[1] . '.' . ((int) ($parts[2] ?? 0) + 1);
			foreach (array('before' => (int) $post['revision'] - 1, 'after' => $post['revision']) as $when => $revision) {
				$version = 'before' === $when ? $tag : $next;
				$files = $post[$when] + array(
					'src/wp-includes/version.php' => version_body('before' === $when ? $tag . '-src' : $next . '-alpha-' . $post['revision'] . '-src'),
					'composer.json' => json_encode(array('name' => 'example/core', 'version' => $version)),
					'package.json' => json_encode(array('name' => 'example-core', 'version' => $version, 'private' => true)),
					'package-lock.json' => json_encode(array('name' => 'example-core', 'version' => $version, 'lockfileVersion' => 3, 'packages' => array('' => array('name' => 'example-core', 'version' => $version), 'node_modules/example' => array('version' => '1.0.0')))),
				);
				foreach ($files as $file => $body) {
					$reads["cat {$url}/branches/{$data['branch']}/{$file}@{$revision}"] = null === $body
						? array('code' => 1, 'stdout' => '', 'stderr' => "svn: E160013: File not found: revision {$revision}, path '/branches/{$data['branch']}/{$file}'")
						: array('code' => 0, 'stdout' => $body, 'stderr' => '');
				}
			}
		}
		$reads["log --xml -v -r {$start}:HEAD -l 1 {$url}/branches/{$data['branch']}"] = array('code' => 0, 'stdout' => $log, 'stderr' => '');
	}
	return static function (array $args) use ($reads): array {
		$key = implode(' ', $args);
		if (!isset($reads[$key])) {
			throw new RuntimeException('Unexpected audit read: ' . $key);
		}
		return $reads[$key];
	};
}

function audit(string $input, ?callable $svn = null, array $options = array()): array {
	return run(array_merge(array('svn-tags.php', '--audit'), $options), $svn ?? fake_audit(), $input);
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
check('range generates same commands', $result['stdout'], run(array('svn-tags.php', '--generate', '--branch-start=6.5', '--branch-end=6.3'), fake_svn($versions), null, NOW)['stdout']);
$result = verify($line, null, array('--branch-start=6.5', '--branch-end=6.4'));
check('range sets expected branches for verify', 2, $result['code']);
check('range names missing branch', true, str_contains($result['stderr'], 'Missing branches: 6.4.'));
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
check('descending range', array('6.5', '6.4', '6.3'), branch_range('6.5', '6.3'));
check('ascending range', array('6.3', '6.4', '6.5'), branch_range('6.3', '6.5'));
check('range rolls over major versions', array('5.1', '5.0', '4.9', '4.8'), branch_range('5.1', '4.8'));
check('single branch range', array('6.5'), branch_range('6.5', '6.5'));
foreach (array(array('6.10', '6.5'), array('6.5', '6.5.1'), array('6', '6.5'), array('', '6.5')) as $bounds) {
	check_throws('invalid range ' . implode(' to ', $bounds), InvalidArgumentException::class, static fn() => branch_range(...$bounds));
}
check('normalize repository URL', 'https://example.test/svn', parse_svn_url('https://example.test/svn/'));
check_throws('shell characters rejected', InvalidArgumentException::class, static fn() => parse_svn_url('https://example.test/$(whoami)'));
check('custom repository works', 0, verify(str_replace(DEFAULT_SVN, 'https://example.test/svn', $line), null, array('--svn=https://example.test/svn/'))['code']);
foreach (array(array(), array('--generate'), array('--generate', '--verify'), array('--verify', '--execute'), array('--generate', '--branches=6.5', '--file=x'), array('--verify', '--min-age=bad')) as $options) {
	check('invalid options ' . implode(' ', $options), 1, run(array_merge(array('svn-tags.php'), $options), $no_reads, $line, NOW)['code']);
}
foreach (array(array('--generate', '--branch-start=6.5'), array('--generate', '--branch-end=6.3'), array('--generate', '--branch-start=6.5', '--branch-end=6.3', '--branches=6.5'), array('--verify', '--branch-start=6.5')) as $options) {
	check('invalid range options ' . implode(' ', $options), 1, run(array_merge(array('svn-tags.php'), $options), $no_reads, $line, NOW)['code']);
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

// Audit landed tags with a separate reader and pinned branch history.
$result = audit($line);
check('audit matching tag passes', 0, $result['code']);
check('audit stdout stays empty on pass', '', $result['stdout']);
check('audit pass summary', true, str_contains($result['stderr'], "PASS: 1 tags audited.\n"));
check('audit table columns', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, 'Branch', 'Tag', 'Created', 'Copied from', 'Bump', 'Version read', 'Post bump', 'Verdict')));
check('audit table values', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'none', 'PASS')));
check('audit message form', true, str_contains($result['stderr'], 'Line 1 (6.5): message form Tag.'));
$result = audit("# commands\n\n{$line}\n");
check('audit ignores blanks and comments', 0, $result['code']);
check('audit retains source line numbers', true, str_contains($result['stderr'], 'Line 3 (6.5): message form Tag.'));
check('audit relative paths pass', 0, audit(str_replace(DEFAULT_SVN, '^', $line))['code']);
check('audit relative paths use custom repository', 0, audit(str_replace(DEFAULT_SVN, '^', $line), fake_audit(array('6.5.12' => array()), 'https://example.test/svn'), array('--svn=https://example.test/svn/'))['code']);
check('audit trims landed trailing whitespace', 0, audit($line, fake_audit(array('6.5.12' => array('message' => "Tag 6.5.12 \t\n"))))['code']);
$cases = array(
	'missing tag' => array(array('missing' => true), 'tag 6.5.12 does not exist.'),
	'changed tag' => array(array('later' => '<logentry revision="1215"/><logentry revision="1214"/>'), 'tag 6.5.12 changed after creation in r1215, r1214.'),
	'wrong branch' => array(array('source' => '/branches/6.4'), "source branch '/branches/6.4' is not /branches/6.5."),
	'two paths' => array(array('extra' => '<path action="A" copyfrom-path="/branches/6.4" copyfrom-rev="1210">/tags/6.4.9</path>'), 'creation revision has 2 paths; expected exactly one.'),
	'wrong action' => array(array('action' => 'M'), "creation action 'M' is not A."),
	'wrong path' => array(array('path' => '/tags/6.5.13'), "creation path '/tags/6.5.13' is not /tags/6.5.12."),
	'missing source' => array(array('source' => '', 'copy' => ''), 'has no copy source with a numeric revision.'),
	'invalid copy revision' => array(array('copy' => 'unknown'), 'has no copy source with a numeric revision.'),
	'wrong message' => array(array('message' => 'Tagging WordPress 6.5.12.'), "landed message 'Tagging WordPress 6.5.12.' differs from command message 'Tag 6.5.12'."),
	'leading message whitespace' => array(array('message' => ' Tag 6.5.12'), "landed message ' Tag 6.5.12' differs"),
	'wrong version' => array(array('version' => '6.5.13-src'), "tag 6.5.12 differs from tag version '6.5.13-src'."),
	'unbumped version' => array(array('version' => '6.5.12-alpha-src'), "tag 6.5.12 differs from tag version '6.5.12-alpha-src'."),
	'change after bump' => array(array('last' => '1205'), 'branches/6.5 changed in r1205 after the version bump r1200, before the tag copy.'),
);
foreach ($cases as $name => [$data, $finding]) {
	$result = audit($line, fake_audit(array('6.5.12' => $data)));
	check("audit {$name} exits 2", 2, $result['code']);
	check("audit {$name} finding", true, str_contains($result['stderr'], $finding));
	check("audit {$name} has no stdout", '', $result['stdout']);
	check("audit {$name} fail summary", true, str_ends_with($result['stderr'], "FAIL: see findings above.\n"));
}
$result = audit($line, fake_audit(array('6.5.12' => array('missing' => true))));
check('audit missing tag has unknown cells and FAIL row', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', '-', '-', '-', '-', '-', 'FAIL')));
$result = audit($line, fake_audit(array('6.5.12' => array('source' => '/branches/6.4'))));
check('audit wrong branch skips the bump check', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.4@1210', '-', '6.5.12-src', 'none', 'FAIL')));
$result = audit($line, fake_audit(array('6.5.12' => array('later' => '<logentry revision="1215"/>'))));
check('audit uses oldest entry for creation', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'none', 'FAIL')));
$second = command_line(DEFAULT_SVN, '6.4', '6.4.9', 'Tagging WordPress 6.4.9.');
$reader = fake_audit(array('6.5.12' => array(), '6.4.9' => array('branch' => '6.4', 'source' => '/branches/6.4', 'message' => 'Tagging WordPress 6.4.9.')));
$result = audit($line . "\n" . $second, $reader);
check('audit mixed forms fail', 2, $result['code']);
check('audit mixed forms finding', true, str_contains($result['stderr'], 'List mixes message forms: Tag and Tagging WordPress.'));
check('audit second form reported', true, str_contains($result['stderr'], 'Line 2 (6.4): message form Tagging WordPress.'));
$result = audit(str_replace('Tag 6.5.12', 'release', $line), fake_audit(array('6.5.12' => array('message' => 'release'))));
check('audit unrecognized form fails', 2, $result['code']);
check('audit unrecognized form reported', true, str_contains($result['stderr'], 'message form unrecognized.'));
check('audit unrecognized form finding', true, str_contains($result['stderr'], "message must name tag 6.5.12 as 'Tag 6.5.12' or 'Tagging WordPress 6.5.12.'"));
$result = audit($line . "\n" . $line);
check('audit duplicates fail', 2, $result['code']);
check('audit duplicate branch finding', true, str_contains($result['stderr'], 'Line 2 (6.5): duplicate branch 6.5.'));
check('audit duplicate tag finding', true, str_contains($result['stderr'], 'Line 2 (6.5): duplicate tag 6.5.12.'));
$result = audit($line, null, array('--branches=6.4'));
check('audit branch set mismatch fails', 2, $result['code']);
check('audit missing branch finding', true, str_contains($result['stderr'], 'Missing branches: 6.4.'));
check('audit extra branch finding', true, str_contains($result['stderr'], 'Extra branches: 6.5.'));
check('audit matching branch set passes', 0, audit($line, null, array('--branches=6.5'))['code']);
$result = audit($line, null, array('--branch-start=6.5', '--branch-end=6.4'));
check('audit range mismatch fails', 2, $result['code']);
check('audit range missing branch finding', true, str_contains($result['stderr'], 'Missing branches: 6.4.'));
check('audit matching range passes', 0, audit($line, null, array('--branch-start=6.5', '--branch-end=6.5'))['code']);
foreach (array('--verify', '--generate', '--audit') as $mode) {
	$result = audit($line, $no_reads, array($mode));
	check('audit rejects mode ' . $mode, 1, $result['code']);
	check('audit conflicting modes diagnostic', true, str_contains($result['stderr'], 'Choose exactly one mode.'));
}
foreach (array('60s', '0', 'bad') as $age) {
	$result = audit($line, $no_reads, array('--min-age=' . $age));
	check('audit rejects explicit age ' . $age, 1, $result['code']);
	check('audit age diagnostic', true, str_contains($result['stderr'], '--min-age does not apply to --audit.'));
}
foreach (array('history', 'version', 'branch', 'bump') as $stage) {
	$reader = static function (array $args) use ($stage, $broken): array {
		$url = $args[count($args) - 1];
		$current = 'cat' === $args[0] ? 'version' : (in_array('--stop-on-copy', $args, true) ? 'history' : (str_contains($url, '/version.php@') ? 'bump' : 'branch'));
		return $stage === $current ? $broken($args) : fake_audit()($args);
	};
	$result = audit($line, $reader);
	check("audit {$stage} read failure exits 1", 1, $result['code']);
	check("audit {$stage} read failure ERROR row", true, str_contains($result['stderr'], 'ERROR'));
	check("audit {$stage} read failure diagnostic", true, str_contains($result['stderr'], 'Authentication failed'));
	check("audit {$stage} read failure empty stdout", '', $result['stdout']);
}
foreach (array('<log><logentry', '<log/>', '<other/>', '<log><logentry revision="bad"/></log>') as $xml) {
	$result = audit($line, fake_audit(array('6.5.12' => array('log' => $xml))));
	check('audit invalid history exits 1', 1, $result['code']);
	check('audit invalid history ERROR row', true, str_contains($result['stderr'], 'ERROR'));
	check('audit invalid history empty stdout', '', $result['stdout']);
}
$result = audit($line . "\n" . $second, fake_audit(array('6.5.12' => array('log' => '<log>'), '6.4.9' => array('missing' => true))));
check('audit tool error takes precedence over findings', 1, $result['code']);
check('audit continues after a row error', true, str_contains($result['stderr'], 'Line 2 (6.4): tag 6.4.9 does not exist.'));
$result = audit("# commands\n{$line}\nnot a command", $no_reads);
check('audit malformed command exits 1', 1, $result['code']);
check('audit malformed command line number', true, str_contains($result['stderr'], 'Line 3: Expected svn cp'));
$result = audit("\n# none", $no_reads);
check('audit empty list exits 1', 1, $result['code']);
check('audit empty list diagnostic', "Command list is empty.\n", $result['stderr']);
try {
	file_put_contents($path, $line . "\n");
	$result = run(array('svn-tags.php', '--audit', '--file=' . $path), fake_audit());
	check('audit file input passes', 0, $result['code']);
	check('audit file input empty stdout', '', $result['stdout']);
} finally {
	if (is_file($path)) {
		unlink($path);
	}
}
check('audit missing file exits 1', 1, run(array('svn-tags.php', '--audit', '--file=' . $path), $no_reads)['code']);

foreach (array('6.5.12' => '6.5.13', '6.5' => '6.5.1') as $tag => $next) {
	$result = audit(command_line(DEFAULT_SVN, '6.5', $tag), fake_audit(array($tag => array('post' => array(
		'after' => array('src/wp-includes/version.php' => version_body($next . '-alpha-1220-src')),
	)))));
	check("audit correct post bump for {$tag} passes", 0, $result['code']);
	check("audit correct post bump for {$tag} row", true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', $tag, 'r1211', '6.5@1210', 'r1200', $tag . '-src', 'r1220', 'PASS')));
	check("audit correct post bump for {$tag} empty stdout", '', $result['stdout']);
}
$result = audit($line);
check('audit no post bump passes', 0, $result['code']);
check('audit no post bump reports none', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'none', 'PASS')));
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('paths' => array('readme.html' => 'M'))))));
check('audit first unrelated branch change passes', 0, $result['code']);
check('audit first unrelated branch change reports none', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'none', 'PASS')));
$post_paths = array('src/wp-includes/version.php' => 'M', 'composer.json' => 'M', 'package.json' => 'M', 'package-lock.json' => 'M');
$without_composer = array_diff_key($post_paths, array('composer.json' => true));
$cases = array(
	'extra file' => array(array('paths' => $post_paths + array('readme.html' => 'M')), 'post-release bump r1220 changed unexpected path /branches/6.5/readme.html.'),
	'wrong next version' => array(array('after' => array('src/wp-includes/version.php' => version_body('6.5.14-alpha-1220-src'))), "post-release bump r1220 version '6.5.14-alpha-1220-src' is not '6.5.13-alpha-1220-src'."),
	'wrong alpha revision' => array(array('after' => array('src/wp-includes/version.php' => version_body('6.5.13-alpha-1219-src'))), "post-release bump r1220 version '6.5.13-alpha-1219-src' is not '6.5.13-alpha-1220-src'."),
	'extra version.php edit' => array(array('after' => array('src/wp-includes/version.php' => version_body('6.5.13-alpha-1220-src') . '$db_version = 1230;')), 'post-release bump r1220 changed version.php beyond the wp_version literal.'),
	'extra package.json edit' => array(array('after' => array('package.json' => '{"name":"example-core","version":"6.5.13","private":false}')), 'post-release bump r1220 changed package.json beyond the required version fields or left them incorrect.'),
	'old lockfile root version' => array(array('after' => array('package-lock.json' => '{"name":"example-core","version":"6.5.13","lockfileVersion":3,"packages":{"":{"name":"example-core","version":"6.5.12"},"node_modules/example":{"version":"1.0.0"}}}')), 'post-release bump r1220 changed package-lock.json beyond the required version fields or left them incorrect.'),
	'missing composer change' => array(array('paths' => $without_composer), 'post-release bump r1220 did not change composer.json.'),
	'non-M action' => array(array('paths' => array('package.json' => 'R') + $post_paths), "post-release bump r1220 action 'R' for /branches/6.5/package.json is not M."),
	'wrong previous JSON version' => array(array('before' => array('composer.json' => '{"name":"example/core","version":"6.5.11"}')), "composer.json version fields before post-release bump r1220 do not read '6.5.12'."),
	'invalid JSON' => array(array('after' => array('package.json' => '{')), 'post-release bump r1220 has invalid JSON in package.json:'),
	'missing PHP literal' => array(array('after' => array('src/wp-includes/version.php' => '<?php $other = 1;')), 'post-release bump r1220: No wp_version assignment in version.php.'),
);
foreach ($cases as $name => [$post, $finding]) {
	$result = audit("# commands\n{$line}", fake_audit(array('6.5.12' => array('post' => $post))));
	check("audit post bump {$name} exits 2", 2, $result['code']);
	check("audit post bump {$name} finding with source line", true, str_contains($result['stderr'], 'Line 2 (6.5): ' . $finding));
	check("audit post bump {$name} FAIL row", true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'r1220', 'FAIL')));
	check("audit post bump {$name} empty stdout", '', $result['stdout']);
}
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('paths' => $without_composer, 'before' => array('composer.json' => null))))));
check('audit absent composer on old branch is optional', 0, $result['code']);
check('audit absent composer on old branch reports post bump', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'r1220', 'PASS')));
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('after' => array('package.json' => "{\n  \"private\": true,\n  \"version\": \"6.5.13\",\n  \"name\": \"example-core\"\n}\n"))))));
check('audit JSON whitespace and key order are immaterial', 0, $result['code']);
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('before' => array('package.json' => '{"name":"example-core","version":"6.5.12","engine":"1.0"}'), 'after' => array('package.json' => '{"name":"example-core","version":"6.5.13","engine":"1"}'))))));
check('audit JSON compares values strictly', 2, $result['code']);
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('before' => array('package.json' => '{"name":"example-core","version":"6.5.12","config":{}}'), 'after' => array('package.json' => '{"name":"example-core","version":"6.5.13","config":[]}'))))));
check('audit JSON keeps objects and lists distinct', 2, $result['code']);
check('missing path accepts the cat warning form', true, missing_path("svn: warning: W160013: '/!svn/rvr/1219/branches/4.7/composer.json' path not found\nsvn: E200009: Could not cat all targets because some targets don't exist\nsvn: E200009: Illegal target for the requested operation\n"));
$before = "<?php\n// \$wp_version = '6.5.12-src';\n\$wp_version /* release */ = \"6.5.12-src\";\n\$other = '6.5.12-src';\n";
$after  = str_replace('"6.5.12-src"', '"6.5.13-alpha-1220-src"', $before);
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('before' => array('src/wp-includes/version.php' => $before), 'after' => array('src/wp-includes/version.php' => $after))))));
check('audit changes only assignment literal preserving comments and other strings', 0, $result['code']);
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('before' => array('src/wp-includes/version.php' => $before), 'after' => array('src/wp-includes/version.php' => str_replace('6.5.12-src', '6.5.13-alpha-1220-src', $before)))))));
check('audit detects edits to comments and other version strings', 2, $result['code']);
$result = audit($line, fake_audit(array('6.5.12' => array('post' => array('revision' => '1230')))));
check('audit delayed post bump passes', 0, $result['code']);
check('audit delayed post bump uses its own revision', true, str_contains($result['stderr'], sprintf(AUDIT_ROW, '6.5', '6.5.12', 'r1211', '6.5@1210', 'r1200', '6.5.12-src', 'r1230', 'PASS')));
$result = audit(str_replace(DEFAULT_SVN, '^', $line), fake_audit(array('6.5.12' => array('post' => array())), 'https://example.test/svn'), array('--svn=https://example.test/svn'));
check('audit post bump uses custom repository for all reads', 0, $result['code']);
$post_reader = fake_audit(array('6.5.12' => array('post' => array())));
foreach (array('log', 'cat') as $operation) {
	$reader = static function (array $args) use ($operation, $post_reader): array {
		$is_post = in_array('1211:HEAD', $args, true) || str_contains(end($args), '/branches/6.5/') && 'cat' === $args[0];
		return $is_post && $operation === $args[0] ? array('code' => 1, 'stdout' => '', 'stderr' => 'svn: E170001: Authentication failed') : $post_reader($args);
	};
	$result = audit($line, $reader);
	check("audit post bump {$operation} failure is a tool error", 1, $result['code']);
	check("audit post bump {$operation} failure reports authentication error", true, str_contains($result['stderr'], 'Authentication failed'));
}

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}
echo "Passed {$assertions} assertions.\n";
