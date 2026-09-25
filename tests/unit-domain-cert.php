#!/usr/bin/env php
<?php
/**
 * A domain's certificate in the panel (Q_WebServer_Certificate_Inspector and
 * the domains/cert API): what the certificate says, which of the domain's
 * names it covers, when it expires, whether this server can issue one, and a
 * certificate job from start to outcome -- with generated certificates and a
 * stand-in for the certificate authority, so nothing is ever requested for real.
 *
 *   php tests/unit-domain-cert.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Domains.php';
require_once __DIR__ . '/../src/Q/WebServer/DomainUsage.php';
require_once __DIR__ . '/../src/Q/WebServer/Certificate/Inspector.php';

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the panel store's ownership rule\n"; exit(0); }

$I = 'Q_WebServer_Certificate_Inspector';
$base = sys_get_temp_dir() . DS . 'qbix-domain-cert-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
Q_Config::set('Q', 'panel', 'aclDir', "$base/acl");
Q_Config::set('Q', 'panel', 'sessionsDir', "$base/sessions");
Q_Config::set('Q', 'webserver', 'domains', array());
Q_WebServer_Panel_Store::reset();
Q_WebServer_Panel_Store::dirTrusted("$base/acl", true, $why);
Q_WebServer_Panel_Store::dirTrusted("$base/sessions", true, $why);

/** A certificate for these names, valid $days, self-signed or signed by $ca = array(cert, key). */
function mkcert($base, $cn, array $names, $days, $ca = null)
{
	static $n = 0;
	$cfg = "$base/openssl-" . (++$n) . '.cnf';
	file_put_contents($cfg, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName="
		. implode(',', array_map(function ($h) { return "DNS:$h"; }, $names)) . "\n");
	$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cfg));
	$csr = openssl_csr_new(array('commonName' => $cn), $key, array('config' => $cfg, 'req_extensions' => 'san', 'digest_alg' => 'sha256'));
	$crt = openssl_csr_sign($csr, $ca ? $ca[0] : null, $ca ? $ca[1] : $key, $days,
		array('config' => $cfg, 'x509_extensions' => 'san', 'digest_alg' => 'sha256'), random_int(1, PHP_INT_MAX));
	openssl_x509_export($crt, $pem);
	$file = "$base/cert-$n.pem";
	file_put_contents($file, $pem);
	return array($file, $crt, $key);
}

// ── Inspecting ─────────────────────────────────────────────────────────
list($self) = mkcert($base, 'live.test', array('live.test', '*.cms.test'), 60);
$c = $I::inspect($self);
ck('names: the CN and every DNS SAN, once', $c['names'], array('live.test', '*.cms.test'));
ck('a self-signed certificate says so', array($c['selfSigned'], $c['issuer']), array(true, 'live.test'));
ck('days left from notAfter', $c['daysLeft'] >= 59 && $c['daysLeft'] <= 60, true);
ck('a sha256 fingerprint', (bool) preg_match('/^[0-9a-f]{2}(:?[0-9a-f]{2}){31}$/', str_replace(':', '', $c['fingerprint']) ? $c['fingerprint'] : ''), true);
ck('PEM text reads the same as the file', $I::inspect(file_get_contents($self))['names'], $c['names']);
ck('an unreadable file gives null', $I::inspect("$base/missing.pem"), null);
ck('text that is no certificate gives null', $I::inspect('-----BEGIN NOTHING-----'), null);
list(, $caCrt, $caKey) = mkcert($base, 'Test CA', array('ca.test'), 3650);
list($signed) = mkcert($base, 'shop.test', array('shop.test'), 60, array($caCrt, $caKey));
ck('a CA-signed certificate is not self-signed, and names its issuer', array($I::inspect($signed)['selfSigned'], $I::inspect($signed)['issuer']), array(false, 'Test CA'));

