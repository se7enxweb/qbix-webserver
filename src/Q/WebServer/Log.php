<?php
/**
 * @module Q
 */

/**
 * Access and error logging with buffered writes, daily rotation,
 * and automatic gzip archiving.
 *
 * Writes access.log (combined format + response time) and error.log,
 * or whatever accessName/errorName call them.
 * Buffered: log lines accumulate in memory and flush on a timer or
 * when the buffer is full — one write() syscall per flush instead of
 * one per request. Daily rotation at midnight. Logs older than
 * `archiveAfterDays` (default 2) are compressed to .gz. Logs older
 * than `deleteAfterDays` (default 30) are deleted.
 *
 * Config:
 *   "Q": { "webserver": { "log": {
 *     "dir":              "logs",
 *     "access":           true,
 *     "error":            true,
 *     "accessName":       "access.log",
 *     "errorName":        "error.log",
 *     "format":           "vhost",
 *     "bufferSize":       65536,
 *     "flushInterval":    1,
 *     "maxSize":          52428800,
 *     "archiveAfterDays": 2,
 *     "deleteAfterDays":  30,
 *     "fileMode":         null,
 *     "dirMode":          "0755"
 *   }}}
 *
 *   bufferSize: 0 = unbuffered (flush every line), default 64KB.
 *   flushInterval: seconds between timer flushes, default 1.
 *   Set access/error to false to disable that log entirely.
 *   accessName/errorName rename the two files -- for one directory
 *   collecting the logs of several servers, or to match what an
 *   existing log shipper already watches. A bare filename, no
 *   directory part: anything else falls back to the default, so a
 *   name out of config cannot write outside `dir`. Rotation keeps
 *   the name and inserts the date before the .log, so "site.log"
 *   rotates to "site.2000-10-10.log".
 *
 *   fileMode/dirMode: the permissions the log files and their
 *   directory are created with, written as octal digits the way
 *   `Q/webserver/socketMode` is. Left out, a file gets 0666 minus the
 *   umask and the directory 0755 minus it -- what this server has
 *   always done. An access log names the people who visited, so on a
 *   machine with other accounts on it "0640" is the better answer, and
 *   where each site runs as its own user, "0660" with a directory of
 *   "2770" lets a shared group read what it is meant to and nobody
 *   else. Those two belong together: the setgid bit passes the group
 *   on, it does not grant the group anything.
 *
 * A virtual host can log to its own files. The hosts are already
 * declared for their document root, so the log goes in that same
 * entry rather than in a second list of domains:
 *
 *   "Q": { "webserver": { "hosts": {
 *     "a.example.com": { "root": "/srv/a", "log": true },
 *     "b.example.com": { "root": "/srv/b", "log": {
 *       "dir": "/var/log/b", "accessName": "web.log"
 *     }}
 *   }}}
 *
 *   log: true       names the files after the host, in `dir`:
 *                   a.example.com-access.log and -error.log
 *   log: {...}      overrides dir, accessName, errorName, fileMode,
 *                   dirMode, or all of them -- a host can be stricter
 *                   than the server and inherits what it omits
 *   no log key      that host's requests go to the server's own log
 *
 * Requests are sorted by the Host header, the same one WebServer
 * resolves the document root with. A request with no Host header,
 * or one for a host with no log of its own, goes to the server's
 * access log. Each host's files rotate, archive and prune on the
 * same terms as the server's own.
 *   format: "vhost" (default), "qbix", "combined", "common", or a format string.
 *
 * Format strings take the Apache tokens that make sense here:
 *
 *   %h  client address          %l  identd, always -
 *   %u  remote user, always -   %t  time, [10/Oct/2000:13:55:36 -0700]
 *   %r  request line            %m  method
 *   %U  path without the query  %q  query, with its ? when not empty
 *   %H  protocol                %s, %>s  status
 *   %b  size, - when zero       %B  size, 0 when zero
 *   %D  microseconds            %T  seconds
 *   %{ms}T  milliseconds        %{Name}i  a request header
 *   %%  a literal percent
 *
 * The named formats:
 *
 *   common    %h %l %u %t "%r" %>s %b
 *   combined  common + "%{Referer}i" "%{User-Agent}i"
 *   qbix      combined + " %{ms}T" -- what this server wrote until the Host was added
 *   vhost     qbix + ' "%{Host}i"' -- the default: the domain each line was for
 *
 * @class Q_WebServer_Log
 */

