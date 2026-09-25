#!/usr/bin/env php
<?php
/**
 * The control panel's SSL tab (Q_WebServer_Certificate_Admin and the ssl/*
 * API), with generated certificates and a stand-in for the certificate
 * authority, so nothing is ever requested for real.
 *
 * Asserted:
 *   - ssl/overview names the mode, the served certificate (issuer, names,
 *     validity, days left, fingerprint, key type) and whether issuing is
 *     offered; manual mode says why not;
 *   - ssl/certs lists every certificate once, soonest-expiring first, each
 *     with its state (expired, critical, warning, ok) and every role it has;
 *   - settings are validated (mode, email, directory, renewAt, host names,
 *     what the new mode needs), answer 409 until confirmed, say whether a
 *     restart is needed, are kept in the panel store and applied at once;
 *   - ssl/renew refuses in manual mode, answers 409 naming the hosts, then
 *     runs the job to its outcome;
 *   - the history is bounded, newest first, and keeps no key material;
 *   - no answer ever carries a private key.
 *
 *   php tests/unit-ssl-admin.php
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Certs.php';
require_once __DIR__ . '/../src/Q/WebServer/Certificate/Inspector.php';
require_once __DIR__ . '/../src/Q/WebServer/Certificate/Admin.php';

$pass = 0; $fail = 0;
function ck($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the panel store's ownership rule\n"; exit(0); }

$A = 'Q_WebServer_Certificate_Admin';
$P = 'Q_WebServer_Panel';
$base = sys_get_temp_dir() . DS . 'qbix-ssl-admin-' . getmypid();
@mkdir($base, 0755, true);
chmod($base, 0755);
Q_Config::set('Q', 'panel', 'aclDir', "$base/acl");
Q_Config::set('Q', 'panel', 'sessionsDir', "$base/sessions");
Q_WebServer_Panel_Store::reset();
Q_WebServer_Panel_Store::dirTrusted("$base/acl", true, $why);
Q_WebServer_Panel_Store::dirTrusted("$base/sessions", true, $why);
$A::$historyFile = "$base/state/ssl/history.json";

/** A self-signed certificate and its key file, valid $days (negative: already expired). */
function mkpair($base, $cn, array $names, $days, $rsa = false)
{
	static $n = 0;
	$cfg = "$base/openssl-" . (++$n) . '.cnf';
	file_put_contents($cfg, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName="
		. implode(',', array_map(function ($h) { return "DNS:$h"; }, $names)) . "\n");
	$key = $rsa
		? openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $cfg))
		: openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cfg));
	$csr = openssl_csr_new(array('commonName' => $cn), $key, array('config' => $cfg, 'req_extensions' => 'san', 'digest_alg' => 'sha256'));
	// A lifetime of 0 days ends the moment it starts: expired a second later.
	$crt = openssl_csr_sign($csr, null, $key, max(0, $days), array('config' => $cfg, 'x509_extensions' => 'san', 'digest_alg' => 'sha256'), random_int(1, PHP_INT_MAX));
	openssl_x509_export($crt, $pem);
	openssl_pkey_export($key, $keyPem);
	file_put_contents("$base/cert-$n.pem", $pem);
	file_put_contents("$base/key-$n.pem", $keyPem);
	chmod("$base/key-$n.pem", 0600);
	return array("$base/cert-$n.pem", "$base/key-$n.pem", $keyPem);
}

list($servedCert, $servedKey, $servedKeyPem) = mkpair($base, 'live.test', array('live.test', 'www.live.test'), 60, true);
list($soonCert) = mkpair($base, 'soon.test', array('soon.test'), 5);
list($midCert) = mkpair($base, 'mid.test', array('mid.test'), 15);
list($oldCert) = mkpair($base, 'old.test', array('old.test'), 0);
sleep(1);
// Issued certificates live under the ACME directory, one folder per first name.
@mkdir("$base/acme/soon.test", 0700, true);
@mkdir("$base/acme/mid.test", 0700, true);
@mkdir("$base/acme/old.test", 0700, true);
copy($soonCert, "$base/acme/soon.test/fullchain.pem");
copy($midCert, "$base/acme/mid.test/fullchain.pem");
copy($oldCert, "$base/acme/old.test/fullchain.pem");
// The served one is also in the ACME directory: it must be listed once, with both roles.
@mkdir("$base/acme/live.test", 0700, true);
copy($servedCert, "$base/acme/live.test/fullchain.pem");

