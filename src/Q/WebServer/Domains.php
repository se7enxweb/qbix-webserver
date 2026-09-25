<?php
/**
 * @module Q
 */
/**
 * The domains this server hosts: one record per domain in the control
 * panel's store (acl/panel.json, key "domains"), plus any declared in
 * Q.webserver.domains, and the gate every request passes on its way in.
 *
 * A record, as stored (every field optional except the key):
 *
 *   "example.com": {
 *     "status":  "active" | "suspended" | "disabled",   (default active)
 *     "since":   unix time the status last changed,
 *     "note":    free text shown in the panel,
 *     "root":    document root for the domain and its aliases
 *     "app":     application name,
 *     "tls":     "auto" | "manual" | "none",
 *     "aliases": [ "www.example.com", ... ]    (served and gated as the domain)
 *     "subdomains": { "blog": "blog", "shop.example.com": "/srv/shop" }
 *                label or full host => document root; a relative root is
 *                taken under the domain's root, and must stay inside it
 *   }
 *
 * Routing: a request's Host (port and case ignored) is looked up as the
 * domain, an alias, or a subdomain, and its document root replaces the
 * server's default root for that request. A root that does not resolve to
 * an existing directory -- or, for a subdomain, resolves outside the
 * domain's root, a symlink included -- is not used; the request is then
 * served from the default root, as an unknown host always is. The server's
 * own /Q/ and /.well-known/ paths are never rerouted.
 *
 *     "redirects": {                           (answered before routing)
 *       "https": true,                          HTTP -> HTTPS, 301
 *       "preferredHost": "www" | "bare",        the other form -> this, 301
 *       "rules": [ { "match": "prefix"|"exact"|"host", "from": "/old",
 *                    "to": "https://example.com/new", "code": 301|302,
 *                    "keepQuery": true } ]    first match wins
 *     },
 *     "hsts": { "enabled": true, "maxAge": 31536000,
 *               "includeSubDomains": false }   sent over HTTPS only
 *     "errorDocs": { "404": "errors/404.html", ... }
 *                path under the domain's root, served (not executed)
 *                with the original status; 403, 404, 500 and 503
 *   }
 *
 * Redirects: the HTTPS redirect and the preferred host are one hop -- the
 * scheme and the host are both corrected in a single 301. A "prefix" rule
 * sends /old/page to <to>/page (the rest of the path is appended); "exact"
 * sends only that path to <to>; "host" sends every request for the host
 * named in "from" (the domain itself, an alias or a subdomain) to <to>,
 * with the path appended. "keepQuery" carries the query string over.
 *
 * Reserved for later, so records written now stay valid: "certificate".
 * Unknown fields are kept as they are when a record is updated.
 *
 * Status, as the gate applies it (the server's own /Q/ and /.well-known/
 * paths are never gated, so the panel and certificate renewals keep
 * working on a suspended host):
 *   active     served normally
 *   suspended  503 with Retry-After: the site is temporarily off
 *   disabled   404: the server does not serve this host at all
 *
 * Order, per request: status (above), then redirects, then routing to the
 * domain's root; error documents replace the server's own error pages for
 * the host, and HSTS is added to its HTTPS responses.
 *
 * @class Q_WebServer_Domains
 * @static
 */
class Q_WebServer_Domains
{
	const STATUSES = array('active', 'suspended', 'disabled');

	/** @var array|null host => record, the gate's view, refreshed every TTL seconds */
	protected static $cache = null;
	protected static $cachedAt = 0.0;
	const TTL = 2.0;

	/**
	 * @var string|null the Host of the request being answered synchronously,
	 * so the server's error page can use the domain's own document; set by
	 * gate(), cleared where the request's document root is restored
	 */
	static $currentHost = null;

	/** @var array host => array(count, last), Host headers seen by the gate */
	protected static $seen = array();
	const SEEN_MAX = 500;

	/** A host as written in a request: lowercase, no port, no trailing dot. */
	static function normalize($host)
	{
		$host = strtolower(trim((string) $host));
		if ($host === '') return '';
		if ($host[0] === '[') {                       // [v6]:port
			$end = strpos($host, ']');
			return $end === false ? '' : substr($host, 0, $end + 1);
		}
		$host = preg_replace('/:\d+$/', '', $host);
		return rtrim($host, '.');
	}

