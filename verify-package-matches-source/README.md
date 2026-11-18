# verify-package-matches-source

Checks that a built WordPress zip matches its source: each zip file must equal the file it is built from in the version's `WordPress/wordpress-develop` tag, and no PHP file may be missing or added. It catches a build that put an old file back, as the 5.9.14 zip did with `wp-includes/blocks/post-date.php`. Install tests and the md5 check pass on such a zip.

## Usage

```sh
php verify-package-matches-source.php --repository=WordPress/wordpress-develop --checkout=../wordpress-develop \
  --package=https://wordpress.org/wordpress-7.1.4.zip
```

`--package` takes a URL or a local path. Run `--help` for the details.

Exit 0 when the zip matches. Exit 2 when a file differs, is missing or is added; the output names the files. Exit 1 when the script broke.

## In CI

The `Release checks: Packages` workflow (`.github/workflows/package-checks.yml`) runs this tool on each zip, next to `package-smoke`. See the [package-smoke README](../package-smoke/README.md#in-ci).

## How it works

- Fetches the version's tag (`7.1` uses `7.1.0`) from the checkout's origin into `refs/verify-package-matches-source/tags/`.
- Compares each zip file `wordpress/<path>` with `src/<path>` in the tag, and `wp-config-sample.php` with the tag's root copy. Git blob ids are compared first, so only files that differ are read in full.
- From 5.1, the build copies `src/js/_enqueues/` files to `wp-admin/js/` and `wp-includes/js/`. The tool compares these copies with their sources. It reads the single-file copies from the tag's `Gruntfile.js`. The folder copies follow the `copy:vendor-js` and `copy:codemirror` tasks.
- Fails when a `src/` PHP file is not in the zip. Exceptions: `_index.php`, `wp-admin/_index.php`, `src/js/_enqueues/` files whose copy is in the zip, and default themes that the zip leaves out.
- Fails when a zip `.php`, `.phtml` or `.phar` file has no source, unless it is build output. Build output is one of these, and must only return an array of strings, `true`, `false` and `null`:
  - a `wp-includes/js/dist/` `*.asset.php` file next to its `.js` file;
  - a generated file listed in `source_match_missing_and_added()`, in `wp-includes/assets/`, `wp-includes/css/dist/` or `wp-includes/js/dist/script-modules/`.
- Does not check Akismet (`wp-content/plugins/akismet/`), because the tag has no source for it.
- Other files on only one side, such as minified files and copied npm packages, are left out.
- Allows these changes, which the build makes on purpose:
  - `index.php` and `wp-admin/index.php` come from `_index.php` (5.1 and later).
  - `wp-includes/version.php` drops `-src` from `$wp_version`.
  - `license.txt` and `wp-includes/images/crystal/license.txt` have CRLF line endings (5.4 and later).
  - In `wp-includes/formatting.php` and `wp-includes/embed.php`, each `include "<file>"` line becomes the zip's copy of that minified file (up to 5.8).
  - Block `*.asset.php` files get new `'version'` hashes (5.9 to 6.4).
  - `*.min.js` files may get the `/*! This file is auto-generated */` banner as their first line.
  - `wp-includes/js/jquery/jquery.js` and `jquery.min.js` may get `jQuery.noConflict();` at the end (5.1 to 5.5).
  - `wp-includes/js/imagesloaded.min.js` (up to 5.0), `wp-includes/js/imgareaselect/jquery.imgareaselect.min.js` (5.1 and later) and `wp-includes/assets/script-loader-packages.php` (5.4 to 6.0) are written again by the build, so they are not compared.

## Requirements

- PHP 8.1 or later with the zip extension and `allow_url_fopen` on.
- Git.
- A clone of `WordPress/wordpress-develop` with `origin` pointing at GitHub. A partial clone (`git clone --filter=blob:none`) is enough. It does not need to be clean.

## Tests

```sh
php tests/verify-package-matches-source-tests.php
```

Offline: no network, no subprocesses.

## License

Free software, like WordPress: GNU General Public License version 2 or (at your option) any later version. See [LICENSE](LICENSE).
