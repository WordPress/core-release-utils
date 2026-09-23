# AGENTS.md

Rules specific to `helphub/`.

## Why this directory needs its own rules

`push-helphub-drafts.php` writes to wordpress.org, a live public site, over the
REST API. Creating or editing drafts requires `--execute`; the default is a dry
run. Other tools are read-only against the network. No tool merges PRs or
publishes drafts. HelpHub needs additional safeguards because its drafts live
on the publishing site.

That is deliberate. It is also the reason for every rule below.

## Hard rules

- **Never give the push tool the ability to publish.** `HELPHUB_DRAFT_STATUS` is
  a `const`, not an option, and it is the only status any write sends. Keep it
  that way. Under embargo, publishing early discloses unpatched vulnerabilities
  to the world, so "the tool cannot do it" is worth more than the convenience.
- **Never read the application password into a log, an argument, or output.** It
  comes from the `HELPHUB_APP_PASSWORD` environment variable and is held in a
  static, because PHP prints scalar arguments in stack traces. The credential
  reaches every wordpress.org site the account can access, not just
  Documentation, and it bypasses two-factor by design. Revoke it after a release.
- **Never regenerate a page a human has started reviewing.** Regeneration
  overwrites whatever the reviewer changed. Use `--replace`, which swaps one
  exact string and refuses a zero or ambiguous match.
- **Never widen `--site`.** The tool constrains itself by an explicit `--site`, a
  REST base that exists only on Documentation, and a refusal to follow
  redirects. A typo there is the real risk surface, not the API.

## Absence is not evidence

Three of the five defects found field-testing this pipeline were the same
mistake: treating a missing thing as proof of something.

- A checker translated a page's paths back before comparing, so a wrong path
  compared equal to itself.
- A page rendered correctly in the editor without block delimiters, which said
  nothing about the front end—WordPress only loads a block's styles when it
  renders that block.
- A branch list ran out at the support floor, and the generator read that
  emptiness as "older versions are unaffected." Nobody had checked them.

When the generator cannot derive a statement, it must emit a visible fill-in
and exit non-zero. It must never guess, and it must never treat "I found
nothing" as "there is nothing."

## Verify against the real system

Editor previews, REST payloads, and control tests have each been wrong here.
A published page read in a real browser is the ground truth for anything
reader-facing. For fix presence, read the branch diff—commit trailers, file
paths, and Slack messages have all produced false answers in this work.

## The versions index

`check-helphub-index.php` and `draft-helphub-index-rows.php` read the two index
articles and emit rows for a human to paste. Both are read-only, take no
credential, and have no code path that writes anywhere.

- **A wave-scoped check cannot see accumulated drift.** Each of the four waves
  that went missing looked fine on its own day. `--release` therefore reports
  drift outside its wave even though only the wave fails the run.
- **Roadmap rows are not shipped versions.** The first article opens with a
  Planned Versions table whose rows look exactly like index rows. Reading the
  article row by row reports 7.1 and 7.2 as both indexed and orphaned.
- **Parse the first cell of each row.** Counting `href` matches gives 468 on a
  page with 820 rows, because some rows carry the anchor on the word
  "Changelog". Stripping tags first runs the version into the date, so no word
  boundary survives.
- **Search relevance is not a match.** Searching the news API for `7.0.2`
  returns a beta post that mentions it in passing ahead of the release post
  published that day. Rank titles above bodies, and reject any post published
  before the release.
- **Bound the version match on digits, not on dots.** Rejecting every trailing
  dot stops `7.0.4` matching `7.0.41`, and also silently drops the most common
  way a post writes one: "fixed in WordPress 7.0.2."
- **A wave post is invisible when its lead version is already indexed.** Twenty
  backports share the 6.9.2 post, which names none of them. Confirm the page
  carries that post's fix set rather than trusting date proximity: a maintenance
  release shipped between the security release and its backports.
