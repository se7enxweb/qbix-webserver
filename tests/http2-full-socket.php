<?php

/**
 * Does a response survive a socket that will not take it all at once?
 *
 * This is the case no test run on the machine serving the requests can reach.
 * Over loopback, and on a LAN, the peer drains faster than the server fills, so
 * fwrite() always accepts everything and the short-write path is dead code.
 * Across a real network the kernel send buffer fills on any sizeable body and
 * fwrite() starts returning short counts, and eventually 0 -- which means "ask
 * again when there is room", not that anything failed.
 *
 * Treating that zero as fatal silently discarded the remainder of every large
 * response. Nothing was logged. A browser reported NS_ERROR_NET_PARTIAL_TRANSFER
 * and the page died; every check made from the server said the file was fine.
 *
 * So force the condition: a socket pair, a far end that refuses to read, and a
 * body far larger than will fit. Nothing may be lost.
 *
 *   php tests/http2-full-socket.php
 *
 * Exits non-zero if any byte goes missing.
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';
require __DIR__ . '/../src/Q/WebServer/Http2/Connection.php';

$F = 'Q_WebServer_Http2_Frame';

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
if (!$pair) {
	fwrite(STDERR, "  cannot create a socket pair on this platform\n");
	exit(2);
}
list($server, $peer) = $pair;
stream_set_blocking($server, false);
stream_set_blocking($peer, false);

$body = str_repeat('abcdefgh', 60000);   // 480 KB

$conn = new Q_WebServer_Http2_Connection($server, function () use ($body) {
	return array(
		'status' => 200,
		'headers' => array('content-type' => 'text/plain'),
		'body' => $body,
	);
});
$conn->start();

// A window large enough that flow control is not what limits this -- the
// socket is. The flow-control path has its own coverage.
$conn->feed($F::PREFACE . $F::build(
	$F::SETTINGS, 0, 0, pack('nN', $F::SETTINGS_INITIAL_WINDOW_SIZE, 1048576)
));
$conn->feed($F::build($F::WINDOW_UPDATE, 0, 0, pack('N', 1048576)));

$hpack = new Q_WebServer_Http2_Hpack();
$block = $hpack->encode(array(
	array(':method', 'GET'), array(':scheme', 'https'),
	array(':authority', 'example.com'), array(':path', '/big'),
));

// The far end is not reading, so the buffer fills while this is answered.
$conn->feed($F::build(
	$F::HEADERS, $F::FLAG_END_HEADERS | $F::FLAG_END_STREAM, 1, $block
));

$held = strlen($conn->outBuffer);
printf("\n  body %d bytes\n", strlen($body));
printf("  refused by the socket at first write: %d bytes\n", $held);

if ($held === 0) {
	fwrite(STDERR, "\n  INCONCLUSIVE - the socket accepted everything, so the\n");
	fwrite(STDERR, "  short-write path was never taken. Increase the body size.\n\n");
	exit(2);
}

// Drain the far end and let the connection push the rest, which is what the
// event loop's writability watcher does.
$received = '';
$guard = 0;
while ($conn->outBuffer !== '' and ++$guard < 20000) {
	$chunk = @fread($peer, 65536);
	if ($chunk !== false and $chunk !== '') $received .= $chunk;
	$conn->drain();
}
while (($chunk = @fread($peer, 65536)) !== false and $chunk !== '') {
	$received .= $chunk;
}

$data = 0;
$buf = $received;
while (($frame = $F::read($buf)) !== null) {
	if ($frame['type'] === $F::DATA) $data += strlen($frame['payload']);
}

printf("  DATA payload reassembled:            %d of %d\n", $data, strlen($body));

fclose($peer);
fclose($server);

echo "\n";
if ($data === strlen($body)) {
	echo "  PASS - nothing was lost to a full socket\n\n";
	exit(0);
}
printf("  FAIL - %d bytes went missing\n\n", strlen($body) - $data);
exit(1);
