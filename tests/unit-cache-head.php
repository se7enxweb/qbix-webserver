<?php

/**
 * A HEAD for a cached page is answered from the cache, with the headers the
 * GET gets and no body, instead of rendering the page to throw it away.
 *
 * The HTTP/1.1 path offered HEAD to the cache, but the cache answered only
 * GET, so every HEAD for a cached page ran PHP. Asserted against a running
 * server with the cache on and a page that counts its renders:
 *   - GET renders once and is stored;
 *   - HEAD is then X-Cache: HIT, renders nothing, carries the GET's
 *     Content-Length and Content-Encoding, and sends no body;
 *   - a HEAD for a page not yet cached renders (no body) and stores nothing,
 *     so the next GET renders too.
 *
 *   php tests/unit-cache-head.php
 */

require __DIR__ . '/fixtures/race-harness.php';

list($base, $root) = rh_setup('cache-head');
$counter = $base . DS . 'renders';
file_put_contents($counter, '0');
file_put_contents($root . DS . 'page.php', '<?php
	$f = ' . var_export($counter, true) . ';
	file_put_contents($f, (string) ((int) file_get_contents($f) + 1));
	header("Cache-Control: public, max-age=300");
	echo str_repeat("<p>a cached page that compresses</p>\n", 100);');

$port = rh_start('head', array('Q' => array('web' => array('cache' => array(
	'enabled' => true, 'dir' => $base . DS . 'cache')))), 1);

/** Raw request; returns [status line + headers (lower-cased names), bytes after the headers]. */
function raw($port, $method, $path)
{
	$s = stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	fwrite($s, "$method $path HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept-Encoding: gzip\r\nConnection: close\r\n\r\n");
	stream_set_timeout($s, 5);
	$raw = '';
	while (!feof($s)) { $c = fread($s, 65536); if ($c === '' or $c === false) { if (stream_get_meta_data($s)['timed_out']) break; continue; } $raw .= $c; }
	fclose($s);
	list($head, $rest) = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
	$h = array();
	foreach (array_slice(explode("\r\n", $head), 1) as $line) {
		$kv = explode(':', $line, 2);
		if (count($kv) === 2) $h[strtolower(trim($kv[0]))] = trim($kv[1]);
	}
	return array($h, $rest);
}
function renders($counter) { clearstatcache(); return (int) file_get_contents($counter); }

list($gh, $gbody) = raw($port, 'GET', '/page.php');
check('GET renders once', renders($counter), 1);
list($gh2, $gbody2) = raw($port, 'GET', '/page.php');
check('a second GET is a hit', $gh2['x-cache'] ?? '', 'HIT');

list($hh, $hbody) = raw($port, 'HEAD', '/page.php');
check('HEAD for a cached page is a hit', $hh['x-cache'] ?? '', 'HIT');
check('...renders nothing', renders($counter), 1);
check('...carries the GET\'s Content-Length', $hh['content-length'] ?? '', $gh2['content-length'] ?? 'missing');
check('...and its Content-Encoding', $hh['content-encoding'] ?? '', $gh2['content-encoding'] ?? '');
check('...and sends no body', strlen($hbody), 0);

list($ch, $cbody) = raw($port, 'HEAD', '/page.php?cold=1');
check('HEAD for an uncached page renders', renders($counter), 2);
check('...and sends no body', strlen($cbody), 0);
raw($port, 'GET', '/page.php?cold=1');
check('...and stores nothing, so the next GET renders', renders($counter), 3);

rh_finish();
