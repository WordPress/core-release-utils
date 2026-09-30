# verify-localized-packages

Checks that the localized WordPress packages exist on `downloads.wordpress.org` after a core release: the core language pack and the localized full zip, for each locale and version.

## Usage

```sh
php verify-localized-packages.php --versions=7.1.2,7.0.6,6.9.9                   # poll until every package exists, or 3 hours pass
php verify-localized-packages.php --versions=7.1.2 --locales=it_IT,fr_FR --timeout=0   # check two locales once
```

Pass the newest version on each branch in the release. Options: `--locales=LIST`, `--timeout=3h`, `--interval=5m`. Durations take `s`, `m`, or `h`. `--timeout=0` checks once.

Exit 0 when every file exists. Exit 2 when a file is missing or could not be checked: report it in #meta-i18n. Exit 1 when the script broke, a version has no expected locales (check it, or pass `--locales`), or a lookup for the expected locales failed three times.

## How it works

- A meta.wordpress.org job builds the files 1–3 hours after the en_US package. It fails silently for some locales.
- The expected locales for X.Y.Z are the locales on the translations API that have a localized zip for an earlier version on the branch: X.Y or any patch before X.Y.Z. A locale below the translation thresholds has no zip and is not expected. `--locales` replaces this list. An X.Y or X.Y.0 release has no earlier version, so it needs `--locales`.
- For each expected locale, it sends a HEAD request to `translation/core/X.Y.Z/{locale}.zip` and `release/{locale}/wordpress-X.Y.Z.zip`, and checks again only the files that are still missing.
- It does not use the version-check API, which offers a localized package only for the newest release.

## Requirements

PHP 8.1 or later, with `allow_url_fopen` on.

## Tests

```sh
php tests/verify-localized-packages-tests.php
```

Offline: no network.

## License

Free software, like WordPress: GNU General Public License version 2 or (at your option) any later version. See [LICENSE](LICENSE).
