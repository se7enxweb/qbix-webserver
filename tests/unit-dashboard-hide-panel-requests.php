<?php

/**
 * Q.dashboard.hidePanelRequests keeps the server's own /Q/ requests -- the
 * dashboard polling itself, the panel's API, health checks, icons -- out of
 * the dashboard's figures. Off by default.
 *
 * With a dashboard open, its own traffic filled the live log and the top
 * paths, and counted towards requests per second, so the page watching the
 * site mostly showed itself. Asserted:
 *   - off (the default): every request is counted, /Q/ ones included;
 *   - on: /Q/ requests leave the counts, top paths and live log untouched,
 *     and are counted apart as hiddenPanelRequests;
 *   - on: a path that merely contains /Q/ further in, or starts with /Qx, is
 *     still the site's and still counted.
 *
 *   php tests/unit-dashboard-hide-panel-requests.php
 */

class Q_Config
{
	static $data = array();
	static function get()
	{
		$args = func_get_args();
		$default = array_pop($args);
		$node = self::$data;
		foreach ($args as $key) {
			if (!is_array($node) or !array_key_exists($key, $node)) return $default;
			$node = $node[$key];
		}
		return $node;
	}
}
class Q_WebSocket { static $channels = array(); }

require_once __DIR__ . '/../src/Q/WebServer/Dashboard.php';
$D = 'Q_WebServer_Dashboard';

$pass = 0; $fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

function reset_dashboard()
{
	global $D;
	$D::$stats['requests'] = 0;
	$D::$recentRequests = array();
	$D::$topPaths = array();
	$D::$hiddenPanelRequests = 0;
}
function traffic()
{
	global $D;
	foreach (array('/', '/site/', '/Q/health', '/Q/ws', '/Q/api/stats', '/Q/favicon.svg',
		'/blog/Q/notes', '/Qx/page') as $uri) {
		$D::recordRequest('GET', $uri, 200, 1.0);
	}
}
function paths() { global $D; return array_keys($D::$topPaths); }
function logged() { global $D; return array_column($D::$recentRequests, 'uri'); }

// ── Off, the default ────────────────────────────────────────────
reset_dashboard();
traffic();
check('off by default: every request counted', $D::$stats['requests'], 8);
check('...the /Q/ ones in the live log too', in_array('/Q/health', logged(), true), true);
check('...and nothing counted as hidden', $D::$hiddenPanelRequests, 0);

// ── On ──────────────────────────────────────────────────────────
Q_Config::$data = array('Q' => array('dashboard' => array('hidePanelRequests' => true)));
reset_dashboard();
traffic();
check('on: only the site\'s requests are counted', $D::$stats['requests'], 4);
check('...the /Q/ ones are counted apart', $D::$hiddenPanelRequests, 4);
check('...not in top paths', paths(), array('GET /', 'GET /site/', 'GET /blog/Q/notes', 'GET /Qx/page'));
check('...not in the live log', logged(), array('/', '/site/', '/blog/Q/notes', '/Qx/page'));
check('/Q/ further into a path is the site\'s', $D::hidesRequest('/blog/Q/notes'), false);
check('/Qx is the site\'s', $D::hidesRequest('/Qx/page'), false);
check('/Q/ with a query string is the server\'s', $D::hidesRequest('/Q/api/stats?x=1'), true);

if ($fail) { printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
