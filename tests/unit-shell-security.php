#!/usr/bin/env php
<?php

/**
 * The Q shell's security review (#70), as regression tests.
 *
 * Asserted:
 *   - a command's environment keeps the path, locale and the server's own
 *     QBIX_/VC_ variables, and nothing else of the server's (tokens,
 *     credentials, agent sockets);
 *   - a command inherits none of the server's descriptors: a listening
 *     socket open in the parent is not open in the child;
 *   - the WebSocket opens only from the same scheme, host and port, or an
 *     origin listed in Q.shell.allowedOrigins -- not from another port on
 *     the same host, which browsers send the same cookies;
 *   - a setting the runner asks the server to apply is checked again there:
 *     unknown, protected and ill-typed values are refused;
 *   - as root, a directory another user could change is reported, and sudo
 *     will not run a script from one as root.
 *
 *   php tests/unit-shell-security.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
function skip($what) { echo "  skip  $what\n"; }

// ── Environment ──────────────────────────────────────────────────────────
putenv('QSH_TEST_SECRET=hunter2');
putenv('AWS_SECRET_ACCESS_KEY=x');
putenv('QBIX_CONF_DIR=/etc/qbix-test');
$env = Q_WebServer_Shell_Exec::environment(array('MINE' => 'yes', 'bad name' => 'no'));
check('a server secret is not passed on', isset($env['QSH_TEST_SECRET']) || isset($env['AWS_SECRET_ACCESS_KEY']), false);
check('the server\'s own configuration variables are', $env['QBIX_CONF_DIR'] ?? null, '/etc/qbix-test');
check('PATH is always there', isset($env['PATH']), true);
check('exported variables are added; bad names are not', array($env['MINE'] ?? null, isset($env['bad name'])), array('yes', false));

// ── Descriptors ──────────────────────────────────────────────────────────
if (is_dir('/proc/self/fd')) {
	$server = stream_socket_server('tcp://127.0.0.1:0');
	$io = new Q_WebServer_Shell_BufferIo();
	$sink = new Q_WebServer_Shell_Sink($io, false, 1 << 20);
	Q_WebServer_Shell_Exec::run(array('/bin/sh', '-c', 'for f in /proc/self/fd/*; do readlink "$f"; done'), '', $sink);
	check('the child holds no socket of the parent\'s', strpos($io->out, 'socket:'), false);
	check('only pipes (and its own listing)', (bool) preg_match('/^(pipe:\[\d+\]|\/proc\/\d+\/fd)$/m', $io->out), true);
	fclose($server);
} else {
	skip('descriptors (no /proc)');
}

// ── Origin ───────────────────────────────────────────────────────────────
function origin($origin, $host, $https = false)
{
	return Q_WebServer_Shell_Api::originAllowed(array('headers' => array('origin' => $origin, 'host' => $host), 'https' => $https));
}
check('same origin', origin('http://example.test:8080', 'example.test:8080'), true);
check('default port written or not', origin('https://example.test', 'example.test:443', true), true);
check('another port on the same host is refused', origin('https://example.test', 'example.test:8080', true), false);
check('another scheme is refused', origin('http://example.test:8080', 'example.test:8080', true), false);
check('another host is refused', origin('https://evil.test:8080', 'example.test:8080', true), false);
check('a lookalike host is refused', origin('https://example.test.evil.test:8080', 'example.test:8080', true), false);
check('no Origin is refused', origin('', 'example.test'), false);
check('Origin null is refused', origin('null', 'example.test'), false);
check('an origin with a path is refused', origin('http://example.test/x', 'example.test'), false);
// The running server marks TLS with '_https', not 'https' (handleRequest()).
$tls = function ($origin, $host) {
	return Q_WebServer_Shell_Api::originAllowed(array('headers' => array('origin' => $origin, 'host' => $host), '_https' => true));
};
check('same origin over TLS as the server marks it', $tls('https://example.test:8080', 'example.test:8080'), true);
check('plain http page refused over TLS as the server marks it', $tls('http://example.test:8080', 'example.test:8080'), false);
check('the session cookie is Secure over TLS HTTP/1.1', strpos(Q_WebServer_Panel_Auth::sessionCookie('t', array('_https' => true, 'httpVersion' => '1.1')), '; Secure') !== false, true);
Q_Config::set('Q', 'shell', 'allowedOrigins', array('https://proxy.test'));
check('a listed origin is allowed', origin('https://proxy.test', 'backend:8080'), true);
check('only as listed (port)', origin('https://proxy.test:444', 'backend:8080'), false);
Q_Config::set('Q', 'shell', 'allowedOrigins', array());

// ── Settings the runner asks for ─────────────────────────────────────────
check('an unknown setting is refused', Q_WebServer_Shell_Server::apply('Q.shell.allowRoot', true) !== null, true);
check('a protected setting is refused', Q_WebServer_Shell_Server::apply('shell.allowSystem', true) !== null, true);
check('an ill-typed value is refused', Q_WebServer_Shell_Server::apply('shell.timeout', 'forever') !== null, true);
check('an array value is refused', Q_WebServer_Shell_Server::apply('shell.timeout', array(1)) !== null, true);
check('a good value is applied', array(Q_WebServer_Shell_Server::apply('shell.timeout', '90'), Q_Config::get('Q', 'shell', 'timeout', null)), array(null, 90));

// ── Directories another user could change ───────────────────────────────
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	$base = sys_get_temp_dir() . '/qsh-sec-' . getmypid();
	@mkdir($base . '/safe', 0700, true);
	chmod($base, 0700);
	check('a root-only path is trusted', Q_WebServer_Shell::untrustedPath($base . '/safe'), null);
	@mkdir($base . '/open/scripts', 0755, true);
	chmod($base . '/open', 0777);
	check('a world-writable parent is reported', Q_WebServer_Shell::untrustedPath($base . '/open/scripts'), realpath($base . '/open'));
	file_put_contents($base . '/open/scripts/tool', "#!/bin/sh\necho ran as \$(id -u)\n");
	chmod($base . '/open/scripts/tool', 0755);
	$io = new Q_WebServer_Shell_BufferIo();
	$reg = new Q_WebServer_Shell_Registry(array('scriptsDir' => $base . '/open/scripts'), array());
	$sh = new Q_WebServer_Shell_Interpreter($io, $reg, new Q_WebServer_Shell_History($base . '/safe'),
		array('tier' => 'advanced', 'root' => true, 'allowRoot' => true, 'elevatedUntil' => time() + 60, 'runsAs' => 'root'));
	check('sudo will not run such a script as root', array($sh->runLine('sudo tool', false), strpos($io->out, 'ran as'), strpos($io->err, 'not run as root') !== false), array(1, false, true));
	@unlink($base . '/open/scripts/tool'); @unlink($base . '/safe/history');
	@rmdir($base . '/open/scripts'); @rmdir($base . '/open'); @rmdir($base . '/safe'); @rmdir($base);
} else {
	skip('untrusted directories (not root)');
}

echo $fail ? "\n  FAIL - $fail of " . ($pass + $fail) . " case(s)\n" : "  PASS - $pass case(s)\n";
exit($fail ? 1 : 0);
