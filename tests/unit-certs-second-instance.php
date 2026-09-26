<?php

/**
 * A second server on the same certificate directory and port never takes
 * HTTPS away from the first.
 *
 * Each server copies the pair it serves to a file of its own
 * (active-<port>-<fingerprint>-<pid>) and HTTPS reads that copy for every
 * handshake. A second instance -- another site, a test or benchmark server
 * bound to another address on the same port -- used to remove every other
 * copy for that port when it started, so the first server's next handshakes
 * failed until it was restarted.
 *
 * Against two real servers (127.0.0.1 and 127.0.0.2, one port):
 *   - starting the second leaves the first one's copy in place, and the
 *     first keeps presenting its certificate, during and after the second;
 *   - the first server's next swap removes its own old copy and the copies of
 *     the stopped second server, and nothing of a running one.
 *   - a pid file another server has taken over is left alone by the server
 *     that wrote it first when that one stops.
 * Without a server:
 *   - which copies a process may remove: its own, a gone process's, never a
 *     running process's or one named without a process id;
 *   - the shared self-signed pair is not replaced because a second instance
 *     wants fewer names, and when it wants a name the pair lacks, the new one
 *     still covers the names the first relied on.
 *
 *   php tests/unit-certs-second-instance.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
if (!function_exists('openssl_pkey_new')) { printf("  skip  needs openssl\n"); exit(0); }
require_once __DIR__ . '/../src/Q.php';
list($base, $root) = rh_setup('certs-second');
file_put_contents($root . DS . 'index.php', '<?php echo "ok";');

$make = function ($cn) {
	return (new Q_WebServer_Certificate_Provider_OpensslEcdsa())->create(array($cn, '127.0.0.1', '127.0.0.2'), 30);
};
$put = function ($file, $data) {
	file_put_contents("$file.tmp", $data);
	rename("$file.tmp", $file);
};
$cn = function ($host, $port) {
	$ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true)));
	$s = @stream_socket_client("tls://$host:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
	if (!$s) return null;
	$p = stream_context_get_params($s);
	fclose($s);
	$c = isset($p['options']['ssl']['peer_certificate']) ? openssl_x509_parse($p['options']['ssl']['peer_certificate']) : null;
	return $c ? $c['subject']['CN'] : null;
};
$until = function ($host, $port, $want, $seconds) use ($cn) {
	$got = null;
	for ($t = microtime(true); microtime(true) - $t < $seconds; usleep(250000)) {
		if (($got = $cn($host, $port)) === $want) return $got;
	}
	return $got;
};
$copies = function ($dir, $port, $pid = null) {
	$out = array();
	foreach ((array) glob("$dir/active-$port-*") as $f) {
		if ($pid === null or preg_match('/-' . (int) $pid . '\.(pem|key)$/', $f)) $out[] = basename($f);
	}
	sort($out);
	return $out;
};
// A server on its own address; rh_start() only knows 127.0.0.1.
$start = function ($name, $host, $tls, array $https) use ($base, $root) {
	$port = rh_free_port();
	file_put_contents("$base/$name.json", json_encode(array('Q' => array(
		'web' => array('cache' => array('enabled' => false), 'https' => $https),
		'compat' => array('skipSourceCodeTransform' => false)))));
	$cmd = array(PHP_BINARY, __DIR__ . '/../qbixserver.php', "--config=$base/$name.json", "--root=$root",
		"--host=$host", "--port=$port", "--https-port=$tls", '--workers=1', "--pid=$base/$name.pid");
	$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'),
		1 => array('file', "$base/$name.log", 'w'), 2 => array('file', "$base/$name.log", 'a')), $pipes);
	$GLOBALS['rh']['servers'][$name] = $proc;
	$GLOBALS['rh']['names'][] = $name;
	for ($i = 0; $i < 60; ++$i) {
		$s = @stream_socket_client("tcp://$host:$port", $en, $es, 1);
		if ($s) { fclose($s); return $proc; }
		usleep(500000);
	}
	fwrite(STDERR, "  FAIL - $name never listened\n" . @file_get_contents("$base/$name.log"));
	exit(1);
};

// ── Which copies a process may remove ────────────────────────────────────
$me = Q_WebServer_Certs::pid();
$gone = proc_open(array(PHP_BINARY, '-n', '-v'), array(1 => array('file', '/dev/null', 'w')), $p0);
$gonePid = (int) proc_get_status($gone)['pid'];
proc_close($gone);
check('its own copy', Q_WebServer_Certs::copyRemovable("/x/active-443-abcdef-$me.pem", $me), true);
check('a gone process\'s copy', Q_WebServer_Certs::copyRemovable("/x/active-443-abcdef-$gonePid.key", $me), true);
check('never a running process\'s copy', Q_WebServer_Certs::copyRemovable('/x/active-443-abcdef-' . getmypid() . '.pem', $me + 1), false);
check('never one named without a process id (an earlier version\'s)', Q_WebServer_Certs::copyRemovable('/x/active-443-abcdef.pem', $me), false);
check('never another file', Q_WebServer_Certs::copyRemovable("/x/self-signed-$gonePid.pem", $me), false);

// ── Two real servers, one port, two addresses ────────────────────────────
$probe = @stream_socket_server('tcp://127.0.0.2:0', $en, $es);
if (!$probe) {
	echo "  skip  two-server part: cannot bind 127.0.0.2\n";
} else {
	fclose($probe);
	$ssl = "$base/ssl";
	$a = $make('second-a'); $put("$base/a.pem", $a->certPem); $put("$base/a.key", $a->keyPem);
	$b = $make('second-b'); $put("$base/b.pem", $b->certPem); $put("$base/b.key", $b->keyPem);
	$tls = rh_free_port();
	$https = function ($cert, $key) use ($ssl) {
		return array('mode' => 'manual', 'cert' => $cert, 'key' => $key, 'watchInterval' => 1,
			'selfSigned' => array('dir' => $ssl, 'hosts' => array('second-self', '127.0.0.1', '127.0.0.2')));
	};
	$A = $start('a', '127.0.0.1', $tls, $https("$base/a.pem", "$base/a.key"));
	check('the first server presents its certificate', $until('127.0.0.1', $tls, 'second-a', 10), 'second-a');
	$pidA = rh_server_pid('a');
	$aCopies = $copies($ssl, $tls, $pidA);
	check('...from a copy named for its process', count($aCopies), 2);

	$B = $start('b', '127.0.0.2', $tls, $https("$base/b.pem", "$base/b.key"));
	check('a second server on the same port and directory presents its own', $until('127.0.0.2', $tls, 'second-b', 10), 'second-b');
	$pidB = rh_server_pid('b');
	check('...and leaves the first one\'s copy in place', $copies($ssl, $tls, $pidA), $aCopies);
	check('...so the first still presents its certificate', $cn('127.0.0.1', $tls), 'second-a');

	rh_stop($B);
	usleep(500000);
	$ok = true;
	for ($i = 0; $i < 5; ++$i) $ok = $ok && $cn('127.0.0.1', $tls) === 'second-a';
	check('with the second stopped, the first keeps serving HTTPS', $ok, true);
	check('...the stopped server\'s copy is still there until someone swaps', count($copies($ssl, $tls, $pidB)), 2);

	$a2 = $make('second-a2');
	$put("$base/a.key", $a2->keyPem); $put("$base/a.pem", $a2->certPem);
	check('the first server swaps in a renewed pair', $until('127.0.0.1', $tls, 'second-a2', 10), 'second-a2');
	$now = $copies($ssl, $tls, $pidA);
	check('...removing its own old copy', array_values(array_intersect($now, $aCopies)), array());
	check('...keeping one copy of the new pair', count($now), 2);
	check('...and the stopped server\'s copies', $copies($ssl, $tls, $pidB), array());

	// A pid file taken over by another server is not removed by the one
	// that wrote it first when that one stops.
	$C = $start('c', '127.0.0.2', rh_free_port(), $https("$base/b.pem", "$base/b.key"));
	check('a server writes its pid file', (int) trim((string) @file_get_contents("$base/c.pid")), rh_server_pid('c'));
	file_put_contents("$base/c.pid", $pidA);   // another server owns it now
	rh_stop($C);
	check('...and on stopping leaves it alone once another server owns it', (int) trim((string) @file_get_contents("$base/c.pid")), $pidA);
	rh_stop($A);
	check('a server removes its own pid file when it stops', is_file("$base/a.pid"), false);
}

// ── The shared self-signed pair ──────────────────────────────────────────
$store = new Q_WebServer_Certificate_Store("$base/self");
$first = array('site.test', 'www.site.test', '203.0.113.7', 'localhost', '127.0.0.1');
$r = Q_WebServer_Certificate_SelfSigned::ensure($first, false, $store);
check('a self-signed pair is made for the first server', $r ? $r[2] : null, true);
$fp = $store->load()->fingerprint();
$r = Q_WebServer_Certificate_SelfSigned::ensure(array('localhost', '127.0.0.1'), false, $store);
check('a second instance wanting fewer names does not replace it', array($r[2], $store->load()->fingerprint()), array(false, $fp));
$r = Q_WebServer_Certificate_SelfSigned::ensure(array('other.test', '127.0.0.1'), false, $store);
$h = $store->load()->hosts();
check('one wanting a missing name replaces it', $r[2], true);
check('...with a pair still covering every name the first relied on', array_values(array_diff($first, $h)), array());
check('...and the new name', in_array('other.test', $h, true), true);

rh_finish();
