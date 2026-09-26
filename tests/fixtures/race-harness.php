<?php

/**
 * Shared scaffolding for the tests/unit-race-*.php concurrency tests.
 *
 * Not a test itself (tests/run-unit.php only runs tests/unit-*.php). It holds
 * what every race test needs and must get exactly right, so it is written
 * once:
 *
 *   - a private temporary directory per test process, removed on exit;
 *   - servers started from the source tree (qbixserver.php) on a free random
 *     port, with proc_open descriptors rather than shell redirection so the
 *     pid we hold is the server's, and terminated on exit (TERM, wait, KILL);
 *   - a non-blocking HTTP/1.1 client that keeps N requests in flight at once
 *     with stream_select(), and reports for each one its status, headers,
 *     body, and whether the body was complete (Content-Length honoured);
 *   - check() / rh_bug() / rh_finish() producing the PASS/FAIL line the runner reads.
 *
 * A test that proves a real engine defect calls rh_bug(), which prints
 * "FAILS: real bug" with the description and fails the test. The defect is
 * then documented in that test's docblock; nothing here fixes src/.
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

$GLOBALS['rh'] = array(
	'pass' => 0, 'fail' => 0, 'bugs' => array(),
	'base' => null, 'root' => null, 'servers' => array(), 'names' => array(),
	'children' => array(),
);

function check($what, $got, $want)
{
	if ($got === $want) { ++$GLOBALS['rh']['pass']; return true; }
	++$GLOBALS['rh']['fail'];
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		rh_short(var_export($got, true)), rh_short(var_export($want, true)));
	return false;
}

/** A condition that, when false, is a real engine defect -- not a flaky test. */
function rh_bug($what, $ok, $description)
{
	if ($ok) { ++$GLOBALS['rh']['pass']; return true; }
	++$GLOBALS['rh']['fail'];
	$GLOBALS['rh']['bugs'][] = $what;
	static $told = array();
	if (isset($told[$description])) {
		printf("  FAIL  %s\n  FAILS: real bug -- (same as above)\n", $what);
	} else {
		$told[$description] = true;
		printf("  FAIL  %s\n  FAILS: real bug -- %s\n", $what, $description);
	}
	return false;
}

/**
 * Responses whose body arrived complete but whose connection the server never
 * closed. That is the inherited-client-socket defect proved by
 * unit-race-worker-replacement.php; any test that makes the pool fork a
 * replacement worker trips over it. Such a test counts those responses by
 * their content and notes the count here, rather than failing a second time
 * for a defect that is not the property it tests.
 */
function rh_note_no_eof($label, $results)
{
	$n = 0;
	foreach ($results as $r) if ($r['error'] === 'no EOF after complete response') ++$n;
	if ($n) rh_note("$label: $n complete response(s) whose connection was never closed"
		. ' (known bug, see unit-race-worker-replacement.php)');
	return $n;
}

/** Whether a result is a transport failure other than the known no-EOF one. */
function rh_transport_error($r)
{
	return $r['error'] !== '' and $r['error'] !== 'no EOF after complete response';
}

function rh_note($line) { printf("  note  %s\n", $line); }

function rh_short($s, $max = 400)
{
	return strlen($s) > $max ? substr($s, 0, $max) . '...(' . strlen($s) . ' bytes)' : $s;
}

function rh_require_pool()
{
	if (!function_exists('pcntl_fork') or !function_exists('posix_kill')) {
		printf("  skip  needs pcntl and posix for the worker pool\n");
		exit(0);
	}
}

/** Create this test's private directory and document root. */
function rh_setup($tag)
{
	$base = sys_get_temp_dir() . DS . 'qbix-' . $tag . '-' . getmypid();
	$root = $base . DS . 'web';
	@mkdir($root, 0700, true);
	$GLOBALS['rh']['base'] = $base;
	$GLOBALS['rh']['root'] = $root;
	register_shutdown_function('rh_cleanup');
	return array($base, $root);
}

