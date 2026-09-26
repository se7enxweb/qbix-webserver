<?php

/**
 * A coding the client refuses with q=0 is never sent, by the static file path
 * either: not from a precompressed file beside the original, and not from the
 * in-memory copy another client's request left behind.
 *
 * The static path, findPreCompressed() and Precompress::serve() tested
 * Accept-Encoding with a substring search, so "gzip;q=0" -- an explicit no --
 * read as yes. Asserted against a running server, with app.css and app.css.gz
 * side by side:
 *   - "gzip" gets the .gz, marked Content-Encoding: gzip;
 *   - "gzip;q=0" gets the plain file, with no Content-Encoding;
 *   - asked in that order, so the gzip copy is already in memory, the refusal
 *     still gets the plain file;
 *   - "br;q=0, gzip" still gets gzip.
 *
 *   php tests/unit-accept-encoding-q-zero.php
 */

require __DIR__ . '/fixtures/race-harness.php';

list($base, $root) = rh_setup('ae-q0');
$css = str_repeat(".rule-with-a-long-name { color: #123456; margin: 0 auto; }\n", 80);
file_put_contents("$root/app.css", $css);
file_put_contents("$root/app.css.gz", gzencode($css, 9));
touch("$root/app.css", time() - 60);
touch("$root/app.css.gz", time() - 30);

$port = rh_start('q0', array(), 1);

function fetch($port, $ae)
{
	$r = rh_get($port, '/app.css', 15.0, 'GET', '', array('Accept-Encoding' => $ae));
	return array($r['headers']['content-encoding'] ?? '', strlen($r['body'] ?? ''));
}

list($enc, $len) = fetch($port, 'gzip');
check('gzip: the precompressed file, marked gzip', $enc, 'gzip');
list($enc, $len) = fetch($port, 'gzip;q=0');
check('gzip;q=0: no Content-Encoding', $enc, '');
check('...and the plain bytes', $len, strlen($css));
list($enc, $len) = fetch($port, 'gzip;q=0, identity');
check('a refusal after the gzip copy is in memory still gets the plain file', array($enc, $len), array('', strlen($css)));
list($enc, $len) = fetch($port, 'br;q=0, gzip');
check('br refused, gzip offered: gzip', $enc, 'gzip');

rh_finish();