// Q_WebServer_Modes decides the permissions the files below are created
// with. The server's autoloader finds it by name; requiring it here as
// well is what makes this class usable on its own, which is how the
// tests drive it.
if (!class_exists('Q_WebServer_Modes', false)
and is_file(__DIR__ . '/Modes.php')) {
	require_once __DIR__ . '/Modes.php';
}

class Q_WebServer_Log
{
	static $accessFp = null;
	static $errorFp = null;
	static $accessPath = null;
	static $errorPath = null;
	static $accessName = 'access.log';
	static $errorName = 'error.log';
	// host => record, for virtual hosts that log to their own files.
	// Each record carries dir, accessName, errorName, the two paths,
	// the two handles and its own buffer.
	static $hosts = array();
	static $dir = null;
	// Parsed once in init(); null means "not configured", which means
	// every file is created exactly as it was before these keys existed.
	static $fileMode = null;
	static $dirMode = null;
	static $dirModeExplicit = false;
	static $maxSize = 52428800;       // 50MB
	static $archiveAfterDays = 2;
	static $deleteAfterDays = 30;
	static $currentDate = null;
	static $enabled = false;

	// ── Buffer ──────────────────────────────────────────
	static $accessBuf = '';
	static $errorBuf = '';
	static $format = null;            // resolved format string
	static $bufferSize = 65536;       // 64KB default
	static $flushInterval = 1.0;      // seconds

	/**
	 * Initialize from config.
	 * @method init
	 * @static
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'webserver', 'log', array());
		if (empty($config)) return;

		// Read before resolveDir(), which is what creates the directory.
		self::$fileMode = Q_WebServer_Modes::parse(
			Q::ifset($config, 'fileMode', null)
		);
		self::$dirModeExplicit = Q::ifset($config, 'dirMode', null) !== null;
		self::$dirMode = Q_WebServer_Modes::parse(
			Q::ifset($config, 'dirMode', null), 0755
		);

		$dir = Q::ifset($config, 'dir', null);
		if (!$dir) {
			$dir = defined('APP_DIR') ? APP_DIR . '/logs' : 'logs';
		}
		self::$dir = $dir = self::resolveDir(
			$dir, self::$dirMode, self::$dirModeExplicit
		);

		self::$maxSize = (int) Q::ifset($config, 'maxSize', 52428800);
		self::$archiveAfterDays = (int) Q::ifset($config, 'archiveAfterDays', 2);
		self::$deleteAfterDays = (int) Q::ifset($config, 'deleteAfterDays', 30);
		self::$format = self::resolveFormat(Q::ifset($config, 'format', 'vhost'));
		self::$bufferSize = (int) Q::ifset($config, 'bufferSize', 65536);
		self::$flushInterval = (float) Q::ifset($config, 'flushInterval', 1.0);

		$accessEnabled = Q::ifset($config, 'access', true);
		$errorEnabled = Q::ifset($config, 'error', true);

		self::$currentDate = date('Y-m-d');

		self::$accessName = self::sanitizeName(
			Q::ifset($config, 'accessName', null), 'access.log'
		);
		self::$errorName = self::sanitizeName(
			Q::ifset($config, 'errorName', null), 'error.log'
		);

		if ($accessEnabled) {
			self::$accessPath = $dir . '/' . self::$accessName;
			self::$accessFp = self::openLog(self::$accessPath, self::$fileMode);
		}
		if ($errorEnabled) {
			self::$errorPath = $dir . '/' . self::$errorName;
			self::$errorFp = self::openLog(self::$errorPath, self::$fileMode);
		}

		self::initHosts($accessEnabled, $errorEnabled);

		// chmod needs ownership, so a directory left behind by another
		// user keeps its own permissions however this is configured.
		// Worth saying once, at startup, rather than per file.
		if (self::$dirModeExplicit
		and !Q_WebServer_Modes::verify($dir, self::$dirMode)) {
			fwrite(STDERR, "  log: $dir is not "
				. decoct(self::$dirMode) . ", chmod did not take\n");
		}

		self::$enabled = true;

		// Flush timer — write accumulated buffer to disk
		if (self::$flushInterval > 0) {
			Q_Evented::repeat(self::$flushInterval, function () {
				Q_WebServer_Log::flush();
			});
		}

		// Check rotation every 60 seconds
		Q_Evented::repeat(60.0, function () {
			Q_WebServer_Log::checkRotation();
		});

		// Archive/prune on startup and daily
		self::archiveAndPrune();
		Q_Evented::repeat(86400.0, function () {
			Q_WebServer_Log::archiveAndPrune();
		});

		$mode = self::$bufferSize > 0
			? 'buffered (' . round(self::$bufferSize / 1024) . 'KB, '
			  . self::$flushInterval . 's)'
			: 'unbuffered';
		$vhosts = count(self::$hosts);
		if ($vhosts) {
			$mode .= ", $vhosts vhost" . ($vhosts === 1 ? '' : 's');
		}
		fwrite(STDERR, "  Logging to $dir/ ($mode)\n");
	}

	/**
	 * Log a request. Line goes into the buffer (or directly to disk
	 * if bufferSize=0).
	 * @method access
	 * @static
	 */
	/**
	 * Turn a named format into its string, or pass a custom one through.
	 * @method resolveFormat
	 * @static
	 * @param {string} $name
	 * @return {string}
	 */
	static function resolveFormat($name)
	{
		static $named = array(
			'common'   => '%h %l %u %t "%r" %>s %b',
			'combined' => '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"',
			// What this server wrote before the format was configurable:
			// combined, plus the response time. Kept as the default so an
			// existing log keeps its shape and whatever parses it keeps working.
			'qbix'     => '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" %{ms}T',
			// qbix, plus the Host the request was for, as one quoted field
			// at the end: the default, so one log can be filtered by domain.
			// Everything that reads the qbix shape reads this unchanged,
			// since the new field comes after all of it.
			'vhost'    => '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" %{ms}T "%{Host}i"',
		);
		if (!is_string($name) or $name === '') return $named['vhost'];
		return isset($named[$name]) ? $named[$name] : $name;
	}