/** Start a helper process (a file writer, say); terminated on exit. */
function rh_spawn($script, $args = array(), $log = null)
{
	$cmd = array(PHP_BINARY, $script);
	foreach ($args as $a) $cmd[] = (string) $a;
	$log = $log ?: $GLOBALS['rh']['base'] . DS . 'helper-' . count($GLOBALS['rh']['children']) . '.log';
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', $log, 'w'),
		2 => array('file', $log, 'a'),
	), $pipes);
	if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start helper\n"); exit(1); }
	$GLOBALS['rh']['children'][] = $proc;
	return $proc;
}

/** Wait for a helper to finish on its own (up to $timeout s), then stop it. */
function rh_wait($proc, $timeout = 20.0)
{
	$until = microtime(true) + $timeout;
	while (microtime(true) < $until) {
		$st = @proc_get_status($proc);
		if (!$st or empty($st['running'])) return true;
		usleep(20000);
	}
	rh_stop($proc);
	return false;
}

function rh_stop($proc)
{
	$st = @proc_get_status($proc);
	if (!$st) return;
	$pid = (int) ($st['pid'] ?? 0);
	if (!empty($st['running']) and $pid > 0) {
		@proc_terminate($proc, 15);
		for ($i = 0; $i < 40; ++$i) {
			$now = @proc_get_status($proc);
			if (!$now or empty($now['running'])) break;
			usleep(250000);
		}
		$now = @proc_get_status($proc);
		if ($now and !empty($now['running'])) {
			@proc_terminate($proc, 9);
			@posix_kill($pid, 9);
		}
	}
}

function rh_cleanup()
{
	foreach ($GLOBALS['rh']['children'] as $p) { rh_stop($p); @proc_close($p); }
	foreach ($GLOBALS['rh']['servers'] as $p) { rh_stop($p); @proc_close($p); }
	$base = $GLOBALS['rh']['base'];
	if ($base and is_dir($base) and strpos($base, sys_get_temp_dir()) === 0) rh_rmtree($base);
}

function rh_rmtree($dir)
{
	foreach (scandir($dir) ?: array() as $e) {
		if ($e === '.' or $e === '..') continue;
		$p = $dir . DS . $e;
		if (is_dir($p) and !is_link($p)) rh_rmtree($p); else @unlink($p);
	}
	@rmdir($dir);
}

function rh_free_port()
{
	for ($i = 0; $i < 60; ++$i) {
		$p = 21000 + random_int(0, 8000);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

/**
 * Start a server from the source tree. Returns its port. The config always
 * turns the reverse proxy cache off, so every request reaches a worker.
 */
function rh_start($name, $config = array(), $workers = 3, $withPid = true)
{
	$base = $GLOBALS['rh']['base'];
	$root = $GLOBALS['rh']['root'];
	$port = rh_free_port();
	if (!$port) { fwrite(STDERR, "  FAIL - no free port\n"); exit(1); }
	if (!isset($config['Q']['web']['cache'])) $config['Q']['web']['cache'] = array('enabled' => false);
	if (!isset($config['Q']['compat'])) $config['Q']['compat'] = array('skipSourceCodeTransform' => false);
	file_put_contents("$base/$name.json", json_encode($config));
	$cmd = array(PHP_BINARY, __DIR__ . '/../../qbixserver.php',
		'--config=' . "$base/$name.json", '--root=' . $root,
		'--port=' . $port, '--workers=' . (int) $workers);
	if ($withPid) $cmd[] = '--pid=' . "$base/$name.pid";
	// Extra command-line options a test needs (e.g. --https-port=N).
	foreach ($GLOBALS['rh_extra_args'] ?? array() as $a) $cmd[] = (string) $a;
	$proc = proc_open($cmd, array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', "$base/$name.log", 'w'),
		2 => array('file', "$base/$name.log", 'a'),
	), $pipes);
	if (!is_resource($proc)) { fwrite(STDERR, "  FAIL - could not start $name\n"); exit(1); }
	$GLOBALS['rh']['servers'][$name] = $proc;
	$GLOBALS['rh']['names'][] = $name;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); return $port; }
		usleep(500000);
	}
	fwrite(STDERR, "  FAIL - $name never listened\n" . @file_get_contents("$base/$name.log"));
	exit(1);
}

