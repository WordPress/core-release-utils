#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/lib.php';

/**
 * Smoke-test one built WordPress zip: a fresh install, then an upgrade from the previous release.
 *
 * Usage:
 *   php package-smoke.php --repository=WordPress/wordpress-develop --checkout=<path>
 *       --package=<zip URL or path> --playwright-module=<path> --output=<report.md> [options]
 *
 * Run --help for the options. The Docker environment comes from the release line's
 * branch in a wordpress-develop checkout, so every input is public.
 *
 * Exit 0: every row passed. Exit 2: a row failed or was blocked by the environment.
 * Exit 1: the script broke before or outside a test.
 *
 * Needs PHP 8.1 with zip, Node, Docker, and Playwright. Tests: php tests/package-smoke-tests.php
 */

/**
 * @return array<string,string|null> Worktree paths mapped to their branch refs.
 */
function registered_worktrees(string $repo): array {
	$output = trim(run_command(array('git', 'worktree', 'list', '--porcelain'), $repo)['stdout']);
	if ('' === $output) {
		return array();
	}

	$worktrees = array();
	foreach (preg_split('/\n\n+/', $output) ?: array() as $record) {
		$path   = null;
		$branch = null;
		foreach (explode("\n", $record) as $line) {
			if (str_starts_with($line, 'worktree ')) {
				$path = substr($line, strlen('worktree '));
			} elseif (str_starts_with($line, 'branch ')) {
				$branch = substr($line, strlen('branch '));
			}
		}
		if (null !== $path) {
			$worktrees[$path] = $branch;
		}
	}
	return $worktrees;
}

function remove_worktree(string $repo, string $path): void {
	if (!array_key_exists($path, registered_worktrees($repo))) {
		// Runs from finally blocks: throwing here would hide the failure that got us here.
		// rmdir removes only an empty directory, so a partial checkout stays for inspection.
		@rmdir($path);
		return;
	}

	verbose_log("Removing temporary worktree {$path}");
	$result = run_command(array('git', 'worktree', 'remove', '--force', $path), $repo, true);
	if (0 !== $result['code']) {
		$detail = trim($result['stderr']);
		throw new RuntimeException("Failed to remove temporary worktree {$path}" . ('' === $detail ? '.' : ": {$detail}"));
	}
	if (is_dir($path) || array_key_exists($path, registered_worktrees($repo))) {
		throw new RuntimeException("Git reported success but temporary worktree cleanup was incomplete: {$path}");
	}
}

function release_line(string $release): string {
	if (!preg_match('/^([0-9]+\.[0-9]+)/', $release, $matches)) {
		throw new InvalidArgumentException("Release does not contain a numbered release line: {$release}");
	}
	return $matches[1];
}

/** Fetch origin's $target branch into refs/remotes/origin/$target and return its tip. */
function fetch_target(string $repo, string $target): string {
	verbose_log("Refreshing target branch {$target}");
	$check = run_command(array('git', 'check-ref-format', '--branch', $target), $repo, true);
	if (0 !== $check['code']) {
		throw new InvalidArgumentException("Invalid target branch: {$target}");
	}
	$ref = "refs/remotes/origin/{$target}";
	run_command(array('git', 'fetch', '--quiet', 'origin', "+refs/heads/{$target}:{$ref}"), $repo);
	$head = trim(run_command(array('git', 'rev-parse', '--verify', "{$ref}^{commit}"), $repo)['stdout']);
	verbose_log("Target {$target} resolved to " . substr($head, 0, 12));
	return $head;
}

/**
 * Read the release line's .version-support-php.json without a worktree.
 *
 * @return array<string,mixed>
 */
function load_release_line_matrix(string $repo, string $release_line): array {
	$show = run_command(
		array('git', 'show', "refs/remotes/origin/{$release_line}:.version-support-php.json"),
		$repo,
		true
	);
	if (0 !== $show['code']) {
		throw new RuntimeException("Release line {$release_line} has no .version-support-php.json.");
	}
	$matrix = json_decode($show['stdout'], true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($matrix) || array_is_list($matrix)) {
		throw new InvalidArgumentException('.version-support-php.json must be an object keyed by branch.');
	}
	return $matrix;
}

function package_smoke_harness_path(): string {
	return __DIR__ . '/functional-smoke.js';
}

function package_smoke_previous_url(string $version): string {
	if (1 !== preg_match('/^(\d+\.\d+)(?:\.(\d+))?$/', $version, $match)) {
		throw new InvalidArgumentException('Invalid package version.');
	}
	$patch = (int) ($match[2] ?? 0);
	if (0 === $patch) {
		throw new InvalidArgumentException('Patch 0 has no previous release in its line; supply --previous.');
	}
	$previous = $match[1] . (1 === $patch ? '' : '.' . ($patch - 1));
	return "https://wordpress.org/wordpress-{$previous}.zip";
}

function package_smoke_version_reported(string $output, string $version): bool {
	$lines = array_map('trim', explode("\n", $output));
	return in_array($version, $lines, true);
}

function package_smoke_require_older(string $previous, string $version): void {
	$pad = static fn(string $release): string => implode('.', array_pad(explode('.', $release), 3, '0'));
	if (version_compare($pad($previous), $pad($version), '<')) {
		return;
	}
	throw new InvalidArgumentException("The previous release {$previous} is not older than the package {$version}.");
}

/**
 * @return array<int,array{arguments:array<int,string>,expects:?string,blocked:bool,retry:bool}>
 */
function package_smoke_package_steps(string $port, string $installed, ?string $upgrade): array {
	$step = static fn(array $arguments, ?string $expects = null, bool $blocked = false, bool $retry = false): array
		=> array('arguments' => $arguments, 'expects' => $expects, 'blocked' => $blocked, 'retry' => $retry);
	$steps = array(
		$step(array(
			'config', 'create', '--dbname=wordpress_develop', '--dbuser=root', '--dbpass=password',
			'--dbhost=mysql', '--force', '--config-file=/var/www/wp-config.php',
		), null, true, true),
		$step(array('config', 'set', 'WP_DEBUG', 'true', '--raw', '--type=constant'), null, true),
		$step(array('config', 'set', 'WP_DEBUG_LOG', 'true', '--raw', '--type=constant'), null, true),
		$step(array('config', 'set', 'WP_DEBUG_DISPLAY', 'true', '--raw', '--type=constant'), null, true),
		$step(array('db', 'reset', '--yes', '--defaults'), null, true),
		$step(array(
			'core', 'install', "--url=http://localhost:{$port}", '--title=package-smoke',
			'--admin_user=admin', '--admin_password=password', '--admin_email=test@example.com', '--skip-email',
		)),
		$step(array('core', 'version'), $installed),
	);
	if (null !== $upgrade) {
		$steps[] = $step(array('config', 'set', 'FS_METHOD', 'direct', '--type=constant'), null, true);
		$steps[] = $step(array('core', 'update', '/var/www/wordpress-package.zip'));
		$steps[] = $step(array('core', 'update-db'));
		$steps[] = $step(array('core', 'version'), $upgrade);
	}
	return $steps;
}

