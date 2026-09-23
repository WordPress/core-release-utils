<?php

declare(strict_types=1);

/**
 * Row and announcement generation for draft-helphub-index-rows.php.
 */

/**
 * A version token bounded from neighboring digits and dots.
 *
 * The trailing bound rejects a dot only when a digit follows it, so 7.0.4 still
 * cannot match inside 7.0.41 while a version ending a sentence still matches.
 * Rejecting every trailing dot silently dropped the most common way a post
 * writes one: "the issue was fixed in WordPress 7.0.2."
 */
function helphub_index_version_pattern(string $version): string {
	return '~(?<![0-9.])' . preg_quote($version, '~') . '(?![0-9])(?!\.[0-9])~';
}

/**
 * Select the strongest news-post match for one version.
 *
 * Title matches beat body mentions. A dated candidate published before the
 * release date is rejected. Body-only mentions must also land within seven
 * days; beyond that they are historical references, not announcements.
 *
 * @param array<int,array<string,mixed>> $posts
 * @return array{link:string,title_match:bool,date_key:?string}|null
 */
function helphub_index_rank_announcement_posts(
	string $version,
	?string $release_date,
	array $posts
): ?array {
	$pattern     = helphub_index_version_pattern($version);
	$release_key = helphub_index_date_key($release_date);
	$release_day = null === $release_key
		? null
		: new DateTimeImmutable($release_key, new DateTimeZone('UTC'));
	$candidates = array();

	foreach ($posts as $position => $post) {
		$link = isset($post['link']) && is_string($post['link']) ? $post['link'] : '';
		if ('' === $link) {
			continue;
		}
		$title = html_entity_decode(strip_tags((string) ($post['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$body  = html_entity_decode(strip_tags((string) ($post['content']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$title_match = 1 === preg_match($pattern, $title);
		$body_match  = 1 === preg_match($pattern, $body);
		if (!$title_match && !$body_match) {
			continue;
		}

		$post_key = null;
		if (isset($post['date']) && is_string($post['date'])
			&& 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', $post['date'], $date_match)) {
			$post_key = $date_match[0];
		}
		$post_day = null === $post_key ? null : new DateTimeImmutable($post_key, new DateTimeZone('UTC'));

		if (null !== $release_day) {
			// With a known release date, an undated post cannot prove that it did
			// not predate the release. Leave the cell blank rather than guessing.
			if (null === $post_day || $post_day < $release_day) {
				continue;
			}
			$days = (int) $release_day->diff($post_day)->format('%r%a');
			if (!$title_match && 7 < $days) {
				continue;
			}
		} elseif (!$title_match) {
			// A body mention with no release date cannot be distinguished from a
			// retrospective reference.
			continue;
		}

		$candidates[] = array(
			'link'        => $link,
			'title_match' => $title_match,
			'date_key'    => $post_key,
			'rank'        => $title_match ? 1 : 2,
			'distance'    => null === $release_day || null === $post_day
				? PHP_INT_MAX
				: abs((int) $release_day->diff($post_day)->format('%r%a')),
			'position'    => $position,
		);
	}

	if (!$candidates) {
		return null;
	}
	usort($candidates, static function (array $a, array $b): int {
		return array($a['rank'], $a['distance'], $a['position'])
			<=> array($b['rank'], $b['distance'], $b['position']);
	});
	$best = $candidates[0];

	return array(
		'link'        => $best['link'],
		'title_match' => $best['title_match'],
		'date_key'    => $best['date_key'],
	);
}

/**
 * Search terms broad enough to find a release post but still verified later.
 *
 * @return string[]
 */
function helphub_index_announcement_terms(string $version): array {
	return array_values(array_unique(array($version, helphub_index_series($version))));
}

/**
 * Find an exact announcement through an injected search callback.
 *
 * The callback is cached by term. Search relevance is never treated as a
 * match; every returned post is ranked and verified by the function above.
 *
 * @param array<string,array<int,array<string,mixed>>> $cache
 */
function helphub_index_exact_announcement(
	string $version,
	?string $release_date,
	callable $search,
	array &$cache
): ?string {
	$posts = array();
	$seen  = array();
	foreach (helphub_index_announcement_terms($version) as $term) {
		if (!array_key_exists($term, $cache)) {
			$result       = $search($term);
			$cache[$term] = is_array($result) ? $result : array();
		}
		foreach ($cache[$term] as $post) {
			$key = isset($post['link']) && is_string($post['link']) ? $post['link'] : md5(serialize($post));
			if (!isset($seen[$key])) {
				$posts[]    = $post;
				$seen[$key] = true;
			}
		}
	}
	$best = helphub_index_rank_announcement_posts($version, $release_date, $posts);

	return $best['link'] ?? null;
}

/**
 * Find the announcement for a row, including a verified wave fallback.
 *
 * The fallback considers every published page near the target date, not only
 * pages missing from the index. That matters when the lead version is already
 * indexed. A candidate must carry the same non-empty fix set before its post
 * can be reused, so a nearby maintenance release cannot win on date alone.
 *
 * @param array<string,array{version:string,date:?string,date_key:?string,fix_set:string[]}> $pages
 * @param array<string,string|null> $exact_cache
 * @param array<string,array<int,array<string,mixed>>> $search_cache
 * @return array{link:?string,source:?string}
 */
function helphub_index_announcement_for_page(
	string $version,
	array $pages,
	callable $search,
	array &$exact_cache,
	array &$search_cache
): array {
	$page = $pages[$version];
	if (!array_key_exists($version, $exact_cache)) {
		$exact_cache[$version] = helphub_index_exact_announcement(
			$version,
			$page['date'],
			$search,
			$search_cache
		);
	}
	if (null !== $exact_cache[$version]) {
		return array('link' => $exact_cache[$version], 'source' => 'exact');
	}
	if (null === $page['date_key'] || !$page['fix_set']) {
		return array('link' => null, 'source' => null);
	}

	$target_day = new DateTimeImmutable($page['date_key'], new DateTimeZone('UTC'));
	$candidates = array();
	foreach ($pages as $candidate_version => $candidate) {
		if ($candidate_version === $version || null === $candidate['date_key']) {
			continue;
		}
		if (!helphub_index_same_fix_set($page['fix_set'], $candidate['fix_set'])) {
			continue;
		}
		$candidate_day = new DateTimeImmutable($candidate['date_key'], new DateTimeZone('UTC'));
		$distance      = abs((int) $target_day->diff($candidate_day)->format('%r%a'));
		if (7 < $distance) {
			continue;
		}
		if (!array_key_exists($candidate_version, $exact_cache)) {
			$exact_cache[$candidate_version] = helphub_index_exact_announcement(
				$candidate_version,
				$candidate['date'],
				$search,
				$search_cache
			);
		}
		if (null !== $exact_cache[$candidate_version]) {
			$candidates[] = array(
				'version'  => $candidate_version,
				'link'     => $exact_cache[$candidate_version],
				'distance' => $distance,
			);
		}
	}
	if (!$candidates) {
		return array('link' => null, 'source' => null);
	}
	usort($candidates, static function (array $a, array $b): int {
		$distance = $a['distance'] <=> $b['distance'];
		return 0 !== $distance ? $distance : version_compare($b['version'], $a['version']);
	});

	return array('link' => $candidates[0]['link'], 'source' => 'wave:' . $candidates[0]['version']);
}

/**
 * Build one five-cell index row from verified facts.
 *
 * @param array{version:string,url:?string,date:?string} $page
 */
function helphub_index_draft_row(array $page, ?string $announcement): string {
	$version = htmlspecialchars($page['version'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$date    = htmlspecialchars($page['date'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
	if (null === $page['url']) {
		$version_cell = $version;
		$changelog    = '';
	} else {
		$url          = htmlspecialchars($page['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$version_cell = "<a href=\"{$url}\">{$version}</a>";
		$changelog    = "<a href=\"{$url}\">Changelog</a>";
	}
	$announcement_cell = '';
	if (null !== $announcement) {
		$link = htmlspecialchars($announcement, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$announcement_cell = "<a href=\"{$link}\">Blog</a>";
	}

	return "<tr><td>{$version_cell}</td><td>{$date}</td><td>{$changelog}</td>"
		. "<td>{$announcement_cell}</td><td></td></tr>";
}

/**
 * Search wordpress.org/news with retry through the shared HTTP client.
 *
 * @return array<int,array<string,mixed>>
 */
function helphub_index_search_news(string $term): array {
	$url = HELPHUB_INDEX_CONFIG['news_rest_url'] . '?' . http_build_query(array(
		'search'   => $term,
		'per_page' => 20,
		'_fields'  => 'link,title,content,date',
	));
	$result = helphub_http_request($url);
	if (200 !== $result['code'] || !is_array($result['data'])) {
		throw new RuntimeException("News search failed for {$term} (HTTP {$result['code']}).");
	}
	// Successful requests are paced too. Backoff handles throttling after it
	// happens; this reduces the chance of causing it during a historical audit.
	usleep(500000);

	return $result['data'];
}
