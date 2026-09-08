#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check that release tags on core.svn.wordpress.org reached the WordPress/WordPress Git mirror.
 *
 * Usage:
 *   php verify-tags-reached-mirror.php --tags=7.0.4,6.8.5   poll until each tag reaches the mirror
 *   php verify-tags-reached-mirror.php --audit              check every tag on SVN once
 *
 * Options (gate only unless noted):
 *   --timeout=45m     give up on a tag after this long
 *   --interval=60s    time between polls
 *   --svn=URL         SVN tags directory, default https://core.svn.wordpress.org/tags/
 *   --repo=OWNER/NAME GitHub mirror or git URL, default WordPress/WordPress
 *   Durations: a whole number, with s (default), m or h. Examples: 90, 5m, 1h.
 *
 * Exit 0: every tag is on the mirror at the SVN revision, or is a KNOWN mismatch.
 * Exit 2: a tag is missing, on another revision, or has no git-svn-id line.
 * Exit 1: the script broke.
 *
 * Per tag: read the SVN revision with PROPFIND, fetch the mirror's tag commit into a
 * scratch repo (commit objects only, no GitHub API or token), compare the revision in
 * its git-svn-id line. The gate times each tag from its first appearance on SVN, since
 * release tags arrive in waves. A tag that never appears on SVN, or whose checks keep
 * failing, times out from the start of the run.
 *
 * Do not change:
 *   - Fetch refs/tags/<T> by full ref name. A branch named 5.3 shadows the tag 5.3.
 *   - Match /tags/<T>@<rev> and ignore the host. Tags up to 3.3.2 use svn.automattic.com.
 *   - Send a User-Agent. core.svn answers 403 without one.
 *
 * Needs PHP 8.1 with SimpleXML and git 2.20+. Tests: php tests/verify-tags-reached-mirror-tests.php
 */

const DEFAULT_SVN  = 'https://core.svn.wordpress.org/tags/';
const DEFAULT_REPO = 'WordPress/WordPress';
const ROW          = "%-10s %-8s %-8s %-9s %s\n";
const RULE         = "------------------------------------------------------------------\n";

// Tags that mismatch forever: a later commit changed the tag directory on SVN
// and the mirror kept the original copy. tag => array(mirror revision, svn revision)
const KNOWN = array(
	'3.1.3' => array(18044, 18288),
	'3.6.1' => array(25316, 25317),
);

function fail(string $message, int $code = 1): never {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function parse_duration(string $text): int {
	if (1 !== preg_match('/^(\d{1,9})([smh]?)$/', $text, $match)) {
		throw new InvalidArgumentException("Bad duration: '{$text}'. Use a whole number with an optional unit: s (default), m, or h. Examples: 90, 90s, 5m, 1h.");
	}
	return (int) $match[1] * array('' => 1, 's' => 1, 'm' => 60, 'h' => 3600)[$match[2]];
}

function format_duration(float $seconds): string {
	$seconds = (int) $seconds;
	$hours   = intdiv($seconds, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	return ($hours ? "{$hours}h" : '') . ($hours || $minutes ? "{$minutes}m" : '') . ($seconds % 60) . 's';
}

/** Split --tags into unique names. Accepts commas or whitespace. */
function parse_tag_list(string $text): array {
	$tags = array_values(array_unique(preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY)));
	if (!$tags) {
		throw new InvalidArgumentException('--tags is empty.');
	}
	foreach ($tags as $tag) {
		if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$(?<!\.lock)(?<!\.)/', $tag) || str_contains($tag, '..')) {
			throw new InvalidArgumentException("Bad tag name: '{$tag}'.");
		}
	}
	return $tags;
}

function parse_svn_url(string $url): string {
	$url = rtrim($url, '/') . '/';
	if (1 !== preg_match('#^https?://[^/]+/.#', $url)) {
		throw new InvalidArgumentException("--svn must be an http(s) URL to a tags directory, got '{$url}'.");
	}
	return $url;
}

/** Accept owner/name for GitHub, or any git URL. */
function parse_repo_url(string $repo): string {
	if (1 === preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
		return "https://github.com/{$repo}.git";
	}
	if (1 === preg_match('#^\w+://\S+$#', $repo)) {
		return $repo;
	}
	throw new InvalidArgumentException("--repo must be owner/name or a git URL, got '{$repo}'.");
}