function package_smoke_locales(array $options): array {
	if (!isset($options['locales'])) {
		return array();
	}
	$locales = array_map('trim', explode(',', $options['locales']));
	foreach ($locales as $locale) {
		if ('en_US' === $locale) {
			throw new InvalidArgumentException('--locales cannot include en_US: it has no localized build.');
		}
		if (1 !== preg_match('/^[a-zA-Z][a-zA-Z0-9_@-]*$/', $locale)) {
			throw new InvalidArgumentException('--locales requires comma-separated locale codes.');
		}
	}
	return array_values(array_unique($locales));
}

function package_smoke_locale_row(
	string $locale,
	string $version,
	string $destination,
	?callable $download = null,
	?callable $read = null
): array {
	$download ??= 'copy_package';
	$read ??= 'read_package_details';
	$row = array(
		'test' => "WordPress {$version} package: {$locale}",
		'locale' => $locale,
		'url' => 'https://downloads.wordpress.org/release/' . rawurlencode($locale) . '/wordpress-' . rawurlencode($version) . '.zip',
		'http' => 'not requested',
		'version' => 'not read',
		'local_package' => 'not read',
		'locale_check' => 'fail',
		'status' => 'failed',
		'reason' => 'Test did not complete.',
	);
	try {
		try {
			$download($row['url'], $destination);
			$row['http'] = 'HTTP download succeeded';
		} catch (Throwable $error) {
			$row['http'] = $error->getMessage();
			throw $error;
		}
		$details = $read($destination);
		$row['version'] = $details['version'];
		$row['local_package'] = $details['local_package'] ?? 'missing';
		if ($version !== $row['version']) {
			throw new RuntimeException("Version mismatch: expected {$version}, read {$row['version']}.");
		}
		if (null === ($details['local_package'] ?? null)) {
			throw new RuntimeException('Missing $wp_local_package: not a localized build.');
		}
		if ($locale !== $details['local_package']) {
			throw new RuntimeException("Locale mismatch: expected {$locale}, read {$row['local_package']}.");
		}
		$row['locale_check'] = 'pass';
		$row['status'] = 'passed';
		$row['reason'] = 'All required checks passed.';
	} catch (Throwable $error) {
		$row['reason'] = $error->getMessage();
	}
	return $row;
}

/**
 * Compare the tested zip's md5 with the one WordPress.org publishes at <zip URL>.md5.
 * A local package has no published md5, so its comparison is n/a.
 */
function package_smoke_md5_row(
	string $version,
	string $md5,
	?string $package_url,
	string $destination,
	?callable $download = null
): array {
	$download ??= 'copy_package';
	$md5_url = null === $package_url ? null : preg_replace('/[?#].*$/', '', $package_url) . '.md5';
	$row = array(
		'test' => "WordPress {$version} package: md5",
		'md5' => $md5,
		// Keep any user:password@ out of the report.
		'md5_url' => null === $md5_url ? 'n/a' : preg_replace('~^(https?://)[^/]*@~i', '$1', $md5_url),
		'published_md5' => 'n/a',
		'md5_check' => 'n/a',
		'status' => 'passed',
		'reason' => 'Local package: no published md5 to compare.',
	);
	if (null === $package_url) {
		return $row;
	}
	$row['published_md5'] = 'not read';
	$row['md5_check'] = 'fail';
	$row['status'] = 'failed';
	try {
		try {
			$download($md5_url, $destination);
			$published = @file_get_contents($destination);
		} catch (Throwable $error) {
			throw new RuntimeException('Unable to fetch the published md5: ' . str_replace($md5_url, $row['md5_url'], $error->getMessage()));
		}
		if (false === $published || 1 !== preg_match('/^([a-f0-9]{32})\b/i', trim($published), $match)) {
			throw new RuntimeException("The published md5 at {$row['md5_url']} is not an md5.");
		}
		$row['published_md5'] = strtolower($match[1]);
		if ($row['published_md5'] !== strtolower($md5)) {
			throw new RuntimeException("md5 mismatch: tested {$md5}, published {$row['published_md5']}.");
		}
		$row['md5_check'] = 'pass';
		$row['status'] = 'passed';
		$row['reason'] = 'All required checks passed.';
	} catch (Throwable $error) {
		$row['reason'] = $error->getMessage();
	}
	return $row;
}

function package_smoke_extract_package(string $path, string $worktree): void {
	$zip = open_package($path);
	try {
		run_command(array('rm', '-rf', $worktree . '/package'), $worktree);
		if (!$zip->extractTo($worktree) || !rename($worktree . '/wordpress', $worktree . '/package')) {
			throw new RuntimeException('Unable to extract the package docroot.');
		}
	} finally {
		$zip->close();
	}
}

/**
 * @return array<string,array<int,string>>
 */
function package_smoke_php_versions(string $repo, string $release_line): array {
	$data      = load_release_line_matrix($repo, $release_line);
	$supported = array();
	foreach ($data as $key => $versions) {
		if (
			!is_string($key)
			|| !is_array($versions)
			|| !array_is_list($versions)
			|| !isset($versions[0])
			|| !is_string($versions[0])
			|| '' === trim($versions[0])
		) {
			continue;
		}
		$normalized = array_values(
			array_filter(
				array_map(
					static fn(mixed $version): string => is_string($version) ? trim($version) : '',
					$versions
				)
			)
		);
		if ($normalized) {
			$supported[str_replace('-', '.', $key)] = $normalized;
		}
	}
	return $supported;
}

/**
 * @return array<string,string>
 */
function package_smoke_php_floors(string $repo, string $release_line): array {
	$floors = array();
	foreach (package_smoke_php_versions($repo, $release_line) as $target => $versions) {
		$floors[$target] = $versions[0];
	}
	return $floors;
}

/**
 * @param array<string,array<int,string>> $versions
 * @param array<string,string>            $floors
 * @return array{floor:string,versions:array<int,string>}
 */
function package_smoke_php_target(array $versions, array $floors, string $target): array {
	if (!array_key_exists($target, $versions) || !array_key_exists($target, $floors)) {
		throw new InvalidArgumentException("No PHP floor is declared for target {$target} in the release-line matrix.");
	}

	return array(
		'floor'    => $floors[$target],
		'versions' => $versions[$target],
	);
}

/**
 * @return array<string,string>
 */
function package_smoke_package_scripts(string $worktree): array {
	$path = $worktree . '/package.json';
	if (!is_file($path)) {
		throw new RuntimeException('The branch has no package.json.');
	}
	$content = file_get_contents($path);
	if (false === $content) {
		throw new RuntimeException('Unable to read package.json from the branch.');
	}
	$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($data) || !isset($data['scripts']) || !is_array($data['scripts'])) {
		throw new InvalidArgumentException('The branch package.json has no scripts object.');
	}

	$scripts = array();
	foreach ($data['scripts'] as $name => $command) {
		if (is_string($name) && is_string($command)) {
			$scripts[$name] = $command;
		}
	}
	return $scripts;
}

/**
 * @return array{type:string,image:?string,platform:?string}
 */
