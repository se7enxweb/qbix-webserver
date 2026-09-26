<?php

/**
 * An uncaught error in a request says where it happened, to the right reader.
 *
 * A 500 used to carry the exception's message and nothing else, and the log
 * only the first frames of the outermost exception. A framework's outer
 * exception is often only "something went wrong while doing X"; the cause
 * sits in the previous-exception chain, which nothing reported.
 *
 * Checked here:
 *  - Q_WebServer_ErrorReport: the body is the message alone unless debug is
 *    on, and then carries the class, file:line, trace and every previous
 *    exception; the log text always names the file:line of each exception in
 *    the chain; the chain is bounded.
 *  - A real server started from this tree, serving a script that throws a
 *    chained exception and one that calls null: without --debug the 500
 *    body holds the message and no server path, and the log holds the
 *    file:line and "caused by"; with --debug the body holds the paths, the
 *    trace and "Caused by". A good page still answers 200 on both.
 *
 *   php tests/unit-error-diagnostics.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/ErrorReport.php';

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

// ── The helper ───────────────────────────────────────────────────────────
function chained()
{
	try {
		throw new LogicException('the cause');
	} catch (Throwable $e) {
		return new RuntimeException("what surfaced\nline two", 0, $e);
	}
}
$e = chained();
$plain = Q_WebServer_ErrorReport::body($e, false);
check('plain body is the message', $plain, "what surfaced\nline two");
check('plain body has no path', strpos($plain, __FILE__), false);
$dbg = Q_WebServer_ErrorReport::body($e, true);
check('debug body names the class and place', strpos($dbg, 'RuntimeException: what surfaced') === 0
	&& strpos($dbg, ' in ' . __FILE__ . ':') !== false, true);
check('debug body has the trace', strpos($dbg, '#0 ') !== false, true);
check('debug body has the cause', strpos($dbg, "Caused by LogicException: the cause in " . __FILE__) !== false, true);
$log = Q_WebServer_ErrorReport::logText($e, 'worker 1');
check('log says where', strpos($log, '  worker 1: uncaught RuntimeException at ' . __FILE__ . ':') === 0, true);
check('log message is one line', strpos($log, 'what surfaced line two') !== false, true);
check('log names the cause and its place', strpos($log, '    caused by LogicException at ' . __FILE__ . ':') !== false, true);
check('debug is off by default', Q_WebServer_ErrorReport::debug(), false);
Q_Config::set('Q', 'webserver', 'debug', true);
check('Q.webserver.debug turns it on', Q_WebServer_ErrorReport::debug(), true);
check('and the body follows it', strpos(Q_WebServer_ErrorReport::body($e), 'Caused by') !== false, true);
Q_Config::set('Q', 'webserver', 'debug', false);
$deep = new Exception('e0');
for ($i = 1; $i <= 30; $i++) $deep = new Exception("e$i", 0, $deep);
check('the chain is bounded', count(Q_WebServer_ErrorReport::chain($deep)), Q_WebServer_ErrorReport::MAX_CHAIN);

// ── A real server, without and with --debug ─────────────────────────────
$tmp = sys_get_temp_dir() . DS . 'qbix-errdiag-' . getmypid();
@mkdir($tmp . DS . 'web', 0700, true);
file_put_contents($tmp . '/web/ok.php', '<?php echo "fine";');
file_put_contents($tmp . '/web/boom.php', '<?php $x = null; $x();');
file_put_contents($tmp . '/web/chained.php', "<?php\ntry {\n\tthrow new LogicException('the cause');\n} catch (Throwable \$e) {\n\tthrow new RuntimeException('what surfaced', 0, \$e);\n}\n");
$procs = array();
register_shutdown_function(function () use ($tmp, &$procs) {
	foreach ($procs as $proc) {
		$st = @proc_get_status($proc);
		if ($st and !empty($st['running'])) {
			@proc_terminate($proc, 15);
			for ($i = 0; $i < 40; ++$i) {
				$now = @proc_get_status($proc);
				if (!$now or empty($now['running'])) break;
				usleep(250000);
			}
			$now = @proc_get_status($proc);
			if ($now and !empty($now['running'])) {
				@proc_terminate($proc, 9);
				if (function_exists('posix_kill')) @posix_kill((int) $st['pid'], 9);
			}
		}
		@proc_close($proc);
	}
	foreach (glob($tmp . '/web/*') ?: array() as $f) @unlink($f);
	foreach (glob($tmp . '/*.log') ?: array() as $f) @unlink($f);
	@rmdir($tmp . '/web');
	@rmdir($tmp);
});

function freePort()
{
	for ($i = 0; $i < 40; ++$i) {
		$p = 19100 + random_int(0, 700);
		$probe = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
		if ($probe) { fclose($probe); return $p; }
	}
	return 0;
}

function startServer($tmp, $debug, &$procs)
{
	$port = freePort();
	if (!$port) return array(0, '');
	$log = $tmp . '/' . ($debug ? 'debug' : 'plain') . '.log';
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../qbixserver.php')
		. ' --root=' . escapeshellarg($tmp . DS . 'web') . ' --host=127.0.0.1'
		. ' --port=' . $port . ' --workers=2' . ($debug ? ' --debug' : '');
	$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'),
		1 => array('file', $log, 'w'), 2 => array('file', $log, 'a')), $pipes);
	if (!is_resource($proc)) return array(0, $log);
	$procs[] = $proc;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1);
		if ($s) { fclose($s); return array($port, $log); }
		usleep(500000);
	}
	return array(0, $log);
}

function fetch($port, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $e, $m, 5);
	if (!$s) return array(0, '');
	stream_set_timeout($s, 10);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
	$raw = stream_get_contents($s);
	fclose($s);
	list($head, $body) = array_pad(explode("\r\n\r\n", (string) $raw, 2), 2, '');
	if (preg_match('/^HTTP\/\S+ (\d+)/', $head, $m) and stripos($head, "transfer-encoding: chunked") !== false) {
		$out = '';
		while ($body !== '' and preg_match('/^([0-9a-f]+)\r\n/i', $body, $c)) {
			$n = hexdec($c[1]);
			if ($n === 0) break;
			$out .= substr($body, strlen($c[0]), $n);
			$body = substr($body, strlen($c[0]) + $n + 2);
		}
		$body = $out;
	}
	return array(isset($m[1]) ? (int) $m[1] : 0, $body);
}

foreach (array(false, true) as $debug) {
	$label = $debug ? 'with --debug' : 'by default';
	list($port, $log) = startServer($tmp, $debug, $procs);
	if (!$port) {
		check("server $label starts", false, true);
		echo (string) @file_get_contents($log);
		continue;
	}
	list($st, $body) = fetch($port, '/ok.php');
	check("a good page is 200 $label", array($st, $body), array(200, 'fine'));
	list($st, $body) = fetch($port, '/chained.php');
	check("a throwing page is 500 $label", $st, 500);
	check("its body has the message $label", strpos($body, 'what surfaced') !== false, true);
	check("its body shows paths only $label", strpos($body, $tmp) !== false, $debug);
	check("its body shows the cause only $label", strpos($body, 'Caused by LogicException: the cause') !== false, $debug);
	list($st, $body) = fetch($port, '/boom.php');
	check("calling null is 500 $label", $st, 500);
	check("that body shows paths only $label", strpos($body, $tmp) !== false, $debug);
	usleep(300000);
	$text = (string) @file_get_contents($log);
	check("the log says where $label", strpos($text, 'uncaught RuntimeException at ' . $tmp . '/web/chained.php:5') !== false, true);
	check("the log names the cause $label", strpos($text, 'caused by LogicException at ' . $tmp . '/web/chained.php:3') !== false, true);
	check("the log says where calling null failed $label", strpos($text, 'uncaught Error at ' . $tmp . '/web/boom.php:1') !== false, true);
}

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