	/**
	 * Build one access line.
	 *
	 * @method formatAccess
	 * @static
	 * @param {string} $format
	 * @param {array} $f Fields: ip, method, uri, path, query, protocol,
	 *   status, size, ms, headers
	 * @return {string}
	 */
	static function formatAccess($format, $f)
	{
		$size = (int) ($f['size'] ?? 0);
		$ms = (float) ($f['ms'] ?? 0);
		$path = $f['path'] ?? ($f['uri'] ?? '');
		$query = $f['query'] ?? '';
		if ($query !== '' and $query[0] !== '?') $query = '?' . $query;
		$proto = $f['protocol'] ?? 'HTTP/1.1';
		$request = trim(($f['method'] ?? '') . ' ' . ($f['uri'] ?? '') . ' ' . $proto);
		$headers = $f['headers'] ?? array();

		return preg_replace_callback(
			'/%(?:\{([^}]*)\}([ioTt])|>?([a-zA-Z%]))/',
			function ($m) use ($f, $size, $ms, $path, $query, $proto, $request, $headers) {
				// Every value escaped as Apache does: a client-supplied header
				// or path with a line break in it otherwise wrote a line of
				// its own into the log, and a quote closed the field early.
				return Q_WebServer_Log::logSafe(Q_WebServer_Log::formatField($m, $f, $size, $ms,
					$path, $query, $proto, $request, $headers));
			},
			$format
		);
	}

	/**
	 * A value made safe for one field of a log line: control characters as
	 * \xhh, and " and \ backslash-escaped, as Apache's mod_log_config does.
	 * @method logSafe
	 * @static
	 * @param {string} $v
	 * @return {string}
	 */
	static function logSafe($v)
	{
		$v = (string) $v;
		if (!preg_match('/[\x00-\x1f\x7f"\\\\]/', $v)) return $v;
		return preg_replace_callback('/[\x00-\x1f\x7f"\\\\]/', function ($c) {
			return ($c[0] === '"' || $c[0] === '\\') ? '\\' . $c[0] : sprintf('\\x%02x', ord($c[0]));
		}, $v);
	}