function package_smoke_build_toolchain(string $worktree): array {
	$path = $worktree . '/package-lock.json';
	if (!is_file($path)) {
		throw new RuntimeException('The branch has no package-lock.json.');
	}
	$content = file_get_contents($path);
	if (false === $content) {
		throw new RuntimeException('Unable to read package-lock.json from the branch.');
	}
	$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($data) || !isset($data['lockfileVersion']) || !is_int($data['lockfileVersion'])) {
		throw new InvalidArgumentException('The branch package-lock.json has no integer lockfileVersion.');
	}

	if (1 === $data['lockfileVersion']) {
		$has_amd64_only_binary = package_smoke_lockfile_has_package($data, 'node-sass')
			|| package_smoke_lockfile_has_package($data, 'optipng-bin');
		return array(
			'type'     => 'container',
			'image'    => 'node:14-bullseye',
			'platform' => $has_amd64_only_binary ? 'linux/amd64' : null,
		);
	}

	return array('type' => 'host', 'image' => null, 'platform' => null);
}

function package_smoke_lockfile_has_package(array $data, string $package): bool {
	foreach ($data as $key => $value) {
		if ($package === $key && is_array($value) && isset($value['version'])) {
			return true;
		}
		if (is_array($value) && package_smoke_lockfile_has_package($value, $package)) {
			return true;
		}
	}
	return false;
}

/**
 * @param array<string,string> $environment
 * @param array<int,string>    $arguments
 * @return array<int,string>
 */
function package_smoke_container_npm_command(
	string $worktree,
	string $cache_directory,
	array $environment,
	array $arguments,
	string $image,
	?string $platform,
	int $user_id,
	int $group_id
): array {
	$command = array('docker', 'run', '--rm');
	if (null !== $platform) {
		$command[] = "--platform={$platform}";
	}
	$command = array_merge(
		$command,
		array(
		"--user={$user_id}:{$group_id}",
		"--volume={$worktree}:/workspace",
		"--volume={$cache_directory}:/tmp/.npm",
		'--workdir=/workspace',
		'--env', 'HOME=/tmp',
		'--env', 'PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true',
		'--env', 'PUPPETEER_SKIP_DOWNLOAD=true',
		)
	);
	foreach ($environment as $name => $value) {
		$command[] = '--env';
		$command[] = "{$name}={$value}";
	}
	$command[] = $image;
	$command[] = 'npm';
	return array_merge($command, $arguments);
}

/**
 * @return array{path:string,temporary:bool}
 */
function package_smoke_prepare_npm_cache(?string $path): array {
	$temporary = null === $path;
	if ($temporary) {
		$path = temporary_worktree('wp-package-smoke-npm-cache');
	} elseif (
		'' === trim($path)
		|| !str_starts_with($path, DIRECTORY_SEPARATOR)
		|| str_contains($path, "\0")
		|| str_contains($path, "\n")
	) {
		throw new InvalidArgumentException('The persistent npm cache path must be an absolute path.');
	}
	if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
		throw new RuntimeException("Unable to create the npm cache: {$path}");
	}
	$real_path = realpath($path);
	if (false === $real_path || !is_writable($real_path)) {
		throw new RuntimeException("The npm cache is not writable: {$path}");
	}
	return array('path' => $real_path, 'temporary' => $temporary);
}

/**
 * @param array{code:int,stdout:string,stderr:string} $result
 */
function package_smoke_known_start_condition(array $result): ?string {
	if (0 === $result['code']) {
		return null;
	}
	$output = $result['stderr'] . "\n" . $result['stdout'];
	if (str_contains($output, 'affected by security advisories')) {
		return 'Composer blocked a pinned development dependency under the current security-advisory policy.';
	}
	return null;
}

/**
 * @param array{code:int,stdout:string,stderr:string} $result
 */
function package_smoke_install_retry_condition(array $result): ?string {
	if (0 === $result['code']) {
		return null;
	}
	$output = $result['stderr'] . "\n" . $result['stdout'];
	if (
		str_contains($output, 'Connection refused')
		&& (
			str_contains($output, "Can't connect to MySQL server")
			|| str_contains($output, 'Database connection error (2002)')
		)
	) {
		return 'MySQL was not ready for the WordPress install.';
	}
	if (
		str_contains($output, 'ENOENT: no such file or directory')
		&& str_contains($output, "rename 'src/wp-config.php' -> 'wp-config.php'")
	) {
		return 'The legacy install did not create wp-config.php on its first attempt.';
	}
	return null;
}

/**
 * @param array{code:int,stdout:string,stderr:string} $result
 */
function package_smoke_e2e_environment_condition(array $result): ?string {
	if (0 === $result['code']) {
		return null;
	}
	$output = $result['stderr'] . "\n" . $result['stdout'];
	if (str_contains($output, "Executable doesn't exist at") || str_contains($output, 'npx playwright install')) {
		return "Playwright's browser is not installed; run `npx playwright install chromium` in the project that installed the --playwright-module package.";
	}
	foreach (
		array(
			'Failed to launch the browser process',
			'Could not find expected browser',
			'Could not find Chrome',
			'Could not find Chromium',
			'Browser was not found at the configured executablePath',
			'The chromium binary is not available',
		) as $needle
	) {
		if (str_contains($output, $needle)) {
			return 'The browser process could not start in the local environment.';
		}
	}
	return null;
}

function package_smoke_artifact_directory(string $report, string $label): string {
	return preg_replace('/\.md$/', '', $report) . '-artifacts/' . $label;
}

function package_smoke_no_core_updates_plugin(): string {
	return "<?php add_filter( 'automatic_updater_disabled', '__return_true' );\n";
}

function package_smoke_disable_core_updates(string $directory): void {
	$plugins = $directory . '/wp-content/mu-plugins';
	if (!is_dir($plugins) && !@mkdir($plugins, 0755, true) && !is_dir($plugins)) {
		throw new RuntimeException('Unable to create the must-use plugin directory for disabling automatic core updates.');
	}
	if (false === @file_put_contents($plugins . '/package-smoke-no-core-updates.php', package_smoke_no_core_updates_plugin())) {
		throw new RuntimeException('Unable to write the must-use plugin that disables automatic core updates.');
	}
}

function package_smoke_source_hashes(string $directory): array {
	$hashes = array();
	foreach (array('wp-includes/version.php', 'wp-settings.php') as $file) {
		$hash = @hash_file('sha256', $directory . '/' . $file);
		$hashes[$file] = false === $hash ? null : $hash;
	}
	return $hashes;
}

function package_smoke_source_change_reason(array $before, array $after): ?string {
	$changed = array();
	$missing = array();
	foreach (array('wp-includes/version.php', 'wp-settings.php') as $file) {
		if (!is_string($before[$file] ?? null) || !is_string($after[$file] ?? null)) {
			$missing[] = basename($file);
		} elseif ($before[$file] !== $after[$file]) {
			$changed[] = basename($file);
		}
	}
	if ($missing) {
		return "The site's WordPress files could not be read (" . implode(', ', $missing) . '); results cannot be verified against the pinned tree.';
	}
	if ($changed) {
		return "The site's WordPress files changed during the run (" . implode(', ', $changed) . '); results are not from the pinned tree.';
	}
	return null;
}

