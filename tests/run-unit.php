<?php

/**
 * Every self-contained test, run in one go.
 *
 * These need no server, no network, no certificate and no fixture directory:
 * each drives a class directly and asserts on what it returns. They are the
 * tests that can run in CI on a machine with nothing installed but PHP, and
 * the ones that fail fast enough to run before a commit.
 *
 * tests/run.sh covers the other half -- a real server, real sockets, real
 * requests -- and calls this first, because there is no sense starting a
 * server to find out the frame codec is broken.
 *
 *   php tests/run-unit.php
 *   php tests/run-unit.php hpack        only tests whose name contains "hpack"
 *
 * Exits non-zero if any test fails, so it can gate a merge.
 */

$dir = __DIR__;
$filter = isset($argv[1]) ? $argv[1] : '';

// Self-contained by construction: unit-*.php, plus the http2-*.php cases that
// predate the naming and are equally standalone. http2-serve.php is excluded
// deliberately -- it is a proving ground that wants a TLS certificate and a
// client, not a test.
$files = array_merge(
	glob($dir . '/unit-*.php') ?: array(),
	array_filter(glob($dir . '/http2-*.php') ?: array(), function ($f) {
		return basename($f) !== 'http2-serve.php';
	})
);
sort($files);

if ($filter !== '') {
	$files = array_filter($files, function ($f) use ($filter) {
		return stripos(basename($f), $filter) !== false;
	});
}

if (!$files) {
	fwrite(STDERR, "  no tests matched\n");
	exit(2);
}

$pass = 0;
$fail = 0;
$failed = array();
$started = microtime(true);

echo "\n";
foreach ($files as $file) {
	$name = basename($file, '.php');
	$began = microtime(true);

	$output = array();
	$status = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
	$ms = (microtime(true) - $began) * 1000;

	// Each test prints its own cases; report the count it claims so a test
	// that silently stops asserting is visible as a number that fell.
	$cases = '';
	foreach ($output as $line) {
		if (preg_match('/PASS - (\d+) case/', $line, $m)) { $cases = $m[1] . ' cases'; break; }
		if (preg_match('/PASS - (.+)$/', $line, $m)) { $cases = trim($m[1]); break; }
	}

	if ($status === 0) {
		++$pass;
		printf("  \033[32mok\033[0m    %-28s %6.0f ms  %s\n", $name, $ms, $cases);
	} else {
		++$fail;
		$failed[$name] = $output;
		printf("  \033[31mFAIL\033[0m  %-28s %6.0f ms\n", $name, $ms);
	}
}

if ($failed) {
	echo "\n";
	foreach ($failed as $name => $output) {
		echo "  ── $name ──\n";
		foreach (array_slice($output, -25) as $line) echo "     $line\n";
		echo "\n";
	}
}

printf("\n  %d passed, %d failed, %.1fs\n\n",
	$pass, $fail, microtime(true) - $started);

exit($fail === 0 ? 0 : 1);
