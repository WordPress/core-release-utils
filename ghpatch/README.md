# ghpatch

Applies a GitHub pull request's diff to a Subversion working copy with
`svn patch`.

## Usage

```zsh
source ghpatch/ghpatch.zsh
ghpatch 12345
ghpatch 12345 WordPress/wordpress-develop
```

The repository argument defaults to `WordPress/wordpress-develop`.

## What it checks

Before applying anything, `ghpatch` confirms, all via `gh`:

- The PR number is numeric.
- The repository exists.
- The PR exists in that repository.

Any of these failing stops the function before it touches the working copy.

## Why `svn patch` instead of `patch -p1`

GNU `patch -p1` writes a new file's contents to disk but never versions it.
`svn commit` then omits that file silently, so the PR's new files vanish
while its modified files land.

`svn patch` schedules new files for add itself, and strips the diff's `a/`
and `b/` prefixes on its own, so no `--strip` argument is needed.

## Why it fails loudly

`svn patch` exits 0 even when it rejects a hunk or reports a conflict
summary. `ghpatch` checks its output for those cases and returns 1 instead,
so a partial apply is never mistaken for a clean one.

## Exit codes

- `0`: the patch applied cleanly.
- `1`: otherwise, including a missing PR number, an unknown repository, a
  missing PR, or a rejected hunk.

## Requirements

- zsh 5 or later.
- The `svn` command-line client.
- The `gh` command-line client, logged in.

## Tests

```zsh
zsh ghpatch/tests/ghpatch-tests.zsh
```

## Provenance

Vendored on 2026-07-26 from
[Peter Wilson's gist](https://gist.github.com/peterwilsoncc/ceca0f962d9eb2416c09f89d695a19ad),
with his permission, and modified since: it applies with `svn patch` instead
of `patch -p1`, fails on a rejected hunk, and creates its temporary file with a
`mktemp` template that GNU and BSD `mktemp` both accept, so it runs on Linux as
well as macOS.

## License

GNU General Public License version 2, or (at your option) any later version.
See [LICENSE](LICENSE).
