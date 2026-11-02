#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for package-smoke.php. No network, no subprocesses. */

require dirname(__DIR__) . '/package-smoke.php';

$failures   = array();
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void {
	++$GLOBALS['assertions'];
	if ($expected !== $actual) {
		$GLOBALS['failures'][] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
	}
}

function error_of(callable $callback): ?Throwable {
	try {
		$callback();
	} catch (Throwable $error) {
		return $error;
	}
	return null;
}

function check_throws(string $label, callable $callback): void {
	++$GLOBALS['assertions'];
	if (null === error_of($callback)) {
		$GLOBALS['failures'][] = "{$label}: expected an exception";
	}
}

function temporary_path(string $name): string {
	return sys_get_temp_dir() . '/package-smoke-' . $name . '-' . bin2hex(random_bytes(4)) . '.zip';
}

check('Package version comes from the assignment', '6.8.3', package_smoke_package_version("<?php\n\$wp_version = '6.8.3';\n"));
check('Initial public release has no patch suffix', '6.8', package_smoke_package_version("<?php\n\$wp_version = '6.8';\n"));
check_throws('Missing package version is rejected', static fn() => package_smoke_package_version('<?php'));
foreach (array('6.8-beta3', '6.8-RC1', '6.8.3-beta1') as $prerelease) {
	check("Pre-release {$prerelease} is named", "The package version {$prerelease} is a pre-release; package mode tests stable release zips only.", error_of(static fn() => package_smoke_package_version("<?php\n\$wp_version = '{$prerelease}';\n"))?->getMessage());
}
check('Unquoted junk keeps the generic message', 'The package version.php has no valid $wp_version assignment.', error_of(static fn() => package_smoke_package_version("<?php\n\$wp_version = 'next';\n"))?->getMessage());

check('Previous patch URL', 'https://wordpress.org/wordpress-6.8.2.zip', package_smoke_previous_url('6.8.3'));
check('Patch one uses the initial public release URL', 'https://wordpress.org/wordpress-6.8.zip', package_smoke_previous_url('6.8.1'));
check_throws('Patch zero requires an explicit previous zip', static fn() => package_smoke_previous_url('6.8.0'));
check_throws('Initial release requires an explicit previous zip', static fn() => package_smoke_previous_url('6.8'));
check_throws('Malformed version format is rejected', static fn() => package_smoke_previous_url('6.8.3.1'));

check('Reported version is matched per line despite runtime notices', true, package_smoke_version_reported("\nDeprecated: notice\n\n6.8.3\n", '6.8.3'));
check('Reported version tolerates surrounding whitespace', true, package_smoke_version_reported("  6.8.3  \n", '6.8.3'));
check('Reported version must match a whole line', false, package_smoke_version_reported("6.8.3-src\n", '6.8.3'));
check('Empty version output is not a match', false, package_smoke_version_reported('', '6.8.3'));

check('Older previous release is accepted', null, package_smoke_require_older('6.8.2', '6.8.3'));
check('Initial release counts as patch zero when compared', null, package_smoke_require_older('6.8', '6.8.1'));
check_throws('Same release is not an upgrade', static fn() => package_smoke_require_older('6.8.3', '6.8.3'));
check_throws('Initial release equals its patch-zero spelling', static fn() => package_smoke_require_older('6.8.0', '6.8'));
check_throws('Newer previous release is rejected', static fn() => package_smoke_require_older('6.9', '6.8.3'));

$fresh_steps = package_smoke_package_steps('8890', '6.8.3', null);
check('Fresh install ends by expecting the package version', array('arguments' => array('core', 'version'), 'expects' => '6.8.3', 'blocked' => false, 'retry' => false), end($fresh_steps));
check('Fresh install never runs a core update', false, in_array(array('core', 'update', '/var/www/wordpress-package.zip'), array_column($fresh_steps, 'arguments'), true));
check('Only the database connection step retries', array(0), array_keys(array_filter(array_column($fresh_steps, 'retry'))));
check('Core install failures are package failures', false, $fresh_steps[5]['blocked']);
check('Install targets the requested port', '--url=http://localhost:8890', $fresh_steps[5]['arguments'][2]);
$upgrade_steps = package_smoke_package_steps('8890', '6.8.2', '6.8.3');
check('Upgrade verifies the previous version before updating', '6.8.2', $upgrade_steps[6]['expects']);
check('Upgrade updates from the package zip then verifies its version', array(
	array('config', 'set', 'FS_METHOD', 'direct', '--type=constant'),
	array('core', 'update', '/var/www/wordpress-package.zip'),
	array('core', 'update-db'),
	array('core', 'version'),
), array_column(array_slice($upgrade_steps, 7), 'arguments'));
check('Upgrade ends by expecting the package version', '6.8.3', end($upgrade_steps)['expects']);

