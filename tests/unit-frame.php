<?php

/**
 * The HTTP/2 frame codec, at its edges.
 *
 * Framing is the layer every other layer trusts. A frame read wrongly is not a
 * wrong answer -- it is a stream of wrong answers, because the parser stays
 * misaligned for the life of the connection and every subsequent frame is read
 * from the wrong offset. That failure looks like anything at all, which is why
 * the edges are worth pinning down here rather than deducing later.
 *
 *   php tests/unit-frame.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Frame.php';

$F = 'Q_WebServer_Http2_Frame';
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

// ── Round trip ───────────────────────────────────────────────────────────
$raw = $F::build($F::DATA, $F::FLAG_END_STREAM, 7, 'hello');
$buf = $raw;
$frame = $F::read($buf);
check('type survives a round trip', $frame['type'], $F::DATA);
check('flags survive', $frame['flags'], $F::FLAG_END_STREAM);
check('stream id survives', $frame['stream'], 7);
check('payload survives', $frame['payload'], 'hello');
check('the frame is consumed from the buffer', $buf, '');

// ── Partial frames ───────────────────────────────────────────────────────
// A frame arrives in pieces. read() must return null and consume nothing,
// rather than parse a header against a body that has not arrived.
$buf = substr($raw, 0, 5);
check('a partial header parses to nothing', $F::read($buf), null);
check('and is left in the buffer', $buf, substr($raw, 0, 5));

$buf = substr($raw, 0, 11);          // header complete, payload short
check('a header without its payload parses to nothing', $F::read($buf), null);
check('and is also left alone', strlen($buf), 11);

// ── Several frames in one read ───────────────────────────────────────────
$buf = $F::build($F::PING, 0, 0, '12345678')
     . $F::build($F::WINDOW_UPDATE, 0, 3, $F::uint32(1024))
     . 'trailing';
$first = $F::read($buf);
$second = $F::read($buf);
check('the first of several frames is read', $first['type'], $F::PING);
check('the second is read', $second['type'], $F::WINDOW_UPDATE);
check('a partial third is left', $buf, 'trailing');
check('and parses to nothing', $F::read($buf), null);

// ── Zero-length payload ──────────────────────────────────────────────────
$buf = $F::build($F::SETTINGS, $F::FLAG_ACK, 0, '');
$frame = $F::read($buf);
check('an empty payload is a frame, not a failure', $frame['payload'], '');
check('its flags are read', $frame['flags'], $F::FLAG_ACK);

// ── The reserved bit ─────────────────────────────────────────────────────
// The top bit of the stream id is reserved and must be ignored on receipt,
// not treated as part of the number. Treating it as data gives a stream id
// around 2^31 for a frame that means stream 1.
$withReserved = "\x00\x00\x00" . chr($F::PING) . "\x00" . "\x80\x00\x00\x01";
$buf = $withReserved;
$frame = $F::read($buf);
check('the reserved stream-id bit is ignored', $frame['stream'], 1);

// ── Maximum values ───────────────────────────────────────────────────────
$buf = $F::build($F::DATA, 0, 0x7fffffff, 'x');
$frame = $F::read($buf);
check('the largest legal stream id survives', $frame['stream'], 0x7fffffff);

$big = str_repeat('x', 16384);
$buf = $F::build($F::DATA, 0, 1, $big);
$frame = $F::read($buf);
check('a full-size frame payload survives', strlen($frame['payload']), 16384);

// ── Integers ─────────────────────────────────────────────────────────────
check('uint32 is four bytes', strlen($F::uint32(1)), 4);
check('uint31 reads what uint32 wrote', $F::readUint31($F::uint32(123456)), 123456);
check('uint31 masks the reserved bit', $F::readUint31("\xff\xff\xff\xff"), 0x7fffffff);
check('uint31 reads at an offset', $F::readUint31('xx' . $F::uint32(42), 2), 42);

// ── SETTINGS ─────────────────────────────────────────────────────────────
$payload = $F::buildSettings(array(
	$F::SETTINGS_MAX_CONCURRENT_STREAMS => 100,
	$F::SETTINGS_INITIAL_WINDOW_SIZE => 65535,
));
$parsed = $F::parseSettings($payload);
check('settings round trip, concurrency',
	$parsed[$F::SETTINGS_MAX_CONCURRENT_STREAMS], 100);
check('settings round trip, window',
	$parsed[$F::SETTINGS_INITIAL_WINDOW_SIZE], 65535);
check('an empty settings payload is an empty list', $F::parseSettings(''), array());

// A settings payload whose length is not a multiple of six is malformed. It
// must not produce a half-read entry.
$short = $F::buildSettings(array($F::SETTINGS_ENABLE_PUSH => 0));
$truncated = substr($short, 0, 4);
$parsedShort = $F::parseSettings($truncated);
check('a truncated settings entry is not invented', count($parsedShort), 0);

// ── Padding ──────────────────────────────────────────────────────────────
// PADDED means the first byte is a length and that many bytes at the end are
// padding. A padding length larger than the payload is a protocol error and
// must not be read as a negative length or reach past the buffer.
$P = $F::FLAG_PADDED;
check('padding is removed', $F::unpad("\x03" . 'body' . 'pad', $P), 'body');
check('zero padding leaves the body', $F::unpad("\x00" . 'body', $P), 'body');
check('an unpadded payload is returned untouched', $F::unpad('body', 0), 'body');

// A padding length larger than what follows it is a protocol error. Read as a
// negative substr length it would silently truncate the body instead.
check('padding longer than the frame is refused',
	$F::unpad("\xff" . 'body', $P), null);
check('an empty padded payload is refused', $F::unpad('', $P), null);
check('padding exactly the length of the rest leaves nothing',
	$F::unpad("\x04" . 'pad!', $P), '');

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
