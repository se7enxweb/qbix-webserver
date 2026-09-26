<?php

/**
 * A dynamic worker pool runs only the workers it needs.
 *
 * Every worker costs its own memory from the moment it is forked -- ~10 MB of
 * real RAM each on an Exponential install, idle or not -- so a fixed pool of
 * 590 spent gigabytes on workers that never served anything. With
 * Q.webserver.spareWorkers set, the pool starts that many, forks more when
 * every worker is busy (up to Q.webserver.workers), and retires the extras
 * once they have been idle for Q.webserver.idleWorkerTimeout seconds.
 *
 * Asserted against real servers:
 *
 *   - a dynamic pool starts with the spare count, not the maximum;
 *   - concurrent requests beyond it are served in parallel by forked workers,
 *     never by more than the maximum, and every one is answered;
 *   - once idle, the extras are retired back to the spare count, leaving no
 *     zombie processes and the pid file in place;
 *   - a worker killed outright is not left a zombie and the pool keeps serving;
 *   - a fixed pool (no spareWorkers) is unchanged: all workers, all the time.
 *
 *   php tests/unit-worker-pool-dynamic.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

if (!function_exists('pcntl_fork')) { printf("  skip  needs pcntl for the worker pool\n"); exit(0); }
if (!is_dir('/proc/self')) { printf("  skip  needs /proc to count workers\n"); exit(0); }

$serverScript = __DIR__ . '/../qbixserver.php';
$base = sys_get_temp_dir() . DS . 'qbix-dynpool-' . getmypid();
$root = $base . DS . 'web';
@mkdir($root, 0700, true);

file_put_contents($root . DS . 'index.php', '<?php
if (isset($_GET["sleep"])) usleep((int) $_GET["sleep"] * 1000);
echo getmypid();
');

$servers = array();
register_shutdown_function(function () use (&$servers, $base, $root) {
	foreach ($servers as $proc) {
		$st = @proc_get_status($proc);
		if ($st and !empty($st['running'])) {
			@proc_terminate($proc, 15);
			for ($i = 0; $i < 40; ++$i) {
				$now = @proc_get_status($proc);
				if (!$now or empty($now['running'])) break;
				usleep(250000);
			}
			$now = @proc_get_status($proc);
			if ($now and !empty($now['running'])) @proc_terminate($proc, 9);
		}
		@proc_close($proc);
	}
	foreach (glob($base . '/*') ?: array() as $f) if (is_file($f)) @unlink($f);
	@unlink($root . '/index.php'); @rmdir($root); @rmdir($base);
});

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 21000 + random_int(0, 600);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/** @return array(port, server pid) */
function startServer($name, $workers, $config, &$servers)
{
	global $serverScript, $base, $root;
	$port = freePort();
	if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }
	file_put_contents("$base/$name.json", json_encode($config));
	$cmd = array(PHP_BINARY, $serverScript, "--config=$base/$name.json",
		"--root=$root", "--port=$port", "--workers=$workers", "--pid=$base/$name.pid");
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', "$base/$name.log", 'w'),
		2 => array('file', "$base/$name.log", 'a'),
	), $pipes);
	if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start\n"); exit(1); }
	$servers[] = $proc;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); break; }
		usleep(500000);
	}
	if (!$s) { fwrite(STDERR, "  FAIL - $name never listened\n" . @file_get_contents("$base/$name.log")); exit(1); }
	$st = proc_get_status($proc);
	return array($port, (int) $st['pid']);
}

/** Whether $pid is the pool's zygote (it names itself "qbixserver: zygote"). */
function isZygote($pid)
{
	return strpos((string) @file_get_contents("/proc/$pid/cmdline"), 'qbixserver: zygote') !== false;
}

/** Workers of $pid by state letter, e.g. array('S' => 3, 'Z' => 0). */
function children($pid)
{
	$by = array('all' => 0, 'Z' => 0);
	foreach (glob('/proc/[0-9]*/stat') ?: array() as $f) {
		$s = @file_get_contents($f);
		if ($s === false) continue;
		$rest = substr($s, strrpos($s, ')') + 2);
		$bits = explode(' ', $rest);
		if ((int) $bits[1] !== $pid) continue;
		$c = (int) basename(dirname($f));
		if (isZygote($c)) {
			// workers are forked by the zygote once there is one
			$z = children($c);
			$by['all'] += $z['all']; $by['Z'] += $z['Z'];
			continue;
		}
		++$by['all'];
		if ($bits[0] === 'Z') ++$by['Z'];
	}
	return $by;
}

function childPids($pid)
{
	$out = array();
	foreach (glob('/proc/[0-9]*/stat') ?: array() as $f) {
		$s = @file_get_contents($f);
		if ($s === false) continue;
		$bits = explode(' ', substr($s, strrpos($s, ')') + 2));
		if ((int) $bits[1] !== $pid or $bits[0] === 'Z') continue;
		$c = (int) basename(dirname($f));
		if (isZygote($c)) $out = array_merge($out, childPids($c)); else $out[] = $c;
	}
	return $out;
}

/** Wait until $test() is true or $seconds pass; returns the last value. */
function waitFor($seconds, $test)
{
	$end = microtime(true) + $seconds;
	do {
		$v = $test();
		if ($v === true) return true;
		usleep(200000);
	} while (microtime(true) < $end);
	return $test();
}