check('Single wordpress root is accepted', null, package_smoke_package_layout(array('wordpress/', 'wordpress/index.php', 'wordpress/wp-includes/version.php')));
check_throws('Flat zip is rejected', static fn() => package_smoke_package_layout(array('index.php', 'wp-includes/version.php')));
check_throws('Two zip roots are rejected', static fn() => package_smoke_package_layout(array('wordpress/wp-includes/version.php', 'other/index.php')));
check_throws('Missing version file is rejected', static fn() => package_smoke_package_layout(array('wordpress/index.php')));
check_throws('Dot-segment zip path is rejected', static fn() => package_smoke_package_layout(array('wordpress/./version.php', 'wordpress/wp-includes/version.php')));
check_throws('Zip traversal is rejected', static fn() => package_smoke_package_layout(array('wordpress/wp-includes/version.php', 'wordpress/../../outside.php')));
check_throws('Backslash zip paths are rejected', static fn() => package_smoke_package_layout(array('wordpress/wp-includes/version.php', 'wordpress/..\\outside.php')));

$package_rows = array(
	array('branch' => 'WordPress 6.8.3 package: fresh install', 'fix_smoke' => 'skip', 'e2e_smoke' => 'pass', 'status' => 'passed', 'reason' => 'All required checks passed.'),
	array('branch' => 'WordPress 6.8.3 package: upgrade from 6.8.2', 'fix_smoke' => 'skip', 'e2e_smoke' => 'fail', 'status' => 'failed', 'reason' => 'Page flow failed.'),
	array('fix_smoke' => 'pass', 'e2e_smoke' => 'pass', 'status' => 'passed', 'reason' => 'All required checks passed.'),
	array('fix_smoke' => 'skip', 'e2e_smoke' => 'env-blocked', 'status' => 'env-blocked'),
);
check('E2E verdicts count once as ran; blocks do not', array(
	'total' => 4, 'ran' => 3, 'percentage' => 75, 'passed' => 2, 'env-blocked' => 1, 'failed' => 1, 'ran_failed' => 1,
), package_smoke_coverage_counts($package_rows));
$package_report = package_smoke_render_report('6.8.3', '', array_slice($package_rows, 0, 2), 'test run');
check('Package report title', true, str_starts_with($package_report, '# WordPress 6.8.3 package UI smoke'));
check('Package report allows an empty fix', true, str_contains($package_report, 'Fix: none (package mode)'));
check('Package report lists the failed row', true, str_contains($package_report, '- WordPress 6.8.3 package: upgrade from 6.8.2: Page flow failed.'));

check('Row with a failed check fails', 'failed', package_smoke_status(array('site_up' => 'pass', 'e2e_smoke' => 'fail')));
check('Blocked row without a failure is env-blocked', 'env-blocked', package_smoke_status(array('site_up' => 'env-blocked', 'e2e_smoke' => 'skip')));
check('Skipped build and fix smoke still pass', 'passed', package_smoke_status(array('build' => 'skip', 'site_up' => 'pass', 'fix_smoke' => 'skip', 'e2e_smoke' => 'pass')));

$copy_retry_destination = temporary_path('copy-retry');
$copy_retry_error = error_of(static fn() => package_smoke_copy_package('http://127.0.0.1:1/wordpress-6.8.3.zip', $copy_retry_destination, 2, 0));
if (is_file($copy_retry_destination)) {
	unlink($copy_retry_destination);
}
check('An unreachable package URL is retried before failing', true, $copy_retry_error instanceof RuntimeException);
check('The retry failure reports the attempt count', true, str_starts_with($copy_retry_error?->getMessage() ?? '', 'Unable to copy the package zip after 2 attempt(s):'));
check_throws('A missing local package source is rejected', static fn() => package_smoke_copy_package(temporary_path('missing'), temporary_path('copy-missing'), 3, 0));
check_throws('At least one copy attempt is required', static fn() => package_smoke_copy_package('http://127.0.0.1:1/x.zip', temporary_path('copy-zero'), 0, 0));

