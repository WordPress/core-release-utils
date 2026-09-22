#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate or verify WordPress release tag commands without executing them.
 * Usage: php svn-tags.php --generate --branches=6.5,6.4,6.3
 * Usage: php svn-tags.php --verify [--branches=6.5,6.4] [--file=commands.txt]
 * Options: --svn=URL (repository root), --min-age=60s (whole number with s, m, or h).
 * Exit 0: all checks pass. Exit 2: findings. Exit 1: malformed input or tool failure.
 * Needs PHP 8.1 and svn. Tests: php tests/svn-tags-tests.php
 */

const DEFAULT_SVN = 'https://develop.svn.wordpress.org';
const ROW         = "%-8s %-26s %-16s %-8s %-13s %-12s %s\n";
const USAGE       = 'Usage: svn-tags.php (--generate --branches=X.Y,... | --verify [--branches=X.Y,...] [--file=path]) [--svn=URL] [--min-age=60s]';

function fail(string $message, int $code = 1): never {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function parse_duration(string $text): int {
	if (1 !== preg_match('/^(\d{1,9})([smh]?)$/', $text, $match)) {
		throw new InvalidArgumentException("Bad duration: '{$text}'. Use a whole number with s (default), m, or h.");
	}
	return (int) $match[1] * array('' => 1, 's' => 1, 'm' => 60, 'h' => 3600)[$match[2]];
}

function parse_branches(string $text): array {
	$branches = array_values(array_unique(preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY)));
	if (!$branches) {
		throw new InvalidArgumentException('--branches is empty.');
	}
	foreach ($branches as $branch) {
		if (1 !== preg_match('/^\d+\.\d+$/D', $branch)) {
			throw new InvalidArgumentException("Bad branch: '{$branch}'. Expected X.Y.");
		}
	}
	return $branches;
}

function parse_svn_url(string $url): string {
	$url = rtrim($url, '/');
	if (1 !== preg_match('#^https?://[A-Za-z0-9.-]+(?::[0-9]+)?(?:/[A-Za-z0-9._~-]+)*$#D', $url)) {
		throw new InvalidArgumentException('--svn must be an http(s) repository URL without credentials, query, or shell characters.');
	}
	return $url;
}

/** Read a literal assignment without executing PHP or matching assignments inside comments. */
function parse_wp_version(string $body): string {
	$tokens = array_values(array_filter(token_get_all($body), static fn($token): bool => !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)));
	$version = null;
	foreach ($tokens as $index => $token) {
		if (!is_array($token) || T_VARIABLE !== $token[0] || '$wp_version' !== $token[1] || '=' !== ($tokens[$index + 1] ?? null)) {
			continue;
		}
		if (null !== $version) {
			throw new RuntimeException('Multiple wp_version assignments in version.php.');
		}
		$value = $tokens[$index + 2] ?? null;
		if (!is_array($value) || T_CONSTANT_ENCAPSED_STRING !== $value[0] || ';' !== ($tokens[$index + 3] ?? null)) {
			throw new RuntimeException('wp_version is not a literal string assignment.');
		}
		$version = substr($value[1], 1, -1);
	}
	if (null === $version) {
		throw new RuntimeException('No wp_version assignment in version.php.');
	}
	return $version;
}

function release_tag(string $version): ?string {
	$tag = str_ends_with($version, '-src') ? substr($version, 0, -4) : $version;
	return 1 === preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $tag) ? $tag : null;
}

function parse_command(string $line, string $svn_url = DEFAULT_SVN): array {
	if (1 !== preg_match('/^svn[ \t]+cp[ \t]+(\S+)[ \t]+(\S+)[ \t]+-m[ \t]+([\'"])([^\r\n]*?)\3[ \t]*$/D', trim($line, " \t"), $match)) {
		throw new InvalidArgumentException('Expected svn cp <branch> <tag> -m "<message>".');
	}
	$base = '(?:' . preg_quote($svn_url, '#') . '|\^)';
	if (1 !== preg_match('#^' . $base . '/branches/(\d+\.\d+)/?$#D', $match[1], $from)
		|| 1 !== preg_match('#^' . $base . '/tags/(\d+\.\d+(?:\.\d+)?)/?$#D', $match[2], $to)) {
		throw new InvalidArgumentException('Source must be a branch and destination a release tag in the configured SVN repository.');
	}
	return array('branch' => $from[1], 'tag' => $to[1], 'message' => $match[4]);
}

