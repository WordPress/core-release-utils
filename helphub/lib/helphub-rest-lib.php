<?php

declare(strict_types=1);

/** Shared authenticated REST requests for HelpHub version pages. */

const HELPHUB_PUSH_AGENT = 'wp-security-helphub-push';

/** A lookup that wordpress.org answered with a non-200 status. */
final class HelphubLookupFailedException extends RuntimeException {
	public function __construct(string $message, public readonly int $httpCode) {
		parent::__construct($message);
	}
}

/** Hold the credential in one place instead of threading it through calls. */
function helphub_auth(?string $set = null): string {
	static $auth = '';
	if (null !== $set) {
		$auth = $set;
	}
	if ('' === $auth) {
		throw new RuntimeException('No credential loaded.');
	}

	return $auth;
}

/** Load the application password without putting it in arguments or output. */
function helphub_load_auth(string $user): void {
	$password = getenv('HELPHUB_APP_PASSWORD');
	$password = is_string($password) ? trim($password) : '';
	if ('' === $password) {
		throw new InvalidArgumentException(
			'Set HELPHUB_APP_PASSWORD to a wordpress.org application password. '
			. 'It is read from the environment on purpose: a password passed as an argument '
			. 'reaches your shell history and every process listing on this machine.'
		);
	}
	helphub_auth(base64_encode($user . ':' . $password));
	unset($password);
}

/** Make one authenticated REST request. */
function helphub_request(string $url, string $method, ?array $body = null, string $agent = HELPHUB_PUSH_AGENT): array {
	$options = array(
		'http' => array(
			'method'           => $method,
			'timeout'          => 30,
			'ignore_errors'    => true,
			'follow_location'  => 0,
			'max_redirects'    => 1,
			'protocol_version' => 1.1,
			'header'           => array(
				'Authorization: Basic ' . helphub_auth(),
				'Accept: application/json',
				'User-Agent: ' . $agent,
			),
		),
	);
	if (null !== $body) {
		$encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (false === $encoded) {
			throw new RuntimeException('Unable to encode the request body.');
		}
		$options['http']['header'][] = 'Content-Type: application/json';
		$options['http']['content']  = $encoded;
	}
	$options['http']['header'] = implode("\r\n", $options['http']['header']);

	$raw = @file_get_contents($url, false, stream_context_create($options));
	$code = 0;
	foreach ($http_response_header ?? array() as $header) {
		if (1 === preg_match('~^HTTP/[0-9.]+\s+([0-9]{3})~', $header, $match)) {
			$code = (int) $match[1];
		}
	}
	if (false === $raw) {
		throw new RuntimeException("Request failed: {$method} {$url}");
	}
	if (300 <= $code && 400 > $code) {
		throw new RuntimeException(
			"Refusing to follow a redirect from {$url} (HTTP {$code}). "
			. 'Check --site points directly at the REST endpoint.'
		);
	}

	return array('code' => $code, 'data' => json_decode($raw, true), 'raw' => $raw);
}

/** Make one authenticated GET request; the only call path a read-only tool may use. */
function helphub_get(string $url, string $agent): array {
	return helphub_request($url, 'GET', null, $agent);
}

/** Find an existing page for a version, in any status. */
function helphub_find_page(string $site, string $version): ?array {
	$url = helphub_rest_url($site, null, array(
		'slug'    => helphub_version_slug($version),
		'status'  => 'publish,draft,pending,private,future',
		'_fields' => 'id,slug,status,link',
	));
	$result = helphub_request($url, 'GET');
	if (200 !== $result['code']) {
		throw new RuntimeException(
			"Lookup failed for {$version} (HTTP {$result['code']}). " . substr($result['raw'], 0, 200)
		);
	}

	return is_array($result['data']) && $result['data'] ? $result['data'][0] : null;
}

/** Look up every page at a version's slug, in any status, with its raw content. */
function helphub_lookup_pages(string $site, string $version, ?callable $request = null): array {
	$request ??= 'helphub_request';
	$url = helphub_rest_url($site, null, array(
		'slug'    => helphub_version_slug($version),
		'status'  => 'publish,draft,pending,private,future',
		'context' => 'edit',
		'_fields' => 'id,slug,status,content',
	));
	$result = $request($url, 'GET');
	if (200 !== $result['code'] || !is_array($result['data'])) {
		throw new HelphubLookupFailedException(
			"Lookup failed for {$version} (HTTP {$result['code']}). " . substr($result['raw'], 0, 200),
			$result['code']
		);
	}

	return array_values($result['data']);
}

/** Read unambiguous live page bodies and retain which version slugs existed. */
function helphub_load_live_pages(string $site, array $versions, ?callable $request = null, ?string $agent = null): array {
	$request ??= static fn(string $url, string $method): array => helphub_get(
		$url,
		$agent ?? throw new InvalidArgumentException('Name the user agent for live reads.')
	);
	$pages      = array();
	$found      = array();
	$unverified = array();
	foreach ($versions as $version) {
		try {
			$matches = helphub_lookup_pages($site, $version, $request);
		} catch (HelphubLookupFailedException $exception) {
			if (in_array($exception->httpCode, array(401, 403), true)) {
				throw new InvalidArgumentException(
					"wordpress.org rejected the credential for {$version} (HTTP {$exception->httpCode}). "
					. 'Check HELPHUB_APP_PASSWORD and --user.'
				);
			}
			throw $exception;
		}
		if (!$matches) {
			continue;
		}
		$found[] = $version;
		if (1 < count($matches)) {
			$ids = array_map(
				static fn(array $page): string => isset($page['id']) ? (string) (int) $page['id'] : 'unknown',
				$matches
			);
			$unverified[] = sprintf(
				'Page %s matched more than one page at its slug (ids %s), so its body was not checked.',
				$version,
				implode(', ', $ids)
			);
			continue;
		}
		$body = $matches[0]['content']['raw'] ?? null;
		if (!is_string($body)) {
			$unverified[] = "Page {$version} returned no raw content, so its body was not checked.";
			continue;
		}
		$pages[$version] = $body;
	}

	return array('pages' => $pages, 'found' => $found, 'unverified' => $unverified);
}

/** Name a page for a skipped-line message. */
function helphub_page_label(array $page): string {
	$slug = isset($page['slug']) && is_string($page['slug']) ? trim($page['slug']) : '';
	$id   = isset($page['id']) ? (int) $page['id'] : 0;
	if ('' !== $slug) {
		return 0 !== $id ? "{$slug} (id {$id})" : $slug;
	}

	return 0 !== $id ? "id {$id}" : 'a page with no id or slug';
}
