<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function require_checkout(string $path): string {
	verbose_log("Checking Git checkout at {$path}");
	$repo = realpath($path);
	if (false === $repo || (!is_dir($repo . '/.git') && !is_file($repo . '/.git'))) {
		throw new InvalidArgumentException("Not a Git checkout: {$path}");
	}
	$check = run_command(array('git', 'rev-parse', '--is-inside-work-tree'), $repo, true);
	if (0 !== $check['code'] || 'true' !== trim($check['stdout'])) {
		throw new InvalidArgumentException("Not a Git worktree: {$path}");
	}

	verbose_log("Using Git checkout {$repo}");
	return $repo;
}

function unique_merge_base(string $repo, string $first, string $second, string $failure): string {
	$result = run_command(array('git', 'merge-base', '--all', $first, $second), $repo, true);
	if (
		0 !== $result['code']
		|| 1 !== preg_match('/\A([0-9a-f]{40})\n\z/D', $result['stdout'], $match)
	) {
		throw new RuntimeException($failure);
	}

	return $match[1];
}
