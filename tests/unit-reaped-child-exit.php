<?php
/**
 * A shell job's exit code survives the server's SIGCHLD handler.
 *
 * The server reaps every child it sees (waitpid(-1)) to clear finished
 * workers. A process the shell opened with proc_open, reaped there first,
 * leaves proc_get_status() and proc_close() with -1, and the job reported 1
 * whatever the command returned -- seen as `server status` exiting 1 while
 * printing "running: yes" under load. The handler now keeps the status of a
 * child it did not start (Q_WebServer::noteReaped), and the shell asks for it.
 *
 *   php tests/unit-reaped-child-exit.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer.php';
require_once __DIR__ . '/../src/Q/WebServer/Shell/Server.php';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; echo "  ok    $what\n"; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('pcntl_waitpid') || !function_exists('proc_open')) { echo "  skip  needs pcntl and proc_open\n"; exit(0); }

$exitCode = new ReflectionMethod('Q_WebServer_Shell_Server', 'exitCode');
$exitCode->setAccessible(true);

foreach (array(0, 3, 127) as $want) {
	$proc = proc_open(array(PHP_BINARY, '-n', '-r', "exit($want);"), array(), $pipes);
	$pid = proc_get_status($proc)['pid'];
	// What the SIGCHLD handler does: reap any child, before the owner looks.
	$st = 0;
	for ($i = 0; $i < 200; ++$i) { $r = pcntl_waitpid(-1, $st, WNOHANG); if ($r === $pid) break; usleep(20000); }
	check("the handler reaped the job first (exit $want)", $r, $pid);
	Q_WebServer::noteReaped($pid, $st);
	$s = proc_get_status($proc);
	$close = proc_close($proc);
	check("...so proc_get_status and proc_close no longer know it (exit $want)", $s['exitcode'] < 0 && $close < 0, true);
	check("...and the job still reports $want", $exitCode->invoke(null, $s, $close, $pid), $want);
}

// A signal is 128 + its number, as a shell reports it.
$proc = proc_open(array(PHP_BINARY, '-n', '-r', 'sleep(30);'), array(), $pipes);
$pid = proc_get_status($proc)['pid'];
posix_kill($pid, 15);
for ($i = 0; $i < 200; ++$i) { $r = pcntl_waitpid(-1, $st, WNOHANG); if ($r === $pid) break; usleep(20000); }
Q_WebServer::noteReaped($pid, $st);
$s = proc_get_status($proc);
$close = proc_close($proc);
check('a job ended by SIGTERM reports 143', $exitCode->invoke(null, $s, $close, $pid), 143);

// Asked once, then forgotten; unknown pids stay unknown.
check('a kept status is handed out once', Q_WebServer::reapedExit($pid), null);
check('a pid never reaped there is unknown', Q_WebServer::reapedExit(999999), null);

// Not reaped by the handler: the ordinary path is untouched.
$proc = proc_open(array(PHP_BINARY, '-n', '-r', 'exit(5);'), array(), $pipes);
$pid = proc_get_status($proc)['pid'];
do { $s = proc_get_status($proc); usleep(20000); } while ($s['running']);
$close = proc_close($proc);
check('a job the owner reaped itself reports its own code', $exitCode->invoke(null, $s, $close, $pid), 5);

// Bounded.
for ($i = 0; $i < 600; ++$i) Q_WebServer::noteReaped(400000 + $i, 0);
check('the kept statuses are bounded', count(Q_WebServer::$reaped) <= 512, true);

echo $fail ? "  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