	/** One format directive's value, unescaped; see formatAccess(). */
	static function formatField($m, $f, $size, $ms, $path, $query, $proto, $request, $headers)
	{
				if ($m[1] !== '' or ($m[2] ?? '') !== '') {
					$arg = $m[1];
					switch ($m[2]) {
						case 'i':
							$k = strtolower($arg);
							$v = $headers[$k] ?? '';
							return $v === '' ? '-' : $v;
						case 'T':
							if ($arg === 'ms') return sprintf('%.1fms', $ms);
							if ($arg === 'us') return (string) (int) ($ms * 1000);
							return (string) (int) ($ms / 1000);
						case 't':
							return date($arg ?: 'd/M/Y:H:i:s O');
					}
					return $m[0];
				}
				switch ($m[3]) {
					case 'h': return $f['ip'] ?? '-';
					case 'l': return '-';
					case 'u': return '-';
					case 't': return '[' . date('d/M/Y:H:i:s O') . ']';
					case 'r': return $request;
					case 'm': return $f['method'] ?? '-';
					case 'U': return $path;
					case 'q': return $query;
					case 'H': return $proto;
					case 's': return (string) (int) ($f['status'] ?? 0);
					case 'b': return $size > 0 ? (string) $size : '-';
					case 'B': return (string) $size;
					case 'D': return (string) (int) ($ms * 1000);
					case 'T': return (string) (int) ($ms / 1000);
					case '%': return '%';
				}
				return $m[0];
	}

	static function access($ip, $method, $uri, $status, $size, $referer, $ua, $ms, $extra = array())
	{
		$headers = $extra['headers'] ?? array();
		// Counted per host whether or not a log is written, so the panel's
		// Domains tab has traffic numbers even with logging switched off.
		if (class_exists('Q_WebServer_Traffic')) {
			Q_WebServer_Traffic::record($headers['host'] ?? ($extra['host'] ?? ''), $status, $size,
				(string) ($extra['path'] ?? $uri));
		}
		// A virtual host with its own log takes the line; everything else,
		// including a request that arrived without a Host header, goes to
		// the server's own access log.
		$host = self::hostKey($headers['host'] ?? ($extra['host'] ?? ''));
		$rec = ($host !== '' and isset(self::$hosts[$host]))
			? $host
			: null;
		if ($rec === null ? !self::$accessFp : !self::$hosts[$rec]['accessFp']) {
			return;
		}
		// Referer and user agent are passed separately for callers that have
		// them but not the header array.
		if ($referer !== '' and $referer !== null and !isset($headers['referer'])) {
			$headers['referer'] = $referer;
		}
		if ($ua !== '' and $ua !== null and !isset($headers['user-agent'])) {
			$headers['user-agent'] = $ua;
		}
		$line = self::formatAccess(self::$format ?: self::resolveFormat('vhost'), array(
			'ip' => $ip, 'method' => $method, 'uri' => $uri,
			'path' => $extra['path'] ?? null, 'query' => $extra['query'] ?? '',
			'protocol' => $extra['protocol'] ?? 'HTTP/1.1',
			'status' => $status, 'size' => $size, 'ms' => $ms,
			'headers' => $headers,
		)) . "\n";

		if ($rec !== null) {
			if (self::$bufferSize <= 0) {
				@fwrite(self::$hosts[$rec]['accessFp'], $line);
				return;
			}
			self::$hosts[$rec]['buf'] .= $line;
			if (strlen(self::$hosts[$rec]['buf']) >= self::$bufferSize) {
				self::flushHost($rec);
			}
			return;
		}

		if (self::$bufferSize <= 0) {
			// Unbuffered — write immediately
			@fwrite(self::$accessFp, $line);
			return;
		}

		self::$accessBuf .= $line;
		if (strlen(self::$accessBuf) >= self::$bufferSize) {
			self::flushAccess();
		}
	}

	/**
	 * Log an error. Errors always flush immediately (they're rare
	 * and you want them on disk before a crash).
	 * @method error
	 * @static
	 */
	static function error($message, $context = '', $host = null)
	{
		$time = date('Y-m-d H:i:s');
		$line = "[$time] $message $context\n";
		$host = self::hostKey($host);
		$fp = ($host !== '' and isset(self::$hosts[$host]))
			? self::$hosts[$host]['errorFp']
			: self::$errorFp;
		if ($fp) {
			// Errors bypass the buffer — flush immediately
			self::flush(); // put the pending access lines on disk first
			@fwrite($fp, $line);
		}
		fwrite(STDERR, "[ERROR] $message $context\n");
	}

	/**
	 * Flush all buffered log lines to disk.
	 * Called by the timer and on shutdown.
	 * @method flush
	 * @static
	 */
	static function flush()
	{
		self::flushAccess();
		foreach (self::$hosts as $host => $rec) {
			self::flushHost($host);
		}
	}

	private static function flushAccess()
	{
		if (self::$accessBuf !== '' && self::$accessFp) {
			@fwrite(self::$accessFp, self::$accessBuf);
			self::$accessBuf = '';
		}
	}

