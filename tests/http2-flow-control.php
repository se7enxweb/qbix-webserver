<?php

/**
 * Does a body larger than the flow-control window resume when room is granted?
 *
 * A peer that advertises a small window gets one window's worth and then has to
 * say, with WINDOW_UPDATE, that it has read some of it. If the server does not
 * resume at that point the response stops dead -- at exactly one window, every
 * time, on a connection healthy enough to answer a PING in about a millisecond.
 *
 * curl and Chromium negotiate windows large enough to swallow an entire
 * response, so neither ever reaches this path. Firefox uses 128KB and does.
 *
 *   php tests/http2-flow-control.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$F = 'Q_WebServer_Http2_Frame';

$window = 16384;
$body = str_repeat('x', 100000);

$out = fopen('php://temp', 'w+b');

$conn = new Q_WebServer_Http2_Connection($out, function () use ($body) {
	return array(
		'status' => 200,
		'headers' => array('content-type' => 'application/javascript'),
		'body' => $body,
	);
});
$conn->start();

$conn->feed($F::PREFACE . $F::build(
	$F::SETTINGS, 0, 0, pack('nN', $F::SETTINGS_INITIAL_WINDOW_SIZE, $window)
));

$hpack = new Q_WebServer_Http2_Hpack();
$block = $hpack->encode(array(
	array(':method', 'GET'), array(':scheme', 'https'),
	array(':authority', 'example.com'), array(':path', '/big.js'),
));
$conn->feed($F::build(
	$F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, 1, $block
));

function dataBytes($handle)
{
	$F = 'Q_WebServer_Http2_Frame';
	rewind($handle);
	$buf = stream_get_contents($handle);
	$total = 0;
	while (($frame = $F::read($buf)) !== null) {
		if ($frame['type'] === $F::DATA) $total += strlen($frame['payload']);
	}
	return $total;
}

printf("\n  body %d bytes, peer window %d\n\n", strlen($body), $window);
printf("  after the response:            %6d DATA bytes\n", dataBytes($out));

$conn->feed($F::build($F::WINDOW_UPDATE, 0, 1, pack('N', 50000)));
printf("  after a stream WINDOW_UPDATE:  %6d\n", dataBytes($out));

$conn->feed($F::build($F::WINDOW_UPDATE, 0, 0, pack('N', 50000)));
printf("  after a connection one:        %6d\n", dataBytes($out));

$conn->feed($F::build($F::WINDOW_UPDATE, 0, 1, pack('N', 50000)));
$conn->feed($F::build($F::WINDOW_UPDATE, 0, 0, pack('N', 50000)));
$final = dataBytes($out);
printf("  after more of both:            %6d\n", $final);

echo "\n";
if ($final === strlen($body)) {
	echo "  PASS - the whole body was sent\n\n";
	exit(0);
}
printf("  FAIL - stopped at %d of %d\n\n", $final, strlen($body));
exit(1);
