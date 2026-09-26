<?php
/**
 * @module Q
 */
/**
 * Server dashboard: comprehensive stats, live HTML display at /Q/dashboard,
 * real-time updates via Q_WebSocket on the 'dashboard' channel.
 * @class Q_WebServer_Dashboard
 */
class Q_WebServer_Dashboard
{
	static $stats = array(
		'startTime' => 0, 'requests' => 0,
		'status2xx' => 0, 'status3xx' => 0, 'status4xx' => 0, 'status5xx' => 0,
		'phpRequests' => 0, 'staticRequests' => 0,
		'bytesOut' => 0, 'totalMs' => 0,
		'slowest' => 0, 'slowestUri' => '',
	);
	static $recentRequests = array();
	static $topPaths = array(); // path => [count, totalMs]
	static $statusCodes = array(); // code => count
	static $rpsHistory = array(); // [timestamp => count] for sparkline

	static function init()
	{
		self::$stats['startTime'] = time();
		self::$maxSessions = (int) Q_Config::get('Q', 'dashboard', 'maxSessions', 20);

		// Push stats every 2 seconds so the dashboard stays live
		// even when no requests are coming in
		Q_Evented::repeat(2.0, function () {
			if (empty(Q_WebSocket::$channels['dashboard'])) return;
			Q_WebSocket::broadcastTo('dashboard', array(
				'type' => 'heartbeat', 'stats' => Q_WebServer_Dashboard::getStats()
			));
		});
	}

	/**
	 * The shell's activity card, or null. Never allowed to fail the stats it
	 * is part of: those feed the live heartbeat and the page itself.
	 */
	static function shellSummary()
	{
		if (!class_exists('Q_WebServer_Shell_Server', false)) return null;
		try {
			return Q_WebServer_Shell_Server::summary();
		} catch (\Throwable $e) {
			return array('error' => $e->getMessage());
		}
	}

	static $sessions = array();  // sessionId => lastSeen timestamp (MRU order)
	static $maxSessions = 20;    // configurable cap

	/** Seconds between the stats sent along with live request entries. */
	const STATS_INTERVAL = 1.0;

	/** @var float when stats last went out with a request entry */
	static $statsSentAt = 0.0;

	static function recordRequest($method, $uri, $status, $ms, $bytes = 0,
		$isPhp = false, $contentType = '', $memUsed = 0, $cookies = array())
	{
		self::$stats['requests']++;
		self::$stats['totalMs'] += $ms;
		self::$stats['bytesOut'] += $bytes;
		if ($isPhp) self::$stats['phpRequests']++;
		else self::$stats['staticRequests']++;

		if ($ms > self::$stats['slowest']) {
			self::$stats['slowest'] = $ms;
			self::$stats['slowestUri'] = $uri;
		}

		if ($status < 300) self::$stats['status2xx']++;
		elseif ($status < 400) self::$stats['status3xx']++;
		elseif ($status < 500) self::$stats['status4xx']++;
		else self::$stats['status5xx']++;

		if (!isset(self::$statusCodes[$status])) self::$statusCodes[$status] = 0;
		self::$statusCodes[$status]++;

		$pathKey = $method . ' ' . strtok($uri, '?');
		if (!isset(self::$topPaths[$pathKey])) self::$topPaths[$pathKey] = array(0, 0);
		self::$topPaths[$pathKey][0]++;
		self::$topPaths[$pathKey][1] += $ms;

		$sec = time();
		if (!isset(self::$rpsHistory[$sec])) self::$rpsHistory[$sec] = 0;
		self::$rpsHistory[$sec]++;
		$cutoff = $sec - 60;
		foreach (self::$rpsHistory as $t => $c) {
			if ($t < $cutoff) unset(self::$rpsHistory[$t]);
			else break;
		}

		$kind = $isPhp ? 'php' : self::mimeKind($uri, $contentType);

		// Detect session ID from cookies
		$sessionId = '';
		foreach ($cookies as $name => $val) {
			if ($name === 'PHPSESSID' || strpos($name, 'sessionId') === 0
				|| strpos($name, 'Q_sessionId') === 0
			) {
				$sessionId = substr($val, 0, 12); // short prefix for display
				break;
			}
		}
		if ($sessionId !== '') {
			self::$sessions[$sessionId] = time();
			// Evict beyond cap
			if (count(self::$sessions) > self::$maxSessions) {
				asort(self::$sessions);
				self::$sessions = array_slice(self::$sessions,
					-self::$maxSessions, null, true);
			}
		}

		$entry = array('time' => date('H:i:s'), 'method' => $method,
			'uri' => $uri, 'status' => $status, 'ms' => round($ms, 1), 'kind' => $kind,
			'mem' => $memUsed, 'sid' => $sessionId);
		self::$recentRequests[] = $entry;
		if (count(self::$recentRequests) > 200) array_shift(self::$recentRequests);

		// Only broadcast if a dashboard client is connected -- and the stats
		// at most once a second. getStats() is not cheap: every worker's
		// /proc entry, the log directory listed, the caches counted. It used
		// to go out with every request, so one open dashboard doubled the
		// cost of serving a cached page (0.25 -> 0.48 ms of CPU, half the
		// requests per second, 43 MB pushed to that one browser in 18 s),
		// and the heartbeat below sends the same stats every two seconds
		// anyway. Each request's entry still goes out as it happens.
		if (!empty(Q_WebSocket::$channels['dashboard'])) {
			$message = array('type' => 'request', 'entry' => $entry);
			$now = microtime(true);
			if ($now - self::$statsSentAt >= self::STATS_INTERVAL) {
				self::$statsSentAt = $now;
				$message['stats'] = self::getStats();
			}
			Q_WebSocket::broadcastTo('dashboard', $message);
		}
	}

