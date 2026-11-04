#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check that a built WordPress zip matches its wordpress-develop tag.
 *
 * Usage:
 *   php verify-package-matches-source.php --repository=WordPress/wordpress-develop --checkout=<path> --package=<zip URL or path>
 *
 * Each zip file wordpress/<path> must equal the src/ file it is built from in the version's tag,
 * apart from the changes the build makes on purpose. A src/ PHP file that the zip lacks, or a
 * zip PHP file with no source that is not known build output, also fails.
 *
 * Exit 0: the zip matches. Exit 2: some files differ. Exit 1: the script broke.
 *
 * Tests: php tests/verify-package-matches-source-tests.php
 */

require_once dirname(__DIR__) . '/lib/lib.php';

/**
 * The src/ path that a package path is built from, or null if src/ has no such file.
 * From 5.1, the build copies _index.php over the index.php placeholders.
 *
 * @param array<string,string> $copies Package paths mapped to the src/ paths the build copies them from.
 */
function source_match_path(string $path, array $source, array $copies = array()): ?string {
	if (in_array($path, array('index.php', 'wp-admin/index.php'), true)) {
		$built_from = preg_replace('~index\.php$~', '_index.php', $path);
		if (array_key_exists($built_from, $source)) {
			return $built_from;
		}
	}
	if (array_key_exists($path, $source)) {
		return $path;
	}
	return isset($copies[$path]) && array_key_exists($copies[$path], $source) ? $copies[$path] : null;
}

/**
 * From 5.1, the build copies src/js/_enqueues/ files to other paths.
 * The single-file copies are read from the tag's Gruntfile.js. The folder copies follow its
 * copy:vendor-js and copy:codemirror tasks, which have not changed from 5.1 to 7.1.
 *
 * @param array<int,string> $source_paths Paths under src/, without src/.
 * @return array<string,string> Package paths mapped to the src/ paths they are copied from.
 */
function source_match_copies(string $gruntfile, array $source_paths): array {
	preg_match_all("~\\[ WORKING_DIR \\+ '([^']+)' \\]: \\[ '\\./src/(js/_enqueues/[^']+)' \\]~", $gruntfile, $matches, PREG_SET_ORDER);
	if (count($matches) !== substr_count($gruntfile, "'./src/js/_enqueues/")) {
		throw new RuntimeException('Unable to read every src/js/_enqueues/ copy from Gruntfile.js.');
	}
	$copies = array_column($matches, 2, 1);
	$vendor = 'js/_enqueues/vendor/';
	foreach ($source_paths as $path) {
		if (!str_starts_with($path, $vendor)) {
			continue;
		}
		$file = substr($path, strlen($vendor));
		if (in_array($file, array('farbtastic.js', 'iris.min.js'), true)) {
			$copies["wp-admin/js/{$file}"] = $path;
		} elseif (str_starts_with($file, 'deprecated/suggest')) {
			$copies['wp-includes/js/jquery/' . substr($file, strlen('deprecated/'))] = $path;
		} elseif (!str_starts_with($file, 'deprecated/')) {
			$copies["wp-includes/js/{$file}"] = $path;
		}
	}
	$copies['wp-includes/js/codemirror/htmlhint-kses.js'] = 'js/_enqueues/lib/codemirror/htmlhint-kses.js';
	$copies['wp-includes/js/codemirror/fakejshint.js'] = 'js/_enqueues/deprecated/fakejshint.js';
	return $copies;
}

/**
 * @param array<int,string>    $package_paths Package paths, without wordpress/.
 * @param array<int,string>    $source_paths  Paths under src/, without src/.
 * @param array<string,string> $copies        From source_match_copies().
 * @param callable(string):(string|false) $read_package Reads a package file by its path.
 * @return array<int,string> "<path> (missing)" for each src/ PHP file that the zip lacks, and
 *                           "<path> (added)" for each zip PHP file with no source that is not known build output.
 */
