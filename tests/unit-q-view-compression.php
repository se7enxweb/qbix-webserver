<?php

/**
 * The server's own pages (/Q/...) go out compressed to a client that accepts
 * it, over HTTP/1.1 as over HTTP/2.
 *
 * The panel page is some 150KB of HTML, CSS and JavaScript and was sent
 * uncompressed on HTTP/1.1 -- five times the bytes it needed. Asserted:
 *
 *   - Accept-Encoding is read per coding, with q=0 a refusal and * a wildcard;
 *   - brotli when the extension is loaded and asked for, else gzip, and only
 *     when the result is smaller;
 *   - Vary gains Accept-Encoding without losing what it held;
 *   - sendResponse() compresses while a /Q/ request is being answered: the
 *     one Content-Length is the compressed length, and the body decodes back;
 *   - never a tiny body, an image, an event stream, a body already encoded,
 *     or anything outside /Q/.
 *
 *   php tests/unit-q-view-compression.php
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

require_once __DIR__ . '/../src/Q/WebServer/Headers.php';
$H = 'Q_WebServer_Headers';

// ── Accept-Encoding ──────────────────────────────────────────────────────
check('gzip named', $H::acceptsCoding('gzip, deflate, br', 'gzip'), true);
check('br named', $H::acceptsCoding('gzip, deflate, br', 'br'), true);
check('not named', $H::acceptsCoding('gzip', 'br'), false);
check('q=0 refuses', $H::acceptsCoding('gzip;q=0, br', 'gzip'), false);
check('q=0.5 accepts', $H::acceptsCoding('gzip; q=0.5', 'gzip'), true);
check('* covers a coding not named', $H::acceptsCoding('*', 'gzip'), true);
check('...but not one refused by name', $H::acceptsCoding('gzip;q=0, *', 'gzip'), false);
check('"brotli" is not "br"', $H::acceptsCoding('brotli', 'br'), false);
check('empty accepts nothing', $H::acceptsCoding('', 'gzip'), false);

// ── Encoding ─────────────────────────────────────────────────────────────
$page = str_repeat('<div class="card">Quiet text, much repeated.</div>' . "\n", 200);
$e = $H::encode($page, 'gzip');
check('gzip when only gzip is accepted', $e[0], 'gzip');
check('...and it decodes back', gzdecode($e[1]), $page);
$e = $H::encode($page, 'br, gzip');
check('brotli when loaded and accepted, else gzip', $e[0], function_exists('brotli_compress') ? 'br' : 'gzip');
check('nothing when nothing is accepted', $H::encode($page, 'identity'), null);
check('nothing when it would not be smaller', $H::encode(random_bytes(64), 'gzip'), null);

// ── Vary ─────────────────────────────────────────────────────────────────
$h = array(); $H::addVary($h, 'Accept-Encoding');
check('Vary added', $h, array('Vary' => 'Accept-Encoding'));
$h = array('vary' => 'Cookie'); $H::addVary($h, 'Accept-Encoding');
check('Vary kept and extended', $h, array('vary' => 'Cookie, Accept-Encoding'));
$h = array('Vary' => 'Cookie, accept-encoding'); $H::addVary($h, 'Accept-Encoding');
check('Vary not repeated', $h, array('Vary' => 'Cookie, accept-encoding'));

// ── sendResponse(), through a copy of its source ─────────────────────────
// Q_WebServer is large and pulls in the world; the methods are copied from
// the file at run time, so they cannot drift from it.
$src = file_get_contents(__DIR__ . '/../src/Q/WebServer.php');
$methods = '';
foreach (array('sendResponse', 'connectionHeader', 'http1Head', 'headerLines') as $name) {
	if (!preg_match('/\n\tstatic function ' . $name . '\(.*?\n\t\}\n/s', $src, $m)) {
		fwrite(STDERR, "  FAIL - $name() not found in src/Q/WebServer.php\n");
		exit(1);
	}
	$methods .= $m[0];
}
eval('class Q_WS_Copy { static $lastStatus; static $lastBody; static $lastBytes; static $compressFor = null;
	static $requestKeepAlive = true;
	static function writeAll($c, $d) { return fwrite($c, $d) === strlen($d); }' . $methods . '}');

function send($compressFor, $status, $body, $type, $extra = array())
{
	Q_WS_Copy::$compressFor = $compressFor;
	$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	Q_WS_Copy::sendResponse($pair[0], $status, $body, $type, $extra);
	fclose($pair[0]);
	$raw = stream_get_contents($pair[1]);
	fclose($pair[1]);
	list($head, $out) = explode("\r\n\r\n", $raw, 2);
	$fields = array();
	foreach (array_slice(explode("\r\n", $head), 1) as $line) {
		list($k, $v) = explode(':', $line, 2);
		$fields[strtolower($k)][] = trim($v);
	}
	return array($fields, $out);
}

$gz = array('accept-encoding' => 'gzip');
list($f, $out) = send($gz, 200, $page, 'text/html; charset=utf-8');
check('a /Q/ page is compressed', $f['content-encoding'] ?? null, array('gzip'));
check('...with Vary', $f['vary'] ?? null, array('Accept-Encoding'));
check('...one Content-Length, the compressed length', $f['content-length'] ?? null, array((string) strlen($out)));
check('...and the body decodes back', gzdecode($out), $page);
check('...the access log counts the bytes sent', Q_WS_Copy::$lastBytes, strlen($out));

list($f, $out) = send($gz, 200, json_encode(array_fill(0, 200, 'metric')), 'text/plain', array('Content-Type' => 'application/json'));
check('JSON, typed by the caller\'s header, is compressed', $f['content-encoding'] ?? null, array('gzip'));

list($f, $out) = send($gz, 200, 'short', 'text/html');
check('a tiny body is not', isset($f['content-encoding']), false);
list($f, $out) = send($gz, 200, str_repeat("\x89PNG", 2000), 'image/png');
check('an image is not', isset($f['content-encoding']), false);
list($f, $out) = send($gz, 200, str_repeat("data: x\n\n", 500), 'text/event-stream');
check('an event stream is not', isset($f['content-encoding']), false);
list($f, $out) = send($gz, 200, gzencode($page), 'text/html', array('Content-Encoding' => 'gzip'));
check('a body already encoded is not encoded twice', gzdecode($out), $page);
list($f, $out) = send(array(), 200, $page, 'text/html');
check('a client that does not ask gets it plain', array(isset($f['content-encoding']), $out === $page), array(false, true));
list($f, $out) = send(null, 200, $page, 'text/html');
check('outside /Q/ nothing changes here', array(isset($f['content-encoding']), $out === $page), array(false, true));

// The Connection header follows the request's keep-alive decision.
check('a request that may stay open says keep-alive', $f['connection'] ?? null, array('keep-alive'));
Q_WS_Copy::$requestKeepAlive = false;
list($f, $out) = send(null, 200, $page, 'text/html');
Q_WS_Copy::$requestKeepAlive = true;
check('the last one before keepAlive.max says close', $f['connection'] ?? null, array('close'));

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