	private static function flushHost($host)
	{
		if (!isset(self::$hosts[$host])) return;
		if (self::$hosts[$host]['buf'] === '') return;
		if (!self::$hosts[$host]['accessFp']) return;
		@fwrite(self::$hosts[$host]['accessFp'], self::$hosts[$host]['buf']);
		self::$hosts[$host]['buf'] = '';
	}

	/**
	 * Daily rotation + mid-day size rotation.
	 * @method checkRotation
	 * @static
	 */
	static function checkRotation()
	{
		if (!self::$enabled) return;

		// Flush before checking sizes
		self::flush();

		$today = date('Y-m-d');
		if ($today !== self::$currentDate) {
			$yesterday = self::$currentDate;
			self::$currentDate = $today;
			if (self::$accessFp) {
				self::rotateTo(self::$accessPath, self::$accessFp,
					self::rotatedPath(self::$dir, self::$accessName, $yesterday));
				self::$accessFp = self::openLog(self::$accessPath, self::$fileMode);
			}
			if (self::$errorFp) {
				self::rotateTo(self::$errorPath, self::$errorFp,
					self::rotatedPath(self::$dir, self::$errorName, $yesterday));
				self::$errorFp = self::openLog(self::$errorPath, self::$fileMode);
			}
			foreach (array_keys(self::$hosts) as $host) {
				self::rotateHost($host, $yesterday);
			}
			self::archiveAndPrune();
			return;
		}

		// Size-based mid-day rotation
		if (self::$accessPath && self::exceedsMax(self::$accessPath)) {
			$stamp = date('Y-m-d-His');
			self::rotateTo(self::$accessPath, self::$accessFp,
				self::rotatedPath(self::$dir, self::$accessName, $stamp));
			self::$accessFp = self::openLog(self::$accessPath, self::$fileMode);
		}
		if (self::$errorPath && self::exceedsMax(self::$errorPath)) {
			$stamp = date('Y-m-d-His');
			self::rotateTo(self::$errorPath, self::$errorFp,
				self::rotatedPath(self::$dir, self::$errorName, $stamp));
			self::$errorFp = self::openLog(self::$errorPath, self::$fileMode);
		}
		foreach (array_keys(self::$hosts) as $host) {
			self::rotateHostBySize($host);
		}
	}

	/**
	 * Rotate one virtual host's two logs to a dated name.
	 *
	 * @method rotateHost
	 * @static
	 * @private
	 * @param {string} $host
	 * @param {string} $stamp
	 */
	private static function rotateHost($host, $stamp)
	{
		$rec = self::$hosts[$host];
		self::flushHost($host);
		if ($rec['accessFp']) {
			self::rotateTo($rec['accessPath'], $rec['accessFp'],
				self::rotatedPath($rec['dir'], $rec['accessName'], $stamp));
			self::$hosts[$host]['accessFp'] = self::openLog(
				$rec['accessPath'], $rec['fileMode']
			);
		}
		if ($rec['errorFp']) {
			self::rotateTo($rec['errorPath'], $rec['errorFp'],
				self::rotatedPath($rec['dir'], $rec['errorName'], $stamp));
			self::$hosts[$host]['errorFp'] = self::openLog(
				$rec['errorPath'], $rec['fileMode']
			);
		}
	}

	/**
	 * The mid-day half: rotate a host's logs only once they are too big.
	 *
	 * @method rotateHostBySize
	 * @static
	 * @private
	 * @param {string} $host
	 */
	private static function rotateHostBySize($host)
	{
		$rec = self::$hosts[$host];
		$stamp = date('Y-m-d-His');
		if ($rec['accessFp'] and self::exceedsMax($rec['accessPath'])) {
			self::flushHost($host);
			self::rotateTo($rec['accessPath'], $rec['accessFp'],
				self::rotatedPath($rec['dir'], $rec['accessName'], $stamp));
			self::$hosts[$host]['accessFp'] = self::openLog(
				$rec['accessPath'], $rec['fileMode']
			);
		}
		if ($rec['errorFp'] and self::exceedsMax($rec['errorPath'])) {
			self::rotateTo($rec['errorPath'], $rec['errorFp'],
				self::rotatedPath($rec['dir'], $rec['errorName'], $stamp));
			self::$hosts[$host]['errorFp'] = self::openLog(
				$rec['errorPath'], $rec['fileMode']
			);
		}
	}

