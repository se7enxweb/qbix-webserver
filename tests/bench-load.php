#!/usr/bin/env php
<?php

/**
 * Load the server until it stops getting faster, and say what it was holding.
 *
 * A single number -- "29ms" -- says almost nothing on its own. It does not say
 * at what concurrency, whether the slowest request in a hundred was thirty
 * times worse, how close the machine was to running out of anything, or what
 * would happen with one more worker. This gathers all of that, sweeps the
 * concurrency until throughput stops improving, and reports where the knee is.
 *
 *   php tests/bench-load.php https://localhost:8443/
 *   php tests/bench-load.php http://127.0.0.1:8088/ --levels=1,4,16,64 --requests=500
 *   php tests/bench-load.php https://host/ --http1        compare protocols
 *   php tests/bench-load.php https://host/ --json         for a pipeline
 *
 * Needs nothing installed: PHP's curl does HTTP/2 and curl_multi does the
 * concurrency, so this runs wherever the server does.
 *
 * ── What it measures, and what it cannot ────────────────────────────────
 *
 * Closed loop, like ab and wrk: N requests in flight, a new one as each
 * finishes. That measures a server's capacity honestly but understates latency
 * under saturation, because a slow response delays the request that would have
 * followed it -- the requests that never got sent are the ones that would have
 * been slowest. This is coordinated omission, and it is why the percentiles
 * here should be read as "how it served what it accepted" rather than "what a
 * user would have seen". An open-loop generator at a fixed arrival rate is the
 * other half, and this is not one.
 *
 * Percentiles, not averages. A mean hides the tail, and the tail is what people
 * notice; p99 on a page assembled from forty assets is met on nearly every page
 * view, not one in a hundred.
 */

// ── Arguments ───────────────────────────────────────────────────────────

$url = null;
$levels = array(1, 2, 4, 8, 16, 32, 64);
$requests = 300;
$warmup = 30;
$json = false;
$http1 = false;
$timeout = 30;

// Where a run is written. Every run writes one, because a measurement nobody
// kept is a measurement that has to be taken again before it can be argued
// with -- and the whole point of a sweep is comparing it against the next one
// after a setting changed.
$outDir = 'var/storage/generated/stats';
$label = '';

foreach (array_slice($argv, 1) as $arg) {
	if (strpos($arg, '--levels=') === 0) {
		$levels = array_values(array_filter(array_map('intval',
			explode(',', substr($arg, 9))), function ($n) { return $n > 0; }));
	} else if (strpos($arg, '--requests=') === 0) {
		$requests = max(10, (int) substr($arg, 11));
	} else if (strpos($arg, '--warmup=') === 0) {
		$warmup = max(0, (int) substr($arg, 9));
	} else if (strpos($arg, '--timeout=') === 0) {
		$timeout = max(1, (int) substr($arg, 10));
	} else if (strpos($arg, '--out=') === 0) {
		$outDir = substr($arg, 6);
	} else if ($arg === '--no-csv') {
		$outDir = false;
	} else if ($arg === '--label' or strpos($arg, '--label=') === 0) {
		$label = strpos($arg, '=') !== false ? substr($arg, 8) : '';
	} else if ($arg === '--json') {
		$json = true;
	} else if ($arg === '--http1') {
		$http1 = true;
	} else if ($arg === '-h' or $arg === '--help') {
		$lines = file(__FILE__);
		foreach (array_slice($lines, 2, 24) as $l) echo preg_replace('!^ ?\*ically?!', '', rtrim(preg_replace('!^\s*\*ically?!', '', preg_replace('!^\s*\*!', '', $l)))), "\n";
		exit(0);
	} else if ($url === null and $arg[0] !== '-') {
		$url = $arg;
	}
}

if ($url === null) {
	fwrite(STDERR, "  usage: php tests/bench-load.php <url> [--levels=1,4,16] [--requests=N] [--http1] [--json]\n");
	exit(2);
}

sort($levels);

// ── The machine, and the server on it ───────────────────────────────────

/**
 * Everything worth knowing about the host that a load number is meaningless
 * without. A result is not reproducible unless this is recorded with it.
 */
