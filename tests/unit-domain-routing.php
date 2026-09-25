#!/usr/bin/env php
<?php
/**
 * Host routing for the panel's domains: a domain, its aliases and its
 * subdomains are each served from their own document root.
 *
 *   - resolveRoot: domain, alias, subdomain (label and full host), unknown
 *     host falls back (null), port and case ignored;
 *   - roots refused: not a directory, a subdomain root outside the domain's
 *     root, a symlink pointing out of it, a relative root with no domain root;
 *   - the response cache key carries the resolved root, so two domains on
 *     different roots never share an entry;
 *   - the panel API: alias add/remove and conflicts, subdomain and root
 *     validation (400), changing or removing needs confirm (409);
 *   - the standard layout: <base>/<domain>/<docDirName> for new domains
 *     and <base>/<domain>/<sub>/<docDirName> for subdomains, the base and
 *     name settings, created only with create + confirm (0755, owned like
 *     the parent), never over anything that exists;
 *   - a real server: two hosts get two roots, an alias gets its domain's,
 *     a subdomain its own, an unknown host the default root, and /Q/ paths
 *     are never rerouted.
 *
 *   php tests/unit-domain-routing.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/DomainUsage.php';
require_once __DIR__ . '/../src/Q/WebServer/Cache.php';
require_once __DIR__ . '/../src/Q/WebServer.php';

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the panel store's ownership rule\n"; exit(0); }

$D = 'Q_WebServer_Domains';
$base = sys_get_temp_dir() . DS . 'qbix-domroute-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
$acl = "$base/acl"; $sessions = "$base/sessions";
Q_Config::set('Q', 'panel', 'aclDir', $acl);
Q_Config::set('Q', 'panel', 'sessionsDir', $sessions);
Q_Config::set('Q', 'webserver', 'domains', array());
Q_WebServer_Panel_Store::reset();
Q_WebServer_Panel_Store::dirTrusted($acl, true, $why);
Q_WebServer_Panel_Store::dirTrusted($sessions, true, $why);

// Roots on disk.
$sites = "$base/sites";
foreach (array('a', 'a/blog', 'b', 'outside') as $d) @mkdir("$sites/$d", 0755, true);
file_put_contents("$sites/a/index.php", '<?php echo "ROOT-A";');
file_put_contents("$sites/a/blog/index.php", '<?php echo "ROOT-BLOG";');
file_put_contents("$sites/b/index.php", '<?php echo "ROOT-B";');
file_put_contents("$sites/a/hello.txt", 'static-a');
file_put_contents("$sites/b/hello.txt", 'static-b');
@symlink("$sites/outside", "$sites/a/escape");
$real = function ($p) { return rtrim(realpath($p), DS); };

$D::setRoot('a.test', "$sites/a");
$D::setAlias('a.test', 'www.a.test', true);
$D::setSubdomain('a.test', 'blog', 'blog');
$D::setSubdomain('a.test', 'shop.a.test', "$sites/b");       // absolute, outside a's root
$D::setSubdomain('a.test', 'leak', 'escape');                  // symlink out of a's root
$D::setSubdomain('a.test', 'gone', 'missing-dir');
$D::setRoot('b.test', "$sites/b");
$D::setRoot('bad.test', "$sites/not-there");
$D::setSubdomain('bad.test', 'x', 'x');
$D::forget();

// ── resolveRoot ────────────────────────────────────────────────────────
ck('the domain resolves to its root', $D::resolveRoot('a.test'), $real("$sites/a"));
ck('...case and port ignored', $D::resolveRoot('A.TEST:8443'), $real("$sites/a"));
ck('an alias resolves to the domain\'s root', $D::resolveRoot('www.a.test'), $real("$sites/a"));
ck('a subdomain by label, relative to the domain\'s root', $D::resolveRoot('blog.a.test'), $real("$sites/a/blog"));
ck('a subdomain outside the domain\'s root is refused', $D::resolveRoot('shop.a.test'), null);
ck('a subdomain through a symlink out of the root is refused', $D::resolveRoot('leak.a.test'), null);
ck('a subdomain whose root does not exist is refused', $D::resolveRoot('gone.a.test'), null);
ck('another domain resolves to its own root', $D::resolveRoot('b.test'), $real("$sites/b"));
ck('a domain whose root does not exist falls back', $D::resolveRoot('bad.test'), null);
ck('...and a relative subdomain root with no domain root is refused', $D::resolveRoot('x.bad.test'), null);
ck('an unknown host falls back', $D::resolveRoot('nobody.test'), null);
ck('an empty host falls back', $D::resolveRoot(''), null);
ck('subdomainRoot explains why', (function () use ($D, $sites) {
	$D::subdomainRoot('/etc', rtrim(realpath("$sites/a"), DS), $why);
	return $why;
})(), 'outside the domain\'s document root');

// ── The cache key ──────────────────────────────────────────────────────
$key = function ($host) { return Q_WebServer_Cache::cacheKey(array('headers' => array('host' => $host), 'path' => '/', 'query' => '')); };
ck('two domains on different roots have different keys', $key('a.test') !== $key('b.test'), true);
ck('the key changes when a domain\'s root changes', (function () use ($D, $key, $sites) {
	$before = $key('b.test');
	$D::setRoot('b.test', "$sites/a");
	$after = $key('b.test');
	$D::setRoot('b.test', "$sites/b");
	return $before !== $after;
})(), true);
ck('an unknown host keeps the plain key', $key('nobody.test') === Q_WebServer_Cache::cacheKey(array('headers' => array('host' => 'nobody.test'), 'path' => '/', 'query' => '')), true);

// ── The panel API ──────────────────────────────────────────────────────
$post = function ($body) { return array('body' => json_encode($body)); };
$P = 'Q_WebServer_Panel';
ck('an alias that is the domain itself is refused', $P::apiDomainAlias($post(array('domain' => 'a.test', 'add' => 'a.test')))['status'] ?? null, 400);
ck('an alias another domain owns is refused', $P::apiDomainAlias($post(array('domain' => 'b.test', 'add' => 'www.a.test')))['status'] ?? null, 400);
ck('an invalid alias is refused', $P::apiDomainAlias($post(array('domain' => 'b.test', 'add' => 'bad name')))['status'] ?? null, 400);
ck('an alias is added', $P::apiDomainAlias($post(array('domain' => 'b.test', 'add' => 'www.b.test')))['added'] ?? null, 'www.b.test');
ck('...and routes', $D::resolveRoot('www.b.test'), $real("$sites/b"));
ck('...and is removed', $P::apiDomainAlias($post(array('domain' => 'b.test', 'remove' => 'www.b.test')))['removed'] ?? null, 'www.b.test');
ck('a relative root is refused', $P::apiDomainRoot($post(array('domain' => 'b.test', 'root' => 'rel/path')))['status'] ?? null, 400);
ck('a root that is not a directory is refused', $P::apiDomainRoot($post(array('domain' => 'b.test', 'root' => "$sites/b/hello.txt")))['status'] ?? null, 400);
ck('changing an existing root needs confirm', $P::apiDomainRoot($post(array('domain' => 'b.test', 'root' => "$sites/a")))['status'] ?? null, 409);
ck('...and is done with it', $P::apiDomainRoot($post(array('domain' => 'b.test', 'root' => "$sites/a", 'confirm' => true)))['root'] ?? null, "$sites/a");
$P::apiDomainRoot($post(array('domain' => 'b.test', 'root' => "$sites/b", 'confirm' => true)));
ck('a subdomain on a domain with no record is refused', $P::apiDomainSubdomain($post(array('domain' => 'none.test', 'name' => 'x', 'root' => 'x')))['status'] ?? null, 400);
ck('a subdomain root outside the domain is refused', $P::apiDomainSubdomain($post(array('domain' => 'b.test', 'name' => 'y', 'root' => '/etc')))['status'] ?? null, 400);
ck('a subdomain name that is invalid is refused', $P::apiDomainSubdomain($post(array('domain' => 'b.test', 'name' => 'bad name', 'root' => '.')))['status'] ?? null, 400);
ck('a subdomain is added', $P::apiDomainSubdomain($post(array('domain' => 'b.test', 'name' => 'm', 'root' => '.')))['subdomain'] ?? null, 'm.b.test');
ck('changing its root needs confirm', $P::apiDomainSubdomain($post(array('domain' => 'b.test', 'name' => 'm', 'root' => $sites . '/b')))['status'] ?? null, 409);
ck('removing it needs confirm', $P::apiDomainSubdomain($post(array('domain' => 'b.test', 'name' => 'm', 'remove' => true)))['status'] ?? null, 409);
ck('...and is done with it', $P::apiDomainSubdomain($post(array('domain' => 'b.test', 'name' => 'm', 'remove' => true, 'confirm' => true)))['removed'] ?? null, 'm.b.test');
ck('the usage view lists a subdomain as a record source', (function () {
	foreach (Q_WebServer_DomainUsage::collect(array('seen' => array()))['hosts'] as $h) {
		if ($h['host'] === 'blog.a.test') return array($h['record'], $h['sources'][0]['source']);
	}
	return null;
})(), array('a.test', 'record subdomain'));
ck('a bare address is flagged, so it gets no status control', (function () {
	foreach (Q_WebServer_DomainUsage::collect(array('seen' => array('10.0.0.1' => array('count' => 1, 'last' => time()))))['hosts'] as $h) {
		if ($h['host'] === '10.0.0.1') return $h['ip'];
	}
	return null;
})(), true);

// ── The standard layout: <base>/<domain>/<docDirName> ──────────────────
ck('the doc directory name defaults to doc', $D::docDirName(), 'doc');
ck('the base follows the server\'s own <base>/<domain>/... root', (function () use ($D) {
	$saved = Q_WebServer::$rootDir;
	Q_WebServer::$rootDir = '/var/www/vhosts/alpha.example.com/doc/alpha.example.com/';
	$a = $D::baseDir();
	Q_WebServer::$rootDir = '/srv/web/';
	$b = $D::baseDir();
	Q_WebServer::$rootDir = $saved;
	return array($a, $b);
})(), array('/var/www/vhosts', '/var/www/vhosts'));
$vh = "$base/vhosts";
@mkdir($vh, 0755, true);
Q_Config::set('Q', 'domains', 'baseDir', $vh);
ck('Q.domains.baseDir sets the base', $D::defaultRoot('New.Test'), "$vh/new.test/doc");
ck('a subdomain defaults under the domain folder', $D::defaultSubdomainRoot('new.test', 'blog.new.test'), "$vh/new.test/blog/doc");
Q_Config::set('Q', 'domains', 'docDirName', 'public_html');
ck('Q.domains.docDirName changes the name', $D::defaultRoot('new.test'), "$vh/new.test/public_html");
Q_Config::set('Q', 'domains', 'docDirName', '../escape');
ck('...and a name that is not a plain directory name is ignored', $D::docDirName(), 'doc');
Q_Config::set('Q', 'domains', 'docDirName', 'doc');
ck('the defaults API previews the root, not yet existing', (function () use ($P, $post, $vh) {
	$r = $P::apiDomainDefaults($post(array('domain' => 'new.test')));
	return array($r['root'], $r['exists']);
})(), array("$vh/new.test/doc", false));
$r = $P::apiAddDomain($post(array('domain' => 'new.test')));
ck('adding without a root offers to create the default (409, create)', array($r['status'] ?? null, $r['create'] ?? null, $r['root'] ?? null), array(409, true, "$vh/new.test/doc"));
ck('...and creates nothing without confirm', file_exists("$vh/new.test"), false);
ck('...nor with confirm but no create', (function () use ($P, $post, $vh) {
	$P::apiAddDomain($post(array('domain' => 'new.test', 'confirm' => true)));
	return file_exists("$vh/new.test");
})(), false);
chown($vh, 'nobody');
$r = $P::apiAddDomain($post(array('domain' => 'new.test', 'create' => true, 'confirm' => true)));
ck('with create and confirm the domain is added', $r['added'] ?? null, 'new.test');
ck('...its folder exists, 0755', array(is_dir("$vh/new.test/doc"), decoct(fileperms("$vh/new.test/doc") & 0777)), array(true, '755'));
ck('...owned like its parent', array(fileowner("$vh/new.test"), fileowner("$vh/new.test/doc")), array(fileowner($vh), fileowner($vh)));
ck('...and the record has the default root', $D::records()['new.test']['root'] ?? null, "$vh/new.test/doc");
chown($vh, 0);
file_put_contents("$vh/new.test/doc/keep.txt", 'mine');
ck('an existing folder is never touched', (function () use ($D, $vh) {
	return array($D::createDir("$vh/new.test/doc", $why), $why, file_get_contents("$vh/new.test/doc/keep.txt"));
})(), array(false, 'already exists', 'mine'));
$r = $P::apiDomainSubdomain($post(array('domain' => 'new.test', 'name' => 'blog')));
ck('a subdomain without a root offers the standard folder', array($r['status'] ?? null, $r['root'] ?? null), array(409, "$vh/new.test/blog/doc"));
$r = $P::apiDomainSubdomain($post(array('domain' => 'new.test', 'name' => 'blog', 'create' => true, 'confirm' => true)));
ck('...and with create and confirm it is created and routed', array($r['subdomain'] ?? null, $D::resolveRoot('blog.new.test')), array('blog.new.test', rtrim(realpath("$vh/new.test/blog/doc"), DS)));
file_put_contents("$vh/new.test/afile", 'x');
ck('a file in the way of a default path is refused and left alone', (function () use ($P, $post, $vh) {
	$r = $P::apiDomainSubdomain($post(array('domain' => 'new.test', 'name' => 'afile', 'create' => true, 'confirm' => true)));
	return array($r['status'] ?? null, file_get_contents("$vh/new.test/afile"));
})(), array(400, 'x'));

// ── A real server ──────────────────────────────────────────────────────
function rawGet($port, $host, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, '');
	stream_set_timeout($s, 10);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
	$raw = stream_get_contents($s);
	fclose($s);
	$status = preg_match('#^HTTP/1\.[01] (\d{3})#', (string) $raw, $m) ? (int) $m[1] : 0;
	$parts = explode("\r\n\r\n", (string) $raw, 2);
	$body = $parts[1] ?? '';
	if (stripos($parts[0], 'transfer-encoding: chunked') !== false) {
		$out = ''; $rest = $body;
		while (preg_match('/^([0-9a-f]+)\r\n/i', $rest, $cm)) {
			$len = hexdec($cm[1]); if ($len === 0) break;
			$out .= substr($rest, strlen($cm[0]), $len);
			$rest = substr($rest, strlen($cm[0]) + $len + 2);
		}
		$body = $out;
	}
	return array($status, trim($body));
}
if (function_exists('pcntl_fork')) {
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('domroute');
	file_put_contents($root . DS . 'index.php', '<?php echo "DEFAULT";');
	$port = rh_start('route', array('Q' => array('panel' => array('aclDir' => $acl, 'sessionsDir' => $sessions))), 2);
	ck('a.test is served from its root', rawGet($port, 'a.test', '/'), array(200, 'ROOT-A'));
	ck('b.test is served from its own root', rawGet($port, 'b.test', '/'), array(200, 'ROOT-B'));
	ck('...twice, from the cache or not, never crossed', array(rawGet($port, 'a.test', '/')[1], rawGet($port, 'b.test', '/')[1]), array('ROOT-A', 'ROOT-B'));
	ck('an alias is served from its domain\'s root', rawGet($port, 'www.a.test:9999', '/'), array(200, 'ROOT-A'));
	ck('a subdomain from its own root', rawGet($port, 'blog.a.test', '/'), array(200, 'ROOT-BLOG'));
	ck('a static file comes from the domain\'s root', array(rawGet($port, 'a.test', '/hello.txt')[1], rawGet($port, 'b.test', '/hello.txt')[1]), array('static-a', 'static-b'));
	ck('an unknown host gets the default root', rawGet($port, 'nobody.test', '/'), array(200, 'DEFAULT'));
	ck('a refused subdomain root falls back to the default root', rawGet($port, 'leak.a.test', '/'), array(200, 'DEFAULT'));
	list($st) = rawGet($port, 'a.test', '/Q/health');
	ck('/Q/ paths are never rerouted', $st === 200, true);
	rh_stop($GLOBALS['rh']['servers']['route']);

	// The same routing over HTTP/2 with TLS, the way a browser asks: the host
	// name travels in SNI and :authority, not in a Host header, and the
	// response cache is on, so a cached page of one domain must never be
	// served for another.
	$h2ok = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS')
		&& (curl_version()['features'] & CURL_VERSION_HTTP2);
	if ($h2ok) {
		$tls = rh_free_port();
		$GLOBALS['rh_extra_args'] = array('--https-port=' . $tls);
		$port2 = rh_start('route-h2', array('Q' => array(
			'panel' => array('aclDir' => $acl, 'sessionsDir' => $sessions),
			'web' => array('http2' => array('enabled' => true),
				'cache' => array('enabled' => true, 'dir' => $rbase . DS . 'h2cache')),
		)), 2);
		$GLOBALS['rh_extra_args'] = array();
		$h2 = function ($host, $path) use ($tls) {
			$ch = curl_init("https://$host:$tls$path");
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
				CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 15,
				CURLOPT_RESOLVE => array("$host:$tls:127.0.0.1"),
			));
			$body = (string) curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$version = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
			curl_close($ch);
			return array($status, trim($body), $version === CURL_HTTP_VERSION_2_0);
		};
		ck('HTTP/2: a.test is answered over HTTP/2 from its root', $h2('a.test', '/'), array(200, 'ROOT-A', true));
		ck('HTTP/2: b.test from its own root', $h2('b.test', '/'), array(200, 'ROOT-B', true));
		$seen = array();
		for ($i = 0; $i < 4; ++$i) { $seen[] = $h2('a.test', '/')[1]; $seen[] = $h2('b.test', '/')[1]; }
		ck('HTTP/2: repeated with the cache on, never crossed', array_unique($seen), array('ROOT-A', 'ROOT-B'));
		ck('HTTP/2: an alias from its domain\'s root', array_slice($h2('www.a.test', '/'), 0, 2), array(200, 'ROOT-A'));
		ck('HTTP/2: a subdomain from its own root', array_slice($h2('blog.a.test', '/'), 0, 2), array(200, 'ROOT-BLOG'));
		ck('HTTP/2: static files per domain', array($h2('a.test', '/hello.txt')[1], $h2('b.test', '/hello.txt')[1]), array('static-a', 'static-b'));
		ck('HTTP/2: an unknown host gets the default root', array_slice($h2('nobody.test', '/'), 0, 2), array(200, 'DEFAULT'));
		ck('HTTP/2: /Q/ paths are never rerouted', $h2('a.test', '/Q/health')[0], 200);
		rh_stop($GLOBALS['rh']['servers']['route-h2']);
	} else {
		printf("  skip  HTTP/2 routing: this PHP's curl has no HTTP/2\n");
	}
}

printf("%s - %d case(s)%s\n", $fail ? '  FAIL' : '  PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
