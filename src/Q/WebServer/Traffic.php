<?php
/**
 * @module Q
 */
/**
 * Traffic per host name, counted in memory as each request is logged:
 * requests, bytes sent, status classes, the most requested paths and when
 * the host was last seen. Everything is capped, so a flood of made-up Host
 * headers or paths cannot grow the server without bound.
 *
 * The numbers cover the window since the server started (or since reset()),
 * which summary() reports as `since`, so the panel can say what they mean.
 *
 * @class Q_WebServer_Traffic
 * @static
 */
class Q_WebServer_Traffic
{
	/** Hosts kept; the least recently seen is dropped beyond this. */
	const HOSTS_MAX = 256;
	/** Paths kept per host; beyond this, only the busiest half is kept. */
	const PATHS_MAX = 200;
	/** Longest path counted; longer ones are cut. */
	const PATH_LEN = 200;

	/** @var array host => array(req, bytes, 2xx, 3xx, 4xx, 5xx, other, last, paths) */
	protected static $hosts = array();

	/** @var int when the window began */
	protected static $since = 0;

	/**
	 * Count one response for a host.
	 * @method record
	 * @static
	 * @param {string} $host the Host header, port and case ignored
	 * @param {int} $status
	 * @param {int} $bytes
	 * @param {string} $path without the query
	 */
	static function record($host, $status, $bytes, $path)
	{
		if (!self::$since) self::$since = time();
		$host = self::key($host);
		if ($host === '') return;
		if (!isset(self::$hosts[$host])) {
			if (count(self::$hosts) >= self::HOSTS_MAX) {
				uasort(self::$hosts, function ($a, $b) { return $a['last'] <=> $b['last']; });
				array_shift(self::$hosts);
			}
			self::$hosts[$host] = array('req' => 0, 'bytes' => 0, 'classes' => array('2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0),
				'last' => 0, 'paths' => array());
		}
		$h =& self::$hosts[$host];
		$h['req']++;
		$h['bytes'] += max(0, (int) $bytes);
		$c = (int) $status;
		$class = ($c >= 200 and $c < 600) ? intdiv($c, 100) . 'xx' : 'other';
		if ($class === '1xx') $class = 'other';
		$h['classes'][$class]++;
		$h['last'] = time();
		$path = (string) $path;
		$q = strpos($path, '?');
		if ($q !== false) $path = substr($path, 0, $q);
		if ($path === '') $path = '/';
		$path = substr($path, 0, self::PATH_LEN);
		if (!isset($h['paths'][$path]) and count($h['paths']) >= self::PATHS_MAX) {
			arsort($h['paths']);
			$h['paths'] = array_slice($h['paths'], 0, intdiv(self::PATHS_MAX, 2), true);
		}
		$h['paths'][$path] = ($h['paths'][$path] ?? 0) + 1;
		unset($h);
	}

	/**
	 * The combined traffic of a set of host names (a domain, its aliases and
	 * subdomains).
	 * @method summary
	 * @static
	 * @param {array} $names
	 * @param {int} $top how many paths to list
	 * @return {array} requests, bytes, classes, top (path, count), last, since, hosts
	 */
	static function summary(array $names, $top = 10)
	{
		$out = array('requests' => 0, 'bytes' => 0,
			'classes' => array('2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0),
			'top' => array(), 'last' => null, 'since' => self::$since ?: time(), 'hosts' => array());
		$paths = array();
		foreach (array_unique(array_map(array(__CLASS__, 'key'), $names)) as $n) {
			if ($n === '' or !isset(self::$hosts[$n])) continue;
			$h = self::$hosts[$n];
			$out['requests'] += $h['req'];
			$out['bytes'] += $h['bytes'];
			foreach ($h['classes'] as $k => $v) $out['classes'][$k] += $v;
			if ($out['last'] === null or $h['last'] > $out['last']) $out['last'] = $h['last'];
			foreach ($h['paths'] as $p => $v) $paths[$p] = ($paths[$p] ?? 0) + $v;
			$out['hosts'][$n] = $h['req'];
		}
		arsort($paths);
		foreach (array_slice($paths, 0, max(1, (int) $top), true) as $p => $v) {
			$out['top'][] = array('path' => (string) $p, 'count' => $v);
		}
		return $out;
	}

	/** Host names counted, for tests and diagnostics. */
	static function hosts()
	{
		return array_keys(self::$hosts);
	}

	/** Start a new window. */
	static function reset()
	{
		self::$hosts = array();
		self::$since = time();
	}

	/** A Host header as counted: lower case, no port, no trailing dot. */
	static function key($host)
	{
		if (class_exists('Q_WebServer_Domains')) return Q_WebServer_Domains::normalize((string) $host);
		$host = strtolower(trim((string) $host));
		if ($host !== '' and $host[0] === '[') {
			$end = strpos($host, ']');
			return $end === false ? '' : substr($host, 0, $end + 1);
		}
		$host = preg_replace('/:\d+$/', '', $host);
		return rtrim($host, '.');
	}
}