	/**
	 * Map URI extension or content-type to a kind for dashboard icons.
	 * @return {string} php, html, css, js, img, font, json, xml, doc, media, file
	 */
	static function mimeKind($uri, $contentType = '')
	{
		$ext = strtolower(pathinfo(strtok($uri, '?') ?: '', PATHINFO_EXTENSION));
		static $map = array(
			'html' => 'html', 'htm' => 'html',
			'css' => 'css', 'less' => 'css', 'scss' => 'css',
			'js' => 'js', 'mjs' => 'js', 'ts' => 'js',
			'png' => 'img', 'jpg' => 'img', 'jpeg' => 'img', 'gif' => 'img',
			'svg' => 'img', 'webp' => 'img', 'ico' => 'img', 'avif' => 'img',
			'woff' => 'font', 'woff2' => 'font', 'ttf' => 'font', 'otf' => 'font', 'eot' => 'font',
			'json' => 'json', 'xml' => 'xml', 'rss' => 'xml',
			'pdf' => 'doc', 'doc' => 'doc', 'docx' => 'doc', 'txt' => 'doc', 'md' => 'doc',
			'mp4' => 'media', 'webm' => 'media', 'mp3' => 'media', 'ogg' => 'media',
			'zip' => 'file', 'gz' => 'file', 'tar' => 'file',
		);
		if (isset($map[$ext])) return $map[$ext];
		if ($contentType) {
			if (strpos($contentType, 'html') !== false) return 'html';
			if (strpos($contentType, 'css') !== false) return 'css';
			if (strpos($contentType, 'javascript') !== false) return 'js';
			if (strpos($contentType, 'image/') !== false) return 'img';
			if (strpos($contentType, 'json') !== false) return 'json';
		}
		return 'file';
	}


	/** Q_WebServer_Extensions::summary(), or null where the manifest is absent. */
	static function extensionsSummary()
	{
		if (!class_exists('Q_WebServer_Extensions', false)) {
			$f = __DIR__ . '/Extensions.php';
			if (!is_file($f)) return null;
			require_once $f;
		}
		return Q_WebServer_Extensions::summary();
	}

