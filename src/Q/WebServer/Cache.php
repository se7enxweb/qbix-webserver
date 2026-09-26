<?php
/**
 * @module Q
 */

/**
 * Built-in reverse proxy cache for Q_WebServer.
 *
 * Sits in the parent process event loop, before worker dispatch.
 * Cached responses are served without forking a worker — pure
 * event loop speed, on par with Varnish for cache hits.
 *
 * Two storage tiers:
 *   - APCu: for small responses (under Q.web.cache.apcu.maxSize)
 *   - Filesystem: for larger responses
 *
 * Respects HTTP caching semantics:
 *   - Cache-Control: max-age, s-maxage, no-store, private, no-cache
 *   - Vary header (cache per Accept-Encoding, etc.)
 *   - Cookie bypass: skip cache if request has specific cookies
 *
 * Config:
 *   "Q": { "web": { "cache": {
 *     "enabled": true,
 *     "dir": "files/cache/reverse",
 *     "apcu": {
 *       "enabled": true,
 *       "maxSize": 65536
 *     },
 *     "defaultTtl": 0,
 *     "skip": {
 *       "cookies": ["Q_sid", "PHPSESSID"]
 *     }
 *   }}}
 *
 * @class Q_WebServer_Cache
 */

// Q_WebServer_Modes decides the permissions the files below are created
// with. The server's autoloader finds it by name; requiring it here as
// well is what makes this class usable on its own, which is how the
// tests drive it.
if (!class_exists('Q_WebServer_Modes', false)
and is_file(__DIR__ . '/Modes.php')) {
	require_once __DIR__ . '/Modes.php';
}

class Q_WebServer_Cache
{
	static $enabled = false;
	static $dir = '';
	static $apcuEnabled = false;
	static $apcuMaxSize = 65536; // 64KB
	static $defaultTtl = 0;      // 0 = don't cache unless told to
	static $skipCookies = array();
	static $hits = 0;
	static $misses = 0;

	/** @var array hits by where they were answered from: a 304 off the validator index, the server's own memory, APCu, disk */
	static $hitsFrom = array('index' => 0, 'memory' => 0, 'apcu' => 0, 'disk' => 0);

	/**
	 * The in-process layer in front of APCu: the hottest entries, kept in the
	 * server process itself, so a hit costs neither an unserialise nor a copy
	 * out of shared memory. Off (0) by default. Q.web.cache.memory.*
	 * @property $memoryMaxEntries
	 * @type {integer}
	 */
	static $memoryMaxEntries = 0;
	/** @var int most bytes of bodies held in memory */
	static $memoryMaxBytes = 33554432;
	/** @var int largest body kept in memory */
	static $memoryMaxEntrySize = 65536;
	/** @var int entries dropped to stay within the limits */
	static $memoryEvictions = 0;

	/** @var array key => entry, least recently used first */
	private static $memory = array();
	/** @var int bytes of bodies in $memory */
	private static $memoryBytes = 0;
	/** @var int the one process whose memory may answer: the one that ran init() */
	private static $memoryPid = 0;
	/** @var string|null the epoch token the memory was filled under */
	private static $memoryEpoch = null;
	/** @var int when the epoch was last read */
	private static $memoryEpochChecked = -1;

	/** @var int apcu_store() calls that failed: a full segment, or APCu unusable */
	static $apcuStoreFailures = 0;

	/** @var string[] what init() found wrong with APCu, as said on STDERR */
	static $apcuWarnings = array();

	/** @var bool whether the warnings have been said in this process */
	private static $apcuWarned = false;

	/**
	 * How long past its expiry an entry may still be served while one request
	 * renders a fresh one. 0 keeps the old behaviour exactly.
	 * @property $staleWhileRevalidate
	 */
	static $staleWhileRevalidate = 0;

	/**
	 * How long a single render may hold the right to refresh a key before
	 * another request is allowed to take it. Covers a worker that died mid
	 * render, which would otherwise leave the page stale until it fell out
	 * of the grace window.
	 * @property $revalidateLockSeconds
	 */
	static $revalidateLockSeconds = 30;

	static $stale = 0;

	/**
	 * Seconds to remember a 404 or 410. 0 keeps the old behaviour exactly.
	 *
	 * A miss costs what a hit saves. Measured on this installation, a 404 is
	 * 220-340ms of full render, and none of them were ever cached, so a
	 * crawler walking a list of dead links paid that price on every request
	 * and so did the server. Nothing bounded it.
	 *
	 * Kept short by default where it is enabled at all, because the cost of
	 * being wrong is a page that exists appearing not to. A minute is long
	 * enough to blunt a crawl and short enough that publishing something is
	 * not visibly delayed.
	 * @property $negativeTtl
	 */
	static $negativeTtl = 0;

	/**
	 * Whether to collapse insignificant whitespace in cached HTML.
	 * Off unless asked for, like everything else that changes what is sent.
	 * @property $minifyHtml
	 */
	static $minifyHtml = false;

	/**
	 * Whether stored entries are compressed against a shared dictionary.
	 *
	 * Off unless asked for. It changes the on-disk format, and an entry written
	 * with a dictionary cannot be read without exactly the same one -- which is
	 * safe, because a mismatch reads as a miss rather than as wrong bytes, but
	 * it is not something to switch on by accident.
	 * @property $middleOut
	 */
	static $middleOut = false;

	/** @var string|null the loaded dictionary, or '' when there is none */
	static $dictionary = null;

	/**
	 * Permissions for the entries and the directories holding them,
	 * parsed once in init(). A cached entry is a response body that was
	 * served to somebody; null keeps the old behaviour, where it is
	 * created at 0666 minus the umask and readable by every account on
	 * the machine.
	 * @property $fileMode
	 */
	static $fileMode = null;
	static $dirMode = null;
	static $dirModeExplicit = false;

	/**
	 * A file whose modification time invalidates every entry stored before it.
	 *
	 * The cache revalidates a page against what the application says about
	 * it, and an application judges that by its content -- a template or a
	 * stylesheet changed on disk moves nothing it looks at. So an entry made
	 * from the old template kept being served and renewed, and a deploy had no
	 * way to say "all of it". Touching this file says it, from the command
	 * line or a deploy script, without a request to the server and without
	 * reaching into APCu, which only the server's own processes can clear.
	 *
	 * Q.web.cache.generationFile; defaults to .generation in the cache dir.
	 * '' when there is nowhere to keep it.
	 * @property $generationFile
	 */
	static $generationFile = '';

	/** @var int the marker's mtime as last read, 0 when there is none */
	private static $generation = 0;

	/** @var int when the marker was last read */
	private static $generationChecked = -1;

	/**
	 * The current generation: the marker's mtime, read at most once a second.
	 * @method generation
	 * @static
	 * @return {integer}
	 */
	static function generation()
	{
		if (self::$generationFile === '') return 0;
		$now = time();
		if ($now !== self::$generationChecked) {
			self::$generationChecked = $now;
			clearstatcache(true, self::$generationFile);
			$mtime = @filemtime(self::$generationFile);
			self::$generation = $mtime === false ? 0 : (int) $mtime;
		}
		return self::$generation;
	}

	/**
	 * Start a new generation: every entry stored until now is a miss from the
	 * next request on. The same as touching the marker, for callers in PHP.
	 * @method bumpGeneration
	 * @static
	 * @return {boolean}
	 */
	static function bumpGeneration()
	{
		if (self::$generationFile === '') return false;
		$ok = @touch(self::$generationFile);
		self::$generationChecked = -1;
		return $ok;
	}

	/**
	 * Whether an entry (or index record) predates the current generation.
	 * Stored in the same second as the bump counts as older: the safe side.
	 */
	private static function isOldGeneration($record)
	{
		$generation = self::generation();
		if ($generation <= 0) return false;
		return (int) ($record['stored'] ?? 0) <= $generation;
	}

