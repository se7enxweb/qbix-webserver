<?php

/**
 * A worker that dies mid-request -- killed outright, or taken down by a fatal
 * error PHP cannot catch -- costs exactly that one request, while requests on
 * the other workers carry on and the pool recovers to full size.
 *
 * The race it guards: the parent learns of a death as an empty read on the
 * worker's socket (Pool::onWorkerData). In persistent mode it then asks
 * posix_kill($pid, 0) whether the process is still there -- and a process
 * that has died but not yet been reaped is still there to kill(0). Whether the
 * SIGCHLD handler (WebServer.php) has reaped it by then is a race. Lost, the
 * parent returns without recycling and the readable-at-EOF socket wakes it
 * again at once; if it never resolved, the dead request would hang and the
 * parent would spin. Won, recycle() answers 502 and forks a replacement, which
 * must pick up the pending queue.
 *
 * How it would fail:
 *
 *   - a killed request that hangs instead of getting a 5xx (timeout here);
 *   - an innocent request answered 502, or with another request's body,
 *     because the death was attributed to the wrong worker index;
 *   - fewer live workers afterwards than --workers (a replacement never
 *     forked), or zombies (never reaped);
 *   - the parent burning CPU while idle afterwards (an EOF watcher left on
 *     a dead socket), measured from /proc/<pid>/stat;
 *   - requests after the storm failing (queue or bookkeeping poisoned).
 *
 *   php tests/unit-race-worker-death.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-death');

file_put_contents($root . DS . 'index.php', '<?php
$t = isset($_GET["t"]) ? preg_replace("/[^a-z0-9]/", "", $_GET["t"]) : "none";
$a = isset($_GET["a"]) ? $_GET["a"] : "";
usleep(15000);   // keep requests overlapping
if ($a === "kill") { echo "partial ", $t; posix_kill(getmypid(), 9); usleep(1000000); echo "NOT REACHED"; return; }
if ($a === "fatal") { echo "partial ", $t; include __DIR__ . "/dup.php"; echo "NOT REACHED"; return; }
echo "OK ", $t, " ", getmypid();
');
// Declaring the same function twice is a compile error: fatal, uncatchable,
// and the process ends.
file_put_contents($root . DS . 'dup.php', '<?php function race_dup() {} function race_dup() {}');

$workers = 3;
$port = rh_start('death', array(), $workers);
$serverPid = rh_server_pid('death');

$reqs = array(); $kind = array();
for ($i = 0; $i < 150; ++$i) {
	$k = ($i % 10 === 5) ? 'kill' : (($i % 15 === 7) ? 'fatal' : 'ok');
	$t = 'd' . $i . 'x' . bin2hex(random_bytes(3));
	$kind[$i] = array($k, $t);
	$reqs[$i] = array('path' => "/index.php?a=$k&t=$t");
}
$res = rh_many($port, $reqs, 20, 40.0, null, 2.0);

$okGood = 0; $okBad = array(); $deadGood = 0; $deadBad = array(); $errs = array();
foreach ($res as $i => $r) {
	list($k, $t) = $kind[$i];
	if (rh_transport_error($r)) $errs[] = "$k: {$r['error']}";
	if ($k === 'ok') {
		if ($r['status'] === 200 and preg_match('/^OK ' . $t . ' \d+$/', $r['body'])) ++$okGood;
		else $okBad[] = $r['status'] . ' ' . rh_short($r['body'], 60);
	} else {
		if ($r['status'] >= 500 and $r['status'] <= 599 and strpos($r['body'], 'NOT REACHED') === false) ++$deadGood;
		else $deadBad[] = "$k -> " . $r['status'] . ' ' . rh_short($r['body'], 60) . ' ' . $r['error'];
	}
}
$nOk = count(array_filter($kind, function ($x) { return $x[0] === 'ok'; }));
$nDead = count($kind) - $nOk;
check('no request hung or failed at the transport level', $errs, array());
check("every innocent request ($nOk) got 200 with its own body", $okBad, array());
check("every killed / fatal request ($nDead) got a 5xx, not a hang", $deadBad, array());
rh_note_no_eof('death', $res);

// Pool back to size, nothing unreaped.
usleep(300000);
check("the pool recovers to $workers live workers", rh_wait_children($serverPid, $workers, 5.0), $workers);
$z = 0;
for ($i = 0; $i < 30; ++$i) {
	$z = count(array_filter(rh_workers($serverPid), function ($s) { return $s === 'Z'; }));
	if (!$z) break;
	usleep(100000);
}
check('no dead worker is left unreaped (zombie)', $z, 0);

// The parent is idle, not spinning on a dead worker's socket.
$cpu = function ($pid) {
	$s = (string) @file_get_contents("/proc/$pid/stat");
	$rest = explode(' ', substr($s, strrpos($s, ')') + 2));
	return ((int) $rest[11] + (int) $rest[12]) / 100.0;   // utime + stime, clock ticks
};
$c0 = $cpu($serverPid); usleep(2000000); $c1 = $cpu($serverPid);
check(sprintf('the parent is idle afterwards (%.2fs CPU in 2s)', $c1 - $c0), ($c1 - $c0) < 0.5, true);

// Sequential and concurrent requests afterwards all succeed.
$bad = 0;
for ($i = 0; $i < 20; ++$i) {
	$r = rh_get($port, "/index.php?t=after$i");
	if ($r['status'] !== 200 or !preg_match("/^OK after$i \\d+$/", $r['body'])) ++$bad;
}
check('20 sequential requests afterwards are all answered', $bad, 0);
$reqs = array();
for ($i = 0; $i < 40; ++$i) $reqs[] = array('path' => "/index.php?t=conc$i");
$res = rh_many($port, $reqs, 10, 20.0, null, 2.0);
$bad = 0; $pids = array();
foreach ($res as $i => $r) {
	if ($r['status'] !== 200 or !preg_match("/^OK conc$i (\\d+)$/", $r['body'], $m)) ++$bad;
	else $pids[$m[1]] = true;
}
check('40 concurrent requests afterwards are all answered', $bad, 0);
check('...by the whole pool (all workers take requests)', count($pids), $workers);

rh_finish();
