#!/usr/bin/env php
<?php
/**
 * Every event loop backend loads, satisfies the driver contract and runs.
 *
 * Q_Evented_IoPoll could not even be loaded: its tick() was protected where
 * Q_Evented_Driver declares it public, which PHP refuses at class load, so a
 * PHP with the native polling API would have died choosing its event loop.
 * Asserted, for StreamSelect, IoPoll and Revolt:
 *
 *   - the class loads and implements every driver method, public, with a
 *     compatible signature;
 *   - a one-shot timer fires once; a repeating timer fires on schedule;
 *   - a deferred callback runs before I/O;
 *   - a stream write/read round trip over a socket pair;
 *   - a timer, a deferred callback and a stream callback that throw leave the
 *     loop running; a timer cancelled from its own callback stays cancelled;
 *     a disabled timer does not fire; stop() from a callback ends run();
 *   - tick(0) returns without waiting;
 *   - backend choice: auto, QBIX_EVENT_LOOP forcing, fallback when forced
 *     to one that is not available.
 *
 * The native Io\Poll API is PHP 8.6+. Where it is missing, IoPoll's own logic
 * is still run, over a small stream_select() stand-in for Io\Poll defined
 * below; Revolt is skipped, with the reason, unless revolt/event-loop is
 * installed.
 *
 *   php tests/unit-evented-backends.php
 */
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0; $skip = 0;
function check($what, $ok) { global $pass, $fail; if ($ok) { ++$pass; return; } ++$fail; echo "  FAIL  $what\n"; }
function skip($what) { global $skip; ++$skip; echo "  skip  $what\n"; }
// A PHP warning or notice anywhere in a loop is a defect, even when the
// guard keeps the loop alive (a cancelled timer resurrected as a half-entry
// showed up only as a warning).
set_error_handler(function ($no, $msg, $file, $line) {
	check("no PHP warning: $msg at " . basename($file) . ":$line", false);
	return true;
});

// ── Contract: every backend class loads, with the driver's public methods ──
$contract = array();
foreach ((new ReflectionClass('Q_Evented_Driver'))->getMethods() as $m) {
	if ($m->isAbstract()) $contract[$m->getName()] = $m;
}
foreach (array('Q_Evented_StreamSelect', 'Q_Evented_IoPoll', 'Q_Evented_Revolt') as $cls) {
	$loaded = false;
	try { $loaded = class_exists($cls); } catch (Throwable $e) { echo "  $cls: " . $e->getMessage() . "\n"; }
	check("$cls loads", $loaded);
	if (!$loaded) continue;
	$rc = new ReflectionClass($cls);
	check("$cls is concrete", !$rc->isAbstract() && $rc->isSubclassOf('Q_Evented_Driver'));
	foreach ($contract as $name => $base) {
		$m = $rc->getMethod($name);
		check("$cls::$name() is public", $m->isPublic());
		check("$cls::$name() takes the driver's parameters",
			$m->getNumberOfRequiredParameters() <= $base->getNumberOfParameters()
			&& $m->getNumberOfParameters() >= $base->getNumberOfParameters());
	}
}

// ── Backend choice, before any stand-in exists ────────────────────────────
$prop = new ReflectionProperty('Q_Evented', 'driver');
$choose = function ($env) use ($prop) {
	putenv($env === null ? 'QBIX_EVENT_LOOP' : "QBIX_EVENT_LOOP=$env");
	$prop->setValue(null, null);
	$b = Q_Evented::backend();
	$prop->setValue(null, null);
	return $b;
};
$native = Q_Evented_IoPoll::available();
$revolt = class_exists('Revolt\\EventLoop');
$auto = $native ? 'iopoll' : ($revolt ? 'revolt' : 'select');
check("auto chooses $auto on this PHP", $choose(null) === $auto);
check('QBIX_EVENT_LOOP=select forces stream_select', $choose('select') === 'select');
check('...and "stream_select" is the same', $choose('stream_select') === 'select');
if (!$native) {
	check('forcing iopoll where it is missing falls back to auto', $choose('iopoll') === $auto);
} else {
	check('QBIX_EVENT_LOOP=iopoll forces Io\Poll', $choose('iopoll') === 'iopoll');
}
check('an unknown name means auto', $choose('nonsense') === $auto);
putenv('QBIX_EVENT_LOOP');

