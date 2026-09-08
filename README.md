# Core release utils

Tools and helper files for improving WordPress core releases. These are the utilities release coordinators and committers reach for when shipping a release: generating credits and "Props", preparing blog posts and documentation, and other chores.

## Scope

This repo holds core WordPress release tooling that is not tied to a security embargo. Anything private should be saved elsewhere.

## Tools

- `wp-profile-link-generator/`: takes a list of WordPress.org usernames and produces the linked contributor list for a minor release post, plus the updated credits array for the credits API.
- `verify-tags-reached-mirror/`: checks that release tags on `core.svn` reached the `WordPress/WordPress` Git mirror. `--tags` polls for a release's tags as a gate; `--audit` checks every tag once.

## Contributing

Open a pull request against `trunk`.

## License

Licensing is per tool. Check each tool's directory for the license that covers it.