function rh_server_pid($name)
{
	$st = @proc_get_status($GLOBALS['rh']['servers'][$name]);
	return $st ? (int) $st['pid'] : 0;
}

function rh_log($name)
{
	return (string) @file_get_contents($GLOBALS['rh']['base'] . DS . $name . '.log');
}

/** Direct children of $pid, as pid => state letter (R, S, Z, ...), from /proc. */
function rh_children($pid)
{
	$out = array();
	foreach (glob('/proc/[0-9]*/stat') ?: array() as $f) {
		$s = @file_get_contents($f);
		if ($s === false) continue;
		// pid (comm) state ppid ... ; comm may contain spaces and parens.
		$r = strrpos($s, ')');
		if ($r === false) continue;
		$rest = explode(' ', trim(substr($s, $r + 2)));
		if ((int) ($rest[1] ?? 0) === $pid) $out[(int) $s] = $rest[0];
	}
	return $out;
}

/** Whether $pid is the pool's zygote (it names itself "qbixserver: zygote"). */
function rh_is_zygote($pid)
{
	return strpos((string) @file_get_contents("/proc/$pid/cmdline"), 'qbixserver: zygote') !== false;
}

/**
 * The server's workers, as pid => state letter: its children other than the
 * zygote, plus the zygote's children -- where the pool forks workers once it
 * has one (Q.webserver.zygote).
 */
function rh_workers($pid)
{
	$out = array();
	foreach (rh_children($pid) as $c => $s) {
		if (rh_is_zygote($c)) {
			$out += rh_children($c);
		} else {
			$out[$c] = $s;
		}
	}
	return $out;
}

/** The zygote's pid, or 0 when the server has none. */
function rh_zygote($pid)
{
	foreach (array_keys(rh_children($pid)) as $c) if (rh_is_zygote($c)) return $c;
	return 0;
}

/** Live (non-zombie) worker count, polled until it equals $want or $timeout. */
function rh_wait_children($pid, $want, $timeout = 5.0)
{
	$until = microtime(true) + $timeout;
	do {
		$live = count(array_filter(rh_workers($pid), function ($s) { return $s !== 'Z'; }));
		if ($live === $want) return $live;
		usleep(50000);
	} while (microtime(true) < $until);
	return $live;
}

/**
 * TCP connections to $port (not the listener) that process $pid holds open,
 * from /proc/<pid>/fd and /proc/net/tcp{,6}. A worker should hold none: the
 * client connections belong to the parent.
 */
function rh_tcp_held($pid, $port)
{
	$inodes = array();
	foreach (array('/proc/net/tcp', '/proc/net/tcp6') as $f) {
		foreach (array_slice(explode("\n", (string) @file_get_contents($f)), 1) as $l) {
			$c = preg_split('/\s+/', trim($l));
			if (count($c) < 10) continue;
			$local = explode(':', $c[1]);
			if (hexdec(end($local)) !== $port or $c[3] === '0A') continue;
			$inodes[$c[9]] = $c[3];
		}
	}
	$held = 0;
	foreach (glob("/proc/$pid/fd/*") ?: array() as $fd) {
		$t = @readlink($fd);
		if ($t and preg_match('/^socket:\[(\d+)\]$/', $t, $m) and isset($inodes[$m[1]])) ++$held;
	}
	return $held;
}

/**
 * One request, blocking. Returns the same shape as rh_many() entries.
 */
function rh_get($port, $path, $timeout = 15.0, $method = 'GET', $body = '', $headers = array())
{
	$r = rh_many($port, array(array('path' => $path, 'method' => $method,
		'body' => $body, 'headers' => $headers)), 1, $timeout);
	return $r[0];
}

