<?php

/**
 * A response must not promise a connection the server is about to close.
 *
 * After a response the server keeps the connection only when the request
 * allowed it -- fewer than keepAlive.max requests on it, no "Connection:
 * close" -- and the status is below 500. sendResponse() said "keep-alive"
 * whatever that decision was. The cache hit goes through sendResponse(), so on
 * a connection reaching keepAlive.max (1000 as configured on the installation
 * where this was found, 2026-09-26) the 1000th response said keep-alive and
 * the server closed the socket straight after it. The client's next request
 * went into a closed connection: ApacheBench reported roughly one "Length" or
 * "Exception" failure per thousand requests, exactly at request 1000, 2000...
 *
 *   php tests/unit-keepalive-connection-header.php
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

// Exercised through a copy of the method taken from the source at run time,
// as the other unit tests do: Q_WebServer pulls in the world.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
if (!preg_match('/\n\tstatic function connectionHeader\(.*?\n\t\}\n/s', $src, $m)) {
	fwrite(STDERR, "  FAIL - connectionHeader() not found in src/Q/WebServer.php\n");
	exit(1);
}
eval('class T { ' . $m[0] . ' }');

// What the header says has to match what the server does next.
check('allowed, 200 keeps the connection',       T::connectionHeader(true, 200), 'keep-alive');
check('allowed, 304 keeps the connection',       T::connectionHeader(true, 304), 'keep-alive');
check('allowed, 404 keeps the connection',       T::connectionHeader(true, 404), 'keep-alive');
check('allowed, 499 keeps the connection',       T::connectionHeader(true, 499), 'keep-alive');
check('allowed, 500 closes (server closes too)', T::connectionHeader(true, 500), 'close');
check('allowed, 502 closes',                     T::connectionHeader(true, 502), 'close');
check('last request before keepAlive.max closes', T::connectionHeader(false, 200), 'close');
check('client asked to close, 200 closes',       T::connectionHeader(false, 200), 'close');
check('not allowed, 500 closes',                 T::connectionHeader(false, 500), 'close');

// sendResponse() must ask for it rather than assume keep-alive, and the
// request's decision must be in place before the request is handled and gone
// after it, so a later callback does not inherit a stale "close".
if (!preg_match('/\n\tstatic function sendResponse\(.*?\n\t\}\n/s', $src, $send)) {
	fwrite(STDERR, "  FAIL - sendResponse() not found\n");
	exit(1);
}
check('sendResponse() defaults through connectionHeader()',
	(bool) preg_match('/\$conn = \$extra\[\'Connection\'\] \?\? self::connectionHeader\(self::\$requestKeepAlive, \$status\);/', $send[0]),
	true);
check('sendResponse() no longer hardcodes keep-alive',
	strpos($send[0], "\$extra['Connection'] ?? 'keep-alive'"), false);
$set = strpos($src, 'self::$requestKeepAlive = $parsed[\'_keepAlive\'];');
$handle = strpos($src, '$keepOpen = self::handleRequest($client, $parsed);');
check('decision recorded before handleRequest()', $set !== false && $handle !== false && $set < $handle, true);
$reset = strpos($src, 'self::$requestKeepAlive = true;', (int) $handle);
check('decision reset after handleRequest()', $reset !== false && $reset > $handle, true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