	/**
	 * Initialize cache from config.
	 * @method init
	 * @static
	 */
	static function init()
	{
		$config = Q_Config::get('Q', 'web', 'cache', array());
		self::$enabled = (bool) Q::ifset($config, 'enabled', false);
		if (!self::$enabled) return;

		self::$fileMode = Q_WebServer_Modes::parse(
			Q::ifset($config, 'fileMode', null)
		);
		self::$dirModeExplicit = Q::ifset($config, 'dirMode', null) !== null;
		self::$dirMode = Q_WebServer_Modes::parse(
			Q::ifset($config, 'dirMode', null), 0755
		);

		self::$dir = Q::ifset($config, 'dir', '');
		if (!self::$dir && defined('APP_DIR')) {
			self::$dir = APP_DIR . DS . 'files' . DS . 'cache' . DS . 'reverse';
		}
		if (self::$dir) {
			Q_WebServer_Modes::dir(
				self::$dir, self::$dirMode, self::$dirModeExplicit
			);
			if (self::$dirModeExplicit
			and !Q_WebServer_Modes::verify(self::$dir, self::$dirMode)) {
				fwrite(STDERR, "  cache: " . self::$dir . " is not "
					. decoct(self::$dirMode) . ", chmod did not take\n");
			}
		}

		$apcu = Q::ifset($config, 'apcu', array());
		self::$apcuMaxSize = (int) Q::ifset($apcu, 'maxSize', 65536);
		$wanted = Q::ifset($apcu, 'enabled', null);
		self::$apcuEnabled = self::apcuUsable($wanted === null ? null : (bool) $wanted);
		$memory = Q::ifset($config, 'memory', array());
		self::$memoryMaxEntries = max(0, (int) Q::ifset($memory, 'maxEntries', 0));
		self::$memoryMaxBytes = max(0, (int) Q::ifset($memory, 'maxBytes', 33554432));
		self::$memoryMaxEntrySize = max(0, (int) Q::ifset($memory, 'maxEntrySize', 65536));
		self::$memory = array();
		self::$memoryBytes = 0;
		self::$memoryPid = function_exists('getmypid') ? getmypid() : 0;
		self::$memoryEpochChecked = -1;
		self::$defaultTtl = (int) Q::ifset($config, 'defaultTtl', 0);
		self::$skipCookies = Q::ifset($config, 'skip', 'cookies', array('Q_sid', 'PHPSESSID'));
		self::$staleWhileRevalidate = (int) Q::ifset($config, 'staleWhileRevalidate', 0);
		self::$revalidateLockSeconds = (int) Q::ifset($config, 'revalidateLockSeconds', 30);
		self::$negativeTtl = (int) Q::ifset($config, 'negativeTtl', 0);
		self::$minifyHtml = (bool) Q::ifset($config, 'minifyHtml', false);
		self::$middleOut = (bool) Q::ifset($config, 'middleOut', false);
		self::$dictionary = null;   // reloaded lazily
		self::$generationFile = (string) Q::ifset($config, 'generationFile',
			self::$dir ? self::$dir . DIRECTORY_SEPARATOR . '.generation' : '');
		self::$generationChecked = -1;
		self::forgetOnAccessListChange();
	}

	// ── The in-process layer ────────────────────────────────────────────
	//
	// Only the process that ran init() -- the server -- answers from it. A
	// worker forked afterwards inherits a copy that nobody keeps up to date,
	// so it never reads it. Anything a worker changes reaches the server
	// through the epoch file: purge(), clear() and a put() made outside the
	// server write a new token into it, and the server, reading it at most
	// once a second as it reads the generation marker, empties its memory
	// when the token changes. A token rather than an mtime, so two changes
	// within one second cannot be mistaken for one.

	/** Whether this process answers from memory. */
	private static function memoryActive()
	{
		return self::$memoryMaxEntries > 0
			and self::$memoryPid === (function_exists('getmypid') ? getmypid() : 0);
	}

	/** Where the epoch token lives, or '' without a cache directory. */
	private static function memoryEpochFile()
	{
		return self::$dir ? self::$dir . DIRECTORY_SEPARATOR . '.memory-epoch' : '';
	}

	/** Empty the memory when another process has changed the store since it was filled. */
	private static function memoryCheckEpoch()
	{
		$now = time();
		if ($now === self::$memoryEpochChecked) return;
		self::$memoryEpochChecked = $now;
		$file = self::memoryEpochFile();
		$token = $file === '' ? '' : (string) @file_get_contents($file);
		if ($token !== self::$memoryEpoch) {
			if (self::$memoryEpoch !== null) self::memoryFlush();
			self::$memoryEpoch = $token;
		}
	}

	/** Tell the server's memory that the store changed under it. */
	private static function memorySignal()
	{
		if (self::$memoryMaxEntries <= 0) return;
		if (self::memoryActive()) self::memoryFlush();
		$file = self::memoryEpochFile();
		if ($file === '') return;
		Q_WebServer_Modes::put($file, uniqid('', true), LOCK_EX, self::$fileMode);
		// This process's own copy is current: it made the change.
		if (self::memoryActive()) {
			self::$memoryEpoch = (string) @file_get_contents($file);
			self::$memoryEpochChecked = time();
		}
	}

	/** @return {array|null} the entry held in memory for $key */
	private static function memoryGet($key)
	{
		if (!self::memoryActive()) return null;
		self::memoryCheckEpoch();
		if (!isset(self::$memory[$key])) return null;
		$entry = self::$memory[$key];
		// Most recently used goes last; eviction takes from the front.
		unset(self::$memory[$key]);
		self::$memory[$key] = $entry;
		return $entry;
	}

	/** Keep $entry in memory, within the limits, evicting the least recently used. */
	private static function memoryPut($key, $entry)
	{
		if (!self::memoryActive()) return;
		self::memoryCheckEpoch();
		$size = strlen($entry['body'] ?? '');
		if ($size > self::$memoryMaxEntrySize or $size > self::$memoryMaxBytes) return;
		self::memoryDrop($key);
		unset($entry['headers']['X-Cache'], $entry['headers']['Age']);
		self::$memory[$key] = $entry;
		self::$memoryBytes += $size;
		while (self::$memory and (count(self::$memory) > self::$memoryMaxEntries
		or self::$memoryBytes > self::$memoryMaxBytes)) {
			$first = array_key_first(self::$memory);
			self::memoryDrop($first);
			self::$memoryEvictions++;
		}
	}

	private static function memoryDrop($key)
	{
		if (!isset(self::$memory[$key])) return;
		self::$memoryBytes -= strlen(self::$memory[$key]['body'] ?? '');
		unset(self::$memory[$key]);
	}

	private static function memoryFlush()
	{
		self::$memory = array();
		self::$memoryBytes = 0;
	}

	/**
	 * Whether APCu can hold entries in this process, and say so when it
	 * cannot although it looks as if it could.
	 *
	 * function_exists('apcu_fetch') is true whenever the extension is loaded,
	 * including under the CLI with apc.enable_cli=0 -- PHP's default. There
	 * every store fails and every fetch misses, without an error: the cache
	 * ran from disk while its settings said memory, and the only sign was a
	 * throughput that halved from one machine to the next. apcu_enabled()
	 * answers the real question.
	 *
	 * @method apcuUsable
	 * @static
	 * @param {boolean|null} $wanted Q.web.cache.apcu.enabled; null when unset
	 * @return {boolean}
	 */
	static function apcuUsable($wanted)
	{
		$loaded = function_exists('apcu_fetch');
		$usable = ($loaded and function_exists('apcu_enabled') and apcu_enabled());
		$warn = array();
		if ($wanted !== false and $loaded and !$usable) {
			$warn[] = 'APCu is loaded but disabled in this process (apc.enable_cli is off),'
				. ' so cached pages are read from disk. Start PHP with -d apc.enable_cli=1,'
				. ' or set it in php.ini.';
		}
		if ($wanted === true and !$loaded) {
			$warn[] = 'Q.web.cache.apcu.enabled is true but APCu is not loaded,'
				. ' so cached pages are read from disk.';
		}
		if ($usable and $wanted !== false) {
			if (ini_get('apc.use_request_time')) {
				// The "request" of a long-running server is its whole life, so an
				// entry stored an hour after startup with a lifetime of five
				// minutes is already expired when it is written.
				$warn[] = "apc.use_request_time=1 measures APCu lifetimes from the server's"
					. ' start, so entries expire early. Set apc.use_request_time=0.';
			}
			$shm = self::iniBytes(ini_get('apc.shm_size'));
			if ($shm > 0 and self::$apcuMaxSize >= $shm) {
				$warn[] = 'Q.web.cache.apcu.maxSize (' . self::$apcuMaxSize . ' bytes) is not'
					. ' smaller than apc.shm_size (' . $shm . ' bytes); one entry could fill it.';
			}
		}
		self::$apcuWarnings = $warn;
		if ($warn and !self::$apcuWarned) {
			self::$apcuWarned = true;
			foreach ($warn as $w) fwrite(STDERR, "  cache: $w\n");
		}
		return $wanted === null ? $usable : ($wanted and $usable);
	}

