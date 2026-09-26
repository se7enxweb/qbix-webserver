<?php

/**
 * A 502 or 504 error metric records the client IP and user agent, and never a
 * cookie -- and the telemetry that holds them answers only an admin.
 *
 * The 502/504 reaper reads what it records from Q_WebServer::$workerPids, whose
 * fork-per-request entry now carries clientIp and userAgent but deliberately
 * not the cookies: a session id must never enter the error telemetry, which is
 * the organisation's absolute rule. This test drives a real server that fails
 * requests on purpose and checks:
 *
 *   - a crashed worker (502) and a killed-for-timeout worker (504) are logged
 *     with the request's IP and user agent;
 *   - the session cookie sent with those requests appears nowhere in the
 *     metrics access log;
 *   - the metrics and stats endpoints are refused to a remote client with no
 *     credential (403), and answered to this machine -- so the enriched
 *     telemetry is never reachable unauthenticated, and never in a visitor's
 *     own response body.
 *
 *   php tests/unit-metrics-5xx-privacy.php
 */

require __DIR__ . '/fixtures/race-harness.php';
rh_require_pool();
list($base, $root) = rh_setup('metrics-5xx');

// The fallback route's handler: it fails on purpose so a fork-per-request
// worker is recorded as 502 (throw) or 504 (sleep past the timeout).
@mkdir("$root/handlers/app/main", 0777, true);
copy(__DIR__ . '/fixtures/metrics-app/handlers/app/main/get.php', "$root/handlers/app/main/get.php");

$accessLog = "$base/access.log";
$config = array('Q' => array(
	'app' => '',
	'webserver' => array(
		'requestTimeout' => 2, // a slept request is killed after 2s -> 504
		'proxy' => array('trusted' => array('127.0.0.1', '::1')),
		'fallback' => array('handler' => 'app/main'),
		'metrics' => array(
			'enabled' => true,
			'accessLog' => $accessLog,
			'db' => "$base/metrics.db",
			'logBufferSize' => 1, // flush every line, so the test can read it
		),
	),
));
// workers=0: the main process forks per request, so the 502/504 reaper and the
// timeout killer -- which read $workerPids -- run here, as in production's
// in-process mode. (A pool takes a different, untouched path.)
$port = rh_start('m5xx', $config, 0);

$SESSION_502 = 'SECRETSESSION502deadbeef';
$SESSION_504 = 'SECRETSESSION504deadbeef';
$UA_502 = 'MetricsProbe-502/9.9';
$UA_504 = 'MetricsProbe-504/9.9';
$IP = '203.0.113.9'; // travels as X-Forwarded-For, trusted proxy -> clientIp

// 502: the worker throws and exits non-zero.
rh_get($port, '/?boom=throw', 15, 'GET', '', array(
	'X-Forwarded-For' => $IP,
	'User-Agent' => $UA_502,
	'Cookie' => "Q_session_app=$SESSION_502",
));
// 504: the worker sleeps past requestTimeout and is killed.
rh_get($port, '/?boom=sleep', 8, 'GET', '', array(
	'X-Forwarded-For' => $IP,
	'User-Agent' => $UA_504,
	'Cookie' => "Q_session_app=$SESSION_504",
));

// The reaper and the flush are asynchronous; wait for the lines to land.
$log = '';
for ($t = microtime(true); microtime(true) - $t < 10; usleep(200000)) {
	$log = (string) @file_get_contents($accessLog);
	if (strpos($log, $UA_502) !== false and strpos($log, $UA_504) !== false) break;
}

check('the 502 metric records the user agent', strpos($log, $UA_502) !== false, true);
check('the 504 metric records the user agent', strpos($log, $UA_504) !== false, true);
check('the 5xx metrics record the client IP', substr_count($log, $IP) >= 2, true);
check('no 502 session cookie value is recorded', strpos($log, $SESSION_502), false);
check('no 504 session cookie value is recorded', strpos($log, $SESSION_504), false);
check('the word "Cookie" is not written to the metrics log', stripos($log, 'cookie'), false);

// The enriched telemetry is admin-gated: refused to a remote client, answered
// to this machine. (#102 keeps /Q/metrics and /Q/stats behind adminAllowed.)
$remote = array('X-Forwarded-For' => $IP);
check('remote /Q/metrics is refused', rh_get($port, '/Q/metrics', 15, 'GET', '', $remote)['status'], 403);
check('remote /Q/stats is refused', rh_get($port, '/Q/stats', 15, 'GET', '', $remote)['status'], 403);
$m = rh_get($port, '/Q/metrics', 15, 'GET', '', array('Accept' => '*/*'));
check('this machine gets /Q/metrics', $m['status'], 200);
// The recorded IP/UA live only in the file/telemetry, never in this response.
check('the metrics response body carries no recorded session cookie',
	strpos($m['body'], $SESSION_502) === false and strpos($m['body'], $SESSION_504) === false, true);
check('the 5xx errors are counted', strpos($m['body'], '5xx') !== false or strpos($m['body'], '502') !== false, true);

rh_finish();
