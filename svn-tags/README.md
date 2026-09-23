# svn-tags

Generates or verifies WordPress release tag commands using the version on each SVN branch. Dry-run by construction: it never runs `svn cp`.

## Usage

```sh
php svn-tags.php --generate --branches=6.5,6.4,6.3
php svn-tags.php --verify --branches=6.5,6.4,6.3 --file=commands.txt
php svn-tags.php --verify < commands.txt
```

Options: `--branches=X.Y,...`, `--file=path` (verify only), `--svn=URL` (default `https://develop.svn.wordpress.org`), and `--min-age=60s`. Durations accept whole seconds or a number with `s`, `m`, or `h`.

Generation requires branches and preserves their order. Verification reads stdin unless `--file` is given. Blank lines and lines starting with `#` are ignored.

Each input line must be `svn cp <branch> <tag> -m "<message>"`. Paths accept the configured repository URL or `^/`:

```sh
svn cp https://develop.svn.wordpress.org/branches/6.5 https://develop.svn.wordpress.org/tags/6.5.12 -m "Tag 6.5.12"
svn cp ^/branches/6.4 ^/tags/6.4.9 -m "Tagging WordPress 6.4.9."
```

Messages accept `Tag VERSION` and `Tagging WordPress VERSION`, each with an optional trailing period. Single or double quotes are accepted. Extra options and shell commands are rejected.

Both modes print a table to stderr: branch, exact version read, proposed tag, whether it exists, last revision, age in seconds, and verdict. Verification also reports each line's message form. Mixed forms are a finding; the example above would need consistent wording to pass.

Commands reach stdout only when the entire list passes. Generation uses `Tag VERSION`; verification rebuilds every accepted command with the checked absolute URLs and its original message. Findings and tool errors suppress all commands.

Exit 0 when all checks pass. Exit 2 for findings, including mixed message forms. Exit 1 for malformed input, an unreadable file, or a tool failure.

## How it works

- Reads `branches/X.Y/src/wp-includes/version.php` with `svn cat`, without executing PHP. The branch is the source of truth.
- Strips one final `-src` and requires `X.Y` or `X.Y.Z` belonging to that branch. Alpha, beta, RC, and revision suffixes fail.
- Reads the proposed tag with `svn info`. An existing tag fails because copying into it could nest the branch inside the tag.
- Treats only SVN path-not-found errors as absence. Authentication, connection, and server errors fail the tool.
- Reads `svn log --xml -q -l 1` for each branch and parses its revision and date with SimpleXML. Its last revision must be at least 60 seconds old by default, in both modes.
- Verification compares each destination with the branch version, checks messages, and rejects duplicate branches or tags. With `--branches`, it names missing and extra branches.

An unbumped version prevents generation of a tag name; its existence cell shows `-`. Branch checks still report the version and last commit. List-wide findings appear below the table.

These are point-in-time checks, not a repository lock. Branches or tags can change after checking. Rerun immediately before tagging; only the operator runs the copy commands. Relative `^/` paths are interpreted against `--svn` during verification and printed as absolute URLs, independent of the operator's working copy.

## Requirements

PHP 8.1 or later with SimpleXML, the `svn` command in PATH, and `proc_open` enabled for live reads. No PHP packages, GitHub access, or `allow_url_fopen` are needed. SVN runs non-interactively with English diagnostics.

## Tests

```sh
php tests/svn-tags-tests.php
php -l svn-tags.php
php -d allow_url_fopen=0 -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen tests/svn-tags-tests.php
```

Offline: no network, no SVN, no subprocesses. Fake SVN readers return command status, stdout, and stderr; a fixed clock makes commit-age checks deterministic.

## License

Free software, like WordPress: GNU General Public License version 2 or (at your option) any later version. See [LICENSE](LICENSE).
