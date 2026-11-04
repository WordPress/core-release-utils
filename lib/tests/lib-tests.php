#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for lib.php. No network, no subprocesses. */

require dirname(__DIR__) . '/lib.php';

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
	return sys_get_temp_dir() . '/core-release-utils-lib-' . $name . '-' . bin2hex(random_bytes(4)) . '.zip';
}

check('Package version comes from the assignment', '6.8.3', package_version("<?php\n\$wp_version = '6.8.3';\n"));
check('Initial public release has no patch suffix', '6.8', package_version("<?php\n\$wp_version = '6.8';\n"));
check_throws('Missing package version is rejected', static fn() => package_version('<?php'));
foreach (array('6.8-beta3', '6.8-RC1', '6.8.3-beta1') as $prerelease) {
	check("Pre-release {$prerelease} is named", "The package version {$prerelease} is a pre-release; this tool tests stable release zips only.", error_of(static fn() => package_version("<?php\n\$wp_version = '{$prerelease}';\n"))?->getMessage());
}
check('Unquoted junk keeps the generic message', 'The package version.php has no valid $wp_version assignment.', error_of(static fn() => package_version("<?php\n\$wp_version = 'next';\n"))?->getMessage());

check('Single wordpress root is accepted', null, package_layout(array('wordpress/', 'wordpress/index.php', 'wordpress/wp-includes/version.php')));
check_throws('Flat zip is rejected', static fn() => package_layout(array('index.php', 'wp-includes/version.php')));
check_throws('Two zip roots are rejected', static fn() => package_layout(array('wordpress/wp-includes/version.php', 'other/index.php')));
check_throws('Missing version file is rejected', static fn() => package_layout(array('wordpress/index.php')));
check_throws('Dot-segment zip path is rejected', static fn() => package_layout(array('wordpress/./version.php', 'wordpress/wp-includes/version.php')));
check_throws('Zip traversal is rejected', static fn() => package_layout(array('wordpress/wp-includes/version.php', 'wordpress/../../outside.php')));
check_throws('Backslash zip paths are rejected', static fn() => package_layout(array('wordpress/wp-includes/version.php', 'wordpress/..\\outside.php')));

$copy_retry_destination = temporary_path('copy-retry');
$copy_retry_error = error_of(static fn() => copy_package('http://127.0.0.1:1/wordpress-6.8.3.zip', $copy_retry_destination, 2, 0));
if (is_file($copy_retry_destination)) {
	unlink($copy_retry_destination);
}
check('An unreachable package URL is retried before failing', true, $copy_retry_error instanceof RuntimeException);
check('The retry failure reports the attempt count', true, str_starts_with($copy_retry_error?->getMessage() ?? '', 'Unable to copy the package zip after 2 attempt(s):'));
check_throws('A missing local package source is rejected', static fn() => copy_package(temporary_path('missing'), temporary_path('copy-missing'), 3, 0));
check_throws('At least one copy attempt is required', static fn() => copy_package('http://127.0.0.1:1/x.zip', temporary_path('copy-zero'), 0, 0));

check('Canonical https remote normalizes', 'WordPress/wordpress-develop', normalize_github_remote('https://github.com/WordPress/wordpress-develop.git'));
check('scp-style GitHub remote normalizes', 'WordPress/wordpress-develop', normalize_github_remote('git@github.com:WordPress/wordpress-develop.git'));
check('Local mirror path falls back to its last two segments', 'WordPress/wordpress-develop', normalize_github_remote('/srv/mirrors/WordPress/wordpress-develop.git'));
check('Other hosts are refused', null, normalize_github_remote('https://evil.example/WordPress/wordpress-develop.git'));
check('scp-style host without user is refused', null, normalize_github_remote('evil.invalid:any/WordPress/wordpress-develop.git'));
check('A token in a query string is refused', null, normalize_github_remote('https://github.com/WordPress/wordpress-develop.git?access_token=TOKEN'));

check('Parser accepts both value forms', array('package' => 'a.zip', 'output' => 'r.md', 'verbose' => false), parse_cli_options(array('x', '--package=a.zip', '--output', 'r.md', '--verbose'), array('package:', 'output:', 'verbose')));
check('Repeated options become a list', array('package' => array('a.zip', 'b.zip')), parse_cli_options(array('x', '--package=a.zip', '--package=b.zip'), array('package:')));
check('Unknown options are refused', 'Unknown option: --release', error_of(static fn() => parse_cli_options(array('x', '--release=7.1.4'), array('package:')))?->getMessage());

check('Git commands turn off auto maintenance', array('git', '-c', 'maintenance.auto=false', 'fetch'), git_command_without_auto_maintenance(array('git', 'fetch')));
check('Other commands are unchanged', array('npm', 'ci'), git_command_without_auto_maintenance(array('npm', 'ci')));

$removable = sys_get_temp_dir() . '/core-release-utils-lib-remove-' . bin2hex(random_bytes(4));
mkdir($removable . '/a/b', 0777, true);
file_put_contents($removable . '/a/b/f.txt', 'x');
remove_temporary_directory($removable, 'core-release-utils-lib-remove-');
check('A temporary directory is removed with its contents', false, is_dir($removable));
$other_prefix = sys_get_temp_dir() . '/core-release-utils-lib-keep-' . bin2hex(random_bytes(4));
mkdir($other_prefix);
check_throws('A directory with the wrong prefix is not removed', static fn() => remove_temporary_directory($other_prefix, 'core-release-utils-lib-remove-'));
check('The wrong-prefix directory is kept', true, is_dir($other_prefix));
rmdir($other_prefix);
check_throws('A directory outside the temporary root is not removed', static fn() => remove_temporary_directory(__DIR__, basename(__DIR__)));
remove_temporary_directory($removable, 'core-release-utils-lib-remove-');
check('A missing directory is ignored', false, is_dir($removable));

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