function package_smoke_first_fatal_reason(array $result): string {
	$fatals = $result['new_fatals'] ?? array();
	$fatal = is_array($fatals) ? ($fatals[0] ?? null) : null;
	if (!is_string($fatal)) {
		return '';
	}
	$fatal = substr(trim(preg_split('/\r\n|\r|\n/', $fatal, 2)[0]), 0, 200);
	return '' === $fatal ? '' : 'First logged fatal: ' . $fatal;
}

function package_smoke_preserve_artifacts(string $status, string $source, string $destination): array {
	if ('fail' !== $status) {
		return array();
	}
	$reasons = array();
	$fatal_reason = '';
	try {
		$content = @file_get_contents($source . '/result.json');
		$result = false === $content ? null : json_decode($content, true);
		if (is_array($result)) {
			$fatal_reason = package_smoke_first_fatal_reason($result);
		}
		$entries = @scandir($source);
		if (false === $entries) {
			throw new RuntimeException('Unable to read the functional directory.');
		}
		if (is_link($destination)) {
			throw new RuntimeException('The artifact directory is a symbolic link.');
		}
		if (!is_dir($destination) && !@mkdir($destination, 0700, true) && !is_dir($destination)) {
			throw new RuntimeException('Unable to create the artifact directory.');
		}
		if (!@chmod($destination, 0700)) {
			throw new RuntimeException('Unable to set artifact directory permissions.');
		}
		foreach (scandir($destination) ?: array() as $stale) {
			if ('.' !== $stale && '..' !== $stale && !@unlink($destination . '/' . $stale)) {
				throw new RuntimeException("Unable to clear the earlier {$stale}.");
			}
		}
		foreach ($entries as $entry) {
			$path = $source . '/' . $entry;
			if (('result.json' !== $entry && !str_ends_with($entry, '.png')) || is_link($path) || !is_file($path)) {
				continue;
			}
			if (!@copy($path, $destination . '/' . $entry)) {
				throw new RuntimeException("Unable to copy {$entry}.");
			}
		}
		$reasons[] = "Functional smoke artifacts preserved in {$destination}.";
	} catch (Throwable $error) {
		$reasons[] = "Unable to preserve functional smoke artifacts in {$destination}: " . $error->getMessage();
	}
	if ('' !== $fatal_reason) {
		$reasons[] = $fatal_reason;
	}
	return $reasons;
}

/**
 * @return array{ok:bool,reason:string}
 */
function package_smoke_functional_result(string $path): array {
	if (!is_file($path)) {
		return array('ok' => false, 'reason' => 'The functional smoke did not write result.json.');
	}
	$content = file_get_contents($path);
	if (false === $content) {
		return array('ok' => false, 'reason' => 'The functional smoke result could not be read.');
	}
	try {
		$result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException) {
		return array('ok' => false, 'reason' => 'The functional smoke result was not valid JSON.');
	}
	if (!is_array($result)) {
		return array('ok' => false, 'reason' => 'The functional smoke result was not an object.');
	}
	$problems = array();
	if (true !== ($result['login']['logged_in'] ?? false)) {
		$problems[] = 'admin login failed';
	}
	if (true !== ($result['logout']['confirmed'] ?? false)) {
		$problems[] = 'logout was not confirmed';
	}
	if (!isset($result['flows']) || !is_array($result['flows']) || !$result['flows']) {
		$problems[] = 'no page flows were recorded';
	} else {
		foreach ($result['flows'] as $flow) {
			if (!is_array($flow)) {
				$problems[] = 'a page flow had an invalid result';
				continue;
			}
			$slug = is_string($flow['slug'] ?? null) ? $flow['slug'] : 'unknown page';
			$verdict = $flow['verdict'] ?? null;
			if ('OK' !== $verdict) {
				$problems[] = "{$slug} returned " . (is_string($verdict) ? $verdict : 'an unknown verdict');
			}
			if (0 !== ($flow['console_errors'] ?? 0)) {
				$messages = is_array($flow['console_error_messages'] ?? null)
					? array_values(array_filter($flow['console_error_messages'], 'is_string'))
					: array();
				$detail = $messages ? ': ' . implode(' | ', array_slice($messages, 0, 3)) : '';
				$problems[] = "{$slug} logged console errors{$detail}";
				$script_failures = $flow['script_failures'] ?? array();
				foreach (is_array($script_failures) ? $script_failures : array(null) as $script_failure) {
					$fields = is_array($script_failure) ? array_map(
						static fn(string $key): mixed => $script_failure[$key] ?? null,
						array('url', 'status', 'content_type')
					) : array();
					if (array(true, true, true) !== array_map('is_scalar', $fields)) {
						$problems[] = "{$slug} recorded an invalid script failure";
						continue;
					}
					$problems[] = "{$slug} script response: {$fields[0]} ({$fields[1]} {$fields[2]})";
				}
			}
		}
	}
	if (!empty($result['new_fatals'])) {
		$problems[] = 'debug.log recorded a new PHP fatal';
	}
	if ($problems) {
		return array('ok' => false, 'reason' => implode('; ', array_unique($problems)) . '.');
	}
	return array('ok' => true, 'reason' => 'All functional page flows passed.');
}

function package_smoke_status(array $row): string {
	foreach (array('site_up', 'e2e_smoke') as $field) {
		if ('fail' === ($row[$field] ?? '')) {
			return 'failed';
		}
	}
	foreach (array('site_up', 'e2e_smoke') as $field) {
		if ('env-blocked' === ($row[$field] ?? '')) {
			return 'env-blocked';
		}
	}
	return 'passed';
}

/**
 * @param array<int,array<string,string>> $rows
 * @return array{total:int,ran:int,percentage:int,passed:int,env-blocked:int,failed:int,ran_failed:int}
 */
function package_smoke_coverage_counts(array $rows): array {
	$counts = array(
		'total'       => count($rows),
		'ran'         => 0,
		'percentage'  => 0,
		'passed'      => 0,
		'env-blocked' => 0,
		'failed'      => 0,
		'ran_failed'  => 0,
	);
	foreach ($rows as $row) {
		$status = $row['status'] ?? 'failed';
		if (isset($counts[$status])) {
			++$counts[$status];
		}
		if (
			in_array($row['e2e_smoke'] ?? '', array('pass', 'fail'), true)
			|| in_array($row['locale_check'] ?? '', array('pass', 'fail'), true)
			|| in_array($row['md5_check'] ?? '', array('pass', 'fail', 'n/a'), true)
		) {
			++$counts['ran'];
			if ('failed' === $status) {
				++$counts['ran_failed'];
			}
		}
	}
	if ($counts['total'] > 0) {
		$counts['percentage'] = (int) round(100 * $counts['ran'] / $counts['total']);
	}
	return $counts;
}

/**
 * @return array{start:string,cli:string,clean:string}
 */
