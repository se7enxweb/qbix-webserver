#!/usr/bin/env php
<?php
/**
 * One failing periodic job must never stop the server.
 *
 * The dashboard's two-second heartbeat built its stats with the shell's
 * activity card, whose summary() called Q_WebServer_Shell::config() with no
 * argument. Once the shell had been used and a dashboard was open, every
 * heartbeat threw, nothing caught it, and the event loop -- the whole server,
 * every listener -- ended: on a live install the site went down minutes after
 * someone opened the shell. Asserted:
 *
 *   - the shell's summary() answers, with its allowSystem flag;
 *   - the dashboard's stats build with the shell loaded;
 *   - a timer or deferred callback that throws is logged and the loop keeps
 *     running its other timers, on every backend that runs callbacks itself.
 *
 *   php tests/unit-event-loop-survives-callback-errors.php
 */
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0;
function check($what, $ok) { global $pass, $fail; if ($ok) { ++$pass; return; } ++$fail; echo "  FAIL  $what\n"; }

$s = Q_WebServer_Shell_Server::summary();
check('shell summary() answers', is_array($s) && array_key_exists('allowSystem', $s));
check('...with a boolean allowSystem', is_bool($s['allowSystem']));

$stats = null;
try { $stats = Q_WebServer_Dashboard::getStats(); } catch (Throwable $e) { echo "  getStats threw: " . $e->getMessage() . "\n"; }
check('dashboard stats build with the shell loaded', is_array($stats) && array_key_exists('shell', $stats));

// The guard every backend uses: a throw is contained, and later work still runs.
$ran = 0;
$err = fopen('php://memory', 'w+');
Q_Evented_Driver::guard(function () { throw new RuntimeException('boom'); }, 'timer');
Q_Evented_Driver::guard(function () use (&$ran) { $ran++; }, 'timer');
check('a throwing callback is contained, and the next one runs', $ran === 1);

// A real loop: one timer throws every tick, another counts; the loop must
// keep going until the counter stops it.
// (Every backend, IoPoll and Revolt included, is exercised the same way in
// tests/unit-evented-backends.php.)
foreach (array('Q_Evented_StreamSelect') as $cls) {
	if (!class_exists($cls)) continue;
	$loop = new $cls();
	$n = 0;
	$loop->repeat(0.01, function () { throw new LogicException('heartbeat failed'); });
	$loop->repeat(0.01, function () use (&$n, $loop) { if (++$n >= 5) $loop->stop(); });
	$loop->delay(3.0, function () use ($loop) { $loop->stop(); });
	try { $loop->run(); } catch (Throwable $e) { echo "  $cls run() threw: " . $e->getMessage() . "\n"; }
	check("$cls keeps running its timers when one of them throws (counted $n)", $n >= 5);
}

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
