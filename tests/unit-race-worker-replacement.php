<?php

/**
 * Workers replaced after every request, under concurrent load, never cost a
 * request, never mix two requests up, never leave zombies, and never take the
 * server's pid file with them.
 *
 * The race it guards: a worker whose health check fails (heap over
 * Q.webserver.workerMemoryCeiling) or which called
 * Q_WebServer_Pool::retireAfterResponse() answers its request and is then
 * replaced by the parent (Pool::onWorkerData -> recycle -> forkWorker). With
 * requests queued (more clients than workers), recycle() hands the head of the
 * pending queue to the freshly forked worker in the same step. If any of that
 * bookkeeping is keyed wrongly -- a client socket left attached to the old
 * worker index, a response buffer not reset, a queued request dispatched twice
 * or never -- the symptoms are exactly what this asserts against:
 *
 *   - a 502 "Worker died" (EOF from the retiring worker taken as a crash);
 *   - a truncated body (response bigger than the 64 KB the parent reads per
 *     wake-up, reassembled across a replacement), detected by comparing the
 *     bytes that arrived with Content-Length;
 *   - a body that belongs to another request, detected because every request
 *     carries a unique token in its query string and the script echoes it
 *     three times in a body whose length also depends on the token;
 *   - a hang (a queued request never dispatched), caught by the client timeout.
 *
 * Also asserted while the load runs:
 *
 *   - the pid file (--pid) exists and names the parent at every poll: a
 *     replaced worker inherits the parent's shutdown functions and once
 *     deleted the pid file as it exited;
 *   - afterwards the pool is back to exactly --workers live children and none
 *     is left a zombie (every replaced worker has been reaped).
 *
 * Three servers: ceiling=1 MB (every worker fails its health check after each
 * request), retireAfterResponse() on every request, and a mix of retiring and
 * ordinary requests.
 *
 *   php tests/unit-race-worker-replacement.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-replace');

file_put_contents($root . DS . 'index.php', '<?php
$t = isset($_GET["t"]) ? $_GET["t"] : "none";
if (!preg_match("/^[a-z0-9]+$/", $t)) { echo "bad token"; return; }
$a = isset($_GET["a"]) ? $_GET["a"] : "";
if ($a === "retire" or ($a === "mix" and hexdec(substr(md5($t), 0, 2)) % 2)) {
	Q_WebServer_Pool::retireAfterResponse("race test " . $t);
}
if ($a === "heavy") {
	// Keep 2 MB for the life of the worker, so it is over a 1 MB ceiling
	// whatever the server itself weighs (a fresh worker can be under 1 MB).
	if (!class_exists("Held", false)) { class Held { static $d; } }
	Held::$d = str_repeat("h", 2097152);
}
// A body whose size depends on the token, bigger than one 64 KB read, with
// the token at both ends and in the middle.
$n = 4000 + hexdec(substr(md5($t), 0, 3));
echo "BEGIN ", $t, "\n", str_repeat($t . ".", $n), "\nPID ", getmypid(), "\nEND ", $t;
');

const BUG_INHERITED_CLIENTS = 'a worker forked as a replacement inherits client connections, so the parent\'s close does not reach the client. (1) Pool::onWorkerData calls Q_WebServer::closeClient((int) $client) after the response, but that only closes sockets still in Q_WebServer::$clients, and the pool\'s clients were removed from it at dispatch (WebServer.php, the self::$pool->dispatch() branch, ~l.3153; the closeClient call is Pool.php ~l.1140) -- so the socket is closed only when its last PHP reference is dropped; (2) recycle() -> forkWorker() runs inside onWorkerData while the local $client, $this->workerClients and $this->pending still reference open client sockets, and the child branch of forkWorker (src/Q/WebServer/Pool.php ~l.340-362 at time of writing) closes only the other workers\' sockets and Q_WebServer::$clients. The new worker therefore holds a duplicate of the connection of the request that caused the replacement and of every other in-flight or queued one: a "Connection: close" response never reaches EOF while that worker lives (an idle worker lives indefinitely), and the worker can read or write another visitor\'s socket. Minimal repro: --workers=1; GET a script calling Q_WebServer_Pool::retireAfterResponse(); the complete response arrives and the connection stays open.';

/** What a request with token $t must answer, apart from the PID line. */
function expectedBody($t, $body)
{
	$n = 4000 + hexdec(substr(md5($t), 0, 3));
	$want = "BEGIN $t\n" . str_repeat("$t.", $n) . "\nPID ";
	if (strncmp($body, $want, strlen($want)) !== 0) return false;
	$rest = substr($body, strlen($want));
	return (bool) preg_match('/^\d+\nEND ' . preg_quote($t, '/') . '$/', $rest);
}