	/** Whether a name can be a domain record key. */
	static function validName($name)
	{
		return is_string($name) and strlen($name) <= 253
			and (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $name);
	}

	/**
	 * Every record: the panel's own, over those declared in config (which
	 * the panel shows but cannot change), each with its status filled in.
	 * @return {array} name => record (plus 'source' => 'panel'|'config')
	 */
	static function records()
	{
		$out = array();
		$config = class_exists('Q_Config', false) ? Q_Config::get('Q', 'webserver', 'domains', array()) : array();
		foreach ((array) $config as $name => $rec) {
			$name = self::normalize($name);
			if ($name === '') continue;
			$out[$name] = (is_array($rec) ? $rec : array()) + array('source' => 'config');
		}
		$acl = class_exists('Q_WebServer_Panel_Store') ? Q_WebServer_Panel_Store::aclLoad() : array();
		foreach ((array) ($acl['domains'] ?? array()) as $name => $rec) {
			$name = self::normalize($name);
			if ($name === '' or !is_array($rec)) continue;
			$out[$name] = array('source' => 'panel') + $rec + ($out[$name] ?? array());
			$out[$name]['source'] = 'panel';
		}
		foreach ($out as $name => $rec) {
			if (!in_array($rec['status'] ?? 'active', self::STATUSES, true)) $out[$name]['status'] = 'active';
			$out[$name]['status'] = $out[$name]['status'] ?? 'active';
		}
		ksort($out);
		return $out;
	}

	/**
	 * Change one panel record under the store's lock: $fn gets the record
	 * (or an empty array) and returns it, or null to delete it.
	 * @return {bool}
	 */
	static function update($name, callable $fn)
	{
		$name = self::normalize($name);
		if (!self::validName($name) or !class_exists('Q_WebServer_Panel_Store')) return false;
		$ok = Q_WebServer_Panel_Store::aclUpdate(function ($acl) use ($name, $fn) {
			$domains = (array) ($acl['domains'] ?? array());
			$rec = $fn(is_array($domains[$name] ?? null) ? $domains[$name] : array());
			if ($rec === null) unset($domains[$name]);
			else $domains[$name] = $rec;
			$acl['domains'] = $domains;
			return $acl;
		});
		self::forget();
		return (bool) $ok;
	}

	/** Set a domain's status (creating the record when there is none). */
	static function setStatus($name, $status, $note = null)
	{
		if (!in_array($status, self::STATUSES, true)) return false;
		return self::update($name, function ($rec) use ($status, $note) {
			$rec['status'] = $status;
			$rec['since'] = time();
			if ($note !== null) $rec['note'] = substr((string) $note, 0, 500);
			return $rec;
		});
	}

	/** Drop the gate's cached view, after a change. */
	static function forget()
	{
		self::$cache = null;
	}

	/**
	 * host => array(name, record, root) for every name, alias and subdomain,
	 * cached briefly; root is the resolved document root, or null.
	 */
	protected static function index()
	{
		$now = microtime(true);
		if (self::$cache !== null and $now - self::$cachedAt < self::TTL) return self::$cache;
		$index = array();
		foreach (self::records() as $name => $rec) {
			$root = self::realDir($rec['root'] ?? null);
			$index[$name] = array($name, $rec, $root);
			foreach ((array) ($rec['aliases'] ?? array()) as $alias) {
				$alias = self::normalize($alias);
				if ($alias !== '' and !isset($index[$alias])) $index[$alias] = array($name, $rec, $root);
			}
			foreach ((array) ($rec['subdomains'] ?? array()) as $sub => $subRoot) {
				$host = self::subdomainHost($sub, $name);
				if ($host === null or isset($index[$host])) continue;
				$index[$host] = array($name, $rec, self::subdomainRoot($subRoot, $root, $why, self::fence($root)));
			}
		}
		self::$cache = $index;
		self::$cachedAt = $now;
		return $index;
	}