/** Send $n requests at once; returns array of array(status, body). */
function concurrent($port, $n, $path)
{
	$socks = array();
	for ($i = 0; $i < $n; ++$i) {
		$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
		fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
		$socks[$i] = $s;
	}
	$out = array();
	foreach ($socks as $i => $s) {
		stream_set_timeout($s, 30);
		// One response: headers, then Content-Length bytes (or chunks),
		// without waiting for the server to close a kept-alive connection.
		$head = '';
		while (!feof($s) and strpos($head, "\r\n\r\n") === false) {
			$line = fgets($s);
			if ($line === false) break;
			$head .= $line;
		}
		$status = preg_match('~^HTTP/1\.[01] (\d+)~', $head, $m) ? (int) $m[1] : 0;
		$body = '';
		if (preg_match('~\r\ncontent-length:\s*(\d+)~i', $head, $m)) {
			$want = (int) $m[1];
			while (strlen($body) < $want and !feof($s)) {
				$chunk = fread($s, $want - strlen($body));
				if ($chunk === false or $chunk === '') break;
				$body .= $chunk;
			}
		} elseif (stripos($head, 'transfer-encoding: chunked') !== false) {
			while (($size = fgets($s)) !== false and ($n2 = hexdec(trim($size))) > 0) {
				$body .= stream_get_contents($s, $n2);
				fgets($s);
			}
		}
		fclose($s);
		$out[] = array($status, trim($body));
	}
	return $out;
}

// ── Dynamic: spare 2, maximum 8, extras retired after 2 s idle ──────────

list($port, $spid) = startServer('dynamic', 8, array('Q' => array('webserver' => array(
	'spareWorkers' => 2, 'idleWorkerTimeout' => 2))), $servers);

$c = children($spid);
check('a dynamic pool starts with its spare count, not its maximum', $c['all'], 2);

// Six requests that each hold a worker for a second, sent together.
$t0 = microtime(true);
$res = concurrent($port, 6, '/index.php?sleep=1000');
$took = microtime(true) - $t0;
$ok = 0; $pids = array();
foreach ($res as $r) { if ($r[0] === 200 and ctype_digit($r[1])) { ++$ok; $pids[$r[1]] = true; } }
check('six concurrent requests are all answered', $ok, 6);
check('...by six different workers (forked on demand)', count($pids), 6);
check(sprintf('...in parallel, not queued behind two (%.1fs)', $took), $took < 2.5, true);
check('the pool grew to serve them', children($spid)['all'] >= 6, true);

// Twenty at once: never more than the maximum, nothing dropped.
$res = concurrent($port, 20, '/index.php?sleep=300');
$ok = 0; $pids = array();
foreach ($res as $r) { if ($r[0] === 200 and ctype_digit($r[1])) { ++$ok; $pids[$r[1]] = true; } }
check('twenty concurrent requests are all answered', $ok, 20);
check('...by no more than the maximum of eight workers', count($pids) <= 8, true);
check('the pool never exceeds its maximum', children($spid)['all'] <= 8, true);

// Idle: back to the spare count, no zombies left behind.
$back = waitFor(10, function () use ($spid) { return children($spid)['all'] === 2 ? true : children($spid)['all']; });
check('idle extras are retired back to the spare count', $back, true);
check('...without leaving zombie processes', waitFor(5, function () use ($spid) {
	return children($spid)['Z'] === 0 ? true : children($spid)['Z']; }), true);
check('...and the pid file is still in place', is_file("$base/dynamic.pid"), true);

$res = concurrent($port, 1, '/index.php');
check('the pool still serves after retiring workers', $res[0][0], 200);

// Stays at the spare count: it never retires below it.
usleep(3500000);
check('it never retires below the spare count', children($spid)['all'], 2);

// A worker killed outright is reaped and the pool keeps serving.
$kids = childPids($spid);
if ($kids) posix_kill($kids[0], 9);
$res = concurrent($port, 4, '/index.php?sleep=100');
$ok = 0; $got = array(); foreach ($res as $r) { if ($r[0] === 200) ++$ok; $got[] = $r[0] . ' ' . substr($r[1], 0, 60); }
check('after a worker is killed, requests are still answered', $ok === 4 ? 4 : implode(' | ', $got), 4);
check('...and the killed worker does not stay a zombie', waitFor(8, function () use ($spid) {
	return children($spid)['Z'] === 0 ? true : children($spid)['Z']; }), true);
check('...and the pool returns to its spare count', waitFor(10, function () use ($spid) {
	$n = children($spid)['all']; return $n === 2 ? true : $n; }), true);

// ── Fixed: no spareWorkers is the old behaviour ─────────────────────────

list($port2, $spid2) = startServer('fixed', 3, array('Q' => array('webserver' => array(
	'idleWorkerTimeout' => 1))), $servers);
check('a fixed pool starts all its workers', children($spid2)['all'], 3);
$res = concurrent($port2, 5, '/index.php?sleep=200');
$ok = 0; foreach ($res as $r) if ($r[0] === 200) ++$ok;
check('a fixed pool queues what it cannot serve at once and answers it', $ok, 5);
usleep(3000000);
check('a fixed pool keeps all its workers when idle', children($spid2)['all'], 3);

printf("%s  %d passed, %d failed\n", $fail ? 'FAIL' : 'PASS', $pass, $fail);
exit($fail ? 1 : 0);
