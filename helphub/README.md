# HelpHub tools

Five tools that produce and check a release's HelpHub version pages: preview,
credits, draft, check, push. Run in that order, they mirror the backport
pipeline in [the private toolkit](https://github.com/WordPress/private-security-utils):
scope, generate, validate, then a human edits and publishes. Three more check
live credits and keep the versions index current.

`push-helphub-drafts.php` is the only tool in this repository that writes to a
live public site. It cannot publish—only create and edit drafts.

## Where the manifest comes from

The private toolkit's `export-helphub-manifest` reads the release scope and the
security checkout and writes `helphub-manifest-<release>.json`: the release,
each target branch line with its backport branch, commit, and base, the fixes
that reached each line, the authored credit lines, and the advisory for each
fix. It carries embargoed content before a release and is never committed
anywhere.

The tools that read a checkout refuse a branch whose tip differs from the
commit the manifest records. Fetch the checkout or regenerate the manifest
before retrying. A target the export could not resolve is `null`, and its file
list stays unverified.

Credits and advisories are authored in the private scope. Export again after
editing them.

## Scoping HelpHub version pages

On a release touching two dozen branches, the first question is which pages it
needs. Until this existed that list was typed by hand into the generator's
`--versions`, which is the same hand-transcription these tools exist to remove.

```sh
php helphub/preview-helphub-version-pages.php \
  --release=7.0.3 \
  --manifest=/path/to/helphub-manifest-7.0.3.json \
  --checkout=/path/to/wordpress-develop-security
```

```
  branch  would be     fixes  files   credits to write
  7.0     7.0.3        9      11      1066, 1231, 1242, ...
  6.9     6.9.6        9      11      1066, 1231, 1242, ...
  6.8     6.8.7        7      8       1066, 1231, 1245, ...
```

The version each branch would publish is read from its own `$wp_version`. A
branch still on its last release publishes the next patch number; one already
bumped to an alpha publishes the version that alpha names. The output says which
branches are in which state, so the derived list can be checked rather than
trusted.

It then prints the `--versions` argument for the generator. **Trim it to the
versions the release actually publishes.** Receiving a backport is not the same
as shipping a release: the July 2026 wave prepared sixteen branches and
published three, and only the release manager knows which.

### After the release

```sh
php helphub/preview-helphub-version-pages.php ... --check-published
```

Asks wordpress.org which pages exist. A version that shipped without a page is
the failure this catches, and it is the one that produces silence rather than an
error. Before the release every page is missing, which is expected.

## Drafting HelpHub version pages

The documented process for these pages is to duplicate the previous release's
page and edit it. That is what produces the errors: a typo entered once and
copied onto all 23 pages of a wave, a credit corrected on one page and not its
siblings, a file list carried over from the release before.

This writes the drafts instead, so those errors cannot occur.

```sh
php helphub/draft-helphub-version-pages.php \
  --release=7.0.3 \
  --manifest=/path/to/helphub-manifest-7.0.3.json \
  --checkout=/path/to/wordpress-develop-security \
  --versions=7.0.3,6.9.6,6.8.7 \
  --date=2026-08-06 \
  --out=/path/to/drafts
```

Review each draft, then paste it into the HelpHub editor. The tool writes files
and nothing else—publishing stays a human action.

`--versions` names what actually shipped, and is an argument rather than a
derivation. A branch that received the backport did not necessarily ship a
release: the July 2026 wave prepared sixteen branches and released three.

### Credit lines

Each fix needs one authored credit line in the manifest's `credits` block, keyed
by fix number and used verbatim:

```json
"credits": {
  "1314": "A login XSS leading to remote code execution reported by <a href=\"https://example.test\">Someone</a>"
}
```

A credit line is two halves joined:

```
A REST API batch-route confusion and SQL injection issue leading to Remote Code
Execution reported by Adam Kues at Assetnote / Searchlight Cyber
\_____________________ advisory summary ______________________/
                                          \____ attribution ____/
```

Draft the first half rather than retyping it. Record each fix's advisory in the
private toolkit’s scope, export the manifest, and run:

```sh
php helphub/draft-helphub-credits.php \
  --manifest=/path/to/helphub-manifest-7.0.3.json
```

```json
"advisories": {
  "1266": "GHSA-ff9f-jf42-662q"
}
```

It reads each advisory's summary and prints a `credits` block to paste into the
scope, with attribution left as a marker. On the published 7.0.2 page that summary is the
credit line word for word, so retyping it only creates a chance to get a correct
sentence wrong. Draft advisories are readable before publication, so this works
during an embargo.

It prints rather than edits. A tool that rewrites a release's own scope file
mid-release is a bad trade for the typing it saves.

**The attribution stays human.** Both 7.0.2 advisories carry an empty `credits`
array, because reporters arrive through HackerOne and are not GitHub users. No
field records whether researchers worked as a team or found an issue
independently, and getting that wrong misrepresents them. A fix with no authored
credit produces a visible `FILL IN` marker and a non-zero exit, never a guess and
never silence.

## Putting drafts into HelpHub

Every other tool here is read-only. This one writes, so it is the narrowest
thing that does the job.

```sh
export HELPHUB_APP_PASSWORD='…'   # a wordpress.org application password
php helphub/push-helphub-drafts.php \
  --site=https://wordpress.org/documentation \
  --user=<wporg login> \
  --release=7.0.3 \
  --create --pages=/path/to/drafts \
  --only=7.0.3          # start with one, look at it, then drop --only
```

Nothing is written without `--execute`. The default is a dry run.

### What it cannot do

**It cannot publish.** The status it sends is a constant, not an option. There
is no flag, and no code path that sets any other value. A draft is not public:
the REST API refuses a non-public status to an unauthenticated caller and there
is no preview URL that works without a session.

**It cannot overwrite a reviewed page.** Creating refuses a version that already
has a page in any status. After a create it compares the slug that came back
against the one requested, because a page appearing between the check and the
write gets a suffixed slug rather than overwriting—and that duplicate would
otherwise be invisible to every later run.

**It cannot leak the credential.** The application password is read from the
environment, never accepted as an argument, and held in a static rather than
passed between functions, because PHP prints scalar arguments in stack traces.
Redirects are refused: PHP's stream wrapper follows them by default and
re-sends the `Authorization` header to whatever host the `Location` names.
`--site` must be https.

### Editing after the fact

```sh
php helphub/push-helphub-drafts.php \
  --site=… --user=… --release=7.0.3 \
  --replace='On August 6, 2026' --with='On August 13, 2026'
```

Targeted, not regenerated, because regenerating destroys whatever a reviewer
changed after the page was created. The anchor must appear exactly once per
page: zero matches means the page is not what you think it is, several means
the edit is ambiguous. Both fail rather than guess. Each page's status is
re-read immediately before writing, so a page published mid-run is left alone.

## Checking live credits

Drafting and checking pages happens before a release ships. Once it does, a
human can still hand-edit a live page, and the 7.1.1 news post once credited
fix #1294 to a different reporter than all 25 HelpHub pages did—nothing
compared the two. This checks a release's published (and, with a credential,
drafted) version pages against the manifest's authored credits.