/**
 * PROPFIND a Subversion path. Returns the parsed multistatus, or null on 404.
 * The transport is injectable so the tests run without a network.
 */
function svn_propfind(string $url, int $depth, ?callable $transport = null): ?SimpleXMLElement {
	$transport ??= static function (string $request_url, array $options): array {
		$handle = fopen($request_url, 'r', false, stream_context_create($options));
		if (false === $handle) {
			return array('body' => false, 'headers' => array());
		}
		$headers = stream_get_meta_data($handle)['wrapper_data'];
		$body    = stream_get_contents($handle);
		fclose($handle);
		return array('body' => $body, 'headers' => $headers);
	};
	$options  = array(
		'http' => array(
			'method'          => 'PROPFIND',
			'header'          => "Depth: {$depth}\r\nUser-Agent: verify-tags-reached-mirror\r\n",
			'timeout'         => 60,
			'ignore_errors'   => true,
			'follow_location' => 0,
		),
	);
	$response = $transport($url, $options);
	$status   = 1 === preg_match('#^HTTP/\S+ (\d+)#', $response['headers'][0] ?? '', $match) ? (int) $match[1] : 0;
	if (404 === $status) {
		return null;
	}
	if (false === $response['body'] || 207 !== $status) {
		throw new RuntimeException("PROPFIND {$url} returned HTTP {$status}.");
	}
	libxml_use_internal_errors(true);
	$document = simplexml_load_string($response['body']);
	libxml_clear_errors();
	if (false === $document) {
		throw new RuntimeException('PROPFIND returned invalid XML.');
	}
	return $document;
}

/** Map tag name => revision from a multistatus. $base is the path of the tags directory. */
function svn_tags_from_multistatus(SimpleXMLElement $document, string $base): array {
	$pattern = '#^' . preg_quote($base, '#') . '([^/]+)/$#';
	$tags    = array();
	foreach ($document->children('DAV:')->response as $response) {
		$href     = rawurldecode((string) $response->children('DAV:')->href);
		$revision = (string) $response->children('DAV:')->propstat->prop?->children('DAV:')?->{'version-name'};
		if ('' !== $revision && 1 === preg_match($pattern, $href, $match)) {
			$tags[$match[1]] = (int) $revision;
		}
	}
	return $tags;
}

function svn_tag_revision(string $svn_url, string $tag, ?callable $transport = null): ?int {
	$url      = $svn_url . rawurlencode($tag) . '/';
	$document = svn_propfind($url, 0, $transport);
	if (null === $document) {
		return null;
	}
	return svn_tags_from_multistatus($document, (string) parse_url($svn_url, PHP_URL_PATH))[$tag]
		?? throw new RuntimeException("PROPFIND {$url} answered 207 without an entry for the tag.");
}

function svn_tag_revisions(string $svn_url, ?callable $transport = null): array {
	$document = svn_propfind($svn_url, 1, $transport);
	if (null === $document) {
		throw new RuntimeException("{$svn_url} does not exist.");
	}
	$tags = svn_tags_from_multistatus($document, (string) parse_url($svn_url, PHP_URL_PATH));
	if (!$tags) {
		throw new RuntimeException("No tags found under {$svn_url}.");
	}
	return $tags;
}

/** @return array{code:int,stdout:string,stderr:string} */
function run_command(array $command, ?string $cwd = null): array {
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start ' . implode(' ', $command));
	}
	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	return array('code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr);
}

/** Run git in the scratch repository, throw with stderr on failure. */
function git(string ...$args): string {
	$result = run_command(array_merge(array('git', '-C', scratch_repo()), $args));
	if (0 !== $result['code']) {
		throw new RuntimeException('git ' . implode(' ', $args) . ' failed: ' . trim($result['stderr']));
	}
	return $result['stdout'];
}