	/**
	 * Where a log rotates to: the date goes before the .log, so the
	 * rotated file sorts next to the live one and still ends in .log,
	 * which is what archiveAndPrune() globs for.
	 *
	 * @method rotatedPath
	 * @static
	 * @private
	 * @param {string} $dir The directory the log lives in
	 * @param {string} $name The live log's filename
	 * @param {string} $stamp A date, or a date and time for a mid-day rotation
	 * @return {string}
	 */
	private static function rotatedPath($dir, $name, $stamp)
	{
		$stem = substr($name, -4) === '.log' ? substr($name, 0, -4) : $name;
		return "$dir/$stem.$stamp.log";
	}

	/**
	 * Open the log files of every virtual host configured to have its own.
	 *
	 * The hosts are the ones already declared under Q/webserver/hosts (or
	 * domains) for their document root -- a host logs to its own files by
	 * adding a `log` key to that same entry, so there is one list of
	 * domains and not two:
	 *
	 *   "hosts": {
	 *     "a.example.com": { "root": "/srv/a", "log": true },
	 *     "b.example.com": { "root": "/srv/b", "log": {
	 *       "dir": "/var/log/b", "accessName": "web.log"
	 *     }}
	 *   }
	 *
	 * `log: true` names the files after the host -- a.example.com-access.log
	 * and a.example.com-error.log, in the server's own log directory. The
	 * object form overrides the directory, either filename, or all three.
	 *
	 * A host whose files would land on the server's own access or error log
	 * is skipped with a warning: two handles appending to one file rotate
	 * against each other, and the second rotation throws away what the first
	 * had just moved.
	 *
	 * @method initHosts
	 * @static
	 * @private
	 * @param {boolean} $accessEnabled Whether access logging is on at all
	 * @param {boolean} $errorEnabled Whether error logging is on at all
	 */
	private static function initHosts($accessEnabled, $errorEnabled)
	{
		self::$hosts = array();
		$configured = Q_Config::get('Q', 'webserver', 'hosts', array());
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		if (is_array($domains) and is_array($configured)) {
			$configured = array_merge($domains, $configured);
		} elseif (is_array($domains)) {
			$configured = $domains;
		}
		if (!is_array($configured)) return;

		foreach ($configured as $host => $conf) {
			if (!is_array($conf) or !isset($conf['log'])) continue;
			$logConf = $conf['log'];
			if ($logConf === false or $logConf === null) continue;
			if (!is_array($logConf)) $logConf = array();

			$key = self::hostKey($host);
			if ($key === '') continue;
			$stem = self::hostStem($key);

			// A host may be stricter than the server, and inherits when
			// it says nothing.
			$fileMode = Q_WebServer_Modes::parse(
				Q::ifset($logConf, 'fileMode', null), self::$fileMode
			);
			$dirExplicit = isset($logConf['dirMode'])
				? true
				: self::$dirModeExplicit;
			$dirMode = Q_WebServer_Modes::parse(
				Q::ifset($logConf, 'dirMode', null), self::$dirMode
			);
			$dir = isset($logConf['dir'])
				? self::resolveDir($logConf['dir'], $dirMode, $dirExplicit)
				: self::$dir;
			$accessName = self::sanitizeName(
				Q::ifset($logConf, 'accessName', null), "$stem-access.log"
			);
			$errorName = self::sanitizeName(
				Q::ifset($logConf, 'errorName', null), "$stem-error.log"
			);
			$accessPath = $dir . '/' . $accessName;
			$errorPath = $dir . '/' . $errorName;

			if ($accessPath === self::$accessPath or $errorPath === self::$errorPath) {
				fwrite(STDERR, "  log: $key would write to the server's own"
					. " log file, skipping its vhost log\n");
				continue;
			}

			self::$hosts[$key] = array(
				'dir' => $dir,
				'accessName' => $accessName,
				'errorName' => $errorName,
				'accessPath' => $accessPath,
				'errorPath' => $errorPath,
				'fileMode' => $fileMode,
				'accessFp' => $accessEnabled
					? self::openLog($accessPath, $fileMode) : null,
				'errorFp' => $errorEnabled
					? self::openLog($errorPath, $fileMode) : null,
				'buf' => '',
			);
		}
	}

