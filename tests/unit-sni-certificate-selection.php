<?php

/**
 * The TLS handshake presents the certificate that matches the client's SNI
 * server name, while the default certificate covers every name that is not
 * registered -- so a single-certificate server behaves exactly as before.
 *
 * A domain certificate is registered through Q.web.https.domains (the same
 * path autohost uses after provisioning, via Q_WebServer::registerDomainCert).
 * Registration keeps #98's per-process copy model: the listener reads a private
 * copy named for this process, not the source file, so a renewal or a second
 * server on the same directory never pulls a certificate out from under a
 * running handshake. This test checks:
 *
 *   - with two certificates, an SNI name that is registered gets its own
 *     certificate, and an unregistered name (or none) gets the default;
 *   - with one certificate, every name gets the default -- unchanged;
 *   - the domain certificate is served from a private per-process copy
 *     (active-<port>-dom<hash>-<fp>-<pid>), beside the default copy, and
 *     neither cleans up the other.
 *
 *   php tests/unit-sni-certificate-selection.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
require_once __DIR__ . '/../src/Q.php';

list($base, $root) = rh_setup('sni');
file_put_contents($root . DS . 'index.php', '<?php echo "ok";');
$ssl = "$base/ssl";

// Two certificates, each covering 127.0.0.1 so a client can reach the server
// at that address while asking for either name by SNI.
$make = function ($cn) {
	return (new Q_WebServer_Certificate_Provider_OpensslEcdsa())->create(array($cn, '127.0.0.1'), 30);
};
$put = function ($file, $data) {
	file_put_contents("$file.tmp", $data);
	rename("$file.tmp", $file);
};
$A = $make('sni-alpha'); $put("$base/alpha.pem", $A->certPem); $put("$base/alpha.key", $A->keyPem);
$B = $make('sni-beta');  $put("$base/beta.pem", $B->certPem);  $put("$base/beta.key", $B->keyPem);

// Connect over TLS asking for $servername by SNI, and report the CN of the
// certificate the server presented.
$cn = function ($host, $port, $servername) {
	$ssl = array('verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true);
	if ($servername !== null) $ssl['peer_name'] = $servername; // sent as SNI
	$ctx = stream_context_create(array('ssl' => $ssl));
	$s = @stream_socket_client("tls://$host:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return null;
	$p = stream_context_get_params($s);
	fclose($s);
	$c = isset($p['options']['ssl']['peer_certificate'])
		? openssl_x509_parse($p['options']['ssl']['peer_certificate']) : null;
	return $c ? $c['subject']['CN'] : null;
};
$until = function ($host, $port, $servername, $want, $seconds) use ($cn) {
	$got = null;
	for ($t = microtime(true); microtime(true) - $t < $seconds; usleep(200000)) {
		if (($got = $cn($host, $port, $servername)) === $want) return $got;
	}
	return $got;
};

$httpsPort = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$httpsPort");

// ── Two certificates: sni-beta registered, sni-alpha the default ──────────
$config = array('Q' => array('web' => array('https' => array(
	'mode' => 'manual',
	'port' => $httpsPort,
	'cert' => "$base/alpha.pem",
	'key' => "$base/alpha.key",
	'watchInterval' => 1,
	'selfSigned' => array('dir' => $ssl, 'hosts' => array('sni-alpha', '127.0.0.1')),
	'domains' => array(
		'sni-beta' => array('cert' => "$base/beta.pem", 'key' => "$base/beta.key"),
	),
))));
rh_start('multi', $config, 1);

check('a registered SNI name gets its own certificate',
	$until('127.0.0.1', $httpsPort, 'sni-beta', 'sni-beta', 10), 'sni-beta');
check('an unregistered SNI name gets the default certificate',
	$cn('127.0.0.1', $httpsPort, 'sni-alpha'), 'sni-alpha');
check('no SNI name gets the default certificate',
	$cn('127.0.0.1', $httpsPort, null), 'sni-alpha');

// #98 per-process copies: a three-segment default copy and a four-segment
// domain copy live side by side, each named for this run's port.
$copies = function ($pattern) use ($ssl, $httpsPort) {
	$out = array();
	foreach ((array) glob("$ssl/active-$httpsPort-*") as $f) {
		if (preg_match($pattern, basename($f))) $out[] = basename($f);
	}
	sort($out);
	return $out;
};
$defaultCopies = $copies('/^active-\d+-[0-9a-fx]+-\d+\.(pem|key)$/');
$domainCopies = $copies('/^active-\d+-dom[0-9a-f]+-[0-9a-fx]+-\d+\.(pem|key)$/');
check('the default certificate is served from a private per-process copy', count($defaultCopies), 2);
check('the domain certificate is served from its own private per-process copy', count($domainCopies), 2);

// ── One certificate: every name gets the default (unchanged behaviour) ────
$httpsPort2 = rh_free_port();
$GLOBALS['rh_extra_args'] = array("--https-port=$httpsPort2");
$config2 = array('Q' => array('web' => array('https' => array(
	'mode' => 'manual',
	'port' => $httpsPort2,
	'cert' => "$base/alpha.pem",
	'key' => "$base/alpha.key",
	'watchInterval' => 1,
	'selfSigned' => array('dir' => "$base/ssl2", 'hosts' => array('sni-alpha', '127.0.0.1')),
))));
rh_start('single', $config2, 1);

check('with one certificate, a name gets the default',
	$until('127.0.0.1', $httpsPort2, 'sni-alpha', 'sni-alpha', 10), 'sni-alpha');
check('with one certificate, any other name still gets the default',
	$cn('127.0.0.1', $httpsPort2, 'sni-beta'), 'sni-alpha');

rh_finish();