	/** "32M" -> 33554432, as PHP reads a size setting. */
	private static function iniBytes($value)
	{
		$value = trim((string) $value);
		if ($value === '') return 0;
		$n = (int) $value;
		switch (strtoupper(substr($value, -1))) {
			case 'G': $n *= 1024; // fall through
			case 'M': $n *= 1024; // fall through
			case 'K': $n *= 1024;
		}
		return $n;
	}

	/**
	 * Store in APCu, counting a refusal. A full segment, or an APCu that cannot
	 * be used, answers false; before this nobody could tell.
	 */
	private static function apcuStore($key, $value, $ttl)
	{
		$ok = @apcu_store($key, $value, $ttl);
		if (!$ok) self::$apcuStoreFailures++;
		return $ok;
	}

	/**
	 * Start a new generation when the lists that decide what may be answered
	 * at all -- Q.webserver.scripts, Q.webserver.frontControllers and
	 * Q.web.static.paths -- differ from those the stored entries were made
	 * under. An entry only exists because a request once passed those checks,
	 * and a lookup comes before them; without this, a script's cached page
	 * or a file's stored copy would still be answered after the lists stop
	 * allowing it, until the entry expired.
	 *
	 * The lists' fingerprint is kept beside the entries (.access-lists), so
	 * a restart with unchanged lists keeps the cache.
	 * @method forgetOnAccessListChange
	 * @static
	 * @return {boolean} whether a new generation was started
	 */
	static function forgetOnAccessListChange()
	{
		if (!self::$enabled or !self::$dir or self::$generationFile === '') return false;
		$lists = array(
			Q_Config::get('Q', 'webserver', 'scripts', null),
			Q_Config::get('Q', 'webserver', 'frontControllers', null),
			Q_Config::get('Q', 'web', 'static', 'paths', null),
		);
		$now = md5(json_encode($lists));
		$file = self::$dir . DIRECTORY_SEPARATOR . '.access-lists';
		$before = @file_get_contents($file);
		if ($before === $now) return false;
		// No record yet and no lists: nothing was ever narrowed, keep the cache.
		$bumped = false;
		if ($before !== false or $lists !== array(null, null, null)) {
			$bumped = self::bumpGeneration();
		}
		@file_put_contents($file, $now, LOCK_EX);
		return $bumped;
	}

	/**
	 * Claim the sole right to re-render one key.
	 *
	 * An entry expires at a moment, not gradually, so every request in flight
	 * for that page misses at once and every one of them renders it. On this
	 * installation a hit costs 0.6 ms and a render costs 1382 ms, so the page
	 * does not get slightly slower at the expiry -- it stops, for as long as
	 * the render takes, for everyone who arrives during it, and they all do
	 * the same work to produce the same bytes.
	 *
	 * So exactly one request is let through to render, and the rest are given
	 * the copy that already exists. The claim is a directory because mkdir is
	 * atomic and needs no cleanup protocol to be correct: two processes racing
	 * cannot both succeed.
	 *
	 * A render that dies still holding the claim would leave the page stale,
	 * so a claim older than revalidateLockSeconds may be taken from it. That
	 * is the one case where two renders can overlap, and duplicated work is a
	 * far better failure than a page frozen at its last version.
	 *
	 * @method claimRevalidation
	 * @static
	 * @param {string} $key
	 * @return {boolean} true if this request should render
	 */
	static function claimRevalidation($key)
	{
		$path = self::filePath($key);
		if (!$path) return true; // nowhere to keep a claim; let it render
		$lock = $path . '.refresh';

		// The mkdir is the claim: its return value is what makes this
		// atomic, so the mode is set inside the branch that won and
		// never allowed to stand in for the result.
		if (@mkdir($lock, self::$dirMode === null ? 0755 : self::$dirMode, true)) {
			if (self::$dirModeExplicit) {
				Q_WebServer_Modes::chmod($lock, self::$dirMode);
			}
			return true;
		}

		// Held. Take it only if whoever holds it has had long enough to be
		// presumed gone.
		//
		// The stat cache has to go first. Every interesting change to this
		// directory's mtime is made by a *different* process -- the holder
		// re-dating it, or another worker taking it over -- and nothing those
		// do invalidates the cache in this one. So a worker that has looked at
		// this lock before answers from its own memory: it can refuse a claim
		// that has since been abandoned, or take one that has just been
		// renewed. Both are wrong, and neither is visible from here.
		clearstatcache(true, $lock);
		$since = @filemtime($lock);
		if ($since !== false
		and time() - $since > max(1, self::$revalidateLockSeconds)) {
			@touch($lock);      // re-date it first, so the next caller waits
			return true;
		}
		return false;
	}

	/**
	 * Give up the right to re-render a key.
	 * @method releaseRevalidation
	 * @static
	 * @param {string} $key
	 */
	static function releaseRevalidation($key)
	{
		$path = self::filePath($key);
		if ($path) @rmdir($path . '.refresh');
	}

	/**
	 * Whether a path belongs to the server itself -- /Q/ and /.well-known/ --
	 * rather than to the application. Those are never stored or served from
	 * this cache, whatever answered them: when /Q/panel reached the
	 * application (over HTTP/2, before the server answered it there), the
	 * application's 404 was stored under /Q/panel and then served in place of
	 * the panel on both protocols, long after the route was fixed.
	 * @method isServerPath
	 * @static
	 * @param {string} $path
	 * @return {boolean}
	 */
	static function isServerPath($path)
	{
		$path = (string) $path;
		return strncmp($path, '/Q/', 3) === 0 || $path === '/Q'
			|| strncmp($path, '/.well-known/', 13) === 0;
	}

