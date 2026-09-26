<?php
/**
 * An open dashboard costs the server almost nothing per request.
 *
 * Every request is pushed to connected dashboards as it happens. The push also
 * carried the full stats snapshot -- getStats(), which reads every worker's
 * /proc entry, lists the log directory and counts the caches -- so one open
 * dashboard doubled the cost of serving a cached page: 0.25 -> 0.48 ms of CPU,
 * half the requests per second, 43 MB sent to that one browser in 18 s. On a
 * busy installation with 48 workers it was worse, and it came and went with
 * whether anyone had the panel open.
 *
 * Now each request still goes out as it happens, but the stats go with at
 * most one of them a second (the two-second heartbeat carries them too), and
 * the page only redraws the stats when a message has them.
 *
 * Asserted against a real server with a real dashboard WebSocket: a burst of
 * requests is pushed entry by entry, and only a handful of those messages
 * carry stats.
 *
 *   php tests/unit-dashboard-stats-throttle.php
 */
require __DIR__ . '/fixtures/race-harness.php';
list($base, $root) = rh_setup('dash-throttle');
file_put_contents($root . DS . 'index.php', '<?php echo "ok";');
$port = rh_start('dash', array(), 2);

/** Open /Q/ws as a dashboard; the socket, or false. */
function dashboardConnect($port)
{
	$s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 5);
	if (!$s) return false;
	$key = base64_encode(random_bytes(16));
	fwrite($s, "GET /Q/ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
		. "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
	stream_set_timeout($s, 5);
	$head = '';
	while (strpos($head, "\r\n\r\n") === false and !feof($s)) {
		$c = fread($s, 1024);
		if ($c === false or $c === '') break;
		$head .= $c;
	}
	if (strpos($head, ' 101 ') === false) return false;
	$GLOBALS['wsLeftover'] = substr($head, strpos($head, "\r\n\r\n") + 4);
	return $s;
}

/** Every complete text frame in $buf, decoded as JSON; $buf keeps the rest. */
function frames(&$buf)
{
	$out = array();
	while (strlen($buf) >= 2) {
		$b1 = ord($buf[1]) & 0x7f;
		$off = 2;
		if ($b1 === 126) { if (strlen($buf) < 4) break; $len = unpack('n', substr($buf, 2, 2))[1]; $off = 4; }
		elseif ($b1 === 127) { if (strlen($buf) < 10) break; $len = unpack('J', substr($buf, 2, 8))[1]; $off = 10; }
		else $len = $b1;
		if (strlen($buf) < $off + $len) break;
		if ((ord($buf[0]) & 0x0f) === 1) $out[] = json_decode(substr($buf, $off, $len), true);
		$buf = substr($buf, $off + $len);
	}
	return $out;
}

$ws = dashboardConnect($port);
check('a dashboard can connect to /Q/ws', $ws !== false, true);
if (!$ws) rh_finish();
stream_set_blocking($ws, false);
$buf = $GLOBALS['wsLeftover'];

// A burst of requests, as fast as they come, reading the dashboard as we go.
$t0 = microtime(true);
$sent = 0;
for ($i = 0; $i < 300; ++$i) {
	$r = rh_get($port, '/index.php?i=' . $i);
	if (($r['status'] ?? 0) === 200) ++$sent;
	while (($c = fread($ws, 65536)) !== false and $c !== '') $buf .= $c;
}
$elapsed = microtime(true) - $t0;
// Let the last pushes arrive.
$until = microtime(true) + 1.5;
while (microtime(true) < $until) {
	$c = fread($ws, 65536);
	if ($c !== false and $c !== '') $buf .= $c; else usleep(50000);
}

$requests = 0; $withStats = 0;
foreach (frames($buf) as $m) {
	if (($m['type'] ?? '') !== 'request') continue;
	++$requests;
	if (isset($m['stats'])) ++$withStats;
}
check('the burst was answered', $sent, 300);
check('every request still reaches the dashboard as it happens', $requests >= 290, true);
// One a second at most, plus the first, plus a margin for a slow machine.
$allowed = (int) ceil($elapsed) + 2;
rh_bug('stats go with at most one request entry a second', $withStats <= $allowed,
	"$withStats of $requests request messages carried the full stats in " . round($elapsed, 1)
	. "s (at most $allowed expected): every request pays for getStats() while a dashboard is open");
check('...but they are still sent', $withStats >= 1, true);

// The page must cope with request messages that carry no stats.
$js = file_get_contents(__DIR__ . '/../designs/default/dashboard/script.js');
check('the dashboard page redraws stats only when a message has them',
	strpos($js, "if(m.type==='request'){A(m.entry);if(m.stats)U(m.stats)}") !== false, true);

fclose($ws);
rh_finish();
