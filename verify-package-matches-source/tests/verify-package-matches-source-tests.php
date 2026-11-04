#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Offline checks for verify-package-matches-source.php. No network, no subprocesses. */

require dirname(__DIR__) . '/verify-package-matches-source.php';

$failures   = array();
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void {
	++$GLOBALS['assertions'];
	if ($expected !== $actual) {
		$GLOBALS['failures'][] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
	}
}

$source_tree = array(
	'wp-includes/blocks/post-date.php' => "<?php\n// fixed\n",
	'wp-includes/version.php' => "<?php\n\$wp_version = '5.9.14-src';\n",
	'index.php' => "<?php\n// Build reminder.\n",
	'_index.php' => "<?php\nrequire 'wp-blog-header.php';\n",
	'license.txt' => "GPL\nv2\n",
	'wp-includes/blocks/file/view.asset.php' => "<?php return array('dependencies' => array(), 'version' => 'aaa111');",
	'wp-includes/embed.php' => "<?php\n\t\tinclude \"js/wp-embed.min.js\"\n\t\tinclude \"css/a.min.css\"\n?>\n",
	'wp-includes/assets/script-loader-packages.php' => "<?php return array('a.js' => array('dependencies' => array('react-dom')));",
);
$built_package = array(
	'wp-includes/blocks/post-date.php' => "<?php\n// fixed\n",
	'wp-includes/version.php' => "<?php\n\$wp_version = '5.9.14';\n",
	'index.php' => "<?php\nrequire 'wp-blog-header.php';\n",
	'license.txt' => "GPL\r\nv2\r\n",
	'wp-includes/blocks/file/view.asset.php' => "<?php return array('dependencies' => array(), 'version' => 'bbb222');",
	'wp-includes/embed.php' => "<?php\n\t\t!function(){}()\n\t\t/*! This file is auto-generated */\n\t\tbody{margin:0}\n?>\n",
	'wp-includes/assets/script-loader-packages.php' => "<?php return array('a.js' => array('dependencies' => array()));",
	'wp-includes/js/wp-embed.min.js' => "!function(){}()\n",
	'wp-includes/css/a.min.css' => "/*! This file is auto-generated */\nbody{margin:0}",
);
check('A zip built from its source matches', array(), source_match_mismatches($built_package, $source_tree, '5.9.14'));
check('A reverted file is named', array('wp-includes/blocks/post-date.php'), source_match_mismatches(array('wp-includes/blocks/post-date.php' => "<?php\n// old\n") + $built_package, $source_tree, '5.9.14'));
check('Only the version suffix may change in version.php', array('wp-includes/version.php'), source_match_mismatches(array('wp-includes/version.php' => "<?php\n\$wp_version = '5.9.13';\n"), $source_tree, '5.9.14'));
check('index.php is compared with _index.php', array('index.php'), source_match_mismatches(array('index.php' => "<?php\n// Build reminder.\n"), $source_tree, '5.9.14'));
check('index.php is compared with itself without _index.php', array(), source_match_mismatches(array('index.php' => "<?php\n// Build reminder.\n"), array('index.php' => "<?php\n// Build reminder.\n"), '5.0'));
check('An asset file may change only its version hash', array('wp-includes/blocks/file/view.asset.php'), source_match_mismatches(array('wp-includes/blocks/file/view.asset.php' => "<?php return array('dependencies' => array('wp-dom'), 'version' => 'aaa111');"), $source_tree, '5.9.14'));
check('Only include lines may change in embed.php', array('wp-includes/embed.php'), source_match_mismatches(array('wp-includes/embed.php' => "<?php\n\t\t!function(){}()\n\t\tbody{margin:0}\n// added\n?>\n") + $built_package, $source_tree, '5.9.14'));
check('An inlined file must match the zip copy', array('wp-includes/embed.php'), source_match_mismatches(array('wp-includes/embed.php' => "<?php\n\t\tevil()\n\t\tbody{margin:0}\n?>\n") + $built_package, $source_tree, '5.9.14'));
check('An inlined file may lack the banner', array(), source_match_mismatches(array('wp-includes/embed.php' => "<?php\n\t\t!function(){}()\n\t\tbody{margin:0}\n?>\n") + $built_package, $source_tree, '5.9.14'));
check('An include with no zip file fails', array('wp-includes/embed.php'), source_match_mismatches(array_diff_key($built_package, array('wp-includes/css/a.min.css' => 1)), $source_tree, '5.9.14'));
check('Include targets are read from the source', array('js/wp-embed.min.js', 'css/a.min.css'), source_match_include_targets($source_tree['wp-includes/embed.php']));
check('X.Y.0 releases use the X.Y.0 tag', array('7.1.0', '7.1.4'), array(source_match_tag('7.1'), source_match_tag('7.1.4')));
check('ls-tree keeps src/ blobs only', array('index.php' => 'aa11', 'wp-admin/a b.php' => 'bb22'), source_match_parse_tree("100644 blob aa11\tsrc/index.php\x00100755 blob bb22\tsrc/wp-admin/a b.php\x00160000 commit cc33\tsrc/vendor\x00100644 blob dd44\ttests/x.php\x00"));
check('The crystal license may change line endings only', array(), source_match_mismatches(array('wp-includes/images/crystal/license.txt' => "a\r\nb\r\n"), array('wp-includes/images/crystal/license.txt' => "a\nb\n"), '5.9.14'));
check('Line endings count outside the license files', array('wp-includes/blocks/post-date.php'), source_match_mismatches(array('wp-includes/blocks/post-date.php' => "<?php\r\n// fixed\r\n"), $source_tree, '5.9.14'));
check('A Git blob id matches git hash-object', 'ce013625030ba8dba906f756967f9e9ca394464a', source_match_git_blob_id("hello\n"));

