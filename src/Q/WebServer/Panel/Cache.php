<?php
/**
 * @module Q
 */

/**
 * The control panel's Cache tab: what the response cache (Q_WebServer_Cache)
 * is doing, its settings, and the things one does to it -- clear, purge,
 * warm -- behind the panel's /Q/api/cache routes.
 *
 *   GET  cache                 stats, effective settings, where each came from, warnings
 *   GET  cache/entries?q=&limit=  stored entries, newest first, never a body
 *   POST cache/settings {...}  validate, apply now, keep in the panel store
 *   POST cache/purge {url} | {pattern}
 *   POST cache/clear           start a new generation (the safe "clear everything")
 *   POST cache/warm {url}      render one path of this server again, in the background
 *
 * Settings saved here are kept in the panel store (acl/panel.json, key
 * "cache", shaped like Q.web.cache) and win over the configuration file:
 * applySaved() lays them over Q.web.cache when the server starts, before
 * Q_WebServer_Cache::init() reads it, and save() does the same at once. A
 * value saved as null goes back to what the configuration file says.
 *
 * The panel is answered in the server's own process, the one that answers
 * from the cache, so re-running init() there is what makes a change live.
 *
 * @class Q_WebServer_Panel_Cache
 * @static
 */
class Q_WebServer_Panel_Cache
{
	/** The panel store's key for the saved settings. */
	const STORE_KEY = 'cache';

	/** Most entries one cache/entries answer lists. */
	const ENTRIES_MAX = 500;

	/** Most files one cache/entries answer reads; it says when it stopped early. */
	const SCAN_MAX = 5000;

	/** Most bytes read from the front of an entry to find its metadata line. */
	const META_MAX = 65536;

	/** Warm results kept for the tab to show. */
	const WARM_KEEP = 20;

	/**
	 * What may be saved, by the name the API and the tab use: type and range.
	 * Ranges are wide enough for any real site and narrow enough that a typo
	 * (an extra zero or three) is refused rather than obeyed.
	 * @return {array} key => array(type, min, max)
	 */
	static function spec()
	{
		return array(
			'enabled'              => array('bool'),
			'defaultTtl'           => array('int', 0, 31536000),     // a year
			'staleWhileRevalidate' => array('int', 0, 86400),        // a day
			'negativeTtl'          => array('int', 0, 86400),
			'skip.cookies'         => array('list', 0, 50),
			'apcu.enabled'         => array('bool'),
			'apcu.maxSize'         => array('int', 1024, 67108864),  // 1 KB .. 64 MB
			'memory.maxEntries'    => array('int', 0, 1000000),
			'memory.maxBytes'      => array('int', 0, 8589934592),   // 8 GB
			'minifyHtml'           => array('bool'),
		);
	}

	/** The values Q_WebServer_Cache::init() uses when a setting is absent. */
	static function defaults()
	{
		return array(
			'enabled' => false, 'defaultTtl' => 0, 'staleWhileRevalidate' => 0,
			'negativeTtl' => 0, 'skip.cookies' => array('Q_sid', 'PHPSESSID'),
			'apcu.enabled' => null, 'apcu.maxSize' => 65536,
			'memory.maxEntries' => 0, 'memory.maxBytes' => 33554432, 'minifyHtml' => false,
		);
	}

	/**
	 * @var array what Q.web.cache held for a key before the panel's value was
	 * laid over it, so a value saved as null can go back to it. A key maps to
	 * array(true, value) when it was set, array(false) when it was absent.
	 */
	static $original = array();

	/** @var callable|null for tests: persist(array $saved) -> bool, instead of the panel store */
	static $persist = null;

	/** @var callable|null for tests: load() -> array, instead of the panel store */
	static $load = null;

	/** @var callable|null for tests: fetch($path, $host) -> array(status, cached), instead of a local HTTP request */
	static $warmer = null;

