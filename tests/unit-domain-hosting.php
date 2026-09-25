#!/usr/bin/env php
<?php
/**
 * Per-domain hosting settings: redirects, HSTS and error documents.
 *
 *   - redirect(): HTTP -> HTTPS (301, same host, path and query, the HTTPS
 *     port), the preferred host (www or bare) in the same single hop, custom
 *     rules (prefix appends the rest of the path, exact, whole host), 302,
 *     keepQuery, first match wins, nothing without settings;
 *   - redirectsProblem(): no HTTPS listener, a certificate that does not
 *     cover the domain, bad match/code/target, a host rule for a foreign
 *     host, rules that would redirect to themselves;
 *   - HSTS only for HTTPS responses of a domain that has it, max-age and
 *     includeSubDomains, never added twice;
 *   - error documents: contained under the domain's root, traversal and
 *     missing files refused;
 *   - the panel API: 400 with the reason, 409 before HTTPS redirects and
 *     HSTS are turned on;
 *   - real servers, HTTP/1.1 and HTTP/2 with TLS: each redirect kind with its
 *     status and Location, the ACME path never redirected, HSTS present only
 *     over HTTPS for that host, the domain's 404 document served with 404.
 *
 *   php tests/unit-domain-hosting.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/DomainUsage.php';
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
$base = sys_get_temp_dir() . DS . 'qbix-domhost-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
$acl = "$base/acl"; $sessions = "$base/sessions";
Q_Config::set('Q', 'panel', 'aclDir', $acl);
Q_Config::set('Q', 'panel', 'sessionsDir', $sessions);
Q_Config::set('Q', 'webserver', 'domains', array());
Q_WebServer_Panel_Store::reset();
Q_WebServer_Panel_Store::dirTrusted($acl, true, $why);
Q_WebServer_Panel_Store::dirTrusted($sessions, true, $why);

$sites = "$base/sites";
foreach (array('a', 'a/errors', 'e', 'e/errors', 'outside') as $d) @mkdir("$sites/$d", 0755, true);
file_put_contents("$sites/a/index.php", '<?php echo "ROOT-A";');
file_put_contents("$sites/a/hello.txt", 'static-a');
file_put_contents("$sites/e/hello.txt", 'static-e');
file_put_contents("$sites/e/errors/404.html", '<h1>E-NOT-FOUND</h1>');
file_put_contents("$sites/outside/secret.html", 'SECRET');

// The HTTPS port the redirect targets (private; set for the unit part only).
$setPort = function ($p) { $r = new ReflectionProperty('Q_WebServer', 'httpsPort'); $r->setValue(null, $p); };

// ── redirect() ─────────────────────────────────────────────────────────
$req = function ($host, $uri, $https = false) {
	return array('headers' => array('host' => $host), 'uri' => $uri,
		'path' => explode('?', $uri, 2)[0], '_https' => $https);
};
$loc = function ($r) { return $r === null ? null : array($r['status'], $r['headers']['Location']); };
$setPort(8443);
$rec = array('redirects' => array('https' => true));
ck('HTTP -> HTTPS: 301 to the same host, path and query on the HTTPS port',
	$loc($D::redirect($req('a.test', '/p/q?x=1'), 'a.test', 'a.test', $rec)), array(301, 'https://a.test:8443/p/q?x=1'));
ck('...nothing for a request already over HTTPS', $D::redirect($req('a.test', '/p', true), 'a.test', 'a.test', $rec), null);
$setPort(443);
ck('...no port in the target when HTTPS is on 443',
	$loc($D::redirect($req('a.test', '/'), 'a.test', 'a.test', $rec)), array(301, 'https://a.test/'));
$setPort(0);
ck('...and no redirect at all without an HTTPS listener', $D::redirect($req('a.test', '/'), 'a.test', 'a.test', $rec), null);
$setPort(8443);
$rec = array('redirects' => array('https' => true, 'preferredHost' => 'www'));
ck('scheme and preferred host in one hop',
	$loc($D::redirect($req('a.test:80', '/x'), 'a.test', 'a.test', $rec)), array(301, 'https://www.a.test:8443/x'));
$rec = array('redirects' => array('preferredHost' => 'bare'));
ck('preferred bare host, keeping the request\'s port over HTTP',
	$loc($D::redirect($req('www.a.test:8080', '/y?z'), 'www.a.test', 'a.test', $rec)), array(301, 'http://a.test:8080/y?z'));
ck('...nothing when the request is already the preferred form', $D::redirect($req('a.test', '/'), 'a.test', 'a.test', $rec), null);
ck('...nothing for another alias', $D::redirect($req('other.test', '/'), 'other.test', 'a.test', $rec), null);
$rules = array(
	array('match' => 'exact', 'from' => '/old', 'to' => 'https://new.test/landing', 'code' => 302),
	array('match' => 'prefix', 'from' => '/old', 'to' => 'https://new.test/new/', 'code' => 301, 'keepQuery' => true),
	array('match' => 'prefix', 'from' => '/docs', 'to' => 'https://docs.test', 'code' => 301),
	array('match' => 'host', 'from' => 'blog.a.test', 'to' => 'https://blog.test', 'code' => 301),
);
$rec = array('redirects' => array('rules' => $rules));
ck('an exact rule, 302', $loc($D::redirect($req('a.test', '/old'), 'a.test', 'a.test', $rec)), array(302, 'https://new.test/landing'));
ck('a prefix rule appends the rest, keepQuery carries the query',
	$loc($D::redirect($req('a.test', '/old/page?q=1'), 'a.test', 'a.test', $rec)), array(301, 'https://new.test/new/page?q=1'));
ck('...without keepQuery the query is dropped',
	$loc($D::redirect($req('a.test', '/docs/a/b?q=1'), 'a.test', 'a.test', $rec)), array(301, 'https://docs.test/a/b'));
ck('a host rule sends the whole host, path appended',
	$loc($D::redirect($req('blog.a.test', '/2024/x'), 'blog.a.test', 'a.test', $rec)), array(301, 'https://blog.test/2024/x'));
ck('no rule matches: no redirect', $D::redirect($req('a.test', '/other'), 'a.test', 'a.test', $rec), null);
ck('no settings: no redirect', $D::redirect($req('a.test', '/old'), 'a.test', 'a.test', array()), null);
ck('the redirect body escapes its target', strpos($D::redirect($req('a.test', '/old'), 'a.test', 'a.test',
	array('redirects' => array('rules' => array(array('match' => 'exact', 'from' => '/old', 'to' => 'https://x.test/"><b>', 'code' => 301)))))['body'], '"><b>'), false);

// ── redirectsProblem() ─────────────────────────────────────────────────
$prob = function ($r, $names = array('a.test'), $rec = array()) use ($D) { return $D::redirectsProblem('a.test', $rec, $r, $names); };
$setPort(0);
ck('HTTPS redirect refused without an HTTPS listener', (bool) preg_match('/no HTTPS listener/', (string) $prob(array('https' => true))), true);
$setPort(8443);
ck('...refused when the certificate does not cover the domain', (bool) preg_match('/does not cover a\.test/', (string) $prob(array('https' => true), array('other.test'))), true);
ck('...allowed when a wildcard covers it', $prob(array('https' => true), array('*.test')), null);
ck('a bad preferredHost', (bool) preg_match('/preferredHost/', (string) $prob(array('preferredHost' => 'maybe'))), true);
ck('a bad match', (bool) preg_match('/match must be/', (string) $prob(array('rules' => array(array('match' => 'regex', 'from' => '/a', 'to' => 'https://b.test'))))), true);
ck('a bad code', (bool) preg_match('/code must be/', (string) $prob(array('rules' => array(array('from' => '/a', 'to' => 'https://b.test', 'code' => 307))))), true);
ck('a target that is not a URL', (bool) preg_match('/target must be/', (string) $prob(array('rules' => array(array('from' => '/a', 'to' => '/local'))))), true);
ck('a path rule whose from is not a path', (bool) preg_match('/from must be a path/', (string) $prob(array('rules' => array(array('from' => 'a', 'to' => 'https://b.test'))))), true);
ck('a host rule for a foreign host', (bool) preg_match('/not this domain/', (string) $prob(array('rules' => array(array('match' => 'host', 'from' => 'evil.test', 'to' => 'https://b.test'))))), true);
ck('a loop: exact rule to itself', (bool) preg_match('/to itself/', (string) $prob(array('rules' => array(array('match' => 'exact', 'from' => '/a', 'to' => 'https://a.test/a'))))), true);
ck('a loop: prefix rule into its own prefix', (bool) preg_match('/to itself/', (string) $prob(array('rules' => array(array('match' => 'prefix', 'from' => '/a', 'to' => 'https://a.test/a/b'))))), true);
ck('a loop through an alias', (bool) preg_match('/to itself/', (string) $prob(array('rules' => array(array('match' => 'exact', 'from' => '/a', 'to' => 'https://www.a.test/a'))), array('a.test'), array('aliases' => array('www.a.test')))), true);
ck('a loop: host rule to itself', (bool) preg_match('/to itself/', (string) $prob(array('rules' => array(array('match' => 'host', 'from' => 'a.test', 'to' => 'https://a.test/'))))), true);
ck('a good set of rules passes', $prob(array('https' => true, 'preferredHost' => 'www', 'rules' => $rules), array('a.test'), array('subdomains' => array('blog' => 'blog'))), null);

// ── HSTS and error documents (from the store) ──────────────────────────
$D::setRoot('a.test', "$sites/a");
$D::setHsts('a.test', array('enabled' => true, 'maxAge' => 600, 'includeSubDomains' => true));
$D::setRoot('e.test', "$sites/e");
$D::setErrorDocs('e.test', array('404' => 'errors/404.html'));
$D::forget();
ck('HSTS for an HTTPS response of a domain that has it', $D::hstsHeader('a.test', true), 'max-age=600; includeSubDomains');
ck('...never over plain HTTP', $D::hstsHeader('a.test', false), null);
ck('...not for a domain without it', $D::hstsHeader('e.test', true), null);
$h = array('strict-transport-security' => 'max-age=1');
$D::addHsts($h, 'a.test', true);
ck('...never added twice, whatever the case of an existing header', $h, array('strict-transport-security' => 'max-age=1'));
$ra = $real = rtrim(realpath("$sites/e"), DS);
ck('an error document under the root resolves', $D::errorDocPath($ra, 'errors/404.html'), $ra . DS . 'errors' . DS . '404.html');
ck('...traversal is refused', $D::errorDocPath($ra, '../outside/secret.html', $w), null);
ck('...and says why', (bool) preg_match('/stay under/', (string) $w), true);
@symlink("$sites/outside/secret.html", "$sites/e/leak.html");
ck('...a symlink out of the root is refused', $D::errorDocPath($ra, 'leak.html'), null);
ck('...a missing file is refused', $D::errorDocPath($ra, 'errors/500.html'), null);
$D::$currentHost = 'e.test';
ck('the domain\'s 404 document replaces the server page', $D::errorDocument(404), '<h1>E-NOT-FOUND</h1>');
ck('...not for a status it has no document for', $D::errorDocument(500), null);
ck('...not for a status outside the set', $D::errorDocument(418), null);
$D::$currentHost = null;
ck('...nothing outside a request', $D::errorDocument(404), null);

// ── The panel API ──────────────────────────────────────────────────────
$P = 'Q_WebServer_Panel';
$post = function ($body) { return array('body' => json_encode($body)); };
$setPort(0);
ck('API: HTTPS redirect without a listener -> 400', $P::apiDomainRedirects($post(array('domain' => 'a.test', 'https' => true)))['status'] ?? 200, 400);
ck('API: HSTS without a listener -> 400', $P::apiDomainHsts($post(array('domain' => 'e.test', 'enabled' => true)))['status'] ?? 200, 400);
$setPort(8443);
ck('API: a loop -> 400 with the reason', (bool) preg_match('/to itself/', (string) ($P::apiDomainRedirects($post(array('domain' => 'a.test',
	'rules' => array(array('match' => 'exact', 'from' => '/a', 'to' => 'https://a.test/a')))))['error'] ?? '')), true);
ck('API: custom rules save without confirm', $P::apiDomainRedirects($post(array('domain' => 'a.test',
	'rules' => array(array('match' => 'prefix', 'from' => '/old', 'to' => 'https://new.test/')))))['redirects']['rules'][0]['to'] ?? null, 'https://new.test/');
ck('API: HSTS on needs confirm (409)', $P::apiDomainHsts($post(array('domain' => 'e.test', 'enabled' => true)))['status'] ?? 200, 409);
ck('API: ...and saves with it', $P::apiDomainHsts($post(array('domain' => 'e.test', 'enabled' => true, 'maxAge' => 300, 'confirm' => true)))['hsts']['maxAge'] ?? null, 300);
ck('API: a max-age out of range -> 400', $P::apiDomainHsts($post(array('domain' => 'e.test', 'enabled' => true, 'maxAge' => -1, 'confirm' => true)))['status'] ?? 200, 400);
ck('API: HSTS off needs no confirm', array_key_exists('hsts', $P::apiDomainHsts($post(array('domain' => 'e.test', 'enabled' => false)))), true);
ck('API: an error document outside the root -> 400', $P::apiDomainErrorDocs($post(array('domain' => 'e.test', 'docs' => array('404' => '../outside/secret.html'))))['status'] ?? 200, 400);
ck('API: an error document for another status -> 400', $P::apiDomainErrorDocs($post(array('domain' => 'e.test', 'docs' => array('418' => 'errors/404.html'))))['status'] ?? 200, 400);
ck('API: a good one saves', $P::apiDomainErrorDocs($post(array('domain' => 'e.test', 'docs' => array('404' => '/errors/404.html'))))['errorDocs']['404'] ?? null, 'errors/404.html');
$setPort(0);
$D::setRedirects('a.test', null);
$D::setHsts('e.test', null);
$D::forget();

// ── Real servers ───────────────────────────────────────────────────────
function rawReq($port, $host, $path)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return array(0, array(), '');
	stream_set_timeout($s, 10);
	fwrite($s, "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
	$raw = (string) stream_get_contents($s);
	fclose($s);
	$parts = explode("\r\n\r\n", $raw, 2);
	$status = preg_match('#^HTTP/1\.[01] (\d{3})#', $raw, $m) ? (int) $m[1] : 0;
	$headers = array();
	foreach (array_slice(explode("\r\n", $parts[0]), 1) as $line) {
		$c = strpos($line, ':');
		if ($c !== false) $headers[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
	}
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
	return array($status, $headers, trim($body));
}
$h2ok = function_exists('curl_init') && defined('CURL_HTTP_VERSION_2TLS') && (curl_version()['features'] & CURL_VERSION_HTTP2);
if (function_exists('pcntl_fork')) {
	require_once __DIR__ . '/fixtures/race-harness.php';
	list($rbase, $root) = rh_setup('domhost');
	file_put_contents($root . DS . 'index.php', '<?php echo "DEFAULT";');

	$D::setRoot('a.test', "$sites/a");
	$D::setRedirects('a.test', array('https' => true));
	$D::setHsts('a.test', array('enabled' => true, 'maxAge' => 600));
	$D::setRoot('b.test', "$sites/a");
	$D::setAlias('b.test', 'www.b.test', true);
	$D::setRedirects('b.test', array('preferredHost' => 'www', 'rules' => array(
		array('match' => 'prefix', 'from' => '/old', 'to' => 'https://new.test/new/', 'code' => 301, 'keepQuery' => true),
		array('match' => 'exact', 'from' => '/tmp', 'to' => 'https://new.test/t', 'code' => 302),
	)));
	$D::setRoot('e.test', "$sites/e");
	$D::setErrorDocs('e.test', array('404' => 'errors/404.html'));
	$D::setHsts('e.test', array('enabled' => true, 'maxAge' => 900, 'includeSubDomains' => true));
	$D::forget();

	$tls = rh_free_port();
	$GLOBALS['rh_extra_args'] = array('--https-port=' . $tls);
	$port = rh_start('hosting', array('Q' => array(
		'panel' => array('aclDir' => $acl, 'sessionsDir' => $sessions),
		'web' => array('http2' => array('enabled' => true)),
	)), 2);
	$GLOBALS['rh_extra_args'] = array();

	list($st, $hd) = rawReq($port, 'a.test', '/p?x=1');
	ck('HTTP/1.1: HTTP -> HTTPS is a 301 to the HTTPS port', array($st, $hd['location'] ?? null), array(301, "https://a.test:$tls/p?x=1"));
	list($st) = rawReq($port, 'a.test', '/.well-known/acme-challenge/probe');
	ck('HTTP/1.1: the ACME path is never redirected', $st !== 301, true);
	list($st) = rawReq($port, 'a.test', '/Q/health');
	ck('HTTP/1.1: /Q/ is never redirected', $st, 200);
	list($st, $hd) = rawReq($port, "b.test:$port", '/x');
	ck('HTTP/1.1: the preferred host, 301 keeping the port', array($st, $hd['location'] ?? null), array(301, "http://www.b.test:$port/x"));
	list($st, $hd) = rawReq($port, 'www.b.test', '/old/page?q=1');
	ck('HTTP/1.1: a prefix rule with the query kept', array($st, $hd['location'] ?? null), array(301, 'https://new.test/new/page?q=1'));
	list($st, $hd) = rawReq($port, 'www.b.test', '/tmp');
	ck('HTTP/1.1: an exact rule, 302', array($st, $hd['location'] ?? null), array(302, 'https://new.test/t'));
	list($st, $hd, $body) = rawReq($port, 'www.b.test', '/hello.txt');
	ck('HTTP/1.1: no HSTS over plain HTTP', array($st, isset($hd['strict-transport-security'])), array(200, false));
	list($st, $hd, $body) = rawReq($port, 'e.test', '/nope.html');
	ck('HTTP/1.1: the domain\'s 404 document, with 404', array($st, $body), array(404, '<h1>E-NOT-FOUND</h1>'));
	list($st, $hd, $body) = rawReq($port, 'nobody.test', '/nope.html');
	ck('HTTP/1.1: another host never gets that document', strpos($body, 'E-NOT-FOUND'), false);
	list($st, $hd, $body) = rawReq($port, 'e.test', '/hello.txt');
	ck('HTTP/1.1: a domain with error documents still serves its files', array($st, $body), array(200, 'static-e'));

	if ($h2ok) {
		$h2 = function ($host, $path, $version = CURL_HTTP_VERSION_2TLS) use ($tls) {
			$ch = curl_init("https://$host:$tls$path");
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_HTTP_VERSION => $version,
				CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 15,
				CURLOPT_RESOLVE => array("$host:$tls:127.0.0.1"),
			));
			$raw = (string) curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$version = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
			curl_close($ch);
			$headers = array();
			foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) {
				$c = strpos($line, ':');
				if ($c !== false) $headers[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
			}
			return array($status, $headers, trim(substr($raw, $hsize)), $version === CURL_HTTP_VERSION_2_0);
		};
		list($st, $hd, $body, $isH2) = $h2('a.test', '/hello.txt');
		ck('HTTP/2: a.test over HTTPS is not redirected, and is HTTP/2', array($st, $body, $isH2), array(200, 'static-a', true));
		ck('HTTP/2: HSTS is sent over HTTPS for a domain that has it', $hd['strict-transport-security'] ?? null, 'max-age=600');
		list($st, $hd) = $h2('a.test', '/');
		ck('HTTP/2: ...on a script response too', array($st, $hd['strict-transport-security'] ?? null), array(200, 'max-age=600'));
		list($st, $hd) = $h2('www.b.test', '/hello.txt');
		ck('HTTP/2: no HSTS for a domain without it', array($st, isset($hd['strict-transport-security'])), array(200, false));
		list($st, $hd) = $h2('b.test', '/x');
		ck('HTTP/2: the preferred host keeps HTTPS', array($st, $hd['location'] ?? null), array(301, "https://www.b.test:$tls/x"));
		list($st, $hd) = $h2('www.b.test', '/old/page?q=1');
		ck('HTTP/2: a prefix rule', array($st, $hd['location'] ?? null), array(301, 'https://new.test/new/page?q=1'));
		list($st, $hd) = $h2('www.b.test', '/tmp');
		ck('HTTP/2: an exact rule, 302', array($st, $hd['location'] ?? null), array(302, 'https://new.test/t'));
		list($st, $hd, $body) = $h2('e.test', '/nope.html');
		ck('HTTP/2: the domain\'s 404 document, with 404', array($st, $body), array(404, '<h1>E-NOT-FOUND</h1>'));
		ck('HTTP/2: ...and the 404 document carries that domain\'s HSTS', $hd['strict-transport-security'] ?? null, 'max-age=900; includeSubDomains');
		list($st, $hd, $body, $isH2) = $h2('a.test', '/hello.txt', CURL_HTTP_VERSION_1_1);
		ck('HTTP/1.1 over TLS: a static file carries HSTS', array($st, $isH2, $hd['strict-transport-security'] ?? null), array(200, false, 'max-age=600'));
		list($st, $hd, $body) = $h2('e.test', '/nope.html', CURL_HTTP_VERSION_1_1);
		ck('HTTP/1.1 over TLS: the 404 document with 404 and HSTS', array($st, $body, $hd['strict-transport-security'] ?? null), array(404, '<h1>E-NOT-FOUND</h1>', 'max-age=900; includeSubDomains'));
		list($st, $hd) = $h2('www.b.test', '/nope.html', CURL_HTTP_VERSION_1_1);
		ck('HTTP/1.1 over TLS: no HSTS for a domain without it', isset($hd['strict-transport-security']), false);
		list($st) = $h2('a.test', '/.well-known/acme-challenge/probe');
		ck('HTTP/2: the ACME path is not redirected', $st !== 301, true);
	} else {
		printf("  skip  HTTP/2: this PHP's curl has no HTTP/2\n");
	}
	rh_stop($GLOBALS['rh']['servers']['hosting']);
}

printf("%s - %d case(s)%s\n", $fail ? '  FAIL' : '  PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