function package_smoke_commands(array $scripts): array {
	foreach (array('env:start', 'env:cli') as $required) {
		if (!isset($scripts[$required])) {
			throw new RuntimeException("The branch package.json has no {$required} script.");
		}
	}
	$clean = isset($scripts['env:clean']) ? 'env:clean' : (isset($scripts['env:stop']) ? 'env:stop' : null);
	if (null === $clean) {
		throw new RuntimeException('The branch package.json has no env:clean or env:stop script.');
	}
	return array('start' => 'env:start', 'cli' => 'env:cli', 'clean' => $clean);
}

function package_smoke_env_value(string $worktree, string $name, string $default): string {
	foreach (array('.env', '.env.example') as $file) {
		$path = $worktree . '/' . $file;
		if (!is_file($path)) {
			continue;
		}
		$lines = file($path, FILE_IGNORE_NEW_LINES);
		if (false === $lines) {
			throw new RuntimeException("Unable to read {$file} from the branch.");
		}
		foreach ($lines as $line) {
			if (preg_match('/^' . preg_quote($name, '/') . '=(.*)$/', $line, $match)) {
				$value = trim($match[1], " \t\n\r\0\x0B\"'");
				if ('' !== $value && !str_contains($value, '${')) {
					return $value;
				}
			}
		}
	}
	return $default;
}

function package_smoke_command_detail(string $output, string $worktree, string $checkout): string {
	if (str_contains($output, 'The chromium binary is not available for arm64')) {
		return "Puppeteer's bundled Chromium is unavailable for macOS arm64.";
	}
	if (str_contains($output, 'digital envelope routines::unsupported')) {
		return 'The legacy Webpack build is incompatible with the host Node/OpenSSL runtime.';
	}
	if (str_contains($output, 'affected by security advisories')) {
		return 'Composer blocked a pinned development dependency under the current security-advisory policy.';
	}
	if (
		str_contains($output, 'notsup Not compatible with your version of node/npm')
		&& preg_match('/Required:\s*(\{[^\n]+\}).*Actual:\s*(\{[^\n]+\})/s', $output, $match)
	) {
		return "npm engine mismatch: required {$match[1]}; actual {$match[2]}.";
	}

	$paths        = array($worktree, $checkout);
	$replacements = array('<worktree>', '<checkout>');
	$real_worktree = realpath($worktree);
	if (false !== $real_worktree) {
		$paths[] = $real_worktree;
		$replacements[] = '<worktree>';
	}
	$output = str_replace($paths, $replacements, $output);
	$output = preg_replace('~/Users/[^/\s]+~', '<home>', $output) ?? $output;
	$lines  = array_values(array_filter(array_map('trim', explode("\n", $output))));
	$important = array_values(
		array_filter(
			$lines,
			static fn(string $line): bool => 1 === preg_match(
				'/\b(error|fatal|failed|failure|unsupported|requires|refused|cannot|could not|not found)\b/i',
				$line
			)
		)
	);
	$lines  = array_slice($important ?: $lines, -4);
	$detail = implode(' / ', $lines);
	return strlen($detail) > 500 ? substr($detail, 0, 497) . '...' : $detail;
}

/**
 * @param array<int,array<string,string>> $rows
 */
function package_smoke_render_report(
	string $release,
	array $rows,
	string $generated,
	?int $elapsed_seconds = null
): string {
	$counts = package_smoke_coverage_counts($rows);

	$report  = "# WordPress {$release} package UI smoke\n\n";
	$report .= "Generated: {$generated}\n\n";
	$report .= '| Test | PHP floor | UI runtime | Site up | E2E smoke |' . "\n";
	$report .= '|---|---:|---:|---|---|' . "\n";
	foreach ($rows as $row) {
		if (isset($row['locale']) || isset($row['md5_check'])) {
			continue;
		}
		$values = array();
		foreach (array('test', 'floor', 'runtime', 'site_up', 'e2e_smoke') as $key) {
			$values[] = str_replace('|', '\\|', $row[$key] ?? '—');
		}
		$report .= '| ' . implode(' | ', $values) . ' |' . "\n";
	}

	foreach ($rows as $row) {
		if (isset($row['md5_check'])) {
			$report .= "\n## Package md5\n\n| md5 | Published md5 URL | Published md5 | Result | Reason |\n|---|---|---|---|---|\n";
			$values = array($row['md5'], $row['md5_url'], $row['published_md5'], strtoupper($row['md5_check']), $row['reason']);
			$report .= '| ' . implode(' | ', array_map(static fn(string $value): string => str_replace(array('|', "\r", "\n"), array('\\|', ' ', ' '), $value), $values)) . " |\n";
		}
	}

	$locale_rows = array_filter($rows, static fn(array $row): bool => isset($row['locale']));
	if ($locale_rows) {
		$report .= "\n## Localized packages\n\n| Locale | URL | HTTP result | Version read | Local package read | Result | Reason |\n|---|---|---|---|---|---|---|\n";
		foreach ($locale_rows as $row) {
			$values = array($row['locale'], $row['url'], $row['http'], $row['version'], $row['local_package'], strtoupper($row['locale_check']), $row['reason']);
			$report .= '| ' . implode(' | ', array_map(static fn(string $value): string => str_replace(array('|', "\r", "\n"), array('\\|', ' ', ' '), $value), $values)) . " |\n";
		}
	}

	$report .= "\n## Summary\n\n";
	$report .= "- Ran: {$counts['ran']} of {$counts['total']} tests ({$counts['percentage']}%)\n";
	$report .= "- Failed: {$counts['ran_failed']} of {$counts['ran']} tests that ran\n";
	$report .= "- Passed: {$counts['passed']}\n";
	$report .= "- Environment blocked: {$counts['env-blocked']}\n";
	$report .= "- Total failures: {$counts['failed']}\n";
	if (null !== $elapsed_seconds) {
		$report .= '- Wall-clock time: ' . package_smoke_format_duration($elapsed_seconds) . "\n";
	}

	$blocked = array_values(
		array_filter(
			$rows,
			static fn(array $row): bool => in_array(
				$row['status'] ?? '',
				array('failed', 'env-blocked'),
				true
			)
		)
	);
	$report .= "\n## Tests not fully passed\n\n";
	if (!$blocked) {
		$report .= "None.\n";
	} else {
		foreach ($blocked as $row) {
			$report .= "- {$row['test']}: {$row['reason']}\n";
		}
	}

	$notes = array_values(
		array_filter(
			$rows,
			static fn(array $row): bool => in_array(
				$row['status'] ?? '',
				array('passed'),
				true
			)
				&& 'All required checks passed.' !== ($row['reason'] ?? '')
		)
	);
	$report .= "\n## Environment notes\n\n";
	if (!$notes) {
		$report .= "None.\n";
	} else {
		foreach ($notes as $row) {
			$report .= "- {$row['test']}: {$row['reason']}\n";
		}
	}

	return $report;
}