	static function getStats()
	{
		$up = time() - self::$stats['startTime'];
		$pool = Q_WebServer::$pool;
		$reqs = self::$stats['requests'];
		$avgMs = $reqs > 0 ? round(self::$stats['totalMs'] / $reqs, 1) : 0;
		$rps = $up > 0 ? round($reqs / $up, 1) : 0;

		// Current RPS (last 5 seconds)
		$now = time();
		$recent5 = 0;
		for ($i = 1; $i <= 5; $i++) {
			$recent5 += self::$rpsHistory[$now - $i] ?? 0;
		}
		$currentRps = round($recent5 / 5, 1);

		// Top 10 paths by count
		$topPaths = self::$topPaths;
		uasort($topPaths, function($a, $b) { return $b[0] - $a[0]; });
		$topPaths = array_slice($topPaths, 0, 10, true);
		$topFormatted = array();
		foreach ($topPaths as $path => $data) {
			$topFormatted[] = array(
				'path' => $path,
				'count' => $data[0],
				'avgMs' => $data[0] > 0 ? round($data[1] / $data[0], 1) : 0,
			);
		}

		// RPS sparkline data (last 60 seconds)
		$sparkline = array();
		for ($i = 59; $i >= 0; $i--) {
			$sparkline[] = self::$rpsHistory[$now - $i] ?? 0;
		}

		// Connection counts
		$keepAlive = count(Q_WebServer::$keepAliveCount);
		$wsConnections = count(Q_WebSocket::$workers);
		$wsRooms = count(Q_WebSocket::$roomWorkers);
		$activeRooms = array();
		foreach (Q_WebSocket::$roomWorkers as $name => $rw) {
			$activeRooms[] = array(
				'name' => $name,
				'members' => count($rw['members'] ?? array()),
			);
		}

		return array(
			'uptime' => self::fmtUp($up), 'uptimeSec' => $up,
			'requests' => $reqs,
			'rps' => $rps, 'currentRps' => $currentRps,
			'avgMs' => $avgMs,
			// Rounded here rather than where it is displayed. microtime()
			// subtraction gives thirteen decimal places, and the dashboard
			// printed all of them -- "slowest: 1541.8879985809326ms" --
			// which overflowed the card it sits in. avgMs a few lines up was
			// already rounded, so the two numbers beside each other disagreed
			// about how precise this server's timings are. Rounding at the
			// source fixes /Q/health and anything else reading this too.
			'slowest' => round(self::$stats['slowest'], 1),
			'slowestUri' => self::$stats['slowestUri'],
			'status2xx' => self::$stats['status2xx'],
			'status3xx' => self::$stats['status3xx'],
			'status4xx' => self::$stats['status4xx'],
			'status5xx' => self::$stats['status5xx'],
			'statusCodes' => self::$statusCodes,
			'phpRequests' => self::$stats['phpRequests'],
			'staticRequests' => self::$stats['staticRequests'],
			'bytesOut' => self::$stats['bytesOut'],
			'bytesFormatted' => self::fmtBytes(self::$stats['bytesOut']),
			'memory' => round(memory_get_usage(true)/1048576, 1),
			'memoryPeak' => round(memory_get_peak_usage(true)/1048576, 1),
			// "idle/live". A dynamic pool runs fewer workers than its maximum while
			// quiet, so the maximum is sent on its own.
			'workers' => $pool ? $pool->idleCount().'/'.$pool->liveCount() : 'fork',
			'workersMax' => $pool ? $pool->targetSize : 0,
			'workersSpare' => $pool ? $pool->spareWorkers : 0,
			'workerStats' => $pool ? self::cachedWorkerStats($pool) : null,
			'systemRam' => self::cachedSystemRam(),
			'parentPid' => getmypid(),
			'forkMode' => !$pool,
			'wsClients' => Q_WebSocket::clientCount(),
			'wsConnections' => $wsConnections,
			'wsRooms' => $wsRooms,
			'activeRooms' => $activeRooms,
			'connections' => count(Q_WebServer::$clients),
			'keepAlive' => $keepAlive,
			'topPaths' => $topFormatted,
			'sparkline' => $sparkline,
			'cache' => Q_WebServer_Cache::stats(),
			'log' => Q_WebServer_Log::stats(),
			'components' => Q_WebServer_Cache_Components::enabled()
				? Q_WebServer_Cache_Components::stats() : null,
			// The running PHP against the standard set of extensions: what is
			// missing per tier, with the install commands. Computed once per process.
			'extensions' => self::extensionsSummary(),
			// Q shell activity; only when the shell has been loaded in this process.
			'shell' => self::shellSummary(),
			'php' => PHP_VERSION,
			'os' => PHP_OS,
			'sessions' => self::getSessionList(),
		);
	}