$manual = array('mode' => 'manual', 'cert' => $servedCert, 'key' => $servedKey,
	'acme' => array('dir' => "$base/acme"), 'selfSigned' => array('dir' => "$base/selfsigned"));
Q_Config::set('Q', 'web', 'https', $manual);
Q_WebServer_Certs::$certPath = $servedCert;
Q_WebServer_Certs::$keyPath = $servedKey;
Q_WebServer_Certs::$activeCert = $servedCert;
Q_WebServer_Certs::$source = 'configured';

$all = array();   // every answer, searched at the end for key material

// ── Overview ───────────────────────────────────────────────────────────
$o = $all[] = $A::overview();
ck('overview: the mode', $o['mode'], 'manual');
ck('overview: the served certificate, its names and issuer', array($o['served']['names'] ?? null, $o['served']['issuer'] ?? null), array(array('live.test', 'www.live.test'), 'live.test'));
ck('overview: days left, fingerprint and key type', array(($o['served']['daysLeft'] ?? 0) >= 59, strlen($o['served']['fingerprint'] ?? '') > 40, $o['served']['key']['type'] ?? null, $o['served']['key']['bits'] ?? null), array(true, true, 'RSA', 2048));
ck('overview: manual mode offers no issuing, and says why', array($o['canIssue'], $o['issueWhy'] !== ''), array(false, true));
ck('overview: the fallback is named and not in use', array($o['fallback'], $o['usingFallback']), array('self-signed', false));
ck('overview: settings say where each value came from', $o['settings']['mode'], array('value' => 'manual', 'source' => 'config', 'restart' => true));

// ── Expiry list ────────────────────────────────────────────────────────
$c = $all[] = $A::certs();
$first = array_map(function ($x) { return $x['names'][0]; }, $c['certificates']);
ck('certs: every certificate once, soonest-expiring first', $first, array('old.test', 'soon.test', 'mid.test', 'live.test'));
ck('certs: coloured by days left', array_map(function ($x) { return $x['state']; }, $c['certificates']), array('expired', 'critical', 'warning', 'ok'));
ck('certs: a certificate with several roles lists each', $c['certificates'][3]['source'], array('served', 'configured', 'issued (live.test)'));
ck('state: the thresholds', array($A::state(-1), $A::state(0), $A::state(7), $A::state(8), $A::state(21), $A::state(22), $A::state(null)),
	array('expired', 'critical', 'critical', 'warning', 'warning', 'ok', 'unknown'));

// ── Settings ───────────────────────────────────────────────────────────
$A::validateSettings(array('mode' => 'bogus'), $e);
ck('settings: an unknown mode is refused', count($e), 1);
$A::validateSettings(array('email' => 'not-an-address', 'directory' => 'http://plain.example/dir', 'renewAt' => 0.95), $e);
ck('settings: a bad email, a non-https directory and renewAt out of range are each refused', count($e), 3);
$ok = $A::validateSettings(array('email' => 'ops@example.test', 'directory' => 'letsencrypt-staging', 'renewAt' => '0.25',
	'domains' => "Live.test, www.live.test\n*.shop.test live.test"), $e);
ck('settings: good values are cleaned (lower case, no duplicates, numbers)', array($e, $ok['domains'], $ok['renewAt']),
	array(array(), array('live.test', 'www.live.test', '*.shop.test'), 0.25));
