<?php

declare(strict_types=1);

/** Helpers that more than one tool in this repo uses. Tests: php lib/tests/lib-tests.php */

function fail(string $message, int $code = 1): never {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

/**
 * Parse long options from argv, including the script name at index zero.
 * A trailing colon declares a required value; other options are valueless.
 * Accept --name=value or --name value. Use = for values beginning with a dash.
 * Explicit empty values are preserved for the command's own validation.
 * Repeated options retain getopt's scalar-or-array result shape.
 */
function parse_cli_options(array $argv, array $declarations): array {
	$allowed = array();
	foreach ($declarations as $declaration) {
		$allowed[rtrim($declaration, ':')] = str_ends_with($declaration, ':');
	}
	$output_key = null;
	$output_alias = null;
	foreach (array('out' => 'output', 'output' => 'out') as $declared => $alias) {
		if (in_array($declared . ':', $declarations, true) && !array_key_exists($alias, $allowed)) {
			$output_key = $declared;
			$output_alias = $alias;
			$allowed[$alias] = true;
			break;
		}
	}
	$options = array();
	for ($index = 1; $index < count($argv); ++$index) {
		$token = $argv[$index];
		if ('--' === $token && $index === count($argv) - 1) {
			break;
		}
		if (!str_starts_with($token, '--') || '--' === $token) {
			throw new InvalidArgumentException('Unexpected argument: ' . $token);
		}
		$pair = explode('=', substr($token, 2), 2);
		$name = $pair[0];
		if (!array_key_exists($name, $allowed)) {
			throw new InvalidArgumentException("Unknown option: --{$name}");
		}
		$value = false;
		if ($allowed[$name]) {
			if (2 === count($pair)) {
				$value = $pair[1];
			} else {
				if (!isset($argv[$index + 1]) || str_starts_with($argv[$index + 1], '-')) {
					throw new InvalidArgumentException("Option --{$name} requires a value.");
				}
				$value = $argv[++$index];
			}
		} elseif (2 === count($pair)) {
			throw new InvalidArgumentException("Option --{$name} takes no value.");
		}
		if (array_key_exists($name, $options)) {
			$options[$name] = array_merge((array) $options[$name], array($value));
		} else {
			$options[$name] = $value;
		}
	}
	if (null !== $output_alias && array_key_exists($output_alias, $options)) {
		if (array_key_exists($output_key, $options) && $options[$output_key] !== $options[$output_alias]) {
			throw new InvalidArgumentException('Options --out and --output must have identical values.');
		}
		$options[$output_key] = $options[$output_alias];
		unset($options[$output_alias]);
	}
	return $options;
}

/** Report syntax errors before a command reads inputs or reaches a writer. */
function cli_options(array $argv, array $declarations): array {
	try {
		return parse_cli_options($argv, $declarations);
	} catch (InvalidArgumentException $error) {
		fail($error->getMessage());
	}
}

function git_command_without_auto_maintenance(array $command): array {
	if (!isset($command[0]) || ('git' !== $command[0] && !str_ends_with($command[0], '/git'))) {
		return $command;
	}
	for ($index = 1; $index < count($command) - 1; ++$index) {
		if ('-c' === $command[$index] && 'maintenance.auto=false' === $command[$index + 1]) {
			return $command;
		}
	}
	array_splice($command, 1, 0, array('-c', 'maintenance.auto=false'));
	return $command;
}

/**
 * @return array{code:int,stdout:string,stderr:string}
 */
function run_command(array $command, ?string $cwd = null, bool $allow_failure = false): array {
	$command = git_command_without_auto_maintenance($command);
	$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
	}
	fclose($pipes[0]);

	// Drain stdout and stderr in one select loop: reading one to the end first
	// deadlocks once the child fills the other pipe's buffer.
	$open   = array(1 => $pipes[1], 2 => $pipes[2]);
	$output = array(1 => '', 2 => '');
	foreach ($open as $pipe) {
		stream_set_blocking($pipe, false);
	}
	while ($open) {
		$read   = array_values($open);
		$write  = null;
		$except = null;
		if (false === stream_select($read, $write, $except, null)) {
			break;
		}
		foreach ($read as $pipe) {
			$index = array_search($pipe, $open, true);
			$chunk = fread($pipe, 65536);
			if (false === $chunk || ('' === $chunk && feof($pipe))) {
				fclose($pipe);
				unset($open[$index]);
				continue;
			}
			$output[$index] .= $chunk;
		}
	}
	foreach ($open as $pipe) {
		fclose($pipe);
	}
	$code = proc_close($process);

	$result = array('code' => $code, 'stdout' => $output[1], 'stderr' => $output[2]);
	if (!$allow_failure && 0 !== $code) {
		$message = sprintf("Command failed (%d): %s\n%s", $code, implode(' ', $command), trim($result['stderr']));
		if (str_contains($result['stderr'], 'promisor remote')) {
			$message .= "\nThis checkout is a partial clone, and the tool never fetches missing objects on demand. Use a full clone.";
		}
		throw new RuntimeException($message);
	}
	return $result;
}

