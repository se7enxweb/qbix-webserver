<?php

/**
 * With staleWhileRevalidate set, the APCu copy of a page outlives its expiry
 * by the stale window, so the pages served stale are served from memory.
 *
 * APCu used to keep an entry exactly for its lifetime, and dropped it the
 * moment it expired -- the moment the stale window begins. Every stale hit, on
 * a page busy enough to be served stale at all, was then a disk read.
 * Asserted (APCu on, in a child process):
 *   - a page with max-age=1 and a 30 s stale window, 2 s later, is served
 *     STALE from APCu while one request renders;
 *   - a stale page that had dropped out of APCu is put back, so the next stale
 *     hit is from memory;
 *   - past the window it is a miss, however long APCu would keep it;
 *   - without a stale window the APCu lifetime is the page's own.
 *
 *   php tests/unit-cache-apcu-stale-window.php
 */

require __DIR__ . '/fixtures/cache-stubs.php';

if (isset($argv[1]) and strpos($argv[1], '--child=') === 0) {
	$C = 'Q_WebServer_Cache';
	$gz = array('accept-encoding' => 'gzip');
	$out = array();

	cache_init(array('dir' => cache_tmpdir('cache-swr'), 'staleWhileRevalidate' => 30));
	$C::put(cache_req('/p', $gz), cache_page(4000, 1));
	sleep(2);                                                  // expired, inside the window
	$out['renderer'] = $C::get(cache_req('/p', $gz));          // claims the re-render: null
	$h = $C::get(cache_req('/p', $gz));
	$out['stale'] = $h['headers']['X-Cache'] ?? null;
	$out['fromAfterExpiry'] = $C::$hitsFrom;

	apcu_delete('qcache:' . $C::cacheKey(cache_req('/p', $gz)));   // dropped out of memory
	$C::get(cache_req('/p', $gz));                             // stale, from disk, put back
	$C::get(cache_req('/p', $gz));                             // stale, from memory again
	$out['fromAfterRefill'] = $C::$hitsFrom;

	$e = array('expires' => time() + 10);
	$out['ttlWithWindow'] = $C::apcuTtl($e);
	$C::$staleWhileRevalidate = 0;
	$out['ttlWithoutWindow'] = $C::apcuTtl($e);

	// No window left: the entry is past it, whatever APCu still holds.
	$C::$staleWhileRevalidate = 0;
	$past = $C::get(cache_req('/p', $gz));
	$out['pastWindow'] = $past === null ? null : ($past['headers']['X-Cache'] ?? 'an entry');
	echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
	exit(0);
}

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}
if (!extension_loaded('apcu')) { printf("  skip  APCu is not loaded in this PHP\n"); exit(0); }

$r = cache_child(__FILE__, 'swr', array('apc.enable_cli' => 1, 'apc.use_request_time' => 0));
check('the first request after expiry renders', array_key_exists('renderer', $r ?? array()) ? $r['renderer'] : 'missing', null);
check('the next is answered STALE', $r['stale'] ?? null, 'STALE');
check('...from APCu, not disk', $r['fromAfterExpiry'] ?? null, array('index' => 0, 'memory' => 0, 'apcu' => 1, 'disk' => 0));
check('a stale page that dropped out of APCu is read from disk once, then from memory',
	$r['fromAfterRefill'] ?? null, array('index' => 0, 'memory' => 0, 'apcu' => 2, 'disk' => 1));
check('APCu keeps an entry for its lifetime plus the window', in_array($r['ttlWithWindow'] ?? 0, array(39, 40), true), true);
check('without a window, for its lifetime', in_array($r['ttlWithoutWindow'] ?? 0, array(9, 10), true), true);
check('past the window it is a miss', array_key_exists('pastWindow', $r ?? array()) ? $r['pastWindow'] : 'missing', null);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