function runLoad($name, $port, $action, $count, $concurrency)
{
	$reqs = array();
	$tokens = array();
	for ($i = 0; $i < $count; ++$i) {
		$t = 'r' . $i . 'x' . bin2hex(random_bytes(4));
		$tokens[$i] = $t;
		$reqs[$i] = array('path' => "/index.php?a=$action&t=$t");
	}
	$serverPid = rh_server_pid($name);
	$pidFile = $GLOBALS['rh']['base'] . DS . "$name.pid";
	$polls = 0; $pidBad = array();
	$last = 0.0;
	$tick = function () use ($pidFile, $serverPid, &$polls, &$pidBad, &$last) {
		$now = microtime(true);
		if ($now - $last < 0.005) return;
		$last = $now;
		++$polls;
		clearstatcache(true, $pidFile);
		$c = @file_get_contents($pidFile);
		if ($c === false) $pidBad[] = 'missing';
		elseif ((int) $c !== $serverPid) $pidBad[] = 'names ' . trim($c);
	};
	$started = microtime(true);
	$res = rh_many($port, $reqs, $concurrency, 40.0, $tick, 2.0);
	$secs = microtime(true) - $started;

	$codes = array(); $trunc = 0; $wrong = 0; $err = array(); $pids = array(); $noEof = 0;
	foreach ($res as $i => $r) {
		$codes[$r['status']] = ($codes[$r['status']] ?? 0) + 1;
		if ($r['error'] === 'no EOF after complete response') {
			++$noEof;
		} elseif ($r['error'] !== '') {
			$err[] = $r['error'];
			if (getenv('RACE_DEBUG')) rh_note("$name: {$tokens[$i]} -> {$r['error']} after " . round($r['ms']) . ' ms, ' . strlen($r['raw']) . ' bytes: ' . rh_short(substr($r['raw'], 0, 200)));
		}
		if ($r['status'] === 200 and !$r['complete']) ++$trunc;
		if ($r['status'] === 200 and $r['complete'] and !expectedBody($tokens[$i], $r['body'])) ++$wrong;
		if (preg_match('/\nPID (\d+)\n/', $r['body'], $m)) $pids[(int) $m[1]] = true;
	}
	ksort($codes);
	check("$name: every one of $count concurrent requests got 200", $codes, array(200 => $count));
	check("$name: no transport errors or timeouts", array_count_values($err), array());
	rh_bug("$name: every connection is closed after its response ($noEof of $count left open)",
		$noEof === 0, BUG_INHERITED_CLIENTS);
	check("$name: no truncated body (bytes received == Content-Length)", $trunc, 0);
	check("$name: every body is its own request's (token + length)", $wrong, 0);
	check("$name: the pid file was polled during the load", $polls > 20, true);
	check("$name: the pid file existed and named the parent at every poll",
		array_count_values($pidBad), array());
	return array($pids, $secs);
}

function assertPoolHealthy($name, $workers)
{
	$serverPid = rh_server_pid($name);
	usleep(300000);
	$live = rh_wait_children($serverPid, $workers, 5.0);
	check("$name: the pool is back to exactly $workers live workers", $live, $workers);
	$zombies = 0;
	$until = microtime(true) + 3.0;
	do {
		$zombies = count(array_filter(rh_workers($serverPid), function ($s) { return $s === 'Z'; }));
		if ($zombies === 0) break;
		usleep(100000);
	} while (microtime(true) < $until);
	check("$name: no replaced worker is left unreaped (zombie)", $zombies, 0);
	clearstatcache();
	check("$name: the pid file survives the load and names the parent",
		(int) @file_get_contents($GLOBALS['rh']['base'] . DS . "$name.pid"), $serverPid);
}

// ── 1. ceiling=1: every worker replaced after every request ─────