function machine()
{
	$m = array(
		'php' => PHP_VERSION,
		'sapi' => PHP_SAPI,
		'cores' => 1,
		'load' => array(0, 0, 0),
		'memTotalKb' => 0,
		'memAvailableKb' => 0,
		'opcache' => 'not loaded',
		'curl' => '',
	);

	$cpuinfo = @file_get_contents('/proc/cpuinfo');
	// Counted at line starts: "\nprocessor" missed the first entry, which
	// begins the file, and so reported one core too few.
	if ($cpuinfo !== false) $m['cores'] = max(1, preg_match_all('/^processor\s*:/m', $cpuinfo));
	else if (function_exists('shell_exec')) $m['cores'] = max(1, (int) @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));

	$loadavg = @file_get_contents('/proc/loadavg');
	if ($loadavg !== false) {
		$parts = explode(' ', trim($loadavg));
		$m['load'] = array((float) $parts[0], (float) $parts[1], (float) $parts[2]);
	} else if (function_exists('sys_getloadavg')) {
		$m['load'] = sys_getloadavg();
	}

	$meminfo = @file_get_contents('/proc/meminfo');
	if ($meminfo !== false) {
		if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $x)) $m['memTotalKb'] = (int) $x[1];
		// MemAvailable, not MemFree: free memory on a busy machine is near zero
		// because the page cache uses the rest, and the page cache is available.
		if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $x)) $m['memAvailableKb'] = (int) $x[1];
	}

	if (function_exists('opcache_get_status')) {
		$o = @opcache_get_status(false);
		$m['opcache'] = (is_array($o) and !empty($o['opcache_enabled']))
			? 'enabled, ' . number_format((int) $o['opcache_statistics']['num_cached_scripts']) . ' scripts'
			: 'loaded, not enabled';
	}

	$c = curl_version();
	$m['curl'] = $c['version'] . ((($c['features'] & CURL_VERSION_HTTP2)) ? ', http2' : ', no http2');

	return $m;
}

/**
 * The server's processes, found by the port it listens on rather than by a
 * name that varies between installations.
 *
 * Returns the parent, its workers, and the memory each is holding. Worker RSS
 * over a run is the number that says whether a persistent server is leaking:
 * flat is healthy, and a slope is not.
 */
function serverProcesses($url)
{
	$port = parse_url($url, PHP_URL_PORT);
	if (!$port) $port = (parse_url($url, PHP_URL_SCHEME) === 'https') ? 443 : 80;

	$pids = array();
	// ss is present on any modern Linux; lsof is not.
	$out = array();
	@exec('ss -ltnp 2>/dev/null', $out);
	foreach ($out as $line) {
		if (strpos($line, ':' . $port . ' ') === false) continue;
		if (preg_match_all('/pid=(\d+)/', $line, $m)) {
			foreach ($m[1] as $pid) $pids[(int) $pid] = true;
		}
	}
	if (!$pids) return array();

	// A worker is a child of a listener, so walk up to the common parent and
	// back down. Simpler: take every process sharing the listener's binary.
	$any = array_keys($pids);
	$parent = null;
	$stat = @file_get_contents('/proc/' . $any[0] . '/stat');
	if ($stat !== false) {
		$f = explode(' ', $stat);
		$ppid = isset($f[3]) ? (int) $f[3] : 0;
		// If the listener's parent also listens, it is the master.
		$parent = isset($pids[$ppid]) ? $ppid : $any[0];
	}

	$procs = array();
	foreach (array_keys($pids) as $pid) {
		$status = @file_get_contents('/proc/' . $pid . '/status');
		if ($status === false) continue;
		$rss = 0;
		if (preg_match('/VmRSS:\s+(\d+)/', $status, $x)) $rss = (int) $x[1];
		$fds = @scandir('/proc/' . $pid . '/fd');
		$procs[$pid] = array(
			'pid' => $pid,
			'rssKb' => $rss,
			'fds' => is_array($fds) ? max(0, count($fds) - 2) : null,
			'isParent' => ($pid === $parent),
		);
	}
	ksort($procs);
	return $procs;
}

function totalRss(array $procs)
{
	$t = 0;
	foreach ($procs as $p) $t += $p['rssKb'];
	return $t;
}

// ── One batch at one concurrency ────────────────────────────────────────

/**
 * Fire $count requests with $concurrency in flight, and time each one.
 *
 * Every handle is timed individually rather than the batch as a whole, because
 * the batch total gives a mean and the mean is the one statistic that hides
 * what people complain about.
 */