function source_match_missing_and_added(array $package_paths, array $source_paths, array $copies, callable $read_package): array {
	$package = array_flip($package_paths);
	$source = array_flip($source_paths);
	$built_from = array_flip(array_merge(array('_index.php', 'wp-admin/_index.php'), array_values(array_intersect_key($copies, $package))));
	$copy_destinations = array_flip($copies);
	$themes = array();
	foreach ($package_paths as $path) {
		if (1 === preg_match('~^wp-content/themes/([^/]+)/~', $path, $match)) {
			$themes[$match[1]] = true;
		}
	}
	$found = array();
	foreach ($source_paths as $path) {
		if (!str_ends_with($path, '.php') || isset($package[$path]) || isset($built_from[$path])) {
			continue;
		}
		// The zip ships only some of the default themes in src/.
		if (1 === preg_match('~^wp-content/themes/([^/]+)/~', $path, $match) && !isset($themes[$match[1]])) {
			continue;
		}
		$found[] = ($copy_destinations[$path] ?? $path) . ' (missing)';
	}
	// The build writes these from the built packages; src/ has no copy in some versions. Akismet has no source in the tag.
	$generated = array(
		'wp-includes/assets/script-loader-packages.php',
		'wp-includes/assets/script-loader-react-refresh-entry.php',
		'wp-includes/assets/script-loader-react-refresh-entry.min.php',
		'wp-includes/assets/script-loader-react-refresh-runtime.php',
		'wp-includes/assets/script-loader-react-refresh-runtime.min.php',
		'wp-includes/assets/script-modules-packages.php',
		'wp-includes/css/dist/registry.php',
		'wp-includes/js/dist/script-modules/registry.php',
	);
	foreach ($package_paths as $path) {
		if (1 !== preg_match('~\.(php|phtml|phar)$~', $path) || null !== source_match_path($path, $source, $copies)) {
			continue;
		}
		if (str_starts_with($path, 'wp-content/plugins/akismet/')) {
			continue;
		}
		$generated_file = in_array($path, $generated, true)
			|| (1 === preg_match('~^(wp-includes/js/dist/.+)\.asset\.php$~', $path, $match) && isset($package["{$match[1]}.js"]));
		if ($generated_file && source_match_is_literal_array((string) $read_package($path))) {
			continue;
		}
		$found[] = "{$path} (added)";
	}
	sort($found);
	return $found;
}

/**
 * True when the PHP file is `<?php return array(...);`, built only from strings, true, false, null and nested arrays,
 * as the generated *.asset.php and registry files are. Comments are allowed.
 */
