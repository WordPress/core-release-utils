<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/lib.php';

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