```sh
php helphub/check-helphub-credits.php \
  --release=7.0.3 \
  --manifest=/path/to/helphub-manifest-7.0.3.json
```

Run it right before publishing, against drafts, with `--user` and
`HELPHUB_APP_PASSWORD` set so it can read them. Run it again after, without a
credential, against what actually went live. Each version's credit lines are
compared as a set of plain text, not an ordered list, so reordering a page's
bullets is not a mismatch. A manifest credit still carrying a `FILL IN` marker is
always a mismatch, on every page it reaches, because there is nothing to
verify it against.

Without a credential, a branch the release wave should have reached but this
run cannot find is reported unreadable, never missing and never a pass—it
might be a draft nobody can see yet.

A mismatch this tool finds is fixed by a human: push-helphub-drafts.php's
`--replace` for a draft, a manual edit for a live page. A live-page writer was
deliberately left out of this tool until bullets can be located by exact
source span rather than a positional or search-string lookup.

Add `--news-post=<URL or file>` to check reporter names and GHSA IDs in a news
post against the required `--release` and `--manifest`. Supply an HTML/plain-text
file or an `https://wordpress.org/news/...` URL. This mode makes no HelpHub
requests, needs no credentials, and refuses `--only` and `--user`. Reporters are
searched only within the security section; a missing section makes every
reporter MISSING. GHSA IDs are searched across the whole post. Exit codes match
HelpHub mode: findings and manifest/validation errors exit 2; usage and runtime
read failures exit 1. Within a security section, credits without “reported by”
are UNCHECKED; null advisories are skipped. Neither fails the check.

## Checking HelpHub version pages

Every release gets a "WordPress Version" page on wordpress.org, created by
duplicating the previous release's page and editing it. A security wave
produces one per supported branch—23 of them in March 2026—all drafted on
release day. Duplication is what produces the errors this tool looks for.

```sh
php helphub/check-helphub-version-pages.php \
  --release=7.0.3 \
  --manifest=/path/to/helphub-manifest-7.0.3.json \
  --checkout=/path/to/wordpress-develop-security \
  --pages=/path/to/pages
```

`--pages` is a directory of page bodies, one file per version, named for the
version it documents: `7.0.3.txt`, `6.9.6.txt`. Either shape works. Paste a
draft out of the HelpHub editor, or save the published body:

```sh
curl -s "https://wordpress.org/documentation/wp-json/wp/v2/wordpress-versions?slug=version-7-0-2" \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)[0]["content"]["rendered"];' \
  > pages/7.0.2.txt
```

Exit codes match the rest of this repository: `0` clean, `1` operational
failure, `2` needs human attention.

### What it checks

