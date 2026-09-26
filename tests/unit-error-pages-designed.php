<?php

/**
 * Every error page the server writes itself is the designed page, in words a
 * visitor uses, and labelled as HTML.
 *
 * HTTP/2 -- what a browser speaks -- answered a blocked path with the bare
 * word "Forbidden" as nine bytes of text; a worker that died or a request that
 * timed out got "Worker died" or "Request timed out" as plain text; and the
 * route() path sent the designed 403 labelled text/plain, so a browser showed
 * its source. Asserted:
 *   - http2Error() returns the design's page, as text/html, for 403 and 404;
 *   - every status the server sends itself has a plain-words title, 504 included;
 *   - a blocked path over HTTP/1.1 gets the designed page as text/html.
 *
 *   php tests/unit-error-pages-designed.php
 */

require __DIR__ . '/fixtures/race-harness.php';
require_once __DIR__ . '/../src/Q.php';

$W = 'Q_WebServer';
$h2 = new ReflectionMethod($W, 'http2Error');
$h2->setAccessible(true);
foreach (array(403, 404) as $code) {
	$r = $h2->invoke(null, $code, 'Forbidden');
	check("http2Error($code) is text/html", $r['headers']['content-type'] ?? '', 'text/html; charset=utf-8');
	check("http2Error($code) is the designed page, not a bare word", strpos($r['body'] ?? '', '<html') !== false && strlen($r['body']) > 200, true);
	check("http2Error($code) keeps its status", $r['status'] ?? 0, $code);
}
$titles = array(403 => "You can't open this page", 404 => "We couldn't find that page",
	500 => 'Something went wrong on our side', 502 => "This page didn't finish loading",
	503 => "We'll be right back", 504 => 'This page took too long');
foreach ($titles as $code => $title) {
	$page = $W::renderErrorPage($code, '/x');
	check("$code has its plain-words title", strpos(html_entity_decode($page, ENT_QUOTES), $title) !== false, true);
}

list($base, $root) = rh_setup('error-pages');
file_put_contents("$root/index.php", '<?php echo "ok";');
$port = rh_start('errpages', array(), 1);
$r = rh_get($port, '/.git/config');
check('a blocked path over HTTP/1.1 is 403', $r['status'] ?? 0, 403);
check('...as text/html', stripos($r['headers']['content-type'] ?? '', 'text/html') === 0, true);
check('...with the designed page', strpos(html_entity_decode($r['body'] ?? '', ENT_QUOTES), "You can't open this page") !== false, true);
rh_finish();