	/** @var bool warm in a forked child (the server must never request itself in its own loop) */
	static $background = true;

	// ── Settings ────────────────────────────────────────────────────────

	/** A dotted name as a Q.web.cache path. */
	private static function path($key)
	{
		return array_merge(array('Q', 'web', 'cache'), explode('.', $key));
	}

	/** Q.web.cache's value for a dotted name, and whether it is set at all. */
	private static function configValue($key, &$isSet = null)
	{
		$missing = "\0missing";
		$v = call_user_func_array(array('Q_Config', 'get'), array_merge(self::path($key), array($missing)));
		$isSet = ($v !== $missing);
		return $isSet ? $v : null;
	}

	/**
	 * The settings in force now, as Q_WebServer_Cache::init() reads them.
	 * apcu.enabled is null when it is left to the server ("use it if it works").
	 * @return {array}
	 */
	static function effective()
	{
		$out = array();
		foreach (self::defaults() as $key => $default) {
			$v = self::configValue($key, $isSet);
			if (!$isSet) $v = $default;
			$type = self::spec()[$key][0];
			if ($type === 'bool') $out[$key] = $v === null ? null : (bool) $v;
			elseif ($type === 'int') $out[$key] = (int) $v;
			else $out[$key] = array_values(array_map('strval', (array) $v));
		}
		return $out;
	}

	/** The saved settings, flattened to dotted names; unknown keys dropped. */
	static function saved()
	{
		if (self::$load) {
			$nested = call_user_func(self::$load);
		} else {
			$nested = class_exists('Q_WebServer_Panel_Store')
				? Q_WebServer_Panel_Store::setting(self::STORE_KEY, array()) : array();
		}
		$flat = self::flatten(is_array($nested) ? $nested : array());
		$errors = array();
		// What the file holds is checked as a request would be: a hand edit
		// that does not pass is ignored, never applied half-understood.
		return self::clean($flat, $errors, false);
	}

	/** array('apcu' => array('enabled' => true)) -> array('apcu.enabled' => true), for known keys. */
	static function flatten(array $nested)
	{
		$flat = array();
		foreach (array_keys(self::spec()) as $key) {
			$node = $nested; $found = true;
			foreach (explode('.', $key) as $part) {
				if (!is_array($node) or !array_key_exists($part, $node)) { $found = false; break; }
				$node = $node[$part];
			}
			if ($found) $flat[$key] = $node;
		}
		return $flat;
	}

	/** The reverse of flatten(). */
	static function nest(array $flat)
	{
		$nested = array();
		foreach ($flat as $key => $v) {
			$ref = &$nested;
			$parts = explode('.', $key);
			$last = array_pop($parts);
			foreach ($parts as $p) {
				if (!isset($ref[$p]) or !is_array($ref[$p])) $ref[$p] = array();
				$ref = &$ref[$p];
			}
			$ref[$last] = $v;
			unset($ref);
		}
		return $nested;
	}