1. **Credit lines shared across the release are identical everywhere.** Two
   pages carrying near-identical credit lines is a correction that reached one
   page and not the others.
2. **No branch has two pages, and no page is a stray.** A manifest target is a
   branch that received the backport, which is not the same as a branch that
   shipped a release: the July 2026 wave prepared sixteen branches and released
   three. So a target with no page is reported as unverified, not as a defect.
   It still reaches you—a page nobody created is silence rather than an
   error—but it is stated as something the check could not confirm.
3. **Each page's revised-file list matches what that branch actually shipped**,
   read from the backport branch the manifest records for that line. A directory
   path where a file path belongs matches nothing, so it surfaces here too.

   The branch is the source, not the fixes' pinned references, because a fix
   does not keep the same shape on every branch. On 4.7 the Quick Edit fix lives
   in `src/wp-admin/js/inline-edit-post.js` where trunk has
   `src/js/_enqueues/admin/inline-edit-post.js`, and comparing against the
   trunk-side reference reports that one file as both missing and unexpected.

   **Fetch the checkout before running.** A stale copy of a backport branch
   produces a confidently wrong file list. The output names the branch and
   commit each comparison read so you can check it against the open pull
   request. A different tip is refused. If the branch or base is not in the
   checkout, the file list stays unverified.

4. **Repeated words and known misspellings.** Checks 1 to 3 compare pages
   against each other or against the release, so all three are blind to a
   mistake copied identically everywhere. "overridding" reached all 23 March
   2026 pages and is still published; "this is is not" is live on 6.9.3. This is
   the only check that sees a consistent error.

5. **Links resolve.** Opt-in with `--check-links`, because everything else here
   is offline and deterministic, and release day is the wrong time to find out a
   checker needs the network. A link that cannot be reached at all is reported
   as unresolved, not broken: a network problem is not evidence a link is dead.

### What it does not check

Check 4 is a curated list, not a spellchecker. A dictionary would flag every
function name, path, and researcher's name in this prose, and this repository
takes no external dependencies. Anything that gets through belongs on the list
in `check-helphub-version-pages-lib.php` afterwards.

It reads pages from a directory rather than fetching them, because the pages
are drafts when they most need checking, and a draft is not public.

Nothing here writes to wordpress.org. There is no code path that could.

A generated page cannot carry a copy-propagated defect at all, which is a better
answer than detecting one. Prefer drafting over checking where you can.

## Keeping the versions index current

Two articles list every WordPress release ever shipped:

| Page | Post id | Covers |
|---|---|---|
| `/documentation/article/wordpress-versions/` | 14146007 | 5.0 onward |
| `/documentation/article/wordpress-versions-0-7-to-4-9/` | 16367941 | 0.7–4.9 |

They are the `articles` post type, not `helphub_version`, and nothing in the
release process updated them. On 2026-08-12 they were 74 rows behind, spanning
four security waves, and had been wrong in both directions for years.

Both tools here are read-only. Neither takes a credential and neither writes
anywhere. An editor pastes the rows, which is a minute per table.

### Check the index

Run this during a release, after the version pages are published and verified
and before the news post goes out.

```sh
php helphub/check-helphub-index.php --release=7.0.4
```

It exits non-zero only when a page from this wave is missing. Older drift and
rows pointing at pages that were never published are printed, because a check
scoped to one wave is exactly how the 74 stayed invisible—each wave looked fine
on its own day. They do not fail the run, because forty orphaned rows inherited
from years of history would make it red on every release forever, and a gate
that is always red is a gate nobody reads.

To reconcile the whole history instead, which fails on any finding:

```sh
php helphub/check-helphub-index.php --audit
```

### Generate the rows

```sh
php helphub/draft-helphub-index-rows.php --release=7.0.4 > rows.html
```

Every field comes from an authoritative source: the version from the published
page's slug, the date from that page's own opening sentence, the changelog from
its URL, and the announcement from a news post verified to name the version. The
DB version is left blank, matching every row added since 2026.

A field that cannot be verified is left blank with a note on stderr. A blank
cell is wrong in a way a reader can see; a wrong link is not.

### Paste them

Open the article, click the table, and choose **Edit as HTML** on that block
rather than the whole post, so a mistake is scoped to one table. Rows go
directly under the header row: every table is newest first.

Two rows sort mid-table rather than at the top (`4.2.26.2`, `3.9.36.2`), and
`1.6` has no table at all.

## Requirements

PHP 8.1 or later and git. `draft-helphub-credits.php` also needs an authenticated
`gh`. Push and authenticated reads need `HELPHUB_APP_PASSWORD` and `--user`.
HTTP reads and writes require `allow_url_fopen` on.

## Tests

```sh
php tests/helphub-tests.php
```

Offline: no network, no git, no subprocesses, no disk fixtures.

## License

Free software, like WordPress: GNU General Public License version 2 or (at your option) any later version. See [LICENSE](LICENSE).