function batch($url, $count, $concurrency, $http1, $timeout)
{
	$multi = curl_multi_init();
	$inFlight = array();
	$times = array();
	$statuses = array();
	$bytes = 0;
	$errors = 0;
	$sent = 0;

	$make = function () use ($url, $http1, $timeout) {
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_ENCODING => 'gzip, deflate, br',
			CURLOPT_HTTP_VERSION => $http1
				? CURL_HTTP_VERSION_1_1
				: CURL_HTTP_VERSION_2_0,
			// A fresh connection per request would measure the TLS handshake
			// rather than the server. Reuse is what a browser does.
			CURLOPT_FORBID_REUSE => false,
			CURLOPT_FRESH_CONNECT => false,
			CURLOPT_USERAGENT => 'qbix-bench',
		));
		return $ch;
	};

	$started = microtime(true);

	for ($i = 0; $i < $concurrency and $sent < $count; ++$i) {
		$ch = $make();
		curl_multi_add_handle($multi, $ch);
		$inFlight[(int) $ch] = true;
		++$sent;
	}

	do {
		curl_multi_exec($multi, $running);
		curl_multi_select($multi, 0.05);

		while (($info = curl_multi_info_read($multi)) !== false) {
			$ch = $info['handle'];
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$t = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

			if ($info['result'] !== CURLE_OK or $code < 200 or $code >= 400) {
				++$errors;
			} else {
				$times[] = $t * 1000;
				$bytes += (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
			}
			$statuses[$code] = (isset($statuses[$code]) ? $statuses[$code] : 0) + 1;

			curl_multi_remove_handle($multi, $ch);
			curl_close($ch);
			unset($inFlight[(int) $ch]);

			if ($sent < $count) {
				$next = $make();
				curl_multi_add_handle($multi, $next);
				$inFlight[(int) $next] = true;
				++$sent;
			}
		}
	} while ($running > 0 or $inFlight);

	curl_multi_close($multi);
	$elapsed = microtime(true) - $started;

	return array(
		'concurrency' => $concurrency,
		'requests' => $count,
		'ok' => count($times),
		'errors' => $errors,
		'statuses' => $statuses,
		'seconds' => $elapsed,
		'rps' => $elapsed > 0 ? count($times) / $elapsed : 0,
		'bytes' => $bytes,
		'times' => $times,
	);
}

function percentile(array $sorted, $p)
{
	if (!$sorted) return 0;
	$i = (int) ceil($p / 100 * count($sorted)) - 1;
	if ($i < 0) $i = 0;
	if ($i >= count($sorted)) $i = count($sorted) - 1;
	return $sorted[$i];
}

function stats(array $result)
{
	$t = $result['times'];
	sort($t);
	$n = count($t);
	$mean = $n ? array_sum($t) / $n : 0;
	$var = 0;
	foreach ($t as $v) $var += ($v - $mean) * ($v - $mean);
	return array(
		'min' => $n ? $t[0] : 0,
		'p50' => percentile($t, 50),
		'p90' => percentile($t, 90),
		'p99' => percentile($t, 99),
		'max' => $n ? $t[$n - 1] : 0,
		'mean' => $mean,
		'stddev' => $n > 1 ? sqrt($var / ($n - 1)) : 0,
	);
}

// ── Run ─────────────────────────────────────────────────────────────────

$before = machine();
$procsBefore = serverProcesses($url);

if (!$json) {
	printf("\n  %s\n", $url);
	printf("  %s, %d cores, %.1f GB total / %.1f GB available, load %.2f\n",
		$before['sapi'] === 'cli' ? 'php ' . $before['php'] : $before['sapi'],
		$before['cores'],
		$before['memTotalKb'] / 1048576, $before['memAvailableKb'] / 1048576,
		$before['load'][0]);
	printf("  curl %s | opcache %s\n", $before['curl'], $before['opcache']);
	if ($procsBefore) {
		printf("  server: %d process(es), %.1f MB resident, %s\n",
			count($procsBefore), totalRss($procsBefore) / 1024,
			$http1 ? 'testing HTTP/1.1' : 'testing HTTP/2');
	}
	echo "\n";
	printf("  %-6s %9s %9s %9s %9s %9s %9s %7s\n",
		'conc', 'req/s', 'p50 ms', 'p90 ms', 'p99 ms', 'max ms', 'stddev', 'errors');
	echo "  " . str_repeat('-', 76) . "\n";
}

// Warm the path so the first level is not measuring a cold cache.
if ($warmup > 0) batch($url, $warmup, 4, $http1, $timeout);