// ── Covering ───────────────────────────────────────────────────────────
ck('an exact name covers', $I::covers($c['names'], 'live.test'), true);
ck('a wildcard covers one label', $I::covers($c['names'], 'a.cms.test'), true);
ck('...not two labels', $I::covers($c['names'], 'a.b.cms.test'), false);
ck('...nor the bare domain', $I::covers($c['names'], 'cms.test'), false);

// ── A domain's report ──────────────────────────────────────────────────
$manual = array('mode' => 'manual', 'cert' => '/etc/certs/site.pem', 'key' => '/etc/certs/site.key');
$r = $I::forDomain('live.test', array('live.test', 'www.live.test', 'a.cms.test'), $manual, array('certFile' => $self));
ck('covered, per host', $r['covered'], array('live.test' => true, 'www.live.test' => false, 'a.cms.test' => true));
ck('the host it misses is listed', $r['notCovered'], array('www.live.test'));
ck('...and warned about', (bool) preg_grep('/not covered by the certificate: www\.live\.test/', $r['warnings']), true);
ck('a self-signed certificate is warned about', (bool) preg_grep('/self-signed/', $r['warnings']), true);
ck('60 days left is not "expiring"', (bool) preg_grep('/expires in/', $r['warnings']), false);
list($soon) = mkcert($base, 'live.test', array('live.test'), 10, array($caCrt, $caKey));
$r2 = $I::forDomain('live.test', array(), $manual, array('certFile' => $soon));
ck('10 days left is expiring (under 21)', (bool) preg_grep('/expires in 1[0-9] days/', $r2['warnings']), true);
ck('...and a CA-signed one is not warned as self-signed', (bool) preg_grep('/self-signed/', $r2['warnings']), false);
$r3 = $I::forDomain('live.test', array(), $manual, array('certFile' => null));
ck('no certificate served is said plainly', array($r3['served'], (bool) preg_grep('/no certificate is being served/', $r3['warnings'])), array(false, true));

// ── Whether this server can issue ──────────────────────────────────────
$why = '';
ck('manual mode cannot issue', $I::canIssue($manual, $why), false);
ck('...and the reason names the files and the way out', (bool) (strpos($why, '/etc/certs/site.pem') !== false and strpos($why, '"acme"') !== false), true);
ck('the report carries the same answer', array($r['canIssue'], $r['issueWhy'] !== ''), array(false, true));
foreach (array('certbot' => 'certbot', 'remote' => 'downloaded', 'self-signed' => 'self-signed', 'files' => 'certificate files') as $mode => $word) {
	$why = '';
	ck("$mode cannot issue, and says so", array($I::canIssue(array('mode' => $mode), $why), strpos($why, $word) !== false), array(false, true));
}
ck('acme can issue', $I::canIssue(array('mode' => 'acme')), true);
ck('letsencrypt is the same mode', $I::canIssue(array('mode' => 'letsencrypt')), true);