	/**
	 * Try to serve from cache. Returns response array or null.
	 *
	 * Called in the parent event loop BEFORE dispatching to a
	 * worker. A cache hit means zero fork overhead.
	 *
	 * @method get
	 * @static
	 * @param {array} $parsed Parsed request
	 * @param {boolean} [$allowHead=false] answer a HEAD from the GET's entry.
	 *   Only for a caller that then sends the headers without the body; the
	 *   HTTP/2 and route() paths hand the entry on whole, so they leave it off.
	 * @return {array|null} [status, headers, body] or null
	 */
	static function get($parsed, $allowHead = false)
	{
		if (!self::$enabled) return null;
		if ($parsed['method'] !== 'GET'
		and !($allowHead and $parsed['method'] === 'HEAD')) return null;
		if (self::isServerPath($parsed['path'] ?? '')) return null;

		// Skip cache if request has bypass cookies, or credentials of its own
		if (self::hasSkipCookie($parsed['headers'])) return null;
		if (!empty($parsed['headers']['authorization'])) return null;

		// A refresh request reads past the stored copy so that the response is
		// rendered and put() stores it again.
		//
		// Without this a cache warmer cannot warm anything. It asks for a page,
		// gets a hit, and the entry it was trying to renew keeps its original
		// expiry -- so with a four-minute warm cycle and a five-minute lifetime
		// the entry still expired, and every visitor in the gap paid for a full
		// render. Measured on this installation: 0.8ms from cache against 800ms
		// rendered, for roughly three minutes in every eight.
		//
		// Deliberately its own header rather than the standard
		// Cache-Control: no-cache. Browsers send that on a plain reload, and
		// honouring it would let any client, or a crawler, bypass the cache at
		// will and make the server render on demand. This one is only sent by
		// something that has been told to send it.
		if (self::isRefreshRequest($parsed['headers'])) return null;

		$key = self::cacheKey($parsed);

		// Before either store. A client that already holds the page is asking
		// a question about two strings, and the answer is in the index.
		$fresh = self::notModifiedFromIndex($parsed, $key);
		if ($fresh !== null) {
			self::$hits++;
			self::$hitsFrom['index']++;
			return $fresh;
		}

		// One entry, found wherever it lives, and one decision about it. The
		// two stores used to decide separately, which let a single request
		// claim the re-render off the APCu copy and then, finding the file
		// copy also expired, be handed its own claim back as a refusal -- so
		// the request that was supposed to render was served stale instead,
		// and nothing ever rendered.
		// The server's own memory first. It holds only fresh copies of what
		// the stores below hold, so it answers only a fresh, current entry;
		// anything else falls through to the one decision made below.
		$held = self::memoryGet($key);
		if ($held !== null) {
			$expires = $held['expires'] ?? 0;
			if (!self::isOldGeneration($held) and ($expires === 0 or $expires > time())) {
				self::$hits++;
				self::$hitsFrom['memory']++;
				$held['headers']['X-Cache'] = 'HIT';
				$held['headers']['Age'] = self::age($held);
				return $held;
			}
			self::memoryDrop($key);
		}

		$entry = null;
		$fromApcu = false;
		if (self::$apcuEnabled) {
			$found = apcu_fetch('qcache:' . $key);
			if ($found !== false) { $entry = $found; $fromApcu = true; }
		}

		$path = self::filePath($key);
		if ($entry === null and $path and file_exists($path)) {
			$entry = self::decodeEntry(file_get_contents($path));
		}

		if ($entry === null) {
			self::$misses++;
			return null;
		}

		// Made before the last "clear everything": worthless, wherever it is.
		if (self::isOldGeneration($entry)) {
			if ($fromApcu) apcu_delete('qcache:' . $key);
			if (self::$apcuEnabled) apcu_delete('qcache:v:' . $key);
			if ($path) @unlink($path);
			self::$misses++;
			return null;
		}

		$expires = isset($entry['expires']) ? $entry['expires'] : 0;
		if ($expires === 0 or $expires > time()) {
			self::$hits++;
			self::$hitsFrom[$fromApcu ? 'apcu' : 'disk']++;
			self::memoryPut($key, $entry);
			$entry['headers']['X-Cache'] = 'HIT';
			$entry['headers']['Age'] = self::age($entry);
			if (!$fromApcu and self::$apcuEnabled
			and strlen($entry['body']) <= self::$apcuMaxSize) {
				self::apcuStore('qcache:' . $key, $entry, self::apcuTtl($entry));
			}
			return $entry;
		}

		// Expired. Whether it may still be served, and who renders, is decided
		// once here.
		$past = time() - $expires;
		if (self::$staleWhileRevalidate > 0
		and $past <= self::$staleWhileRevalidate) {
			if (!self::claimRevalidation($key)) {
				// Someone else is rendering. This one gets the old page now.
				self::$hits++;
				self::$hitsFrom[$fromApcu ? 'apcu' : 'disk']++;
				self::$stale++;
				// Back into memory for the rest of the window, if it had
				// dropped out: a page served stale is by definition a busy one.
				if (!$fromApcu and self::$apcuEnabled
				and strlen($entry['body']) <= self::$apcuMaxSize) {
					self::apcuStore('qcache:' . $key, $entry, self::apcuTtl($entry));
				}
				$entry['headers']['X-Cache'] = 'STALE';
				$entry['headers']['Age'] = self::age($entry);
				return $entry;
			}

			// This request renders. The old copy stays exactly where it is:
			// deleting it here is what made the stampede survive the fix --
			// the renderer removed the very page the others were about to be
			// served, and all of them rendered after all.
			self::$misses++;
			return null;
		}

		// Past the grace window, so the copy is genuinely worthless.
		if ($fromApcu) apcu_delete('qcache:' . $key);
		if ($path) @unlink($path);

		self::$misses++;
		return null;
	}

	/**
	 * Store a response in cache if cacheable.
	 *
	 * Checks Cache-Control headers to determine TTL.
	 * Only caches GET responses with 200 status.
	 *
	 * @method put
	 * @static
	 * @param {array} $parsed Request
	 * @param {array} $response [status, headers, body]
	 * @return {array} the response as it should now be sent
	 *
	 * Returns rather than modifies in place. Whatever normalising happens here
	 * -- at present, collapsing the HTML and deriving the validator -- has to
	 * reach the client as well as the store, or the request that fills the
	 * cache is served something different from every request after it. That was
	 * real: with minification on, the response that filled the cache was 95,086
	 * bytes and every one after it 70,061.
	 *
	 * A return value rather than a reference parameter, because a reference
	 * would break every caller that passes an expression rather than a
	 * variable, and a caller that ignores the return simply gets today's
	 * behaviour.
	 */
	static function put($parsed, $response)
	{
		// Every exit hands the response back, including the ones that store
		// nothing, so a caller can always assign the result without checking.
		if (!self::$enabled) return $response;
		if ($parsed['method'] !== 'GET') return $response;
		if (self::isServerPath($parsed['path'] ?? '')) return $response;

		// A request that claimed the re-render and then produced something
		// uncacheable -- an error, a redirect, a no-store -- must still give
		// the claim back. Otherwise the page it was refreshing stays stale
		// until the claim ages out, and the next render is delayed by exactly
		// the timeout meant for a crashed worker.
		$status = $response['status'] ?? 200;

		// 404 and 410 are answers too, and expensive ones: they run the whole
		// routing and rendering path before concluding there is nothing there.
		// Only these two, and only when asked for -- a 500 must never be
		// remembered, because the next request is exactly when it might work.
		// && not `and`: `and` binds looser than `=`, so with `and` this would
		// assign only the status test and the ttl check would be a discarded
		// expression -- negative caching would switch itself on regardless of
		// configuration.
		$negative = ($status === 404 || $status === 410)
			&& self::$negativeTtl > 0;

		if ((!$negative and $status !== 200)
		or self::hasSkipCookie($parsed['headers'])
		or self::isPersonal($parsed, $response)) {
			self::releaseRevalidation(self::cacheKey($parsed));
			return $response;
		}

		// Collapse the template engine's indentation, once, here.
		//
		// Done at store time rather than per response: the work happens on the
		// render that fills the cache and every hit afterwards is already
		// small. Before the validator is taken, so the ETag describes what will
		// actually be sent, and before compression, so what is compressed is
		// what is stored.
		//
		// gzip already handles repeated whitespace well, so the saving on the
		// wire is modest. The decompressed document is the point: it is what
		// the browser parses, what a service worker stores, and what sits in
		// memory. Measured on the front page here, 94,090 bytes became 68,842.
		// Loaded explicitly rather than through the autoloader.
		//
		// The autoloader here is a composer classmap, generated when the
		// package was installed. A class file added afterwards is not in it, so
		// class_exists() answers false for a file sitting right beside this one
		// -- which is how this arrived configured, deployed, and doing nothing
		// at all. The require is guarded so a stripped-down install without the
		// file still runs; it simply does not minify.
		if (self::$minifyHtml and !class_exists('Q_WebServer_Minify', false)) {
			$minifier = __DIR__ . DS . 'Minify.php';
			if (file_exists($minifier)) require_once $minifier;
		}

		if (self::$minifyHtml
		and class_exists('Q_WebServer_Minify', false)
		and Q_WebServer_Minify::applies($response['headers'] ?? array())) {
			$response['body'] = Q_WebServer_Minify::html($response['body'] ?? '');
		}

		// From here the stored copy and the caller's copy diverge: what is
		// stored is the wire form -- compressed, with Content-Encoding on it --
		// and the caller must not be handed that, or its send path may compress
		// an already-compressed body.
		$stored = $response;

		// The validator is taken from the body as the application produced it,
		// before any compression, because the application's output is the
		// thing whose sameness we mean. gzip is not guaranteed to produce
		// identical bytes for identical input across versions or levels, so
		// hashing the compressed form could invent a new validator for a page
		// that had not changed -- the exact failure this is here to prevent.
		$rawBody = $stored['body'] ?? '';

		// Before anything below reads the headers or the body. They must come
		// from the same value: taking headers first and compressing afterwards
		// stores a gzip body described by the headers of the plain one.
		$stored = self::encodeBody(
			$stored,
			isset($parsed['headers']['accept-encoding'])
				? $parsed['headers']['accept-encoding'] : ''
		);

		$stored['headers'] = self::withEntityTag(
			$stored['headers'] ?? array(), $rawBody
		);

		// The validator travels back to the caller, because it describes the
		// representation the caller is about to send. Nothing else does: the
		// Content-Encoding encodeBody() just added belongs to the stored copy.
		foreach ($stored['headers'] as $name => $value) {
			if (strcasecmp($name, 'ETag') === 0) {
				$response['headers'][$name] = $value;
				break;
			}
		}

		$headers = $stored['headers'];
		$cc = self::parseCacheControl($headers);

		$key = self::cacheKey($parsed);

		// Don't cache if explicitly forbidden
		if (isset($cc['no-store']) || isset($cc['private'])) {
			self::releaseRevalidation($key);
			return $response;
		}

		// Determine TTL
		$ttl = 0;
		if (isset($cc['s-maxage'])) {
			$ttl = (int) $cc['s-maxage'];
		} elseif (isset($cc['max-age'])) {
			$ttl = (int) $cc['max-age'];
		} elseif (self::$defaultTtl > 0) {
			$ttl = self::$defaultTtl;
		}

		if ($negative) {
			// A not-found carries whatever Cache-Control the application
			// happened to set, which is usually none and occasionally no-cache
			// meant for a different purpose. The lifetime of a negative answer
			// is our decision, not its.
			$ttl = self::$negativeTtl;
		}

		if ($ttl <= 0) {
			self::releaseRevalidation($key);
			return $response; // nothing to cache
		}

		$body = $stored['body'] ?? '';
		$expires = time() + $ttl;

		$entry = array(
			'status'  => $stored['status'] ?? 200,
			'headers' => $headers,
			'body'    => $body,
			'expires' => $expires,
			'stored'  => time(),

			// What this entry is of, and what it is filed under.
			//
			// purge() matches on 'url' and no entry ever carried one, so
			// `$entry['url'] ?? ''` was always the empty string and nothing
			// could ever match a pattern: purging by URL has never removed
			// anything. The key is stored beside it because it cannot be
			// recovered from the URL -- cacheKey() folds in the host and the
			// content-coding, so one URL has a `|gzip` entry and a plain one,
			// and md5($url) is neither of them.
			'url'     => $parsed['path'] . (($parsed['query'] ?? '') !== ''
			             ? '?' . $parsed['query'] : ''),
			'key'     => $key,
		);

		// Store in APCu if small enough
		if (self::$apcuEnabled && strlen($body) <= self::$apcuMaxSize) {
			self::apcuStore('qcache:' . $key, $entry, self::apcuTtl($entry));
		}

		// Into the server's memory when this is the server; from anywhere
		// else, tell the server its copy of this page may now be old.
		if (self::memoryActive()) self::memoryPut($key, $entry);
		else self::memorySignal();

		// The validators, kept apart from the page they describe.
		//
		// A returning visitor's request is answered by comparing two short
		// strings, and nothing else about the entry is needed to do it. Before
		// this, answering one meant loading the whole entry -- 9KB of gzipped
		// HTML off disk or out of shared memory -- decoding it, comparing the
		// ETag, and then discarding every byte of the body unread. That is the
		// most common request a cached site gets, and it was the most wasteful.
		//
		// A few hundred bytes per URL buys the whole thing: 271 entries on this
		// installation is about 50KB of index against 2.4MB of pages. Memory is
		// the cheap resource here and a disk read is the expensive one.
		self::indexValidators($key, $entry);

		// Always store on filesystem (APCu is per-process, lost on restart)
		$path = self::filePath($key);
		if ($path) {
			$dir = dirname($path);
			Q_WebServer_Modes::dir($dir, self::$dirMode, self::$dirModeExplicit);
			Q_WebServer_Modes::put(
				$path, self::encodeEntry($entry), LOCK_EX, self::$fileMode
			);
		}

		// The fresh copy is in place, so whoever was being served the old one
		// can stop. Released after the write, never before: released first,
		// another request could claim the re-render, find the entry still
		// expired, and render it a second time.
		self::releaseRevalidation($key);

		return $response;
	}

