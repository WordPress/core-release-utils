<?php

declare(strict_types=1);

/**
 * Shared configuration, parsing, and reconciliation for the HelpHub indexes.
 *
 * The two index articles are configuration here, in one place. Callers do not
 * scatter post IDs or reconstruct their public URLs independently.
 */

const HELPHUB_INDEX_CONFIG = array(
	'site'              => 'https://wordpress.org/documentation',
	'version_rest_base' => 'wp/v2/wordpress-versions',
	'article_rest_base' => 'wp/v2/articles',
	'news_rest_url'     => 'https://wordpress.org/news/wp-json/wp/v2/posts',
	'articles'          => array(
		14146007 => 'https://wordpress.org/documentation/article/wordpress-versions/',
		16367941 => 'https://wordpress.org/documentation/article/wordpress-versions-0-7-to-4-9/',
	),
);

/**
 * Build a REST URL from the central configuration.
 *
 * @param array<string,scalar> $query
 */
function helphub_index_rest_url(string $base, ?int $id = null, array $query = array()): string {
	$url = rtrim(HELPHUB_INDEX_CONFIG['site'], '/') . '/wp-json/' . $base;
	if (null !== $id) {
		$url .= '/' . $id;
	}
	if ($query) {
		$url .= '?' . http_build_query($query);
	}

	return $url;
}

/**
 * Convert a published page slug back to its version.
 *
 * Four-part versions exist in the historical index, so this deliberately does
 * not stop at the usual X.Y.Z shape.
 */
function helphub_index_version_from_slug(string $slug): ?string {
	if (1 !== preg_match('/^version-([0-9]+(?:-[0-9]+)+)$/', $slug, $match)) {
		return null;
	}

	return str_replace('-', '.', $match[1]);
}

/**
 * The major release table a version belongs to.
 */
function helphub_index_series(string $version): string {
	$parts = explode('.', $version);
	if (2 > count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
		throw new InvalidArgumentException("Not a version: {$version}");
	}

	return $parts[0] . '.' . $parts[1];
}

/**
 * Read the version from the first cell of a table row.
 *
 * The cell may contain nested anchors and strong tags. Reading its stripped
 * text works for both old and new tables; counting hrefs does not, because some
 * rows put their only version-page anchor on the word "Changelog".
 */
