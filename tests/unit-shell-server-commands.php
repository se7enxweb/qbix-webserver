#!/usr/bin/env php
<?php
/**
 * Every shell command works under the server, whoever the runner runs as.
 *
 * The runner drops to Q.shell.user, which cannot read a root-only site
 * configuration (0600), the server's logs, or reconfigure the server. The
 * engine's console commands and `logs tail` are therefore run by the server
 * for the runner (Q_WebServer_Shell_Server::serverRun()). Asserted, through
 * real runners and the event loop:
 *
 *   - server configtest and cache clear see the 0600 configuration, with no
 *     PHP warning in the output;
 *   - logs tail reads a root-only log;
 *   - -f / --force confirms wherever it is on the line (cache -f clear), and
 *     without it a non-interactive run is refused, saying how to confirm;
 *   - the HTTP route and the WebSocket both honour force, and both prompt
 *     when interactive and not forced;
 *   - the server checks a request again: a command above the job's tier, one
 *     that needs the password without it, and anything that is not an engine
 *     console command are refused, whatever the runner says;
 *   - PHP's warnings are kept out of the terminal, and an error that stops a
 *     command becomes one plain line;
 *   - lines that name no command say what is there (a noun without its verb,
 *     a verb the noun lacks), never suggesting the name itself;
 *   - a built-in that is also a noun (layout) yields to the noun's verbs.
 *
 * As root, with the source tree readable by others and a "nobody" user, the
 * runners switch to nobody, as on a production server; otherwise they run as
 * this user and the same paths are exercised.
 *
 *   php tests/unit-shell-server-commands.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
$pass = 0; $fail = 0; $skipped = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
function has($what, $text, $needle, $want = true)
{
	check($what, strpos((string) $text, $needle) !== false, $want);
}
require __DIR__ . '/../src/Q.php';
foreach (array('Shell.php', 'Shell/Server.php', 'Shell/Api.php', 'Log.php', 'Layout.php', 'Panel/Store.php', 'Panel/Auth.php') as $f) require_once __DIR__ . '/../src/Q/WebServer/' . $f;
foreach ((array) glob(__DIR__ . '/../src/Q/WebServer/Shell/*.php') as $f) require_once $f;

// ── A server's configuration, root-only when we are root ─────────────────
$base = sys_get_temp_dir() . DS . 'qbix-shell-cmds-' . getmypid();
@mkdir($base . '/conf/sites-enabled', 0755, true);
@mkdir($base . '/cache', 0755, true);
@mkdir($base . '/doc', 0755, true);
@mkdir($base . '/log', 0700, true);
chmod($base, 0755);
$confFile = $base . '/conf/sites-enabled/site.conf';
file_put_contents($confFile, json_encode(array('Q' => array('web' => array('cache' => array('dir' => $base . '/cache'))))));
chmod($confFile, 0600);
file_put_contents($base . '/cache/page', 'cached');
$accessLog = $base . '/log/access.log';
file_put_contents($accessLog, "127.0.0.1 - - [x] \"GET /only-root-reads-this HTTP/1.1\" 200 1\n");
chmod($accessLog, 0600);
Q_WebServer_Log::$accessPath = $accessLog;

$root = function_exists('posix_geteuid') && posix_geteuid() === 0;
// As on a production server, the runners switch to an unprivileged user:
// the owner of this source tree or the nearest directory above it (as
// Q.shell.user defaults to the document root's owner), else nobody -- the first that can actually read the tree.
$canRead = function ($name) {
	if (!function_exists('pcntl_fork') || !function_exists('posix_getpwnam')) return false;
	$pw = posix_getpwnam($name);
	if (!$pw || (int) $pw['uid'] === 0) return false;
	$pid = pcntl_fork();
	if ($pid === 0) {
		@posix_setgid((int) $pw['gid']); @posix_setuid((int) $pw['uid']);
		exit(is_readable(__DIR__ . '/../qshell.php') && is_readable(__DIR__ . '/../src/Q.php') ? 0 : 1);
	}
	pcntl_waitpid($pid, $st);
	return pcntl_wifexited($st) && pcntl_wexitstatus($st) === 0;
};
$switchTo = null;
if ($root) {
	// The tree's owner, or of the nearest directory above it not owned by root.
	$ownerName = null;
	for ($d = realpath(__DIR__ . '/..'); $d && $d !== '/' && $ownerName === null; $d = dirname($d)) {
		$owner = @fileowner($d);
		if ($owner && function_exists('posix_getpwuid') && ($o = posix_getpwuid($owner))) $ownerName = $o['name'];
	}
	foreach (array_filter(array($ownerName, 'nobody')) as $cand) if ($canRead($cand)) { $switchTo = $cand; break; }
}
$switch = $switchTo !== null;
if ($switch) {
	Q_Config::set('Q', 'shell', 'user', $switchTo);
	echo "  (runners switch to $switchTo)\n";
} elseif ($root) {
	echo "  (runners stay this user: no unprivileged user can read this tree)\n";
}
Q_Config::set('Q', 'webserver', 'startOptions', array('config' => $confFile, 'confDir' => $base . '/conf', 'root' => $base . '/doc'));
Q_Config::set('Q', 'shell', 'timeout', 60);

// A signed-in panel session: the WebSocket checks it on every message.
@mkdir($base . '/acl', 0700, true);
@mkdir($base . '/sessions', 0700, true);
Q_Config::set('Q', 'panel', 'aclDir', $base . '/acl');
Q_Config::set('Q', 'panel', 'sessionsDir', $base . '/sessions');
Q_WebServer_Panel_Store::reset();
$token = str_repeat('c', 64);
check('a panel session for the test', (bool) Q_WebServer_Panel_Store::sessionPut($token, time() + 600), true);
$key = Q_WebServer_Shell_Server::session($token, 'cmds-p1');

/** Run one line through a real runner; return the job when it is done. */
function runLine($key, $line, array $opts = array())
{
	$r = Q_WebServer_Shell_Server::exec($key, $line, $opts + array('interactive' => false));
	if (empty($r['ok'])) return array('state' => 'refused', 'exit' => null, 'stdout' => '', 'stderr' => (string) ($r['error'] ?? ''));
	return waitJob($r['id']);
}
function waitJob($id, $seconds = 60)
{
	$until = microtime(true) + $seconds;
	while (microtime(true) < $until) {
		Q_Evented::tick(0.05);
		$j = Q_WebServer_Shell_Server::job($id);
		if ($j && $j['state'] === 'done') return $j;
	}
	Q_WebServer_Shell_Server::signal($id, 'KILL');
	return Q_WebServer_Shell_Server::job($id);
}
$noWarning = function ($j) { return !preg_match('/PHP (Warning|Notice|Deprecated)|Failed to open stream/', $j['stdout'] . $j['stderr']); };