	/**
	 * Return sessions sorted by most recently used (newest first).
	 */
	static function getSessionList()
	{
		if (empty(self::$sessions)) return array();
		arsort(self::$sessions); // highest timestamp first = most recent
		$list = array();
		foreach (self::$sessions as $sid => $ts) {
			$list[] = array('id' => $sid, 'last' => date('H:i:s', $ts));
		}
		return $list;
	}

	static function handle($client, $parsed)
	{
		$p = $parsed['path'];
		if ($p === '/Q/dashboard' || $p === '/Q/dashboard/') {
			// If panel password is set, require auth (cookie or query token)
			// This machine is let in, as adminAllowed() lets it in everywhere else.
			if (Q_WebServer_Panel::hasPassword() && !Q_WebServer::isLocalRequest($parsed)) {
				if (!Q_WebServer::hasAdminCredential($parsed)) {
					Q_WebServer::sendRedirect($client, Q_WebServer::loginRedirectUrl($parsed));
					return true;
				}
			}
			Q_WebServer::sendResponse($client, 200, self::renderHtml($parsed), 'text/html; charset=utf-8', array('Cache-Control' => 'no-store'));
			return true;
		}
		if ($p === '/Q/stats') {
			Q_WebServer::sendResponse($client, 200, json_encode(self::getStats()), 'application/json');
			return true;
		}
		return false;
	}

	// ── Cached stats (avoid shell calls every heartbeat) ──

	private static $cachedRam = null;
	private static $cachedRamTime = 0;
	private static $cachedWs = null;
	private static $cachedWsTime = 0;

	static function cachedSystemRam()
	{
		$now = time();
		if (self::$cachedRam && ($now - self::$cachedRamTime) < 5) {
			return self::$cachedRam;
		}
		self::$cachedRam = self::getSystemRam();
		self::$cachedRamTime = $now;
		return self::$cachedRam;
	}

	static function cachedWorkerStats($pool)
	{
		$now = time();
		if (self::$cachedWs && ($now - self::$cachedWsTime) < 15) {
			// Update the idle count and the pids (both cheap) even on a cache hit
			self::$cachedWs['idle'] = $pool->idleCount();
			if (method_exists($pool, 'pids')) self::$cachedWs['pids'] = $pool->pids();
			return self::$cachedWs;
		}
		self::$cachedWs = $pool->getWorkerStats();
		self::$cachedWsTime = $now;
		return self::$cachedWs;
	}