function helphub_index_row_version(string $row): ?string {
	if (1 !== preg_match('~<t[dh]\b[^>]*>(.*?)</t[dh]>~is', $row, $cell)) {
		return null;
	}
	$text = html_entity_decode(trim(strip_tags($cell[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
	if (1 !== preg_match('/^[0-9]+(?:\.[0-9]+)+$/', $text)) {
		return null;
	}

	return $text;
}

/**
 * Read every indexed version from rendered or raw article content.
 *
 * @return string[]
 */
function helphub_index_versions(string $content): array {
	$versions = array();
	// Read table by table rather than sweeping every row in the article. The
	// first article opens with a "Planned Versions" roadmap whose rows look
	// exactly like index rows, so an article-wide sweep reports 7.1 and 7.2 as
	// indexed versions and then as rows with no published page. They are
	// neither: they are release dates that have not happened yet.
	if (!preg_match_all('~<table\b[^>]*>.*?</table>~is', $content, $tables)) {
		return array();
	}
	foreach ($tables[0] as $table) {
		if (helphub_index_is_planned_table($table)) {
			continue;
		}
		if (!preg_match_all('~<tr\b[^>]*>.*?</tr>~is', $table, $rows)) {
			continue;
		}
		foreach ($rows[0] as $row) {
			$version = helphub_index_row_version($row);
			if (null !== $version) {
				$versions[$version] = true;
			}
		}
	}

	return array_keys($versions);
}

/**
 * A roadmap table rather than a record of what shipped.
 *
 * Detected from its own header, which says "Planned Release Date" where a
 * released-versions table says "Release Date". Detecting it by position or by
 * counting columns would break the first time someone adds a table.
 */
function helphub_index_is_planned_table(string $table): bool {
	if (!preg_match('~<tr\b[^>]*>.*?</tr>~is', $table, $header)) {
		return false;
	}

	return 1 === preg_match('~planned~i', strip_tags($header[0]));
}

/**
 * The date stated by a version page's opening sentence.
 *
 * The sentence must name the same version as the slug. A copied page that kept
 * its neighbor's opening sentence is not authority for either date.
 */
function helphub_index_release_date(string $content, string $version): ?string {
	$text = helphub_page_to_text($content);
	$pattern = '~(?:^|\n)On\s+([A-Z][a-z]+\s+[0-9]{1,2},\s+[0-9]{4}),\s*WordPress\s+'
		. preg_quote($version, '~') . '(?![0-9.])\s+was released to the public\.~';
	if (1 !== preg_match($pattern, $text, $match)) {
		return null;
	}

	return $match[1];
}

/**
 * Convert the page's prose date to a calendar key without timezone inference.
 */
function helphub_index_date_key(?string $date): ?string {
	if (null === $date) {
		return null;
	}
	$parsed = DateTimeImmutable::createFromFormat('!F j, Y', $date, new DateTimeZone('UTC'));
	$errors = DateTimeImmutable::getLastErrors();
	if (false === $parsed || (is_array($errors) && (0 < $errors['warning_count'] || 0 < $errors['error_count']))) {
		return null;
	}

	return $parsed->format('Y-m-d');
}

/**
 * Normalize the security credit bullets that identify a page's fix set.
 *
 * @return string[]
 */
function helphub_index_fix_set(string $content): array {
	$bullets = helphub_credit_bullets(helphub_page_to_text($content));
	if (null === $bullets || !$bullets) {
		return array();
	}
	$fixes = array();
	foreach ($bullets as $bullet) {
		// Reporter attribution can be corrected on one sibling without changing
		// which vulnerability the page carries. The advisory-style opening is the
		// fix identity; the existing page checker audits attribution consistency.
		$opening    = preg_split('/\s+reported(?:\s+as a team)?\s+by\s+/i', $bullet, 2)[0] ?? $bullet;
		$normalized = strtolower(trim(preg_replace('/\s+/', ' ', $opening) ?? $opening, " .\t\n\r\0\x0B"));
		if ('' !== $normalized) {
			$fixes[$normalized] = true;
		}
	}
	$fixes = array_keys($fixes);
	sort($fixes);

	return $fixes;
}

/**
 * Whether two version pages positively identify the same security fix set.
 */
function helphub_index_same_fix_set(array $a, array $b): bool {
	return $a && $b && $a === $b;
}

/**
 * Companion versions the lead page explicitly says were released.
 *
 * @return string[]
 */
function helphub_index_courtesy_versions(string $content): array {
	$lines = helphub_section_lines(helphub_page_to_text($content), HELPHUB_SECURITY_HEADING);
	if (null === $lines) {
		return array();
	}
	$inside   = false;
	$versions = array();
	foreach ($lines as $line) {
		$plain = ltrim($line, "\x01\x02");
		if (!$inside) {
			if (str_starts_with($plain, 'As a courtesy')) {
				$inside = true;
			}
			continue;
		}
		if (preg_match('/\bVersion\s+([0-9]+(?:\.[0-9]+)+)\s+has been released\b/', $plain, $match)) {
			$versions[$match[1]] = true;
		}
	}

	return array_keys($versions);
}

/**
 * Normalize one REST page into the facts every index tool shares.
 *
 * @param array<string,mixed> $post
 * @return array{version:string,slug:string,url:?string,content:string,date:?string,date_key:?string,fix_set:string[]}|null
 */
function helphub_index_page_facts(array $post): ?array {
	$slug    = isset($post['slug']) && is_string($post['slug']) ? $post['slug'] : '';
	$version = helphub_index_version_from_slug($slug);
	if (null === $version) {
		return null;
	}
	$content = '';
	if (isset($post['content']['rendered']) && is_string($post['content']['rendered'])) {
		$content = $post['content']['rendered'];
	} elseif (isset($post['content']['raw']) && is_string($post['content']['raw'])) {
		$content = $post['content']['raw'];
	}
	$url  = isset($post['link']) && is_string($post['link']) && '' !== $post['link'] ? $post['link'] : null;
	$date = helphub_index_release_date($content, $version);

	return array(
		'version'  => $version,
		'slug'     => $slug,
		'url'      => $url,
		'content'  => $content,
		'date'     => $date,
		'date_key' => helphub_index_date_key($date),
		'fix_set'  => helphub_index_fix_set($content),
	);
}

/**
 * Normalize a collection of REST posts, keyed by their own slug's version.
 *
 * @param array<int,array<string,mixed>> $posts
 * @return array<string,array{version:string,slug:string,url:?string,content:string,date:?string,date_key:?string,fix_set:string[]}>
 */
function helphub_index_page_map(array $posts): array {
	$pages = array();
	foreach ($posts as $post) {
		$facts = helphub_index_page_facts($post);
		if (null !== $facts) {
			$pages[$facts['version']] = $facts;
		}
	}

	return $pages;
}

/**
 * Identify a release wave from published page evidence.
 *
 * The lead page's courtesy block explicitly links companion releases and is
 * the preferred source. Older pages without that block fall back to an exact,
 * non-empty security fix set within seven calendar days. Date proximity alone
 * is never enough: a maintenance release can sit between the lead and backports.
 *
 * @param array<string,array{content?:string,date_key:?string,fix_set:string[]}> $pages
 * @return string[] Newest first.
 */
function helphub_index_release_wave(array $pages, string $release): array {
	if (!isset($pages[$release])) {
		throw new InvalidArgumentException("No published version page found for {$release}.");
	}
	$lead = $pages[$release];
	$courtesy = isset($lead['content']) ? helphub_index_courtesy_versions($lead['content']) : array();
	if ($courtesy) {
		$wave = array($release);
		foreach ($courtesy as $version) {
			if (isset($pages[$version])) {
				$wave[] = $version;
			}
		}
		$wave = array_values(array_unique($wave));
		usort($wave, static fn(string $a, string $b): int => version_compare($b, $a));

		return $wave;
	}
	if (!$lead['fix_set']) {
		throw new InvalidArgumentException(
			"Page {$release} has no verifiable security credit bullets, so its release wave cannot be identified."
		);
	}
	if (null === $lead['date_key']) {
		throw new InvalidArgumentException(
			"Page {$release} has no matching opening release sentence, so its release wave cannot be identified."
		);
	}

	$lead_day = new DateTimeImmutable($lead['date_key'], new DateTimeZone('UTC'));
	$wave     = array();
	foreach ($pages as $version => $page) {
		if (null === $page['date_key'] || !helphub_index_same_fix_set($lead['fix_set'], $page['fix_set'])) {
			continue;
		}
		$day  = new DateTimeImmutable($page['date_key'], new DateTimeZone('UTC'));
		$days = abs((int) $lead_day->diff($day)->format('%r%a'));
		if (7 >= $days) {
			$wave[] = $version;
		}
	}
	usort($wave, static fn(string $a, string $b): int => version_compare($b, $a));

	return $wave;
}

/**
 * Compare published pages with the two index articles.
 *
 * @param string[] $published
 * @param string[] $indexed
 * @return array{missing:string[],orphaned:string[]}
 */
function helphub_index_reconcile(array $published, array $indexed): array {
	$missing  = array_values(array_diff(array_unique($published), array_unique($indexed)));
	$orphaned = array_values(array_diff(array_unique($indexed), array_unique($published)));
	usort($missing, static fn(string $a, string $b): int => version_compare($b, $a));
	usort($orphaned, static fn(string $a, string $b): int => version_compare($b, $a));

	return array('missing' => $missing, 'orphaned' => $orphaned);
}

/**
 * Published pages known to have no index row by content decision, not a gap.
 *
 * `1.6` predates the index's own conventions and has never had a row on
 * either article. Reported as a "gap" on every run since 2026-09-17, which is
 * a content decision nobody was ever going to close, not a missing row a
 * release should fix.
 */
const HELPHUB_INDEX_KNOWN_EXCEPTIONS = array('1.6');

/**
 * Split a list of versions into known exceptions and everything else.
 *
 * @param string[] $versions
 * @param string[] $known
 * @return array{known:string[],rest:string[]}
 */
function helphub_index_split_known(array $versions, array $known): array {
	$known_set = array_flip($known);
	$found     = array();
	$rest      = array();
	foreach ($versions as $version) {
		if (isset($known_set[$version])) {
			$found[] = $version;
		} else {
			$rest[] = $version;
		}
	}

	return array('known' => $found, 'rest' => $rest);
}

/**
 * Fetch the complete published version-page collection.
 *
 * @return array<int,array<string,mixed>>
 */
function helphub_index_fetch_published_pages(): array {
	$all = array();
	for ($page = 1; $page <= 20; $page++) {
		$url = helphub_index_rest_url(HELPHUB_INDEX_CONFIG['version_rest_base'], null, array(
			'status'   => 'publish',
			'per_page' => 100,
			'page'     => $page,
			'orderby'  => 'id',
			'order'    => 'asc',
			'_fields'  => 'slug,link,status,content',
		));
		$result = helphub_http_request($url);
		if (200 !== $result['code'] || !is_array($result['data'])) {
			throw new RuntimeException("Published version-page listing failed on page {$page} (HTTP {$result['code']}).");
		}
		usleep(300000);
		$batch = $result['data'];
		if (1 === $page && !$batch) {
			throw new RuntimeException('Published version-page listing returned no pages; refusing to treat that as an audit result.');
		}
		$all   = array_merge($all, $batch);
		$total_pages = isset($result['headers']['x-wp-totalpages'])
			? (int) $result['headers']['x-wp-totalpages']
			: null;
		if ((null !== $total_pages && $page >= $total_pages) || 100 > count($batch)) {
			return $all;
		}
	}

	throw new RuntimeException('Published version-page listing exceeded 20 pages; refusing a partial result.');
}

/**
 * Fetch both public index articles as rendered HTML.
 *
 * @return array<int,string> Post ID to rendered content.
 */
function helphub_index_fetch_articles(): array {
	$articles = array();
	foreach (HELPHUB_INDEX_CONFIG['articles'] as $id => $public_url) {
		$url    = helphub_index_rest_url(HELPHUB_INDEX_CONFIG['article_rest_base'], $id, array('_fields' => 'content'));
		$result = helphub_http_request($url);
		$content = $result['data']['content']['rendered'] ?? null;
		if (200 !== $result['code'] || !is_string($content)) {
			throw new RuntimeException("Could not read index article {$id} (HTTP {$result['code']}).");
		}
		usleep(300000);
		$articles[$id] = $content;
	}

	return $articles;
}
