<?php

/**
 * The whole of what Q_WebServer_Cache needs from the rest of Q, for tests that
 * drive get() and put() directly -- usually in a child PHP process started with
 * the APCu settings a case needs, since apc.enable_cli is fixed at startup.
 *
 *   require __DIR__ . '/fixtures/cache-stubs.php';
 *   cache_init(array('dir' => $dir, 'apcu' => array('enabled' => true)));
 *   $hit = Q_WebServer_Cache::get(cache_req('/page'));
 */

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

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

require_once __DIR__ . '/../../src/Q/WebServer/Modes.php';
require_once __DIR__ . '/../../src/Q/WebServer/Cache.php';

/** Configure and initialise the cache; enabled unless the config says not. */
function cache_init(array $cache)
{
	Q_Config::$data = array('Q' => array('web' => array('cache' => $cache + array('enabled' => true))));
	Q_WebServer_Cache::init();
}

/** A parsed request, as the server hands it to the cache. */
function cache_req($path, array $headers = array(), $method = 'GET', $query = '')
{
	return array('method' => $method, 'path' => $path, 'query' => $query,
		'headers' => $headers + array('host' => 'example.test'));
}

/** A cacheable response of $bytes bytes of HTML. */
function cache_page($bytes = 4000, $maxAge = 300, array $headers = array())
{
	$body = '';
	for ($i = 0; strlen($body) < $bytes; ++$i) $body .= "<p>line $i of a page that compresses</p>\n";
	return array('status' => 200, 'body' => substr($body, 0, $bytes), 'headers' => $headers + array(
		'Content-Type' => 'text/html; charset=utf-8',
		'Cache-Control' => 'public, max-age=' . $maxAge,
	));
}

/** A fresh directory for one test's entries, under the system temp dir. */
function cache_tmpdir($tag)
{
	$d = sys_get_temp_dir() . DS . 'qbix-' . $tag . '-' . getmypid() . '-' . mt_rand();
	@mkdir($d, 0700, true);
	register_shutdown_function(function () use ($d) {
		if (!is_dir($d)) return;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,
			FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		@rmdir($d);
	});
	return $d;
}

/**
 * Run this test file again as a child with extra -d settings and
 * "--child=<name>"; returns what the child printed as JSON, decoded.
 */
function cache_child($file, $name, array $ini = array())
{
	$cmd = array(PHP_BINARY);
	foreach ($ini as $k => $v) { $cmd[] = '-d'; $cmd[] = "$k=$v"; }
	$cmd[] = $file; $cmd[] = '--child=' . $name;
	$p = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
	$r = json_decode($out, true);
	if (!is_array($r)) printf("        child %s said: %s\n", $name, trim($out . ' ' . $err));
	return $r;
}