check('wp-admin/index.php is compared with wp-admin/_index.php', array('wp-admin/index.php'), source_match_mismatches(array('wp-admin/index.php' => "<?php\n// Build reminder.\n"), array('wp-admin/index.php' => "<?php\n// Build reminder.\n", 'wp-admin/_index.php' => "<?php\n// Dashboard.\n"), '5.9.14'));
check('formatting.php inlines its includes', array(), source_match_mismatches(array('wp-includes/formatting.php' => "<?php\n\t\t\t!function(){}()\n", 'wp-includes/js/wp-emoji-loader.min.js' => '!function(){}()'), array('wp-includes/formatting.php' => "<?php\n\t\t\tinclude \"js/wp-emoji-loader.min.js\"\n"), '5.8'));
check('formatting.php may change only its include lines', array('wp-includes/formatting.php'), source_match_mismatches(array('wp-includes/formatting.php' => "<?php\n\t\t\tevil()\n", 'wp-includes/js/wp-emoji-loader.min.js' => '!function(){}()'), array('wp-includes/formatting.php' => "<?php\n\t\t\tinclude \"js/wp-emoji-loader.min.js\"\n"), '5.8'));
check('An X.Y.0 version.php drops -src', array(), source_match_mismatches(array('wp-includes/version.php' => "<?php\n\$wp_version = '7.1';\n"), array('wp-includes/version.php' => "<?php\n\$wp_version = '7.1-src';\n"), '7.1'));

$gruntfile = "\t\t\t[ WORKING_DIR + 'wp-admin/js/common.js' ]: [ './src/js/_enqueues/admin/common.js' ],\n"
	. "\t\t\t[ WORKING_DIR + 'wp-includes/js/dist/vendor/react.js' ]: [ './node_modules/react/umd/react.development.js' ],\n";
