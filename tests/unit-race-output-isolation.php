<?php

/**
 * Under concurrent load, with scripts that end in every awkward way a PHP
 * script can, each response carries exactly its own output, status, headers
 * and cookies -- and nothing any other request produced.
 *
 * A persistent worker keeps one capture buffer for its life
 * (Q_WebServer_Capture) and serves request after request from it; scripts
 * leave buffers open, open nested ones with callbacks, end every buffer they
 * can find, exit and die (turned into Q_WebServer_ExitSignal by the
 * transform), throw, install error handlers they never restore, set headers,
 * status codes, cookies, globals and statics. The race: 4 workers, 300
 * requests, 30 clients at once, those behaviours mixed randomly, so every
 * worker serves them in a different order and every kind follows every other
 * kind on the same process. What would fail:
 *
 *   - output left in the capture buffer by one request prefixed to the next;
 *   - an unrestored error handler of an earlier request (it echoes that
 *     request's token) firing in a later one;
 *   - a header, status or cookie set by one request appearing on another's
 *     response (Q_WebServer_State / Q_Response not cleared);
 *   - a global or a static property of a class the script declared keeping
 *     an earlier request's value;
 *   - an exit/die/throw ending the worker (a 502) instead of the request;
 *   - a throw answering with its message or its partial output, instead of
 *     the designed error page.
 *
 * Every request carries a unique token; every response is scanned for every
 * token-shaped string, and all of them must be its own. The bodies of the
 * deterministic kinds are compared exactly.
 *
 *   php tests/unit-race-output-isolation.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('race-output');

const BUG_FIRST_COOKIE = 'Set-Cookie is dropped from the first response a worker serves. Pool::executeScript (src/Q/WebServer/Pool.php, the "Cookies live in Q_Response" block, ~l.840-850 at time of writing) collects cookies only `if (class_exists(\'Q_WebServer_State\', false) ...)`; setcookie() goes to Q_Response::setCookie() and never loads Q_WebServer_State, which a fresh worker has not loaded yet, so the cookie stays in Q_Response::$cookies and is not sent (and is cleared before the next request). Every worker forked after a replacement (memory ceiling, retireAfterResponse, maxRequests, crash) loses the cookies of its first request -- a login or session start answered by such a worker never reaches the browser. Repro: fresh server --workers=1; first request calls setcookie("a","b"); response has no Set-Cookie; second identical request has it.';

file_put_contents($root . DS . 'index.php', '<?php
$t = preg_replace("/[^a-z0-9]/", "", $_GET["t"]);
$k = $_GET["k"];
$A = "A:$t|"; $Z = "Z:$t";
if (!empty($_GET["log"])) file_put_contents(__DIR__ . "/../order.log", getmypid() . " $k $t\n", FILE_APPEND | LOCK_EX);
switch ($k) {
case "plain":    echo $A, $Z; break;
case "open":     echo $A; ob_start(); echo $Z; break;
case "nested":   ob_start(); echo $A; ob_start(); ob_start(); echo $Z; break;
case "callback": ob_start(function ($b) { return "[" . $b . "]"; }); echo $A, $Z; break;
case "endall":   echo "LOST:$t"; while (@ob_end_clean()); echo $A, $Z; break;
case "exit":     echo $A, $Z; exit; echo "NOT:$t"; break;
case "exitob":   ob_start(); echo $A, $Z; exit(3); break;
case "die":      die($A . $Z); break;
case "throw":    echo "JUNK:$t"; throw new RuntimeException($A . $Z);
case "handler":
	set_error_handler(function () use ($t) { echo "H:$t|"; return true; });
	set_exception_handler(function () use ($t) { echo "EH:$t"; });
	@trigger_error("x", E_USER_NOTICE);
	echo $A, $Z; break;
case "warn":     @trigger_error("w", E_USER_NOTICE); echo $A, $Z; break;
case "header":   header("X-Token: $t"); http_response_code(203); echo $A, $Z; break;
case "cookie":   setcookie("tok", $t); echo $A, $Z; break;
case "global":
	$prev = isset($GLOBALS["raceLeak"]) ? $GLOBALS["raceLeak"] : "none";
	$GLOBALS["raceLeak"] = "G:$t";
	echo $A, "P:", $prev, "|", $Z; break;
case "static":
	if (!class_exists("RaceBox", false)) { eval("class RaceBox { public static \$v = null; }"); }
	$prev = RaceBox::$v === null ? "none" : RaceBox::$v;
	RaceBox::$v = "S:$t";
	echo $A, "P:", $prev, "|", $Z; break;
}
');

$kinds = array(
	// kind => array(expected status, expected exact body or null to skip)
	'plain' => array(200, '%A%Z'), 'open' => array(200, '%A%Z'), 'nested' => array(200, '%A%Z'),
	'callback' => array(200, null), 'endall' => array(200, '%A%Z'), 'exit' => array(200, '%A%Z'),
	'exitob' => array(200, '%A%Z'), 'die' => array(200, '%A%Z'), 'throw' => array(500, null),
	'handler' => array(200, 'H:%t|%A%Z'), 'warn' => array(200, '%A%Z'),
	'header' => array(203, '%A%Z'), 'cookie' => array(200, '%A%Z'),
	'global' => array(200, '%AP:none|%Z'), 'static' => array(200, '%AP:none|%Z'),
);

$port = rh_start('iso', array(), 4);

$reqs = array(); $meta = array();
$names = array_keys($kinds);
for ($i = 0; $i < 300; ++$i) {
	// Every kind at least 12 times, in random order.
	$k = $i < count($names) * 12 ? $names[$i % count($names)] : $names[random_int(0, count($names) - 1)];
	$t = sprintf('t%04dx%s', $i, bin2hex(random_bytes(3)));
	$meta[] = array($k, $t);
}
shuffle($meta);
$dbg = getenv('RACE_DEBUG') ? '&log=1' : '';
foreach ($meta as $m) $reqs[] = array('path' => "/index.php?k={$m[0]}&t={$m[1]}$dbg");

$res = rh_many($port, $reqs, 30, 40.0, null, 2.0);

$foreign = array(); $wrongBody = array(); $wrongStatus = array(); $transport = array();
$hdr = array(); $cookie = array(); $cb = array(); $cookieMissing = 0;
foreach ($res as $i => $r) {
	list($k, $t) = $meta[$i];
	if (rh_transport_error($r)) { $transport[] = "$k: {$r['error']}"; continue; }
	$everything = $r['body'] . "\n" . json_encode($r['headers']);
	preg_match_all('/t\d{4}x[0-9a-f]{6}/', $everything, $m);
	$others = array_values(array_unique(array_diff($m[0], array($t))));
	if ($others) $foreign[] = "$k got " . implode(',', $others);
	list($wantStatus, $wantBody) = $kinds[$k];
	if ($r['status'] !== $wantStatus) $wrongStatus[] = "$k: {$r['status']}";
	if ($wantBody !== null) {
		$want = strtr($wantBody, array('%A' => "A:$t|", '%Z' => "Z:$t", '%t' => $t));
		// Output before an end-every-buffer loop is discarded under
		// output_buffering=4096 (php.ini-production) and already sent under
		// output_buffering=0; both are PHP. Either is accepted, as its own.
		$ok = ($r["body"] === $want || ($k === "endall" && $r["body"] === "LOST:$t" . $want));
		if (!$ok) $wrongBody[] = "$k: " . rh_short($r['body'], 80);
	}
	if ($k === 'header' and ($r['headers']['x-token'] ?? '') !== $t) $hdr[] = $r['headers']['x-token'] ?? 'missing';
	if ($k !== 'header' and isset($r['headers']['x-token'])) $hdr[] = "$k carries X-Token";
	if ($k === 'cookie' and !isset($r['headers']['set-cookie'])) {
		// A cookie missing altogether is the first-request defect proved
		// deterministically below; counted here, not failed twice.
		++$cookieMissing;
	} elseif ($k === 'cookie' and strpos($r['headers']['set-cookie'], "tok=$t") !== 0) {
		$cookie[] = $r['headers']['set-cookie'];
		if ($dbg) {
			// Which request did the same worker serve just before this one?
			$lines = file($base . DS . 'order.log', FILE_IGNORE_NEW_LINES);
			foreach ($lines as $n => $l) if (strpos($l, " $t") !== false) {
				list($pid) = explode(' ', $l);
				$prev = 'first';
				for ($q = $n - 1; $q >= 0; --$q) if (strpos($lines[$q], "$pid ") === 0) { $prev = $lines[$q]; break; }
				rh_note("cookie lost on $t (pid $pid); previous on that worker: $prev");
			}
		}
	}
	if ($k !== 'cookie' and isset($r['headers']['set-cookie'])) $cookie[] = "$k carries Set-Cookie";
	if ($k === 'callback' and strpos($r['body'], "A:$t|Z:$t") === false) $cb[] = rh_short($r['body'], 80);
	// A throw answers with the server's designed error page: nothing the
	// script printed before throwing (JUNK:), and nothing of the exception's
	// message (its own token) -- that is for the log, not the visitor.
	if ($k === 'throw' and (strpos($r['body'], 'JUNK:') !== false or strpos($r['body'], $t) !== false
		or strpos($r['body'], '<html') === false)) $wrongBody[] = "throw: " . rh_short($r['body'], 80);
}
check('no request lost its worker or its connection (exit/die/throw end the request only)', $transport, array());
check('no response contains another request\'s token (body or headers)', $foreign, array());
check('every response has its kind\'s status (200, 203 for header(), 500 for a throw)', $wrongStatus, array());
check('every deterministic body is exactly its own (buffers left open, exit, die, handlers, globals, statics)', $wrongBody, array());
check('X-Token appears on header() responses only, with their own token', $hdr, array());
check('Set-Cookie appears on setcookie() responses only, never with another request\'s token', $cookie, array());
if ($cookieMissing) rh_note("$cookieMissing setcookie() response(s) had no Set-Cookie at all (first request of a worker; see the FAILS below)");
check('a buffer left open with a callback still delivers its own output', $cb, array());
rh_note_no_eof('iso', $res);

// Workers survived all of it: the same small set of pids keeps serving.
$pids = array();
foreach (rh_many($port, array_fill(0, 40, array('path' => '/index.php?k=plain&t=t9999xffffff')), 8, 10.0) as $r) {
	$pids[$r['status']] = ($pids[$r['status']] ?? 0) + 1;
}
check('the pool still answers 40 requests afterwards', $pids, array(200 => 40));
$replaced = preg_match_all('/replaced after/', rh_log('iso'));
rh_note("workers replaced during the run: $replaced (health check: open buffers / heap)");

// ── A cookie set by a worker's very first request ───────────────
//
// Found by the mix above: a lost Set-Cookie, always on the first request a
// worker served. Reproduced deterministically: a fresh one-worker server whose
// first request sets a cookie, and a server replacing its worker after every
// request (so every request is some worker's first).

$fresh = rh_start('fresh', array(), 1);
$r1 = rh_get($fresh, '/index.php?k=cookie&t=t8001xaaaaaa');
$r2 = rh_get($fresh, '/index.php?k=cookie&t=t8002xaaaaaa');
check('fresh worker: its second request\'s cookie arrives', strpos($r2['headers']['set-cookie'] ?? '', 'tok=t8002xaaaaaa') === 0, true);
rh_bug('fresh worker: its first request\'s cookie arrives (got ' . var_export($r1['headers']['set-cookie'] ?? null, true) . ')',
	strpos($r1['headers']['set-cookie'] ?? '', 'tok=t8001xaaaaaa') === 0, BUG_FIRST_COOKIE);

$ceil = rh_start('ceil', array('Q' => array('webserver' => array('workerMemoryCeiling' => 1))), 2);
$got = 0;
for ($i = 0; $i < 6; ++$i) {
	$r = rh_get($ceil, "/index.php?k=cookie&t=t810{$i}xbbbbbb");
	if (strpos($r['headers']['set-cookie'] ?? '', "tok=t810{$i}xbbbbbb") === 0) ++$got;
	usleep(150000);
}
rh_bug("ceiling=1 (every request on a fresh worker): cookies arrive ($got of 6)", $got === 6, BUG_FIRST_COOKIE);

rh_finish();
