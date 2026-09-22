<?php

/**
 * What the response cache treats as the same request, and what it refuses.
 *
 * A cache key is a claim that two requests deserve the same answer. Too broad
 * and one visitor is served another's page; too narrow and the cache is a
 * memory leak that never hits. Both failures are quiet, and both have happened
 * here: the warmer filled entries under a host nobody sent, and the skip list
 * named cookies this application does not use, so a signed-in visitor sailed
 * past the cache while a stale analytics cookie bypassed it entirely.
 *
 *   php tests/unit-cache-key.php
 */

require __DIR__ . '/../src/Q/WebServer/Cache.php';

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

function request($path, array $headers = array(), $query = '', $method = 'GET')
{
	return array(
		'method' => $method,
		'path' => $path,
		'query' => $query,
		'headers' => $headers,
	);
}

$C = 'Q_WebServer_Cache';

// ── What must differ ─────────────────────────────────────────────────────
check('a different path is a different entry',
	$C::cacheKey(request('/a')) === $C::cacheKey(request('/b')), false);

check('a trailing slash is a different entry',
	$C::cacheKey(request('/site')) === $C::cacheKey(request('/site/')), false);

check('a different query is a different entry',
	$C::cacheKey(request('/a', array(), 'p=1')) === $C::cacheKey(request('/a', array(), 'p=2')), false);

// The host is part of the key because one server answers several sites. A
// warmer that sends the wrong Host fills entries nobody will ever read, which
// is exactly what happened here and looked like success from its own side.
check('a different host is a different entry',
	$C::cacheKey(request('/a', array('host' => 'one.example')))
		=== $C::cacheKey(request('/a', array('host' => 'two.example'))), false);

check('a host with a port differs from one without',
	$C::cacheKey(request('/a', array('host' => 'example.com')))
		=== $C::cacheKey(request('/a', array('host' => 'example.com:8080'))), false);

// A gzipped body must never be handed to a client that did not ask for it.
check('an encoding variant is a different entry',
	$C::cacheKey(request('/a', array('accept-encoding' => 'gzip')))
		=== $C::cacheKey(request('/a', array())), false);

// ── What must not differ ─────────────────────────────────────────────────
// Browsers send Accept-Encoding in several spellings. Keying on the literal
// string would give each browser its own entry and each its own cold render.
$variants = array(
	'gzip', 'gzip, deflate', 'gzip, deflate, br', 'gzip, deflate, br, zstd',
	'deflate, gzip',
);
$keys = array();
foreach ($variants as $v) {
	$keys[$v] = $C::cacheKey(request('/a', array('accept-encoding' => $v)));
}
// br is offered by all but the first two, so those two share a key and the
// rest share another; what matters is that there are not five.
check('encoding spellings collapse to a few entries, not one each',
	count(array_unique($keys)) <= 2, true);

check('the same request twice is the same entry',
	$C::cacheKey(request('/a', array('host' => 'x'))),
	$C::cacheKey(request('/a', array('host' => 'x'))));

// ── Cookies that mean "this is personal" ─────────────────────────────────
$C::$skipCookies = array('PHPSESSID');

check('no cookie header is not a skip',
	$C::hasSkipCookie(array()), false);

check('an unrelated cookie is not a skip',
	$C::hasSkipCookie(array('cookie' => '_ga=GA1.1.x; theme=dark')), false);

check('the named cookie is a skip',
	$C::hasSkipCookie(array('cookie' => 'PHPSESSID=abc123')), true);

check('the named cookie is found after others',
	$C::hasSkipCookie(array('cookie' => '_ga=x; PHPSESSID=abc123; theme=dark')), true);

// A name that merely contains the skip name is a different cookie. Matching
// loosely would bypass the cache for everyone carrying it.
check('a cookie whose name merely contains the skip name is not a skip',
	$C::hasSkipCookie(array('cookie' => 'NOTPHPSESSID=abc')), false);

check('a cookie whose value contains the skip name is not a skip',
	$C::hasSkipCookie(array('cookie' => 'ref=PHPSESSID=abc')), false);

// ── Asking for a renewal ─────────────────────────────────────────────────
check('an ordinary request is not a refresh',
	$C::isRefreshRequest(array('host' => 'x')), false);

check('the refresh header is a refresh',
	$C::isRefreshRequest(array('x-cache-refresh' => '1')), true);

// Deliberately not Cache-Control: no-cache, which every browser sends on an
// ordinary reload and which would let any client force a render on demand.
check('a browser reload is not a refresh',
	$C::isRefreshRequest(array('cache-control' => 'no-cache', 'pragma' => 'no-cache')), false);

check('an empty refresh header is not a refresh',
	$C::isRefreshRequest(array('x-cache-refresh' => '')), false);

// ── Cache-Control on the way out ─────────────────────────────────────────
$cc = $C::parseCacheControl(array('cache-control' => 'public, max-age=300'));
check('max-age is read', isset($cc['max-age']) ? (int) $cc['max-age'] : null, 300);

$cc = $C::parseCacheControl(array('cache-control' => 'no-store, private'));
check('no-store is seen', isset($cc['no-store']), true);
check('private is seen', isset($cc['private']), true);

$cc = $C::parseCacheControl(array('cache-control' => 's-maxage=60, max-age=300'));
check('a shared max-age is available', isset($cc['s-maxage']) ? (int) $cc['s-maxage'] : null, 60);

check('no cache-control header parses to nothing',
	$C::parseCacheControl(array()), array());

echo "\n";
if ($fail === 0) { printf("  PASS - %d case(s)\n\n", $pass); exit(0); }
printf("  FAIL - %d of %d\n\n", $fail, $pass + $fail);
exit(1);