check('Locale list is trimmed and deduplicated', array('it_IT', 'fr_FR'), package_smoke_locales(array('locales' => 'it_IT, fr_FR,it_IT')));
check('No --locales means no locale rows', array(), package_smoke_locales(array()));
check_throws('Empty locale codes are refused', static fn() => package_smoke_locales(array('locales' => 'it_IT,')));
check_throws('en_US is refused', static fn() => package_smoke_locales(array('locales' => 'it_IT,en_US')));

$locale_downloads = array();
$locale_download  = static function (string $url, string $destination) use (&$locale_downloads): void {
	$locale_downloads[] = array($url, $destination);
};
$locale_read = static fn(string $path): array => package_smoke_package_details("<?php\n\$wp_version = '6.8.3';\n\$wp_local_package = 'pt_BR';\n");
$locale_pass = package_smoke_locale_row('pt_BR', '6.8.3', 'locale.zip', $locale_download, $locale_read);
check('Locale version and local package match passes', 'passed', $locale_pass['status']);
check('Locale download uses the exact built URL', array(array('https://downloads.wordpress.org/release/pt_BR/wordpress-6.8.3.zip', 'locale.zip')), $locale_downloads);
$locale_404 = package_smoke_locale_row('pt_BR', '6.8.3', 'locale.zip',
	static function (): void {
		throw new RuntimeException('Unable to copy the package zip after 3 attempt(s): HTTP/1.1 404 Not Found');
	}, $locale_read);
check('Locale download 404 after retries fails', 'failed', $locale_404['status']);
check('Locale download failure retains HTTP status', true, str_contains($locale_404['http'], '404'));
$locale_mismatch = package_smoke_locale_row('pt_BR', '6.8.3', 'locale.zip', $locale_download, static fn(): array => array('version' => '6.8.2', 'local_package' => 'pt_BR'));
check('Locale version mismatch fails', 'failed', $locale_mismatch['status']);
check('Locale version mismatch preserves the version read', '6.8.2', $locale_mismatch['version']);
check('Locale mismatch explains expected and actual versions', 'Version mismatch: expected 6.8.3, read 6.8.2.', $locale_mismatch['reason']);
$locale_english = package_smoke_locale_row('pt_BR', '6.8.3', 'locale.zip', $locale_download,
	static fn(): array => package_smoke_package_details("<?php\n\$wp_version = '6.8.3';\n"));
check('English zip is not a localized build', 'failed', $locale_english['status']);
check('English zip explains missing local package', 'Missing $wp_local_package: not a localized build.', $locale_english['reason']);
$locale_wrong = package_smoke_locale_row('pt_BR', '6.8.3', 'locale.zip', $locale_download,
	static fn(): array => package_smoke_package_details("<?php\n\$wp_version = '6.8.3';\n\$wp_local_package = \"de_DE\";\n"));
check('Wrong local package fails', 'failed', $locale_wrong['status']);
check('Wrong local package preserves the locale read', 'de_DE', $locale_wrong['local_package']);
check('Wrong local package explains expected and actual locales', 'Locale mismatch: expected pt_BR, read de_DE.', $locale_wrong['reason']);

$locale_rows = array_merge(array_slice($package_rows, 0, 2), array($locale_pass, $locale_english, $locale_404, $locale_mismatch));
check('Locale outcomes count alongside English phases', array('total' => 6, 'ran' => 6, 'percentage' => 100, 'passed' => 2, 'env-blocked' => 0, 'failed' => 4, 'ran_failed' => 4), package_smoke_coverage_counts($locale_rows));
$locale_report = package_smoke_render_report('6.8.3', '', $locale_rows, 'test run');
check('Locale report follows the English phases', true, strpos($locale_report, 'Localized packages') > strpos($locale_report, 'upgrade from 6.8.2'));
check('Locale report includes URL HTTP version and PASS', true, str_contains($locale_report, '| pt_BR | https://downloads.wordpress.org/release/pt_BR/wordpress-6.8.3.zip | HTTP download succeeded | 6.8.3 | pt_BR | PASS |'));
check('Locale report includes missing local package and FAIL', true, str_contains($locale_report, '| HTTP download succeeded | 6.8.3 | missing | FAIL | Missing $wp_local_package: not a localized build.'));

