#!/usr/bin/env php
<?php
/**
 * qshell -- the Q shell's runner, and a shell of its own at a terminal.
 *
 *   php qshell.php                      an interactive shell here, on this
 *                                       server's configuration (--config=...,
 *                                       --conf-dir=..., --root=..., --pid=...)
 *   php qshell.php -c 'health; uptime'  run one line and exit
 *   php qshell.php --exec               the server's runner: a JSON request
 *                                       on the first line of stdin, JSON
 *                                       messages out (Q_WebServer_Shell_JsonIo)
 *   php qshell.php --meta               what completion needs, as JSON
 *
 * The server starts one --exec runner per command line, in a process group
 * of its own, so a command can be cancelled, timed out and killed without
 * touching the server. See docs/shell.md.
 */

if (PHP_SAPI !== 'cli') { exit("qshell runs from the command line\n"); }

// One of the engine's console commands, run by the server for a runner that
// asked (Q_WebServer_Shell_Server::serverRun()): in a session of its own, so a
// restart it triggers does not take it down with the server, and with PHP's
// messages on standard error, where the server keeps them out of the terminal.
if (($argv[1] ?? '') === '--console') {
	if (function_exists('posix_setsid')) @posix_setsid();
	$args = array_merge(array('-d', 'display_errors=stderr', '-d', 'log_errors=0', __DIR__ . '/qbixconsole.php'), array_slice($argv, 2));
	if (function_exists('pcntl_exec')) @pcntl_exec(PHP_BINARY, $args);
	$p = proc_open(array_merge(array(PHP_BINARY), $args), array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
	exit(is_resource($p) ? proc_close($p) : 127);
}
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once __DIR__ . '/src/Q.php';

$argvIn = array_slice($argv, 1);
$mode = 'tty';
$oneLine = null;
$opts = array();
for ($i = 0; $i < count($argvIn); $i++) {
	$a = $argvIn[$i];
	if ($a === '--exec') { $mode = 'exec'; continue; }
	if ($a === '--meta') { $mode = 'meta'; continue; }
	if ($a === '-c' && isset($argvIn[$i + 1])) { $oneLine = $argvIn[++$i]; continue; }
	if (preg_match('/^--?(config|conf-dir|root|pid|distribution)=(.*)$/', $a, $m)) { $opts[$m[1]] = $m[2]; continue; }
	if (preg_match('/^--?(config|conf-dir|root|pid|distribution)$/', $a, $m) && isset($argvIn[$i + 1])) { $opts[$m[1]] = $argvIn[++$i]; continue; }
}

if ($mode === 'exec') {
	// A group of its own: the server signals the whole group (this runner and
	// whatever it started) to cancel or time a command out.
	if (function_exists('posix_setsid')) @posix_setsid();
	$line = fgets(STDIN);
	$req = is_string($line) ? json_decode($line, true) : null;
	if (!is_array($req)) {
		fwrite(STDOUT, json_encode(array('t' => 'err', 'd' => "qsh: bad request\n")) . "\n" . json_encode(array('t' => 'exit', 'code' => 2)) . "\n");
		exit(2);
	}
	$ctx = (array) ($req['ctx'] ?? array());
	qshell_config($ctx);
	$io = new Q_WebServer_Shell_JsonIo(STDIN, STDOUT, !empty($req['interactive']));
	$io->serverRun = !empty($req['serverRun']);
	// History, aliases and the shell directory's scripts come from the server,
	// and changes go back to it: this runner may not be able to reach them.
	$history = array_key_exists('history', $req)
		? Q_WebServer_Shell_History::remote((array) $req['history'], (array) ($req['aliases'] ?? array()),
			(array) ($req['files'] ?? array()), function ($m) use ($io) { $io->send($m); },
			isset($req['historyCount']) ? (int) $req['historyCount'] : null,
			function ($m) use ($io) { return $io->request($m, 'hist_reply'); })
		: null;
	$shell = qshell_build($ctx, $io, $req, $history);
	// Everything this runner needs is loaded now, before it gives up the
	// server's rights: the unprivileged user may not be able to read it.
	qshell_preload();
	if (!empty($req['runAs'])) {
		$err = qshell_become((string) $req['runAs']);
		if ($err !== null) {
			$io->err('qsh: ' . $err . "\n");
			$io->send(array('t' => 'exit', 'code' => 126));
			exit(126);
		}
	}
	$shell->vars = array_map('strval', (array) ($req['vars'] ?? array()));
	$shell->exported = array_fill_keys(array_filter((array) ($req['exported'] ?? array()), 'is_string'), true);
	$shell->context = (array) ($req['context'] ?? array());
	$shell->status = (int) ($req['status'] ?? 0);
	if (!empty($req['source'])) {
		$sink = new Q_WebServer_Shell_Sink($io, false, (int) $shell->opts['maxOutput']);
		$shell->scriptDepth++;
		$code = $shell->builtins->run('source', array((string) $req['source']), '', $sink);
	} else {
		$code = $shell->runLine((string) ($req['line'] ?? ''), empty($req['noHistory']));
	}
	$io->send(array('t' => 'state', 'vars' => (object) $shell->vars, 'exported' => array_keys($shell->exported),
		'context' => (object) $shell->context, 'status' => $code));
	$io->send(array('t' => 'exit', 'code' => $code));
	exit(0);
}

$ctx = qshell_local_context($opts);
qshell_config($ctx);

if ($mode === 'meta') {
	$reg = new Q_WebServer_Shell_Registry($ctx);
	$history = new Q_WebServer_Shell_History($ctx['shellDir'] ?? null);
	$commands = array();
	foreach ($reg->all() as $spec) {
		$commands[] = array('name' => $spec['name'], 'tier' => $spec['tier'], 'disruptive' => !empty($spec['disruptive']),
			'description' => $spec['description'], 'usage' => $spec['usage'],
			'options' => array_keys((array) $spec['options']), 'console' => $spec['console'] ?? null,
			'aliases' => (array) $spec['aliases'], 'source' => $spec['source']);
	}
	echo json_encode(array(
		'commands' => $commands,
		'builtins' => array_keys(Q_WebServer_Shell_Builtins::DOCS),
		'settings' => array_keys(Q_WebServer_Shell_Settings::TABLE),
		'aliases' => array_keys($history->aliases()),
	), JSON_UNESCAPED_SLASHES) . "\n";
	exit(0);
}

// At a terminal: the same shell, answered from the keyboard.
$io = new Q_WebServer_Shell_TtyIo();
$shell = qshell_build($ctx, $io, array('tier' => 'advanced', 'allowSystem' => true, 'elevation' => false));
if ($oneLine !== null) exit($shell->runLine($oneLine, false));
fwrite(STDOUT, "Q shell -- `help` for commands, `exit` or Ctrl-D to leave\n");
$useReadline = function_exists('readline') && function_exists('stream_isatty') && @stream_isatty(STDIN);
if ($useReadline) foreach ($shell->history->page(Q_WebServer_Shell_History::PAGE) as $h) readline_add_history($h['c']);
while (true) {
	$prompt = 'qsh' . (isset($shell->context['site']) ? ':' . $shell->context['site'] : '') . ($shell->status ? ' [' . $shell->status . ']' : '') . '> ';
	$line = $useReadline ? readline($prompt) : (fwrite(STDOUT, $prompt) ? fgets(STDIN) : false);
	if ($line === false) { fwrite(STDOUT, "\n"); break; }
	$line = rtrim($line, "\r\n");
	if (trim($line) === 'exit') break;
	if ($useReadline && trim($line) !== '') readline_add_history($line);
	$shell->runLine($line);
}
exit($shell->status);

/** Configuration the registry and settings read, from the context. */
function qshell_config(array $ctx)
{
	$start = (array) ($ctx['startOptions'] ?? array());
	if (!empty($start['config']) && is_file($start['config'])) {
		try { Q_Config::load($start['config']); } catch (Exception $e) { /* read-only use; the server has it */ }
	}
	if (!empty($ctx['confDirs'])) Q_Config::set('Q', 'webserver', 'confDirs', (array) $ctx['confDirs']);
}

/** The interpreter, its registry and its history, for a context. */
function qshell_build(array $ctx, Q_WebServer_Shell_Io $io, array $req, Q_WebServer_Shell_History $history = null)
{
	$registry = new Q_WebServer_Shell_Registry($ctx);
	if ($history === null) $history = new Q_WebServer_Shell_History($ctx['shellDir'] ?? null);
	return new Q_WebServer_Shell_Interpreter($io, $registry, $history, array(
		'tier' => isset(Q_WebServer_Shell_Interpreter::TIERS[$req['tier'] ?? '']) ? $req['tier'] : 'basic',
		'allowSystem' => !empty($req['allowSystem']),
		'force' => !empty($req['force']),
		'maxOutput' => (int) ($req['maxOutput'] ?? 8388608),
		'user' => (string) ($req['user'] ?? 'panel'),
		'elevatedUntil' => (int) ($req['elevatedUntil'] ?? 0),
		'elevationLocked' => !empty($req['elevationLocked']),
		'elevateMinutes' => (int) ($req['elevateMinutes'] ?? 5),
		'elevation' => array_key_exists('elevation', $req) ? (bool) $req['elevation'] : true,
		'root' => !empty($req['root']),
		'allowRoot' => !empty($req['allowRoot']),
		'runsAs' => (string) ($req['runsAs'] ?? ''),
	));
}

/** A context for qshell run by hand, from --config and friends. */
function qshell_local_context(array $opts)
{
	$start = array(
		'config' => $opts['config'] ?? null,
		'confDir' => $opts['conf-dir'] ?? null,
		'root' => $opts['root'] ?? null,
		'pid' => $opts['pid'] ?? null,
		'distribution' => $opts['distribution'] ?? null,
	);
	if (!empty($start['config']) && is_file($start['config'])) {
		try { Q_Config::load($start['config']); } catch (Exception $e) {}
	}
	require_once __DIR__ . '/src/Q/WebServer/Ctl.php';
	$panelFile = Q_WebServer_Ctl::panelPasswordFile(array('root' => $start['root'] ?: null));
	$shellDir = $panelFile ? dirname($panelFile) . '/shell' : Q_WebServer_Shell::dataDir();
	$themes = array();
	foreach (Q_WebServer_Shell::themes() as $n => $t) $themes[$n] = (string) ($t['label'] ?? $n);
	return array(
		'serverDir' => __DIR__,
		'startOptions' => $start,
		'shellDir' => $shellDir,
		'scriptsDir' => Q_WebServer_Shell::config('scriptsDir'),
		'themes' => $themes,
		'runtime' => array(),
		'settings' => array(),
		'runtimeChanged' => array(),
	);
}

/**
 * Switch this process to another user, for good (group, supplementary
 * groups, then user). Null on success, else why it could not.
 */
function qshell_become($name)
{
	if (!function_exists('posix_getpwnam') || !function_exists('posix_setuid')) return 'cannot switch to ' . $name . ' (no posix extension)';
	$pw = posix_getpwnam($name);
	if (!$pw) return 'no such user: ' . $name;
	if ((int) $pw['uid'] === 0) return 'refusing to switch to a root user';
	if (posix_geteuid() !== 0) return (posix_geteuid() === (int) $pw['uid']) ? null : 'the server is not root, so it cannot switch to ' . $name;
	// The user's own groups, not root's: without them the command would keep
	// root's supplementary groups (disk, adm, wheel ...) after the switch.
	if (!function_exists('posix_initgroups') || !@posix_initgroups($name, (int) $pw['gid'])) {
		return 'could not set the groups of ' . $name;
	}
	if (!posix_setgid((int) $pw['gid']) || !posix_setuid((int) $pw['uid']) || posix_geteuid() !== (int) $pw['uid']) {
		return 'could not switch to ' . $name;
	}
	putenv('HOME=' . $pw['dir']);
	putenv('USER=' . $name);
	putenv('LOGNAME=' . $name);
	return null;
}

/** Load every class a runner can need, while it can still read them. */
function qshell_preload()
{
	foreach ((array) glob(__DIR__ . '/src/Q/WebServer/Shell/*.php') as $f) require_once $f;
	foreach (array('Shell.php', 'Panel/Auth.php', 'Panel/PasswordPolicy.php', 'Design.php') as $f) {
		if (is_file(__DIR__ . '/src/Q/WebServer/' . $f)) require_once __DIR__ . '/src/Q/WebServer/' . $f;
	}
}