// ── The engine's console commands see the root-only configuration ────────
$j = runLine($key, 'server configtest');
check('server configtest: exit 0', $j['exit'], 0);
has('server configtest: checked the 0600 site config', $j['stdout'], 'site.conf');
check('server configtest: no PHP warning in the output', $noWarning($j), true);

$j = runLine($key, 'cache clear');
check('cache clear without -f, nobody to ask: refused', $j['exit'], 1);
has('...and says how to confirm', $j['stderr'], 'add -f');

$j = runLine($key, 'cache clear -f');
check('cache clear -f: exit 0', $j['exit'], 0);
has('cache clear -f: found the cache in the 0600 config', $j['stdout'], 'cache cleared');
check('cache clear -f: no PHP warning', $noWarning($j), true);

$j = runLine($key, 'cache -f clear');
check('-f between the noun and its verb confirms', $j['exit'], 0);
$j = runLine($key, 'cache --force clear');
check('--force between the noun and its verb confirms', $j['exit'], 0);

// ── The server's own log, root-only ─────────────────────────────────────
$j = runLine($key, 'logs tail');
check('logs tail: exit 0', $j['exit'], 0);
has('logs tail: read the root-only log', $j['stdout'], '/only-root-reads-this');
$j = runLine($key, 'logs tail | grep only-root');
has('logs tail in a pipeline', $j['stdout'], '/only-root-reads-this');