	/** A subdomain key ("blog" or "blog.example.com") as a full host under $domain, or null. */
	static function subdomainHost($sub, $domain)
	{
		$sub = self::normalize($sub);
		if ($sub === '') return null;
		$host = (substr($sub, -strlen('.' . $domain)) === '.' . $domain) ? $sub : $sub . '.' . $domain;
		return self::validName($host) ? $host : null;
	}

	/** An existing directory, resolved (symlinks followed), or null. */
	static function realDir($path)
	{
		if (!is_string($path) or $path === '' or strpos($path, "\0") !== false) return null;
		$real = realpath($path);
		return ($real !== false and is_dir($real)) ? rtrim($real, DIRECTORY_SEPARATOR) : null;
	}

	/**
	 * A subdomain's root, resolved: relative to the domain's root when it
	 * is not absolute, and required to stay inside it (after symlinks) when
	 * the domain has a root. Null, with the reason in $why, otherwise.
	 */
	static function subdomainRoot($subRoot, $domainRoot, &$why = null, $fence = null)
	{
		$why = null;
		if ($fence === null) $fence = $domainRoot;
		if (!is_string($subRoot) or trim($subRoot) === '') { $why = 'no document root given'; return null; }
		$path = $subRoot;
		if ($path[0] !== '/' and !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
			if ($domainRoot === null) { $why = 'a relative root needs the domain to have a root'; return null; }
			$path = $domainRoot . DIRECTORY_SEPARATOR . $path;
		}
		$real = self::realDir($path);
		if ($real === null) { $why = 'not an existing directory'; return null; }
		if ($fence !== null and $real !== $fence
			and strncmp($real, $fence . DIRECTORY_SEPARATOR, strlen($fence) + 1) !== 0) {
			$why = 'outside the domain\'s document root';
			return null;
		}
		return $real;
	}

	/**
	 * What a subdomain's root must stay inside: the domain's folder when its
	 * root follows the standard layout (<base>/<domain>/<docDirName>), so
	 * <base>/<domain>/<sub>/<docDirName> qualifies; the root itself otherwise.
	 */
	static function fence($domainRoot)
	{
		if ($domainRoot === null) return null;
		return basename($domainRoot) === self::docDirName() ? dirname($domainRoot) : $domainRoot;
	}

