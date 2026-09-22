<?php

/**
 * Each known way to make an HTTP/2 server hold state it cannot bound.
 *
 * None of these need an authenticated user, a malformed frame or a bug. They
 * are ordinary protocol use taken to excess, which is what makes them hard to
 * see in a log: no request completes, so nothing is recorded as having
 * happened, and the process simply grows until it dies.
 *
 * Every case below mounts the attack against the connection class and asserts
 * that it is refused. A test that only asserts the limit constant exists would
 * pass against a server that never checks it -- which is exactly the state
 * this file was written to leave behind, since SETTINGS advertised a stream
 * limit of 128 for months while nothing counted.
 *
 *   php tests/http2-limits.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/ErrorPage.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$F = 'Q_WebServer_Http2_Frame';
$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; printf("  PASS  %s\n", $what); return; }
	++$fail;
	printf("  FAIL  %s\n        got %s, want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

function connection(&$out, array $limits = array())
{
	$out = fopen('php://temp', 'w+b');
	$conn = new Q_WebServer_Http2_Connection($out, function () {
		$body = 'ok';
		return array('status' => 200, 'headers' => array(), 'body' => $body);
	});
	foreach ($limits as $k => $v) $conn->limits[$k] = $v;
	$conn->start();
	$conn->feed(Q_WebServer_Http2_Frame::PREFACE
		. Q_WebServer_Http2_Frame::build(Q_WebServer_Http2_Frame::SETTINGS, 0, 0, ''));
	return $conn;
}

function headerBlock($path = '/')
{
	$hpack = new Q_WebServer_Http2_Hpack();
	return $hpack->encode(array(
		array(':method', 'GET'), array(':scheme', 'https'),
		array(':authority', 'example.com'), array(':path', $path),
	));
}

echo "\n";

// ── 1. Concurrent streams ────────────────────────────────────────────────
// The limit was advertised in SETTINGS and never enforced.
$conn = connection($out, array('concurrentStreams' => 8));
$alive = true;
for ($i = 1; $i <= 40 and $alive; $i += 2) {
	// No END_STREAM: each stays open, which is the point.
	$alive = $conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS, $i, headerBlock()));
}
check('a peer cannot exceed the stream concurrency it was told', $alive, false);

// ── 2. CONTINUATION flood (CVE-2024-27316 in shape) ──────────────────────
$conn = connection($out, array('headerListSize' => 4096));
$conn->feed($F::build($F::HEADERS, 0, 1, headerBlock()));   // no END_HEADERS
$alive = true;
for ($i = 0; $i < 400 and $alive; ++$i) {
	$alive = $conn->feed($F::build($F::CONTINUATION, 0, 1, str_repeat("\x00", 256)));
}
check('a header block cannot grow without END_HEADERS', $alive, false);

// ── 3. Request body ──────────────────────────────────────────────────────
$conn = connection($out, array('bodySize' => 8192));
$conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS, 1, headerBlock('/upload')));
$alive = true;
for ($i = 0; $i < 200 and $alive; ++$i) {
	$alive = $conn->feed($F::build($F::DATA, 0, 1, str_repeat('x', 1024)));
}
check('a request body cannot grow past its limit', $alive, false);

// ── 4. Undecoded inbound bytes ───────────────────────────────────────────
// A frame header announcing a length that never arrives.
$conn = connection($out, array('readBuffer' => 16384));
$header = substr($F::build($F::DATA, 0, 1, str_repeat('x', 16384)), 0, 9);
$alive = $conn->feed($header . str_repeat('x', 65536));
check('undecoded inbound bytes are bounded', $alive, false);

// ── 5. Rapid reset (CVE-2023-44487 in shape) ─────────────────────────────
$conn = connection($out, array('resetStreams' => 16, 'concurrentStreams' => 1024));
$alive = true;
for ($i = 1; $i <= 200 and $alive; $i += 2) {
	$conn->feed($F::build($F::HEADERS, $F::FLAG_END_HEADERS, $i, headerBlock()));
	$alive = $conn->feed($F::build($F::RST_STREAM, 0, $i, $F::uint32(8)));
}
check('opening and cancelling streams is bounded', $alive, false);

// ── 6. Frames that oblige an answer ──────────────────────────────────────
$conn = connection($out, array('reflexFrames' => 32));
$alive = true;
for ($i = 0; $i < 400 and $alive; ++$i) {
	$alive = $conn->feed($F::build($F::PING, 0, 0, str_repeat("\x00", 8)));
}
check('PING cannot be used to make the server write forever', $alive, false);

// ── 7. Write-side slowloris ──────────────────────────────────────────────
// A peer that asks for a great deal and then stops reading. The socket here
// is a memory stream that always accepts, so the queue is driven directly.
$out = fopen('php://temp', 'w+b');
$big = str_repeat('x', 200000);
$conn = new Q_WebServer_Http2_Connection($out, function () use ($big) {
	return array('status' => 200, 'headers' => array(), 'body' => $big);
});
$conn->limits['writeBuffer'] = 4096;
$conn->start();
$refused = $conn->write(str_repeat('y', 8192));
check('bytes queued for a peer that is not reading are bounded', $refused, false);

// ── 8. Idle connections ──────────────────────────────────────────────────
$conn = connection($out, array('idleSeconds' => 5));
check('a fresh connection is not idle', $conn->isIdle(microtime(true)), false);
check('a silent connection becomes idle', $conn->isIdle(microtime(true) + 10), true);

// ── 9. The limits are still limits ───────────────────────────────────────
// A configured 0 would mean unbounded, which is the state being left behind.
$conn = connection($out);
$sane = true;
foreach ($conn->limits as $name => $value) {
	if (!is_int($value) or $value <= 0) { $sane = false; break; }
}
check('every limit has a positive default', $sane, true);

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