// ── A stand-in for Io\Poll where the native API is missing ────────────────
if (!$native) {
	eval(<<<'PHP'
namespace Io\Poll {
	enum Event { case Read; case Write; }
	final class Watcher {
		function __construct(public $handle, public array $events, private $data) {}
		function getData() { return $this->data; }
	}
	final class Context {
		private $watchers = array();
		function add($handle, array $events, $data = null) {
			$w = new Watcher($handle, $events, $data);
			$this->watchers[spl_object_id($handle)] = $w;
			return $w;
		}
		function remove($handle) { unset($this->watchers[spl_object_id($handle)]); }
		function wait(float $timeoutSeconds = -1) {
			$r = $w = $by = array();
			foreach ($this->watchers as $k => $x) {
				$s = $x->handle->stream;
				if (!is_resource($s)) throw new \Error('closed stream in poll set');
				if (in_array(Event::Read, $x->events, true)) { $r[] = $s; }
				if (in_array(Event::Write, $x->events, true)) { $w[] = $s; }
				$by[] = $x;
			}
			$e = null;
			if (!$r && !$w) { usleep((int)($timeoutSeconds * 1e6)); return array(); }
			$n = stream_select($r, $w, $e, (int)$timeoutSeconds, (int)(fmod($timeoutSeconds, 1) * 1e6));
			if (!$n) return array();
			$out = array();
			foreach ($by as $x) {
				$s = $x->handle->stream;
				if ((in_array(Event::Read, $x->events, true) && in_array($s, $r, true))
					|| (in_array(Event::Write, $x->events, true) && in_array($s, $w, true))) $out[] = $x;
			}
			return $out;
		}
	}
}
namespace {
	final class StreamPollHandle { function __construct(public $stream) {} }
}
PHP
	);
	echo "  note  Io\\Poll is not in this PHP; IoPoll's logic runs over a stream_select() stand-in\n";
}

// ── Runtime behaviour, per backend ────────────────────────────────────────
$backends = array('Q_Evented_StreamSelect' => true, 'Q_Evented_IoPoll' => true, 'Q_Evented_Revolt' => $revolt);
foreach ($backends as $cls => $ok) {
	if (!$ok) { skip("$cls runtime: revolt/event-loop is not installed"); continue; }
	$L = new $cls();

	// One-shot and repeating timers, deferred before them.
	$order = array(); $reps = 0; $once = 0;
	$t0 = microtime(true);
	$L->delay(0.03, function () use (&$once, &$order) { $once++; $order[] = 'delay'; });
	$rep = null;
	$rep = $L->repeat(0.01, function () use (&$reps, &$rep, $L) { if (++$reps >= 4) $L->cancel($rep); });
	$L->defer(function () use (&$order) { $order[] = 'defer'; });
	$L->delay(0.08, function () use ($L) { $L->stop(); });
	$L->delay(2.0, function () use ($L) { $L->stop(); });
	$L->run();
	$took = microtime(true) - $t0;
	check("$cls: one-shot timer fired once ($once)", $once === 1);
	check("$cls: repeating timer fired 4 times, then stayed cancelled ($reps)", $reps === 4);
	check("$cls: deferred callback ran first", ($order[0] ?? null) === 'defer');
	check("$cls: stop() from a timer ended run() on time (" . round($took, 3) . "s)", $took < 1.0);

	// Throwing callbacks leave the loop running; disabled timers stay quiet.
	$L = new $cls();
	$n = 0; $quiet = 0; $afterDefer = 0;
	$L->defer(function () { throw new RuntimeException('deferred failed'); });
	$L->defer(function () use (&$afterDefer) { $afterDefer++; });
	$L->repeat(0.01, function () { throw new LogicException('heartbeat failed'); });
	$L->repeat(0.01, function () use (&$n, $L) { if (++$n >= 5) $L->stop(); });
	$d = $L->delay(0.02, function () use (&$quiet) { $quiet++; });
	$L->disable($d);
	$L->delay(3.0, function () use ($L) { $L->stop(); });
	$err = null;
	try { $L->run(); } catch (Throwable $e) { $err = $e->getMessage(); }
	check("$cls: loop survives throwing timers and deferreds (counted $n)" . ($err ? ": $err" : ''), $n >= 5 && $afterDefer === 1);
	check("$cls: a disabled timer does not fire", $quiet === 0);

	// Stream round trip over a socket pair, and a throwing stream callback.
	$L = new $cls();
	list($a, $b) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	stream_set_blocking($a, false); stream_set_blocking($b, false);
	$got = ''; $wrote = false; $threw = 0;
	$w = null; $r = null;
	$w = $L->onWritable($a, function ($s) use (&$wrote, &$w, $L) { fwrite($s, "ping\n"); $wrote = true; $L->cancel($w); });
	$r = $L->onReadable($b, function ($s) use (&$got, &$r, $L) {
		$got .= (string)fread($s, 100);
		if (strpos($got, "\n") !== false) { $L->cancel($r); $L->stop(); }
	});
	list($c, $d2) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	fwrite($d2, 'x');
	$L->onReadable($c, function ($s) use (&$threw) { $threw++; throw new RuntimeException('bad connection'); });
	$L->delay(2.0, function () use ($L) { $L->stop(); });
	$err = null;
	try { $L->run(); } catch (Throwable $e) { $err = $e->getMessage(); }
	check("$cls: write then read over a socket pair" . ($err ? " ($err)" : ''), $wrote && $got === "ping\n");
	check("$cls: a throwing stream callback does not end the loop (" . $threw . "x)", $err === null && $threw >= 1);

	// tick(0) does not wait.
	$L = new $cls();
	list($e1, $e2) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	$L->onReadable($e1, function () {});
	$L->delay(5, function () {});
	$t0 = microtime(true);
	$L->tick(0);
	check("$cls: tick(0) returns without waiting", microtime(true) - $t0 < 0.05);
	fclose($a); fclose($b); fclose($c); fclose($d2); fclose($e1); fclose($e2);
}

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