function package_smoke_format_duration(int $seconds): string {
	if ($seconds < 0) {
		throw new InvalidArgumentException('Elapsed time cannot be negative.');
	}
	$hours   = intdiv($seconds, 3600);
	$minutes = intdiv($seconds % 3600, 60);
	$seconds = $seconds % 60;
	$parts   = array();
	if (0 < $hours) {
		$parts[] = "{$hours}h";
	}
	if (0 < $minutes || 0 < $hours) {
		$parts[] = "{$minutes}m";
	}
	$parts[] = "{$seconds}s";
	return implode(' ', $parts);
}

/**
 * @return array{code:int,stdout:string,stderr:string}
 */
function package_smoke_npm(
	string $worktree,
	array $environment,
	array $arguments,
	bool $allow_failure = true
): array {
	$command = array('/usr/bin/env');
	foreach ($environment as $name => $value) {
		$command[] = "{$name}={$value}";
	}
	$command[] = 'npm';
	$command   = array_merge($command, $arguments);
	return run_command($command, $worktree, $allow_failure);
}

/**
 * Point host npm at --npm-cache. Without it, keep the host's own cache: the
 * temporary cache starts empty, so every run would download every package.
 *
 * @param array<string,string>             $environment
 * @param array{path:string,temporary:bool} $cache
 * @return array<string,string>
 */
function package_smoke_host_npm_environment(array $environment, array $cache): array {
	return $cache['temporary'] ? $environment : $environment + array('npm_config_cache' => $cache['path']);
}

/**
 * @param array{path:string,temporary:bool}         $cache
 * @param array<string,string>                      $environment
 * @param array<int,string>                         $arguments
 * @param array{type:string,image:?string,platform:?string} $toolchain
 * @return array{code:int,stdout:string,stderr:string}
 */
function package_smoke_build_npm(
	string $worktree,
	array $cache,
	array $environment,
	array $arguments,
	array $toolchain
): array {
	if ('container' !== $toolchain['type']) {
		return package_smoke_npm($worktree, package_smoke_host_npm_environment($environment, $cache), $arguments);
	}
	$cache_directory = $cache['path'];
	$user_id = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
	$group_id = function_exists('posix_getegid') ? posix_getegid() : getmygid();
	return run_command(
		package_smoke_container_npm_command(
			$worktree,
			$cache_directory,
			$environment,
			$arguments,
			$toolchain['image'] ?? throw new RuntimeException('The container toolchain has no image.'),
			$toolchain['platform'],
			$user_id,
			$group_id
		),
		$worktree,
		true
	);
}

function package_smoke_detail(array $result, string $worktree, string $checkout): string {
	$output = trim($result['stderr'] . "\n" . $result['stdout']);
	if ('' === $output) {
		return "command exited {$result['code']} without output";
	}
	return package_smoke_command_detail($output, $worktree, $checkout);
}

/**
 * @return array{ok:bool,reason:string}
 */
function package_smoke_http(string $url, array $needles, int $attempts = 30): array {
	$context = stream_context_create(
		array(
			'http' => array(
				'follow_location' => 1,
				'ignore_errors'   => true,
				'max_redirects'   => 5,
				'timeout'         => 5,
				'user_agent'      => 'WordPress package smoke',
			),
		)
	);
	$last_reason = 'no response';
	for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
		$body = @file_get_contents($url, false, $context);
		$headers = $http_response_header ?? array();
		$status = null;
		foreach ($headers as $header) {
			if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
				$status = (int) $match[1];
			}
		}
		if (200 === $status && false !== $body) {
			$missing = array_values(
				array_filter(
					$needles,
					static fn(string $needle): bool => !str_contains($body, $needle)
				)
			);
			if (!$missing) {
				return array('ok' => true, 'reason' => 'HTTP 200 with expected markup');
			}
			$last_reason = 'HTTP 200 missing expected markup: ' . implode(', ', $missing);
		} else {
			$last_reason = null === $status ? 'no HTTP status' : "HTTP {$status}";
		}
		if ($attempt < $attempts) {
			sleep(2);
		}
	}
	return array('ok' => false, 'reason' => $last_reason);
}

function package_smoke_write_report(string $path, string $report): void {
	$directory = dirname($path);
	if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
		throw new RuntimeException("Unable to create report directory: {$directory}");
	}
	if (false === file_put_contents($path, $report)) {
		throw new RuntimeException("Unable to write report: {$path}");
	}
}

function package_smoke_functional(
	string $worktree,
	string $repo,
	string $port,
	string $wp_dir,
	string $playwright_module,
	string $functional_runner,
	string $functional_directory,
	string $artifact_directory,
	array &$row,
	array &$reasons
): void {
	$functional = run_command(
		array(
			'/usr/bin/env',
			"SMOKE_BASE=http://localhost:{$port}",
			'SMOKE_USER=admin', 'SMOKE_PASS=password',
			"PW_PATH={$playwright_module}",
			"DEBUG_LOG={$worktree}/{$wp_dir}/wp-content/debug.log",
			'node', $functional_runner, $functional_directory,
		),
		$worktree,
		true
	);
	if (0 !== $functional['code']) {
		$functional_environment_condition = package_smoke_e2e_environment_condition($functional);
		if (null !== $functional_environment_condition) {
			$row['e2e_smoke'] = 'env-blocked';
			$reasons[] = $functional_environment_condition;
		} else {
			$row['e2e_smoke'] = 'fail';
			$reasons[] = 'Functional smoke: ' . package_smoke_detail($functional, $worktree, $repo);
		}
	} else {
		$functional_result = package_smoke_functional_result($functional_directory . '/result.json');
		$row['e2e_smoke'] = $functional_result['ok'] ? 'pass' : 'fail';
		if (!$functional_result['ok']) {
			$reasons[] = 'Functional smoke: ' . $functional_result['reason'];
		}
	}
	$reasons = array_merge($reasons, package_smoke_preserve_artifacts($row['e2e_smoke'], $functional_directory, $artifact_directory));
}

function package_smoke_verify_source(string $directory, array $before, array &$row, array &$reasons): void {
	$reason = package_smoke_source_change_reason($before, package_smoke_source_hashes($directory));
	if (null === $reason) {
		return;
	}
	if (in_array($row['e2e_smoke'], array('pass', 'fail'), true)) {
		$row['e2e_smoke'] = 'env-blocked';
	}
	$row['site_up'] = 'env-blocked';
	$reasons[] = $reason;
}

function package_smoke_package_steps_run(
	string $worktree,
	string $repo,
	array $environment,
	array $commands,
	array $steps,
	array &$reasons
): ?string {
	foreach ($steps as $step) {
		$label = implode(' ', array_slice($step['arguments'], 0, 2));
		for ($attempt = 1; ; ++$attempt) {
			$result = package_smoke_npm($worktree, $environment, array_merge(
				array('--silent', 'run', $commands['cli'], '--'),
				$step['arguments'],
				array('--path=/var/www/package')
			));
			$retry_condition = $step['retry'] && 24 > $attempt ? package_smoke_install_retry_condition($result) : null;
			if (null === $retry_condition) {
				break;
			}
			sleep(5);
		}
		if (0 !== $result['code']) {
			$reasons[] = "{$label}: " . package_smoke_detail($result, $worktree, $repo);
			return $step['blocked'] ? 'env-blocked' : 'fail';
		}
		if (1 < $attempt) {
			$reasons[] = "{$label} succeeded after retry: MySQL was not ready for the WordPress install.";
		}
		if (null !== $step['expects'] && !package_smoke_version_reported($result['stdout'], $step['expects'])) {
			$reasons[] = "{$label} did not report {$step['expects']}.";
			return 'fail';
		}
	}
	return null;
}