/** Bare repository for fetched tag commits, created once and removed at exit. */
function scratch_repo(): string {
	static $directory = null;
	if (null !== $directory) {
		return $directory;
	}
	$path = sys_get_temp_dir() . '/verify-tags-reached-mirror-' . bin2hex(random_bytes(6));
	if (!mkdir($path, 0700)) {
		throw new RuntimeException("Unable to create {$path}.");
	}
	// The absent-tag check matches git's English message, so keep git in the C locale.
	putenv('LC_ALL=C');
	putenv('GIT_TERMINAL_PROMPT=0');
	putenv('GIT_ASKPASS=');
	register_shutdown_function(static function () use ($path): void {
		try {
			$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($entries as $entry) {
				$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
			}
			rmdir($path);
		} catch (Throwable $error) {
			fwrite(STDERR, "Could not remove {$path}: {$error->getMessage()}\n");
		}
	});
	$init = run_command(array('git', 'init', '-q', '--bare'), $path);
	if (0 !== $init['code']) {
		throw new RuntimeException('git init failed: ' . trim($init['stderr']));
	}
	// Shutdown functions do not run when a signal kills the process, and the
	// gate is meant to be cancelled with Ctrl-C.
	if (function_exists('pcntl_async_signals')) {
		pcntl_async_signals(true);
		foreach (array(SIGINT, SIGTERM) as $signal) {
			pcntl_signal($signal, static fn() => exit(128 + $signal));
		}
	}
	return $directory = $path;
}

/** Fetch only the commit objects behind $refspec. */
function mirror_fetch(string $repo_url, string $refspec): array {
	$command = array('git', '-C', scratch_repo(), '-c', 'credential.helper=', '-c', 'http.lowSpeedLimit=1', '-c', 'http.lowSpeedTime=60', 'fetch', '-q', '--no-tags', '--filter=tree:0', '--depth=1', $repo_url, $refspec);
	return run_command($command);
}

function revision_from_message(string $tag, ?string $message): ?int {
	$pattern = '#git-svn-id: \S+/tags/' . preg_quote($tag, '#') . '@(\d+) #';
	return 1 === preg_match($pattern, (string) $message, $match) ? (int) $match[1] : null;
}

/** Null when the tag is not on the mirror; 0 when it is there without a usable git-svn-id line. */
function mirror_tag_revision(string $repo_url, string $tag): ?int {
	$result = mirror_fetch($repo_url, "+refs/tags/{$tag}:refs/tags/{$tag}");
	if (0 !== $result['code']) {
		if (str_contains($result['stderr'], "couldn't find remote ref")) {
			return null;
		}
		throw new RuntimeException('git fetch failed: ' . trim($result['stderr']));
	}
	return revision_from_message($tag, git('log', '-1', '--format=%B', "refs/tags/{$tag}")) ?? 0;
}

/** Parse `git for-each-ref` output in the format mirror_tag_revisions() asks for: name => commit message. */
function parse_tag_refs(string $output): array {
	$tags = array();
	foreach (explode("\x01", $output) as $record) {
		if ('' === trim($record)) {
			continue;
		}
		[$name, $type, $contents, $peeled] = explode("\x00", $record, 4);
		$tags[ltrim($name)] = 'tag' === $type ? $peeled : $contents;
	}
	return $tags;
}

function mirror_tag_revisions(string $repo_url): array {
	$result = mirror_fetch($repo_url, '+refs/tags/*:refs/tags/*');
	if (0 !== $result['code']) {
		throw new RuntimeException('git fetch failed: ' . trim($result['stderr']));
	}
	$output = git('for-each-ref', 'refs/tags', '--format=%(refname:strip=2)%00%(objecttype)%00%(contents)%00%(*contents)%01');
	$tags   = array();
	foreach (parse_tag_refs($output) as $name => $message) {
		$tags[$name] = revision_from_message($name, $message) ?? 0;
	}
	return $tags;
}

/** PASS, KNOWN, ABSENT, or MISMATCH. */
function verdict(string $tag, int $svn_revision, ?int $mirror_revision): string {
	if (null === $mirror_revision) {
		return 'ABSENT';
	}
	if ($mirror_revision === $svn_revision) {
		return 'PASS';
	}
	if ((KNOWN[$tag] ?? null) === array($mirror_revision, $svn_revision)) {
		return 'KNOWN';
	}
	return 'MISMATCH';
}

function report(string $tag, ?int $svn, ?int $mirror, string $verdict, string $detail = ''): void {
	printf(ROW, $tag, null === $svn ? '' : "r{$svn}", null === $mirror ? '' : "r{$mirror}", $verdict, $detail);
}

