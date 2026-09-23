<?php

declare(strict_types=1);

/**
 * Request building and body editing for push-helphub-drafts.php.
 *
 * Separated from the CLI entry point so the test suite can exercise them
 * without a network or a credential, the way every other tool in this
 * repository pairs a script with its `-lib.php`.
 */

/** The only status this tool will ever send. Publishing stays a human action. */
const HELPHUB_DRAFT_STATUS = 'draft';

/** The custom post type's REST base on the documentation site. */
const HELPHUB_REST_BASE = 'wp/v2/wordpress-versions';

/**
 * Build the request body for a new draft.
 *
 * Status is a constant, not a parameter. A caller cannot ask this to publish,
 * which is the point: every other tool here is read-only, and the one that
 * writes should only be able to write the reversible thing.
 *
 * @return array<string,string>
 */
function helphub_draft_payload(string $version, string $content): array {
	if ('' === trim($content)) {
		throw new InvalidArgumentException("Refusing to create an empty page for {$version}.");
	}

	return array(
		'title'   => "Version {$version}",
		'slug'    => helphub_version_slug($version),
		'status'  => HELPHUB_DRAFT_STATUS,
		'content' => $content,
	);
}

/**
 * Apply one exact replacement to a page body.
 *
 * Read, modify, write: the REST API exposes `content` as a single field, so a
 * targeted edit means replacing a known string inside the body a human may have
 * revised since it was created.
 *
 * The anchor must appear exactly once. Zero matches means the page is not what
 * the caller thinks it is; several means the edit is ambiguous. Both fail rather
 * than guess, because the whole reason to edit rather than regenerate is to
 * leave a reviewer's work intact.
 */
function helphub_apply_replacement(string $body, string $search, string $replace): string {
	if ('' === $search) {
		throw new InvalidArgumentException('Replacement anchor cannot be empty.');
	}
	$count = substr_count($body, $search);
	if (0 === $count) {
		throw new RuntimeException("Anchor not found in the page: {$search}");
	}
	if (1 < $count) {
		throw new RuntimeException("Anchor appears {$count} times; it must be unique: {$search}");
	}

	return str_replace($search, $replace, $body);
}

/**
 * Describe what a run would do, for the dry run and the summary.
 *
 * @param array<string,string> $payload
 */
function helphub_describe_create(string $version, array $payload): string {
	return sprintf(
		'create draft "%s" at slug %s (%d bytes of content)',
		$payload['title'],
		$payload['slug'],
		strlen($payload['content'])
	);
}

/**
 * Confirm a drafts directory belongs to the release being pushed.
 *
 * Every sibling tool checks that the artifacts it was handed match the release
 * it was told about. This one writes to wordpress.org, so it has the most to
 * lose from acting on the wrong wave's pages.
 */
function helphub_verify_release_stamp(string $directory, string $release): void {
	$stamp = rtrim($directory, '/') . '/.release';
	if (!is_file($stamp)) {
		throw new InvalidArgumentException(
			"No .release stamp in {$directory}. Regenerate the drafts with "
			. 'draft-helphub-version-pages.php so the release is recorded.'
		);
	}
	$found = trim((string) file_get_contents($stamp));
	if ($found !== $release) {
		throw new InvalidArgumentException(
			"These drafts are for WordPress {$found}, but --release says {$release}."
		);
	}
}

/**
 * Narrow a set of candidates to what a run should touch.
 *
 * A `--only` that matches nothing is the dangerous case. Skipping every
 * iteration leaves a run that reports zero pages touched and exits clean, which
 * reads exactly like a successful no-op. An operator who mistyped a version, or
 * named one the listing never returned, would believe the edit landed. So an
 * unmatched filter is an error, not an empty result.
 *
 * @param string[] $available
 * @return string[]
 */
function helphub_select(array $available, ?string $only): array {
	if (null === $only) {
		return $available;
	}
	$selected = array_values(array_filter(
		$available,
		static fn(string $candidate): bool => $candidate === $only
	));
	if (!$selected) {
		throw new RuntimeException(sprintf(
			'--only=%s matched nothing. Available: %s',
			$only,
			$available ? implode(', ', $available) : '(none)'
		));
	}

	return $selected;
}

/**
 * Refuse a site URL that would carry the credential in the clear.
 *
 * Basic auth over plaintext hands the password to anyone on the path, and no
 * later check can undo a first hop that was never encrypted. This runs before
 * any request, not after one has failed.
 */
function helphub_require_https(string $site): string {
	if (0 !== stripos($site, 'https://')) {
		throw new InvalidArgumentException(
			"--site must be an https:// URL. Refusing to send a credential to: {$site}"
		);
	}

	return rtrim($site, '/');
}

/**
 * The REST endpoint for a collection or a single post.
 */
function helphub_rest_url(string $site, ?int $id = null, array $query = array()): string {
	$base = rtrim($site, '/') . '/wp-json/' . HELPHUB_REST_BASE;
	if (null !== $id) {
		$base .= '/' . $id;
	}
	if ($query) {
		$base .= '?' . http_build_query($query);
	}

	return $base;
}
