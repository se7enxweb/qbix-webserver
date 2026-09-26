#!/usr/bin/env php
<?php
/**
 * The control panel's Cache tab (Q_WebServer_Panel_Cache and the cache/*
 * API), against a real cache in a temporary directory.
 *
 * Asserted:
 *   - cache/settings refuses unknown names, wrong types and values out of
 *     range, each by name, and saves nothing then;
 *   - a good save is kept as one nested "cache" object (shaped like
 *     Q.web.cache), applied at once (Q_WebServer_Cache::init() re-run) and
 *     reported change by change; null goes back to the configuration file's
 *     value; the saved values are laid over the configuration at start
 *     (applySaved), and a bad saved value is ignored rather than applied;
 *   - with the real panel store (as root), the settings land in
 *     acl/panel.json beside the password hash;
 *   - purge by url removes exactly that page (a path that looks like a
 *     regex is still taken literally), purge by pattern every match, and
 *     both say how many; bad input is refused;
 *   - clear starts a new generation, and a page stored before it misses;
 *   - warm refuses anything but a path on this server (full URLs, //host,
 *     /Q/ pages, bad host names), and asks for the page twice (gzip and
 *     plain) with the right host;
 *   - cache/entries is bounded (limit at most 500, scanning stopped at
 *     SCAN_MAX files and said so), newest first, filtered, and never carries
 *     a body;
 *   - changes are POST only; the routes are wired into Q_WebServer_Panel.
 *
 *   php tests/unit-panel-cache-tab.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Modes.php';
require_once __DIR__ . '/../src/Q/WebServer/Cache.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Cache.php';

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}

$base = sys_get_temp_dir() . DS . 'qbix-panel-cache-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
register_shutdown_function(function () use ($base) {
	if (!is_dir($base)) return;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	@rmdir($base);
});

$C = 'Q_WebServer_Cache';
$P = 'Q_WebServer_Panel_Cache';

// The configuration file's values, as the server would have loaded them.
Q_Config::set('Q', 'web', 'cache', array('enabled' => true, 'dir' => "$base/c1",
	'apcu' => array('enabled' => false), 'staleWhileRevalidate' => 10));
$C::init();

// The store, in memory.
$store = null; $writes = 0;
$P::$load = function () use (&$store) { return $store ?? array(); };
$P::$persist = function (array $nested) use (&$store, &$writes) { $store = $nested; ++$writes; return true; };

function req($path, $query = '')
{
	return array('method' => 'GET', 'path' => $path, 'query' => $query, 'headers' => array('host' => 'example.test'));
}
function page($body = 'hello world')
{
	return array('status' => 200, 'body' => $body, 'headers' => array('Content-Type' => 'text/html', 'Cache-Control' => 'public, max-age=300'));
}
function post($route, $body, $method = 'POST')
{
	return Q_WebServer_Panel_Cache::api($route, array('method' => $method, 'body' => is_string($body) ? $body : json_encode($body),
		'headers' => array('host' => 'example.test'), 'query' => ''));
}

// ── Settings: refused ───────────────────────────────────────────────
$bad = array(
	'unknown name' => array(array('bogus' => 1), 'bogus'),
	'negative int' => array(array('defaultTtl' => -1), 'defaultTtl'),
	'int over max' => array(array('staleWhileRevalidate' => 86401), 'staleWhileRevalidate'),
	'fraction' => array(array('negativeTtl' => 1.5), 'negativeTtl'),
	'text for a number' => array(array('defaultTtl' => '5 min'), 'defaultTtl'),
	'text for a switch' => array(array('enabled' => 'yes'), 'enabled'),
	'apcu.maxSize below 1 KB' => array(array('apcu.maxSize' => 10), 'apcu.maxSize'),
	'cookie with a space' => array(array('skip.cookies' => array('ok', 'bad name')), 'skip.cookies'),
	'cookies not a list' => array(array('skip.cookies' => 'PHPSESSID'), 'skip.cookies'),
	'cookies as an object' => array(array('skip.cookies' => array('a' => 'x')), 'skip.cookies'),
	'nested instead of dotted' => array(array('apcu' => array('enabled' => true)), 'apcu'),
);
foreach ($bad as $what => $case) {
	$r = post('cache/settings', $case[0]);
	ck("refused: $what (400)", $r['status'] ?? 200, 400);
	ck("refused: $what names the setting", isset($r['errors'][$case[1]]), true);
}
ck('a good value beside a bad one is not saved either', (post('cache/settings', array('defaultTtl' => 60, 'bogus' => 1))['status'] ?? 200), 400);
ck('nothing to save is refused', (post('cache/settings', array())['status'] ?? 200), 400);
ck('no refused request wrote the store', $writes, 0);
ck('no refused request changed the cache', $C::$defaultTtl, 0);

// ── Settings: saved, applied, kept ──────────────────────────────────
$r = post('cache/settings', array('defaultTtl' => 300, 'skip.cookies' => array('eZSESSID', ' PHPSESSID ', 'eZSESSID'),
	'apcu.maxSize' => '262144', 'memory.maxEntries' => 100, 'minifyHtml' => true));
ck('saved', $r['saved'] ?? null, true);
ck('one write', $writes, 1);
ck('kept nested, like Q.web.cache', $store, array('defaultTtl' => 300, 'skip' => array('cookies' => array('eZSESSID', 'PHPSESSID')),
	'apcu' => array('maxSize' => 262144), 'memory' => array('maxEntries' => 100), 'minifyHtml' => true));
ck('applied: the lifetime', $C::$defaultTtl, 300);
ck('applied: the skip cookies', $C::$skipCookies, array('eZSESSID', 'PHPSESSID'));
ck('applied: the memory layer', $C::$memoryMaxEntries, 100);
ck('applied: minify', $C::$minifyHtml, true);
ck('a change reported with from and to', $r['changes']->defaultTtl ?? null, array('from' => 0, 'to' => 300));
ck('the source is the panel now', $r['sources']['defaultTtl'], 'panel');
ck('an untouched setting keeps its source', $r['sources']['staleWhileRevalidate'], 'config');
ck('a setting nobody set is the default', $r['sources']['negativeTtl'], 'default');

$r = post('cache/settings', array('staleWhileRevalidate' => 60));
ck('stale window set', $C::$staleWhileRevalidate, 60);
$r = post('cache/settings', array('staleWhileRevalidate' => null));
ck('null goes back to the configuration file', $C::$staleWhileRevalidate, 10);
ck('null removes it from the store', isset($store['staleWhileRevalidate']), false);
ck('and its source is the file again', $r['sources']['staleWhileRevalidate'], 'config');
$r = post('cache/settings', array('memory.maxEntries' => null));
ck('null for a key the file never set: the default again', $C::$memoryMaxEntries, 0);
ck('the nested parent goes when empty', isset($store['memory']), false);
$r = post('cache/settings', array('defaultTtl' => 300));
ck('saving the same value says nothing changed', (array) $r['changes'], array());

// At start: laid over the configuration before init().
$P::$original = array();
Q_Config::set('Q', 'web', 'cache', 'defaultTtl', 5);
$store = array('defaultTtl' => 77, 'negativeTtl' => -5, 'bogus' => 1, 'apcu' => array('maxSize' => 4096));
$applied = $P::applySaved();
ck('applySaved lays the good values over', $applied, array('defaultTtl' => 77, 'apcu.maxSize' => 4096));
ck('Q.web.cache.defaultTtl is the panel\'s', Q_Config::get('Q', 'web', 'cache', 'defaultTtl', null), 77);
ck('a bad saved value is not applied', Q_Config::get('Q', 'web', 'cache', 'negativeTtl', 'unset'), 'unset');
$C::init();
ck('init() reads it', $C::$defaultTtl, 77);
$store = null;
post('cache/settings', array('defaultTtl' => null, 'apcu.maxSize' => null));
ck('null after a start goes back to the file\'s value', $C::$defaultTtl, 5);
post('cache/settings', array('defaultTtl' => 300, 'staleWhileRevalidate' => 0));

// ── Purge ───────────────────────────────────────────────────────────
$C::clear();
foreach (array(array('/a', ''), array('/a', 'x=1'), array('/b/', ''), array('/xb/y', ''), array('/c', '')) as $u) {
	$C::put(req($u[0], $u[1]), page("page {$u[0]} {$u[1]}"));
}
$r = post('cache/purge', array('url' => '/b/'));
ck('purge url: removes exactly that page, though it looks like a regex', $r['removed'] ?? null, 1);
ck('the look-alike stays', $C::get(req('/xb/y')) !== null, true);
ck('the purged page misses', $C::get(req('/b/')), null);
$r = post('cache/purge', array('url' => '/a?x=1'));
ck('purge url with its query', $r['removed'] ?? null, 1);
$r = post('cache/purge', array('pattern' => '#^/(a|c)#'));
ck('purge pattern: every match counted', $r['removed'] ?? null, 2);
ck('purge of nothing says 0', post('cache/purge', array('url' => '/none'))['removed'] ?? null, 0);
ck('purge: both url and pattern refused', post('cache/purge', array('url' => '/a', 'pattern' => '#a#'))['status'] ?? 200, 400);
ck('purge: neither refused', post('cache/purge', array())['status'] ?? 200, 400);
ck('purge: a full URL refused', post('cache/purge', array('url' => 'http://example.test/a'))['status'] ?? 200, 400);
ck('purge: a bad regex refused', post('cache/purge', array('pattern' => '#(#'))['status'] ?? 200, 400);
ck('purge: a pattern that is not text refused', post('cache/purge', array('pattern' => array('x')))['status'] ?? 200, 400);

// ── Clear ───────────────────────────────────────────────────────────
$C::put(req('/fresh'), page());
ck('a page is stored', $C::get(req('/fresh')) !== null, true);
@touch($C::$generationFile, time() - 1000);
clearstatcache();
$C::bumpGeneration(); @touch($C::$generationFile, time() - 1000); // an old marker
$before = filemtime($C::$generationFile);
$r = post('cache/clear', array());
ck('clear: cleared', $r['cleared'] ?? null, true);
clearstatcache();
ck('clear: the generation moved on', filemtime($C::$generationFile) > $before, true);
ck('clear: says it is safe and quick', strpos($r['note'] ?? '', 'within a second') !== false, true);
ck('clear: the page stored before it misses', $C::get(req('/fresh')), null);
ck('clear is POST only', post('cache/clear', array(), 'GET')['status'] ?? 200, 405);

// ── Warm ────────────────────────────────────────────────────────────
$calls = array();
$P::$background = false;
$P::$warmer = function ($url, $host, $enc) use (&$calls) { $calls[] = array($url, $host, $enc); return array('status' => 200); };
foreach (array('http://evil.test/x', 'https://example.test/a', '//evil.test/x', 'x', '', '/Q/panel', "/a\nb", '/a b', '\\\\evil') as $u) {
	$r = post('cache/warm', array('url' => $u));
	ck('warm refuses ' . json_encode($u), $r['status'] ?? 200, 400);
}
ck('warm refuses a url that is not text', post('cache/warm', array('url' => array('/a')))['status'] ?? 200, 400);
ck('warm refuses a bad host', post('cache/warm', array('url' => '/a', 'host' => 'evil.test/x'))['status'] ?? 200, 400);
ck('nothing refused was requested', $calls, array());
$r = post('cache/warm', array('url' => '/a?x=1'));
ck('warm: done', $r['warmed'] ?? null, true);
ck('warm: asked twice, compressed and not, on the panel\'s host', $calls, array(array('/a?x=1', 'example.test', 'gzip'), array('/a?x=1', 'example.test', '')));
$w = $P::warmResults();
ck('warm: the result is kept for the tab', array($w[0]['url'] ?? null, $w[0]['results']['gzip']['status'] ?? null), array('/a?x=1', 200));

// ── Entries ─────────────────────────────────────────────────────────
$C::clear();
for ($i = 0; $i < 30; $i++) $C::put(req('/list/' . $i), page(str_repeat('x', 100 + $i)));
$r = $P::entries('', 10);
ck('entries: limited', count($r['entries']), 10);
ck('entries: counted', $r['matched'], 30);
ck('entries: no body anywhere', strpos(json_encode($r), 'xxxx'), false);
ck('entries: no body key', array_filter($r['entries'], function ($e) { return array_key_exists('body', $e); }), array());
$e = $r['entries'][0];
ck('entries: the fields', array_keys($e), array('url', 'status', 'coding', 'size', 'stored', 'age', 'ttl', 'expired', 'cleared', 'heldIn'));
ck('entries: held on disk', in_array('disk', $e['heldIn'], true), true);
ck('entries: plain coding (no Accept-Encoding)', $e['coding'], 'plain');
$sizes = array_column($P::entries('/list/29', 5)['entries'], 'size');
ck('entries: the body size', $sizes, array(129));
$stored = array_column($r['entries'], 'stored');
$sorted = $stored; rsort($sorted);
ck('entries: newest first', $stored, $sorted);
ck('entries: filtered', $P::entries('/list/2', 100)['matched'], 11); // 2, 20..29
ck('entries: the limit is at most 500', $P::entries('', 100000)['limit'], 500);
ck('entries: the limit is at least 1', $P::entries('', -3)['limit'], 1);
ck('entries over the API', count(Q_WebServer_Panel_Cache::api('cache/entries', array('method' => 'GET', 'query' => 'q=/list/1&limit=3'))['entries']), 3);

// Bounded scan: more files than SCAN_MAX.
Q_Config::set('Q', 'web', 'cache', 'dir', "$base/c2");
$C::init();
$n = $P::SCAN_MAX + 25;
for ($i = 0; $i < $n; $i++) {
	$key = md5("k$i");
	$f = $C::filePath($key);
	if (!is_dir(dirname($f))) mkdir(dirname($f), 0700, true);
	file_put_contents($f, json_encode(array('v' => 2, 'url' => "/s/$i", 'key' => $key, 'stored' => time(), 'expires' => time() + 60, 'headers' => array())) . "\nbody");
}
$r = $P::entries('', 500);
ck('entries: scanning stops at SCAN_MAX', $r['scanned'], $P::SCAN_MAX);
ck('entries: and says so', $r['truncated'], true);
ck('entries: at most the limit returned', count($r['entries']), 500);

// ── The API's rules ─────────────────────────────────────────────────
ck('settings is POST only', post('cache/settings', array('defaultTtl' => 1), 'GET')['status'] ?? 200, 405);
ck('purge is POST only', post('cache/purge', array('url' => '/a'), 'GET')['status'] ?? 200, 405);
ck('warm is POST only', post('cache/warm', array('url' => '/a'), 'GET')['status'] ?? 200, 405);
ck('a body that is not an object is refused', post('cache/settings', '"x"')['status'] ?? 200, 400);
$r = Q_WebServer_Panel_Cache::api('cache', array('method' => 'GET'));
ck('GET cache: stats, settings, sources, warnings', array(isset($r['stats']['hitsFrom']), isset($r['settings']['defaultTtl']), isset($r['sources']['enabled']), is_array($r['warnings'])), array(true, true, true, true));
$r = Q_WebServer_Panel::handleApi('/Q/api/cache', array('method' => 'GET', 'query' => ''));
ck('wired into the panel: cache', isset($r['stats']), true);
$r = Q_WebServer_Panel::handleApi('/Q/api/cache/clear', array('method' => 'GET', 'query' => ''));
ck('wired into the panel: a change by GET is refused', $r['status'] ?? 200, 405);
$C::$apcuWarnings = array('APCu is loaded but disabled in this process (apc.enable_cli is off), so cached pages are read from disk.');
$w = $P::warnings();
ck('an APCu warning comes with the fix in plain words', array($w[0]['level'], strpos($w[0]['fix'], 'apc.enable_cli=1') !== false), array('error', true));
$C::$apcuWarnings = array();

// ── The real store (root only: its ownership rule) ──────────────────
if (function_exists('posix_geteuid') and posix_geteuid() === 0) {
	$P::$load = null; $P::$persist = null;
	Q_Config::set('Q', 'panel', 'aclDir', "$base/acl");
	Q_Config::set('Q', 'panel', 'sessionsDir', "$base/sessions");
	Q_WebServer_Panel_Store::reset();
	Q_WebServer_Panel_Store::dirTrusted("$base/acl", true, $why);
	Q_WebServer_Panel_Store::dirTrusted("$base/sessions", true, $why);
	Q_WebServer_Panel_Store::aclSave(array('passwordHash' => 'HASH'));
	$r = post('cache/settings', array('negativeTtl' => 60, 'apcu.enabled' => false));
	ck('store: saved', $r['saved'] ?? null, true);
	$acl = Q_WebServer_Panel_Store::aclLoad();
	ck('store: under "cache", nested', $acl['cache'] ?? null, array('negativeTtl' => 60, 'apcu' => array('enabled' => false)));
	ck('store: the password hash kept', $acl['passwordHash'] ?? null, 'HASH');
	ck('store: read back', $P::saved(), array('negativeTtl' => 60, 'apcu.enabled' => false));
	post('cache/settings', array('negativeTtl' => null, 'apcu.enabled' => null));
	$acl = Q_WebServer_Panel_Store::aclLoad();
	ck('store: the key goes when nothing is saved', array_key_exists('cache', $acl), false);
} else {
	echo "  skip  the real panel store case needs root\n";
}

if ($fail) { printf("FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("PASS - %d case(s)\n", $pass);