function package_smoke_package_run(
	string $repo,
	array $options,
	string $playwright_module,
	string $functional_runner
): array {
	$download_directory = temporary_worktree('wp-package-ui-download');
	$worktree = null;
	$commands = null;
	$environment = array();
	$npm_cache_config = null;
	$started = false;
	$rows = array();
	try {
		$download = $download_directory . '/wordpress-package.zip';
		copy_package($options['package'], $download);
		$version = read_package_version($download);
		$md5 = md5_file($download);
		echo "PACKAGE_MD5={$version} {$md5}\n";
		$md5_row = package_smoke_md5_row(
			$version,
			$md5,
			1 === preg_match('~^https?://~i', $options['package']) ? $options['package'] : null,
			$download . '.md5'
		);
		echo 'Package md5 check: ' . strtoupper($md5_row['md5_check']) . ". {$md5_row['reason']}\n";
		$previous = $options['previous'] ?? package_smoke_previous_url($version);
		$target = release_line($version);
		fetch_target($repo, $target);
		fetch_target($repo, 'trunk');
		$php = package_smoke_php_target(
			package_smoke_php_versions($repo, 'trunk'),
			package_smoke_php_floors($repo, 'trunk'),
			$target
		);
		$runtime = $php['versions'][count($php['versions']) - 1];
		$worktree = temporary_worktree("wp-package-ui-{$version}");
		// The web server container runs as its own user and must read the site,
		// including the files that git and the zip extraction create below.
		umask(022);
		if (!chmod($worktree, 0755)) {
			throw new RuntimeException("Unable to make {$worktree} readable for the web server container.");
		}
		run_command(array('git', 'worktree', 'add', '--quiet', '--detach', $worktree, "refs/remotes/origin/{$target}"), $repo);
		$package = $worktree . '/wordpress-package.zip';
		$previous_package = $worktree . '/wordpress-previous.zip';
		copy_package($download, $package);
		copy_package($previous, $previous_package);
		$previous_version = read_package_version($previous_package);
		package_smoke_require_older($previous_version, $version);
		foreach (array('fresh install', "upgrade from {$previous_version}") as $phase) {
			$rows[] = array(
				'test' => "WordPress {$version} package: {$phase}",
				'floor' => $php['floor'],
				'runtime' => $runtime,
				'site_up' => 'env-blocked',
				'e2e_smoke' => 'skip',
				'status' => 'env-blocked',
				'reason' => 'Test did not complete.',
			);
		}
		$commands = package_smoke_commands(package_smoke_package_scripts($worktree));
		$toolchain = package_smoke_build_toolchain($worktree);
		$port = $options['port'] ?? package_smoke_env_value($worktree, 'LOCAL_PORT', '8889');
		if (1 !== preg_match('/^\d{2,5}$/', $port)) {
			throw new RuntimeException("The branch declares an invalid LOCAL_PORT: {$port}.");
		}
		$environment = array(
			'LOCAL_PHP' => "{$runtime}-fpm",
			'LOCAL_PORT' => $port,
			'LOCAL_DIR' => 'package',
			'LOCAL_DB_TYPE' => package_smoke_env_value($worktree, 'LOCAL_DB_TYPE', 'mysql'),
			'LOCAL_DB_VERSION' => package_smoke_env_value($worktree, 'LOCAL_DB_VERSION', 'latest'),
		);
		$npm_cache_config = package_smoke_prepare_npm_cache($options['npm-cache'] ?? null);
		$dependencies = package_smoke_build_npm($worktree, $npm_cache_config, array(), array('ci'), $toolchain);
		if (0 !== $dependencies['code']) {
			$reason = 'npm ci: ' . package_smoke_detail($dependencies, $worktree, $repo);
			throw new RuntimeException('package-environment-blocked');
		}
		$started = true;
		$start = package_smoke_npm($worktree, $environment, array('run', $commands['start']));
		$start_condition = package_smoke_known_start_condition($start);
		if (0 !== $start['code'] && null === $start_condition) {
			$reason = "{$commands['start']}: " . package_smoke_detail($start, $worktree, $repo);
			throw new RuntimeException('package-environment-blocked');
		}
		foreach ($rows as $index => &$row) {
			echo "Testing {$row['test']} at UI PHP {$runtime}.\n";
			$reasons = null === $start_condition ? array() : array($start_condition);
			$source_hashes = null;
			try {
				package_smoke_extract_package(0 === $index ? $package : $previous_package, $worktree);
				package_smoke_disable_core_updates($worktree . '/package');
				$verdict = package_smoke_package_steps_run(
					$worktree, $repo, $environment, $commands,
					package_smoke_package_steps($port, 0 === $index ? $version : $previous_version, 0 === $index ? null : $version),
					$reasons
				);
				if (null !== $verdict) {
					$row['site_up'] = $verdict;
					continue;
				}
				$source_hashes = package_smoke_source_hashes($worktree . '/package');
				$site = package_smoke_http("http://localhost:{$port}/", array('<html', 'wp-content/'));
				$admin = package_smoke_http("http://localhost:{$port}/wp-admin/", array('id="loginform"', 'name="log"'));
				if (!$site['ok'] || !$admin['ok']) {
					$unreachable = str_contains($site['reason'] . $admin['reason'], 'no HTTP status');
					$row['site_up'] = $unreachable ? 'env-blocked' : 'fail';
					$reasons[] = "site: {$site['reason']}; wp-admin: {$admin['reason']}";
					continue;
				}
				$row['site_up'] = 'pass';
				package_smoke_functional(
					$worktree, $repo, $port, 'package', $playwright_module, $functional_runner,
					$worktree . '/.package-functional-' . $index,
					package_smoke_artifact_directory($options['output'], $version . '-' . $index), $row, $reasons
				);
			} catch (Throwable $error) {
				$row['site_up'] = 'env-blocked';
				if ('skip' === $row['e2e_smoke']) {
					$row['e2e_smoke'] = 'env-blocked';
				}
				$reasons[] = $error->getMessage();
			} finally {
				if (null !== $source_hashes) {
					package_smoke_verify_source($worktree . '/package', $source_hashes, $row, $reasons);
				}
				$row['reason'] = $reasons ? implode(' ', $reasons) : 'All required checks passed.';
			}
		}
		unset($row);
	} catch (RuntimeException $error) {
		if (!$rows) {
			throw $error;
		}
		if ('package-environment-blocked' !== $error->getMessage()) {
			$reason = $error->getMessage();
		}
		foreach ($rows as &$row) {
			if ('pass' !== $row['site_up']) {
				$row['reason'] = $reason;
			}
		}
		unset($row);
	} finally {
		try {
			if (null !== $worktree && null !== $commands) {
				$cleanup = package_smoke_npm($worktree, $environment, array('run', $commands['clean']));
				if (0 !== $cleanup['code'] && $started) {
					foreach ($rows as &$row) {
						$row['site_up'] = 'fail';
						$row['reason'] .= ' Environment cleanup failed: ' . package_smoke_detail($cleanup, $worktree, $repo);
					}
					unset($row);
				}
			}
		} finally {
			try {
				if (null !== $worktree) {
					remove_worktree($repo, $worktree);
				}
			} finally {
				remove_temporary_directory($download_directory, 'wp-package-ui-download-');
				if (null !== $npm_cache_config && $npm_cache_config['temporary']) {
					remove_temporary_directory($npm_cache_config['path'], 'wp-package-smoke-npm-cache-');
				}
			}
		}
	}
	foreach ($rows as &$row) {
		$row['status'] = package_smoke_status($row);
	}
	unset($row);
	$rows[] = $md5_row;
	$locales = package_smoke_locales($options);
	if ($locales) {
		$locale_directory = temporary_worktree('wp-package-ui-download');
		try {
			foreach ($locales as $locale) {
				$rows[] = package_smoke_locale_row($locale, $version, $locale_directory . '/' . $locale . '.zip');
			}
		} finally {
			remove_temporary_directory($locale_directory, 'wp-package-ui-download-');
		}
	}
	return array('version' => $version, 'rows' => $rows);
}