/** Check every tag on SVN once. Returns true when nothing needs a human. */
function audit(string $svn_url, string $repo_url): bool {
	$svn    = svn_tag_revisions($svn_url);
	$mirror = mirror_tag_revisions($repo_url);
	asort($svn);
	$counts = array();
	foreach ($svn as $tag => $revision) {
		$result           = verdict((string) $tag, $revision, $mirror[$tag] ?? null);
		$counts[$result]  = ($counts[$result] ?? 0) + 1;
		if ('PASS' !== $result) {
			report((string) $tag, $revision, $mirror[$tag] ?? null, $result);
		}
	}
	ksort($counts);
	$summary = array();
	foreach ($counts as $result => $count) {
		$summary[] = "{$count} {$result}";
	}
	echo RULE . count($svn) . ' tags on SVN: ' . implode(', ', $summary) . "\n";
	return passed(array_keys($counts));
}

/**
 * Release gate: poll until each tag reaches the mirror or times out. Returns the final
 * line of every tag, in the given order, as array(svn, mirror, verdict, detail, reason).
 * The reason of a TIMEOUT is 'svn', 'mirror' or 'check'; other verdicts carry null.
 * Every poll that still finds a tag absent prints a WAITING line.
 * $io carries the clock, sleeper, both lookups, and the line printer so the tests can stub them.
 */
function gate(array $tags, int $timeout, int $interval, array $io): array {
	$io        += array('now' => static fn(): float => microtime(true), 'sleep' => 'sleep', 'report' => 'report');
	$started    = $io['now']();
	$first_seen = array();
	$pending    = $tags;
	$final      = array();
	$svn_seen   = array();
	$done       = static function (int $index, ?int $svn, ?int $mirror, string $verdict, string $detail, ?string $reason = null) use (&$pending, &$final, $io): void {
		$io['report']($pending[$index], $svn, $mirror, $verdict, $detail);
		$final[$pending[$index]] = array($svn, $mirror, $verdict, $detail, $reason);
		unset($pending[$index]);
	};
	while ($pending) {
		foreach ($pending as $index => $tag) {
			$now     = $io['now']();
			$elapsed = $now - ($first_seen[$tag] ?? $started);
			$expired = $elapsed >= $timeout;
			try {
				$svn_revision = $io['svn']($tag);
				if (null === $svn_revision) {
					if ($expired) {
						$done($index, null, null, 'TIMEOUT', 'not on SVN after ' . format_duration($elapsed), 'svn');
					} else {
						$io['report']($tag, null, null, 'WAITING', 'not on SVN after ' . format_duration($elapsed));
					}
					continue;
				}
				$first_seen[$tag] ??= $now;
				$svn_seen[$tag]     = $svn_revision;
				$elapsed           = $now - $first_seen[$tag];
				$expired           = $elapsed >= $timeout;
				$mirror_revision   = $io['mirror']($tag);
			} catch (RuntimeException $error) {
				if ($expired) {
					$done($index, $svn_seen[$tag] ?? null, null, 'TIMEOUT', $error->getMessage(), 'check');
				} else {
					$io['report']($tag, $svn_seen[$tag] ?? null, null, 'RETRY', $error->getMessage());
				}
				continue;
			}
			$result = verdict($tag, $svn_revision, $mirror_revision);
			if ('ABSENT' === $result) {
				if ($expired) {
					$done($index, $svn_revision, null, 'TIMEOUT', '+' . format_duration($elapsed), 'mirror');
				} else {
					$io['report']($tag, $svn_revision, null, 'WAITING', '+' . format_duration($elapsed));
				}
				continue;
			}
			$done($index, $svn_revision, $mirror_revision, $result, '+' . format_duration($elapsed));
		}
		if ($pending) {
			$io['sleep']($interval);
		}
	}
	return array_replace(array_flip($tags), $final);
}

/** True when nothing needs a human: every verdict is PASS or KNOWN. */
function passed(array $verdicts): bool {
	return !array_diff($verdicts, array('PASS', 'KNOWN'));
}