check('Canonical https remote normalizes', 'WordPress/wordpress-develop', normalize_github_remote('https://github.com/WordPress/wordpress-develop.git'));
check('scp-style GitHub remote normalizes', 'WordPress/wordpress-develop', normalize_github_remote('git@github.com:WordPress/wordpress-develop.git'));
check('Local mirror path falls back to its last two segments', 'WordPress/wordpress-develop', normalize_github_remote('/srv/mirrors/WordPress/wordpress-develop.git'));
check('Other hosts are refused', null, normalize_github_remote('https://evil.example/WordPress/wordpress-develop.git'));
check('scp-style host without user is refused', null, normalize_github_remote('evil.invalid:any/WordPress/wordpress-develop.git'));
check('A token in a query string is refused', null, normalize_github_remote('https://github.com/WordPress/wordpress-develop.git?access_token=TOKEN'));

check('Parser accepts both value forms', array('package' => 'a.zip', 'output' => 'r.md', 'verbose' => false), parse_cli_options(array('x', '--package=a.zip', '--output', 'r.md', '--verbose'), array('package:', 'output:', 'verbose')));
check('Repeated options become a list', array('package' => array('a.zip', 'b.zip')), parse_cli_options(array('x', '--package=a.zip', '--package=b.zip'), array('package:')));
check('Branch-mode options are unknown', 'Unknown option: --release', error_of(static fn() => parse_cli_options(array('x', '--release=7.1.4'), array('package:')))?->getMessage());

check('Artifact directory drops the .md suffix', '/tmp/report-artifacts/6.8.3-0', package_smoke_artifact_directory('/tmp/report.md', '6.8.3-0'));
check('Unchanged files give no reason', null, package_smoke_source_change_reason(array('wp-includes/version.php' => 'a', 'wp-settings.php' => 'b'), array('wp-includes/version.php' => 'a', 'wp-settings.php' => 'b')));
check('Changed version.php is reported', true, str_contains((string) package_smoke_source_change_reason(array('wp-includes/version.php' => 'a', 'wp-settings.php' => 'b'), array('wp-includes/version.php' => 'c', 'wp-settings.php' => 'b')), 'changed during the run (version.php)'));
check('Duration format', '1h 1m 5s', package_smoke_format_duration(3665));

$late_updater = array('config', 'set', 'AUTOMATIC_UPDATER_DISABLED', 'true', '--raw');
check('Fresh install needs no late automatic update config setting', false, in_array($late_updater, array_column($fresh_steps, 'arguments'), true));
check('Previous install needs no late automatic update config setting', false, in_array($late_updater, array_column($upgrade_steps, 'arguments'), true));

$result = static fn(int $code, string $stderr, string $stdout = ''): array => array('code' => $code, 'stdout' => $stdout, 'stderr' => $stderr);
check('MySQL refusal retries', 'MySQL was not ready for the WordPress install.', package_smoke_install_retry_condition($result(1, "Connection refused\nCan't connect to MySQL server")));
check('Database connection error 2002 retries', 'MySQL was not ready for the WordPress install.', package_smoke_install_retry_condition($result(1, 'Database connection error (2002) Connection refused')));
check('Success never retries', null, package_smoke_install_retry_condition($result(0, 'Connection refused')));
check('Other failures do not retry', null, package_smoke_install_retry_condition($result(1, 'Error: table missing')));
check('Composer advisory start failure is known', 'Composer blocked a pinned development dependency under the current security-advisory policy.', package_smoke_known_start_condition($result(1, 'Packages are affected by security advisories')));
check('Other start failures are not known', null, package_smoke_known_start_condition($result(1, 'docker: daemon not running')));
check('Missing Playwright browser blocks the environment', true, str_starts_with((string) package_smoke_e2e_environment_condition($result(1, "Executable doesn't exist at /x")), "Playwright's browser is not installed"));
check('Browser launch failure blocks the environment', 'The browser process could not start in the local environment.', package_smoke_e2e_environment_condition($result(1, 'Failed to launch the browser process')));
check('A failed walkthrough is not an environment block', null, package_smoke_e2e_environment_condition($result(1, 'SMOKE ERR: timeout')));
check('Command detail keeps error lines and hides paths', '<worktree>/x: error: boom', package_smoke_command_detail("ok\n/tmp/wt/x: error: boom\n", '/tmp/wt', '/tmp/co'));
check('First fatal is trimmed to one line', 'First logged fatal: PHP Fatal error: x', package_smoke_first_fatal_reason(array('new_fatals' => array("PHP Fatal error: x\nstack"))));
check('No fatal gives no reason', '', package_smoke_first_fatal_reason(array()));

