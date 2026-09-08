# svnmergecheck

Checks that a `svn merge --record-only` actually recorded the revisions you
meant it to, before you commit.

## What it checks

After `svn merge --record-only -c <revs> '^/trunk'`, `svn:mergeinfo` on `.`
should carry every trunk revision you named. `svnmergecheck` reads that
property and confirms it, so you catch a missed or partial record-only merge
before the commit goes out, not after.

## Usage

```zsh
source svnmergecheck/svnmergecheck.zsh
svnmergecheck 62859 62862
```

Run it from the branch root, after the record-only merge and before
`svn commit`.

## What it prints

- The repository UUID and working-copy URL, so a rehearsal working copy can't
  be mistaken for a real one.
- Any of the named revisions missing from `svn:mergeinfo` on `.`, plus the
  record-only merge command to fix it.
- When every revision is present, a warning to commit the whole working copy,
  not a path list. `svn commit somefile.php` omits `.` from the commit, which
  drops the mergeinfo even though the fix content lands.

That last warning covers a real failure:
[r62873](https://core.trac.wordpress.org/changeset/62873) claims to merge
[r62859](https://core.trac.wordpress.org/changeset/62859) into a branch, but
its file list has no `.`, so the mergeinfo was never written. The fix content
was there; only the provenance was missing, and nothing downstream noticed.

## Exit codes

- `0`: every named revision is recorded in `svn:mergeinfo` on `.`.
- `1`: otherwise, including no arguments given or the current directory is not
  a Subversion working copy.

## Requirements

- zsh 5 or later.
- The `svn` command-line client.

## Tests

```zsh
zsh svnmergecheck/tests/svnmergecheck-tests.zsh
```

The test builds a scratch Subversion repository with `svnadmin` and separately
drives the function against a fake `svn` on `PATH` to exercise mergeinfo
shapes a real repository cannot easily produce; neither needs network access.
It runs in CI on every push and pull request that touches a `.zsh` file.

## License

GNU General Public License version 2, or (at your option) any later version.
See [LICENSE](LICENSE).