/** One line of counts for the gate result, from the final lines gate() returns. */
function gate_summary(array $final): string {
	$reasons = array_count_values(array_filter(array_column($final, 4)));
	$labels  = array(
		'svn'      => 'missing from SVN',
		'mirror'   => 'missing from the mirror',
		'MISMATCH' => 'on another revision',
		'check'    => 'with failing checks',
	);
	$counts  = $reasons + array_count_values(array_column($final, 2));
	$parts   = array(count($final) . (1 === count($final) ? ' tag checked' : ' tags checked'));
	foreach ($labels as $key => $label) {
		$count = $counts[$key] ?? 0;
		if ($count || 'svn' === $key || 'mirror' === $key) {
			$parts[] = "{$count} {$label}";
		}
	}
	return implode(', ', $parts);
}

function main(array $argv): int {
	$usage   = "Usage: {$argv[0]} (--tags=<T,...> | --audit) [--timeout=45m] [--interval=60s] [--svn=<url>] [--repo=<owner/name>]";
	$options = array('timeout' => '45m', 'interval' => '60s', 'svn' => DEFAULT_SVN, 'repo' => DEFAULT_REPO);
	$tags    = null;
	$summary = null;
	$audit   = false;
	foreach (array_slice($argv, 1) as $arg) {
		if ('--audit' === $arg) {
			$audit = true;
		} elseif ('--help' === $arg) {
			echo $usage, "\n";
			return 0;
		} elseif (1 === preg_match('/^--tags=(.*)$/', $arg, $match)) {
			$tags = $match[1];
		} elseif (1 === preg_match('/^--(timeout|interval|svn|repo)=(.*)$/', $arg, $match)) {
			$options[$match[1]] = $match[2];
		} else {
			fail("Unknown argument: {$arg}\n{$usage}");
		}
	}
	if ($audit === (null !== $tags)) {
		fail($usage);
	}
	$svn_url  = parse_svn_url($options['svn']);
	$repo_url = parse_repo_url($options['repo']);
	$timeout  = parse_duration($options['timeout']);
	$interval = parse_duration($options['interval']);
	if ($timeout < 1 || $interval < 1) {
		throw new InvalidArgumentException('--timeout and --interval must be at least 1s.');
	}
	if ($interval > $timeout) {
		throw new InvalidArgumentException('--interval must not be longer than --timeout.');
	}
	$tag_list = $audit ? array() : parse_tag_list($tags);
	if (0 !== run_command(array('/bin/sh', '-c', 'command -v git'))['code']) {
		throw new RuntimeException('git not found in PATH.');
	}
	if (!ini_get('allow_url_fopen')) {
		throw new RuntimeException('allow_url_fopen is off; the SVN lookup needs it.');
	}
	// Stream warnings become exceptions so a refused connection is retried by
	// the gate instead of printed between report lines.
	set_error_handler(static function (int $severity, string $message): never {
		throw new RuntimeException($message);
	}, E_WARNING);

	echo($audit ? 'Tag mirror audit: ' : 'Tag mirror gate: ') . "{$svn_url} vs " . preg_replace('#//[^@/]+@#', '//', $repo_url) . "\n" . RULE;
	printf(ROW, 'Tag', 'SVN', 'Mirror', 'Verdict', 'Detail');
	if ($audit) {
		$passed = audit($svn_url, $repo_url);
	} else {
		$final = gate($tag_list, $timeout, $interval, array(
			'svn'    => static fn(string $tag): ?int => svn_tag_revision($svn_url, $tag),
			'mirror' => static fn(string $tag): ?int => mirror_tag_revision($repo_url, $tag),
		));
		echo RULE . "Summary\n";
		printf(ROW, 'Tag', 'SVN', 'Mirror', 'Verdict', 'Detail');
		foreach ($final as $tag => $line) {
			report((string) $tag, ...array_slice($line, 0, 4));
		}
		$passed  = passed(array_column($final, 2));
		$summary = gate_summary($final);
	}
	echo RULE;
	if (!$passed) {
		echo 'Result: FAIL — ' . ($summary ?? 'see the lines above') . ".\n";
		return 2;
	}
	echo 'Result: PASS — ' . ($summary ?? 'every tag is on the mirror at the SVN revision') . ".\n";
	return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	try {
		exit(main($argv));
	} catch (Throwable $error) {
		fail($error->getMessage());
	}
}