/**
 * Many requests, $concurrency in flight at once, over separate connections.
 *
 * Each request is array('path' => ..., 'method' => 'GET', 'body' => '',
 * 'headers' => array(), 'writeDelay' => 0 (µs between bytes), 'readDelay' => 0
 * (µs between reads), 'onStart' => callable). Each result is array('status',
 * 'headers' (lowercased), 'body', 'complete' (body length matched
 * Content-Length), 'error' (string or ''), 'raw', 'ms').
 *
 * Driven by stream_select over non-blocking sockets. The server closes the
 * connection after each pooled response, so a response ends at EOF; the
 * Content-Length it declared is then compared with what arrived, which is how
 * a truncated body is told apart from a short one.
 */
function rh_many($port, $requests, $concurrency, $timeout = 30.0, $tick = null, $eofGrace = 3.0)
{
	$results = array();
	$queue = array_keys($requests);
	$live = array();   // id => state
	$deadline = microtime(true) + $timeout;

	$open = function ($id) use ($port, $requests, &$live) {
		$q = $requests[$id];
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5,
			STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
		if (!$s) return array('status' => 0, 'headers' => array(), 'body' => '',
			'complete' => false, 'error' => "connect: $es", 'raw' => '', 'ms' => 0);
		stream_set_blocking($s, false);
		$method = $q['method'] ?? 'GET';
		$body = $q['body'] ?? '';
		$head = "$method {$q['path']} HTTP/1.1\r\nHost: 127.0.0.1:$port\r\n";
		$hdrs = $q['headers'] ?? array();
		if (!isset($hdrs['Connection'])) $hdrs['Connection'] = 'close';
		if ($body !== '' or $method === 'POST') {
			$hdrs['Content-Length'] = strlen($body);
			if (!isset($hdrs['Content-Type'])) $hdrs['Content-Type'] = 'application/octet-stream';
		}
		foreach ($hdrs as $k => $v) $head .= "$k: $v\r\n";
		$live[$id] = array('sock' => $s, 'out' => $head . "\r\n" . $body, 'in' => '',
			'started' => microtime(true), 'writeDelay' => (int) ($q['writeDelay'] ?? 0),
			'readDelay' => (int) ($q['readDelay'] ?? 0), 'nextIo' => 0.0, 'completeAt' => 0.0);
		if (isset($q['onStart'])) call_user_func($q['onStart'], $id);
		return null;
	};

	$finish = function ($id, $error = '') use (&$live, &$results) {
		$st = $live[$id];
		@fclose($st['sock']);
		unset($live[$id]);
		$results[$id] = rh_parse($st['in'], $error);
		$results[$id]['ms'] = (microtime(true) - $st['started']) * 1000;
	};

	while ($queue or $live) {
		while ($queue and count($live) < $concurrency) {
			$id = array_shift($queue);
			$fail = $open($id);
			if ($fail) $results[$id] = $fail;
		}
		if (!$live) continue;
		if (microtime(true) > $deadline) {
			foreach (array_keys($live) as $id) $finish($id, 'timeout');
			break;
		}
		if ($tick) call_user_func($tick);
		$now = microtime(true);
		// A response that is complete by its Content-Length but whose
		// connection the server never closes: reported, not waited out.
		foreach ($live as $id => $st) {
			if ($st['completeAt'] and $now - $st['completeAt'] > $eofGrace) {
				$finish($id, 'no EOF after complete response');
			}
		}
		if (!$live) continue;
		$r = array(); $w = array(); $map = array();
		foreach ($live as $id => $st) {
			if ($st['nextIo'] > $now) continue;
			$map[(int) $st['sock']] = $id;
			if ($st['out'] !== '') $w[] = $st['sock'];
			else $r[] = $st['sock'];
		}
		if (!$r and !$w) { usleep(1000); continue; }
		$e = null;
		$n = @stream_select($r, $w, $e, 0, 20000);
		if ($n === false) { usleep(1000); continue; }
		foreach ($w as $s) {
			$id = $map[(int) $s];
			$st = &$live[$id];
			$chunk = $st['writeDelay'] ? substr($st['out'], 0, 1) : $st['out'];
			$wrote = @fwrite($s, $chunk);
			if ($wrote === false) { unset($st); $finish($id, 'write failed'); continue; }
			$st['out'] = (string) substr($st['out'], $wrote);
			if ($st['writeDelay']) $st['nextIo'] = microtime(true) + $st['writeDelay'] / 1e6;
			unset($st);
		}
		foreach ($r as $s) {
			$id = $map[(int) $s];
			if (!isset($live[$id])) continue;
			$st = &$live[$id];
			$chunk = @fread($s, $st['readDelay'] ? 4096 : 262144);
			if ($chunk === '' or $chunk === false) {
				if (feof($s)) { unset($st); $finish($id); continue; }
			} else {
				$st['in'] .= $chunk;
				if ($st['readDelay']) $st['nextIo'] = microtime(true) + $st['readDelay'] / 1e6;
				$p = strpos($st['in'], "\r\n\r\n");
				if (!$st['completeAt'] and $p !== false
					and preg_match('/\r\ncontent-length:\s*(\d+)/i', substr($st['in'], 0, $p), $m)
					and strlen($st['in']) >= $p + 4 + (int) $m[1]) {
					$st['completeAt'] = microtime(true);
					// A keep-alive response ends at its Content-Length, not EOF.
					if (!empty($requests[$id]['headers']['Connection'])
						and strcasecmp($requests[$id]['headers']['Connection'], 'keep-alive') === 0) {
						unset($st); $finish($id); continue;
					}
				}
			}
			unset($st);
		}
	}
	ksort($results);
	return $results;
}

