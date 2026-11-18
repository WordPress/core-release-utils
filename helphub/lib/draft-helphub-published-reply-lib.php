<?php

declare(strict_types=1);

/** Default opening for the published-pages reply. */
const HELPHUB_REPLY_TITLE = 'HelpHub pages are published';

/** Parse a comma list of versions, newest first, once each. */
function helphub_reply_versions(string $list): array {
	$versions = array();
	foreach (explode(',', $list) as $version) {
		$version = trim($version);
		helphub_version_slug($version);
		$versions[$version] = true;
	}

	return helphub_sort_versions(array_keys($versions));
}

/** Say why each version is not live at its expected address; an empty list means all are. */
function helphub_reply_problems(array $versions, array $posts): array {
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
		} elseif (1 < count($matches)) {
			$problems[] = sprintf('%s: %d published pages share its slug', $version, count($matches));
		} elseif ('publish' !== ($matches[0]['status'] ?? '')) {
			$problems[] = "{$version}: status is " . ($matches[0]['status'] ?? 'unknown') . ', not publish';
		} elseif ($url !== ($matches[0]['link'] ?? '')) {
			$problems[] = "{$version}: published at " . ($matches[0]['link'] ?? 'no link') . ", not {$url}";
		}
	}

	return $problems;
}

/** The Slack reply body: one line, each version linked to its page, newest first. */
function helphub_reply_body(array $versions, string $title): string {
	$links = array();
	foreach ($versions as $version) {
		$links[] = sprintf('[%s](%s)', $version, helphub_version_url($version));
	}

	return ':white_check_mark: ' . rtrim(trim($title), ':') . ': ' . implode(', ', $links);
}