$A::validateSettings(array('domains' => 'good.test, bad_name, -x.test'), $e);
ck('settings: host names a certificate cannot carry are refused one by one', count($e), 2);
Q_Config::set('Q', 'web', 'https', array('mode' => 'acme', 'acme' => array('dir' => "$base/acme")));
$A::validateSettings(array('mode' => 'manual'), $e);
ck('settings: manual mode needs a usable certificate and key first', count($e), 1);
$A::validateSettings(array('mode' => 'acme', 'domains' => ''), $e);
ck('settings: acme mode needs a host name', count($e), 1);
Q_Config::set('Q', 'web', 'https', array('mode' => 'manual', 'cert' => $servedCert, 'key' => $soonCert . '.nokey', 'acme' => array('dir' => "$base/acme")));
$A::validateSettings(array('mode' => 'files'), $e);
ck('settings: a missing key file is refused', count($e), 1);
Q_Config::set('Q', 'web', 'https', $manual);

$r = $all[] = $P::apiSsl('ssl/settings', array('method' => 'POST', 'body' => json_encode(array('email' => 'ops@example.test', 'renewAt' => 0.25))));
ck('ssl/settings: without confirm is 409, listing the changes and no restart', array($r['status'] ?? null, array_keys($r['changes'] ?? array()), $r['restart'] ?? null), array(409, array('email', 'renewAt'), false));
ck('...and nothing is kept yet', $A::overrides(), array());
$r = $all[] = $P::apiSsl('ssl/settings', array('method' => 'POST', 'body' => json_encode(array('email' => 'ops@example.test', 'renewAt' => 0.25, 'confirm' => true))));
ck('ssl/settings: confirmed, it is saved, applied at once, and needs no restart', array($r['ok'] ?? null, $r['restart'] ?? null, Q_Config::get('Q', 'web', 'https', 'acme', 'email', null)), array(true, false, 'ops@example.test'));
ck('...kept in the panel store', $A::overrides(), array('email' => 'ops@example.test', 'renewAt' => 0.25));
ck('...and shown as coming from the panel', $A::settings()['email']['source'], 'panel');
$r = $P::apiSsl('ssl/settings', array('method' => 'POST', 'body' => json_encode(array('mode' => 'acme', 'domains' => 'live.test'))));
ck('ssl/settings: a mode change needs a restart, and says so before confirming', array($r['status'] ?? null, $r['restart'] ?? null), array(409, true));
ck('ssl/settings: invalid is 400 before any confirm', $P::apiSsl('ssl/settings', array('method' => 'POST', 'body' => json_encode(array('renewAt' => 5))))['status'] ?? null, 400);
ck('ssl/settings: not JSON is 400', $P::apiSsl('ssl/settings', array('method' => 'POST', 'body' => 'nope'))['status'] ?? null, 400);
ck('ssl/settings: GET is 405', $P::apiSsl('ssl/settings', array('method' => 'GET'))['status'] ?? null, 405);
Q_Config::set('Q', 'web', 'https', 'acme', 'email', null);
$A::applyOverrides();
ck('applyOverrides lays the stored settings over the configuration, as at start-up', Q_Config::get('Q', 'web', 'https', 'acme', 'email', null), 'ops@example.test');

// ── Renew ──────────────────────────────────────────────────────────────
$r = $all[] = $P::apiSsl('ssl/renew', array('method' => 'POST', 'body' => json_encode(array('confirm' => true))));
ck('ssl/renew: manual mode is 400 with the reason, nothing issued', array($r['status'] ?? null, ($r['error'] ?? '') !== ''), array(400, true));
$acme = array('mode' => 'acme', 'acme' => array('dir' => "$base/acme", 'domains' => array('new.test', 'www.new.test'), 'email' => 'ops@example.test'));
Q_Config::set('Q', 'web', 'https', $acme);
$r = $all[] = $P::apiSsl('ssl/renew', array('method' => 'POST', 'body' => '{}'));
ck('ssl/renew: without confirm is 409, naming the hosts', array($r['status'] ?? null, $r['names'] ?? null), array(409, array('new.test', 'www.new.test')));
$A::$issuer = function ($cfg) use ($base) { file_put_contents("$base/issued-for", implode(',', Q_WebServer_Acme::domains($cfg))); return array('ok' => true); };
$r = $all[] = $P::apiSsl('ssl/renew', array('method' => 'POST', 'body' => json_encode(array('confirm' => true))));
ck('ssl/renew: confirmed, it starts a job and gives its id', array($r['status'] ?? null, strlen($r['job'] ?? '')), array(202, 16));
$state = "$base/acme/new.test/state.json";
for ($i = 0; $i < 100; ++$i) { $s = Q_WebServer_Certificate_Inspector::jobStatus($state); if ($s['state'] !== 'running' and $s['state'] !== 'queued') break; usleep(50000); }
ck('...the job ends succeeded, having asked for every host', array($s['state'], trim((string) @file_get_contents("$base/issued-for"))), array('succeeded', 'new.test,www.new.test'));
$h = $all[] = $A::history();
ck('ssl/history: the job is listed with its state', array_values(array_filter(array_map(function ($j) { return $j['name'] === 'new.test' ? $j['state'] : null; }, $h['jobs']))), array('succeeded'));