function set_verbose(bool $verbose): void {
	$GLOBALS['core_release_utils_verbose'] = $verbose;
}

function verbose_log(string $message): void {
	if ($GLOBALS['core_release_utils_verbose'] ?? false) {
		fwrite(STDERR, '[verbose] ' . $message . PHP_EOL);
	}
}

/** Make a temporary directory, readable only by its owner (0700), under the system temporary directory. */
function temporary_worktree(string $prefix): string {
	$base = realpath(sys_get_temp_dir());
	if (false === $base) {
		throw new RuntimeException('Unable to resolve the system temporary directory.');
	}
	$path = $base . '/' . $prefix . '-' . bin2hex(random_bytes(6));
	if (!mkdir($path, 0700)) {
		throw new RuntimeException("Unable to create a temporary worktree directory at {$path}.");
	}
	return $path;
}

function remove_temporary_directory(string $path, string $prefix): void {
	if (!is_dir($path)) {
		return;
	}
	$temporary_root = realpath(sys_get_temp_dir());
	$real_path      = realpath($path);
	if (
		false === $temporary_root
		|| false === $real_path
		|| !str_starts_with($real_path, $temporary_root . DIRECTORY_SEPARATOR)
		|| !str_starts_with(basename($real_path), $prefix)
	) {
		throw new RuntimeException('Refusing to remove an unexpected temporary directory.');
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($real_path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $item) {
		$removed = $item->isDir() && !$item->isLink()
			? rmdir($item->getPathname())
			: unlink($item->getPathname());
		if (!$removed) {
			throw new RuntimeException('Unable to remove the temporary directory.');
		}
	}
	if (!rmdir($real_path)) {
		throw new RuntimeException('Unable to remove the temporary directory itself.');
	}
}

function require_checkout(string $path): string {
	verbose_log("Checking Git checkout at {$path}");
	$repo = realpath($path);
	if (false === $repo || (!is_dir($repo . '/.git') && !is_file($repo . '/.git'))) {
		throw new InvalidArgumentException("Not a Git checkout: {$path}");
	}
	$check = run_command(array('git', 'rev-parse', '--is-inside-work-tree'), $repo, true);
	if (0 !== $check['code'] || 'true' !== trim($check['stdout'])) {
		throw new InvalidArgumentException("Not a Git worktree: {$path}");
	}
	verbose_log("Using Git checkout {$repo}");
	return $repo;
}

/**
 * Normalize a GitHub remote URL to owner/name, or null.
 *
 * Only canonical github.com https, http, ssh:// and git@ forms normalize. A local
 * path falls back to its last two segments, for mirror and test checkouts. A colon
 * before the first slash is Git's rule for a remote (scp-like host:path, scheme://,
 * or a transport:: helper), so those return null. A name with ? or # returns null,
 * so a token in a query string is never reported back.
 */
function normalize_github_remote(string $url): ?string {
	if (preg_match('~^(?:https?://github\.com/|ssh://git@github\.com/|git@github\.com:)([^/\s?#]+)/([^/\s?#]+?)(?:\.git)?/?$~i', $url, $matches)) {
		return "{$matches[1]}/{$matches[2]}";
	}
	$first_slash = strpos($url, '/');
	$leading     = false === $first_slash ? $url : substr($url, 0, $first_slash);
	if (str_contains($leading, ':')) {
		return null;
	}

	$path     = rtrim($url, '/');
	$path     = preg_replace('~\.git$~i', '', $path);
	$segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => '' !== $segment));
	$resolved = 2 <= count($segments) ? implode('/', array_slice($segments, -2)) : $path;
	return preg_match('~[?#]~', $resolved) ? null : $resolved;
}

/**
 * Check that every origin fetch and push URL resolves to $github_repo.
 *
 * Never print the raw URL: it can hold a credential.
 */