$runs = array();
foreach ($levels as $c) {
	$r = batch($url, $requests, $c, $http1, $timeout);
	$s = stats($r);
	$procs = serverProcesses($url);
	$load = machine();

	$runs[] = array(
		'concurrency' => $c,
		'requests' => $r['requests'],
		'rps' => $r['rps'],
		'ok' => $r['ok'],
		'errors' => $r['errors'],
		'statuses' => $r['statuses'],
		'seconds' => $r['seconds'],
		'bytes' => $r['bytes'],
		'latency' => $s,
		'server' => array(
			'processes' => count($procs),
			'rssKb' => totalRss($procs),
			'fds' => array_sum(array_map(function ($p) { return (int) $p['fds']; }, $procs)),
		),
		'host' => array(
			'load1' => $load['load'][0],
			'memAvailableKb' => $load['memAvailableKb'],
		),
	);

	if (!$json) {
		printf("  %-6d %9.1f %9.1f %9.1f %9.1f %9.1f %9.1f %7d\n",
			$c, $r['rps'], $s['p50'], $s['p90'], $s['p99'], $s['max'],
			$s['stddev'], $r['errors']);
	}
}

$after = machine();
$procsAfter = serverProcesses($url);

// ── Where it stopped getting faster ─────────────────────────────────────

$best = null;
foreach ($runs as $r) {
	if ($best === null or $r['rps'] > $best['rps']) $best = $r;
}

// The knee is the lowest concurrency reaching 95% of peak throughput. Past it,
// more concurrency buys queueing rather than work: latency climbs and req/s
// does not.
$knee = $best;
foreach ($runs as $r) {
	if ($r['rps'] >= $best['rps'] * 0.95) { $knee = $r; break; }
}

$leak = null;
if ($procsBefore and $procsAfter) {
	$leak = totalRss($procsAfter) - totalRss($procsBefore);
}

// ── Keep it ─────────────────────────────────────────────────────────────

/**
 * Write the sweep as a CSV, named so that two runs sort next to each other and
 * say what they were without being opened.
 *
 * One row per concurrency level, with the host and server context repeated on
 * each row. Repeating it is deliberate: a spreadsheet or a plot takes one flat
 * table and nothing else, and a header block above the data breaks every tool
 * that reads CSV.
 */
function writeCsv($dir, $url, $http1, $label, array $machine, array $runs,
	array $procsBefore, array $procsAfter, array $after)
{
	if (!$dir) return null;

	if (!is_dir($dir) and !@mkdir($dir, 0775, true) and !is_dir($dir)) {
		return null;
	}

	$host = parse_url($url, PHP_URL_HOST) . '_' . (parse_url($url, PHP_URL_PORT) ?: '');
	$path = trim((string) parse_url($url, PHP_URL_PATH), '/');
	$levels = array();
	foreach ($runs as $r) $levels[] = $r['concurrency'];

	$parts = array(
		'performance_results',
		date('Y_m_d'),
		'-',
		date('H-i-s'),
		preg_replace('/[^a-z0-9]+/i', '_', trim($host, '_')),
		$path === '' ? 'root' : preg_replace('/[^a-z0-9]+/i', '_', $path),
		$http1 ? 'http1' : 'http2',
		'c' . implode('-', $levels),
		'n' . (isset($runs[0]) ? $runs[0]['requests'] : 0),
		'w' . max(0, count($procsAfter) - 1),
	);
	if ($label !== '') $parts[] = preg_replace('/[^a-z0-9]+/i', '_', $label);

	$file = rtrim($dir, '/') . '/' . implode('_', array_filter($parts)) . '.csv';

	$fh = @fopen($file, 'wb');
	if (!$fh) return null;

	fputcsv($fh, array(
		'timestamp', 'url', 'protocol', 'label',
		'concurrency', 'requests', 'ok', 'errors',
		'req_per_sec', 'seconds', 'bytes',
		'min_ms', 'p50_ms', 'p90_ms', 'p99_ms', 'max_ms', 'mean_ms', 'stddev_ms',
		'server_processes', 'server_workers', 'server_rss_kb', 'server_fds',
		'host_load1', 'host_mem_available_kb', 'host_mem_total_kb', 'host_cores',
		'php_version', 'curl_version',
	));

	$stamp = date('c');
	foreach ($runs as $r) {
		fputcsv($fh, array(
			$stamp, $url, $http1 ? 'http/1.1' : 'http/2', $label,
			$r['concurrency'], $r['requests'], $r['ok'], $r['errors'],
			round($r['rps'], 2), round($r['seconds'], 3), $r['bytes'],
			round($r['latency']['min'], 2), round($r['latency']['p50'], 2),
			round($r['latency']['p90'], 2), round($r['latency']['p99'], 2),
			round($r['latency']['max'], 2), round($r['latency']['mean'], 2),
			round($r['latency']['stddev'], 2),
			$r['server']['processes'], max(0, $r['server']['processes'] - 1),
			$r['server']['rssKb'], $r['server']['fds'],
			$r['host']['load1'], $r['host']['memAvailableKb'],
			$machine['memTotalKb'], $machine['cores'],
			$machine['php'], $machine['curl'],
		));
	}
	fclose($fh);
	return $file;
}

