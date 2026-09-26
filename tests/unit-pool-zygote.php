<?php
/**
 * Workers forked from the zygote inherit no client connection.
 *
 * A worker forked from the server inherits every client connection open at
 * that moment. A plain one it closes at once; a TLS stream it cannot (PHP
 * closes one with SSL_shutdown(), which writes close_notify onto the
 * server's own live connection), so it parks it until the worker exits. A
 * dynamic pool forks as workers become busy, and on alpha a 20-second burst
 * of 355 rendered pages left up to 50 connections in CLOSE-WAIT, held by
 * workers alone, until those workers retired. With Q.webserver.zygote the
 * pool forks later workers from a process forked before the first
 * connection was accepted, which has none to pass on.
 *
 * Asserted over real TLS against a dynamic pool (one spare worker, so load
 * forks new ones), with keep-alive connections held open while it forks:
 *
 *   - without the zygote, a worker forked under load does hold client
 *     connections (the premise: this test can see the bug);
 *   - with it, the pool forks through the zygote and no worker, nor the
 *     zygote, holds any;
 *   - a worker that dies is reaped by the zygote at once, and the zygote
 *     keeps forking (it used to leave at the first worker's exit: recvmsg()
 *     reports the interruption in the global error, not on the socket);
 *   - a zygote that dies costs nothing but itself: requests are still
 *     answered and new workers are forked from the server, which says so;
 *   - stopping the server leaves no process behind.
 *
 *   php tests/unit-pool-zygote.php
 */
require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
if (!function_exists('socket_sendmsg') or !defined('SCM_RIGHTS')) { printf("  skip  needs ext-sockets with SCM_RIGHTS\n"); exit(0); }
list($base, $root) = rh_setup('zygote');
file_put_contents($root . DS . 'index.php', '<?php
if (isset($_GET["sleep"])) usleep((int) $_GET["sleep"] * 1000);
echo "ok ", getmypid();
');
$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem);
file_put_contents("$base/key.pem", $keyPem);

function tlsConnect($port)
{
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
	for ($i = 0; $i < 40; ++$i) {
		$s = @stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
		if ($s) return $s;
		usleep(250000);
	}
	return false;
}

/** One request on an open keep-alive connection; its status (0 on failure). */
function tlsGet($s, $path)
{
	fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: keep-alive\r\n\r\n");
	stream_set_timeout($s, 20);
	$head = '';
	while (!feof($s) and strpos($head, "\r\n\r\n") === false) {
		$line = fgets($s);
		if ($line === false) break;
		$head .= $line;
	}
	if (!preg_match('~^HTTP/1\.[01] (\d+)~', $head, $m)) return 0;
	if (preg_match('~\r\ncontent-length:\s*(\d+)~i', $head, $cl)) {
		$want = (int) $cl[1]; $got = 0;
		while ($got < $want and !feof($s)) {
			$c = fread($s, $want - $got);
			if ($c === false or $c === '') break;
			$got += strlen($c);
		}
	}
	return (int) $m[1];
}