// ── HTTP and WebSocket: the same force, the same prompt ─────────────────
$parsed = function ($body) use ($token) {
	return array('method' => 'POST', 'headers' => array('x-panel-token' => $token), 'body' => json_encode($body), 'clientIp' => '127.0.0.1');
};
list($st, $b) = Q_WebServer_Shell_Api::handle('shell/exec', $parsed(array('command' => 'cache clear', 'session' => 'http-p1', 'force' => true)), $token);
check('HTTP exec with force: accepted', $st, 202);
$j = waitJob($b['job']);
check('HTTP exec with force: ran without asking', $j['exit'], 0);

Q_WebServer_Shell_Api::onMessage('ws-test', json_encode(array('t' => 'exec', 'session' => 'ws-p1', 'line' => 'cache clear', 'force' => true)), $token, '127.0.0.1');
$ws = null;
$wsKey = Q_WebServer_Shell_Server::session($token, 'ws-p1');
foreach (Q_WebServer_Shell_Server::jobs(Q_WebServer_Shell_Server::owner($token), $wsKey) as $x) $ws = $x['id'];
$j = $ws ? waitJob($ws) : null;
check('WebSocket exec with force: ran without asking, as HTTP did', $j['exit'] ?? null, 0);

$promptSeen = function ($client) use ($token) {
	$key = Q_WebServer_Shell_Server::session($token, $client);
	$until = microtime(true) + 20;
	while (microtime(true) < $until) {
		Q_Evented::tick(0.05);
		list($msgs) = Q_WebServer_Shell_Server::poll($key, 0);
		foreach ($msgs as $m) if ($m['t'] === 'prompt') return $m;
	}
	return null;
};
list($st, $b) = Q_WebServer_Shell_Api::handle('shell/exec', $parsed(array('command' => 'cache clear', 'session' => 'http-p2', 'interactive' => true)), $token);
$pm = $promptSeen('http-p2');
has('HTTP, interactive, not forced: asks', $pm['d'] ?? '', 'proceed? [y/N]');
if ($pm) { Q_WebServer_Shell_Server::input($pm['id'], 'y'); $j = waitJob($pm['id']); check('...and a yes runs it', $j['exit'], 0); }
Q_WebServer_Shell_Api::onMessage('ws-test', json_encode(array('t' => 'exec', 'session' => 'ws-p2', 'line' => 'cache clear')), $token, '127.0.0.1');
$pm = $promptSeen('ws-p2');
has('WebSocket, not forced: asks, as HTTP does', $pm['d'] ?? '', 'proceed? [y/N]');
if ($pm) { Q_WebServer_Shell_Server::input($pm['id'], 'n'); $j = waitJob($pm['id']); check('...and a no leaves it', $j['exit'], 1); }

// ── The server checks what a runner asks for, again ─────────────────────
$jobs = new ReflectionProperty('Q_WebServer_Shell_Server', 'jobs');
$jobs->setAccessible(true);
$serverRun = new ReflectionMethod('Q_WebServer_Shell_Server', 'serverRun');
$serverRun->setAccessible(true);
$ask = function (array $request, $tier) use ($jobs, $serverRun, $key) {
	list($ours, $theirs) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	$all = $jobs->getValue();
	$all['jfake'] = array('id' => 'jfake', 'session' => $key, 'state' => 'running', 'eof' => false, 'tier' => $tier, 'ip' => '',
		'pipes' => array(0 => $ours), 'wbuf' => '', 'wwatch' => null, 'run' => null, 'bg' => false, 'phpErrors' => 0);
	$jobs->setValue(null, $all);
	$serverRun->invoke(null, 'jfake', $request);
	$until = microtime(true) + 20;
	$got = '';
	stream_set_blocking($theirs, false);
	while (microtime(true) < $until) {
		Q_Evented::tick(0.02);
		$got .= (string) fread($theirs, 65536);
		if (preg_match('/"t":"run_exit".*\n/', $got)) break;
	}
	$all = $jobs->getValue();
	unset($all['jfake']);
	$jobs->setValue(null, $all);
	foreach (explode("\n", $got) as $line) {
		$m = json_decode($line, true);
		if (is_array($m) && ($m['t'] ?? '') === 'run_exit') return $m;
	}
	return null;
};
$m = $ask(array('kind' => 'console', 'name' => 'cache:clear', 'args' => array()), 'basic');
check('a basic job asking for an expanded command: refused by the server', $m['code'] ?? null, 126);
$m = $ask(array('kind' => 'console', 'name' => 'server:stop', 'args' => array()), 'advanced');
check('server stop without the password: refused by the server', $m['code'] ?? null, 1);
has('...saying why', $m['error'] ?? '', 'password');
$m = $ask(array('kind' => 'console', 'name' => 'health', 'args' => array()), 'advanced');
check('not an engine console command: refused', $m['code'] ?? null, 127);
$m = $ask(array('kind' => 'console', 'name' => '../../bin/sh', 'args' => array()), 'advanced');
check('a path for a name: refused', $m['code'] ?? null, 127);
$m = $ask(array('kind' => 'nope'), 'advanced');
check('an unknown kind: refused', $m['code'] ?? null, 2);
$m = $ask(array('kind' => 'console', 'name' => 'server:configtest', 'args' => array("a\0b")), 'advanced');
check('an argument with a NUL: refused', $m['code'] ?? null, 2);