	static function getSystemRam()
	{
		// Linux: read /proc/meminfo
		$meminfo = @file_get_contents('/proc/meminfo');
		if ($meminfo) {
			$info = array();
			foreach (explode("\n", $meminfo) as $line) {
				if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
					$info[$m[1]] = (int) $m[2]; // kB
				}
			}
			$total = ($info['MemTotal'] ?? 0) / 1024;
			$available = ($info['MemAvailable'] ?? 0) / 1024;
			$used = $total - $available;
			// Swap in use is the signal a RAM percentage hides: a box can read
			// a comfortable 42% while it has pushed gigabytes to disk under
			// earlier pressure, which is the opposite of comfortable.
			$swapTotal = ($info['SwapTotal'] ?? 0) / 1024;
			$swapFree = ($info['SwapFree'] ?? 0) / 1024;
			$swapUsed = max(0, $swapTotal - $swapFree);
			return array(
				'totalMb' => round($total),
				'usedMb' => round($used),
				'availableMb' => round($available),
				'percent' => $total > 0 ? round($used / $total * 100) : 0,
				'swapTotalMb' => round($swapTotal),
				'swapUsedMb' => round($swapUsed),
				'swapPercent' => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100) : 0,
			);
		}
		// macOS: sysctl + vm_stat
		if (PHP_OS_FAMILY === 'Darwin') {
			$totalBytes = (int) trim(shell_exec('sysctl -n hw.memsize 2>/dev/null') ?? '0');
			$vmstat = @shell_exec('vm_stat 2>/dev/null');
			if ($totalBytes && $vmstat) {
				$pageSize = 4096;
				if (preg_match('/page size of (\d+)/', $vmstat, $ps)) $pageSize = (int) $ps[1];
				preg_match('/Pages free:\s+(\d+)/', $vmstat, $mf);
				preg_match('/Pages active:\s+(\d+)/', $vmstat, $ma);
				preg_match('/Pages inactive:\s+(\d+)/', $vmstat, $mi);
				preg_match('/Pages speculative:\s+(\d+)/', $vmstat, $ms);
				preg_match('/Pages wired down:\s+(\d+)/', $vmstat, $mw);
				preg_match('/Pages occupied by compressor:\s+(\d+)/', $vmstat, $mc);
				$total = $totalBytes / 1048576;
				$used = ((int)($ma[1] ?? 0) + (int)($mw[1] ?? 0) + (int)($mc[1] ?? 0)) * $pageSize / 1048576;
				$available = $total - $used;
				return array(
					'totalMb' => round($total),
					'usedMb' => round($used),
					'availableMb' => round($available),
					'percent' => $total > 0 ? round($used / $total * 100) : 0,
				);
			}
		}
		// Windows: wmic or powershell
		if (PHP_OS_FAMILY === 'Windows') {
			$out = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /VALUE 2>NUL');
			if ($out) {
				$total = 0;
				$free = 0;
				if (preg_match('/TotalVisibleMemorySize=(\d+)/', $out, $m)) $total = (int) $m[1] / 1024;
				if (preg_match('/FreePhysicalMemory=(\d+)/', $out, $m)) $free = (int) $m[1] / 1024;
				$used = $total - $free;
				return array(
					'totalMb' => round($total),
					'usedMb' => round($used),
					'availableMb' => round($free),
					'percent' => $total > 0 ? round($used / $total * 100) : 0,
				);
			}
			// Fallback: PowerShell
			$out = @shell_exec('powershell -Command "Get-CimInstance Win32_OperatingSystem | Select TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json" 2>NUL');
			if ($out) {
				$d = json_decode($out, true);
				if ($d) {
					$total = ($d['TotalVisibleMemorySize'] ?? 0) / 1024;
					$free = ($d['FreePhysicalMemory'] ?? 0) / 1024;
					return array(
						'totalMb' => round($total),
						'usedMb' => round($total - $free),
						'availableMb' => round($free),
						'percent' => $total > 0 ? round(($total - $free) / $total * 100) : 0,
					);
				}
			}
		}
		return null;
	}

	static function fmtUp($s) {
		if ($s < 60) return "{$s}s";
		$d = floor($s/86400); $h = floor(($s%86400)/3600);
		$m = floor(($s%3600)/60);
		if ($d > 0) return "{$d}d {$h}h {$m}m";
		if ($h > 0) return "{$h}h {$m}m";
		return "{$m}m ".($s%60).'s';
	}

	/**
	 * A duration as a person reads it: "10.6 ms" under a second, "3.64 s"
	 * from one. The script's fmtMs() is the same rule, so the rows served
	 * first and the rows the live refresh writes cannot disagree.
	 */
	static function fmtMs($ms) {
		$ms = (float) $ms;
		$r = round($ms, 1);
		if ($r < 1000) return $r . ' ms';
		return number_format($ms / 1000, 2, '.', '') . ' s';
	}

	/**
	 * One "Top paths" row: the path, truncated by CSS, then the request
	 * count and the average time as separate labelled values. They used to
	 * sit in bare adjacent spans and read as one string ("GET /643636.2ms").
	 * The path is request data, so it is escaped for the text and the title
	 * attribute. Mirrors tpRow() in the page script character for character:
	 * ENT_COMPAT because esc() there leaves ' alone, which is safe inside a
	 * double-quoted attribute.
	 */
	static function topPathRow($p) {
		$path = htmlspecialchars((string) $p['path'], ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
		return '<div class="tp"><span class="p" title="' . $path . '">' . $path
			. '</span><span class="c">' . (int) $p['count'] . ' req</span>'
			. '<span class="a">' . self::fmtMs($p['avgMs']) . ' avg</span></div>';
	}

	static function topPathsHtml($topPaths) {
		$out = '';
		foreach ((array) $topPaths as $p) $out .= self::topPathRow($p);
		return $out;
	}

	/**
	 * Severity of system RAM use: 'ok' under 70%, 'warn' from 70% to under
	 * 90%, 'crit' from 90%. ramLevel() in the page script is the same rule.
	 */
	static function ramLevel($percent) {
		$percent = (float) $percent;
		if ($percent >= 90) return 'crit';
		if ($percent >= 70) return 'warn';
		return 'ok';
	}

	/**
	 * Severity of swap: 'none' when nothing is swapped, 'warn' when any is,
	 * 'crit' when more than half of a known swap total is in use.
	 */
	static function swapLevel($usedMb, $totalMb = 0) {
		$usedMb = (float) $usedMb; $totalMb = (float) $totalMb;
		if ($usedMb <= 0) return 'none';
		if ($totalMb > 0 and $usedMb > $totalMb / 2) return 'crit';
		return 'warn';
	}

	/** One decimal, trailing ".0" dropped, as JavaScript prints a number. */
	private static function num1($v) {
		$r = round((float) $v, 1);
		return ($r == floor($r)) ? (string) (int) $r : (string) $r;
	}

	static function ramPercentHtml($ram) {
		return '<span class="sev-' . self::ramLevel($ram['percent'] ?? 0) . '">'
			. (int) ($ram['percent'] ?? 0) . '%</span>';
	}

	/**
	 * "18.5 / 46.8 GB · 4.7 GB swap", with the swap part in its own span so
	 * it takes its severity colour. Swap is shown whenever the host reports
	 * any (Linux); a platform that sends no swap figures gets no swap part.
	 * Mirrors ramDetail() in the page script.
	 */
	static function ramDetailHtml($ram) {
		$out = self::num1(($ram['usedMb'] ?? 0) / 1024) . ' / '
			. self::num1(($ram['totalMb'] ?? 0) / 1024) . ' GB';
		$swTotal = (float) ($ram['swapTotalMb'] ?? 0);
		$sw = (float) ($ram['swapUsedMb'] ?? 0);
		if ($swTotal > 0 or $sw > 0) {
			$amt = ($sw > 0 and $sw < 1024) ? ((int) round($sw)) . ' MB' : self::num1($sw / 1024) . ' GB';
			$out .= ' &#183; <span class="sev-' . self::swapLevel($sw, $swTotal) . '">' . $amt . ' swap</span>';
		}
		return $out;
	}

	static function fmtBytes($b) {
		if ($b < 1024) return $b . ' B';
		if ($b < 1048576) return round($b/1024, 1) . ' KB';
		if ($b < 1073741824) return round($b/1048576, 1) . ' MB';
		return round($b/1073741824, 2) . ' GB';
	}

	static function renderHtml($parsed)
	{
		// Product name, configurable, default "Qbix Server". The heredoc
		// below interpolates $brand for the chrome a person sees; $brandJs
		// is the same value JSON-encoded for the one place the script
		// rebuilds the subtitle. htmlspecialchars because it is operator
		// input reaching HTML, trusted or not.
		$brand = class_exists('Q_WebServer', false)
			? Q_WebServer::brand() : 'Qbix Server';
		$brand = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
		$brandJs = json_encode($brand);
		$verLabel = function_exists('qbix_version_label')
			? qbix_version_label(true)
			: (defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '');
		$verLabel = htmlspecialchars($verLabel, ENT_QUOTES, 'UTF-8');
		$brandUrl = class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('brandUrl') : '';
		$maintainer = class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('maintainer') : '';
		$maintainerUrl = class_exists('Q_WebServer', false) ? Q_WebServer::brandLink('maintainerUrl') : '';
		$brandUrl = htmlspecialchars($brandUrl, ENT_QUOTES, 'UTF-8');
		$maintainer = htmlspecialchars($maintainer, ENT_QUOTES, 'UTF-8');
		$maintainerUrl = htmlspecialchars($maintainerUrl, ENT_QUOTES, 'UTF-8');
		// The brand name, linked to the product home when one is configured.
		// The header brand links to the dashboard itself; the footer links to
		// the product. Different destinations, same name.
		$brandHeader = '<a href="/Q/dashboard" style="color:inherit;text-decoration:none">' . $brand . '</a>';
		$brandName = ($brandUrl !== '')
			? '<a href="' . $brandUrl . '" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">' . $brand . '</a>'
			: $brand;
		// The maintainer line, shown only when a maintainer is named.
		$maintainedBy = '';
		if ($maintainer !== '') {
			$inner = '<span>Maintained by ' . $maintainer . '</span>';
			if ($maintainerUrl !== '') {
				$inner = '<a href="' . $maintainerUrl . '" target="_blank" rel="noopener">' . $inner . '</a>';
			}
			$maintainedBy = '<div class="foot-by">' . $inner . '</div>';
		}
		$statsArr = self::getStats();
		// Into a <script>: < > & ' " as \u escapes, so no value -- a request
		// path in the recent list, say -- can close the script element.
		$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$stats = json_encode($statsArr, $jsonFlags);
		// Cards rendered here as well as by U(), from the same helpers the
		// script mirrors, so the first paint already reads correctly.
		$topPathsHtml = self::topPathsHtml($statsArr['topPaths'] ?? array());
		$ramSrv = $statsArr['systemRam'] ?? null;
		$sysramHtml = $ramSrv ? self::ramPercentHtml($ramSrv) : '&#8212;';
		$sysramDetailHtml = $ramSrv ? self::ramDetailHtml($ramSrv) : '&#8212;';
		$recent = json_encode(array_reverse(array_slice(self::$recentRequests, -50)), $jsonFlags);

		// Get auth token for WebSocket connection
		$wsToken = '';
		$ps = Q_WebServer_Panel_Auth::sessionFromRequest($parsed);
		if ($ps !== null && !$ps['mustChange']) {
			$wsToken = $ps['token'];
		}
		if (!$wsToken) {
			$qp = array();
			if (!empty($parsed['query'])) parse_str($parsed['query'], $qp);
			$wsToken = $qp['token'] ?? '';
		}
		// Only a token the caller brought is passed on. The configured
		// Q.dashboard.token used to be written into the page when none was
		// given, handing the secret to anyone the page was shown to; the
		// WebSocket is now allowed by the same rule as this page.
		//
		// URL-encoded: it goes inside a JavaScript string, and a ?token=
		// containing a quote was script injected into the dashboard by a link.
		$tokenParam = (is_string($wsToken) and $wsToken !== '') ? '?token=' . rawurlencode($wsToken) : '';
		// Scheme and host are chosen in the browser from location, below --
		// a hardcoded ws:// is blocked as mixed content on an https page. The
		// request links (BASE) likewise use location.origin: they were built
		// from the Host header, unescaped inside a JavaScript string, and
		// always http:// even on an https dashboard.
		// Icons, manifest and link-preview tags (Q_WebServer_Brand).
		$brandHead = Q_WebServer_Brand::headTags(Q_WebServer::brand() . ' Dashboard', '/Q/dashboard');
		// The page itself is a design on disk -- designs/default/dashboard/
		// (page.html, style.css, script.js), or the same files in the
		// configuration directory's designs/ -- rendered by substitution.
		// The shipped files are byte for byte what this method used to
		// return from a heredoc here (see tests/unit-design-render.php).
		$page = Q_WebServer_Design::render('dashboard', compact(
			'brand', 'brandHead', 'brandHeader', 'sysramHtml', 'sysramDetailHtml',
			'topPathsHtml', 'stats', 'recent', 'tokenParam', 'brandName',
			'verLabel', 'maintainedBy'
		));
		return $page !== null ? Q_WebServer_Shell::decorate($page)
			: '<!DOCTYPE html><html><body><p>The dashboard design is missing (designs/default/dashboard).</p></body></html>';
	}
}