$result_dir = sys_get_temp_dir() . '/package-smoke-result-' . bin2hex(random_bytes(4));
mkdir($result_dir);
$flow = array('slug' => '01-home', 'verdict' => 'OK', 'console_errors' => 0);
$functional = static function (array $data) use ($result_dir): array {
	file_put_contents($result_dir . '/result.json', json_encode($data));
	return package_smoke_functional_result($result_dir . '/result.json');
};
check('A clean walkthrough passes', array('ok' => true, 'reason' => 'All functional page flows passed.'), $functional(array('login' => array('logged_in' => true), 'logout' => array('confirmed' => true), 'flows' => array($flow))));
check('Failed login, fatal page, and logged fatal are all named', array('ok' => false, 'reason' => 'admin login failed; 01-home returned FATAL; debug.log recorded a new PHP fatal.'),
	$functional(array('login' => array('logged_in' => false), 'logout' => array('confirmed' => true), 'flows' => array(array('verdict' => 'FATAL') + $flow), 'new_fatals' => array('PHP Fatal error: x'))));
check('No flows fails', array('ok' => false, 'reason' => 'no page flows were recorded.'), $functional(array('login' => array('logged_in' => true), 'logout' => array('confirmed' => true), 'flows' => array())));
unlink($result_dir . '/result.json');
check('Missing result.json fails', 'The functional smoke did not write result.json.', package_smoke_functional_result($result_dir . '/result.json')['reason']);
rmdir($result_dir);

check('Container npm runs as the user with the cache mounted', array(
	'docker', 'run', '--rm', '--platform=linux/amd64', '--user=501:20', '--volume=/wt:/workspace', '--volume=/cache:/tmp/.npm', '--workdir=/workspace',
	'--env', 'HOME=/tmp', '--env', 'PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true', '--env', 'PUPPETEER_SKIP_DOWNLOAD=true', '--env', 'LOCAL_PORT=8889',
	'node:14-bullseye', 'npm', 'ci',
), package_smoke_container_npm_command('/wt', '/cache', array('LOCAL_PORT' => '8889'), array('ci'), 'node:14-bullseye', 'linux/amd64', 501, 20));
check('Lockfile search finds a nested package', true, package_smoke_lockfile_has_package(array('dependencies' => array('a' => array('dependencies' => array('node-sass' => array('version' => '4.0.0'))))), 'node-sass'));
check('Lockfile search needs a version', false, package_smoke_lockfile_has_package(array('node-sass' => array()), 'node-sass'));
check('PHP target uses the release-line matrix', array('floor' => '7.4', 'floor_source' => 'release-line-matrix', 'versions' => array('7.4', '8.4')), package_smoke_php_target(array('7.1' => array('7.4', '8.4')), array('7.1' => '7.4'), '7.1'));
check_throws('PHP target needs a matrix entry', static fn() => package_smoke_php_target(array(), array(), '7.1'));
check_throws('Commands need env:cli', static fn() => package_smoke_commands(array('env:start' => 'x', 'env:install' => 'x', 'build' => 'x', 'env:clean' => 'x'), '/nonexistent'));
check('Commands prefer env:clean', 'env:clean', package_smoke_commands(array('env:start' => 'x', 'env:install' => 'x', 'env:cli' => 'x', 'build' => 'x', 'env:clean' => 'x', 'env:stop' => 'x'), '/nonexistent')['clean']);
check('Release line from a patch version', '7.1', release_line('7.1.4'));
check('Git commands turn off auto maintenance', array('git', '-c', 'maintenance.auto=false', 'fetch'), git_command_without_auto_maintenance(array('git', 'fetch')));
check('Other commands are unchanged', array('npm', 'ci'), git_command_without_auto_maintenance(array('npm', 'ci')));

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
