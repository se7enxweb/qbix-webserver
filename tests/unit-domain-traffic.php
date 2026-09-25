#!/usr/bin/env php
<?php
/**
 * Traffic per domain, and finding a domain's lines in the access log.
 *
 *   - Q_WebServer_Traffic counts requests, bytes, status classes and paths
 *     per host (port, case and query ignored), capped in hosts and paths;
 *   - a summary adds up a domain with its aliases and subdomains;
 *   - the access log's default format ends with the Host as one quoted
 *     field, and the named "qbix" format keeps the old shape;
 *   - Log::access() feeds the counters even with no log file open;
 *   - the panel: domains/traffic, and the logs API's host filter matching
 *     a domain's aliases and subdomains, 400 on a bad host;
 *   - a real server writing two hosts' requests to one log, told apart by
 *     the host filter.
 *
 *   php tests/unit-domain-traffic.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Log.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/Traffic.php';
$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the panel store's ownership rule\n"; exit(0); }
$T = 'Q_WebServer_Traffic';

// ── Counting ────────────────────────────────────────────────────────────
$T::reset();
$T::record('Shop.Test:8080', 200, 1000, '/a?x=1');
$T::record('shop.test', 404, 50, '/missing');
$T::record('shop.test', 500, 10, '/a');
$T::record('shop.test', 301, 0, '');
$T::record('', 200, 5, '/nohost');
$s = $T::summary(array('shop.test'));
ck('requests counted, port and case ignored', $s['requests'], 4);
ck('bytes summed', $s['bytes'], 1060);
ck('status classes', $s['classes'], array('2xx' => 1, '3xx' => 1, '4xx' => 1, '5xx' => 1, 'other' => 0));
ck('the query is not part of the path', $s['top'][0], array('path' => '/a', 'count' => 2));
ck('an empty path counts as /', in_array(array('path' => '/', 'count' => 1), $s['top'], true), true);
ck('a request with no Host is not counted', in_array('', $T::hosts(), true), false);
ck('last seen is set', is_int($s['last']) && $s['last'] >= time() - 5, true);
ck('the window start is reported', is_int($s['since']) && $s['since'] <= time(), true);

// A domain with an alias and a subdomain, added up.
$T::record('www.shop.test', 200, 7, '/a');
$T::record('blog.shop.test', 200, 3, '/post');
$s = $T::summary(array('shop.test', 'www.shop.test', 'blog.shop.test'), 2);
ck('a summary adds up every name', array($s['requests'], $s['bytes']), array(6, 1070));
ck('...lists only the busiest paths asked for', count($s['top']), 2);
ck('...and names each host counted', $s['hosts'], array('shop.test' => 4, 'www.shop.test' => 1, 'blog.shop.test' => 1));
ck('an unknown host sums to nothing', $T::summary(array('nobody.test'))['requests'], 0);

// Caps.
$T::reset();
for ($i = 0; $i < $T::HOSTS_MAX + 20; $i++) $T::record("h$i.test", 200, 1, '/');
ck('hosts are capped', count($T::hosts()), $T::HOSTS_MAX);
$T::reset();
for ($i = 0; $i < $T::PATHS_MAX + 50; $i++) $T::record('p.test', 200, 1, "/p$i");
$T::record('p.test', 200, 1, '/p0');
$top = $T::summary(array('p.test'), 1000)['top'];
ck('paths per host are capped', count($top) <= $T::PATHS_MAX, true);
ck('a long path is cut', strlen((function () use ($T) { $T::reset(); $T::record('l.test', 200, 1, '/' . str_repeat('x', 500)); return $T::summary(array('l.test'))['top'][0]['path']; })()), $T::PATH_LEN);

// ── The access log format ───────────────────────────────────────────────
$vhost = Q_WebServer_Log::resolveFormat('');
ck('the default format ends with the Host', substr($vhost, -10), '"%{Host}i"');
ck('the qbix format keeps its old shape', Q_WebServer_Log::resolveFormat('qbix'),
	'%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" %{ms}T');
$line = Q_WebServer_Log::formatAccess($vhost, array('ip' => '1.2.3.4', 'method' => 'GET', 'uri' => '/x', 'status' => 200,
	'size' => 5, 'ms' => 1.5, 'headers' => array('host' => 'shop.test', 'user-agent' => 'ua')));
ck('a line ends with the response time and the host', (bool) preg_match('/ 1\.5ms "shop\.test"$/', $line), true);
$line = Q_WebServer_Log::formatAccess($vhost, array('ip' => '1.2.3.4', 'method' => 'GET', 'uri' => '/x', 'status' => 200,
	'size' => 5, 'ms' => 1, 'headers' => array('host' => "evil\"\ninjected")));
ck('a hostile Host stays inside its field', strpos($line, "\n") === false && (bool) preg_match('/ms "evil\\\\"\\\\x0ainjected"$/', $line), true);

// Log::access counts even with no file open.
$T::reset();
Q_WebServer_Log::access('1.2.3.4', 'GET', '/y?z=1', 200, 42, '', '', 1.0, array('path' => '/y', 'headers' => array('host' => 'count.test')));
ck('Log::access feeds the traffic counters', $T::summary(array('count.test'))['requests'], 1);

// ── The panel ───────────────────────────────────────────────────────────
$base = sys_get_temp_dir() . DS . 'qbix-traffic-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
$acl = "$base/acl"; $sessions = "$base/sessions";
Q_Config::set('Q', 'panel', 'aclDir', $acl);
Q_Config::set('Q', 'panel', 'sessionsDir', $sessions);
Q_Config::set('Q', 'webserver', 'domains', array('shop.test' => array('aliases' => array('www.shop.test'),
	'subdomains' => array('blog' => 'blog'))));
Q_WebServer_Panel_Store::reset();
Q_WebServer_Panel_Store::dirTrusted($acl, true, $why);
Q_WebServer_Panel_Store::dirTrusted($sessions, true, $why);
$parsed = function ($q) { return array('query' => $q, 'path' => '/Q/api/x'); };

$T::reset();
$T::record('shop.test', 200, 10, '/');
$T::record('www.shop.test', 404, 1, '/nope');
$T::record('other.test', 200, 99, '/');
$r = Q_WebServer_Panel::apiDomainTraffic($parsed('domain=shop.test'));
ck('domains/traffic sums the domain and its alias', array($r['requests'], $r['bytes']), array(2, 11));
ck('...names the hosts it covers', in_array('blog.shop.test', $r['names'], true), true);
ck('...and says which window', $r['window'], 'since the server started');
ck('domains/traffic: an unknown domain is 404', Q_WebServer_Panel::apiDomainTraffic($parsed('domain=none.test'))['status'] ?? null, 404);
ck('domains/traffic: a bad name is 400', Q_WebServer_Panel::apiDomainTraffic($parsed('domain=bad%20name'))['status'] ?? null, 400);

$log = "$base/access.log";
file_put_contents($log, implode("\n", array(
	'1.1.1.1 - - [25/Sep/2026:10:00:00 +0000] "GET /a HTTP/1.1" 200 10 "-" "ua" 1.0ms "shop.test"',
	'1.1.1.1 - - [25/Sep/2026:10:00:01 +0000] "GET /b HTTP/1.1" 404 10 "-" "ua" 1.0ms "WWW.Shop.test:8080"',
	'1.1.1.1 - - [25/Sep/2026:10:00:02 +0000] "GET /c HTTP/1.1" 200 10 "-" "ua" 1.0ms "blog.shop.test"',
	'1.1.1.1 - - [25/Sep/2026:10:00:03 +0000] "GET /d HTTP/1.1" 200 10 "-" "ua" 1.0ms "other.test"',
	'1.1.1.1 - - [25/Sep/2026:10:00:04 +0000] "GET /e HTTP/1.1" 200 10 "-" "ua" 1.0ms',
)) . "\n");
Q_WebServer_Log::$accessPath = $log;
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&host=shop.test'));
ck('the host filter keeps the domain, its alias and subdomain', count($r['lines']), 3);
ck('...and leaves out other hosts and lines without a host', (bool) preg_grep('#/d |/e #', $r['lines']), false);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&host=other.test'));
ck('a host that is not a domain matches itself only', count($r['lines']), 1);
$r = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&host=shop.test&status=404'));
ck('the host filter combines with the others', count($r['lines']), 1);
ck('a bad host is refused', Q_WebServer_Panel::apiLogs($parsed('type=access&host=' . rawurlencode('a b')))['status'] ?? null, 400);

// ── A real server: two hosts in one log, told apart ─────────────────────
if (function_exists('pcntl_fork')) {
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('domtraffic');
	file_put_contents($root . DS . 'index.php', '<?php echo "OK";');
	$logDir = $rbase . DS . 'logs';
	@mkdir($logDir, 0755, true);
	$port = rh_start('traffic', array('Q' => array(
		'panel' => array('aclDir' => $acl, 'sessionsDir' => $sessions),
		'webserver' => array('log' => array('dir' => $logDir, 'bufferSize' => 0)),
	)), 2);
	$get = function ($host, $path) use ($port) {
		$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
		if (!$s) return '';
		fwrite($s, "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
		stream_set_timeout($s, 10);
		$out = stream_get_contents($s);
		fclose($s);
		return (string) $out;
	};
	$get('shop.test', '/'); $get('shop.test', '/?two'); $get('www.shop.test', '/');
	$get('other.test', '/'); $get('other.test', '/');  $get('other.test', '/');
	usleep(300000);
	$file = $logDir . DS . 'access.log';
	$lines = is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)), 'strlen')) : array();
	$tagged = count(preg_grep('/ms "[^"]+"$/', $lines));
	ck('the server writes the host on every line', $tagged >= 6 && $tagged === count($lines), true);
	Q_WebServer_Log::$accessPath = $file;
	$shop = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&host=shop.test'));
	$other = Q_WebServer_Panel::apiLogs($parsed('type=access&lines=50&host=other.test'));
	ck("two hosts' lines stay apart (shop.test with its alias, other.test)", array(count($shop['lines']), count($other['lines'])), array(3, 3));
	rh_stop($GLOBALS['rh']['servers']['traffic']);
}

echo ($fail ? "  FAIL - $fail of " . ($pass + $fail) : "  PASS - $pass") . " case(s)\n";
exit($fail ? 1 : 0);