// ── A job, with a stand-in certificate authority ───────────────────────
$acme = array('mode' => 'acme', 'acme' => array('dir' => "$base/acme", 'domains' => array('live.test'), 'email' => 'ops@example.test'));
Q_Config::set('Q', 'web', 'https', $acme);
$cfg = $I::issueConfig($acme, 'shop.test', array('www.shop.test'));
ck('the configured first name stays first, the domain and alias are added', $cfg['acme']['domains'], array('live.test', 'shop.test', 'www.shop.test'));
$wait = function ($file) use ($I) {
	for ($i = 0; $i < 100; $i++) {
		$s = $I::jobStatus($file);
		if ($s['state'] !== 'running' and $s['state'] !== 'queued') return $s;
		usleep(100000);
	}
	return $I::jobStatus($file);
};
$seen = null;
$ok = $I::startIssue('shop.test', array('www.shop.test'), $acme, function ($c) use (&$seen, $base) {
	file_put_contents("$base/issued-for", implode(',', $c['acme']['domains']));
	return array('ok' => true);
}, $why);
ck('a job starts, with a 16-hex id and the names it asks for', array((bool) preg_match('/^[0-9a-f]{16}$/', $ok['job'] ?? ''), $ok['names'] ?? null), array(true, array('live.test', 'shop.test', 'www.shop.test')));
$file = $I::jobFile($ok['job']);
ck('its id finds its state file', is_string($file) && basename($file) === 'state.json', true);
$s = $wait($file);
ck('a job that works ends succeeded', $s['state'], 'succeeded');
ck('...having asked for every name', trim((string) @file_get_contents("$base/issued-for")), 'live.test,shop.test,www.shop.test');
ck('the domain is remembered for renewals', Q_WebServer_Domains::records()['shop.test']['certificate']['acme'] ?? null, true);
ck('...so the configured names grow by it and its alias', Q_WebServer_Acme::domains(array('mode' => 'acme', 'acme' => array('domains' => array('live.test')))), array('live.test', 'shop.test', 'www.shop.test'));
$bad = $I::startIssue('shop.test', array(), $acme, function () { return array('ok' => false, 'error' => 'the CA said no'); }, $why);
$s = $wait($I::jobFile($bad['job']));
ck('a job that fails ends failed, with the reason and a next try', array($s['state'], $s['error'], $s['nextAttempt'] > time()), array('failed', 'the CA said no', true));
ck('an unknown or malformed job id finds nothing', array($I::jobFile('0123456789abcdef'), $I::jobFile('../../etc')), array(null, null));
ck('manual mode will not start a job', array($I::startIssue('shop.test', array(), $manual, function () { return array('ok' => true); }, $why), $why !== ''), array(null, true));

// ── The API ────────────────────────────────────────────────────────────
Q_WebServer_Domains::update('shop.test', function ($r) { $r['aliases'] = array('www.shop.test'); return $r; });
$P = 'Q_WebServer_Panel';
ck('domains/cert: a bad name is 400', $P::apiDomainCert(array('query' => array('domain' => 'bad name')))['status'] ?? null, 400);
ck('domains/cert: an unknown domain is 404', $P::apiDomainCert(array('query' => 'domain=nobody.test'))['status'] ?? null, 404);
$rep = $P::apiDomainCert(array('query' => 'domain=shop.test'));
ck('domains/cert: a known domain reports its hosts and whether it can issue', array(array_keys($rep['covered'] ?? array()), $rep['canIssue'] ?? null), array(array('shop.test', 'www.shop.test'), true));
ck('domains/cert/issue: without confirm is 409, naming what would be asked for',
	array(($x = $P::apiDomainCertIssue(array('body' => json_encode(array('domain' => 'shop.test')))))['status'] ?? null, $x['names'] ?? null),
	array(409, array('live.test', 'shop.test', 'www.shop.test')));
Q_Config::set('Q', 'web', 'https', $manual);
$m = $P::apiDomainCertIssue(array('body' => json_encode(array('domain' => 'shop.test', 'confirm' => true))));
ck('domains/cert/issue: in manual mode is 400 with what to change', array($m['status'] ?? null, strpos($m['error'] ?? '', 'acme') !== false), array(400, true));
ck('domains/cert/issue: not JSON is 400', $P::apiDomainCertIssue(array('body' => 'nope'))['status'] ?? null, 400);
ck('domains/cert/job: an unknown id is 404', $P::apiDomainCertJob(array('query' => 'id=ffffffffffffffff'))['status'] ?? null, 404);
Q_Config::set('Q', 'web', 'https', $acme);
$j = $P::apiDomainCertJob(array('query' => array('id' => $ok['job'])));
ck('domains/cert/job: a known id gives its state, never a path', array($j['state'] ?? null, isset($j['file'])), array('failed', false));

printf("  %s - %d case(s)%s\n", $fail ? 'FAIL' : 'PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
