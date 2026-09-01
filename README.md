# Core release utils

Utilities and helpers for WordPress core releases. These are the small tools release coordinators and committers reach for when shipping a release: generating credits, preparing posts, and similar chores.

## Scope

This repo holds release tooling that is not tied to a security embargo. Anything that must stay private until a release ships does not belong here.

## Layout

Each tool lives in its own top-level directory. There is no shared `tools/` root. Every tool directory has its own README with setup and usage.

## Tools

- `wp-profile-link-generator/`: takes a list of WordPress.org usernames and produces the linked contributor list for a minor release post, plus the updated credits array for the credits API.

## Contributing

Open a pull request against `trunk`.

## License

GPLv2 or later. See [LICENSE](LICENSE).