	/**
	 * The name of a domain's document root directory in the standard
	 * layout: Q.domains.docDirName, "doc" by default. A value that is not a
	 * single plain directory name is ignored.
	 */
	static function docDirName()
	{
		$name = class_exists('Q_Config', false) ? Q_Config::get('Q', 'domains', 'docDirName', 'doc') : 'doc';
		return (is_string($name) and preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) and $name !== '..') ? $name : 'doc';
	}

	/**
	 * Where new domains live: Q.domains.baseDir, else the folder above the
	 * server's own domain folder when its document root follows the
	 * <base>/<domain>/... pattern, else /var/www/vhosts.
	 */
	static function baseDir()
	{
		$set = class_exists('Q_Config', false) ? Q_Config::get('Q', 'domains', 'baseDir', null) : null;
		if (is_string($set) and $set !== '' and $set[0] === '/') return rtrim($set, '/');
		$own = class_exists('Q_WebServer', false) ? rtrim((string) Q_WebServer::$rootDir, '/') : '';
		$parts = $own === '' ? array() : explode('/', ltrim($own, '/'));
		foreach ($parts as $i => $seg) {
			if ($i > 0 and strpos($seg, '.') !== false and self::validName(strtolower($seg))) {
				return '/' . implode('/', array_slice($parts, 0, $i));
			}
		}
		return '/var/www/vhosts';
	}

	/** The standard root for a new domain: <base>/<domain>/<docDirName>. */
	static function defaultRoot($domain)
	{
		return self::baseDir() . '/' . self::normalize($domain) . '/' . self::docDirName();
	}

	/** The standard root for a new subdomain: <base>/<domain>/<label>/<docDirName>. */
	static function defaultSubdomainRoot($domain, $sub)
	{
		$domain = self::normalize($domain);
		$label = self::normalize($sub);
		if (substr($label, -strlen('.' . $domain)) === '.' . $domain) $label = substr($label, 0, -strlen('.' . $domain));
		return self::baseDir() . '/' . $domain . '/' . $label . '/' . self::docDirName();
	}

	/**
	 * Create a missing directory (and missing parents), 0755, each owned like
	 * the nearest existing parent. Never touches anything that exists.
	 * @return {bool} true when it now exists as a directory made here
	 */
	static function createDir($path, &$why = null)
	{
		$why = null;
		if (!is_string($path) or $path === '' or $path[0] !== '/' or strpos($path, "\0") !== false or strpos('/' . $path . '/', '/../') !== false) {
			$why = 'not a plain absolute path';
			return false;
		}
		if (file_exists($path) or is_link($path)) { $why = 'already exists'; return false; }
		$missing = array();
		$p = $path;
		while ($p !== '/' and !file_exists($p) and !is_link($p)) { array_unshift($missing, $p); $p = dirname($p); }
		if (!is_dir($p)) { $why = "$p is not a directory"; return false; }
		$uid = @fileowner($p); $gid = @filegroup($p);
		foreach ($missing as $dir) {
			if (!@mkdir($dir, 0755)) { $why = "could not create $dir"; return false; }
			@chmod($dir, 0755);
			if ($uid !== false) @chown($dir, $uid);
			if ($gid !== false) @chgrp($dir, $gid);
		}
		return is_dir($path);
	}

	/**
	 * The document root a request's Host is served from, or null for the
	 * server's default root.
	 * @param {string} $host
	 * @return {string|null} without a trailing separator
	 */
	static function resolveRoot($host)
	{
		$host = self::normalize($host);
		if ($host === '') return null;
		$index = self::index();
		return isset($index[$host]) ? $index[$host][2] : null;
	}

	/** Set a domain's document root (null clears it). */
	static function setRoot($name, $root)
	{
		return self::update($name, function ($rec) use ($root) {
			if ($root === null) unset($rec['root']);
			else $rec['root'] = $root;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Add or remove an alias. */
	static function setAlias($name, $alias, $add = true)
	{
		$alias = self::normalize($alias);
		return self::update($name, function ($rec) use ($alias, $add) {
			$list = array_values(array_filter(array_map(array(__CLASS__, 'normalize'), (array) ($rec['aliases'] ?? array())), 'strlen'));
			$list = array_values(array_diff($list, array($alias)));
			if ($add) $list[] = $alias;
			$rec['aliases'] = $list;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Set a subdomain's root, or remove the subdomain with a null root. */
	static function setSubdomain($name, $sub, $root)
	{
		return self::update($name, function ($rec) use ($sub, $root) {
			$subs = is_array($rec['subdomains'] ?? null) ? $rec['subdomains'] : array();
			if ($root === null) unset($subs[$sub]);
			else $subs[$sub] = $root;
			if ($subs) $rec['subdomains'] = $subs;
			else unset($rec['subdomains']);
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Which domain already answers to $host (as itself, an alias or a subdomain), or null. */
	static function owner($host)
	{
		$hit = self::lookup($host);
		return $hit ? $hit[0] : null;
	}

	/** The record a host belongs to, as array(name, record), or null. */
	static function lookup($host)
	{
		$host = self::normalize($host);
		if ($host === '') return null;
		$index = self::index();
		return $index[$host] ?? null;
	}

	/**
	 * The gate: count the Host, then answer for a suspended or disabled
	 * domain. Returns null to let the request through, or a response as
	 * array(status, headers, body) -- the shape the HTTP/2 route returns.
	 * @param {array} $parsed with 'headers' and 'path'
	 * @return {array|null}
	 */
	static function gate(array $parsed)
	{
		$host = self::normalize($parsed['headers']['host'] ?? '');
		if ($host === '') return null;
		self::noteHost($host);
		$path = (string) ($parsed['path'] ?? '/');
		if (self::exempt($path)) return null;
		self::$currentHost = $host;
		$hit = self::lookup($host);
		if ($hit === null) return null;
		$status = $hit[1]['status'] ?? 'active';
		if ($status === 'active') return self::redirect($parsed, $host, $hit[0], $hit[1]);
		if ($status === 'suspended') {
			$body = class_exists('Q_WebServer', false)
				? Q_WebServer::renderErrorPage(503, $path, 'This site is temporarily suspended.')
				: 'This site is temporarily suspended.';
			return array('status' => 503, 'headers' => array(
				'Content-Type' => 'text/html; charset=utf-8',
				'Retry-After' => '3600',
				'Cache-Control' => 'no-store',
			), 'body' => $body);
		}
		$body = class_exists('Q_WebServer', false)
			? Q_WebServer::renderErrorPage(404, $path, 'This site is not served here.')
			: 'This site is not served here.';
		return array('status' => 404, 'headers' => array(
			'Content-Type' => 'text/html; charset=utf-8',
			'Cache-Control' => 'no-store',
		), 'body' => $body);
	}

	/** The server's own paths, never gated, redirected or rerouted (ACME keeps working). */
	static function exempt($path)
	{
		$path = (string) $path;
		return strncmp($path, '/Q/', 3) === 0 or strncmp($path, '/.well-known/', 13) === 0;
	}

	/** Whether the request came over TLS: set by the server as '_https'. */
	static function isHttps(array $parsed)
	{
		return !empty($parsed['_https']) or !empty($parsed['https']);
	}

	/** $host in the preferred form for $domain ("www" or "bare"), or null when it is not one of the two. */
	static function preferredForm($host, $domain, $preferred)
	{
		$bare = strncmp($domain, 'www.', 4) === 0 ? substr($domain, 4) : $domain;
		$www = 'www.' . $bare;
		if ($host !== $bare and $host !== $www) return null;
		return $preferred === 'www' ? $www : ($preferred === 'bare' ? $bare : null);
	}

	/**
	 * The redirect a request gets, as a response array, or null.
	 * @param {array} $parsed with path (and query), headers, _https
	 * @param {string} $host normalized
	 * @param {string} $domain the record's name
	 * @param {array} $rec
	 * @return {array|null}
	 */
	static function redirect(array $parsed, $host, $domain, array $rec)
	{
		$r = is_array($rec['redirects'] ?? null) ? $rec['redirects'] : array();
		if (!$r) return null;
		$uri = (string) ($parsed['uri'] ?? $parsed['path'] ?? '/');
		$q = strpos($uri, '?');
		$path = $q === false ? $uri : substr($uri, 0, $q);
		$query = $q === false ? '' : substr($uri, $q + 1);
		if ($query === '' and !empty($parsed['query']) and is_string($parsed['query'])) $query = $parsed['query'];
		if ($path === '') $path = '/';
		$https = self::isHttps($parsed);

		// Scheme and host in one hop.
		$toHttps = (!empty($r['https']) && !$https && self::httpsPortSuffix() !== null);
		$toHost = null;
		if (!empty($r['preferredHost'])) {
			$want = self::preferredForm($host, $domain, $r['preferredHost']);
			if ($want !== null and $want !== $host) $toHost = $want;
		}
		if ($toHttps or $toHost !== null) {
			$scheme = ($toHttps or $https) ? 'https' : 'http';
			$port = $scheme === 'https' ? self::httpsPortSuffix() : self::portOf($parsed['headers']['host'] ?? '');
			$loc = $scheme . '://' . ($toHost ?? $host) . (string) $port . $path . ($query !== '' ? '?' . $query : '');
			return self::redirectResponse(301, $loc);
		}

		foreach ((array) ($r['rules'] ?? array()) as $rule) {
			if (!is_array($rule) or empty($rule['to'])) continue;
			$match = $rule['match'] ?? 'prefix';
			$from = (string) ($rule['from'] ?? '');
			$code = (int) ($rule['code'] ?? 301) === 302 ? 302 : 301;
			$to = (string) $rule['to'];
			$rest = null;
			if ($match === 'host') {
				if (self::normalize($from) === $host) $rest = $path;
			} elseif ($match === 'exact') {
				if ($from !== '' and $path === $from) $rest = '';
			} elseif ($from !== '' and strncmp($path, $from, strlen($from)) === 0) {
				$rest = (string) substr($path, strlen($from));
			}
			if ($rest === null) continue;
			if ($rest !== '' and substr($to, -1) === '/' and $rest[0] === '/') $rest = substr($rest, 1);
			$loc = $to . $rest;
			if (!empty($rule['keepQuery']) and $query !== '') $loc .= (strpos($loc, '?') === false ? '?' : '&') . $query;
			return self::redirectResponse($code, $loc);
		}
		return null;
	}

	protected static function redirectResponse($code, $location)
	{
		$safe = htmlspecialchars($location, ENT_QUOTES);
		return array('status' => $code, 'headers' => array(
			'Content-Type' => 'text/html; charset=utf-8',
			'Location' => $location,
			'Cache-Control' => $code === 301 ? 'max-age=3600' : 'no-store',
		), 'body' => "<!DOCTYPE html><title>Moved</title><p>Moved to <a href=\"$safe\">$safe</a></p>");
	}

	/** ":port" for the HTTPS listener (empty for 443), or null when there is none. */
	static function httpsPortSuffix()
	{
		$p = class_exists('Q_WebServer', false) ? Q_WebServer::httpsPort() : 0;
		if ($p <= 0) return null;
		return $p === 443 ? '' : ':' . $p;
	}

	/** ":port" from a Host header, or "". */
	protected static function portOf($hostHeader)
	{
		$h = (string) $hostHeader;
		if ($h !== '' and $h[0] === '[') {
			$end = strpos($h, ']');
			return ($end !== false and isset($h[$end + 1]) and $h[$end + 1] === ':') ? substr($h, $end + 1) : '';
		}
		$c = strrpos($h, ':');
		return $c === false ? '' : substr($h, $c);
	}

	/**
	 * The Strict-Transport-Security value for an HTTPS response to $host, or
	 * null (not HTTPS, no domain, HSTS off).
	 */
	static function hstsHeader($host, $https)
	{
		if (!$https) return null;
		$hit = self::lookup($host);
		if ($hit === null) return null;
		$h = $hit[1]['hsts'] ?? null;
		if (!is_array($h) or empty($h['enabled'])) return null;
		$age = isset($h['maxAge']) ? max(0, (int) $h['maxAge']) : 31536000;
		return 'max-age=' . $age . (!empty($h['includeSubDomains']) ? '; includeSubDomains' : '');
	}

	/** Add HSTS to a response's headers when it applies (headers as name => value). */
	static function addHsts(array &$headers, $host, $https)
	{
		$v = self::hstsHeader(self::normalize($host), $https);
		if ($v === null) return;
		foreach ($headers as $k => $_) {
			if (is_string($k) and strcasecmp($k, 'Strict-Transport-Security') === 0) return;
		}
		$headers['Strict-Transport-Security'] = $v;
	}

	const ERROR_CODES = array(403, 404, 500, 503);

	/**
	 * The domain's own error document for the request being answered, or
	 * null: a file under the domain's root, read (never executed).
	 */
	static function errorDocument($code)
	{
		if (self::$currentHost === null or !in_array((int) $code, self::ERROR_CODES, true)) return null;
		$hit = self::lookup(self::$currentHost);
		if ($hit === null) return null;
		$docs = is_array($hit[1]['errorDocs'] ?? null) ? $hit[1]['errorDocs'] : array();
		$rel = $docs[(string) (int) $code] ?? null;
		if (!is_string($rel) or $rel === '') return null;
		$root = self::realDir($hit[1]['root'] ?? null);
		$file = self::errorDocPath($root, $rel);
		if ($file === null) return null;
		$body = @file_get_contents($file);
		return $body === false ? null : $body;
	}

	/** The real path of an error document under $root, or null when it is missing or escapes. */
	static function errorDocPath($root, $rel, &$why = null)
	{
		if ($root === null) { $why = 'the domain has no document root'; return null; }
		$rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
		if ($rel === '' or preg_match('#(^|/)\.\.(/|$)#', $rel)) { $why = 'the path must stay under the document root'; return null; }
		$real = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
		if ($real === false or !is_file($real)) { $why = "$rel is not a file under the document root"; return null; }
		if (strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) { $why = 'the path must stay under the document root'; return null; }
		return $real;
	}

	/**
	 * Why a redirects setting cannot be saved for $domain, or null. Checks
	 * shapes, codes, the HTTPS listener and certificate for "https", and
	 * rules that would redirect to themselves.
	 */
	static function redirectsProblem($domain, array $rec, array $r, $certNames = null)
	{
		if (!empty($r['https'])) {
			if (self::httpsPortSuffix() === null) return 'there is no HTTPS listener, so HTTP cannot be sent to HTTPS';
			if ($certNames !== null) {
				$covered = false;
				foreach ($certNames as $n) if (Q_WebServer_DomainUsage::covers($n, $domain)) { $covered = true; break; }
				if (!$covered) return "the certificate the server presents does not cover $domain";
			}
		}
		if (isset($r['preferredHost']) and $r['preferredHost'] !== null and !in_array($r['preferredHost'], array('www', 'bare'), true)) {
			return 'preferredHost must be "www", "bare" or null';
		}
		$hosts = array($domain);
		foreach ((array) ($rec['aliases'] ?? array()) as $a) $hosts[] = self::normalize($a);
		foreach ((array) ($rec['subdomains'] ?? array()) as $sub => $_) {
			$h = self::subdomainHost($sub, $domain);
			if ($h !== null) $hosts[] = $h;
		}
		foreach ((array) ($r['rules'] ?? array()) as $i => $rule) {
			$n = $i + 1;
			if (!is_array($rule)) return "rule $n is not an object";
			$match = $rule['match'] ?? 'prefix';
			if (!in_array($match, array('prefix', 'exact', 'host'), true)) return "rule $n: match must be prefix, exact or host";
			$from = (string) ($rule['from'] ?? '');
			$to = (string) ($rule['to'] ?? '');
			if (!in_array((int) ($rule['code'] ?? 301), array(301, 302), true)) return "rule $n: code must be 301 or 302";
			if (!preg_match('#^https?://[^/\s]+#i', $to)) return "rule $n: the target must be an http:// or https:// URL";
			if ($match === 'host') {
				if (!in_array(self::normalize($from), $hosts, true)) return "rule $n: $from is not this domain, an alias or a subdomain";
			} elseif ($from === '' or $from[0] !== '/') {
				return "rule $n: from must be a path starting with /";
			}
			$th = self::normalize((string) parse_url($to, PHP_URL_HOST));
			$tp = (string) (parse_url($to, PHP_URL_PATH) ?: '/');
			if (in_array($th, $hosts, true)) {
				if ($match === 'host') {
					if ($th === self::normalize($from)) return "rule $n would redirect $from to itself";
				} elseif ($tp === $from or ($match === 'prefix' and strncmp($tp, $from, strlen($from)) === 0)) {
					return "rule $n would redirect $from to itself";
				}
			}
		}
		return null;
	}

	/** Store the redirects setting (null clears it). */
	static function setRedirects($name, $r)
	{
		return self::update($name, function ($rec) use ($r) {
			if ($r === null) unset($rec['redirects']);
			else $rec['redirects'] = $r;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Store the HSTS setting (null clears it). */
	static function setHsts($name, $h)
	{
		return self::update($name, function ($rec) use ($h) {
			if ($h === null) unset($rec['hsts']);
			else $rec['hsts'] = $h;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Store the error documents (an empty array clears them). */
	static function setErrorDocs($name, array $docs)
	{
		return self::update($name, function ($rec) use ($docs) {
			if (!$docs) unset($rec['errorDocs']);
			else $rec['errorDocs'] = $docs;
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
	}

	/** Count a Host header, keeping the most recently seen when full. */
	static function noteHost($host)
	{
		if (!isset(self::$seen[$host]) and count(self::$seen) >= self::SEEN_MAX) {
			uasort(self::$seen, function ($a, $b) { return $a[1] <=> $b[1]; });
			array_shift(self::$seen);
		}
		$c = self::$seen[$host][0] ?? 0;
		self::$seen[$host] = array($c + 1, time());
	}

	/** host => array('count' => n, 'last' => unix time) */
	static function seen()
	{
		$out = array();
		foreach (self::$seen as $h => $v) $out[$h] = array('count' => $v[0], 'last' => $v[1]);
		return $out;
	}

	/** For tests. */
	static function reset()
	{
		self::$seen = array();
		self::$currentHost = null;
		self::forget();
	}
}
