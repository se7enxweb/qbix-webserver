<?php

/**
 * The in-process memory layer in front of APCu (Q.web.cache.memory): off by
 * default, bounded, and never able to answer with something the stores below
 * would not.
 *
 * Asserted (each case in its own PHP process, APCu on unless noted):
 *   - off by default: no hit ever comes from memory;
 *   - on: a stored page is then answered from memory, even with its APCu copy
 *     and its file gone, i.e. without touching either;
 *   - the skip cookies and Authorization still bypass it;
 *   - a generation bump makes a held page a miss;
 *   - an expired page is not answered from memory;
 *   - maxEntries and maxBytes bound it, least recently used out first, and
 *     a body over maxEntrySize is not held;
 *   - a forked worker never answers from its inherited copy;
 *   - purge() or put() in a worker empties the server's memory within a second;
 *   - without APCu at all it still works, in front of the disk.
 *
 *   php tests/unit-cache-memory-layer.php
 */

require __DIR__ . '/fixtures/cache-stubs.php';

$C = 'Q_WebServer_Cache';
$gz = array('accept-encoding' => 'gzip');

/** Remove what the stores below hold for a path, leaving only memory. */
function forget_below($path)
{
	global $C, $gz;
	$key = $C::cacheKey(cache_req($path, $gz));
	if ($C::$apcuEnabled) apcu_delete('qcache:' . $key);
	@unlink($C::filePath($key));
}
function from($path, array $extra = array())
{
	global $C, $gz;
	$before = $C::$hitsFrom;
	$hit = $C::get(cache_req($path, $gz + $extra));
	if ($hit === null) return 'miss';
	foreach ($C::$hitsFrom as $k => $n) if ($n > $before[$k]) return $k;
	return '?';
}

