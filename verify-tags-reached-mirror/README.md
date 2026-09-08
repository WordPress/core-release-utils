# verify-tags-reached-mirror

Checks that release tags on `core.svn.wordpress.org/tags/` reached the `WordPress/WordPress` Git mirror at the same SVN revision.

## Usage

```sh
php verify-tags-reached-mirror.php --tags=7.0.4,6.8.5   # poll until each tag reaches the mirror, or 45 minutes pass
php verify-tags-reached-mirror.php --audit              # check every tag on SVN once
```

Options: `--timeout=45m`, `--interval=60s`, `--svn=URL`, `--repo=OWNER/NAME`. Durations take `s`, `m`, or `h`.

Exit 0 when every tag is on the mirror at the SVN revision, or is a known permanent mismatch (3.1.3 and 3.6.1). Exit 2 when a human must look. Exit 1 when the script broke.

## How it works

- Reads the SVN revision of a tag with a WebDAV PROPFIND.
- Fetches the tag commit from the mirror into a scratch bare repository with `git fetch --no-tags --filter=tree:0 --depth=1`. No GitHub API, no token.
- Compares the revision in the commit's `git-svn-id` line.

## Requirements

PHP 8.1 or later with SimpleXML, git 2.20 or later, and `allow_url_fopen` on.

## Tests

```sh
php tests/verify-tags-reached-mirror-tests.php
```

Offline: no network, no git, no subprocesses.

## License

Free software, like WordPress: GNU General Public License version 2 or (at your option) any later version. See [LICENSE](LICENSE).