	/**
	 * Purge cache entries matching a URL pattern.
	 *
	 * Called by application code when content changes:
	 *   Q_WebServer_Cache::purge('/blog/my-post');
	 *   Q_WebServer_Cache::purge('#^/api/v1/#');
	 *
	 * @method purge
	 * @static
	 * @param {string} $pattern URL path or regex
	 */
	static function purge($pattern)
	{
		if (!self::$dir || !is_dir(self::$dir)) return;
		self::memorySignal();

		// If it looks like a regex (starts with a delimiter), match against files
		$isRegex = (strlen($pattern) > 2 && $pattern[0] === $pattern[strlen($pattern)-1])
			|| (strlen($pattern) > 2 && $pattern[0] === '#');

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if ($file->getExtension() !== 'json') continue;
			$entry = self::decodeEntry(file_get_contents($file->getPathname()));
			if (!$entry) continue;

			$url = $entry['url'] ?? '';
			if ($url === '') continue;
			$match = $isRegex ? preg_match($pattern, $url) : ($url === $pattern);
			if ($match) {
				@unlink($file->getPathname());
				if (self::$apcuEnabled) {
					// The key as it was filed, not one derived from the URL.
					// One URL has as many entries as it has content-codings,
					// and md5($url) names none of them.
					$key = isset($entry['key'])
						? $entry['key']
						: self::cacheKeyFromUrl($url);
					apcu_delete('qcache:' . $key);

					// The validators go with the page. Left behind, they would
					// answer 304 to a returning visitor for a page that was
					// deliberately purged -- and every reload would answer 304
					// again, so the stale version would never clear itself.
					apcu_delete('qcache:v:' . $key);
				}
			}
		}
	}

	/**
	 * Clear all cached entries.
	 * @method clear
	 * @static
	 */
	static function clear()
	{
		if (self::$apcuEnabled) {
			$iterator = new APCUIterator('#^qcache:#');
			apcu_delete($iterator);
		}
		if (self::$dir && is_dir(self::$dir)) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			$dictionary = self::dictionaryPath();
			foreach ($files as $f) {
				// The dictionary lives here but is not an entry. Clearing the
				// cache means discarding what was stored, not discarding the
				// thing that knows how to read it -- deleting it would leave
				// every subsequent write uncompressed until someone noticed,
				// which is the kind of silent regression that survives for
				// months.
				if ($dictionary !== null and $f->getPathname() === $dictionary) continue;
				$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
			}
		}
		self::memorySignal();
	}

	/**
	 * Delete expired entries from the filesystem tier.
	 *
	 * get() only unlinks an expired entry when that exact URL is requested
	 * again, so anything never asked for a second time stays on disk for
	 * good. APCu expires its own entries and needs nothing here.
	 *
	 * Runs in the parent event loop, so it stops after $budget files to
	 * keep a large directory from stalling request dispatch; the next run
	 * picks up where this one left off.
	 *
	 * Config, under Q.web.cache.sweep:
	 *   every   seconds between runs (0 turns the sweep off)
	 *   budget  most files to examine in one pass
	 *   maxAge  seconds after which an entry goes regardless of its own
	 *           expiry. An entry lives as long as the response asked for,
	 *           and a Cache-Control: public, max-age=31536000 keeps one on
	 *           disk for a year -- fine per URL, unbounded across a site
	 *           that generates them. 0 respects every expiry as given.
	 *
	 * @method sweep
	 * @static
	 * @param {integer} [$budget=null] Overrides the configured budget
	 * @return {array} ['scanned' => int, 'removed' => int, 'done' => bool]
	 */
	static function sweep($budget = null)
	{
		if ($budget === null) {
			$budget = (int) Q_Config::get('Q', 'web', 'cache', 'sweep', 'budget', 2000);
		}
		$maxAge = (int) Q_Config::get('Q', 'web', 'cache', 'sweep', 'maxAge', 0);
		$scanned = 0;
		$removed = 0;
		$done = true;
		if (!self::$dir or !is_dir(self::$dir)) {
			return compact('scanned', 'removed', 'done');
		}
		$now = time();
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($files as $f) {
			if (!$f->isFile()) continue;
			if ($scanned >= $budget) { $done = false; break; }
			++$scanned;
			$path = $f->getPathname();
			if ($maxAge > 0) {
				$mtime = @filemtime($path);
				if ($mtime and $mtime <= $now - $maxAge) {
					if (@unlink($path)) ++$removed;
					continue;
				}
			}
			$entry = self::decodeEntry(@file_get_contents($path));
			// A file that will not parse can never be served either.
			if (!is_array($entry)
			or (isset($entry['expires']) and $entry['expires'] > 0 and $entry['expires'] <= $now)) {
				if (@unlink($path)) ++$removed;
			}
		}
		return compact('scanned', 'removed', 'done');
	}

	// ── Internals ────────────────────────────────────────

	/**
	 * How old a stored response is, in seconds.
	 *
	 * RFC 9111 requires a cache to generate an Age field whenever it reuses a
	 * stored response, and the requirement is not a formality. Age is how a
	 * client, and every cache between here and it, works out how much of the
	 * response's freshness lifetime is left. Without it a response already 290
	 * seconds into a 300 second lifetime looks newly generated, so the next
	 * cache downstream holds it for a further 300 -- and the lifetime
	 * multiplies at every hop rather than running out.
	 *
	 * Measured from when the entry was stored, which for this cache is when the
	 * application produced it, so it is the age of the representation rather
	 * than of the file on disk.
	 *
	 * @method age
	 * @static
	 * @param {array} $entry
	 * @return {string} seconds, never negative
	 */
	static function age($entry)
	{
		$stored = isset($entry['stored']) ? (int) $entry['stored'] : 0;
		if ($stored <= 0) return '0';
		return (string) max(0, time() - $stored);
	}

	/**
	 * Give a response a validator derived from what it says.
	 *
	 * Without this a cached page carries only Last-Modified, and that is the
	 * moment the entry was stored rather than the moment the page changed.
	 * When the entry expires and is built again the timestamp moves even if
	 * every byte of the HTML is the same, so the next reload revalidates,
	 * fails, and pulls the whole document down again. A visitor reloading a
	 * page nobody has edited pays full price once per lifetime, forever.
	 *
	 * A hash of the body does not move unless the body does, which is the
	 * property wanted: an unchanged page answers 304 no matter how often the
	 * cache behind it is rebuilt.
	 *
	 * An application that set its own ETag knows something we do not, so it
	 * is left alone. The tag is strong -- it is a hash of the exact bytes --
	 * and is marked with the content-coding, because RFC 9110 scopes a
	 * validator to the selected representation and the gzip and identity
	 * forms of a page are two representations.
	 *
	 * @method withEntityTag
	 * @static
	 * @param {array} $headers
	 * @param {string} $rawBody the body before any compression
	 * @return {array} the headers, with an ETag
	 */
	static function withEntityTag($headers, $rawBody)
	{
		if (!is_array($headers)) return array();
		foreach ($headers as $name => $value) {
			if (strcasecmp($name, 'ETag') === 0 and $value !== '') {
				return $headers;
			}
		}

		$tag = substr(sha1($rawBody), 0, 27);

		// Same bytes, different coding, different representation.
		foreach ($headers as $name => $value) {
			if (strcasecmp($name, 'Content-Encoding') === 0 and $value !== '') {
				$tag .= '-' . preg_replace('/[^A-Za-z0-9]+/', '', $value);
				break;
			}
		}

		$headers['ETag'] = '"' . $tag . '"';
		return $headers;
	}

	/**
	 * Remember just enough about an entry to answer a conditional request.
	 *
	 * @method indexValidators
	 * @static
	 * @param {string} $key
	 * @param {array} $entry
	 */
	private static function indexValidators($key, $entry)
	{
		if (!self::$apcuEnabled) return;

		// The validators, and everything a 304 is allowed to carry.
		//
		// A 304 is not only an answer, it is an update: RFC 9111 has the client
		// replace the stored headers with the ones it carries. Sending back
		// only ETag and Last-Modified left the client's copy with its original
		// expiry rather than a renewed one, so it revalidated sooner and more
		// often -- the opposite of the point. Keeping the freshness directives
		// in the index costs a few dozen bytes per page.
		// Age travels with the rest, because a 304 updates the client's stored
		// headers just as a 200 does. Omitting it leaves the client believing
		// its copy was revalidated against a freshly generated response.
		$keep = array('etag', 'last-modified', 'cache-control', 'expires', 'vary', 'age');
		$headers = array();
		foreach ($entry['headers'] as $name => $value) {
			if (in_array(strtolower($name), $keep, true)) {
				$headers[$name] = $value;
			}
		}
		if (!isset($headers['ETag']) and !isset($headers['Last-Modified'])) {
			$has = false;
			foreach ($headers as $n => $v) {
				if (strcasecmp($n, 'ETag') === 0 or strcasecmp($n, 'Last-Modified') === 0) {
					$has = true; break;
				}
			}
			if (!$has) return; // nothing to validate against
		}

		$ttl = self::ttlRemaining($entry);
		if ($ttl <= 0) return;

		self::apcuStore('qcache:v:' . $key, array(
			'headers' => $headers,
			'expires' => isset($entry['expires']) ? $entry['expires'] : 0,
			'stored'  => isset($entry['stored']) ? (int) $entry['stored'] : time(),
		), $ttl);
	}

	/**
	 * Answer a conditional request without loading the page it is about.
	 *
	 * Returns a 304 when the client's validators match what we hold, and null
	 * whenever anything is less than certain -- no index, an expired index, a
	 * request carrying no validators. Every null simply means the ordinary
	 * path runs, so being wrong here costs a little work and never a wrong
	 * answer. Being *right* skips a disk read and a decode on what is, for a
	 * site people come back to, the most common request there is.
	 *
	 * @method notModifiedFromIndex
	 * @static
	 * @param {array} $parsed
	 * @param {string} $key
	 * @return {array|null}
	 */
	private static function notModifiedFromIndex($parsed, $key)
	{
		if (!self::$apcuEnabled) return null;

		$headers = $parsed['headers'];
		if (!isset($headers['if-none-match'])
		and !isset($headers['if-modified-since'])) return null;

		$index = apcu_fetch('qcache:v:' . $key);
		if (!is_array($index)) return null;

		// A stale index must not answer. Whether the page may still be served
		// past its expiry is a decision made in get(), with the entry in hand.
		if (!empty($index['expires']) and $index['expires'] < time()) return null;
		// Nor one from before the last "clear everything": a 304 would keep the
		// browser on the page that was cleared.
		if (self::isOldGeneration($index)) {
			apcu_delete('qcache:v:' . $key);
			return null;
		}

		if (!isset($index['headers']) or !is_array($index['headers'])) return null;

		return self::notModified(array('headers' => $index['headers']), $headers);
	}

	/**
	 * Turn a cached hit into a 304 when the client already has it.
	 *
	 * A returning visitor sends If-None-Match or If-Modified-Since with what
	 * they hold. If it matches, the right answer is 304 and no body at all --
	 * a few hundred bytes instead of the page.
	 *
	 * Static files have always done this. Cached pages never did, so a reload
	 * of a page the browser already had still carried the whole document:
	 * observed at 9.73 kB transferred against a Last-Modified identical to the
	 * one just sent. The browser said it had the page, and was sent it anyway.
	 *
	 * This is also what makes a reload stay fast rather than merely start fast.
	 * The body is the part that scales with the page; once it is gone, what is
	 * left is a round trip and a couple of hundred bytes, whatever the page.
	 *
	 * RFC 9110: If-None-Match wins outright where both are present, and a
	 * validator that does not match means the full response.
	 *
	 * @method notModified
	 * @static
	 * @param {array} $entry a hit from get()
	 * @param {array} $requestHeaders
	 * @return {array|null} the 304 to send, or null to send the entry
	 */
	static function notModified($entry, $requestHeaders)
	{
		if (!is_array($entry)) return null;

		$headers = isset($entry['headers']) ? $entry['headers'] : array();
		$etag = null;
		$lastModified = null;
		foreach ($headers as $k => $v) {
			$lk = strtolower($k);
			if ($lk === 'etag') $etag = trim((string) $v);
			else if ($lk === 'last-modified') $lastModified = trim((string) $v);
		}

		$noneMatch = isset($requestHeaders['if-none-match'])
			? trim((string) $requestHeaders['if-none-match']) : '';
		$modifiedSince = isset($requestHeaders['if-modified-since'])
			? trim((string) $requestHeaders['if-modified-since']) : '';

		$fresh = false;

		if ($noneMatch !== '' and $etag !== null and $etag !== '') {
			// A weak comparison, which is what a cached representation wants:
			// W/"x" and "x" describe the same bytes for this purpose.
			$strip = function ($t) {
				$t = trim($t);
				if (strncasecmp($t, 'W/', 2) === 0) $t = substr($t, 2);
				return trim($t, '"');
			};
			$want = $strip($etag);
			foreach (explode(',', $noneMatch) as $candidate) {
				$candidate = trim($candidate);
				if ($candidate === '*' or $strip($candidate) === $want) {
					$fresh = true;
					break;
				}
			}
			// If-None-Match was present and decided the matter, either way.
			if (!$fresh) return null;
		} else if ($modifiedSince !== '' and $lastModified !== null) {
			$a = strtotime($modifiedSince);
			$b = strtotime($lastModified);
			// Not "equal": a client may hold something newer than the entry
			// after a cache was rebuilt, and it is still current.
			if ($a !== false and $b !== false and $b <= $a) $fresh = true;
		}

		if (!$fresh) return null;

		// 304 carries the validators and the caching metadata, and nothing
		// that describes a body, because there is no body.
		$out = array();
		foreach ($headers as $k => $v) {
			$lk = strtolower($k);
			if (in_array($lk, array(
				'etag', 'last-modified', 'cache-control', 'expires', 'vary', 'date',
				'age'
			), true)) {
				$out[$k] = $v;
			}
		}

		return array(
			'status' => 304,
			'headers' => $out,
			'body' => '',
		);
	}

	/**
	 * Types worth compressing, and the size below which it is not worth it.
	 *
	 * @property $compressibleTypes
	 * @type {array}
	 */
	static $compressibleTypes = array(
		'text/', 'application/json', 'application/javascript', 'application/xml',
		'application/xhtml', 'image/svg+xml', 'application/rss', 'application/atom',
		'application/x-javascript', 'application/ld+json',
	);

	/** @var integer Below this many bytes, compressing costs more than it saves. */
	static $compressMinSize = 1024;

	/**
	 * Compress a body once, so what is stored is what goes on the wire.
	 *
	 * The key already distinguishes encoding variants, so the gzip row and the
	 * plain row are separate entries -- and both held the same uncompressed
	 * bytes, so every hit on the gzip row compressed the page again to produce
	 * output identical to the time before. On a 250KB page that is 4.19ms per
	 * hit, against 1.89ms of measured CPU for an average request: the largest
	 * single thing the server did with a response it had already finished.
	 *
	 * Implemented here, not called out to. An earlier attempt reached for
	 * another class behind class_exists() with autoloading disabled, which
	 * silently did nothing on one of the two protocol paths.
	 *
	 * It returns a new response and never edits in place, because the caller
	 * must take the headers and the body from the same value. The first attempt
	 * at this ran after put() had already taken a copy of the headers, so the
	 * entry was stored with the old headers and the new body -- a gzip payload
	 * with no Content-Encoding, which is unreadable rubbish to a browser.
	 *
	 * @method encodeBody
	 * @static
	 * @param {array} $response
	 * @param {string} $accept the request's Accept-Encoding
	 * @return {array}
	 */
	static function encodeBody($response, $accept)
	{
		if (!is_array($response)) return $response;

		$body = isset($response['body']) ? $response['body'] : '';
		if (!is_string($body) or strlen($body) < self::$compressMinSize) return $response;

		$headers = isset($response['headers']) ? $response['headers'] : array();
		$contentType = '';
		foreach ($headers as $k => $v) {
			$lk = strtolower($k);
			if ($lk === 'content-encoding' and $v !== '') return $response;
			if ($lk === 'content-type') $contentType = strtolower((string) $v);
		}

		if (self::storedCoding($accept) !== 'gzip') return $response;
		if (!function_exists('gzencode')) return $response;

		$ok = false;
		foreach (self::$compressibleTypes as $prefix) {
			if (strpos($contentType, $prefix) === 0) { $ok = true; break; }
		}
		if (!$ok) return $response;

		$compressed = @gzencode($body, 6);
		if ($compressed === false or strlen($compressed) >= strlen($body)) {
			return $response;
		}

		$headers['Content-Encoding'] = 'gzip';
		$headers['Vary'] = 'Accept-Encoding';
		$headers['Content-Length'] = (string) strlen($compressed);

		$response['headers'] = $headers;
		$response['body'] = $compressed;
		return $response;
	}

	/**
	 * Write an entry as a metadata line followed by the body, raw.
	 *
	 * The body used to live inside the JSON. That meant every hit ran
	 * json_decode over a document containing a whole page, unescaping every
	 * byte of it to hand back a string that had been a string all along.
	 * Measured on a 250KB page: 1.108ms to decode against 0.049ms to read the
	 * file, so 96% of the cost of a cache hit was undoing an encoding that
	 * need never have happened.
	 *
	 * The format is one line of JSON, a newline, then the bytes. The metadata
	 * contains no newline because json_encode escapes them, so the first
	 * newline is unambiguously the separator.
	 *
	 * @method encodeEntry
	 * @static
	 * @param {array} $entry
	 * @return {string}
	 */
	/**
	 * Where the shared dictionary lives, beside the entries it describes.
	 * @method dictionaryPath
	 * @static
	 * @return {string|null}
	 */
	static function dictionaryPath()
	{
		return self::$dir ? (self::$dir . DS . 'middle-out.dict') : null;
	}

	/**
	 * The shared dictionary, loaded once per process.
	 *
	 * Absent is a perfectly good answer: without one, entries are stored the
	 * ordinary way and everything still works. That is what makes this safe to
	 * switch on before a dictionary has ever been built.
	 *
	 * @method dictionary
	 * @static
	 * @return {string} '' when there is none
	 */
	static function dictionary()
	{
		if (self::$dictionary !== null) return self::$dictionary;

		self::$dictionary = '';
		$path = self::dictionaryPath();
		if ($path and is_file($path)) {
			$raw = @file_get_contents($path);
			if (is_string($raw) and $raw !== '') self::$dictionary = $raw;
		}
		return self::$dictionary;
	}

	/**
	 * Make sure the compressor class is loaded.
	 *
	 * Required explicitly rather than autoloaded, for the same reason the
	 * minifier is: the autoloader is a composer classmap generated at install
	 * time, and a class added afterwards is not in it.
	 *
	 * @method middleOutAvailable
	 * @static
	 * @return {boolean}
	 */
	static function middleOutAvailable()
	{
		if (!self::$middleOut) return false;
		if (!class_exists('Q_WebServer_MiddleOut', false)) {
			$file = __DIR__ . DS . 'MiddleOut.php';
			if (!file_exists($file)) return false;
			require_once $file;
		}
		return Q_WebServer_MiddleOut::available() and self::dictionary() !== '';
	}

	static function encodeEntry($entry)
	{
		$body = isset($entry['body']) ? $entry['body'] : '';
		$meta = $entry;
		unset($meta['body']);
		$meta['v'] = 2;

		// Compressed against the shared dictionary when one is configured and
		// present. Marked in the metadata rather than sniffed from the body,
		// so a reader never has to guess what it is holding.
		if (self::middleOutAvailable() and $body !== '') {
			$packed = Q_WebServer_MiddleOut::compress($body, self::dictionary());
			if ($packed !== null and strlen($packed) < strlen($body)) {
				$body = $packed;
				$meta['mo'] = 1;
			}
		}

		return json_encode($meta) . "\n" . $body;
	}

	/**
	 * Read an entry written by encodeEntry, or by the version before it.
	 *
	 * An installation upgrading in place has a cache full of the old shape, and
	 * throwing it away would mean every page rendering once more for no reason.
	 * A leading "{" and a newline says which this is; anything without the
	 * version marker is read the old way.
	 *
	 * @method decodeEntry
	 * @static
	 * @param {string} $raw
	 * @return {array|null}
	 */
	static function decodeEntry($raw)
	{
		if (!is_string($raw) or $raw === '') return null;

		$newline = strpos($raw, "\n");
		if ($newline !== false) {
			$meta = json_decode(substr($raw, 0, $newline), true);
			if (is_array($meta) and isset($meta['v']) and $meta['v'] === 2) {
				$body = substr($raw, $newline + 1);

				if (!empty($meta['mo'])) {
					// An entry written against a dictionary this process does
					// not have is unreadable, and that is the correct outcome:
					// null here becomes a cache miss, which costs a render. The
					// alternative -- guessing -- costs a corrupted page.
					if (!self::middleOutAvailable()) return null;
					$body = Q_WebServer_MiddleOut::decompress($body, self::dictionary());
					if ($body === null) return null;
					unset($meta['mo']);
				}

				$meta['body'] = $body;
				return $meta;
			}
		}

		// Written by an earlier version, with the body inside the JSON.
		$entry = json_decode($raw, true);
		return is_array($entry) ? $entry : null;
	}

	/**
	 * Whether a response belongs to one visitor and must not be stored.
	 *
	 * The skip cookies catch a signed-in visitor's request. They could not
	 * catch a response that SETS a cookie -- a session started, a login
	 * answered -- which was stored with its Set-Cookie and handed, cookie
	 * and all, to every visitor after it until it expired: one visitor's
	 * session given to the next. Nor a request that brought credentials of
	 * its own in an Authorization header, whose page may be made for that
	 * caller alone. Neither is stored.
	 *
	 * @method isPersonal
	 * @static
	 * @param {array} $parsed
	 * @param {array} $response
	 * @return {boolean}
	 */
	static function isPersonal($parsed, $response)
	{
		if (!empty($parsed['headers']['authorization'])) return true;
		if (!empty($response['cookies'])) return true;
		foreach ((array) ($response['headers'] ?? array()) as $name => $value) {
			if (strcasecmp((string) $name, 'Set-Cookie') === 0 and $value !== '' and $value !== array()) {
				return true;
			}
		}
		return false;
	}

	static function cacheKey($parsed)
	{
		$host = $parsed['headers']['host'] ?? '';
		$parts = $host . $parsed['path'] . '?' . ($parsed['query'] ?? '');
		// The document root the host is served from, so two names routed to
		// different roots never share an entry, whatever else the key holds.
		if (class_exists('Q_WebServer_Domains')) {
			$root = Q_WebServer_Domains::resolveRoot($host);
			if ($root !== null) $parts .= '|root=' . $root;
		}

		// Filed under the coding the stored body will actually be in, which
		// is what encodeBody() decides from the same header. Keyed on what the
		// client could accept instead, a brotli-capable browser got a |br entry
		// holding the very gzip bytes of the |gzip entry beside it: every page
		// rendered, written and held in APCu twice for no difference in output.
		$coding = self::storedCoding($parsed['headers']['accept-encoding'] ?? '');
		if ($coding !== '') $parts .= '|' . $coding;

		return md5($parts);
	}

	/**
	 * The content-coding a response for this Accept-Encoding is stored in:
	 * 'gzip', or '' for the body as rendered. The one place that decides it,
	 * for both the key and encodeBody(), so the two cannot drift apart.
	 * @method storedCoding
	 * @static
	 * @param {string} $acceptEncoding
	 * @return {string}
	 */
	static function storedCoding($acceptEncoding)
	{
		// The same reading of Accept-Encoding as everything else the server
		// compresses: a coding refused with q=0 is refused. A substring test
		// took "gzip;q=0" for a yes and sent gzip to a client that said no.
		if (!class_exists('Q_WebServer_Headers', false)) require_once __DIR__ . '/Headers.php';
		return Q_WebServer_Headers::acceptsCoding((string) $acceptEncoding, 'gzip') ? 'gzip' : '';
	}

	static function cacheKeyFromUrl($url)
	{
		return md5($url);
	}

	static function filePath($key)
	{
		if (!self::$dir) return null;
		// Two-level directory to avoid too many files in one dir
		return self::$dir . DS . substr($key, 0, 2) . DS . $key . '.json';
	}

	/**
	 * Check if request has any cookies from the skip list.
	 * If a session cookie is present, the response is likely
	 * personalized and shouldn't be cached.
	 */
	/**
	 * Is this a request to renew the stored copy rather than read it?
	 *
	 * @method isRefreshRequest
	 * @static
	 * @param {array} $headers
	 * @return {boolean}
	 */
	static function isRefreshRequest($headers)
	{
		$name = 'x-cache-refresh';
		if (class_exists('Q_Config')) {
			$name = strtolower((string) Q_Config::get(
				'Q', 'web', 'cache', 'refreshHeader', 'x-cache-refresh'
			));
		}
		if ($name === '') return false;
		return !empty($headers[$name]);
	}

	static function hasSkipCookie($headers)
	{
		$cookieHeader = $headers['cookie'] ?? '';
		if (!$cookieHeader || empty(self::$skipCookies)) return false;

		foreach (self::$skipCookies as $name) {
			// A configured name matches itself and anything it prefixes, so
			// that a session cookie whose real name carries a suffix is still
			// recognised. This is not a nicety: Exponential sets
			// SessionNamePrefix=eZSESSID with SessionNamePerSiteAccess
			// enabled, so the cookie is eZSESSID<digest> and never plain
			// eZSESSID. Matching only the exact name meant the configured skip
			// never fired, and a signed-in visitor's pages were stored under a
			// key with no session in it and served to everybody else.
			//
			// Over-matching is the safe direction here. A name that catches a
			// cookie it did not mean to costs cache hits; a name that misses
			// the session cookie costs one visitor's page to another.
			if (preg_match('/(?:^|;\s*)' . preg_quote($name, '/') . '[^=;]*=/',
					$cookieHeader)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse Cache-Control header into directives.
	 */
	static function parseCacheControl($headers)
	{
		$cc = '';
		foreach ($headers as $k => $v) {
			if (strtolower($k) === 'cache-control') { $cc = $v; break; }
		}
		if (!$cc) return array();

		$directives = array();
		foreach (explode(',', $cc) as $part) {
			$part = trim($part);
			if (strpos($part, '=') !== false) {
				list($k, $v) = explode('=', $part, 2);
				$directives[trim($k)] = trim($v);
			} else {
				$directives[$part] = true;
			}
		}
		return $directives;
	}

	/**
	 * How long APCu keeps an entry: its lifetime plus the stale window.
	 *
	 * Kept for the lifetime alone, APCu dropped the copy the moment the page
	 * expired -- exactly when staleWhileRevalidate starts serving it -- so
	 * every stale hit, on a page busy enough to be stale-served at all, was a
	 * disk read and a decode. Whether an entry may still be served is decided
	 * in get() from its own expiry, never from APCu's.
	 */
	static function apcuTtl($entry)
	{
		if (($entry['expires'] ?? 0) <= 0) return 86400;
		return max(1, $entry['expires'] + max(0, self::$staleWhileRevalidate) - time());
	}

	static function ttlRemaining($entry)
	{
		if ($entry['expires'] <= 0) return 86400;
		return max(1, $entry['expires'] - time());
	}

	/**
	 * Stats for the dashboard and /Q/health.
	 *
	 * hits, misses and hitRate as always; then where the hits came from, so a
	 * cache that has quietly fallen back to disk shows it, and what APCu itself
	 * says about its segment. Read at most once a second (the stats throttle),
	 * so the two APCu calls cost nothing that matters.
	 */
	static function stats()
	{
		$total = self::$hits + self::$misses;
		$out = array(
			'hits' => self::$hits,
			'misses' => self::$misses,
			'hitRate' => $total > 0 ? round(self::$hits / $total * 100, 1) : 0,
			'stale' => self::$stale,
			'hitsFrom' => self::$hitsFrom,
			'memory' => array(
				'enabled' => self::$memoryMaxEntries > 0,
				'entries' => count(self::$memory),
				'bytes' => self::$memoryBytes,
				'maxEntries' => self::$memoryMaxEntries,
				'maxBytes' => self::$memoryMaxBytes,
				'evictions' => self::$memoryEvictions,
			),
			'apcu' => array(
				'enabled' => self::$apcuEnabled,
				'maxSize' => self::$apcuMaxSize,
				'storeFailures' => self::$apcuStoreFailures,
				'warnings' => self::$apcuWarnings,
			),
		);
		if (self::$apcuEnabled and function_exists('apcu_sma_info')) {
			$sma = @apcu_sma_info(true);
			if (is_array($sma)) {
				$size = (int) ($sma['num_seg'] ?? 1) * (int) ($sma['seg_size'] ?? 0);
				$avail = (int) ($sma['avail_mem'] ?? 0);
				$out['apcu']['memory'] = array(
					'size' => $size,
					'available' => $avail,
					'usedPercent' => $size > 0 ? round(($size - $avail) / $size * 100, 1) : 0,
				);
			}
			$info = @apcu_cache_info(true);
			if (is_array($info)) {
				$out['apcu']['entries'] = (int) ($info['num_entries'] ?? 0);
				$out['apcu']['expunges'] = (int) ($info['expunges'] ?? 0);
			}
		}
		return $out;
	}
}
