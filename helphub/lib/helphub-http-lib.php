<?php

declare(strict_types=1);

/**
 * Retrying HTTP requests shared by the live HelpHub tools.
 *
 * wordpress.org rate-limits collection reads hard. A 429 is not an empty
 * collection, a missing page, or a failed release gate: it is no answer at all.
 * Every caller comes through this function so none can accidentally interpret
 * throttling as evidence.
 */

const HELPHUB_HTTP_ATTEMPTS = 6;

/**
 * Parse the final response code and headers from PHP's stream wrapper.
 *
 * @param string[] $headers
 * @return array{code:int,headers:array<string,string>}
 */
function helphub_http_response_meta(array $headers): array {
	$code   = 0;
	$parsed = array();
	foreach ($headers as $header) {
		if (1 === preg_match('~^HTTP/[0-9.]+\s+([0-9]{3})~', $header, $match)) {
			$code   = (int) $match[1];
			$parsed = array();
			continue;
		}
		$colon = strpos($header, ':');
		if (false === $colon) {
			continue;
		}
		$name          = strtolower(trim(substr($header, 0, $colon)));
		$parsed[$name] = trim(substr($header, $colon + 1));
	}

	return array('code' => $code, 'headers' => $parsed);
}

/**
 * Make one request with bounded retry and backoff.
 *
 * Reads only. These tools never write, so there is no credential to protect and
 * no request body to send. Redirects are refused anyway, because a Location
 * header is not evidence about the resource that was asked for.
 * Network failures, throttling, and server errors are retried; other statuses
 * are returned to the caller as real answers.
 *
 * The sleeper is injectable so the offline suite can test retry decisions
 * without waiting. The transport hook receives the stream options and returns
 * the same response shape as the native transport.
 *
 * @param callable(int):void|null $sleeper
 * @param callable(string,array<string,mixed>):array{body:string|false,headers:string[]}|null $transport
 * @return array{code:int,data:mixed,raw:string,headers:array<string,string>}
 */
function helphub_http_request(
	string $url,
	?callable $sleeper = null,
	?callable $transport = null
): array {
	$sleeper ??= static function (int $seconds): void {
		sleep($seconds);
	};
	$transport ??= static function (string $request_url, array $options): array {
		$raw     = @file_get_contents($request_url, false, stream_context_create($options));
		$headers = $http_response_header ?? array();

		return array('body' => $raw, 'headers' => $headers);
	};

	$options = array(
		'http' => array(
			'method'           => 'GET',
			'timeout'          => 30,
			'ignore_errors'    => true,
			'follow_location'  => 0,
			'max_redirects'    => 1,
			'protocol_version' => 1.1,
			'header'           => array(
				'Accept: application/json',
				'User-Agent: wp-security-helphub-tools',
			),
		),
	);
	$options['http']['header'] = implode("\r\n", $options['http']['header']);

	$last_code = 0;
	for ($attempt = 1; $attempt <= HELPHUB_HTTP_ATTEMPTS; $attempt++) {
		$response = $transport($url, $options);
		$meta     = helphub_http_response_meta($response['headers']);
		$raw      = is_string($response['body']) ? $response['body'] : '';
		$last_code = $meta['code'];

		if (300 <= $last_code && 400 > $last_code) {
			throw new RuntimeException(
				"Refusing to follow a redirect from {$url} (HTTP {$last_code})."
			);
		}

		$retry = false === $response['body'] || 0 === $last_code || 429 === $last_code || 500 <= $last_code;
		if (!$retry) {
			return array(
				'code'    => $last_code,
				'data'    => json_decode($raw, true),
				'raw'     => $raw,
				'headers' => $meta['headers'],
			);
		}
		if (HELPHUB_HTTP_ATTEMPTS !== $attempt) {
			$sleeper(3 * $attempt);
		}
	}

	throw new RuntimeException(
		"Request did not produce an answer after " . HELPHUB_HTTP_ATTEMPTS
		. " attempts: GET {$url} (last HTTP {$last_code})."
	);
}