	/**
	 * Check a set of settings. Unknown names, wrong types and values out of
	 * range are each reported; null (remove the panel's value) is allowed
	 * where $allowNull.
	 * @param {array} $in dotted name => value
	 * @param {array} &$errors name => reason
	 * @param {boolean} $allowNull
	 * @return {array} the values, normalised; the ones that failed are left out
	 */
	static function clean(array $in, array &$errors, $allowNull = true)
	{
		$spec = self::spec();
		$out = array();
		foreach ($in as $key => $v) {
			$key = (string) $key;
			if (!isset($spec[$key])) { $errors[$key] = 'is not a cache setting'; continue; }
			if ($v === null) {
				if ($allowNull) $out[$key] = null;
				else $errors[$key] = 'cannot be empty';
				continue;
			}
			$s = $spec[$key];
			switch ($s[0]) {
				case 'bool':
					if (is_bool($v)) $out[$key] = $v;
					elseif ($v === 0 or $v === 1) $out[$key] = (bool) $v;
					else $errors[$key] = 'must be true or false';
					break;
				case 'int':
					if (is_string($v) and preg_match('/^\d{1,12}$/', $v)) $v = (int) $v;
					if (is_float($v) and floor($v) == $v and abs($v) < 1e15) $v = (int) $v;
					if (!is_int($v)) { $errors[$key] = 'must be a whole number'; break; }
					if ($v < $s[1] or $v > $s[2]) { $errors[$key] = "must be between {$s[1]} and {$s[2]}"; break; }
					$out[$key] = $v;
					break;
				case 'list':
					if (!is_array($v) or ($v !== array() and array_keys($v) !== range(0, count($v) - 1))) {
						$errors[$key] = 'must be a list of cookie names'; break;
					}
					if (count($v) > $s[2]) { $errors[$key] = "may name at most {$s[2]} cookies"; break; }
					$names = array(); $bad = array();
					foreach ($v as $name) {
						$name = is_string($name) ? trim($name) : $name;
						// A cookie name is an HTTP token: no spaces, separators or controls.
						if (!is_string($name) or !preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,128}$/', $name)) {
							$bad[] = is_string($name) ? $name : gettype($name); continue;
						}
						if (!in_array($name, $names, true)) $names[] = $name;
					}
					if ($bad) { $errors[$key] = 'not a cookie name: ' . implode(', ', array_slice($bad, 0, 5)); break; }
					$out[$key] = $names;
					break;
			}
		}
		return $out;
	}

	/**
	 * Lay settings over Q.web.cache (null: back to the configuration file's
	 * value), remembering the file's value the first time a key is touched.
	 * @param {array} $values dotted name => value or null
	 * @param {boolean} $reinit re-run Q_WebServer_Cache::init() so they take effect
	 */
	static function apply(array $values, $reinit = true)
	{
		foreach ($values as $key => $v) {
			if (!isset(self::spec()[$key])) continue;
			$path = self::path($key);
			if (!array_key_exists($key, self::$original)) {
				$cur = self::configValue($key, $isSet);
				self::$original[$key] = $isSet ? array(true, $cur) : array(false);
			}
			if ($v === null) {
				$o = self::$original[$key];
				if ($o[0]) call_user_func_array(array('Q_Config', 'set'), array_merge($path, array($o[1])));
				else call_user_func_array(array('Q_Config', 'clear'), $path);
			} else {
				call_user_func_array(array('Q_Config', 'set'), array_merge($path, array($v)));
			}
		}
		if ($reinit and class_exists('Q_WebServer_Cache')) Q_WebServer_Cache::init();
	}

	/**
	 * At server start: lay what the panel saved over the configuration
	 * file, before Q_WebServer_Cache::init() reads it. Nothing saved, or a
	 * store that fails its trust rule, changes nothing.
	 * @return {array} what was applied
	 */
	static function applySaved()
	{
		$saved = self::saved();
		if ($saved) self::apply($saved, false);
		return $saved;
	}

	/** Where each setting's value comes from: panel, config or default. */
	static function sources()
	{
		$saved = self::saved();
		$out = array();
		foreach (array_keys(self::spec()) as $key) {
			if (array_key_exists($key, $saved)) { $out[$key] = 'panel'; continue; }
			if (array_key_exists($key, self::$original)) { $out[$key] = self::$original[$key][0] ? 'config' : 'default'; continue; }
			self::configValue($key, $isSet);
			$out[$key] = $isSet ? 'config' : 'default';
		}
		return $out;
	}

	/**
	 * POST cache/settings: validate, keep, apply.
	 * @param {array} $body dotted name => value (null: back to the configuration file)
	 * @return {array} saved, changes (name => from, to), settings, sources, warnings; or status and error
	 */
	static function save(array $body)
	{
		unset($body['confirm']);
		if (!$body) return array('status' => 400, 'error' => 'Nothing to save: send one or more cache settings.');
		$errors = array();
		$clean = self::clean($body, $errors, true);
		if ($errors) {
			$lines = array();
			foreach ($errors as $k => $why) $lines[] = "$k $why";
			return array('status' => 400, 'error' => 'Not saved: ' . implode('; ', $lines), 'errors' => $errors);
		}
		$before = self::effective();
		$saved = self::saved();
		foreach ($clean as $k => $v) {
			if ($v === null) unset($saved[$k]); else $saved[$k] = $v;
		}
		if (!self::persist($saved)) {
			$p = class_exists('Q_WebServer_Panel_Store') ? Q_WebServer_Panel_Store::problem() : null;
			return array('status' => 503, 'error' => 'The panel store refused the change'
				. ($p ? ': ' . Q_WebServer_Panel_Store::problemMessage($p) : ''));
		}
		self::apply($clean, true);
		$after = self::effective();
		$changes = array();
		foreach ($after as $k => $v) {
			if ($before[$k] !== $v) $changes[$k] = array('from' => $before[$k], 'to' => $v);
		}
		return array('saved' => true, 'changes' => (object) $changes, 'settings' => $after,
			'sources' => self::sources(), 'warnings' => self::warnings(),
			'note' => $changes ? 'Saved and in effect now. Kept by the panel, so it survives a restart.'
				: 'Saved. Nothing changed: those were already the values in force.');
	}

	/** Write the saved settings: the whole "cache" key, nested like Q.web.cache. */
	private static function persist(array $saved)
	{
		$nested = self::nest($saved);
		if (self::$persist) return (bool) call_user_func(self::$persist, $nested);
		if (!class_exists('Q_WebServer_Panel_Store')) return false;
		return (bool) Q_WebServer_Panel_Store::aclUpdate(function (array $config) use ($nested) {
			if ($nested) $config[self::STORE_KEY] = $nested;
			else unset($config[self::STORE_KEY]);
			return $config;
		});
	}

	// ── Status ──────────────────────────────────────────────────────────

	/**
	 * What is wrong or worth knowing, each with the fix in plain words.
	 * @return {array} list of array(level: error|warn|info, title, fix)
	 */
	static function warnings()
	{
		$out = array();
		$C = 'Q_WebServer_Cache';
		foreach ((array) $C::$apcuWarnings as $w) {
			$w = (string) $w;
			if (strpos($w, 'enable_cli') !== false) {
				$out[] = array('level' => 'error', 'title' => 'APCu is switched off in this server process, so cached pages are read from disk.',
					'fix' => 'Set apc.enable_cli=1 in php.ini (or start PHP with -d apc.enable_cli=1), then restart the server.');
			} elseif (strpos($w, 'not loaded') !== false) {
				$out[] = array('level' => 'error', 'title' => 'APCu is turned on here, but the APCu extension is not installed.',
					'fix' => 'Install the APCu extension for this PHP and restart the server, or turn APCu off here.');
			} elseif (strpos($w, 'use_request_time') !== false) {
				$out[] = array('level' => 'warn', 'title' => 'APCu measures lifetimes from when the server started, so pages expire too early.',
					'fix' => 'Set apc.use_request_time=0 in php.ini and restart the server.');
			} elseif (strpos($w, 'shm_size') !== false) {
				$out[] = array('level' => 'warn', 'title' => 'The largest page allowed in APCu is as big as all of APCu\'s memory.',
					'fix' => 'Lower "Largest page kept in APCu", or raise apc.shm_size in php.ini.');
			} else {
				$out[] = array('level' => 'warn', 'title' => $w, 'fix' => '');
			}
		}
		if (!$C::$enabled) {
			$out[] = array('level' => 'info', 'title' => 'The cache is off: every page is rendered on every request.',
				'fix' => 'Turn it on with the Cache switch above.');
		} elseif (!$C::$dir) {
			$out[] = array('level' => 'error', 'title' => 'The cache has no directory to keep pages in.',
				'fix' => 'Set Q.web.cache.dir in the configuration file, or run the server with an application directory.');
		} elseif ($C::$defaultTtl <= 0) {
			$out[] = array('level' => 'info', 'title' => 'Pages are kept only when the application says how long (Cache-Control: max-age).',
				'fix' => 'Pick a page lifetime below to keep every cacheable page for that long.');
		}
		return $out;
	}

	/** GET cache */
	static function status()
	{
		$C = 'Q_WebServer_Cache';
		return array(
			'stats' => $C::stats(),
			'settings' => self::effective(),
			'sources' => self::sources(),
			'warnings' => self::warnings(),
			'dir' => $C::$dir,
			'generation' => $C::generation(),
			'refreshHeader' => self::refreshHeader(),
			'limits' => self::limits(),
			'warm' => self::warmResults(),
			'time' => time(),
		);
	}

	/** The ranges, for the tab's inputs. */
	static function limits()
	{
		$out = array();
		foreach (self::spec() as $k => $s) if ($s[0] === 'int') $out[$k] = array('min' => $s[1], 'max' => $s[2]);
		return $out;
	}

	private static function refreshHeader()
	{
		return strtolower((string) Q_Config::get('Q', 'web', 'cache', 'refreshHeader', 'x-cache-refresh'));
	}

	// ── Entries ─────────────────────────────────────────────────────────

	/**
	 * GET cache/entries: what the disk store holds (it holds every entry;
	 * APCu and memory hold copies). Only each file's metadata line is read,
	 * never a body; at most SCAN_MAX files are looked at.
	 * @param {string} $q part of the URL to match, case-insensitive
	 * @param {int} $limit at most ENTRIES_MAX
	 * @return {array} entries (url, coding, size, age, ttl, expired, cleared, heldIn), matched, scanned, truncated
	 */
	static function entries($q = '', $limit = 100)
	{
		$C = 'Q_WebServer_Cache';
		$limit = max(1, min(self::ENTRIES_MAX, (int) $limit));
		$q = substr((string) $q, 0, 200);
		$dir = $C::$dir;
		$out = array('entries' => array(), 'matched' => 0, 'scanned' => 0, 'truncated' => false, 'limit' => $limit);
		if (!$C::$enabled or !$dir or !is_dir($dir)) return $out;
		$now = time();
		$generation = $C::generation();
		$rows = array();
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (!$f->isFile() or $f->getExtension() !== 'json') continue;
			if ($out['scanned'] >= self::SCAN_MAX) { $out['truncated'] = true; break; }
			$out['scanned']++;
			$meta = self::readMeta($f->getPathname(), $bodySize);
			if ($meta === null) continue;
			$url = (string) ($meta['url'] ?? '');
			if ($url === '') continue;
			if ($q !== '' and stripos($url, $q) === false) continue;
			$stored = (int) ($meta['stored'] ?? 0);
			$expires = (int) ($meta['expires'] ?? 0);
			$coding = '';
			foreach ((array) ($meta['headers'] ?? array()) as $n => $v) {
				if (strcasecmp((string) $n, 'Content-Encoding') === 0) { $coding = strtolower((string) $v); break; }
			}
			$key = (string) ($meta['key'] ?? basename($f->getPathname(), '.json'));
			$rows[] = array(
				'url' => $url,
				'status' => (int) ($meta['status'] ?? 200),
				'coding' => $coding === '' ? 'plain' : $coding,
				'size' => $bodySize,
				'stored' => $stored,
				'age' => $stored > 0 ? max(0, $now - $stored) : null,
				'ttl' => $expires > 0 ? $expires - $now : null,
				'expired' => $expires > 0 and $expires <= $now,
				'cleared' => $generation > 0 and $stored <= $generation,
				'key' => $key,
			);
		}
		$out['matched'] = count($rows);
		usort($rows, function ($a, $b) { return $b['stored'] <=> $a['stored']; });
		$rows = array_slice($rows, 0, $limit);
		foreach ($rows as &$r) {
			$held = $C::heldIn($r['key']);
			$r['heldIn'] = array_keys(array_filter($held));
			unset($r['key']);
		}
		unset($r);
		$out['entries'] = $rows;
		return $out;
	}

	/**
	 * An entry file's metadata, from its first line, and the size of the
	 * body after it. An entry in the older one-JSON format is read whole only
	 * when small; otherwise it is skipped.
	 */
	private static function readMeta($file, &$bodySize)
	{
		$bodySize = 0;
		$h = @fopen($file, 'rb');
		if (!$h) return null;
		$line = fgets($h, self::META_MAX);
		fclose($h);
		if ($line === false) return null;
		$total = (int) @filesize($file);
		$meta = json_decode(rtrim($line, "\n"), true);
		if (is_array($meta) and isset($meta['v'])) {
			$bodySize = max(0, $total - strlen($line));
			return $meta;
		}
		if ($total > 262144) return null;
		$entry = json_decode((string) @file_get_contents($file), true);
		if (!is_array($entry)) return null;
		$bodySize = strlen((string) ($entry['body'] ?? ''));
		unset($entry['body']);
		return $entry;
	}

	// ── Actions ─────────────────────────────────────────────────────────

	/** A path on this server: starts with one "/", no scheme or host, no controls. */
	static function validPath($url)
	{
		return is_string($url) and $url !== '' and strlen($url) <= 2048
			and $url[0] === '/' and substr($url, 0, 2) !== '//'
			and strpos($url, '\\') === false
			and !preg_match('/[\x00-\x20\x7f]/', $url);
	}

	/**
	 * POST cache/purge: {url} removes that exact path (with its query, as
	 * stored), {pattern} every URL a regular expression matches.
	 * @return {array} removed, and url or pattern; or status and error
	 */
	static function purge(array $body)
	{
		$C = 'Q_WebServer_Cache';
		$url = $body['url'] ?? null;
		$pattern = $body['pattern'] ?? null;
		if (($url === null) === ($pattern === null)) {
			return array('status' => 400, 'error' => 'Send either url (one page, e.g. /about) or pattern (a regular expression, e.g. #^/blog/#).');
		}
		if (!$C::$enabled) return array('status' => 409, 'error' => 'The cache is off, so there is nothing to purge.');
		if ($url !== null) {
			if (!self::validPath($url)) return array('status' => 400, 'error' => 'The page must be a path on this server, starting with / (for example /about).');
			return array('purged' => true, 'url' => $url, 'removed' => (int) $C::purge($url, false));
		}
		if (!is_string($pattern) or $pattern === '' or strlen($pattern) > 500) {
			return array('status' => 400, 'error' => 'The pattern must be a regular expression of at most 500 characters, e.g. #^/blog/#.');
		}
		if (@preg_match($pattern, '') === false) {
			return array('status' => 400, 'error' => 'That is not a valid regular expression. Wrap it in delimiters, e.g. #^/blog/#.');
		}
		return array('purged' => true, 'pattern' => $pattern, 'removed' => (int) $C::purge($pattern, true));
	}

	/** POST cache/clear: a new generation. Nothing is deleted; every stored page becomes a miss. */
	static function clear()
	{
		$C = 'Q_WebServer_Cache';
		if (!$C::$enabled) return array('status' => 409, 'error' => 'The cache is off, so there is nothing to clear.');
		if (!$C::bumpGeneration()) {
			return array('status' => 500, 'error' => 'Could not touch the generation marker'
				. ($C::$generationFile !== '' ? ' (' . $C::$generationFile . ')' : '') . '; check that the cache directory is writable.');
		}
		return array('cleared' => true, 'generation' => $C::generation(),
			'note' => 'Every stored page is now out of date and is rendered afresh on its next request, within a second. Nothing was deleted; old files are swept later.');
	}

	/**
	 * POST cache/warm {url[, host]}: render one path of this server again and
	 * store it, by requesting it from the server with the refresh header --
	 * twice, compressed and not, since those are two entries. Only a path;
	 * never another host or a full URL. In a forked child, because the server
	 * would otherwise wait on itself.
	 * @param {array} $body
	 * @param {string} $panelHost the Host the panel was reached on (the default host)
	 * @return {array} warming (202) with url and host; or status and error
	 */
	static function warm(array $body, $panelHost = '')
	{
		$C = 'Q_WebServer_Cache';
		$url = $body['url'] ?? null;
		if (!self::validPath($url)) {
			return array('status' => 400, 'error' => 'Warm takes a path on this server, starting with / (for example /about), not a full address.');
		}
		if ($C::isServerPath($url)) return array('status' => 400, 'error' => 'The server\'s own /Q/ pages are never cached.');
		if (!$C::$enabled) return array('status' => 409, 'error' => 'The cache is off, so there is nothing to warm.');
		$host = isset($body['host']) ? (string) $body['host'] : (string) $panelHost;
		$host = strtolower(trim($host));
		if ($host === '' or !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::\d{1,5})?$|^\[[0-9a-f:.]+\](?::\d{1,5})?$/', $host)) {
			return array('status' => 400, 'error' => 'The host name is not valid.');
		}
		$work = function () use ($url, $host) {
			$results = array();
			foreach (array('gzip', '') as $enc) {
				$results[$enc === '' ? 'plain' : $enc] = self::$warmer
					? call_user_func(self::$warmer, $url, $host, $enc)
					: self::fetch($url, $host, $enc);
			}
			self::recordWarm($url, $host, $results);
			return $results;
		};
		if (!self::$background) {
			$results = $work();
			return array('warmed' => true, 'url' => $url, 'host' => $host, 'results' => $results);
		}
		$pid = (class_exists('Q_WebServer_Fork') and Q_WebServer_Fork::available()) ? Q_WebServer_Fork::fork() : -1;
		if ($pid === 0) {
			try { $work(); } catch (\Throwable $e) {}
			if (function_exists('posix_kill')) posix_kill(getmypid(), SIGKILL);
			exit(0);
		}
		if ($pid < 0 or $pid === false) {
			return array('status' => 501, 'error' => 'Warming needs process forking (pcntl), which this PHP does not have.');
		}
		return array('status' => 202, 'warming' => true, 'url' => $url, 'host' => $host,
			'note' => 'Rendering in the background; the result shows here in a moment.');
	}

	/**
	 * Request a path from this server with the refresh header. HTTP first;
	 * over HTTPS when the plain port redirects there or there is none.
	 * @return {array} status (0: no answer), cached (X-Cache said so), error
	 */
	static function fetch($url, $host, $encoding)
	{
		$W = 'Q_WebServer';
		$header = self::refreshHeader() ?: 'x-cache-refresh';
		$lines = array('Host: ' . $host, $header . ': 1', 'Connection: close', 'User-Agent: cache-warm');
		if ($encoding !== '') $lines[] = 'Accept-Encoding: ' . $encoding;
		$targets = array();
		$port = class_exists($W, false) ? (int) $W::$port : 0;
		$tls = (class_exists($W, false) and method_exists($W, 'httpsPort')) ? (int) $W::httpsPort() : 0;
		if ($port > 0) $targets[] = array('http', $port);
		if ($tls > 0) $targets[] = array('https', $tls);
		$last = array('status' => 0, 'error' => 'no port to reach this server on');
		foreach ($targets as $t) {
			list($scheme, $p) = $t;
			$ctx = stream_context_create(array(
				'http' => array('method' => 'GET', 'header' => implode("\r\n", $lines),
					'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30, 'protocol_version' => 1.1),
				'ssl' => array('verify_peer' => false, 'verify_peer_name' => false,
					'peer_name' => preg_replace('/:\d+$/', '', $host), 'SNI_enabled' => true),
			));
			$http_response_header = null;
			$body = @file_get_contents("$scheme://127.0.0.1:$p" . $url, false, $ctx);
			$status = 0; $cache = '';
			foreach ((array) $http_response_header as $h) {
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
				elseif (stripos($h, 'x-cache:') === 0) $cache = trim(substr($h, 8));
			}
			$last = array('status' => $status, 'scheme' => $scheme, 'bytes' => is_string($body) ? strlen($body) : 0);
			if ($status === 0) { $last['error'] = 'no answer'; continue; }
			if ($status >= 300 and $status < 400 and $scheme === 'http' and $tls > 0) continue;
			break;
		}
		return $last;
	}

	/** Where the recent warm results are kept: beside the entries. */
	private static function warmFile()
	{
		$dir = Q_WebServer_Cache::$dir;
		return $dir ? $dir . DIRECTORY_SEPARATOR . '.panel-warm' : '';
	}

	private static function recordWarm($url, $host, array $results)
	{
		$file = self::warmFile();
		if ($file === '') return;
		$h = @fopen($file, 'c+');
		if (!$h) return;
		@flock($h, LOCK_EX);
		$list = json_decode((string) stream_get_contents($h), true);
		if (!is_array($list)) $list = array();
		array_unshift($list, array('url' => $url, 'host' => $host, 'time' => time(), 'results' => $results));
		$list = array_slice($list, 0, self::WARM_KEEP);
		ftruncate($h, 0); rewind($h);
		fwrite($h, json_encode($list));
		@flock($h, LOCK_UN);
		fclose($h);
	}

	/** The recent warm results, newest first. */
	static function warmResults()
	{
		$file = self::warmFile();
		if ($file === '' or !is_file($file)) return array();
		$list = json_decode((string) @file_get_contents($file), true);
		return is_array($list) ? $list : array();
	}

	// ── The API ─────────────────────────────────────────────────────────

	/** A value from the request's query, parsed or as a string. */
	private static function query($parsed, $name)
	{
		$q = $parsed['query'] ?? array();
		if (is_string($q)) { $s = array(); parse_str($q, $s); $q = $s; }
		return isset($q[$name]) && is_string($q[$name]) ? $q[$name] : '';
	}

	/**
	 * Answer one cache/* route. Reads are GET; every change must be a POST
	 * with a JSON object body. The session, the default-password gate and
	 * the other checks have run before this (Q_WebServer_Panel::respond()).
	 * @param {string} $route cache, cache/entries, cache/settings, ...
	 * @param {array} $parsed
	 * @return {array}
	 */
	static function api($route, $parsed)
	{
		if (!class_exists('Q_WebServer_Cache')) return array('status' => 501, 'error' => 'The response cache is not part of this server.');
		$method = strtoupper((string) ($parsed['method'] ?? 'GET'));
		if ($route === 'cache') return self::status();
		if ($route === 'cache/entries') {
			$limit = self::query($parsed, 'limit');
			return self::entries(self::query($parsed, 'q'), $limit === '' ? 100 : (int) $limit);
		}
		if ($method !== 'POST') return array('status' => 405, 'error' => 'Use POST');
		$raw = (string) ($parsed['body'] ?? '');
		$body = trim($raw) === '' ? array() : json_decode($raw, true);
		if (!is_array($body)) return array('status' => 400, 'error' => 'The body must be a JSON object');
		switch ($route) {
			case 'cache/settings': return self::save($body);
			case 'cache/purge': return self::purge($body);
			case 'cache/clear': return self::clear();
			case 'cache/warm': return self::warm($body, (string) ($parsed['headers']['host'] ?? ''));
		}
		return array('status' => 404, 'error' => 'Unknown endpoint');
	}
}
