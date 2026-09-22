<?php

/**
 * Do the cookies a script set reach the client over HTTP/2?
 *
 * They did not. A worker builds cookies with setcookie() in its own process, so
 * a pooled response carries them separately from its headers, and the HTTP/2
 * path only ever read the headers. Every Set-Cookie was silently dropped, which
 * made signing in impossible over h2: the login answered 302 to the right place
 * and set no session.
 *
 * Nothing noticed, because a browser holding a session carried on working and a
 * session obtained over HTTP/1.1 is equally good over HTTP/2. Only a fresh
 * sign-in on an h2 connection could see it.
 *
 *   php tests/http2-cookies.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$F = 'Q_WebServer_Http2_Frame';

$out = fopen('php://temp', 'w+b');

$conn = new Q_WebServer_Http2_Connection($out, function () {
	return array(
		'status' => 302,
		'headers' => array(
			'location' => 'https://example.com/dashboard',
			'content-type' => 'text/html; charset=utf-8',
		),
		// Exactly how a pooled response carries them.
		'cookies' => array(
			'SESSIONID=abc123; Path=/',
			'is_logged_in=true; Path=/',
		),
		'body' => 'Redirecting',
	);
});
$conn->start();

$conn->feed($F::PREFACE . $F::build($F::SETTINGS, 0, 0, ''));

$hpack = new Q_WebServer_Http2_Hpack();
$block = $hpack->encode(array(
	array(':method', 'POST'), array(':scheme', 'https'),
	array(':authority', 'example.com'), array(':path', '/user/login'),
));
$conn->feed($F::build(
	$F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, 1, $block
));

// Decode the HEADERS frame the connection wrote back.
rewind($out);
$buf = stream_get_contents($out);
$decoder = new Q_WebServer_Http2_Hpack();
$fields = array();
while (($frame = $F::read($buf)) !== null) {
	if ($frame['type'] !== $F::HEADERS) continue;
	foreach ($decoder->decode($frame['payload']) as $pair) {
		$fields[] = $pair;
	}
}

$cookies = array();
$status = '';
foreach ($fields as $pair) {
	if ($pair[0] === 'set-cookie') $cookies[] = $pair[1];
	if ($pair[0] === ':status') $status = $pair[1];
}

echo "\n";
printf("  status:          %s\n", $status === '' ? '(none)' : $status);
printf("  set-cookie sent: %d\n", count($cookies));
foreach ($cookies as $c) {
	printf("      %s\n", $c);
}

$want = array('SESSIONID=abc123; Path=/', 'is_logged_in=true; Path=/');
echo "\n";
if ($cookies === $want and $status === '302') {
	echo "  PASS - both cookies arrived, in order, with the status\n\n";
	exit(0);
}
echo "  FAIL - expected " . count($want) . " cookie(s) and a 302\n\n";
exit(1);
