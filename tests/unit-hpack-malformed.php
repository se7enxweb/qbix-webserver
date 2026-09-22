<?php

/**
 * HPACK against input that is wrong on purpose.
 *
 * The decoder is the first thing on a connection to touch bytes a peer chose,
 * and it runs before any request exists to authenticate or reject. Whatever it
 * is handed, it has three jobs: do not crash the worker, do not loop forever,
 * and do not read past the buffer. Returning nonsense is acceptable -- the
 * connection is already lost -- but hanging is not, because a hung worker is
 * one fewer worker for everybody else.
 *
 * tests/http2-hpack.php proves the decoder correct against the specification's
 * own vectors. This proves it survives everything else.
 *
 *   php tests/unit-hpack-malformed.php
 */

require __DIR__ . '/../src/Q/WebServer/Http2/Hpack.php';

$pass = 0;
$fail = 0;

/**
 * Decode, and insist it returns rather than dying or hanging.
 */
function survives($what, $bytes)
{
	global $pass, $fail;
	$hpack = new Q_WebServer_Http2_Hpack();
	$began = microtime(true);
	try {
		$hpack->decode($bytes);
	} catch (\Throwable $e) {
		// An exception is a decision. The connection layer catches it and
		// closes the stream, which is a correct outcome for a bad header block.
		$elapsed = microtime(true) - $began;
		if ($elapsed < 1.0) { ++$pass; printf("  PASS  %s (threw, %s)\n", $what, get_class($e)); return; }
		++$fail; printf("  FAIL  %s -- threw, but took %.1fs\n", $what, $elapsed); return;
	}
	$elapsed = microtime(true) - $began;
	if ($elapsed >= 1.0) {
		++$fail;
		printf("  FAIL  %s -- returned, but took %.1fs\n", $what, $elapsed);
		return;
	}
	++$pass;
	printf("  PASS  %s\n", $what);
}

function h($hex) { return hex2bin(preg_replace('/\s+/', '', $hex)); }

echo "\n";

// ── Truncation, at every offset of a valid block ─────────────────────────
// A block cut short is what a CONTINUATION flood looks like when the peer
// gives up, and what any dropped connection produces.
$valid = h('8286 8441 0f77 7777 2e65 7861 6d70 6c65 2e63 6f6d');
for ($i = 1; $i < strlen($valid); ++$i) {
	$hpack = new Q_WebServer_Http2_Hpack();
	try { $hpack->decode(substr($valid, 0, $i)); } catch (\Throwable $e) {}
}
++$pass;
echo "  PASS  every truncation of a valid block is survivable\n";

// ── Indexes that do not exist ────────────────────────────────────────────
survives('an index past the static table', h('ff'));
survives('the forbidden index zero', h('80'));
survives('a huge indexed field', h('ff ff ff ff 7f'));
survives('a name index that does not exist', h('7f ff ff ff 7f 00'));

// ── Lengths that lie ─────────────────────────────────────────────────────
survives('a literal claiming more bytes than exist', h('40 7f ff ff ff 0f 61'));
survives('a huffman string claiming more than exists', h('40 ff 7f 61'));
survives('a zero-length name with a huge value', h('40 00 7f ff ff ff 0f'));

// ── Integer encoding ─────────────────────────────────────────────────────
// A prefixed integer continues while the high bit is set. A run of 0xff bytes
// is the classic way to ask a decoder to loop or to overflow.
survives('an unterminated integer', str_repeat("\xff", 64));
survives('a very long unterminated integer', "\x1f" . str_repeat("\xff", 1024));

// ── Huffman ──────────────────────────────────────────────────────────────
// The end-of-string symbol and padding rules are where hand-entered tables go
// wrong, and 0xff repeated is the padding pattern taken to excess.
survives('huffman padding taken to excess', h('40 01 61 8f') . str_repeat("\xff", 15));
survives('a huffman string of only padding', h('40 01 61 88') . str_repeat("\xff", 8));

// ── Dynamic table instructions ───────────────────────────────────────────
survives('a table size update beyond the maximum', h('3f e1 ff ff 07'));
survives('a table size update to zero, then a reference', h('20 be'));
survives('eviction pressure from repeated insertions',
	str_repeat(h('40 01 61 01 62'), 512));

// ── Shapes ───────────────────────────────────────────────────────────────
survives('an empty block', '');
survives('a single zero byte', "\x00");
survives('every single byte value as a block', implode('', array_map('chr', range(0, 255))));

// ── Random bytes, deterministically seeded ───────────────────────────────
// Not a substitute for the cases above -- random input rarely reaches an
// interesting state -- but it catches what nobody thought to write down.
mt_srand(20260922);
$survivedAll = true;
$slowest = 0;
for ($i = 0; $i < 600; ++$i) {
	$len = mt_rand(1, 256);
	$bytes = '';
	for ($j = 0; $j < $len; ++$j) $bytes .= chr(mt_rand(0, 255));
	$hpack = new Q_WebServer_Http2_Hpack();
	$began = microtime(true);
	try { $hpack->decode($bytes); } catch (\Throwable $e) {}
	$took = microtime(true) - $began;
	if ($took > $slowest) $slowest = $took;
	if ($took >= 1.0) { $survivedAll = false; break; }
}
if ($survivedAll) {
	++$pass;
	printf("  PASS  600 random blocks, slowest %.0f ms\n", $slowest * 1000);
} else {
	++$fail;
	echo "  FAIL  a random block took over a second\n";
}

// ── Still correct afterwards ─────────────────────────────────────────────
// A decoder that survives everything by refusing everything is no use.
$hpack = new Q_WebServer_Http2_Hpack();
$decoded = $hpack->decode(h('8286 8441 8cf1 e3c2 e5f2 3a6b a0ab 90f4 ff'));
$expected = array(
	array(':method', 'GET'), array(':scheme', 'http'),
	array(':path', '/'), array(':authority', 'www.example.com'),
);
if ($decoded === $expected) {
	++$pass;
	echo "  PASS  a valid block still decodes correctly\n";
} else {
	++$fail;
	echo "  FAIL  a valid block no longer decodes correctly\n";
}

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