	/**
	 * A Host header, or a configured host, as the key both sides agree on:
	 * lower case and without the port. Matches how WebServer resolves a
	 * request to a virtual host, so a request cannot land on one host's
	 * document root and the other host's log.
	 *
	 * @method hostKey
	 * @static
	 * @private
	 * @param {string} $host
	 * @return {string} '' when there is nothing usable
	 */
	private static function hostKey($host)
	{
		if (!is_string($host) or $host === '') return '';
		return strtolower(preg_replace('/:\d+$/', '', trim($host)));
	}

	/**
	 * A hostname as the stem of a filename: what it is called, minus
	 * anything that has a meaning in a path. A wildcard host therefore
	 * becomes a name rather than a glob.
	 *
	 * @method hostStem
	 * @static
	 * @private
	 * @param {string} $host An already normalised host key
	 * @return {string}
	 */
	private static function hostStem($host)
	{
		$stem = preg_replace('/[^a-z0-9._-]+/', '-', $host);
		$stem = trim($stem, '.-');
		return $stem === '' ? 'vhost' : $stem;
	}

	/**
	 * A log directory as an absolute path, created if it is not there.
	 * A relative path is read against the application, not against
	 * whatever directory the server happens to have been started from.
	 *
	 * @method resolveDir
	 * @static
	 * @private
	 * @param {string} $dir
	 * @param {integer|null} [$mode=null] Mode for the directory
	 * @param {boolean} [$explicit=false] Whether $mode came from config
	 * @return {string}
	 */
	private static function resolveDir($dir, $mode = null, $explicit = false)
	{
		if ($dir === '' or $dir[0] !== '/') {
			$base = defined('APP_DIR') ? APP_DIR : getcwd();
			$dir = $base . '/' . $dir;
		}
		Q_WebServer_Modes::dir($dir, $mode, $explicit);
		return rtrim($dir, '/');
	}

	/**
	 * Open a log for appending, at the configured mode.
	 *
	 * Every log file this class opens goes through here -- the two at
	 * startup, the two each rotation replaces, and each host's pair --
	 * so a mode set in config reaches all of them and not just the ones
	 * created at startup.
	 *
	 * @method openLog
	 * @static
	 * @private
	 * @param {string} $path
	 * @param {integer|null} $mode
	 * @return {resource|false}
	 */
	private static function openLog($path, $mode)
	{
		return Q_WebServer_Modes::open($path, 'a', $mode);
	}

	/**
	 * A log filename out of config, or the default when it is not one.
	 *
	 * Only a bare filename is accepted -- no directory part, and not
	 * . or .. -- so a name out of config writes inside `dir` and
	 * nowhere else.
	 *
	 * @method sanitizeName
	 * @static
	 * @private
	 * @param {string} $name The configured name, or null
	 * @param {string} $default Used when $name is not a bare filename
	 * @return {string}
	 */
	private static function sanitizeName($name, $default)
	{
		if (!is_string($name)) return $default;
		$name = trim($name);
		if ($name === '' or $name === '.' or $name === '..') return $default;
		if (strpbrk($name, "/\\") !== false) return $default;
		if (strpos($name, "\0") !== false) return $default;
		return $name;
	}

	private static function rotateTo($currentPath, &$fp, $targetPath)
	{
		// No chmod here on purpose: rename() keeps the inode, so the
		// rotated file carries the mode it already had, and the fresh
		// live file that replaces it is opened through openLog().
		if ($fp) @fclose($fp);
		if (file_exists($currentPath) && filesize($currentPath) > 0) {
			@rename($currentPath, $targetPath);
		}
	}

	private static function exceedsMax($path)
	{
		if (!file_exists($path)) return false;
		clearstatcache(true, $path);
		return filesize($path) > self::$maxSize;
	}