/** Split a raw HTTP/1.1 response. */
function rh_parse($raw, $error = '')
{
	$out = array('status' => 0, 'headers' => array(), 'body' => '', 'complete' => false,
		'error' => $error, 'raw' => $raw);
	$p = strpos($raw, "\r\n\r\n");
	if ($p === false) { if ($out['error'] === '') $out['error'] = 'no response head'; return $out; }
	$lines = explode("\r\n", substr($raw, 0, $p));
	if (preg_match('#^HTTP/1\.[01] (\d{3})#', $lines[0], $m)) $out['status'] = (int) $m[1];
	foreach (array_slice($lines, 1) as $l) {
		$c = strpos($l, ':');
		if ($c !== false) $out['headers'][strtolower(trim(substr($l, 0, $c)))] = trim(substr($l, $c + 1));
	}
	$body = (string) substr($raw, $p + 4);
	if (isset($out['headers']['transfer-encoding'])
		and stripos($out['headers']['transfer-encoding'], 'chunked') !== false) {
		$dec = ''; $ok = false; $i = 0;
		while (true) {
			$nl = strpos($body, "\r\n", $i);
			if ($nl === false) break;
			$len = hexdec(trim(substr($body, $i, $nl - $i)));
			if ($len === 0) { $ok = true; break; }
			$dec .= substr($body, $nl + 2, $len);
			$i = $nl + 2 + $len + 2;
		}
		$out['body'] = $dec;
		$out['complete'] = $ok;
	} elseif (isset($out['headers']['content-length'])) {
		$want = (int) $out['headers']['content-length'];
		$out['complete'] = strlen($body) === $want;
		$out['body'] = substr($body, 0, $want);
	} else {
		$out['body'] = $body;
		$out['complete'] = true;
	}
	return $out;
}

/** Print the verdict the runner reads, with server log tails on failure. */
function rh_finish()
{
	$rh = $GLOBALS['rh'];
	if ($rh['fail']) {
		printf("\n  FAIL - %d of %d case(s)%s\n", $rh['fail'], $rh['pass'] + $rh['fail'],
			$rh['bugs'] ? ' (FAILS: real bug: ' . implode('; ', $rh['bugs']) . ')' : '');
		foreach ($rh['names'] as $n) {
			printf("  %s log (tail):\n", $n);
			foreach (array_slice(explode("\n", rh_log($n)), -10) as $l) printf("    %s\n", $l);
		}
		exit(1);
	}
	printf("  PASS - %d case(s)\n", $rh['pass']);
	exit(0);
}