// ── PHP's messages stay out of the terminal ─────────────────────────────
$n = 0;
$out = Q_WebServer_Shell_Server::filterPhpErrors("one\nPHP Warning:  file_get_contents(x): Failed to open stream in y on line 1\ntwo\n", 'demo', $n);
check('a warning is taken out', $out, "one\ntwo\n");
check('...and counted', $n, 1);
$out = Q_WebServer_Shell_Server::filterPhpErrors("PHP Fatal error:  Uncaught Error: z in /a/b.php:3\n", 'demo', $n);
has('a fatal error becomes one plain line', $out, 'demo stopped on a PHP error');
has('...without the path', $out, '/a/b.php', false);

// ── Lines that name no command ──────────────────────────────────────────
$console = array(
	'server:status' => array('description' => 's', 'usage' => '', 'options' => array()),
	'server:stop' => array('description' => 's', 'usage' => '', 'options' => array()),
	'layout:show' => array('description' => 'l', 'usage' => '', 'options' => array()),
	'cache:clear' => array('description' => 'c', 'usage' => '', 'options' => array()),
);
$reg = new Q_WebServer_Shell_Registry(array('serverDir' => '', 'startOptions' => array()), $console);
list($msg, $code) = $reg->notFound(array('server'));
has('a noun alone: lists its verbs', $msg, 'which one? status, stop');
check('...exit 2', $code, 2);
list($msg) = $reg->notFound(array('server', 'stauts'));
has('a verb the noun lacks: suggests', $msg, 'did you mean status');
list($msg) = $reg->notFound(array('servr'));
has('an unknown name: suggests the near one', $msg, 'did you mean server');
list($msg) = $reg->notFound(array('cache', 'nosuch'));
has('never suggests the name itself', $msg, 'did you mean cache', false);

$io = new Q_WebServer_Shell_BufferIo(array(), false);
$sh = new Q_WebServer_Shell_Interpreter($io, $reg, new Q_WebServer_Shell_History(null), array('tier' => 'advanced'));
$sh->runLine('layout sideways', false);
has('layout with its own words stays the shell\'s built-in', $io->err, 'vertical');
$io->err = '';
$sh->runLine('layout show', false);
has('layout show goes to the server\'s command, not the built-in', $io->err, 'vertical|horizontal', false);

// ── Tidy up ─────────────────────────────────────────────────────────────
foreach (array($confFile, $accessLog, $base . '/cache/page') as $f) @unlink($f);
foreach ((array) glob($base . '/sessions/*') as $f) @unlink($f);
foreach ((array) glob($base . '/acl/{,.}*', GLOB_BRACE) as $f) if (is_file($f)) @unlink($f);
foreach (array($base . '/conf/sites-enabled', $base . '/conf', $base . '/cache', $base . '/doc', $base . '/log', $base . '/sessions', $base . '/acl') as $d) @rmdir($d);
@rmdir($base);

printf("  %s - %d case(s)%s\n", $fail ? 'FAIL' : 'PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
