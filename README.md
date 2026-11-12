# Core release utils

Tools and helper files for improving WordPress core releases. These are the utilities release coordinators and committers reach for when shipping a release: generating credits and "Props", preparing blog posts and documentation, and other chores.

## Scope

This repo holds core WordPress release tooling that is not tied to a security embargo. Anything private should be saved elsewhere.

## Tools

- `helphub/`: drafts and checks HelpHub release pages, credits, and versions index rows using a release manifest and checkout.
- `wp-profile-link-generator/`: takes a list of WordPress.org usernames and produces the linked contributor list for a minor release post, plus the updated credits array for the credits API.
- `verify-tags-reached-mirror/`: checks that release tags on `core.svn` reached the `WordPress/WordPress` Git mirror. `--tags` polls for a release's tags as a gate; `--audit` checks every tag once.
- `verify-localized-packages/`: checks that the core language pack and the localized zip exist for each locale after a release. Polls as a gate, and names the missing locales to report in #meta-i18n.
- `svn-tags/`: generates or verifies release tag commands from SVN branch versions, checking for existing tags, unbumped versions, and recent commits before tagging. Audits landed tags too.
- `svnmergecheck/`: verifies that `svn merge --record-only` recorded every named trunk revision in `svn:mergeinfo` before you commit, and warns to commit the whole working copy rather than a path list.
- `package-smoke/`: smoke-tests one built WordPress zip in a Docker site, as a fresh install and as an upgrade from the previous release, with a Playwright walkthrough of each.
- `verify-package-matches-source/`: checks that a built WordPress zip matches the `src/` files of its wordpress-develop tag, apart from the changes the build makes on purpose, and names each file that differs.
- `ghpatch/`: applies a GitHub pull request diff to a Subversion working copy with `svn patch`, so new files are scheduled for add, and fails loudly on a rejected hunk.

Shared PHP helpers that more than one tool uses live in `lib/lib.php`, with tests in `lib/tests/`.

## Release check workflows

Three GitHub Actions workflows run the release checks. The release lead starts each one by hand from the Actions tab, once per phase, with that phase's versions:

- `Package checks`: after the zips are live. Runs `package-smoke` and `verify-package-matches-source` on each zip.
- `Tags reached mirror`: after the SVN tags are created. Runs `verify-tags-reached-mirror --tags`.
- `Localized packages`: after auto-updates are on. Runs `verify-localized-packages`.

Each tool's README lists the workflow inputs.

## Contributing

Open a pull request against `trunk`.

## License

Licensing is per tool. Check each tool's directory for the license that covers it.