function source_match_is_literal_array(string $php): bool {
	$all = token_get_all($php);
	if (!is_array($all[0] ?? null) || T_OPEN_TAG !== $all[0][0]) {
		return false;
	}
	$kinds = array();
	foreach ($all as $token) {
		$kind = is_array($token) ? $token[0] : $token;
		if (T_STRING === $kind && in_array(strtolower($token[1]), array('true', 'false', 'null'), true)) {
			$kind = T_CONSTANT_ENCAPSED_STRING;
		}
		if (!in_array($kind, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			$kinds[] = $kind;
		}
	}
	$at = 2;
	$value = static function () use (&$value, $kinds, &$at): bool {
		if (T_CONSTANT_ENCAPSED_STRING === ($kinds[$at] ?? null)) {
			++$at;
			return true;
		}
		if (T_ARRAY !== ($kinds[$at] ?? null) || '(' !== ($kinds[$at + 1] ?? null)) {
			return false;
		}
		$at += 2;
		while (')' !== ($kinds[$at] ?? null)) {
			if (!$value()) {
				return false;
			}
			if (T_DOUBLE_ARROW === ($kinds[$at] ?? null)) {
				++$at;
				if (!$value()) {
					return false;
				}
			}
			if (',' === ($kinds[$at] ?? null)) {
				++$at;
			} elseif (')' !== ($kinds[$at] ?? null)) {
				return false;
			}
		}
		++$at;
		return true;
	};
	return T_RETURN === ($kinds[1] ?? null) && $value() && ';' === ($kinds[$at] ?? null) && count($kinds) === $at + 1;
}

/**
 * Compare package files with their source files, after the changes the build makes on purpose.
 *
 * @param array<string,string> $package Package paths, without wordpress/, mapped to their content.
 * @param array<string,string> $source  Paths under src/, without src/, mapped to their content.
 * @return array<int,string> The package paths whose content differs from the source.
 */
function source_match_mismatches(array $package, array $source, string $version, array $copies = array()): array {
	$mismatches = array();
	foreach ($package as $path => $content) {
		$path = (string) $path;
		$source_path = source_match_path($path, $source, $copies);
		if (null === $source_path || source_match_rebuilt($path, $version)) {
			continue;
		}
		$expected = $source[$source_path];
		if ('wp-includes/version.php' === $path) {
			$expected = str_replace("\$wp_version = '{$version}-src';", "\$wp_version = '{$version}';", $expected);
		} elseif (in_array($path, array('license.txt', 'wp-includes/images/crystal/license.txt'), true)) {
			// The 5.4+ zips have CRLF line endings here.
			$expected = str_replace("\r\n", "\n", $expected);
			$content = str_replace("\r\n", "\n", $content);
		} elseif (str_ends_with($path, '.asset.php')) {
			// The build writes new version hashes into block asset files (5.9 to 6.4).
			$expected = preg_replace("/'version' => '[0-9a-f]+'/", "'version' => ''", $expected);
			$content = preg_replace("/'version' => '[0-9a-f]+'/", "'version' => ''", $content);
		} elseif (in_array($path, array('wp-includes/formatting.php', 'wp-includes/embed.php'), true)) {
			$expected = source_match_inline_includes($expected, $package);
			$content = source_match_without_banners($content);
		} elseif (in_array($path, array('wp-includes/js/jquery/jquery.js', 'wp-includes/js/jquery/jquery.min.js'), true)) {
			// The build appends jQuery.noConflict(); unless src/ already has it (5.1 to 5.5).
			if (!str_ends_with($expected, 'jQuery.noConflict();')) {
				$expected .= 'jQuery.noConflict();';
			}
		} elseif (str_ends_with($path, '.min.js')) {
			$content = preg_replace('~\A/\*! This file is auto-generated \*/\n~', '', $content);
		}
		if ($expected !== $content) {
			$mismatches[] = $path;
		}
	}
	sort($mismatches);
	return $mismatches;
}

/**
 * The build minifies imagesloaded.min.js (up to 5.0) and jquery.imgareaselect.min.js (5.1 and later) again,
 * and writes script-loader-packages.php from the built packages (5.4 to 6.0), so src/ holds stale copies.
 */
function source_match_rebuilt(string $path, string $version): bool {
	$from_5_1 = version_compare($version, '5.1', '>=');
	return match ($path) {
		'wp-includes/js/imagesloaded.min.js' => !$from_5_1,
		'wp-includes/js/imgareaselect/jquery.imgareaselect.min.js' => $from_5_1,
		'wp-includes/assets/script-loader-packages.php' => version_compare($version, '5.4', '>=') && version_compare($version, '6.1', '<'),
		default => false,
	};
}

/**
 * Up to 5.8, the build replaces each `include "<file>"` line in formatting.php and embed.php
 * with the indented lines of that file from wp-includes/. A missing file stays unreplaced.
 * The build adds the "auto-generated" banner to some minified files only after this step, so banners are dropped.
 */
function source_match_inline_includes(string $content, array $package): string {
	$inlined = preg_replace_callback(
		'/^([ \t]*)include "([^"]+)"[ \t]*$/m',
		static function (array $match) use ($package): string {
			$file = $package['wp-includes/' . $match[2]] ?? null;
			if (!is_string($file)) {
				return $match[0];
			}
			return implode("\n", array_map(static fn(string $line): string => $match[1] . $line, explode("\n", trim($file))));
		},
		$content
	);
	return source_match_without_banners($inlined);
}

function source_match_without_banners(string $content): string {
	return preg_replace('~^[ \t]*/\*! This file is auto-generated \*/\n~m', '', $content);
}

/**
 * @return array<int,string> The `include "<file>"` targets in formatting.php or embed.php.
 */
function source_match_include_targets(string $content): array {
	preg_match_all('/^[ \t]*include "([^"]+)"[ \t]*$/m', $content, $matches);
	return $matches[1];
}

/**
 * @return array<string,string> Paths under src/, without src/, mapped to their blob ids.
 *                              The build copies wp-config-sample.php from the tag's root.
 */
function source_match_parse_tree(string $tree): array {
	$source_ids = array();
	foreach (explode("\0", $tree) as $entry) {
		if (1 === preg_match('~^\d+ blob ([0-9a-f]+)\t(?:src/(.+)|(wp-config-sample\.php))$~s', $entry, $match)) {
			$source_ids[$match[2] . ($match[3] ?? '')] = $match[1];
		}
	}
	return $source_ids;
}

/** wordpress-develop tags X.Y.0 releases, whose $wp_version is X.Y, as X.Y.0. */
function source_match_tag(string $version): string {
	return 1 === preg_match('/^\d+\.\d+$/', $version) ? "{$version}.0" : $version;
}

function source_match_git_blob_id(string $content): string {
	return sha1('blob ' . strlen($content) . "\0" . $content);
}

/**
 * Fetch the version's tag and compare the zip with its src/ tree.
 * Only files whose Git blob ids differ are read in full.
 *
 * @return array<int,string>
 */
function source_match_read_mismatches(string $repo, string $version, string $zip_path): array {
	$tag = source_match_tag($version);
	$ref = "refs/verify-package-matches-source/tags/{$tag}";
	run_command(array('git', 'fetch', '--quiet', '--no-tags', 'origin', "+refs/tags/{$tag}:{$ref}"), $repo);
	$source_ids = source_match_parse_tree(
		run_command(array('git', 'ls-tree', '-r', '-z', "{$ref}^{tree}", '--', 'src/', 'wp-config-sample.php'), $repo)['stdout']
	);
	if (!$source_ids) {
		throw new RuntimeException("The {$tag} tag has no files under src/.");
	}
	$copies = source_match_copies(
		run_command(array('git', 'cat-file', 'blob', "{$ref}:Gruntfile.js"), $repo)['stdout'],
		array_map('strval', array_keys($source_ids))
	);
	$package = array();
	$source = array();
	$package_paths = array();
	$zip = open_package($zip_path);
	try {
		for ($index = 0; $index < $zip->numFiles; ++$index) {
			$path = substr($zip->getNameIndex($index), strlen('wordpress/'));
			if ('' !== $path && !str_ends_with($path, '/')) {
				$package_paths[] = $path;
			}
			$source_path = source_match_path($path, $source_ids, $copies);
			if (null === $source_path) {
				continue;
			}
			$content = $zip->getFromIndex($index);
			if (false === $content) {
				throw new RuntimeException("Unable to read {$path} from the package zip.");
			}
			if (source_match_git_blob_id($content) !== $source_ids[$source_path]) {
				$package[$path] = $content;
				$source[$source_path] = run_command(array('git', 'cat-file', 'blob', $source_ids[$source_path]), $repo)['stdout'];
			}
		}
		foreach (array('wp-includes/formatting.php', 'wp-includes/embed.php') as $path) {
			foreach (isset($package[$path]) ? source_match_include_targets($source[$path]) : array() as $target) {
				$file = $zip->getFromName("wordpress/wp-includes/{$target}");
				if (is_string($file)) {
					$package["wp-includes/{$target}"] = $file;
				}
			}
		}
		$found = array_merge(
			source_match_mismatches($package, $source, $version, $copies),
			source_match_missing_and_added(
				$package_paths,
				array_map('strval', array_keys($source_ids)),
				$copies,
				static fn(string $path): string|false => $zip->getFromName("wordpress/{$path}")
			)
		);
	} finally {
		$zip->close();
	}
	sort($found);
	return $found;
}

/**
 * @param array<int,string> $paths
 * @return array{code:int,message:string}
 */
function source_match_result(string $version, array $paths): array {
	$tag = source_match_tag($version);
	if ($paths) {
		return array('code' => 2, 'message' => "FAIL: WordPress {$version} differs from the {$tag} tag's src/: " . implode(', ', $paths) . '.');
	}
	return array('code' => 0, 'message' => "PASS: WordPress {$version} matches the {$tag} tag's src/.");
}

function source_match_help(string $script): string {
	return <<<HELP
Usage: {$script} --repository=<owner/name> --checkout=<path> --package=<zip URL or path>

Fetch the zip's version tag from the checkout's origin into refs/verify-package-matches-source/tags/,
and compare each zip file with the tag's src/ file it is built from.
A src/ PHP file that the zip lacks, or a zip PHP file with no source, also fails.

Required options:
  --repository=<owner/name>  Expected origin repository, such as WordPress/wordpress-develop.
  --checkout=<path>          Checkout of that repository.
  --package=<zip URL or path> The built zip; its version.php supplies the version.

Exit codes:
  0  The zip matches its tag.
  1  Operational failure.
  2  One or more files differ, are missing or are added.
HELP;
}

function source_match_main(array $argv): int {
	$options = cli_options($argv, array('repository:', 'checkout:', 'package:', 'help'));
	if (isset($options['help'])) {
		echo source_match_help($argv[0]);
		return 0;
	}
	foreach (array('repository', 'checkout', 'package') as $required) {
		if (!isset($options[$required]) || !is_string($options[$required]) || '' === trim($options[$required])) {
			fail("Usage: {$argv[0]} --repository=<owner/name> --checkout=<path> --package=<zip URL or path>");
		}
	}
	$download_directory = null;
	$error = null;
	try {
		if (!putenv('GIT_NO_REPLACE_OBJECTS=1')) {
			throw new RuntimeException('Unable to disable Git replacement objects.');
		}
		$repo = require_checkout($options['checkout']);
		require_origin_matches($repo, $options['repository']);
		$download_directory = temporary_worktree('wp-source-match');
		$zip = $download_directory . '/wordpress.zip';
		copy_package($options['package'], $zip);
		$version = read_package_version($zip);
		$result = source_match_result($version, source_match_read_mismatches($repo, $version, $zip));
	} catch (Throwable $caught) {
		$error = $caught;
	} finally {
		if (null !== $download_directory) {
			try {
				remove_temporary_directory($download_directory, 'wp-source-match-');
			} catch (Throwable $cleanup) {
				$error ??= new RuntimeException('Unable to remove the temporary download directory: ' . $cleanup->getMessage());
			}
		}
	}
	if (null !== $error) {
		fail($error->getMessage());
	}
	echo $result['message'] . PHP_EOL;
	return $result['code'];
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	exit(source_match_main($argv));
}