$copy_sources = array('js/_enqueues/admin/common.js', 'js/_enqueues/vendor/jquery/jquery.js', 'js/_enqueues/vendor/iris.min.js', 'js/_enqueues/vendor/deprecated/suggest.js', 'js/_enqueues/vendor/deprecated/other.js', 'js/_enqueues/vendor/tinymce/wp-tinymce.php');
$copies = source_match_copies($gruntfile, $copy_sources);
check('Copies come from the Gruntfile and the vendor folder', array(
	'wp-admin/js/common.js' => 'js/_enqueues/admin/common.js',
	'wp-admin/js/iris.min.js' => 'js/_enqueues/vendor/iris.min.js',
	'wp-includes/js/codemirror/fakejshint.js' => 'js/_enqueues/deprecated/fakejshint.js',
	'wp-includes/js/codemirror/htmlhint-kses.js' => 'js/_enqueues/lib/codemirror/htmlhint-kses.js',
	'wp-includes/js/jquery/jquery.js' => 'js/_enqueues/vendor/jquery/jquery.js',
	'wp-includes/js/jquery/suggest.js' => 'js/_enqueues/vendor/deprecated/suggest.js',
	'wp-includes/js/tinymce/wp-tinymce.php' => 'js/_enqueues/vendor/tinymce/wp-tinymce.php',
), (static function (array $copies): array { ksort($copies); return $copies; })($copies));
check('A reverted copied file is named', array('wp-admin/js/common.js'), source_match_mismatches(array('wp-admin/js/common.js' => 'old()'), array('js/_enqueues/admin/common.js' => 'new()'), '7.1.4', $copies));
check('A copied minified file may gain the banner', array(), source_match_mismatches(array('wp-admin/js/iris.min.js' => "/*! This file is auto-generated */\nx()"), array('js/_enqueues/vendor/iris.min.js' => 'x()'), '7.1.4', $copies));
check('jquery.js may gain jQuery.noConflict(); only', array(array(), array('wp-includes/js/jquery/jquery.js')), array(
	source_match_mismatches(array('wp-includes/js/jquery/jquery.js' => "j()\njQuery.noConflict();"), array('js/_enqueues/vendor/jquery/jquery.js' => "j()\n"), '5.5.24', $copies),
	source_match_mismatches(array('wp-includes/js/jquery/jquery.js' => "j()\nevil();"), array('js/_enqueues/vendor/jquery/jquery.js' => "j()\n"), '5.5.24', $copies),
));

