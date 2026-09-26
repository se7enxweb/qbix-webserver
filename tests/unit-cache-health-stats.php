<?php

/**
 * The cache's stats -- what /Q/health reports under "cache" -- say where hits
 * came from and what APCu itself holds, not only hits and misses.
 *
 * With APCu silently unusable a cache used to report a healthy hit rate while
 * every hit was a disk read; nothing in /Q/health could tell the two apart.
 * Asserted, with APCu on and off (each in its own process):
 *   - a hit is counted under hitsFrom.apcu, disk or index (a 304 answered off
 *     the validator index), and the three add up to hits;
 *   - with APCu on, apcu carries memory (size, available, usedPercent),
 *     entries and expunges, and storeFailures stays 0;
 *   - with APCu off, apcu.enabled is false, every hit is a disk hit, and the
 *     APCu figures are absent rather than zero;
 *   - the long-standing keys (hits, misses, hitRate) are unchanged.
 *
 *   php tests/unit-cache-health-stats.php
 */

require __DIR__ . '/fixtures/cache-stubs.php';

if (isset($argv[1]) and strpos($argv[1], '--child=') === 0) {
	$dir = cache_tmpdir('cache-health');
	cache_init(array('dir' => $dir));
	$C = 'Q_WebServer_Cache';
	$gz = array('accept-encoding' => 'gzip');
	$C::get(cache_req('/a', $gz));                             // miss
	$C::put(cache_req('/a', $gz), cache_page());               // stored
	$hit = $C::get(cache_req('/a', $gz));                      // apcu (or disk)
	if ($C::$apcuEnabled) apcu_delete('qcache:' . $C::cacheKey(cache_req('/a', $gz)));
	$C::get(cache_req('/a', $gz));                             // disk
	$etag = $hit['headers']['ETag'] ?? '';
	$C::get(cache_req('/a', $gz + array('if-none-match' => $etag)));   // index when APCu
	echo json_encode($C::stats());
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

$on = cache_child(__FILE__, 'on', array('apc.enable_cli' => 1, 'apc.shm_size' => '32M'));
check('APCu on: the old keys are there', array_keys(array_intersect_key($on ?? array(), array_flip(array('hits', 'misses', 'hitRate')))), array('hits', 'misses', 'hitRate'));
check('APCu on: one miss, three hits', array($on['misses'] ?? null, $on['hits'] ?? null), array(1, 3));
check('APCu on: one from APCu, one from disk, one 304 off the index', $on['hitsFrom'] ?? null, array('index' => 1, 'apcu' => 1, 'disk' => 1));
check('APCu on: enabled, nothing refused', array($on['apcu']['enabled'] ?? null, $on['apcu']['storeFailures'] ?? null), array(true, 0));
check('APCu on: the segment size is reported', ($on['apcu']['memory']['size'] ?? 0) > 0, true);
check('APCu on: available is within it', ($on['apcu']['memory']['available'] ?? -1) > 0
	and $on['apcu']['memory']['available'] <= $on['apcu']['memory']['size'], true);
check('APCu on: usedPercent is a percentage', is_numeric($on['apcu']['memory']['usedPercent'] ?? null), true);
check('APCu on: entries counted (page + validators)', ($on['apcu']['entries'] ?? 0) >= 2, true);
check('APCu on: expunges reported', isset($on['apcu']['expunges']), true);

$off = cache_child(__FILE__, 'off', array('apc.enable_cli' => 0));
check('APCu off: every hit is a disk hit', $off['hitsFrom'] ?? null, array('index' => 0, 'apcu' => 0, 'disk' => 3));
check('APCu off: hits add up', ($off['hits'] ?? null), 3);
check('APCu off: reported as not enabled, with the reason', array($off['apcu']['enabled'] ?? null, count($off['apcu']['warnings'] ?? array())), array(false, 1));
check('APCu off: no memory figures pretending to be zero', array_key_exists('memory', $off['apcu'] ?? array()), false);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