function package_smoke_usage(string $script): never {
	fail(
		"Usage: {$script} --repository=<owner/name> --checkout=<path> "
		. '--package=<zip URL or path> --playwright-module=<path> --output=<report.md> '
		. '[--previous=<zip URL or path>] [--locales=<codes>] [--npm-cache=<path>] [--port=<port>] [--verbose]'
	);
}

function package_smoke_help(string $script): string {
	return <<<HELP
Usage: {$script} --repository=<owner/name> --checkout=<path>
       --package=<zip URL or path> --playwright-module=<path> --output=<report.md>
       [--previous=<zip URL or path>] [--locales=<codes>] [--npm-cache=<path>] [--port=<port>] [--verbose]

Test one built WordPress zip. Uses the release line's develop Docker
environment, newest supported PHP, functional smoke, and Markdown report. Runs
npm ci without a build, then installs through the branch's env:cli script with
an explicit --path, so every release line installs the zip itself. Starts one
environment, smokes a fresh package install, resets to the previous release,
upgrades with wp core update, and smokes again. Writes two rows per package,
then cleans the environment and worktree. A must-use plugin disables automatic
core updates. Reports env-blocked if served files change during a run.
Prints PACKAGE_MD5=<version> <md5> and fails if a URL package's md5 differs
from <URL>.md5.

Required options:
  --repository=<owner/name>  Expected origin repository, such as WordPress/wordpress-develop.
  --checkout=<path>          Clean checkout of that repository.
  --package=<zip URL or path> Test a built zip; its version.php supplies the version.
                             Stable release zips only; beta and RC zips are refused.
  --playwright-module=<path> Standalone Playwright module outside the checkout.
  --output=<report.md>       Markdown report. A failed walkthrough keeps result.json and
                             screenshots in <report without .md>-artifacts/<version>-<row>/.

Optional:
  --previous=<zip URL or path> Override the previous public release zip.
                             The default subtracts one patch: patch 1 uses wordpress-X.Y.zip;
                             patch 0 requires --previous. The previous release must be older
                             than the package. The package's own URL is never guessed.
  --locales=<codes>          Check localized downloads for matching version and locale.
  --npm-cache=<path>         Reuse a persistent npm download cache across runs.
  --port=<port>              Override the branch's localhost port.
  --verbose                  Log Git checkout and worktree steps to stderr.
  --help                     Show this help.

Exit codes:
  0  Every row passed.
  1  Operational failure before or outside a test.
  2  One or more rows failed or were blocked by the environment.
HELP;
}

function main(array $argv): int {
	$options = cli_options($argv, array(
		'repository:', 'checkout:', 'package:', 'locales:', 'previous:', 'playwright-module:', 'output:',
		'npm-cache:', 'port:', 'verbose', 'help',
	));
	if (isset($options['help'])) {
		echo package_smoke_help($argv[0]);
		return 0;
	}
	try {
		package_smoke_locales($options);
	} catch (Throwable $error) {
		fail($error->getMessage());
	}
	foreach (array('repository', 'checkout', 'package', 'playwright-module', 'output') as $required) {
		if (!isset($options[$required]) || !is_string($options[$required]) || '' === trim($options[$required])) {
			package_smoke_usage($argv[0]);
		}
	}
	foreach (array('npm-cache', 'previous') as $name) {
		if (isset($options[$name]) && (!is_string($options[$name]) || '' === trim($options[$name]))) {
			package_smoke_usage($argv[0]);
		}
	}
	if (
		isset($options['port'])
		&& (
			!is_string($options['port'])
			|| 1 !== preg_match('/^\d{2,5}$/', $options['port'])
			|| 0 >= (int) $options['port']
			|| 65535 < (int) $options['port']
		)
	) {
		package_smoke_usage($argv[0]);
	}
	set_verbose(isset($options['verbose']));

	try {
		if (!putenv('GIT_NO_REPLACE_OBJECTS=1')) {
			throw new RuntimeException('Unable to disable Git replacement objects.');
		}
		$run_started_at = hrtime(true);
		$repo = require_checkout($options['checkout']);
		require_origin_matches($repo, $options['repository']);
		$dirty = run_command(array('git', 'status', '--porcelain=v1'), $repo);
		if ('' !== trim($dirty['stdout'])) {
			throw new RuntimeException('The WordPress checkout must be clean before UI smoke testing.');
		}
		$playwright_module = realpath($options['playwright-module']);
		if (false === $playwright_module || !is_dir($playwright_module) || !is_file($playwright_module . '/package.json')) {
			throw new InvalidArgumentException("Standalone Playwright module is missing: {$options['playwright-module']}");
		}
		$functional_runner = package_smoke_harness_path();
		if (!is_file($functional_runner)) {
			throw new RuntimeException('The functional smoke harness is missing from the tool.');
		}
		$result = package_smoke_package_run($repo, $options, $playwright_module, $functional_runner);
		$generated = (new DateTimeImmutable('now', new DateTimeZone('America/Phoenix')))->format('Y-m-d H:i T');
		$elapsed_seconds = (int) round((hrtime(true) - $run_started_at) / 1_000_000_000);
		package_smoke_write_report($options['output'], package_smoke_render_report(
			$result['version'], $result['rows'], $generated, $elapsed_seconds
		));
		$counts = package_smoke_coverage_counts($result['rows']);
		echo "Summary: ran {$counts['ran']} of {$counts['total']} package phases; passed {$counts['passed']}, "
			. "env-blocked {$counts['env-blocked']}, total failures {$counts['failed']}.\n";
		echo "Wrote {$options['output']}.\n";
		return $counts['env-blocked'] || $counts['failed'] ? 2 : 0;
	} catch (Throwable $error) {
		fail($error->getMessage());
	}
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	exit(main($argv));
}