// ── History ────────────────────────────────────────────────────────────
$A::record('failed', array('name' => 'x', 'error' => 'the CA said no', 'key' => $servedKeyPem, 'privateKey' => 'secret',
	'pem' => $servedKeyPem, 'note' => $servedKeyPem, 'hosts' => array('a.test', 'b.test')));
$h = $all[] = $A::history();
ck('history: newest first, with the reason and hosts, no key fields', $h['entries'][0], array('time' => $h['entries'][0]['time'], 'event' => 'failed',
	'info' => array('name' => 'x', 'error' => 'the CA said no', 'hosts' => array('a.test', 'b.test'))));
$A::record('loaded', array('cert' => $servedCert, 'certificate' => array('cert' => 'x', 'key' => $servedKeyPem), 'names' => array($servedKeyPem, 'a.test')));
ck('history: a nested record is left out and PEM text is never kept', $A::history(1)['entries'][0]['info'], array('cert' => $servedCert, 'names' => array('a.test')));
$A::record('error', array('reason' => 'same problem')); $A::record('error', array('reason' => 'same problem')); $A::record('error', array('reason' => 'same problem'));
$e = $A::history(1)['entries'][0];
ck('history: the same event again counts on the last entry', array($e['event'], $e['count'] ?? null), array('error', 3));
for ($i = 0; $i < $A::HISTORY_MAX + 30; ++$i) $A::record('loaded', array('n' => $i));
$h = $A::history(1000);
ck('history: bounded to HISTORY_MAX, the oldest dropped', array($h['total'], $h['entries'][0]['info']['n'], count($h['entries'])), array($A::HISTORY_MAX, $A::HISTORY_MAX + 29, $A::HISTORY_MAX));
ck('history: the file is private', sprintf('%o', fileperms($A::$historyFile) & 0777), '600');
(new Q_WebServer_Certificate_History())->update('trying', array('provider' => 'x'));
(new Q_WebServer_Certificate_History())->update('stored', array('file' => 'x.pem'));
$h = $A::history(2);
ck('the observer keeps outcomes, not each provider tried', array_map(function ($e) { return $e['event']; }, $h['entries']), array('stored', 'loaded'));

// ── The watcher, seen from any process ─────────────────────────────────
Q_WebServer_Certs::$lastCheck = null;
$A::mark('last-check');
ck('overview: the watcher\'s last check is read from the state directory too', abs(($A::overview()['watch']['lastCheck'] ?? 0) - time()) <= 2, true);

// ── No private key anywhere ────────────────────────────────────────────
Q_Config::set('Q', 'web', 'https', $manual);
$all[] = $A::overview(); $all[] = $A::certs(); $all[] = $A::history(1000);
$blob = json_encode($all) . file_get_contents($A::$historyFile);
$body = trim(preg_replace('/-----[^-]+-----|\s+/', '', $servedKeyPem));
ck('no answer and no history carries a private key', array(strpos($blob, 'PRIVATE KEY'), strpos($blob, substr($body, 20, 60))), array(false, false));

printf("  %s - %d case(s)%s\n", $fail ? 'FAIL' : 'PASS', $pass + $fail, $fail ? " ($fail failed)" : '');
exit($fail ? 1 : 0);
