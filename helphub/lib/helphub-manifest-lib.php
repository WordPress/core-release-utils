<?php

declare(strict_types=1);


function helphub_manifest_decode(string $json): array {
	try {
		$manifest = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $exception) {
		throw new InvalidArgumentException('Manifest is not valid JSON: ' . $exception->getMessage(), 0, $exception);
	}
	if (!$manifest instanceof stdClass) {
		throw new InvalidArgumentException('Manifest must be an object.');
	}
	foreach (array_keys(get_object_vars($manifest)) as $key) {
		if (!in_array($key, array('format', 'release', 'generated_at', 'checkout_head', 'targets', 'fixes', 'credits', 'advisories'), true)) {
			throw new InvalidArgumentException("Unknown manifest field: {$key}.");
		}
	}
	if (1 !== ($manifest->format ?? null)) {
		throw new InvalidArgumentException('Manifest format must be 1.');
	}
	if (!is_string($manifest->release ?? null) || 1 !== preg_match('/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/', $manifest->release)) {
		throw new InvalidArgumentException('Manifest release must be an X.Y or X.Y.Z string.');
	}
	if (!($manifest->targets ?? null) instanceof stdClass || array() === get_object_vars($manifest->targets)) {
		throw new InvalidArgumentException('Manifest targets must be a non-empty object.');
	}
	foreach ($manifest->targets as $target => $entry) {
		if (1 !== preg_match('/\A[0-9]+\.[0-9]+\z/', $target)) {
			throw new InvalidArgumentException("Manifest target {$target} must look like X.Y.");
		}
		if (null === $entry) {
			continue;
		}
		if (!$entry instanceof stdClass) {
			throw new InvalidArgumentException("Manifest target {$target} must be an object or null.");
		}
		foreach (array_keys(get_object_vars($entry)) as $field) {
			if (!in_array($field, array('branch', 'commit', 'base'), true)) {
				throw new InvalidArgumentException("Manifest target {$target} has an unknown field: {$field}.");
			}
		}
		foreach (array('branch', 'commit', 'base') as $field) {
			if (!is_string($entry->$field ?? null) || '' === trim($entry->$field)) {
				throw new InvalidArgumentException("Manifest target {$target} {$field} must be a non-empty string.");
			}
		}
		if (1 !== preg_match('/\A[0-9a-f]{40}\z/i', $entry->commit)) {
			throw new InvalidArgumentException("Manifest target {$target} commit must be 40 hexadecimal characters.");
		}
	}
	foreach (array('fixes', 'credits', 'advisories') as $field) {
		if (!($manifest->$field ?? null) instanceof stdClass) {
			throw new InvalidArgumentException("Manifest {$field} must be an object.");
		}
		foreach ($manifest->$field as $number => $value) {
			if (1 !== preg_match('/\A[1-9][0-9]*\z/', (string) $number)) {
				throw new InvalidArgumentException("Manifest {$field} key {$number} must be a fix number.");
			}
			if ('fixes' === $field) {
				if (!is_array($value) || !array_is_list($value) || array() === $value) {
					throw new InvalidArgumentException("Manifest fix {$number} must contain a non-empty list of targets.");
				}
				foreach ($value as $target) {
					if (!is_string($target) || !property_exists($manifest->targets, $target)) {
						throw new InvalidArgumentException("Manifest fix {$number} names a target absent from targets.");
					}
				}
				if (count($value) !== count(array_unique($value))) {
					throw new InvalidArgumentException("Manifest fix {$number} names a target twice.");
				}
				continue;
			}
			if (!property_exists($manifest->fixes, (string) $number)) {
				throw new InvalidArgumentException("Manifest {$field} names unknown fix {$number}.");
			}
			if ('credits' === $field && (!is_string($value) || '' === trim($value))) {
				throw new InvalidArgumentException("Manifest credit {$number} must be a non-empty string.");
			}
			if ('advisories' === $field && null !== $value && (!is_string($value) || 1 !== preg_match('/\AGHSA(-[0-9a-z]{4}){3}\z/i', $value))) {
				throw new InvalidArgumentException("Manifest advisory {$number} must be a GHSA identifier or null.");
			}
		}
	}
	$missing_advisories = array_diff(array_keys(get_object_vars($manifest->fixes)), array_keys(get_object_vars($manifest->advisories)));
	if ($missing_advisories) {
		throw new InvalidArgumentException('Manifest advisories must name every fix, with null when there is none: missing ' . implode(', ', $missing_advisories) . '.');
	}
	return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function helphub_manifest_missing_targets(array $manifest): array {
	return array_keys(array_filter($manifest['targets'], static fn($entry): bool => null === $entry));
}
