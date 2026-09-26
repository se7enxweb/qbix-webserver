<?php

/**
 * The response cache uses APCu only when APCu can actually hold entries in
 * this process, and says so when it looks as if it could but cannot.
 *
 * function_exists('apcu_fetch') is true whenever the extension is loaded,
 * including under the CLI with apc.enable_cli=0, PHP's default. The cache used
 * to switch APCu on from that alone, and then every store failed and every
 * fetch missed without a word: it ran from disk while its settings said memory.
 *
 * Each case runs in its own PHP process, because apc.enable_cli is fixed when a
 * process starts:
 *   - enable_cli off, apcu.enabled unset: APCu off, and a warning naming the fix;
 *   - enable_cli on: APCu on, nothing said;
 *   - apcu.enabled false: off, and nothing said, whatever APCu can do;
 *   - apc.use_request_time=1: on, with a warning that entries expire early;
 *   - apcu.maxSize not smaller than apc.shm_size: on, with a warning;
 *   - a refused store is counted;
 *   - the warnings are said once per process, not on every init().
 *
 *   php tests/unit-cache-apcu-usable.php
 */

// ── A child: one case, answered as JSON on stdout ───────────────────────
if (isset($argv[1]) and strpos($argv[1], '--case=') === 0) {
	class Q_Config
	{
		static $data = array();
		static function get()
		{
			$args = func_get_args();
			$default = array_pop($args);
			$node = self::$data;
			foreach ($args as $key) {
				if (!is_array($node) or !array_key_exists($key, $node)) return $default;
				$node = $node[$key];
			}
			return $node;
		}
	}
	class Q
	{
		static function ifset($array)
		{
			$args = func_get_args();
			$default = array_pop($args);
			array_shift($args);
			foreach ($args as $key) {
				if (!is_array($array) or !isset($array[$key])) return $default;
				$array = $array[$key];
			}
			return $array;
		}
	}
	require __DIR__ . '/../src/Q/WebServer/Cache.php';
	$cache = json_decode(substr($argv[1], 7), true);
	Q_Config::$data = array('Q' => array('web' => array('cache' => $cache + array('enabled' => true, 'dir' => ''))));

	ob_start();
	Q_WebServer_Cache::init();
	Q_WebServer_Cache::init();        // a second init() must not repeat itself
	ob_end_clean();
	$store = new ReflectionMethod('Q_WebServer_Cache', 'apcuStore');
	$store->setAccessible(true);
	$stored = $store->invoke(null, 'unit-cache-apcu-usable', 'x', 10);
	echo json_encode(array(
		'enabled' => Q_WebServer_Cache::$apcuEnabled,
		'warnings' => Q_WebServer_Cache::$apcuWarnings,
		'stored' => $stored,
		'failures' => Q_WebServer_Cache::$apcuStoreFailures,
	));
	exit(0);
}

// ── The parent ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

if (!extension_loaded('apcu')) {
	printf("  skip  APCu is not loaded in this PHP; nothing to decide\n");
	exit(0);
}

/** Run one case: [result, what it wrote on STDERR]. */
function run_case(array $ini, array $cache)
{
	$cmd = array(PHP_BINARY);
	foreach ($ini as $k => $v) { $cmd[] = '-d'; $cmd[] = "$k=$v"; }
	$cmd[] = __FILE__;
	$cmd[] = '--case=' . json_encode($cache, JSON_FORCE_OBJECT);
	$p = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
	return array(json_decode($out, true), $err);
}

$off = array('apc.enable_cli' => 0);
$on = array('apc.enable_cli' => 1, 'apc.use_request_time' => 0, 'apc.shm_size' => '32M');

list($r, $err) = run_case($off, array());
check('enable_cli off, apcu.enabled unset: APCu is not used', $r['enabled'] ?? null, false);
check('...and one warning says so', count($r['warnings'] ?? array()), 1);
check('...naming the fix', strpos($r['warnings'][0] ?? '', '-d apc.enable_cli=1') !== false, true);
check('...said on STDERR, once for two init() calls', substr_count($err, 'cache: APCu is loaded but disabled'), 1);
check('...and a refused store is counted', array($r['stored'] ?? null, $r['failures'] ?? null), array(false, 1));

list($r, $err) = run_case($on, array());
check('enable_cli on, apcu.enabled unset: APCu is used', $r['enabled'] ?? null, true);
check('...and nothing is said', array($r['warnings'] ?? null, trim($err)), array(array(), ''));
check('...a store succeeds and nothing is counted', array($r['stored'] ?? null, $r['failures'] ?? null), array(true, 0));

list($r, $err) = run_case($on, array('apcu' => array('enabled' => false)));
check('apcu.enabled false: not used even when APCu works', $r['enabled'] ?? null, false);
list($r, $err) = run_case($off, array('apcu' => array('enabled' => false)));
check('apcu.enabled false with enable_cli off: nothing said', array($r['enabled'] ?? null, $r['warnings'] ?? null, trim($err)), array(false, array(), ''));

list($r, $err) = run_case($off, array('apcu' => array('enabled' => true)));
check('apcu.enabled true but unusable: not used, and warned', array($r['enabled'] ?? null, count($r['warnings'] ?? array())), array(false, 1));

list($r, $err) = run_case(array('apc.use_request_time' => 1) + $on, array());
check('apc.use_request_time=1: still used', $r['enabled'] ?? null, true);
check('...with a warning that entries expire early',
	strpos(implode(' ', $r['warnings'] ?? array()), 'apc.use_request_time=0') !== false, true);

list($r, $err) = run_case(array('apc.shm_size' => '1M') + $on, array('apcu' => array('maxSize' => 2097152)));
check('apcu.maxSize >= apc.shm_size: used, with a warning',
	array($r['enabled'] ?? null, strpos(implode(' ', $r['warnings'] ?? array()), 'apc.shm_size (1048576 bytes)') !== false),
	array(true, true));

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