function require_origin_matches(string $repo, string $github_repo): void {
	verbose_log("Checking that origin matches --repository={$github_repo}");
	foreach (array('fetch' => array('git', 'remote', 'get-url', 'origin'), 'push' => array('git', 'remote', 'get-url', '--push', '--all', 'origin')) as $side => $command) {
		$result = run_command($command, $repo, true);
		$urls   = array_values(array_filter(array_map('trim', explode("\n", $result['stdout'])), static fn(string $url): bool => '' !== $url));
		if (0 !== $result['code'] || !$urls) {
			throw new InvalidArgumentException("Checkout has no 'origin' remote: {$repo}");
		}
		foreach ($urls as $origin_url) {
			$origin_repo = normalize_github_remote($origin_url);
			if (null === $origin_repo) {
				$inspect = implode(' ', $command);
				throw new InvalidArgumentException(
					"Checkout origin {$side} url is not a recognized github.com remote for --repository ({$github_repo}). "
					. "Inspect it by running '{$inspect}' in the checkout: {$repo}"
				);
			}
			if (0 !== strcasecmp($origin_repo, $github_repo)) {
				throw new InvalidArgumentException(
					"Checkout origin {$side} url resolves to {$origin_repo}, which does not match --repository ({$github_repo})."
				);
			}
		}
	}
	verbose_log("Origin matches --repository={$github_repo}");
}

function copy_package(string $source, string $destination, int $attempts = 3, int $delay = 20): void {
	if (1 > $attempts) {
		throw new InvalidArgumentException('Attempts must be at least 1.');
	}
	$context = null;
	if (1 === preg_match('~^https?://~i', $source)) {
		$context = stream_context_create(array('http' => array(
			'timeout' => 60,
			'user_agent' => 'WordPress core-release-utils',
		)));
	} else {
		$source = realpath($source);
		if (false === $source || !is_file($source)) {
			throw new InvalidArgumentException('The package must be an HTTP(S) URL or an existing local zip file.');
		}
		$attempts = 1;
	}
	$last_error = $source;
	for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
		if (@copy($source, $destination, $context)) {
			return;
		}
		$error = error_get_last();
		$last_error = $error['message'] ?? $source;
		if ($attempt < $attempts) {
			sleep($delay);
		}
	}
	throw new RuntimeException("Unable to copy the package zip after {$attempts} attempt(s): {$last_error}");
}

function package_layout(array $entries): void {
	foreach ($entries as $entry) {
		if (
			!is_string($entry)
			|| !str_starts_with($entry, 'wordpress/')
			|| str_contains($entry, '\\')
			|| str_contains($entry, "\0")
			|| array_intersect(array('', '.', '..'), explode('/', rtrim($entry, '/')))
		) {
			throw new InvalidArgumentException('Every zip entry must sit under one wordpress/ directory without path traversal.');
		}
	}
	if (!in_array('wordpress/wp-includes/version.php', $entries, true)) {
		throw new InvalidArgumentException('The zip is missing wordpress/wp-includes/version.php.');
	}
}

function open_package(string $path): ZipArchive {
	$zip = new ZipArchive();
	if (true !== $zip->open($path)) {
		throw new RuntimeException('Unable to open the package zip.');
	}
	try {
		$entries = array();
		for ($index = 0; $index < $zip->numFiles; ++$index) {
			$entries[] = $zip->getNameIndex($index);
		}
		package_layout($entries);
	} catch (Throwable $error) {
		$zip->close();
		throw $error;
	}
	return $zip;
}

function package_version(string $content): string {
	if (1 !== preg_match('/^\s*\$wp_version\s*=\s*([\'\"])(\d+\.\d+(?:\.\d+)?)\1\s*;/m', $content, $match)) {
		if (1 === preg_match('/^\s*\$wp_version\s*=\s*([\'\"])(\d+\.\d+(?:\.\d+)?-[A-Za-z0-9.-]+)\1\s*;/m', $content, $match)) {
			throw new InvalidArgumentException("The package version {$match[2]} is a pre-release; this tool tests stable release zips only.");
		}
		throw new InvalidArgumentException('The package version.php has no valid $wp_version assignment.');
	}
	return $match[2];
}

function package_details(string $content): array {
	preg_match('/^\s*\$wp_local_package\s*=\s*([\'\"])([^\'\"]*)\1\s*;/m', $content, $match);
	return array('version' => package_version($content), 'local_package' => $match[2] ?? null);
}

function read_package_details(string $path): array {
	$zip = open_package($path);
	try {
		$content = $zip->getFromName('wordpress/wp-includes/version.php');
		if (false === $content) {
			throw new RuntimeException('Unable to read version.php from the package zip.');
		}
		return package_details($content);
	} finally {
		$zip->close();
	}
}

function read_package_version(string $path): string {
	return read_package_details($path)['version'];
}
