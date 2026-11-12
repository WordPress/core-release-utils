# package-smoke

Smoke-tests one built WordPress zip in a real site: a fresh install, then an upgrade from the previous release. Each install gets a Playwright walkthrough of the front end and wp-admin.

## Usage

```sh
php package-smoke.php --repository=WordPress/wordpress-develop --checkout=../wordpress-develop \
  --package=https://wordpress.org/wordpress-7.1.4.zip \
  --playwright-module=../playwright-module/node_modules/playwright --output=/tmp/package-smoke/7.1.4.md
```

Options: `--previous=ZIP` (the release to upgrade from), `--locales=it_IT,fr_FR` (also check those localized zips), `--npm-cache=PATH`, `--port=PORT`, `--verbose`. Run `--help` for the details.

`--package` and `--previous` take a URL or a local path. The default previous release is one patch earlier: 7.1.4 upgrades from 7.1.3, and 7.1.1 from 7.1. An X.Y.0 package needs `--previous`. Beta and RC zips are refused.

The Markdown report has one row per install, one md5 row and, with `--locales`, one row per locale. When a walkthrough fails, its `result.json` and screenshots go to `<report>-artifacts/<version>-<row>/`.

The tool prints `PACKAGE_MD5=<version> <md5>` for the zip under test.

Exit 0 when every row passed. Exit 2 when a row failed or the environment blocked it. Exit 1 when the script broke before or outside a test.

## In CI

The `Package checks` workflow (`.github/workflows/package-checks.yml`) runs this tool and `verify-package-matches-source` on each `https://wordpress.org/wordpress-<version>.zip`, as two jobs per zip that run in parallel. Start it by hand with `versions`, a comma-separated list such as `7.1.5,7.0.6`. It refuses X.Y and X.Y.0 versions, because it does not pass `--previous`: test those locally. The `package-smoke` job uploads the report, its screenshots and the log as an artifact.

## How it works

- Hashes the package zip and compares the md5 with the one published at `<zip URL>.md5`. A different or unreadable published md5 fails the md5 row, so a URL from a host with no `.md5` files always fails. Download such a zip and pass its local path. A local `--package` has no published md5, so the comparison is `n/a`.
- Fetches the package's `X.Y` branch and trunk from the checkout's origin. The `X.Y` branch, as a temporary worktree, supplies the Docker environment (`env:start`, `env:cli`, `env:clean`). Trunk's `.version-support-php.json` supplies the newest PHP version for that line.
- Runs `npm ci`, starts the environment, and serves the unzipped package from `package/` in the worktree.
- Fresh install: `wp core install` on the package, then checks `wp core version`, the home page, and the login form, then runs `functional-smoke.js`.
- Upgrade: installs the previous zip, then `wp core update` with the package zip and `wp core update-db`, and runs the same checks.
- A must-use plugin turns off automatic core updates. A row is env-blocked if `version.php` or `wp-settings.php` changed during the run.
- `--locales` downloads `downloads.wordpress.org/release/{locale}/wordpress-X.Y.Z.zip` and checks that its `$wp_version` and `$wp_local_package` match.
- Removes the environment, worktree, and temporary files at the end.

## Requirements

- PHP 8.1 or later with the zip extension and `allow_url_fopen` on.
- Git, Node and npm, and Docker.
- A clean clone of `WordPress/wordpress-develop` with `origin` pointing at GitHub.
- The `playwright` package outside that clone, with Chromium installed. Pass its directory, `node_modules/playwright`, as `--playwright-module`:

  ```sh
  mkdir playwright-module && cd playwright-module
  npm init -y && npm install playwright && npx playwright install chromium
  ```

## Tests

```sh
php tests/package-smoke-tests.php
```

Offline: no network, no subprocesses.

## License

Free software, like WordPress: GNU General Public License version 2 or (at your option) any later version. See [LICENSE](LICENSE).