$asset = "<?php return array('dependencies' => array('wp-data'), 'module_dependencies' => array(array('id' => '@wordpress/boot', 'import' => 'static')), 'version' => 'e2f8');";
$read_asset = static fn(string $path): string => $asset;
$built_paths = array('index.php', 'wp-includes/kses.php', 'wp-includes/js/tinymce/wp-tinymce.php', 'wp-config-sample.php', 'wp-content/plugins/akismet/akismet.php', 'wp-includes/js/dist/a.min.js', 'wp-includes/js/dist/a.min.asset.php', 'wp-includes/assets/script-loader-packages.php', 'wp-content/themes/twentytwentyfive/functions.php');
$src_paths = array('_index.php', 'index.php', 'wp-includes/kses.php', 'wp-config-sample.php', 'js/_enqueues/vendor/tinymce/wp-tinymce.php', 'wp-content/themes/twentytwentyfive/functions.php', 'wp-content/themes/twentyten/functions.php');
check('Known build output and left-out themes pass', array(), source_match_missing_and_added($built_paths, $src_paths, $copies, $read_asset));
check('A missing or added PHP file is named', array(
	'wp-config-sample.php (missing)',
	'wp-content/themes/twentytwentyfive/404.php (missing)',
	'wp-includes/assets/script-loader-shell.php (added)',
	'wp-includes/backdoor.phtml (added)',
	'wp-includes/js/dist/evil.php (added)',
	'wp-includes/js/dist/shell.asset.php (added)',
	'wp-includes/js/tinymce/wp-tinymce.php (missing)',
	'wp-includes/kses.php (missing)',
), source_match_missing_and_added(
	array_merge(array_diff($built_paths, array('wp-includes/kses.php', 'wp-config-sample.php', 'wp-includes/js/tinymce/wp-tinymce.php')), array('wp-includes/backdoor.phtml', 'wp-includes/js/dist/evil.php', 'wp-includes/js/dist/shell.asset.php', 'wp-includes/assets/script-loader-shell.php')),
	array_merge($src_paths, array('wp-content/themes/twentytwentyfive/404.php')),
	$copies,
	$read_asset
));
check('An asset file that runs code is added', array('wp-includes/js/dist/a.min.asset.php (added)'), source_match_missing_and_added(array('wp-includes/js/dist/a.min.js', 'wp-includes/js/dist/a.min.asset.php'), array(), $copies, static fn(): string => "<?php system(\$_GET['c']); return array();"));
check('Only literal arrays count as generated files', array(true, true, false, false, false, false, false, false, false), array(
	source_match_is_literal_array($asset),
	source_match_is_literal_array("<?php\n/**\n * Registry.\n */\n\nreturn array(\n\tarray('id' => 'a', 'min_only' => true),\n);\n"),
	source_match_is_literal_array("<?php return array('a' => \$x);"),
	source_match_is_literal_array("x<?php return array();"),
	source_match_is_literal_array("<?php return 'system'('file_get_contents'('php://input'));"),
	source_match_is_literal_array("<?php return array('Closure', 'fromCallable')('system')('id');"),
	source_match_is_literal_array("<?php return array(); system('id');"),
	source_match_is_literal_array("<?php return array('a' 'b');"),
	source_match_is_literal_array("<?php return array(PHP_OS);"),
));
check('A generated file that runs code is added', array('wp-includes/css/dist/registry.php (added)'), source_match_missing_and_added(array('wp-includes/css/dist/registry.php'), array(), $copies, static fn(): string => "<?php return 'system'('id');"));
check('A Gruntfile copy in an unknown format stops the run', 'Unable to read every src/js/_enqueues/ copy from Gruntfile.js.', (static function (): ?string {
	try {
		source_match_copies("[ WORKING_DIR + 'a.js' ]: [\"./src/js/_enqueues/a.js\", './src/js/_enqueues/b.js' ]", array());
	} catch (RuntimeException $error) {
		return $error->getMessage();
	}
	return null;
})());
check('wp-config-sample.php comes from the tag root', array('wp-config-sample.php' => 'ee11'), source_match_parse_tree("100644 blob ee11\twp-config-sample.php\x00"));
check('Rebuilt files are skipped only in their versions', array(true, false, true, false, false, true, false), array(
	source_match_rebuilt('wp-includes/js/imagesloaded.min.js', '5.0'),
	source_match_rebuilt('wp-includes/js/imagesloaded.min.js', '5.1'),
	source_match_rebuilt('wp-includes/js/imgareaselect/jquery.imgareaselect.min.js', '5.1.28'),
	source_match_rebuilt('wp-includes/js/imgareaselect/jquery.imgareaselect.min.js', '4.9.26'),
	source_match_rebuilt('wp-includes/assets/script-loader-packages.php', '5.3.27'),
	source_match_rebuilt('wp-includes/assets/script-loader-packages.php', '6.0.18'),
	source_match_rebuilt('wp-includes/assets/script-loader-packages.php', '7.1.4'),
));
check('A changed script-loader-packages.php fails from 6.1', array('wp-includes/assets/script-loader-packages.php'), source_match_mismatches(array('wp-includes/assets/script-loader-packages.php' => '<?php system(1);'), array('wp-includes/assets/script-loader-packages.php' => '<?php return array();'), '7.1.4'));
check('The banner is allowed only as the first line', array(array(), array('wp-admin/js/iris.min.js')), array(
	source_match_mismatches(array('wp-admin/js/iris.min.js' => "/*! This file is auto-generated */\nx()"), array('js/_enqueues/vendor/iris.min.js' => 'x()'), '7.1.4', $copies),
	source_match_mismatches(array('wp-admin/js/iris.min.js' => "x()\n/*! This file is auto-generated */\n"), array('js/_enqueues/vendor/iris.min.js' => "x()\n"), '7.1.4', $copies),
));

check('A match passes', array('code' => 0, 'message' => "PASS: WordPress 7.1 matches the 7.1.0 tag's src/."), source_match_result('7.1', array()));
check('A difference fails and names the files', array('code' => 2, 'message' => "FAIL: WordPress 5.9.14 differs from the 5.9.14 tag's src/: wp-includes/blocks/post-date.php."), source_match_result('5.9.14', array('wp-includes/blocks/post-date.php')));

foreach ($failures as $failure) {
	echo "FAIL {$failure}\n";
}
echo ($failures ? 'FAILED' : 'OK') . ": {$assertions} assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