if (isset($argv[1]) and strpos($argv[1], '--child=') === 0) {
	$case = substr($argv[1], 8);
	$out = array();
	$dir = cache_tmpdir('cache-mem');
	$mem = array('maxEntries' => 100);
	switch ($case) {
	case 'default':
		cache_init(array('dir' => $dir));
		$C::put(cache_req('/a', $gz), cache_page());
		$out['first'] = from('/a');
		$out['second'] = from('/a');
		$out['stats'] = $C::stats()['memory'];
		break;
	case 'on':
		cache_init(array('dir' => $dir, 'memory' => $mem, 'skip' => array('cookies' => array('PHPSESSID'))));
		$C::put(cache_req('/a', $gz), cache_page());
		forget_below('/a');
		$out['held'] = from('/a');
		$out['cookie'] = from('/a', array('cookie' => 'PHPSESSID=x'));
		$out['auth'] = from('/a', array('authorization' => 'Bearer x'));
		$out['stats'] = $C::stats()['memory'];
		break;
	case 'generation':
		cache_init(array('dir' => $dir, 'memory' => $mem));
		$C::put(cache_req('/a', $gz), cache_page());
		$out['before'] = from('/a');
		sleep(1);                 // a bump in the same second as the store counts too, but be plain
		$C::bumpGeneration();
		$out['after'] = from('/a');
		break;
	case 'expiry':
		cache_init(array('dir' => $dir, 'memory' => $mem));
		$C::put(cache_req('/a', $gz), cache_page(4000, 1));
		$out['fresh'] = from('/a');
		sleep(2);
		$out['expired'] = from('/a');
		break;
	case 'bounds':
		cache_init(array('dir' => $dir, 'memory' => array('maxEntries' => 2, 'maxBytes' => 1000000, 'maxEntrySize' => 20000)));
		foreach (array('/a', '/b') as $p) $C::put(cache_req($p, $gz), cache_page());
		from('/a');                                  // a most recent; b least
		$C::put(cache_req('/c', $gz), cache_page()); // evicts b
		// Newest and most recent first: asking for b fetches it back into memory.
		$out['a'] = from('/a'); $out['c'] = from('/c'); $out['b'] = from('/b');
		$C::put(cache_req('/big', $gz), cache_page(200000, 300, array('Content-Type' => 'application/octet-stream')));
		$out['big'] = from('/big');                  // over maxEntrySize: not held
		$out['evictions'] = $C::stats()['memory']['evictions'] >= 1;
		cache_init(array('dir' => cache_tmpdir('cache-mem2'), 'memory' => array('maxEntries' => 100, 'maxBytes' => 5000)));
		$C::put(cache_req('/x', $gz), cache_page(3000, 300, array('Content-Type' => 'application/octet-stream')));
		$C::put(cache_req('/y', $gz), cache_page(3000, 300, array('Content-Type' => 'application/octet-stream')));
		$within = $C::stats()['memory']['bytes'] <= 5000;
		$out['bytesBound'] = array(from('/y'), from('/x'), $within);
		break;
	case 'worker':
		cache_init(array('dir' => $dir, 'memory' => $mem));
		$C::put(cache_req('/a', $gz), cache_page());
		$C::put(cache_req('/b', $gz), cache_page());
		from('/a'); from('/b');
		forget_below('/b');       // /b now only in the server's memory
		$pid = pcntl_fork();
		if ($pid === 0) {
			// The worker: its inherited memory must not answer.
			file_put_contents("$dir/worker-b", from('/b'));
			$C::purge('/a');
			exit(0);
		}
		pcntl_waitpid($pid, $st);
		$out['workerB'] = file_get_contents("$dir/worker-b");
		sleep(1);                 // the server reads the epoch at most once a second
		$out['aAfterPurge'] = from('/a');
		$out['bAfterPurge'] = from('/b');  // memory emptied as a whole; /b is gone below too
		// A put in a worker also reaches the server.
		$C::put(cache_req('/c', $gz), cache_page());
		$out['cHeld'] = from('/c');
		$pid = pcntl_fork();
		if ($pid === 0) { $C::put(cache_req('/d', $gz), cache_page()); exit(0); }
		pcntl_waitpid($pid, $st);
		forget_below('/c');
		sleep(1);
		$out['cAfterWorkerPut'] = from('/c');
		break;
	case 'noapcu':
		cache_init(array('dir' => $dir, 'memory' => $mem, 'apcu' => array('enabled' => false)));
		$C::put(cache_req('/a', $gz), cache_page());
		$out['held'] = from('/a');
		@unlink($C::filePath($C::cacheKey(cache_req('/a', $gz))));
		$out['heldWithoutFile'] = from('/a');
		break;
	}
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
$on = array('apc.enable_cli' => 1, 'apc.use_request_time' => 0);

$r = cache_child(__FILE__, 'default', $on);
check('off by default: hits come from APCu, never memory', array($r['first'] ?? null, $r['second'] ?? null), array('apcu', 'apcu'));
check('...and it reports itself off and empty', array($r['stats']['enabled'] ?? null, $r['stats']['entries'] ?? null), array(false, 0));

$r = cache_child(__FILE__, 'on', $on);
check('on: answered from memory with APCu and the file gone', $r['held'] ?? null, 'memory');
check('a skip cookie still bypasses it', $r['cookie'] ?? null, 'miss');
check('Authorization still bypasses it', $r['auth'] ?? null, 'miss');
check('reported: enabled, one entry, its bytes', array($r['stats']['enabled'] ?? null, $r['stats']['entries'] ?? null, ($r['stats']['bytes'] ?? 0) > 0), array(true, 1, true));

$r = cache_child(__FILE__, 'generation', $on);
check('before a generation bump: from memory', $r['before'] ?? null, 'memory');
check('after it: a miss', $r['after'] ?? null, 'miss');

$r = cache_child(__FILE__, 'expiry', $on);
check('fresh: from memory', $r['fresh'] ?? null, 'memory');
check('expired: not from memory (a miss without a stale window)', $r['expired'] ?? null, 'miss');

$r = cache_child(__FILE__, 'bounds', $on);
check('maxEntries 2: the recently used page stays', $r['a'] ?? null, 'memory');
check('...the least recently used is out of memory (APCu answers)', $r['b'] ?? null, 'apcu');
check('...the newest is held', $r['c'] ?? null, 'memory');
check('a body over maxEntrySize is not held (too big for APCu too: disk)', $r['big'] ?? null, 'disk');
check('evictions are counted', $r['evictions'] ?? null, true);
check('maxBytes bounds it: the second of two 3 KB pages is held, the first made room', $r['bytesBound'] ?? null, array('memory', 'apcu', true));

if (function_exists('pcntl_fork')) {
	$r = cache_child(__FILE__, 'worker', $on);
	check('a forked worker never answers from its inherited copy', $r['workerB'] ?? null, 'miss');
	check('purge() in a worker reaches the server within a second', $r['aAfterPurge'] ?? null, 'miss');
	check('...its memory emptied as a whole', $r['bAfterPurge'] ?? null, 'miss');
	check('a page the server stored is held', $r['cHeld'] ?? null, 'memory');
	check('a put() in a worker empties the server\'s memory too', $r['cAfterWorkerPut'] ?? null, 'miss');
} else {
	printf("  skip  pcntl_fork is not available; worker cases not run\n");
}

$r = cache_child(__FILE__, 'noapcu', array('apc.enable_cli' => 0));
check('without APCu: in front of the disk', $r['held'] ?? null, 'memory');
check('...answering with the file gone', $r['heldWithoutFile'] ?? null, 'memory');

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