function message_form(string $message, string $tag): ?string {
	if (1 === preg_match('/^(Tag|Tagging WordPress) ' . preg_quote($tag, '/') . '\.?$/D', $message, $match)) {
		return $match[1];
	}
	return null;
}

function parse_log(string $output): array {
	$log = simplexml_load_string($output, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
	if (false === $log || 'log' !== $log->getName() || 1 !== count($log->logentry)
		|| 1 !== preg_match('/^\d+$/D', (string) $log->logentry['revision']) || 1 !== count($log->logentry->date)) {
		throw new RuntimeException('svn log returned no revision and timestamp.');
	}
	$timestamp = (string) $log->logentry->date;
	if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp)) {
		throw new RuntimeException('svn log returned an invalid timestamp.');
	}
	$date   = DateTimeImmutable::createFromFormat(str_contains($timestamp, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP', $timestamp);
	$errors = DateTimeImmutable::getLastErrors();
	if (false === $date || (false !== $errors && ($errors['warning_count'] || $errors['error_count']))) {
		throw new RuntimeException('svn log returned an invalid timestamp.');
	}
	return array('revision' => (string) $log->logentry['revision'], 'time' => $date->getTimestamp());
}

/** Only path-not-found diagnostics mean an absent tag; authentication and transport errors must fail. */
function missing_path(string $stderr): bool {
	preg_match_all('/^svn: (?:warning: )?[EW](\d{6}):[^\r\n]*/m', $stderr, $codes);
	return (bool) $codes[1]
		&& !array_diff($codes[1], array('160013', '170000', '200009'))
		&& 1 === preg_match('/^svn: (?:warning: )?(?:W170000:[^\r\n]*non-existent|E160013:[^\r\n]*(?:not found|does not exist))/m', $stderr);
}

/** @return array{code:int,stdout:string,stderr:string} */
function run_svn(array $args): array {
	if (!in_array($args[0] ?? '', array('cat', 'info', 'log'), true)) {
		throw new InvalidArgumentException('Only SVN reads are supported.');
	}
	if (!function_exists('proc_open')) {
		throw new RuntimeException('proc_open is required to read SVN.');
	}
	$environment           = getenv();
	$environment['LC_ALL'] = 'C';
	$process = proc_open(array_merge(array('svn', '--non-interactive', '--no-auth-cache'), $args), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start svn.');
	}
	fclose($pipes[0]);
	$output = array(1 => '', 2 => '');
	$open   = array(1 => $pipes[1], 2 => $pipes[2]);
	foreach ($open as $pipe) {
		stream_set_blocking($pipe, false);
	}
	while ($open) {
		$read = array_values($open);
		$write = $except = null;
		if (false === stream_select($read, $write, $except, null)) {
			foreach ($open as $pipe) {
				fclose($pipe);
			}
			proc_terminate($process);
			proc_close($process);
			throw new RuntimeException('Unable to read svn output.');
		}
		foreach ($open as $key => $pipe) {
			if (in_array($pipe, $read, true)) {
				$output[$key] .= stream_get_contents($pipe);
				if (feof($pipe)) {
					fclose($pipe);
					unset($open[$key]);
				}
			}
		}
	}
	return array('code' => proc_close($process), 'stdout' => $output[1], 'stderr' => $output[2]);
}

/** The reader receives SVN arguments and returns code, stdout, and stderr. */
function svn_read(array $args, ?callable $svn = null, bool $allow_missing = false): ?string {
	$result = ($svn ?? 'run_svn')($args);
	if (0 === $result['code']) {
		return $result['stdout'];
	}
	if ($allow_missing && missing_path($result['stderr'])) {
		return null;
	}
	throw new RuntimeException('svn ' . implode(' ', $args) . ' failed: ' . trim($result['stderr']));
}

function command_line(string $svn_url, string $branch, string $tag, ?string $message = null): string {
	$message ??= "Tag {$tag}";
	return "svn cp {$svn_url}/branches/{$branch} {$svn_url}/tags/{$tag} -m \"{$message}\"";
}

/** Collect all rows and findings before releasing any commands to stdout. */
function check_tags(array $entries, bool $generate, ?array $expected, string $svn_url, int $min_age, ?callable $svn = null, ?int $now = null): array {
	$rows = $findings = $forms = $message_forms = $branches = $tags = $commands = array();
	$tool_error = false;
	foreach ($entries as $entry) {
		$branch = $entry['branch'];
		$tag    = $entry['tag'] ?? null;
		$label  = isset($entry['line']) ? "Line {$entry['line']} ({$branch})" : "Branch {$branch}";
		$issues = array();
		$row    = array($branch, '-', $tag ?? '-', '-', '-', '-', 'PASS');
		if (isset($branches[$branch])) {
			$issues[] = "{$label}: duplicate branch {$branch}.";
		}
		$branches[$branch] = true;
		try {
			$version = parse_wp_version(svn_read(array('cat', "{$svn_url}/branches/{$branch}/src/wp-includes/version.php"), $svn));
			$row[1]  = $version;
			$head    = release_tag($version);
			if (null === $head) {
				$issues[] = "{$label}: unbumped version '{$version}'.";
			} elseif ($head !== $branch && !str_starts_with($head, $branch . '.')) {
				$issues[] = "{$label}: version '{$version}' does not belong to branch {$branch}.";
			}
			if ($generate) {
				$tag = $head;
			} elseif (null !== $head && $tag !== $head) {
				$issues[] = "{$label}: tag {$tag} differs from branch version '{$version}' (expected {$head}).";
			}
			$row[2] = $tag ?? '-';
			if (null !== $tag) {
				$row[3] = null === svn_read(array('info', "{$svn_url}/tags/{$tag}"), $svn, true) ? 'no' : 'yes';
				if ('yes' === $row[3]) {
					$issues[] = "{$label}: tag {$tag} already exists.";
				}
			}
			$log    = parse_log(svn_read(array('log', '--xml', '-q', '-l', '1', "{$svn_url}/branches/{$branch}"), $svn));
			$age    = ($now ?? time()) - $log['time'];
			$row[4] = 'r' . $log['revision'];
			$row[5] = "{$age}s";
			if ($age < $min_age) {
				$issues[] = "{$label}: {$row[4]} is {$age}s old; requires at least {$min_age}s.";
			}
		} catch (Throwable $error) {
			$tool_error = true;
			$row[6]     = 'ERROR';
			$issues[]   = "{$label}: {$error->getMessage()}";
		}
		if (null !== $tag) {
			if (isset($tags[$tag])) {
				$issues[] = "{$label}: duplicate tag {$tag}.";
			}
			$tags[$tag] = true;
			$commands[] = command_line($svn_url, $branch, $tag, $generate ? null : $entry['message']);
		}
		if (!$generate) {
			$form = message_form($entry['message'], $tag);
			$forms[] = "{$label}: message form " . ($form ?? 'unrecognized') . '.';
			if (null === $form) {
				$issues[] = "{$label}: message must name tag {$tag} as 'Tag {$tag}' or 'Tagging WordPress {$tag}.'";
			} else {
				$message_forms[$form] = true;
			}
		}
		if ($issues && 'ERROR' !== $row[6]) {
			$row[6] = 'FAIL';
		}
		$rows[] = $row;
		$findings = array_merge($findings, $issues);
	}
	if (count($message_forms) > 1) {
		$findings[] = 'List mixes message forms: Tag and Tagging WordPress.';
	}
	if (null !== $expected) {
		$missing = array_diff($expected, array_keys($branches));
		$extra   = array_diff(array_keys($branches), $expected);
		if ($missing) {
			$findings[] = 'Missing branches: ' . implode(', ', $missing) . '.';
		}
		if ($extra) {
			$findings[] = 'Extra branches: ' . implode(', ', $extra) . '.';
		}
	}
	$stderr = sprintf(ROW, 'Branch', 'Version read', 'Tag', 'Exists?', 'Last revision', 'Age', 'Verdict');
	foreach ($rows as $row) {
		$stderr .= sprintf(ROW, ...$row);
	}
	foreach (array_merge($forms, $findings) as $detail) {
		$stderr .= $detail . "\n";
	}
	$code = $tool_error ? 1 : ($findings ? 2 : 0);
	$stderr .= 0 === $code ? 'PASS: ' . count($rows) . " branches checked.\n" : "FAIL: see findings above.\n";
	return array('code' => $code, 'stdout' => 0 === $code ? implode("\n", $commands) . "\n" : '', 'stderr' => $stderr);
}

/** Injectable input and clock keep the complete CLI decision path testable offline. */
function run(array $argv, ?callable $svn = null, ?string $input = null, ?int $now = null): array {
	try {
		$options = array('svn' => DEFAULT_SVN, 'min-age' => '60s');
		$mode = null;
		foreach (array_slice($argv, 1) as $arg) {
			if ('--help' === $arg) {
				return array('code' => 0, 'stdout' => USAGE . "\n", 'stderr' => '');
			}
			if (in_array($arg, array('--generate', '--verify'), true)) {
				if (null !== $mode) {
					throw new InvalidArgumentException('Choose exactly one mode.');
				}
				$mode = $arg;
			} elseif (1 === preg_match('/^--(branches|file|svn|min-age)=(.*)$/D', $arg, $match)) {
				$options[$match[1]] = $match[2];
			} else {
				throw new InvalidArgumentException("Unknown argument: {$arg}");
			}
		}
		$generate = '--generate' === $mode;
		if (null === $mode || ($generate && (!isset($options['branches']) || isset($options['file'])))) {
			throw new InvalidArgumentException(USAGE);
		}
		$svn_url  = parse_svn_url($options['svn']);
		$min_age  = parse_duration($options['min-age']);
		$expected = isset($options['branches']) ? parse_branches($options['branches']) : null;
		$entries  = array();
		if ($generate) {
			foreach ($expected as $branch) {
				$entries[] = array('branch' => $branch);
			}
		} else {
			if (null === $input) {
				if (isset($options['file']) && (!is_file($options['file']) || !is_readable($options['file']))) {
					throw new RuntimeException('Cannot read --file.');
				}
				$input = isset($options['file']) ? file_get_contents($options['file']) : stream_get_contents(STDIN);
				if (false === $input) {
					throw new RuntimeException('Cannot read command list.');
				}
			}
			foreach (preg_split('/\r\n|\n|\r/', $input) as $index => $line) {
				$line = trim($line, " \t");
				if ('' === $line || str_starts_with($line, '#')) {
					continue;
				}
				try {
					$entries[] = parse_command($line, $svn_url) + array('line' => $index + 1);
				} catch (InvalidArgumentException $error) {
					throw new InvalidArgumentException('Line ' . ($index + 1) . ': ' . $error->getMessage());
				}
			}
			if (!$entries) {
				throw new InvalidArgumentException('Command list is empty.');
			}
		}
		return check_tags($entries, $generate, $expected, $svn_url, $min_age, $svn, $now);
	} catch (Throwable $error) {
		return array('code' => 1, 'stdout' => '', 'stderr' => $error->getMessage() . "\n");
	}
}

function main(array $argv): int {
	$result = run($argv);
	fwrite(STDERR, $result['stderr']);
	fwrite(STDOUT, $result['stdout']);
	return $result['code'];
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	try {
		exit(main($argv));
	} catch (Throwable $error) {
		fail($error->getMessage());
	}
}