	/**
	 * Compress old logs to .gz, delete expired ones.
	 * @method archiveAndPrune
	 * @static
	 */
	static function archiveAndPrune()
	{
		if (!self::$dir || !is_dir(self::$dir)) return;

		$now = time();
		$archiveCutoff = $now - (self::$archiveAfterDays * 86400);
		$deleteCutoff = $now - (self::$deleteAfterDays * 86400);

		// A virtual host may log to its own directory, and each directory
		// has its own set of live files that must survive the sweep.
		foreach (self::liveNames() as $dir => $live) {
		if (!is_dir($dir)) continue;
		foreach (glob($dir . '/*.log') as $file) {
			$base = basename($file);
			if (in_array($base, $live, true)) continue;
			$mtime = filemtime($file);
			if ($mtime < $deleteCutoff) { @unlink($file); continue; }
			if ($mtime < $archiveCutoff && function_exists('gzopen')) {
				$gzPath = $file . '.gz';
				if (!file_exists($gzPath)) {
					$in = fopen($file, 'rb');
					$out = Q_WebServer_Modes::gzopen(
						$gzPath, 'wb9', self::fileModeFor($dir)
					);
					if ($in && $out) {
						while (!feof($in)) gzwrite($out, fread($in, 65536));
						fclose($in); gzclose($out);
						@unlink($file); @touch($gzPath, $mtime);
					} else {
						if ($in) fclose($in);
						if ($out) gzclose($out);
					}
				}
			}
		}

		foreach (glob($dir . '/*.log.gz') as $file) {
			if (filemtime($file) < $deleteCutoff) @unlink($file);
		}
		}
	}

	/**
	 * The file mode that applies in one log directory.
	 *
	 * A gzipped log is still that log, so it gets the mode of the files
	 * it sits among rather than whatever the umask says. Where several
	 * hosts share a directory and disagree, the bits common to both are
	 * used -- never wider than either host asked for.
	 *
	 * @method fileModeFor
	 * @static
	 * @private
	 * @param {string} $dir
	 * @return {integer|null}
	 */
	private static function fileModeFor($dir)
	{
		$mode = ($dir === self::$dir) ? self::$fileMode : null;
		foreach (self::$hosts as $rec) {
			if ($rec['dir'] !== $dir or $rec['fileMode'] === null) continue;
			$mode = ($mode === null) ? $rec['fileMode'] : ($mode & $rec['fileMode']);
		}
		return $mode;
	}

	/**
	 * The live log files, grouped by the directory they sit in.
	 *
	 * These are the files being written right now. archiveAndPrune() must
	 * not gzip or delete one: the handle would go on pointing at a file
	 * that is no longer there, and the lines would vanish.
	 *
	 * @method liveNames
	 * @static
	 * @private
	 * @return {array} dir => array of filenames
	 */
	private static function liveNames()
	{
		$live = array(
			self::$dir => array(self::$accessName, self::$errorName)
		);
		foreach (self::$hosts as $rec) {
			if (!isset($live[$rec['dir']])) $live[$rec['dir']] = array();
			$live[$rec['dir']][] = $rec['accessName'];
			$live[$rec['dir']][] = $rec['errorName'];
		}
		return $live;
	}

	/**
	 * Log stats for the dashboard.
	 * @method stats
	 * @static
	 */
	static function stats()
	{
		if (!self::$enabled) return null;
		$accessSize = self::$accessPath && file_exists(self::$accessPath)
			? filesize(self::$accessPath) : 0;
		$errorSize = self::$errorPath && file_exists(self::$errorPath)
			? filesize(self::$errorPath) : 0;
		$archives = self::$dir ? count(glob(self::$dir . '/*.gz')) : 0;
		$hosts = array();
		foreach (self::$hosts as $host => $rec) {
			$hosts[$host] = array(
				'dir' => $rec['dir'],
				'accessName' => $rec['accessName'],
				'errorName' => $rec['errorName'],
				'accessSize' => file_exists($rec['accessPath'])
					? filesize($rec['accessPath']) : 0,
				'errorSize' => file_exists($rec['errorPath'])
					? filesize($rec['errorPath']) : 0,
				'bufferBytes' => strlen($rec['buf']),
			);
		}
		return array(
			'dir' => self::$dir,
			'fileMode' => self::$fileMode === null
				? null : decoct(self::$fileMode),
			'dirMode' => self::$dirModeExplicit
				? decoct(self::$dirMode) : null,
			'accessSize' => $accessSize,
			'errorSize' => $errorSize,
			'archives' => $archives,
			'bufferBytes' => strlen(self::$accessBuf),
			'hosts' => $hosts,
		);
	}

	static function shutdown()
	{
		self::flush();
		if (self::$accessFp) { @fclose(self::$accessFp); self::$accessFp = null; }
		if (self::$errorFp) { @fclose(self::$errorFp); self::$errorFp = null; }
		foreach (self::$hosts as $host => $rec) {
			if ($rec['accessFp']) @fclose($rec['accessFp']);
			if ($rec['errorFp']) @fclose($rec['errorFp']);
			self::$hosts[$host]['accessFp'] = null;
			self::$hosts[$host]['errorFp'] = null;
		}
	}
}