$port = rh_start('ceiling', array('Q' => array('webserver' => array('workerMemoryCeiling' => 1))), 3);
list($pids, $secs) = runLoad('ceiling', $port, 'heavy', 200, 20);
check('ceiling: requests were served by many distinct workers (replacement really happened)',
	count($pids) > 150, true);
$log = rh_log('ceiling');
$n = preg_match_all('/worker \d+ replaced after (\d+) requests: heap/', $log, $m);
check('ceiling: a replacement was logged for (nearly) every request', $n >= 195, true);
check('ceiling: ...each after exactly one request', array_unique($m[1]), array('1'));
assertPoolHealthy('ceiling', 3);
rh_note(sprintf('ceiling: 200 requests x 20 clients in %.1fs, %d distinct workers', $secs, count($pids)));

// ── 1b. The same race, deterministic: a slow request in flight while
//        another worker is replaced ──────────────────────────────────
//
// Worker A serves a request that takes 1.5 s. Meanwhile a request on worker
// B retires it, so the parent forks B' while A's client connection is held in
// the pool. When A answers, the parent closes that connection -- which must
// reach the client as EOF.

$sport = rh_start('inherit', array(), 2);
file_put_contents($root . DS . 'slow.php', '<?php usleep(1500000); echo "SLOW-DONE";');
file_put_contents($root . DS . 'bye.php', '<?php Q_WebServer_Pool::retireAfterResponse("inherit test"); echo "BYE";');
$inheritPid = rh_server_pid('inherit');
$before = array_keys(rh_workers($inheritPid));
$heldByNew = -1;
$res = rh_many($sport, array(
	array('path' => '/slow.php?x=1'),
	array('path' => '/bye.php?x=2', 'onStart' => function () { usleep(300000); }),
), 2, 15.0, function () use ($inheritPid, $before, $sport, &$heldByNew) {
	// Once the replacement exists and the slow request is still in flight,
	// count the client connections the new worker holds.
	if ($heldByNew >= 0) return;
	$new = array_diff(array_keys(rh_workers($inheritPid)), $before);
	foreach ($new as $pid) { usleep(100000); $heldByNew = rh_tcp_held($pid, $sport); }
}, 2.0);
check('inherit: the slow request is answered', $res[0]['status'] . ' ' . $res[0]['body'], '200 SLOW-DONE');
check('inherit: the retiring request is answered', $res[1]['status'] . ' ' . $res[1]['body'], '200 BYE');
rh_bug('inherit: the replacement worker holds no client connection of another request (held ' . $heldByNew . ')',
	$heldByNew === 0, BUG_INHERITED_CLIENTS);
rh_bug('inherit: the slow request\'s connection is closed after its response (' . ($res[0]['error'] ?: 'EOF seen') . ')',
	$res[0]['error'] === '', BUG_INHERITED_CLIENTS);

// ── 1c. Minimal: one request that retires its worker ────────────

$mport = rh_start('minimal', array(), 1);
$r = rh_get($mport, '/bye.php?x=minimal', 10.0);
check('minimal: a retiring request is answered in full', $r['status'] . ' ' . $r['body'], '200 BYE');
rh_bug('minimal: ...and its connection is closed (' . ($r['error'] ?: 'EOF seen') . ')',
	$r['error'] === '', BUG_INHERITED_CLIENTS);

// ── 2. retireAfterResponse() on every request ───────────────────

$port = rh_start('retire', array(), 3);
list($pids, $secs) = runLoad('retire', $port, 'retire', 200, 20);
check('retire: requests were served by many distinct workers', count($pids) > 150, true);
$n = preg_match_all('/replaced after 1 requests: asked by the application: race test r\d+x/',
	rh_log('retire'), $m);
check('retire: every retirement was logged with the application\'s reason', $n >= 195, true);
assertPoolHealthy('retire', 3);

// ── 3. Mixed: half the requests retire, half keep their worker ──

list($pids, $secs) = runLoad('retire', $port, 'mix', 300, 25);
assertPoolHealthy('retire', 3);

// And the pool still serves ordinary requests afterwards.
$r = rh_get($port, '/index.php?a=plain&t=afterall');
check('retire: an ordinary request after the load is answered in full',
	$r['status'] === 200 and expectedBody('afterall', $r['body']), true);

rh_finish();
