<?php

declare(strict_types=1);

/** Default opening for the published-pages reply. */
const HELPHUB_REPLY_TITLE = 'HelpHub pages are published';

/** Most versions one reply covers; the REST lookup reads one page of 100 results. */
const HELPHUB_REPLY_MAX_VERSIONS = 50;

/** Say why each version is not live for readers; an empty list means all are. */
function helphub_reply_problems(array $versions, array $posts, callable $public_status): array {
	$by_slug = array();
	foreach ($posts as $post) {
		if (is_array($post) && is_string($post['slug'] ?? null)) {
			$by_slug[$post['slug']][] = $post;
		}
	}
	$problems = array();
	foreach ($versions as $version) {
		$matches = $by_slug[helphub_version_slug($version)] ?? array();
		$url     = helphub_version_url($version);
		if (!$matches) {
			$problems[] = "{$version}: not published (no public page at {$url})";
			continue;
		}
		if (1 < count($matches)) {
			$problems[] = sprintf('%s: %d published pages share its slug', $version, count($matches));
			continue;
		}
		if ($url !== ($matches[0]['link'] ?? '')) {
			$problems[] = "{$version}: published at " . ($matches[0]['link'] ?? 'no link') . ", not {$url}";
			continue;
		}
		$status = $public_status($url);
		if (0 === $status) {
			$problems[] = "{$version}: {$url} could not be read (redirect or no response); stopped checking the rest";
			break;
		}
		if (200 !== $status) {
			$problems[] = "{$version}: {$url} returned HTTP {$status} to a reader";
		}
	}

	return $problems;
}

/** The Slack reply body: one line, each version linked to its page, newest first. */
function helphub_reply_body(array $versions, string $title): string {
	$links = array_map(
		static fn(string $version): string => sprintf('[%s](%s)', $version, helphub_version_url($version)),
		$versions
	);

	return ':white_check_mark: ' . $title . ': ' . implode(', ', $links);
}

/** Normalize a reply title, refusing one that is empty or spans lines. */
function helphub_reply_title(?string $title): string {
	if (null === $title) {
		return HELPHUB_REPLY_TITLE;
	}
	$clean = rtrim(trim($title), ': ');
	if ('' === $clean || 1 === preg_match('/[\r\n]/', $clean)) {
		throw new InvalidArgumentException('--title must be one non-empty line.');
	}

	return $clean;
}