/** $n slow requests at once, each on its own TLS connection: makes the pool fork. */
function tlsBurst($port, $n, $sleepMs)
{
	$socks = array();
	for ($i = 0; $i < $n; ++$i) {
		if ($s = tlsConnect($port)) {
			fwrite($s, "GET /index.php?sleep=$sleepMs&i=$i HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
			$socks[] = $s;
		}
	}
	$ok = 0;
	foreach ($socks as $s) {
		stream_set_timeout($s, 30);
		$r = stream_get_contents($s);
		if (is_string($r) and strpos($r, ' 200 ') !== false and strpos($r, 'ok ') !== false) ++$ok;
		fclose($s);
	}
	return $ok;
}

/** SIGKILL one process -- never 0 (this process group) or below. */
function killOne($p)
{
	if ((int) $p > 1) @posix_kill((int) $p, SIGKILL);
}

/** Descendants of $pid (children and grandchildren), pid => parent. */
function tree($pid)
{
	$out = array();
	foreach (array_keys(rh_children($pid)) as $c) {
		$out[$c] = $pid;
		foreach (array_keys(rh_children($c)) as $g) $out[$g] = $c;
	}
	return $out;
}

/**
 * Start a dynamic pool on TLS, hold $keep keep-alive connections open,
 * make it fork under load, and count client connections held by processes
 * other than the server. Returns array(port, tlsPort, serverPid, heldByOthers, burstOk).
 */
function scenario($name, $zygote, $keep = 6)
{
	$tlsPort = rh_free_port();
	$config = array('Q' => array(
		'web' => array('https' => array('mode' => 'manual', 'cert' => $GLOBALS['base'] . '/cert.pem',
			'key' => $GLOBALS['base'] . '/key.pem')),
		'webserver' => array('spareWorkers' => 1, 'idleWorkerTimeout' => 120, 'zygote' => $zygote),
	));
	$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
	$port = rh_start($name, $config, 12);
	$pid = rh_server_pid($name);
	// Connections open while the pool forks -- what a forked worker could
	// inherit. TLS is established; no request is sent yet.
	$held = array();
	for ($i = 0; $i < $keep; ++$i) {
		// Open and idle, as a browser holds one: a request would get a
		// worker-rendered answer, and those close the connection.
		if ($s = tlsConnect($tlsPort)) $held[] = $s;
	}
	$ok = tlsBurst($tlsPort, 8, 700);
	usleep(300000);
	$others = 0;
	foreach (array_keys(tree($pid)) as $p) $others += rh_tcp_held($p, $tlsPort);
	$GLOBALS['held_' . $name] = $held;   // keep them open until the end
	return array($port, $tlsPort, $pid, $others, $ok, count($held));
}

// ── The premise: without the zygote, a worker forked under load holds them ──
list(, , , $othersPlain, $okPlain, $keptPlain) = scenario('plain', false);
check('without the zygote: the burst is answered', $okPlain, 8);
check('without the zygote: keep-alive connections were open while it forked', $keptPlain, 6);
check('without the zygote: a worker forked under load holds client connections (the premise)', $othersPlain > 0, true);
rh_stop($GLOBALS['rh']['servers']['plain']);

// ── With the zygote: no worker holds one ─────────────────────────────────
list($port, $tlsPort, $pid, $othersZ, $okZ, $keptZ) = scenario('zygote', true);
check('with the zygote: the burst is answered', $okZ, 8);
check('with the zygote: keep-alive connections were open while it forked', $keptZ, 6);
rh_bug('with the zygote: no worker, nor the zygote, holds a client connection', $othersZ === 0,
	"a worker forked from the zygote inherited $othersZ client connection(s)");

// Which child is the zygote: the one with children of its own.
$zygotePid = 0;
foreach (array_keys(rh_children($pid)) as $c) {
	if (count(rh_children($c)) > 0) $zygotePid = $c;
}
check('the pool forked workers through the zygote', $zygotePid > 0, true);
$works = 0;
foreach ($GLOBALS['held_zygote'] as $s) if (tlsGet($s, '/index.php') === 200) ++$works;
check('the connections held open through the forks still work', $works, count($GLOBALS['held_zygote']));

// ── Signals at the server during hand-offs cost nothing ──────────────────
// A busy server takes SIGCHLD constantly (every worker it forked itself that
// exits), and each one interrupts whatever call it is blocked in. The
// hand-off to the zygote took an interrupted read for a dead zygote and
// stopped it: on alpha the first burst after every start. Recreated here by
// a process that showers the server with SIGCHLD while the pool forks.
$shower = pcntl_fork();
if ($shower === 0) {
	$until = microtime(true) + 6.0;
	while ($pid > 1 and microtime(true) < $until) { @posix_kill($pid, SIGCHLD); usleep(200); }
	posix_kill(getmypid(), SIGKILL);   // no shutdown functions: they would stop the servers
}
$before = count(rh_children($zygotePid));
$okShower = tlsBurst($tlsPort, 12, 600) + tlsBurst($tlsPort, 12, 600);
pcntl_waitpid($shower, $st);
check('requests are answered while signals rain on the server', $okShower, 24);
rh_bug('the zygote survives hand-offs interrupted by signals', file_exists("/proc/$zygotePid")
	and strpos(rh_log('zygote'), 'zygote:') === false,
	'an interrupted read of the zygote\'s reply was taken for a dead zygote, which the server then stopped');

// ── A worker dies: reaped at once, and the zygote carries on ─────────────
$zw = array_keys(rh_children($zygotePid));
$victim = $zw[0] ?? 0;
killOne($victim);
usleep(500000);
$states = rh_children($zygotePid);
check('a killed worker is reaped by the zygote at once (no zombie)', isset($states[$victim]), false);
check('the zygote outlives a worker', file_exists("/proc/$zygotePid"), true);
check('requests are still answered after a worker died', tlsBurst($tlsPort, 6, 400), 6);
check('the zygote forked the replacement', count(rh_children($zygotePid)) > 0, true);

// ── The zygote dies: nothing but itself is lost ──────────────────────────
// Its workers too, so the pool has to fork: listed first, since once the
// zygote is gone they belong to init and no longer show under it.
$orphans = array_keys(rh_children($zygotePid));
killOne($zygotePid);
foreach ($orphans as $w) killOne($w);
usleep(800000);
check('requests are still answered after the zygote died', tlsBurst($tlsPort, 8, 400), 8);
check('...and the server says it now forks workers itself',
	strpos(rh_log('zygote'), 'zygote:') !== false, true);
$zombies = count(array_filter(rh_children($pid), function ($s) { return $s === 'Z'; }));
check('no zombie under the server', $zombies, 0);

// ── Stop: nothing left ───────────────────────────────────────────────────
foreach ($GLOBALS['held_zygote'] as $s) @fclose($s);
$all = array_keys(tree($pid));
rh_stop($GLOBALS['rh']['servers']['zygote']);
usleep(500000);
$left = 0;
foreach (array_merge(array($pid), $all) as $p) if (file_exists("/proc/$p") and (@file_get_contents("/proc/$p/stat") ?: '') !== '') {
	$st = @file_get_contents("/proc/$p/stat");
	if ($st and substr($st, strrpos($st, ')') + 2, 1) !== 'Z') ++$left;
}
check('stopping the server leaves no process behind', $left, 0);

rh_finish();
