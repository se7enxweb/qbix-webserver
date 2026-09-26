<?php

/**
 * The peer-to-peer mesh is opt-in and OFF by default, and even when it is on it
 * is not an unauthenticated mutation or dispatch surface.
 *
 * The mesh is bootstrapped by an unauthenticated handshake and then relays HTTP
 * requests between servers in-process. That is the same shape as the cluster
 * join endpoint a security audit found open by default, so it must not ship
 * open. This test drives a real server over a socket and asserts:
 *
 *   - default config: every /Q/sync/* path answers 404 (the mesh is off);
 *   - Q.webserver.mesh = true: /Q/sync/identity answers 200 with a peer_id
 *     (the read-only bootstrap works);
 *   - even switched on, /Q/sync/request with an unencrypted `raw` payload and
 *     no established session is refused, so no request is dispatched in-process
 *     for an unauthenticated caller;
 *   - /Q/sync/ping without a session is refused too.
 *
 *   php tests/unit-mesh-endpoints.php
 */

require __DIR__ . '/fixtures/race-harness.php';
list($base, $root) = rh_setup('mesh-endpoints');
file_put_contents($root . DS . 'index.php', '<?php echo "app";');

// ── Default config: the mesh is off, so /Q/sync/* is 404 ────────────────────

$port = rh_start('mesh-off', array(), 1);
foreach (array('/Q/sync/identity', '/Q/sync/ping', '/Q/sync/records') as $p) {
	check("mesh off: GET $p is 404", rh_get($port, $p)['status'], 404);
}
$r = rh_get($port, '/Q/sync/request', 15, 'POST',
	json_encode(array('from' => 'x', 'raw' => base64_encode("GET /Q/phpinfo HTTP/1.1\r\nHost: x\r\n\r\n"))),
	array('Content-Type' => 'application/json'));
check('mesh off: POST /Q/sync/request is 404 (no in-process dispatch)', $r['status'], 404);

// ── Switched on: the read-only bootstrap works ──────────────────────────────

$on = array('Q' => array('webserver' => array('mesh' => true)));
$port = rh_start('mesh-on', $on, 1);
$r = rh_get($port, '/Q/sync/identity');
check('mesh on: /Q/sync/identity is 200', $r['status'], 200);
$id = json_decode($r['body'], true);
check('mesh on: identity carries a 64-hex peer_id',
	is_array($id) && isset($id['peer_id']) && preg_match('/^[0-9a-f]{64}$/', $id['peer_id']) === 1, true);

// ── Switched on, but not an unauthenticated dispatch/SSRF surface ────────────

$r = rh_get($port, '/Q/sync/request', 15, 'POST',
	json_encode(array('from' => 'attacker', 'raw' => base64_encode("GET /Q/phpinfo HTTP/1.1\r\nHost: x\r\n\r\n"))),
	array('Content-Type' => 'application/json'));
$body = json_decode($r['body'], true);
// The route answers 200 with a JSON error; what matters is that no request was
// dispatched (no phpinfo output) and an error was returned instead.
check('mesh on: unauthenticated raw request is refused', is_array($body) && isset($body['error']), true);
check('mesh on: unauthenticated raw request did not run phpinfo',
	stripos($r['body'], 'phpinfo') === false && stripos($r['body'], 'PHP Version') === false, true);

$r = rh_get($port, '/Q/sync/ping', 15, 'POST',
	json_encode(array('from' => 'attacker', 'message' => 'x')),
	array('Content-Type' => 'application/json'));
$body = json_decode($r['body'], true);
check('mesh on: ping without a session is refused', is_array($body) && isset($body['error']), true);

rh_finish();
