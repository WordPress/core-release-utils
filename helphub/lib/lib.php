<?php

declare(strict_types=1);


function fail(string $message, int $code = 1): never {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function parse_cli_options(array $argv, array $declarations): array {
	$allowed = array();
	foreach ($declarations as $declaration) {
		$allowed[rtrim($declaration, ':')] = str_ends_with($declaration, ':');
	}
	$output_key = null;
	$output_alias = null;
	foreach (array('out' => 'output', 'output' => 'out') as $declared => $alias) {
		if (in_array($declared . ':', $declarations, true) && !array_key_exists($alias, $allowed)) {
			$output_key = $declared;
			$output_alias = $alias;
			$allowed[$alias] = true;
			break;
		}
	}
	$options = array();
	for ($index = 1; $index < count($argv); ++$index) {
		$token = $argv[$index];
		if ('--' === $token && $index === count($argv) - 1) {
			break;
		}
		if (!str_starts_with($token, '--') || '--' === $token) {
			throw new InvalidArgumentException('Unexpected argument: ' . $token);
		}
		$pair = explode('=', substr($token, 2), 2);
		$name = $pair[0];
		if (!array_key_exists($name, $allowed)) {
			throw new InvalidArgumentException("Unknown option: --{$name}");
		}
		$value = false;
		if ($allowed[$name]) {
			if (2 === count($pair)) {
				$value = $pair[1];
			} else {
				if (!isset($argv[$index + 1]) || str_starts_with($argv[$index + 1], '-')) {
					throw new InvalidArgumentException("Option --{$name} requires a value.");
				}
				$value = $argv[++$index];
			}
		} elseif (2 === count($pair)) {
			throw new InvalidArgumentException("Option --{$name} takes no value.");
		}
		if (array_key_exists($name, $options)) {
			$options[$name] = array_merge((array) $options[$name], array($value));
		} else {
			$options[$name] = $value;
		}
	}
	if (null !== $output_alias && array_key_exists($output_alias, $options)) {
		if (array_key_exists($output_key, $options) && $options[$output_key] !== $options[$output_alias]) {
			throw new InvalidArgumentException('Options --out and --output must have identical values.');
		}
		$options[$output_key] = $options[$output_alias];
		unset($options[$output_alias]);
	}
	return $options;
}

function cli_options(array $argv, array $declarations): array {
	try {
		return parse_cli_options($argv, $declarations);
	} catch (InvalidArgumentException $error) {
		fail($error->getMessage());
	}
}

function run_command(array $command, ?string $cwd = null, bool $allow_failure = false, ?string $stdin = null): array {
	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);

	$process = proc_open($command, $descriptors, $pipes, $cwd);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
	}

	// Writing all of stdin before reading a byte of stdout deadlocks as soon as
	// the child's output fills its own pipe buffer: the child blocks writing,
	// this process blocks writing, and neither ever drains the other. Pipe
	// buffers are small (64KB on macOS), and the child's output can exceed
	// them long before a large stdin is finished. Pump instead: offer stdin
	// and drain both output pipes in the same select loop, so neither side can
	// fill. Empirically this is not theoretical — `git check-attr -z merge
	// --stdin` over one WordPress checkout's file list hangs forever under the
	// write-everything-first shape and returns immediately under this one.
	$stdout = '';
	$stderr = '';
	$offset = 0;
	$length = null === $stdin ? 0 : strlen($stdin);
	if (0 === $length) {
		fclose($pipes[0]);
		$pipes[0] = null;
	} else {
		stream_set_blocking($pipes[0], false);
	}
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	while (null !== $pipes[0] || null !== $pipes[1] || null !== $pipes[2]) {
		$read = array();
		foreach (array(1, 2) as $index) {
			if (null !== $pipes[$index]) {
				$read[] = $pipes[$index];
			}
		}
		$write = null !== $pipes[0] ? array($pipes[0]) : array();
		$except = null;
		if (!$read && !$write) {
			break;
		}
		// A null timeout blocks until something is ready, which is what we want:
		// the loop is driven by the child, never by polling.
		if (false === stream_select($read, $write, $except, null)) {
			break;
		}
		foreach ($write as $pipe) {
			// A child is entitled to exit without draining its stdin, and the
			// broken pipe that follows is that fact rather than a fault: there
			// is no longer anyone to send the rest to. PHP ignores SIGPIPE and
			// reports it as a warning plus a false return, so the suppression
			// is narrow and the false is what actually drives the branch. The
			// child's own exit code still reaches the caller untouched.
			$written = @fwrite($pipe, substr((string) $stdin, $offset, 65536));
			if (false === $written || 0 === $written) {
				fclose($pipes[0]);
				$pipes[0] = null;
				break;
			}
			$offset += $written;
			if ($offset >= $length) {
				fclose($pipes[0]);
				$pipes[0] = null;
			}
		}
		foreach ($read as $pipe) {
			$index = $pipe === $pipes[1] ? 1 : 2;
			$chunk = fread($pipe, 65536);
			if (false === $chunk || ('' === $chunk && feof($pipe))) {
				fclose($pipes[$index]);
				$pipes[$index] = null;
				continue;
			}
			if (1 === $index) {
				$stdout .= $chunk;
			} else {
				$stderr .= $chunk;
			}
		}
	}
	foreach (array(0, 1, 2) as $index) {
		if (null !== $pipes[$index]) {
			fclose($pipes[$index]);
			$pipes[$index] = null;
		}
	}
	$code = proc_close($process);

	$result = array(
		'code'   => $code,
		'stdout' => $stdout === false ? '' : $stdout,
		'stderr' => $stderr === false ? '' : $stderr,
	);

	if (!$allow_failure && 0 !== $code) {
		throw new RuntimeException(
			sprintf("Command failed (%d): %s\n%s", $code, implode(' ', $command), trim($result['stderr']))
		);
	}

	return $result;
}

function set_verbose(bool $verbose): void {
	$GLOBALS['security_release_verbose'] = $verbose;
}

function verbose_log(string $message): void {
	if (!($GLOBALS['security_release_verbose'] ?? false)) {
		return;
	}
	fwrite(STDERR, '[verbose] ' . $message . PHP_EOL);
}
