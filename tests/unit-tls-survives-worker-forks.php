<?php

/**
 * Forking a worker never ends a visitor's TLS connection.
 *
 * A forked worker inherits the parent's open client connections and has to
 * let go of them. It did so with fclose(), and for a TLS stream PHP's close
 * is SSL_shutdown(), which writes a close_notify alert onto the socket -- the
 * socket the parent is still using. Every fork ended every TLS connection
 * open at that moment. With workers forked only to replace one it was rare;
 * a dynamic pool forks under load, and a burst of 360 requests on alpha lost
 * 239 of them: "remote end closed connection without response".
 *
 * Asserted over real TLS against a dynamic pool (one spare worker, so load
 * forks new ones constantly):
 *
 *   - concurrent TLS requests that make the pool fork are all answered, and
 *     the pool did fork while they were in flight;
 *   - rounds in which workers also retire (and so exit) while other TLS
 *     requests are in flight lose none of them.
 *
 * Fails on the engine before Q_WebServer::releaseInherited() existed.
 *
 *   php tests/unit-tls-survives-worker-forks.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
list($base, $root) = rh_setup('tls-forks');

file_put_contents($root . DS . 'index.php', '<?php
if (isset($_GET["sleep"])) usleep((int) $_GET["sleep"] * 1000);
if (isset($_GET["retire"])) Q_WebServer_Pool::retireAfterResponse("test");
echo "ok ", getmypid();
');

// A throwaway self-signed certificate.
$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
// sha256, because PHP's default is SHA-1, which a system crypto policy may
// refuse to sign with (RHEL's DEFAULT does); the server then never gets a
// certificate and this test waits for a TLS connection that never comes.
$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key, array('digest_alg' => 'sha256'));
$crt = openssl_csr_sign($csr, null, $key, 2, array('digest_alg' => 'sha256'));
openssl_x509_export($crt, $crtPem);
openssl_pkey_export($key, $keyPem);
file_put_contents("$base/cert.pem", $crtPem);
file_put_contents("$base/key.pem", $keyPem);

$tlsPort = rh_free_port();
$config = array('Q' => array(
	'web' => array('https' => array('mode' => 'manual', 'cert' => "$base/cert.pem", 'key' => "$base/key.pem")),
	'webserver' => array('spareWorkers' => 1, 'idleWorkerTimeout' => 60),
));
// rh_start() passes --port; the TLS listener is added with --https-port.
$GLOBALS['rh_extra_args'] = array("--https-port=$tlsPort");
$port = rh_start('tls', $config, 24);

function tlsConnect($port)
{
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
	for ($i = 0; $i < 40; ++$i) {
		$s = @stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
		if ($s) return $s;
		usleep(250000);
	}
	return false;
}

/** One request on an open connection; returns array(status, body) or array(0, error). */
function tlsRequest($s, $path, $close = false)
{
	fwrite($s, "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: " . ($close ? 'close' : 'keep-alive') . "\r\n\r\n");
	stream_set_timeout($s, 20);
	$head = '';
	while (!feof($s) and strpos($head, "\r\n\r\n") === false) {
		$line = fgets($s);
		if ($line === false) break;
		$head .= $line;
	}
	if (!preg_match('~^HTTP/1\.[01] (\d+)~', $head, $m)) return array(0, 'no response: ' . json_encode(substr($head, 0, 80)));
	$body = '';
	if (preg_match('~\r\ncontent-length:\s*(\d+)~i', $head, $cl)) {
		while (strlen($body) < (int) $cl[1] and !feof($s)) {
			$c = fread($s, (int) $cl[1] - strlen($body));
			if ($c === false or $c === '') break;
			$body .= $c;
		}
	} elseif (stripos($head, 'chunked') !== false) {
		while (($sz = fgets($s)) !== false and ($n = hexdec(trim($sz))) > 0) {
			$body .= stream_get_contents($s, $n);
			fgets($s);
		}
		fgets($s);
	}
	return array((int) $m[1], $body);
}

/** $n requests at once, each on its own TLS connection. */
function tlsBurst($port, $n, $path)
{
	$socks = array();
	for ($i = 0; $i < $n; ++$i) {
		$s = tlsConnect($port);
		if ($s) {
			fwrite($s, "GET $path&i=$i HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
			$socks[] = $s;
		}
	}
	$ok = 0; $bad = array();
	foreach ($socks as $s) {
		stream_set_timeout($s, 20);
		$raw = (string) stream_get_contents($s);
		fclose($s);
		if (preg_match('~^HTTP/1\.[01] 200~', $raw)) ++$ok; else $bad[] = substr($raw, 0, 40);
	}
	return array($ok, $n - count($socks) + count($bad), $bad);
}

// Every request here is in flight -- its TLS connection open in the parent --
// while other requests make the pool fork. A fork that sent close_notify
// would end some of them.
$start = count(array_filter(rh_workers(rh_server_pid('tls')), function ($s) { return $s !== 'Z'; }));
list($ok, $lost, $bad) = tlsBurst($tlsPort, 20, '/index.php?sleep=400');
check('twenty concurrent TLS requests that make the pool fork are all answered', $ok . ' ' . json_encode(array_slice($bad, 0, 3)), '20 []');
$live = count(array_filter(rh_workers(rh_server_pid('tls')), function ($s) { return $s !== 'Z'; }));
check(sprintf('...and the pool did fork for them (%d -> %d workers)', $start, $live), $live > $start, true);

// Workers that exit (each replaces its worker after answering) while other
// TLS requests are in flight.
$lostTotal = 0;
for ($round = 0; $round < 6; ++$round) {
	list($ok, $lost) = tlsBurst($tlsPort, 16, '/index.php?sleep=' . (100 + 50 * $round) . ($round % 2 ? '&retire=1' : ''));
	$lostTotal += 16 - $ok;
}
check('six more rounds of forking and exiting workers lose no TLS request', $lostTotal, 0);

rh_finish();