$csv = writeCsv($outDir, $url, $http1, $label, $before, $runs,
	$procsBefore, $procsAfter, $after);

if ($json) {
	echo json_encode(array(
		'csv' => $csv,
		'url' => $url,
		'protocol' => $http1 ? 'http/1.1' : 'http/2',
		'machine' => $before,
		'runs' => $runs,
		'peak' => $best,
		'knee' => $knee,
		'rssDeltaKb' => $leak,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
	exit(0);
}

echo "\n";
printf("  peak      %.1f req/s at concurrency %d\n", $best['rps'], $best['concurrency']);
printf("  knee      concurrency %d reaches %.0f%% of peak at p99 %.0f ms\n",
	$knee['concurrency'], $knee['rps'] / $best['rps'] * 100, $knee['latency']['p99']);

$errors = 0;
foreach ($runs as $r) $errors += $r['errors'];
printf("  errors    %d across %d requests\n", $errors, count($levels) * $requests);

if ($procsAfter) {
	printf("  server    %d process(es), %.1f MB resident",
		count($procsAfter), totalRss($procsAfter) / 1024);
	if ($leak !== null) {
		printf(", %s%.1f MB over the run\n", $leak >= 0 ? '+' : '', $leak / 1024);
	} else {
		echo "\n";
	}
	$fds = array_sum(array_map(function ($p) { return (int) $p['fds']; }, $procsAfter));
	printf("  handles   %d open across the server\n", $fds);
}
if ($csv !== null) {
	printf("  written   %s\n", $csv);
} else if ($outDir !== false) {
	printf("  written   could not write under %s\n", $outDir);
}
printf("  host      load %.2f -> %.2f, %.1f GB available -> %.1f GB\n",
	$before['load'][0], $after['load'][0],
	$before['memAvailableKb'] / 1048576, $after['memAvailableKb'] / 1048576);

// ── What to do about it ─────────────────────────────────────────────────

echo "\n";
$workers = max(1, count($procsAfter) - 1);   // one of them is the listener
$cores = $before['cores'];

if ($errors > 0) {
	echo "  Errors appeared under load. Raising worker counts will not help until\n";
	echo "  what is failing is known -- check the server log for the levels above.\n";
} else if ($best['concurrency'] === end($levels)) {
	printf("  Throughput was still climbing at the highest concurrency tried (%d).\n", end($levels));
	printf("  Sweep further before drawing a conclusion: --levels=%s\n",
		implode(',', array_map(function ($n) { return $n * 2; }, $levels)));
} else if ($knee['concurrency'] <= $workers) {
	printf("  The knee is at or below the %d worker(s) running, so the workers are\n", $workers);
	echo "  not the limit -- more of them will not raise throughput. What is left is\n";
	echo "  the work each request does, or something shared beneath them.\n";
} else {
	printf("  The knee is at concurrency %d against %d worker(s). More workers is the\n",
		$knee['concurrency'], $workers);
	printf("  next thing to try; this machine has %d cores, and %.1f GB available at\n",
		$cores, $after['memAvailableKb'] / 1048576);
	printf("  about %.1f MB per worker.\n",
		$workers > 0 ? totalRss($procsAfter) / 1024 / max(1, count($procsAfter)) : 0);
}

if ($leak !== null and $leak > 51200) {
	printf("\n  Resident memory rose %.1f MB across the run. That may be caches filling\n", $leak / 1024);
	echo "  rather than a leak -- run the same sweep twice and compare the second\n";
	echo "  rise against the first. A flat second run is healthy; another climb is not.\n";
}

echo "\n";
exit(0);
