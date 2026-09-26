<?php
/**
 * Q_WebServer_Compat — framework compatibility layer.
 *
 * In CLI SAPI (which the webserver uses), PHP's built-in header(),
 * setcookie(), http_response_code(), session_start() etc. either
 * silently fail or go nowhere. This class provides two mechanisms
 * to make framework code work without modification:
 *
 * 1. Source transformation: token_get_all() rewrites global function
 *    calls in included files (header() → Q_Response::header(), etc.)
 *    Transformed files are cached to disk for performance.
 *
 * 2. URL rewriting: routes all requests to a front controller
 *    (e.g., index.php for Laravel/WordPress) based on config.
 *
 * 3. $_FILES population from multipart/form-data bodies.
 *
 * 4. Session handling with file locking.
 *
 * Enable in config:
 *   { "Q": { "compat": { "skipSourceCodeTransform": false, "rewrite": "index.php" } } }
 *
 * @class Q_WebServer_Compat
 */
class Q_WebServer_Compat
{
	/**
	 * Function replacements: built-in → Q_Response/Q_Request equivalent.
	 * Keys are lowercase function names.
	 */
	private static $replacements = array(
		'header'               => 'Q_WebServer_Compat::_header',
		'setcookie'            => 'Q_WebServer_Compat::_setcookie',
		'setrawcookie'         => 'Q_WebServer_Compat::_setrawcookie',
		'http_response_code'   => 'Q_WebServer_Compat::_http_response_code',
		'headers_sent'         => 'Q_WebServer_Compat::_headers_sent',
		'headers_list'         => 'Q_WebServer_Compat::_headers_list',
		'header_remove'        => 'Q_WebServer_Compat::_header_remove',
		'session_start'        => 'Q_WebServer_Compat::_session_start',
		'session_id'           => 'Q_WebServer_Compat::_session_id',
		'session_name'         => 'Q_WebServer_Compat::_session_name',
		'session_set_cookie_params' => 'Q_WebServer_Compat::_session_set_cookie_params',
		'session_get_cookie_params' => 'Q_WebServer_Compat::_session_get_cookie_params',
		'session_write_close'  => 'Q_WebServer_Compat::_session_write_close',
		'session_regenerate_id'=> 'Q_WebServer_Compat::_session_regenerate_id',
		'session_destroy'      => 'Q_WebServer_Compat::_session_destroy',
		'session_status'       => 'Q_WebServer_Compat::_session_status',
		'move_uploaded_file'   => 'Q_WebServer_Compat::_move_uploaded_file',
		'is_uploaded_file'     => 'Q_WebServer_Compat::_is_uploaded_file',
		'ini_get'              => 'Q_WebServer_Compat::_ini_get',
		'ini_set'              => 'Q_WebServer_Compat::_ini_set',
		'set_time_limit'       => 'Q_WebServer_Compat::_set_time_limit',
		'getallheaders'        => 'Q_WebServer_Compat::_getallheaders',
		'phpinfo'              => 'Q_WebServer_Compat::_phpinfo',
		'apache_request_headers' => 'Q_WebServer_Compat::_getallheaders',
		// Octane safety — lifecycle functions that leak state in persistent workers
		'register_shutdown_function' => 'Q_WebServer_Compat::_register_shutdown_function',
		'set_error_handler'    => 'Q_WebServer_Compat::_set_error_handler',
		'set_exception_handler'=> 'Q_WebServer_Compat::_set_exception_handler',
		'restore_error_handler'=> 'Q_WebServer_Compat::_restore_error_handler',
		'restore_exception_handler' => 'Q_WebServer_Compat::_restore_exception_handler',
		'spl_autoload_register'=> 'Q_WebServer_Compat::_spl_autoload_register',
		'spl_autoload_unregister' => 'Q_WebServer_Compat::_spl_autoload_unregister',
		'putenv'               => 'Q_WebServer_Compat::_putenv',
		// Existence and type checks, answered without the file wrapper --
		// which cannot reach the disk without re-registering itself, and
		// every registration is a resource the worker keeps. See
		// Q_WebServer_CompatFileWrapper::unwrap().
		'file_exists'          => 'Q_WebServer_Compat::_file_exists',
		'is_dir'               => 'Q_WebServer_Compat::_is_dir',
		'is_file'              => 'Q_WebServer_Compat::_is_file',
		// Each returns one number, so it can be answered without the file
		// wrapper and without a partial stat reaching PHP's stat cache.
		'filemtime'            => 'Q_WebServer_Compat::_filemtime',
		'filesize'             => 'Q_WebServer_Compat::_filesize',
		// The answers above are remembered for the rest of the request, so
		// "forget what you know about files" has to reach that memory too.
		'clearstatcache'       => 'Q_WebServer_Compat::_clearstatcache',
		// And so does running another program: it can create, change or
		// remove files the wrapper never sees. Exponential makes image
		// variations with ImageMagick through system(), then checks the
		// file it expects; a remembered "not there" from before would say
		// the new file is missing.
		'exec'                 => 'Q_WebServer_Compat::_exec',
		'system'               => 'Q_WebServer_Compat::_system',
		'passthru'             => 'Q_WebServer_Compat::_passthru',
		'shell_exec'           => 'Q_WebServer_Compat::_shell_exec',
		'proc_close'           => 'Q_WebServer_Compat::_proc_close',
		'pclose'               => 'Q_WebServer_Compat::_pclose',
	);

	/**
	 * Replacement for clearstatcache(): PHP's stat cache and the wrapper's
	 * per-request memory of stats and includes.
	 *
	 * Without it, filemtime()/filesize()/stat() kept their first answer for
	 * the whole request whatever the application did: a file another process
	 * rewrote every 50ms showed one or two sizes to a worker and thirty to
	 * plain PHP. Exponential's eZFSFileHandler::loadMetaData($force) is
	 * exactly clearstatcache() followed by a stat, to see a cache file that
	 * another request has just regenerated.
	 *
	 * @method _clearstatcache
	 * @static
	 */
	static function _clearstatcache($clearRealpathCache = false, $filename = '')
	{
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
			Q_WebServer_CompatFileWrapper::dropStats();
		}
		\clearstatcache((bool) $clearRealpathCache, (string) $filename);
	}

	/**
	 * After another program ran: forget what is remembered about files,
	 * here and in PHP's own stat cache. See the replacements for why.
	 */
	private static function forgetFilesAfterProcess()
	{
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
			Q_WebServer_CompatFileWrapper::dropStats();
		}
		\clearstatcache();
	}

	/** exec(), then forget remembered file facts. */
	static function _exec($command, &$output = null, &$result_code = null)
	{
		try {
			return \exec($command, $output, $result_code);
		} finally {
			self::forgetFilesAfterProcess();
		}
	}

	/** system(), then forget remembered file facts. */
	static function _system($command, &$result_code = null)
	{
		try {
			return \system($command, $result_code);
		} finally {
			self::forgetFilesAfterProcess();
		}
	}

	/** passthru(), then forget remembered file facts. */
	static function _passthru($command, &$result_code = null)
	{
		try {
			return \passthru($command, $result_code);
		} finally {
			self::forgetFilesAfterProcess();
		}
	}

	/** shell_exec(), then forget remembered file facts. */
	static function _shell_exec($command)
	{
		try {
			return \shell_exec($command);
		} finally {
			self::forgetFilesAfterProcess();
		}
	}

	/** proc_close(): the program has finished; forget remembered file facts. */
	static function _proc_close($process)
	{
		try {
			return \proc_close($process);
		} finally {
			self::forgetFilesAfterProcess();
		}
	}

	/** pclose(): the program has finished; forget remembered file facts. */
	static function _pclose($handle)
	{
		try {
			return \pclose($handle);
		} finally {
			self::forgetFilesAfterProcess();
		}
	}

	/** @var bool Whether the phar:// scheme is currently wrapped for transforms. */
	private static $pharWrapped = false;

	/** @var array In-memory transform cache: realpath → ['source' => ..., 'mtime' => ...] */
	private static $transformCache = array();

	/** @var bool Whether compat mode is active */
	private static $enabled = false;

	/** @var array Uploaded file paths (for move_uploaded_file validation) */
	static $uploadedFiles = array();

	/** @var bool Whether headers have been flushed to the client */
	private static $headersSent = false;

	/** @var bool Whether a session is currently active */
	private static $sessionActive = false;

	/**
	 * The session id this layer manages. PHP's own session machinery is
	 * not used, so its session_id() is not the place to keep it.
	 * @var string
	 */
	private static $sessionId = '';

	/**
	 * The session name and cookie parameters the application asked for.
	 * Kept here for the same reason as the id: PHP's session_name() and
	 * session_set_cookie_params() refuse once output has begun, which in a
	 * worker it always has, so the application's choice was dropped with a
	 * warning -- every session went out as PHPSESSID with a browser-session
	 * lifetime, and an application that looks for its own cookie name to
	 * decide whether a visitor is signed in never found it.
	 * null = not set this request; PHP's configured value applies.
	 * @var string|null
	 */
	private static $sessionName = null;

	/** @var array|null Cookie parameters set this request, as session_get_cookie_params() returns them */
	private static $sessionCookieParams = null;

	/** @var string Current session file path */
	private static $sessionFile = '';

	/** @var resource|null Session file handle (held for locking) */
	private static $sessionFp = null;

	/** @var array Request headers (set by the server before dispatch) */
	private static $requestHeaders = array();

	// ── Octane lifecycle state ────────────────────────────

	/** @var array Shutdown callbacks registered during this request */
	private static $shutdownCallbacks = array();

	/** @var array Error handler stack for this request */
	private static $errorHandlerStack = array();

	/** @var callable|null Original error handler at boot time */
	private static $bootErrorHandler = null;

	/** @var callable|null Original exception handler at boot time */
	private static $bootExceptionHandler = null;

	/** @var bool Whether boot handlers have been captured */
	private static $bootHandlersCaptured = false;

	/** @var array Autoloaders registered during this request (for cleanup) */
	private static $requestAutoloaders = array();

	/** @var array Boot-time autoloader list */
	private static $bootAutoloaders = array();

	/** @var bool Whether boot autoloaders have been captured */
	private static $bootAutoloadersCaptured = false;

	/** @var array Environment variables set during this request */
	private static $requestEnvVars = array();

	/** @var array ini values changed during this request: key => old_value */
	private static $requestIniChanges = array();

	/** @var array Boot-time ini overrides from config (snapshot once) */
	private static $bootIniOverrides = array();

	/** @var bool Whether boot ini has been captured */
	private static $bootIniCaptured = false;

	/**
	 * Initialize the compatibility layer.
	 * Call this before dispatching any user PHP.
	 */
	static function init()
	{
		$enabled = !Q_Config::get('Q', 'compat', 'skipSourceCodeTransform', false);
		if (!$enabled) return;

		self::$enabled = true;

		// Capture boot-time error/exception handlers (once)
		if (!self::$bootHandlersCaptured) {
			// Push a dummy, get the current, restore it
			self::$bootErrorHandler = set_error_handler(function () { return false; });
			restore_error_handler();
			self::$bootExceptionHandler = set_exception_handler(function () {});
			restore_exception_handler();
			self::$bootHandlersCaptured = true;
		}

		// Capture boot-time autoloaders (once)
		if (!self::$bootAutoloadersCaptured) {
			self::$bootAutoloaders = spl_autoload_functions() ?: array();
			self::$bootAutoloadersCaptured = true;
		}

		// Capture boot-time ini overrides (once)
		if (!self::$bootIniCaptured) {
			self::$bootIniOverrides = Q_Config::get('Q', 'compat', 'ini', array());
			self::$bootIniCaptured = true;
		}

		// Register custom include wrapper
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');

		// And for phar://, when an engine archive is in use.
		//
		// The transform is what makes header(), setcookie() and their kin work
		// at all under the CLI SAPI, and it only reaches code that is included
		// through a wrapped scheme. Code included from inside an archive went
		// through phar:// instead, so none of it was rewritten: header() and
		// setcookie() stayed the real PHP functions, which do nothing here.
		//
		// The result was a site that rendered the right page and sent almost
		// no headers with it -- no Cache-Control, so nothing could be cached
		// and every request was rendered -- and could not sign anybody in,
		// because the session cookie was never sent. Nothing was logged,
		// because nothing failed; the calls simply went nowhere.
		// Read the environment, not the constant. The constant is defined by
		// the application's own bootstrap, which runs when the front
		// controller is included -- long after this. Checking it here was
		// always false, so the wrapper was never installed and the fix did
		// nothing at all.
		$enginePhar = getenv('EXP_ENGINE_PHAR');
		if (is_string($enginePhar) and $enginePhar !== ''
		and in_array('phar', stream_get_wrappers(), true)) {
			stream_wrapper_unregister('phar');
			stream_wrapper_register('phar', 'Q_WebServer_CompatFileWrapper');
			self::$pharWrapped = true;
		}
	}

	/**
	 * Shut down the compatibility layer.
	 * Cleans up temp files, closes sessions, restores file:// wrapper.
	 */
	static function shutdown()
	{
		if (!self::$enabled) return;

		// ── Fire registered shutdown functions (in registration order) ──
		foreach (self::$shutdownCallbacks as $entry) {
			try {
				call_user_func_array($entry[0], $entry[1]);
			} catch (\Throwable $e) {
				// Shutdown callbacks must not kill the worker
			}
		}
		self::$shutdownCallbacks = array();

		// Close any open session
		if (self::$sessionActive) {
			self::_session_write_close();
		}

		// Clean up temp upload files
		foreach (self::$uploadedFiles as $path => $flag) {
			if (file_exists($path)) @unlink($path);
		}

		// ── Restore error/exception handlers to boot state ──
		//
		// By popping, never by pushing. PHP keeps every handler that
		// set_error_handler() replaces on an internal stack, which no PHP
		// code can see. This used to "restore" the boot handler by setting
		// it -- one more push -- so the stack gained an entry per request,
		// each holding the handler the request had installed. Exponential
		// installs a method of its eZDebug instance, and that instance holds
		// every message and timing the request logged, so every request's
		// debug log stayed in the worker for good: about 1 MB a request, and
		// 4 MB for a search page, with no static, global or object anywhere
		// in reach of it.
		//
		// The stack is unwound until the current handler IS the boot one,
		// compared by identity rather than taken on trust from the
		// bookkeeping, so a push made by code that did not go through the
		// shim is unwound too.
		if (self::$bootHandlersCaptured) {
			self::unwindHandlers('error', self::$bootErrorHandler);
			self::unwindHandlers('exception', self::$bootExceptionHandler);
		}
		self::$errorHandlerStack = array();

		// Stats remembered during the request are only good for the request.
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
			Q_WebServer_CompatFileWrapper::forgetStats();
		}

		// ── Autoloaders stay registered ──
		// They used to be unregistered here, to hand the next request the
		// boot-time stack. But a class declared during a request stays
		// declared, and an application registers its autoloader behind a
		// guard on exactly that:
		//
		//     if (!class_exists('ezpAutoloader', false)) {
		//         class ezpAutoloader { ... }
		//         spl_autoload_register(array('ezpAutoloader', 'autoload'));
		//     }
		//
		// Second request: the class is still there, the block is skipped,
		// the autoloader is not registered again -- and nothing can be
		// loaded any more. eZ Publish answered the first request and then
		// reported "Class eZDB not found" for every one after it.
		//
		// Removing half of the pair is what breaks; keeping both matches
		// what the worker actually is. An autoloader that closes over one
		// request's state would be a problem, but that is rare, and far
		// rarer than the guarded registration this used to break.
		self::$requestAutoloaders = array();

		// ── Restore environment variables ──
		foreach (self::$requestEnvVars as $key => $oldValue) {
			if ($oldValue === false) {
				putenv($key); // remove it
			} else {
				putenv($key . '=' . $oldValue);
			}
		}
		self::$requestEnvVars = array();

		// ── Restore ini values changed during this request ──
		foreach (self::$requestIniChanges as $key => $oldValue) {
			@\ini_set($key, $oldValue);
			// Restore the config override to boot-time value (or remove)
			if (array_key_exists($key, self::$bootIniOverrides)) {
				Q_Config::set('Q', 'compat', 'ini', $key, self::$bootIniOverrides[$key]);
			} else {
				Q_Config::clear('Q', 'compat', 'ini', $key);
			}
		}
		self::$requestIniChanges = array();

		// ── Reset response state ──
		// Headers accumulated via _header() shim
		if (class_exists('Q_WebServer_State', false)) {
			\Q_WebServer_State::clear();
		}
		// Native headers (belt and suspenders)
		if (function_exists('header_remove')) {
			@header_remove();
		}
		// HTTP response code
		@http_response_code(200);

		self::$headersSent = false;
		self::$uploadedFiles = array();
		self::$sessionActive = false;
		self::$sessionFile = '';
		self::$sessionFp = null;
		self::$sessionId = '';
		self::$sessionName = null;
		self::$sessionCookieParams = null;
		self::$requestHeaders = array();

		@stream_wrapper_restore('file');
	}

	/**
	 * Set the request headers (called by the server before dispatch).
	 * @param array $headers Associative array of headers
	 */
	static function setRequestHeaders($headers)
	{
		self::$requestHeaders = $headers;
	}

	/**
	 * Check if compat mode is active.
	 * @return bool
	 */
	/**
	 * Is the phar:// scheme currently wrapped for source transforms?
	 *
	 * @method pharWrapped
	 * @static
	 * @return {boolean}
	 */
	static function pharWrapped()
	{
		return self::$pharWrapped;
	}

	static function isEnabled()
	{
		return self::$enabled;
	}

	/**
	 * Transform PHP source code by replacing built-in function calls
	 * with our compatibility shims.
	 *
	 * Uses token_get_all() for safe, accurate replacement. Only replaces
	 * global function calls — method calls ($obj->header()) and
	 * fully-qualified calls (\header()) are left alone unless they're
	 * the built-in.
	 *
	 * @param string $source PHP source code
	 * @param string $filePath Original file path (for cache key)
	 * @return string Transformed source
	 */
	static function transformSource($source, $filePath = '')
	{
		// Check cache first
		if ($filePath) {
			$cached = self::getCachedTransform($filePath);
			// false is the "needs no transform" sentinel, not source code.
			if ($cached !== null) return $cached === false ? $source : $cached;
		}

		$tokens = token_get_all($source);
		$count = count($tokens);
		$out = '';
		$changed = false;

		// foreach, not for ($i = 0; ...; $i++): PHP 8.2 and 8.3's function JIT
		// (opcache.jit=1235, which setup-php and some hosts turn on) restarted
		// this loop from the first token -- keeping what it had written -- when
		// it compiled the function mid-call, once the loop turned hot. The
		// result was every token so far written twice, a parse error in the
		// script being loaded. Seen once per process, on about the fourth
		// call; PHP 8.4's JIT, tracing mode and no JIT were never affected.
		// $i is only read in the body, so the two loops are the same.
		foreach ($tokens as $i => $token) {

			if (!is_array($token)) {
				$out .= $token;
				continue;
			}

			// exit and die are language constructs, not functions, so they
			// never reach the T_STRING branch below and have to be handled
			// here. "exit;" and "die;" take no parentheses, so the call has
			// to supply them; "exit(1)" and "die('x')" bring their own.
			if ($token[0] === T_EXIT) {
				$p = $i - 1;
				while ($p >= 0 and is_array($tokens[$p])
				and $tokens[$p][0] === T_WHITESPACE) $p--;
				$prev = $p >= 0 ? $tokens[$p] : null;
				// T_NULLSAFE_OBJECT_OPERATOR is "?->", added in PHP 8.0 and
				// every bit as much a method call as "->". Missing it did not
				// merely rewrite something it should not have: it produced
				// $o?->\Q_WebServer_Compat::_header(...), which is a parse
				// error, so a file using a nullsafe call to a method sharing a
				// name with any rewritten function would not load at all.
				// Guarded by defined() so this still runs on PHP 7.
				// Parenthesised: "=" binds tighter than "and", so without
				// them this assigned is_array($prev) alone, and every exit or
				// die after a keyword -- "$db or die(...)", "else exit;",
				// "$ok || exit(1)" -- counted as a method and was left as a
				// real exit, ending the worker.
				$isMember = (is_array($prev)
					and ($prev[0] === T_OBJECT_OPERATOR or $prev[0] === T_DOUBLE_COLON
						or (defined('T_NULLSAFE_OBJECT_OPERATOR')
							and $prev[0] === T_NULLSAFE_OBJECT_OPERATOR)
						or $prev[0] === T_FUNCTION or $prev[0] === T_CONST));

				if ($isMember) {
					$out .= $token[1];
					continue;
				}

				$n = $i + 1;
				while ($n < $count and is_array($tokens[$n])
				and $tokens[$n][0] === T_WHITESPACE) $n++;
				$hasArgs = ($n < $count and $tokens[$n] === '(');

				$out .= '\\Q_WebServer_Compat::_exit' . ($hasArgs ? '' : '()');
				$changed = true;
				continue;
			}

			// Only look at T_STRING (function/constant names)
			// PHP 8+: \header() is T_NAME_FULLY_QUALIFIED, Ns\header() is T_NAME_QUALIFIED
			if ($token[0] === T_STRING) {
				$name = strtolower($token[1]);
			} elseif (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
				// \header(), \setcookie(), etc. — strip the leading backslash
				$name = strtolower(ltrim($token[1], '\\'));
			} else {
				$out .= $token[1];
				continue;
			}

			if (!isset(self::$replacements[$name])) {
				$out .= $token[1];
				continue;
			}

			// For T_NAME_FULLY_QUALIFIED, it's always a global call — skip the method/function check
			if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
				// Still verify it's followed by '('
				$hasParen = false;
				for ($j = $i + 1; $j < $count; $j++) {
					if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
					if ($tokens[$j] === '(') $hasParen = true;
					break;
				}
				if ($hasParen) {
					$out .= self::qualified($name);
					$changed = true;
					continue;
				}
				$out .= $token[1];
				continue;
			}

			// Check: is this a global function call?
			// Must have '(' after it (skipping whitespace)
			// Must NOT have '->' or '::' before it (that's a method call)
			// Must NOT have 'function' before it (that's a definition)
			if (!self::isGlobalFunctionCall($tokens, $i, $count)) {
				$out .= $token[1];
				continue;
			}

			// If preceded by '\' (T_NS_SEPARATOR), strip it from output
			// since we're replacing \header() → Q_WebServer_Compat::_header()
			$prevIdx = $i - 1;
			while ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_WHITESPACE) $prevIdx--;
			if ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_NS_SEPARATOR) {
				$out = substr($out, 0, -1); // remove the trailing '\'
			}

			$out .= self::qualified($name);
			$changed = true;
		}

		$result = $changed ? $out : $source;

		// Save to cache
		if ($changed && $filePath) {
			self::saveCache($filePath, $result);
		}

		return $result;
	}

	/**
	 * Replacement for exit / die.
	 *
	 * exit ends the process. Under one-process-per-request that is the end of
	 * a request; in a persistent worker it is the end of the worker, and the
	 * client gets nothing but a 502 while the pool quietly replaces the
	 * corpse. Legacy PHP is full of it -- one installation tested here has 99
	 * files calling a helper whose last statement is exit -- so it cannot be
	 * fixed script by script.
	 *
	 * Throwing instead lets the worker unwind to the request boundary, keep
	 * whatever the script had already produced, and answer.
	 *
	 * A string argument is what exit("message") means: print it, then stop.
	 *
	 * @method _exit
	 * @static
	 * @param {integer|string} $status
	 * @throws Q_WebServer_ExitSignal
	 */
	static function _exit($status = 0)
	{
		if (is_string($status)) {
			echo $status;
			$status = 0;
		}
		throw new Q_WebServer_ExitSignal((int) $status);
	}

	/**
	 * Check if token at position $i is a global function call.
	 */
	private static function isGlobalFunctionCall($tokens, $i, $count)
	{
		// Must be followed by '('
		$foundParen = false;
		for ($j = $i + 1; $j < $count; $j++) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
			if ($tokens[$j] === '(') { $foundParen = true; }
			break;
		}
		if (!$foundParen) return false;

		// Must NOT be preceded by '->', '::', 'function', 'new'
		// Special case: '\' (T_NS_SEPARATOR) means \header() — that IS global
		// UNLESS there's a T_STRING before the '\' (Namespace\header() — NOT global)
		for ($j = $i - 1; $j >= 0; $j--) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
			if (is_array($tokens[$j])) {
				$type = $tokens[$j][0];
				if ($type === T_OBJECT_OPERATOR  // ->header()
				 || $type === T_DOUBLE_COLON     // Class::header()
				 || (defined('T_NULLSAFE_OBJECT_OPERATOR')
					 && $type === T_NULLSAFE_OBJECT_OPERATOR)  // ?->header()
				 || $type === T_FUNCTION          // function header()
				 || $type === T_NEW               // new header()
				) {
					return false;
				}
				if ($type === T_NS_SEPARATOR) {
					// \header() — check if it's standalone or Namespace\header()
					for ($k = $j - 1; $k >= 0; $k--) {
						if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) continue;
						if (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
							return false; // Namespace\header() — not our target
						}
						break; // standalone \header() — IS global
					}
					// It's \header() with no namespace prefix — treat as global
					// Mark position so the caller can strip the backslash from output
					return true;
				}
			}
			break;
		}

		return true;
	}

	/**
	 * Get cached entry from memory.
	 * Returns: string (transformed source), false (sentinel — no transform needed),
	 * or null (not in cache, file added after prewarm).
	 */
	/**
	 * The modification time recorded with a cached transform, or 0.
	 * @method cachedMtime
	 * @static
	 * @param {string} $filePath
	 * @return {integer}
	 */
	static function cachedMtime($filePath)
	{
		return isset(self::$transformCache[$filePath]['mtime'])
			? (int) self::$transformCache[$filePath]['mtime'] : 0;
	}

	/**
	 * Drop a file's cached transform (or sentinel), so the next open reads
	 * and transforms the file again. For a file that changed on disk.
	 * @method forgetTransform
	 * @static
	 * @param {string} $filePath
	 */
	static function forgetTransform($filePath)
	{
		unset(self::$transformCache[$filePath]);
	}

	static function getCachedTransform($filePath)
	{
		if (!isset(self::$transformCache[$filePath])) return null;
		$entry = self::$transformCache[$filePath];
		return $entry['source'];  // string, false (sentinel), never null
	}

	/**
	 * Store transformed source in the in-memory cache.
	 *
	 * Public because the stream wrapper is a separate class and is the only
	 * place that learns, at request time, what a file turned out to need. The
	 * prewarm walk records that for every file it sees; anything created after
	 * it -- which is every file the hosted application generates while running
	 * -- can only be recorded here.
	 *
	 * @param string $filePath
	 * @param string|false $transformed transformed source, or false to record
	 *                                  that the file needs no transform
	 */
	static function saveCache($filePath, $transformed, $mtime = null)
	{
		// The mtime of the version that was read, taken BEFORE reading it.
		// Taken afterwards, a rewrite landing in between paired the old
		// transform with the new file's mtime, and it was served for good.
		if ($mtime === null) $mtime = @filemtime($filePath);
		// Not kept while the file is this new: a rewrite within the same
		// second would leave the mtime unchanged and the entry would look
		// current. Such a file is read again next time; once it is older
		// than that, its entry can be trusted.
		if ($mtime === false or self::isRacy((int) $mtime)) {
			unset(self::$transformCache[$filePath]);
			return;
		}
		self::$transformCache[$filePath] = array(
			'source' => $transformed,
			'mtime' => (int) $mtime,
		);
	}

	/**
	 * Seconds for which a file's mtime cannot tell two versions apart.
	 *
	 * A stat's mtime is whole seconds, so a file rewritten within the second
	 * it was cached in looks unchanged. The same problem, and the same
	 * answer, as git's "racy" index entries: nothing modified this recently
	 * is served from or stored into a cache keyed on its mtime.
	 */
	const RACY_SECONDS = 2;

	/**
	 * Whether a file with this mtime is too recent to cache by mtime.
	 * @method isRacy
	 * @static
	 * @param {integer} $mtime
	 * @return {boolean}
	 */
	static function isRacy($mtime)
	{
		return $mtime > 0 and time() - (int) $mtime < self::RACY_SECONDS;
	}

	/**
	 * Format version of the persisted pre-warm file.
	 *
	 * Bump this whenever transformSource() starts producing different output
	 * for the same input. A stale entry is not merely unhelpful: it would feed
	 * the old transform to a worker for a file that has not changed, so the
	 * cache has to be able to say "I was written by a different transformer"
	 * and be ignored wholesale.
	 */
	const PREWARM_FORMAT = 5;

	/** Resolved path of the persisted pre-warm file, or null. */
	private static $persistPath = false;

	/**
	 * Identifies the transformer that produced a cached result: a hash of
	 * this file, which holds every rule the transform applies.
	 *
	 * @method rulesHash
	 * @static
	 * @return {string}
	 */
	static function rulesHash()
	{
		static $hash = null;
		if ($hash === null) {
			$hash = (string) @sha1_file(__FILE__);
			if ($hash === '') $hash = sha1(serialize(self::$replacements));
		}
		return $hash;
	}

	/** How many entries the last prewarm() took from disk instead of redoing. */
	private static $prewarmReused = 0;

	/**
	 * Where the pre-warm result is kept between starts.
	 *
	 * The file holds transformed copies of the application's own source, so it
	 * is private to the user running the server and lives in a directory
	 * created 0700. The name carries a digest of the document root because one
	 * machine may serve several roots and their maps must not collide.
	 *
	 * @method persistPath
	 * @static
	 * @param {string} $root the resolved document root
	 * @return {string|null} null when persistence is off or unavailable
	 */
	static function persistPath($root)
	{
		if (self::$persistPath !== false) return self::$persistPath;

		$enabled = true;
		$dir = null;
		$mode = null;
		if (class_exists('Q_Config', false)) {
			$enabled = (bool) Q_Config::get(
				'Q', 'webserver', 'compat', 'persistPrewarm', true);
			$dir = Q_Config::get('Q', 'webserver', 'compat', 'dir', null);
			$mode = Q_Config::get('Q', 'webserver', 'compat', 'dirMode', null);
		}
		if (!$enabled) return self::$persistPath = null;

		if (!$dir) {
			$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbixserver-compat';
		}
		if (class_exists('Q_WebServer_Modes', false)) {
			Q_WebServer_Modes::dir(
				$dir, Q_WebServer_Modes::parse($mode, 0700), $mode !== null);
		} else if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		if (!is_dir($dir) || !is_writable($dir)) return self::$persistPath = null;

		return self::$persistPath = $dir . DIRECTORY_SEPARATOR
			. 'prewarm-' . substr(sha1($root), 0, 16) . '.cache';
	}

	/**
	 * Read the previous start's pre-warm result.
	 *
	 * Anything that does not match exactly -- format, PHP version, root -- is
	 * treated as absent rather than repaired. A cache is an optimisation, and
	 * the cost of ignoring one is a slow start; the cost of trusting a wrong
	 * one is serving the wrong bytes.
	 *
	 * @method loadPersisted
	 * @static
	 * @param {string} $root
	 * @return {array|null}
	 */
	static function loadPersisted($root)
	{
		$path = self::persistPath($root);
		if (!$path || !is_file($path)) return null;
		$raw = @file_get_contents($path);
		if ($raw === false || $raw === '') return null;
		$data = @unserialize($raw);
		if (!is_array($data)) return null;
		if (($data['v'] ?? 0) !== self::PREWARM_FORMAT) return null;
		// The transformer's output can depend on the running PHP, so a cache
		// written by another one says nothing about this one.
		if (($data['php'] ?? 0) !== PHP_VERSION_ID) return null;
		// And by another transformer: the entries are this file's output, so
		// a change to it -- a function added to $replacements -- makes every
		// one of them wrong. Checked against the format number alone, a new
		// replacement never reached a file already in the cache: session_name()
		// stayed PHP's, and sessions kept the default cookie name.
		if (($data['rules'] ?? '') !== self::rulesHash()) return null;
		if (($data['root'] ?? '') !== $root) return null;
		if (!isset($data['entries']) || !is_array($data['entries'])) return null;
		return $data['entries'];
	}

	/**
	 * Write the pre-warm result for the next start.
	 *
	 * The file is completed under a temporary name and renamed into place, so
	 * a start that races a write, or a crash in the middle of one, finds
	 * either the old cache or none -- never half of one.
	 *
	 * @method savePersisted
	 * @static
	 * @param {string} $root
	 * @param {array} $entries
	 * @return {boolean}
	 */
	static function savePersisted($root, $entries)
	{
		$path = self::persistPath($root);
		if (!$path) return false;

		$blob = @serialize(array(
			'v' => self::PREWARM_FORMAT,
			'php' => PHP_VERSION_ID,
			'rules' => self::rulesHash(),
			'root' => $root,
			'entries' => $entries,
		));
		if ($blob === false || $blob === '') return false;

		$tmp = $path . '.' . getmypid() . '.tmp';
		$fh = @fopen($tmp, 'wb');
		if (!$fh) return false;
		// A plain file writes everything or errors, but the count is still the
		// only thing that says which happened.
		$ok = (@fwrite($fh, $blob) === strlen($blob)) && @fflush($fh);
		@fclose($fh);
		if (!$ok) { @unlink($tmp); return false; }
		@chmod($tmp, 0600);
		if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
		return true;
	}

	/**
	 * How many files the last prewarm() reused instead of re-tokenising.
	 * @method prewarmReused
	 * @static
	 * @return {integer}
	 */
	static function prewarmReused()
	{
		return self::$prewarmReused;
	}

	/**
	 * Pre-warm the transform cache by walking a directory.
	 * Call from the parent process before accepting connections.
	 * Fork children inherit the cache via COW — zero per-request cost.
	 *
	 * @param string $dir Directory to walk (e.g., the app root)
	 * @param int $maxFiles Safety limit (default 5000)
	 * @return int Number of files pre-warmed
	 */
	static function prewarm($dir, $maxFiles = 5000)
	{
		$count = 0;
		$dir = rtrim($dir, DIRECTORY_SEPARATOR);
		// Nothing, or the filesystem root (which the trim turns into
		// nothing): never a tree to walk. RecursiveDirectoryIterator throws a
		// ValueError on '' and the server died at start.
		if ($dir === '') return 0;
		$root = realpath($dir) ?: $dir;

		// A previous start already did this work. Nothing about a transform
		// depends on anything but the file's bytes, so an entry whose size and
		// mtime still match is reusable exactly as it stands. The walk used to
		// cost several seconds on every start, and the overwhelming majority
		// of it was rediscovering that most of the tree needs no transform at
		// all -- work whose answer had been computed and then thrown away.
		$previous = self::loadPersisted($root);
		$reused = 0;
		$fresh = array();
		$dirty = false;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $file) {
			if ($count >= $maxFiles) break;
			if (!$file->isFile()) continue;
			if ($file->getExtension() !== 'php') continue;
			$path = $file->getRealPath() ?: $file->getPathname();
			if (!$path || $path === '') continue;
			// Skip vendor test files (large, rarely included)
			if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
			if (strpos($path, DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR) !== false) continue;

			$mtime = $file->getMTime();
			$size = $file->getSize();

			// Size as well as mtime, because a modification within the same
			// second is invisible to mtime alone and an edit that changes the
			// length is the common case.
			if ($previous !== null && isset($previous[$path])
				&& ($previous[$path]['mtime'] ?? -1) === $mtime
				&& ($previous[$path]['size'] ?? -1) === $size) {
				$fresh[$path] = $previous[$path];
				$reused++;
				$count++;
				continue;
			}

			$source = file_get_contents($path);
			if ($source === false) continue;
			$transformed = self::transformSource($source, $path);
			$fresh[$path] = array(
				// A file that transforms to itself stores a sentinel, so that
				// at request time it costs neither a disk read nor a
				// tokenisation.
				'source' => $transformed !== $source ? $transformed : false,
				'mtime' => $mtime,
				'size' => $size,
			);
			$dirty = true;
			$count++;
		}

		// The walk's result is authoritative for the paths it covered; entries
		// recorded at request time for files created since are kept.
		self::$transformCache = $fresh + self::$transformCache;
		self::$prewarmReused = $reused;

		// A file that disappeared since the last start leaves no trace in the
		// walk, so a changed entry count is itself a reason to rewrite.
		if ($dirty || $previous === null || count($previous) !== count($fresh)) {
			self::savePersisted($root, $fresh);
		}
		return $count;
	}

	/**
	 * Invalidate cached entries whose files changed on disk.
	 * Call from the parent process on a timer (e.g., every 2 seconds)
	 * for hot-reload during development.
	 *
	 * @return int Number of entries invalidated
	 */
	static function invalidateStale()
	{
		$invalidated = 0;
		foreach (self::$transformCache as $path => $entry) {
			if (!file_exists($path) || filemtime($path) > $entry['mtime']) {
				unset(self::$transformCache[$path]);
				$invalidated++;
			}
		}
		return $invalidated;
	}

	/**
	 * Write a session's data to its file, completely or not at all silently.
	 *
	 * Every caller truncates the file first, so the old contents are already
	 * gone by the time this runs. A short write therefore does not leave the
	 * previous session intact -- it leaves a cut-off one, which unserialize
	 * rejects on the next request, and the visitor is logged out with nothing
	 * in any log to say why.
	 *
	 * fwrite on a regular file usually places everything, which is exactly why
	 * the count was never read. Usually is not always: a full disk or a quota
	 * makes this real, and losing a session is the visible consequence.
	 *
	 * @method writeSessionData
	 * @static
	 * @param {resource} $fp
	 * @param {string} $data
	 * @return {boolean}
	 */
	static function writeSessionData($fp, $data)
	{
		if (!is_resource($fp)) return false;
		$length = strlen($data);
		$written = 0;
		while ($written < $length) {
			$n = @fwrite($fp, substr($data, $written));
			if ($n === false or $n === 0) {
				if (class_exists('Q_WebServer_Log', false)
					and method_exists('Q_WebServer_Log', 'error')) {
					Q_WebServer_Log::error(
						'session write incomplete: ' . $written . ' of ' . $length
						. ' bytes; the session file is now truncated');
				} else {
					error_log('qbix: session write incomplete, ' . $written
						. ' of ' . $length . ' bytes');
				}
				return false;
			}
			$written += $n;
		}
		return true;
	}

	/**
	 * Get cache stats (for dashboard/debugging).
	 * @return array
	 */
	static function cacheStats()
	{
		$totalBytes = 0;
		$transforms = 0;
		$passthrough = 0;
		foreach (self::$transformCache as $entry) {
			if ($entry['source'] === false) {
				$passthrough++;
			} else {
				$transforms++;
				$totalBytes += strlen($entry['source']);
			}
		}
		return array(
			'entries' => count(self::$transformCache),
			'transforms' => $transforms,
			'passthrough' => $passthrough,
			'bytes' => $totalBytes,
		);
	}

	// ── Shim functions ──────────────────────────────────
	// These replace the built-in functions in transformed source.
	// They delegate to Q_Response which the server reads after script execution.

	/**
	 * Replacement for header().
	 * @param string $string The header string
	 * @param bool $replace Whether to replace a previous similar header
	 * @param int|null $response_code Optional HTTP status code
	 */
	static function _header($string, $replace = true, $response_code = null)
	{
		if ($response_code !== null) {
			Q_Response::code($response_code);
		}

		// Parse "HTTP/1.1 404 Not Found" style headers
		if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/i', $string, $m)) {
			Q_Response::code((int) $m[1]);
			return;
		}

		// header('Location: ...') means a redirect. PHP itself sets 302 for
		// it unless the script has already chosen a status, and a great deal
		// of code relies on that rather than saying 302 explicitly. Without
		// it the response is 200 carrying a Location header and an empty
		// body, which is not a redirect at all: the browser renders the
		// nothing it was given. Every redirect in a legacy application --
		// after a login, after a publish -- lands there.
		//
		// Set through responseCode(), which updates the server's own status
		// as well as the Platform's; writing only one of them leaves the
		// value the response is built from untouched.
		if ($response_code === null
		and preg_match('/^\s*Location\s*:/i', $string)) {
			$current = class_exists('Q_WebServer_State', false)
				? Q_WebServer_State::getStatusCode()
				: Q_Response::code();
			if (!$current or (int) $current === 200) {
				if (class_exists('Q_WebServer_State', false)) {
					Q_WebServer_State::responseCode(302);
				} else {
					Q_Response::code(302);
				}
			}
		}

		Q_Response::header($string, $replace);
	}

	/**
	 * Replacement for phpinfo().
	 *
	 * phpinfo() renders an HTML page under mod_php and fpm, but the CLI-family
	 * SAPIs — phpmicro included — emit plain text instead, and the choice is a
	 * SAPI flag no ini setting reaches. Q_WebServer_PhpInfo parses that text
	 * back into the familiar tables.
	 */
	static function _phpinfo($flags = INFO_ALL)
	{
		ob_start();
		phpinfo($flags);
		echo self::phpinfoAsHtml((string) ob_get_clean());
		return true;
	}

	/**
	 * Render phpinfo() output as HTML. Markup is returned unchanged, so this
	 * is safe to call whatever the SAPI produced.
	 * @param {string} $out Raw phpinfo() output
	 * @return {string}
	 */
	static function phpinfoAsHtml($out)
	{
		return Q_WebServer_PhpInfo::render($out);
	}

	/**
	 * Replacement for setcookie().
	 */
	static function _setcookie(
		$name, $value = '', $expires_or_options = 0,
		$path = '', $domain = '', $secure = false, $httponly = false
	) {
		// Handle PHP 7.3+ options array form
		if (is_array($expires_or_options)) {
			$opts = $expires_or_options;
			$expires  = $opts['expires'] ?? 0;
			$path     = $opts['path'] ?? '';
			$domain   = $opts['domain'] ?? '';
			$secure   = $opts['secure'] ?? false;
			$httponly  = $opts['httponly'] ?? false;
			$samesite = $opts['samesite'] ?? '';
		} else {
			$expires = $expires_or_options;
			$samesite = '';
		}

		Q_Response::setCookie($name, $value, $expires, $path, $domain, $secure, $httponly, $samesite);
	}

	/**
	 * Replacement for setrawcookie().
	 */
	static function _setrawcookie(
		$name, $value = '', $expires_or_options = 0,
		$path = '', $domain = '', $secure = false, $httponly = false
	) {
		// Same as setcookie but without URL encoding — delegate and let Q_Response handle it
		self::_setcookie($name, $value, $expires_or_options, $path, $domain, $secure, $httponly);
	}

	/**
	 * Replacement for http_response_code().
	 * @param int|null $code
	 * @return int
	 */
	static function _http_response_code($code = null)
	{
		if ($code !== null) {
			Q_Response::code($code);
		}
		return Q_Response::code() ?: 200;
	}

	/**
	 * Replacement for headers_sent().
	 * @return bool
	 */
	static function _headers_sent(&$file = null, &$line = null)
	{
		return self::$headersSent;
	}

	/**
	 * Replacement for headers_list().
	 * @return array
	 */
	static function _headers_list()
	{
		$result = array();
		foreach (Q_WebServer_State::getHeaders() as $name => $value) {
			$result[] = "$name: $value";
		}
		return $result;
	}

	/**
	 * Replacement for header_remove().
	 * @param string|null $name
	 */
	static function _header_remove($name = null)
	{
		if ($name === null) {
			// Remove all headers — reset
			Q_WebServer_State::clear();
		} else {
			Q_Response::header("$name:");  // empty value removes it
		}
	}

	/**
	 * Replacement for session_start().
	 * File-based sessions with proper locking for concurrent requests.
	 */
	/**
	 * Replacement for session_id().
	 *
	 * Reports, and before the session starts sets, the id this layer uses.
	 * The native function is no use here: it refuses to set once output has
	 * begun, and it would answer for a session that is never started.
	 *
	 * @param {string} $id New id, or null to only read
	 * @return {string|false} The previous id, or false if it could not be set
	 */
	static function _session_id($id = null)
	{
		$previous = self::$sessionId;
		if ($id !== null) {
			if (self::$sessionActive) {
				trigger_error(
					'session_id(): Session ID cannot be changed when a session is active',
					E_USER_WARNING
				);
				return false;
			}
			self::$sessionId = (string) $id;
		}
		return $previous;
	}

	/**
	 * Replacement for session_name().
	 *
	 * Reports, and before the session starts sets, the name of the session
	 * cookie. See $sessionName for why the native one cannot be used.
	 *
	 * @param {string} $name New name, or null to only read
	 * @return {string|false} The previous name, or false if it could not be set
	 */
	static function _session_name($name = null)
	{
		$previous = self::$sessionName ?? (\session_name() ?: 'PHPSESSID');
		if ($name !== null) {
			if (self::$sessionActive) {
				trigger_error(
					'session_name(): Session name cannot be changed when a session is active',
					E_USER_WARNING
				);
				return false;
			}
			self::$sessionName = (string) $name;
		}
		return $previous;
	}

	/**
	 * Replacement for session_get_cookie_params().
	 *
	 * @return {array} lifetime, path, domain, secure, httponly, samesite
	 */
	static function _session_get_cookie_params()
	{
		if (self::$sessionCookieParams !== null) return self::$sessionCookieParams;
		$native = \session_get_cookie_params();
		return array(
			'lifetime' => (int) ($native['lifetime'] ?? 0),
			'path'     => (string) ($native['path'] ?? '/'),
			'domain'   => (string) ($native['domain'] ?? ''),
			'secure'   => (bool) ($native['secure'] ?? false),
			'httponly' => (bool) ($native['httponly'] ?? false),
			'samesite' => (string) ($native['samesite'] ?? ''),
		);
	}

	/**
	 * Replacement for session_set_cookie_params(), in both of its forms:
	 * (lifetime, path, domain, secure, httponly) and (array $options).
	 *
	 * @return {boolean}
	 */
	static function _session_set_cookie_params(
		$lifetime_or_options, $path = null, $domain = null, $secure = null, $httponly = null
	) {
		if (self::$sessionActive) {
			trigger_error(
				'session_set_cookie_params(): Session cookie parameters cannot be changed when a session is active',
				E_USER_WARNING
			);
			return false;
		}
		$params = self::_session_get_cookie_params();
		if (is_array($lifetime_or_options)) {
			foreach (array('lifetime', 'path', 'domain', 'secure', 'httponly', 'samesite') as $k) {
				if (array_key_exists($k, $lifetime_or_options)) $params[$k] = $lifetime_or_options[$k];
			}
		} else {
			$params['lifetime'] = (int) $lifetime_or_options;
			if ($path !== null) $params['path'] = (string) $path;
			if ($domain !== null) $params['domain'] = (string) $domain;
			if ($secure !== null) $params['secure'] = (bool) $secure;
			if ($httponly !== null) $params['httponly'] = (bool) $httponly;
		}
		$params['lifetime'] = (int) $params['lifetime'];
		self::$sessionCookieParams = $params;
		return true;
	}

	/**
	 * The session cookie, with the parameters the application set.
	 */
	private static function setSessionCookie($name, $id)
	{
		$p = self::_session_get_cookie_params();
		self::_setcookie($name, $id, array(
			'expires'  => $p['lifetime'] > 0 ? time() + $p['lifetime'] : 0,
			'path'     => $p['path'] !== '' ? $p['path'] : '/',
			'domain'   => $p['domain'],
			'secure'   => $p['secure'],
			'httponly' => $p['httponly'],
			'samesite' => $p['samesite'],
		));
	}

	static function _session_start($options = array())
	{
		if (self::$sessionActive) return true;

		$savePath = $options['save_path']
			?? self::_ini_get('session.save_path')
			?: sys_get_temp_dir();
		$name = $options['name']
			?? self::_session_name()
			?: 'PHPSESSID';
		$maxLifetime = (int) ($options['gc_maxlifetime']
			?? self::_ini_get('session.gc_maxlifetime')
			?: 1440);
		// An id set by session_id() before the session starts wins over
		// the cookie, which is what PHP does.
		$id = self::$sessionId ?: ($_COOKIE[$name] ?? '');

		// The id arrives in a cookie and becomes part of the session filename
		// below, so the anchor has to mean the end of the string: without the
		// D modifier a trailing newline came through the check and into the
		// path.
		if (!$id || !preg_match('/^[a-zA-Z0-9,-]{22,256}$/D', $id)) {
			$id = bin2hex(random_bytes(16));
			self::setSessionCookie($name, $id);
		}

		// Deliberately not session_id($id): this layer runs the session
		// itself, PHP's own is never started, and the native setter refuses
		// once output has begun -- which under a persistent worker it has,
		// so every session_start() printed "Session ID cannot be changed
		// after headers have already been sent" into the response body.
		self::$sessionId = $id;
		self::$sessionFile = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $id;

		// Read with exclusive lock (held until write_close)
		$_SESSION = array();
		self::$sessionFp = fopen(self::$sessionFile, 'c+');
		if (self::$sessionFp) {
			flock(self::$sessionFp, LOCK_EX);
			$data = stream_get_contents(self::$sessionFp);
			if ($data) {
				$_SESSION = self::unserializeSession($data);
			}
		}

		self::$sessionActive = true;

		// Garbage collection (probabilistic)
		$gcProb = (int) self::_ini_get('session.gc_probability') ?: 1;
		$gcDiv = (int) self::_ini_get('session.gc_divisor') ?: 100;
		if (mt_rand(1, $gcDiv) <= $gcProb) {
			self::sessionGc($savePath, $maxLifetime);
		}

		return true;
	}

	/**
	 * Replacement for session_write_close().
	 * Flushes $_SESSION to file and releases the lock.
	 */
	static function _session_write_close()
	{
		if (!self::$sessionActive) return;
		if (self::$sessionFp) {
			$data = self::serializeSession($_SESSION);
			ftruncate(self::$sessionFp, 0);
			rewind(self::$sessionFp);
			self::writeSessionData(self::$sessionFp, $data);
			fflush(self::$sessionFp);
			flock(self::$sessionFp, LOCK_UN);
			fclose(self::$sessionFp);
			self::$sessionFp = null;
		}
		self::$sessionActive = false;
	}

	/**
	 * Replacement for session_regenerate_id().
	 * Generates a new session ID, optionally deletes the old file.
	 */
	static function _session_regenerate_id($delete_old = false)
	{
		if (!self::$sessionActive) return false;

		$oldFile = self::$sessionFile;
		$oldId = self::$sessionId;
		$newId = bin2hex(random_bytes(16));

		// Write current data and release lock on old file
		if (self::$sessionFp) {
			$data = self::serializeSession($_SESSION);
			ftruncate(self::$sessionFp, 0);
			rewind(self::$sessionFp);
			self::writeSessionData(self::$sessionFp, $data);
			fflush(self::$sessionFp);
			flock(self::$sessionFp, LOCK_UN);
			fclose(self::$sessionFp);
			self::$sessionFp = null;
		}

		if ($delete_old && file_exists($oldFile)) {
			@unlink($oldFile);
		}

		// Set new ID — ours, for the same reason as in _session_start()
		self::$sessionId = $newId;
		$savePath = dirname(self::$sessionFile);
		self::$sessionFile = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $newId;

		// Open new file with lock
		self::$sessionFp = fopen(self::$sessionFile, 'c+');
		if (self::$sessionFp) {
			flock(self::$sessionFp, LOCK_EX);
			$data = self::serializeSession($_SESSION);
			self::writeSessionData(self::$sessionFp, $data);
			fflush(self::$sessionFp);
		}

		// Update cookie
		$name = self::_session_name();
		self::setSessionCookie($name, $newId);

		return true;
	}

	/**
	 * Replacement for session_destroy().
	 */
	static function _session_destroy()
	{
		if (!self::$sessionActive) return false;

		$_SESSION = array();

		if (self::$sessionFp) {
			flock(self::$sessionFp, LOCK_UN);
			fclose(self::$sessionFp);
			self::$sessionFp = null;
		}

		if (file_exists(self::$sessionFile)) {
			@unlink(self::$sessionFile);
		}

		self::$sessionActive = false;
		return true;
	}

	/**
	 * Replacement for session_status().
	 */
	static function _session_status()
	{
		if (self::$sessionActive) return PHP_SESSION_ACTIVE;
		return PHP_SESSION_NONE;  // Sessions are always available, just not started yet
	}

	/**
	 * Session garbage collection — delete expired session files.
	 */
	private static function sessionGc($savePath, $maxLifetime)
	{
		$cutoff = time() - $maxLifetime;
		$files = glob($savePath . DIRECTORY_SEPARATOR . 'sess_*');
		if (!$files) return;
		foreach ($files as $file) {
			if (filemtime($file) < $cutoff) {
				@unlink($file);
			}
		}
	}

	/**
	 * Serialize session data in PHP's session format.
	 */
	private static function serializeSession($data)
	{
		$result = '';
		foreach ($data as $key => $value) {
			$result .= $key . '|' . serialize($value);
		}
		return $result;
	}

	/**
	 * Unserialize PHP session format.
	 */
	private static function unserializeSession($data)
	{
		$result = array();
		$offset = 0;
		while ($offset < strlen($data)) {
			$pipe = strpos($data, '|', $offset);
			if ($pipe === false) break;
			$key = substr($data, $offset, $pipe - $offset);
			$offset = $pipe + 1;
			$value = unserialize(substr($data, $offset), ['allowed_classes' => true]);
			$result[$key] = $value;
			// Find the end of the serialized value
			$serialized = serialize($value);
			$offset += strlen($serialized);
		}
		return $result;
	}

	/**
	 * Replacement for move_uploaded_file().
	 * Validates the source was actually an uploaded file.
	 */
	static function _move_uploaded_file($from, $to)
	{
		if (!isset(self::$uploadedFiles[$from])) {
			return false;
		}
		$result = @rename($from, $to);
		if (!$result) {
			// rename() cannot cross a filesystem boundary, and the temporary
			// directory very often is one: /tmp as tmpfs, or a document root
			// on a volume of its own. PHP's own move_uploaded_file() falls
			// back to a copy for exactly this case, so a shim that does not
			// leaves an application able to accept an upload but not to keep
			// it -- and the failure is silent, because the function simply
			// returns false.
			if (@copy($from, $to)) {
				@unlink($from);
				$result = true;
			}
		}
		if ($result) {
			unset(self::$uploadedFiles[$from]);
		}
		return $result;
	}

	/**
	 * Replacement for is_uploaded_file().
	 */
	static function _is_uploaded_file($filename)
	{
		return isset(self::$uploadedFiles[$filename]);
	}

	// ── ini_get / ini_set ───────────────────────────────

	/**
	 * Replacement for ini_get().
	 * Returns config override first, falls back to real ini_get().
	 */
	static function _ini_get($key)
	{
		$override = Q_Config::get('Q', 'compat', 'ini', $key, null);
		if ($override !== null) return (string) $override;
		return \ini_get($key);
	}

	/**
	 * Replacement for ini_set().
	 * Stores in config and also sets the real value where possible.
	 */
	static function _ini_set($key, $value)
	{
		$old = self::_ini_get($key);
		// Track for between-request restore (only first change per key)
		if (!array_key_exists($key, self::$requestIniChanges)) {
			self::$requestIniChanges[$key] = $old;
		}
		Q_Config::set('Q', 'compat', 'ini', $key, (string) $value);
		@\ini_set($key, $value);
		return $old;
	}

	/**
	 * Replacement for set_time_limit().
	 * Uses pcntl_alarm() to enforce execution timeout.
	 */
	static function _set_time_limit($seconds)
	{
		Q_Config::set('Q', 'compat', 'ini', 'max_execution_time', (string) $seconds);
		if (function_exists('pcntl_alarm')) {
			pcntl_alarm((int) $seconds);
		}
		return true;
	}

	// ── Request headers ─────────────────────────────────

	/**
	 * Replacement for getallheaders() / apache_request_headers().
	 * Returns headers from the parsed request.
	 */
	static function _getallheaders()
	{
		$result = array();
		foreach (self::$requestHeaders as $key => $value) {
			// Convert 'content-type' → 'Content-Type'
			$key = str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)));
			$result[$key] = $value;
		}
		return $result;
	}

	// ── Octane safety shims ────────────────────────────────

	/**
	 * Replacement for register_shutdown_function().
	 * Collects callbacks; Compat::shutdown() fires them at request end.
	 * In fork-per-request mode this behaves identically to PHP's native
	 * version because the process exits after the request anyway.
	 */
	static function _register_shutdown_function($callback)
	{
		$args = func_get_args();
		array_shift($args); // remove $callback
		self::$shutdownCallbacks[] = array($callback, $args);
	}

	/**
	 * The error or exception handler currently installed, without changing it.
	 *
	 * @method currentHandler
	 * @static
	 * @param {string} $kind 'error' or 'exception'
	 * @return {callable|null}
	 */
	static function currentHandler($kind)
	{
		if ($kind === 'error') {
			$current = set_error_handler(static function () { return false; });
			restore_error_handler();
		} else {
			$current = set_exception_handler(static function () {});
			restore_exception_handler();
		}
		return $current;
	}

	/**
	 * Pop handlers until the one installed is $boot, so PHP's hidden handler
	 * stack holds nothing a request put there. Bounded: a stack that never
	 * reaches $boot is emptied and $boot installed once on the empty stack.
	 *
	 * @method unwindHandlers
	 * @static
	 * @param {string} $kind 'error' or 'exception'
	 * @param {callable|null} $boot
	 */
	static function unwindHandlers($kind, $boot)
	{
		for ($i = 0; $i < 256; ++$i) {
			$current = self::currentHandler($kind);
			if ($current === $boot) return;
			if ($current === null) break;
			if ($kind === 'error') restore_error_handler();
			else restore_exception_handler();
		}
		if ($boot !== null and self::currentHandler($kind) !== $boot) {
			if ($kind === 'error') set_error_handler($boot);
			else set_exception_handler($boot);
		}
	}

	/**
	 * What a plain path is, asked of the operating system directly.
	 *
	 * Returns 'native' for anything the fast answer does not cover -- a
	 * non-string, another stream wrapper's URL, or the kernel's device and
	 * process trees, where "is it a regular file" has answers glob() cannot
	 * give -- and otherwise what Q_WebServer_CompatFileWrapper::existsAndIsDir()
	 * says.
	 *
	 * The answer never passes through PHP's stat cache, so a filemtime() on
	 * the same path afterwards still asks for, and gets, the real stat.
	 */
	private static function pathType($path)
	{
		if (!is_string($path)) return 'native';
		if (strpos($path, '://') !== false) {
			if (strncmp($path, 'file://', 7) !== 0) return 'native';
			$path = substr($path, 7);
		}
		if (strncmp($path, '/dev/', 5) === 0 or strncmp($path, '/proc/', 6) === 0
			or strncmp($path, '/sys/', 5) === 0) return 'native';
		return Q_WebServer_CompatFileWrapper::existsAndIsDir($path);
	}

	/**
	 * A plain file's mtime and size without the file wrapper, or null when
	 * the native function should answer: a missing path (for its warning),
	 * a directory, another stream wrapper's URL, a device tree.
	 */
	private static function fileTimes($filename)
	{
		$t = self::pathType($filename);
		if ($t !== false) return null;
		$path = strncmp($filename, 'file://', 7) === 0 ? substr($filename, 7) : $filename;
		$info = Q_WebServer_CompatFileWrapper::includeStat($path);
		return $info ?: null;
	}

	/**
	 * Replacement for filemtime().
	 * @method _filemtime
	 * @static
	 */
	static function _filemtime($filename)
	{
		$i = self::fileTimes($filename);
		return $i ? (int) $i['mtime'] : \filemtime($filename);
	}

	/**
	 * Replacement for filesize().
	 * @method _filesize
	 * @static
	 */
	static function _filesize($filename)
	{
		$i = self::fileTimes($filename);
		return $i ? (int) $i['size'] : \filesize($filename);
	}

	/**
	 * Replacement for file_exists().
	 * @method _file_exists
	 * @static
	 */
	static function _file_exists($filename)
	{
		$t = self::pathType($filename);
		return $t === 'native' ? \file_exists($filename) : $t !== null;
	}

	/**
	 * Replacement for is_dir().
	 * @method _is_dir
	 * @static
	 */
	static function _is_dir($filename)
	{
		$t = self::pathType($filename);
		return $t === 'native' ? \is_dir($filename) : $t === true;
	}

	/**
	 * Replacement for is_file(). A path that exists and is not a directory
	 * is taken to be a file; sockets and FIFOs, which is_file() rejects, do
	 * not occur in an application's tree, and the device trees go native.
	 * @method _is_file
	 * @static
	 */
	static function _is_file($filename)
	{
		$t = self::pathType($filename);
		return $t === 'native' ? \is_file($filename) : $t === false;
	}

	/**
	 * Replacement for set_error_handler().
	 * Tracks pushed handlers so shutdown() can restore the boot state.
	 */
	static function _set_error_handler($callback, $error_levels = E_ALL)
	{
		self::$errorHandlerStack[] = 'error';
		return set_error_handler($callback, $error_levels);
	}

	/**
	 * Replacement for set_exception_handler().
	 */
	static function _set_exception_handler($callback)
	{
		self::$errorHandlerStack[] = 'exception';
		return set_exception_handler($callback);
	}

	/**
	 * Replacement for restore_error_handler().
	 */
	static function _restore_error_handler()
	{
		// Remove tracking entry if we have one
		$key = array_search('error', self::$errorHandlerStack);
		if ($key !== false) {
			array_splice(self::$errorHandlerStack, $key, 1);
		}
		return restore_error_handler();
	}

	/**
	 * Replacement for restore_exception_handler().
	 */
	static function _restore_exception_handler()
	{
		$key = array_search('exception', self::$errorHandlerStack);
		if ($key !== false) {
			array_splice(self::$errorHandlerStack, $key, 1);
		}
		return restore_exception_handler();
	}

	/**
	 * Replacement for spl_autoload_register().
	 * Tracks autoloaders registered during this request so shutdown()
	 * can unregister them — restoring the boot-time autoloader stack.
	 */
	static function _spl_autoload_register($callback = null, $throw = true, $prepend = false)
	{
		$result = spl_autoload_register($callback, $throw, $prepend);
		if ($result && $callback !== null) {
			self::$requestAutoloaders[] = $callback;
		}
		return $result;
	}

	/**
	 * Replacement for spl_autoload_unregister().
	 * If the script unregisters one of its own autoloaders, stop tracking it.
	 */
	static function _spl_autoload_unregister($callback)
	{
		$result = spl_autoload_unregister($callback);
		if ($result) {
			$key = array_search($callback, self::$requestAutoloaders, true);
			if ($key !== false) {
				array_splice(self::$requestAutoloaders, $key, 1);
			}
		}
		return $result;
	}

	/**
	 * Replacement for putenv().
	 * Records the previous value so shutdown() can restore it.
	 */
	static function _putenv($setting)
	{
		$eq = strpos($setting, '=');
		if ($eq !== false) {
			$key = substr($setting, 0, $eq);
		} else {
			$key = $setting;
		}
		// Save old value only on first set per request
		if (!array_key_exists($key, self::$requestEnvVars)) {
			$old = getenv($key);
			self::$requestEnvVars[$key] = ($old === false) ? false : $old;
		}
		return putenv($setting);
	}

	// ── Multipart form-data parser ──────────────────────

	/**
	 * Parse a multipart/form-data body into $_POST and $_FILES.
	 *
	 * @param string $body Raw request body
	 * @param string $contentType The Content-Type header (contains boundary)
	 */
	static function parseMultipart($body, $contentType)
	{
		if (!preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $m)) {
			return;
		}
		$boundary = $m[1] ?: $m[2];
		$parts = explode('--' . $boundary, $body);

		// Remove first (empty) and last (--) parts
		array_shift($parts);
		array_pop($parts);

		$tmpDir = sys_get_temp_dir();
		$maxFileSize = self::parseIniSize(self::_ini_get('upload_max_filesize') ?: '8M');
		$maxPostSize = self::parseIniSize(self::_ini_get('post_max_size') ?: '32M');

		// Check total body size
		if (strlen($body) > $maxPostSize) {
			// POST body exceeds limit — frameworks check $_SERVER['CONTENT_LENGTH']
			return;
		}

		foreach ($parts as $part) {
			$part = ltrim($part, "\r\n");
			$split = strpos($part, "\r\n\r\n");
			if ($split === false) continue;

			$headerBlock = substr($part, 0, $split);
			$content = substr($part, $split + 4);
			// Remove trailing \r\n
			if (substr($content, -2) === "\r\n") {
				$content = substr($content, 0, -2);
			}

			// Parse headers
			$headers = array();
			foreach (explode("\r\n", $headerBlock) as $line) {
				$colon = strpos($line, ':');
				if ($colon === false) continue;
				$key = strtolower(trim(substr($line, 0, $colon)));
				$headers[$key] = trim(substr($line, $colon + 1));
			}

			$disposition = $headers['content-disposition'] ?? '';
			if (!preg_match('/name="([^"]*)"/', $disposition, $nm)) continue;
			$fieldName = $nm[1];

			if (preg_match('/filename="([^"]*)"/', $disposition, $fn)) {
				// File upload with size enforcement
				$filename = $fn[1];
				$contentLength = strlen($content);
				$type = $headers['content-type'] ?? 'application/octet-stream';

				if ($filename === '') {
					$error = UPLOAD_ERR_NO_FILE;
					$tmpPath = '';
				} elseif ($contentLength > $maxFileSize) {
					$error = UPLOAD_ERR_INI_SIZE;
					$tmpPath = '';
				} else {
					$tmpPath = tempnam($tmpDir, 'qbix_upload_');
					file_put_contents($tmpPath, $content);
					self::$uploadedFiles[$tmpPath] = true;
					$error = UPLOAD_ERR_OK;
				}

				$entry = array(
					'name'     => $filename,
					'type'     => $type,
					'tmp_name' => $tmpPath,
					'error'    => $error,
					'size'     => $contentLength,
				);
				self::setNestedValue($_FILES, $fieldName, $entry, true);
			} else {
				// Regular form field
				self::setNestedValue($_POST, $fieldName, $content, false);
			}
		}
	}

	/**
	 * Set a nested value in $_POST or $_FILES from form field names
	 * like "user[name]" or "files[]".
	 */
	private static function setNestedValue(&$array, $fieldName, $value, $isFile)
	{
		// Simple name (no brackets)
		if (strpos($fieldName, '[') === false) {
			if ($isFile) {
				$array[$fieldName] = $value;
			} else {
				$array[$fieldName] = $value;
			}
			return;
		}

		// Parse bracketed keys: "user[address][city]" → ['user', 'address', 'city']
		preg_match('/^([^\[]+)/', $fieldName, $base);
		$keys = array($base[1]);
		preg_match_all('/\[([^\]]*)\]/', $fieldName, $brackets);
		$keys = array_merge($keys, $brackets[1]);

		$ref = &$array;
		$lastIdx = count($keys) - 1;
		foreach ($keys as $idx => $key) {
			if ($key === '' && $idx === $lastIdx) {
				// [] means append
				if ($isFile) {
					// $_FILES array notation is special — each property is an array
					foreach ($value as $prop => $val) {
						$ref[$prop][] = $val;
					}
				} else {
					$ref[] = $value;
				}
				return;
			}
			if (!isset($ref[$key]) || !is_array($ref[$key])) {
				$ref[$key] = array();
			}
			$ref = &$ref[$key];
		}
		$ref = $value;
	}

	// ── URL Rewrite Engine ──────────────────────────────

	/**
	 * Apply URL rewriting rules.
	 * Returns the rewritten script path, or null if no rewrite matched.
	 *
	 * Config:
	 *   { "Q": { "compat": { "rewrite": "index.php" } } }
	 *
	 * This routes all requests that don't match a static file to index.php,
	 * which is what Laravel, Symfony, WordPress, and most modern PHP
	 * frameworks expect from their nginx/Apache rewrite rules.
	 *
	 * @param string $path The request URL path
	 * @param string $rootDir Document root
	 * @return string|null Rewritten script path, or null
	 */
	static function rewriteUrl($path, $rootDir)
	{
		// 1. Try .htaccess rules first (if .htaccess exists)
		$htaccessResult = self::applyHtaccessChain($path, $rootDir);
		if ($htaccessResult !== null) {
			return $htaccessResult;
		}

		// 2. JSON config-based rewrite (fallback when no .htaccess)
		$frontController = Q_Config::get('Q', 'compat', 'rewrite', null);
		if (!$frontController) return null;

		// If the path matches an actual file, don't rewrite
		$fsPath = $rootDir . str_replace('/', DIRECTORY_SEPARATOR, $path);
		if (is_file($fsPath)) return null;

		// Route to front controller
		$scriptPath = $rootDir . $frontController;
		if (!is_file($scriptPath)) return null;

		// Set up server vars that frameworks expect
		$_SERVER['SCRIPT_NAME'] = '/' . $frontController;
		$_SERVER['SCRIPT_FILENAME'] = $scriptPath;
		$_SERVER['PATH_INFO'] = $path;

		// Drupal-style query param rewriting: ?q=/the/path
		$queryParam = Q_Config::get('Q', 'compat', 'rewriteQueryParam', null);
		if ($queryParam) {
			$_GET[$queryParam] = ltrim($path, '/');
			$_SERVER['QUERY_STRING'] = http_build_query($_GET);
			// Keep REQUEST_URI as the original path — Drupal reads it
		}

		// Custom rewrite rules (regex → target)
		$rules = Q_Config::get('Q', 'compat', 'rewriteRules', null);
		if (is_array($rules)) {
			foreach ($rules as $rule) {
				$pattern = $rule['match'] ?? '';
				$target = $rule['to'] ?? '';
				$isStatic = !empty($rule['static']);
				if (!$pattern) continue;
				if (preg_match('#' . $pattern . '#', $path, $matches)) {
					if ($isStatic) return null; // serve as static file
					$rewritten = preg_replace('#' . $pattern . '#', $target, $path);
					// Parse the rewritten path for query string
					$parts = explode('?', $rewritten, 2);
					$scriptPath = $rootDir . ltrim($parts[0], '/');
					if (isset($parts[1])) {
						parse_str($parts[1], $extra);
						$_GET = array_merge($_GET, $extra);
						$_SERVER['QUERY_STRING'] = http_build_query($_GET);
					}
					$_SERVER['SCRIPT_NAME'] = $parts[0];
					$_SERVER['SCRIPT_FILENAME'] = $scriptPath;
					return $scriptPath;
				}
			}
		}

		return $scriptPath;
	}

	// ── Presets ─────────────────────────────────────────

	/**
	 * Load a framework preset config.
	 * Presets set compat.skipSourceCodeTransform, compat.rewrite, compat.ini, etc.
	 *
	 * @param string $preset Preset name: 'laravel', 'symfony', 'wordpress', 'drupal', 'exponential'
	 */
	static function loadPreset($preset)
	{
		$presets = array(
			'laravel' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'ini' => array(
					'upload_max_filesize' => '10M',
					'post_max_size' => '12M',
					'memory_limit' => '256M',
					'max_execution_time' => '60',
					'session.gc_maxlifetime' => '7200',
					'session.gc_probability' => '2',
					'session.gc_divisor' => '100',
				),
			),
			'symfony' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'ini' => array(
					'upload_max_filesize' => '10M',
					'post_max_size' => '12M',
					'memory_limit' => '256M',
					'max_execution_time' => '60',
					'session.gc_maxlifetime' => '1440',
				),
			),
			'wordpress' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'ini' => array(
					'upload_max_filesize' => '64M',
					'post_max_size' => '64M',
					'memory_limit' => '256M',
					'max_execution_time' => '300',
					'session.gc_maxlifetime' => '1440',
				),
			),
			'drupal' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'rewriteQueryParam' => 'q',
				'ini' => array(
					'upload_max_filesize' => '32M',
					'post_max_size' => '32M',
					'memory_limit' => '256M',
					'max_execution_time' => '240',
				),
			),
			// Exponential (the eZ Publish 4 legacy line). Unlike the modern
			// frameworks above it needs the source-code transform left ON --
			// its kernel calls header() and setcookie() the SAPI-coupled way,
			// and the transform is what makes those reach the response under a
			// persistent worker. And it needs its type registries kept across
			// requests: three include_once-populated globals that, cleared,
			// empty for the worker's life and fail publishing with
			// "Call to a member function initializeEvent() on null". Both are
			// discoveries that cost real time; the preset is where they stop
			// costing it.
			'exponential' => array(
				'enabled' => true,
				'rewrite' => 'index.php',
				'skipSourceCodeTransform' => false,
				'ini' => array(
					'upload_max_filesize' => '64M',
					'post_max_size' => '64M',
					'memory_limit' => '256M',
					'max_execution_time' => '300',
				),
				// Merged into Q.webserver, not Q.compat -- see loadPreset().
				'_webserver' => array(
					'keepGlobals' => array(
						'eZDataTypes', 'eZDataTypeObjects', 'eZDataTypeAllowedTypes',
						'eZWorkflowTypes', 'eZWorkflowTypeObjects', 'eZWorkflowAllowedTypes',
						'eZNotificationEventTypes', 'eZNotificationEventTypeObjects',
						'eZNotificationEventTypeAllowedTypes',
					),
				),
			),
		);

		if (!isset($presets[$preset])) {
			fwrite(STDERR, "Unknown preset: $preset (available: "
				. implode(', ', array_keys($presets)) . ")\n");
			return false;
		}

		$conf = $presets[$preset];
		// A preset may carry settings for the server itself, not only for the
		// compatibility layer -- Exponential's kept globals are the case. Pull
		// them out of the compat block and merge them under Q.webserver.
		$webserver = null;
		if (isset($conf['_webserver'])) {
			$webserver = $conf['_webserver'];
			unset($conf['_webserver']);
		}
		Q_Config::merge(array('Q' => array('compat' => $conf)));
		// Which preset is in force, for the panel's Apps and Frameworks tabs.
		Q_Config::set('Q', 'webserver', 'preset', $preset);
		if (is_array($webserver)) {
			Q_Config::merge(array('Q' => array('webserver' => $webserver)));
		}
		return true;
	}

	// ── .htaccess Parser ───────────────────────────────

	/**
	 * Parse and apply .htaccess rewrite rules from a file.
	 *
	 * Supports the subset of mod_rewrite used by WordPress, Laravel,
	 * Symfony, Drupal, Joomla, Magento, and most PHP frameworks:
	 *
	 *   RewriteEngine On
	 *   RewriteBase /subdir
	 *   RewriteCond %{REQUEST_FILENAME} !-f
	 *   RewriteCond %{REQUEST_FILENAME} !-d
	 *   RewriteCond %{REQUEST_URI} pattern [NC]
	 *   RewriteRule pattern target [L,QSA,R=301,F,E=VAR:val]
	 *
	 * @param string $htaccessPath Path to .htaccess file
	 * @param string $requestPath The request URL path
	 * @param string $rootDir Document root
	 * @return array|null ['script' => path, 'redirect' => url, 'forbidden' => bool] or null
	 */
	static function applyHtaccess($htaccessPath, $requestPath, $rootDir)
	{
		if (!is_file($htaccessPath)) return null;

		static $cache = array();
		$cacheKey = $htaccessPath;
		if (!isset($cache[$cacheKey]) || filemtime($htaccessPath) > ($cache[$cacheKey]['mtime'] ?? 0)) {
			$cache[$cacheKey] = array(
				'mtime' => filemtime($htaccessPath),
				'rules' => self::parseHtaccess(file_get_contents($htaccessPath)),
			);
		}

		$parsed = $cache[$cacheKey]['rules'];
		if (!$parsed['engine']) return null;

		$base = $parsed['base'] ?: '/';
		$env = array();

		foreach ($parsed['blocks'] as $block) {
			// Each block is: conditions[] + rule
			$conditions = $block['conditions'];
			$rule = $block['rule'];

			// Check all conditions
			$condsMet = true;
			foreach ($conditions as $cond) {
				if (!self::evalRewriteCond($cond, $requestPath, $rootDir, $env)) {
					$condsMet = false;
					break;
				}
			}
			if (!$condsMet) continue;

			// Apply the rule
			$result = self::evalRewriteRule($rule, $requestPath, $rootDir, $base, $env);
			if ($result !== null) return $result;
		}

		return null;
	}

	/**
	 * The replacement to write for a function name, fully qualified.
	 *
	 * The table holds plain names like Q_WebServer_Compat::_header. Written
	 * as they are, a file that declares a namespace resolves them inside it:
	 * Composer's autoloader, which is namespaced, asked PHP for
	 * Composer\Autoload\Q_WebServer_Compat and got a fatal. A leading
	 * backslash costs nothing in global code and is required in namespaced
	 * code.
	 *
	 * @method qualified
	 * @static
	 * @protected
	 * @param {string} $name Lowercased function name
	 * @return {string}
	 */
	protected static function qualified($name)
	{
		$to = self::$replacements[$name];
		return $to[0] === '\\' ? $to : '\\' . $to;
	}

	/**
	 * Parse .htaccess content into structured rules.
	 */
	private static function parseHtaccess($content)
	{
		$result = array('engine' => false, 'base' => '', 'blocks' => array());
		$lines = preg_split('/\r?\n/', $content);
		$pendingConds = array();

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') continue;

			// RewriteEngine On/Off
			if (preg_match('/^RewriteEngine\s+(On|Off)/i', $line, $m)) {
				$result['engine'] = (strtolower($m[1]) === 'on');
				continue;
			}

			// RewriteBase
			if (preg_match('/^RewriteBase\s+(\S+)/i', $line, $m)) {
				$result['base'] = rtrim($m[1], '/');
				continue;
			}

			// RewriteCond
			if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $m)) {
				$pendingConds[] = array(
					'testString' => $m[1],
					'pattern' => $m[2],
					'flags' => isset($m[3]) ? strtoupper($m[3]) : '',
				);
				continue;
			}

			// RewriteRule
			if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $m)) {
				$result['blocks'][] = array(
					'conditions' => $pendingConds,
					'rule' => array(
						'pattern' => $m[1],
						'target' => $m[2],
						'flags' => isset($m[3]) ? strtoupper($m[3]) : '',
					),
				);
				$pendingConds = array();
				continue;
			}
		}

		return $result;
	}

	/**
	 * Evaluate a RewriteCond.
	 */
	private static function evalRewriteCond($cond, $requestPath, $rootDir, &$env)
	{
		$testString = $cond['testString'];
		$pattern = $cond['pattern'];
		$negate = false;

		// Variable substitution
		$testString = self::htaccessVarReplace($testString, $requestPath, $rootDir, $env);

		// Negation
		if (isset($pattern[0]) && $pattern[0] === '!') {
			$negate = true;
			$pattern = substr($pattern, 1);
		}

		// Special tests
		if ($pattern === '-f') {
			$result = is_file($testString);
		} elseif ($pattern === '-d') {
			$result = is_dir($testString);
		} elseif ($pattern === '-l') {
			$result = is_link($testString);
		} elseif ($pattern === '-s') {
			$result = is_file($testString) && filesize($testString) > 0;
		} else {
			// Regex match
			$flags = $cond['flags'];
			$modifiers = strpos($flags, 'NC') !== false ? 'i' : '';
			$result = (bool) preg_match('#' . $pattern . '#' . $modifiers, $testString);
		}

		return $negate ? !$result : $result;
	}

	/**
	 * Evaluate a RewriteRule and return result or null.
	 */
	private static function evalRewriteRule($rule, $requestPath, $rootDir, $base, &$env)
	{
		$pattern = $rule['pattern'];
		$target = $rule['target'];
		$flags = $rule['flags'];

		// Strip base from request path for matching
		$matchPath = $requestPath;
		if ($base && strpos($matchPath, $base) === 0) {
			$matchPath = substr($matchPath, strlen($base));
		}
		$matchPath = ltrim($matchPath, '/');

		// Match the pattern
		if (!preg_match('#' . $pattern . '#', $matchPath, $matches)) {
			return null;
		}

		// Target "-" means don't rewrite, but flags still apply
		if ($target === '-') {
			// Handle [E=VAR:val] flag
			if (preg_match_all('/E=([^:,\]]+):([^,\]]*)/i', $flags, $em, PREG_SET_ORDER)) {
				foreach ($em as $e) {
					$env[$e[1]] = preg_replace_callback(
						'/%(\d)/', function ($m) use ($matches) { return $matches[(int)$m[1]] ?? ''; }, $e[2]
					);
				}
			}
			// [L] means stop processing
			if (strpos($flags, 'L') !== false) return null;
			return null;  // continue to next rule
		}

		// [F] = Forbidden
		if (strpos($flags, 'F') !== false) {
			return array('forbidden' => true);
		}

		// Backreference substitution in target
		$rewritten = preg_replace_callback('/(\\$|%)(\d)/', function ($m) use ($matches) {
			return $matches[(int)$m[2]] ?? '';
		}, $target);

		// ENV variable substitution
		$rewritten = self::htaccessVarReplace($rewritten, $requestPath, $rootDir, $env);

		// Ensure leading slash, avoid double slashes
		if ($rewritten !== '' && $rewritten[0] !== '/' && strpos($rewritten, '://') === false) {
			$rewritten = rtrim($base, '/') . '/' . $rewritten;
		}
		// Normalize double slashes
		$rewritten = preg_replace('#//+#', '/', $rewritten);

		// [R] or [R=301] = Redirect
		if (preg_match('/R(?:=(\d+))?/i', $flags, $rm)) {
			$code = isset($rm[1]) ? (int)$rm[1] : 302;
			return array('redirect' => $rewritten, 'code' => $code);
		}

		// [QSA] = Query String Append
		if (strpos($flags, 'QSA') !== false && !empty($_SERVER['QUERY_STRING'])) {
			$sep = strpos($rewritten, '?') !== false ? '&' : '?';
			$rewritten .= $sep . $_SERVER['QUERY_STRING'];
		}

		// Parse query string from rewritten URL
		$parts = explode('?', $rewritten, 2);
		$scriptPath = $rootDir . ltrim($parts[0], '/');

		if (isset($parts[1])) {
			parse_str($parts[1], $extra);
			$_GET = array_merge($_GET, $extra);
			$_SERVER['QUERY_STRING'] = http_build_query($_GET);
		}

		$_SERVER['SCRIPT_NAME'] = $parts[0];
		$_SERVER['SCRIPT_FILENAME'] = $scriptPath;

		return array('script' => $scriptPath);
	}

	/**
	 * Replace Apache variables in a string.
	 */
	private static function htaccessVarReplace($str, $requestPath, $rootDir, $env)
	{
		$replacements = array(
			'%{REQUEST_FILENAME}' => $rootDir . ltrim($requestPath, '/'),
			'%{REQUEST_URI}' => $requestPath,
			'%{DOCUMENT_ROOT}' => $rootDir,
			'%{SERVER_NAME}' => $_SERVER['SERVER_NAME'] ?? 'localhost',
			'%{HTTP_HOST}' => $_SERVER['HTTP_HOST'] ?? 'localhost',
			'%{QUERY_STRING}' => $_SERVER['QUERY_STRING'] ?? '',
			'%{THE_REQUEST}' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $requestPath . ' HTTP/1.1',
			'%{HTTPS}' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'on' : 'off',
			'%{SERVER_PORT}' => $_SERVER['SERVER_PORT'] ?? '80',
		);

		// ENV variables: %{ENV:VARNAME}
		$str = preg_replace_callback('/%\{ENV:([^}]+)\}/', function ($m) use ($env) {
			return $env[$m[1]] ?? '';
		}, $str);

		return strtr($str, $replacements);
	}

	/**
	 * Parse and apply .htaccess files.
	 * Checks the document root and each directory up to the script for .htaccess files.
	 *
	 * @param string $requestPath
	 * @param string $rootDir
	 * @return string|null Rewritten script path, or null
	 */
	static function applyHtaccessChain($requestPath, $rootDir)
	{
		// Check root .htaccess first
		$htaccessPath = $rootDir . '.htaccess';
		$result = self::applyHtaccess($htaccessPath, $requestPath, $rootDir);

		if ($result === null) {
			// Check directory-specific .htaccess
			$parts = explode('/', trim($requestPath, '/'));
			$dir = $rootDir;
			for ($i = 0; $i < count($parts) - 1; $i++) {
				$dir .= $parts[$i] . DIRECTORY_SEPARATOR;
				$subHtaccess = $dir . '.htaccess';
				$result = self::applyHtaccess($subHtaccess, $requestPath, $dir);
				if ($result !== null) break;
			}
		}

		if ($result === null) return null;

		if (!empty($result['forbidden'])) {
			http_response_code(403);
			echo "Forbidden";
			return '__FORBIDDEN__';
		}

		if (!empty($result['redirect'])) {
			Q_WebServer_Compat::_header('Location: ' . $result['redirect'], true, $result['code'] ?? 302);
			return '__REDIRECT__';
		}

		if (!empty($result['script'])) {
			return $result['script'];
		}

		return null;
	}

	// ── Helpers ─────────────────────────────────────────

	/**
	 * Parse PHP ini size values (8M, 1G, 512K) to bytes.
	 * @param string $value
	 * @return int
	 */
	static function parseIniSize($value)
	{
		$value = trim($value);
		$num = (int) $value;
		$suffix = strtoupper(substr($value, -1));
		switch ($suffix) {
			case 'G': return $num * 1073741824;
			case 'M': return $num * 1048576;
			case 'K': return $num * 1024;
			default:  return $num;
		}
	}
}

/**
 * Stream wrapper that transforms PHP source at include time.
 * Only transforms .php files in the app directory.
 * Non-PHP files and files outside the app pass through unchanged.
 */
class Q_WebServer_CompatFileWrapper
{
	/**
	 * A path without its file:// scheme, as the real filesystem functions
	 * take it. Exactly what preg_replace('/^file:\/\//', '', $path) gave --
	 * case-sensitive, at the start only, once -- without a regular
	 * expression on a path that every file operation passes through.
	 * @method bare
	 * @static
	 * @param {string} $path
	 * @return {string}
	 */
	static function bare($path)
	{
		return strncmp($path, 'file://', 7) === 0 ? substr($path, 7) : $path;
	}

	/**
	 * Set by PHP on every wrapper instance that is opened with a stream
	 * context. Declaring it keeps PHP 8.2+ from reporting the assignment
	 * as a dynamic property -- a deprecation notice that, with the default
	 * display_errors, is written into the response body. Text responses
	 * merely carried a stray paragraph; a generated image came out with
	 * 1.5KB of notices in front of its PNG signature and would not open.
	 *
	 * @var resource|null
	 */
	public $context;

	/** @var resource The underlying file handle */
	private $handle;
	/** @var int Modification time of the file a transformed buffer came from */
	private $mtime = 0;
	/** @var array|null Directory entries, when listed without a handle */
	private $dirEntries = null;

	/**
	 * Real stats taken during this request, by path.
	 *
	 * A template engine asks for the same few files' modification times over
	 * and over -- one search page asked 3 855 times about a few dozen files
	 * -- and each real stat costs an unwrap, which leaves a resource behind
	 * for the life of the worker (see self::unwrap()). The first stat of a
	 * path in a request is kept and the repeats answered from it.
	 *
	 * Emptied at the end of every request, and whenever this process writes,
	 * renames, deletes or creates anything through the wrapper, so what it
	 * holds is never older than the request and never older than this
	 * process's own last change. Bounded, so no request can grow it without
	 * limit. Exempt from the snapshot restore (Q_WebServer_Snapshot), which
	 * would otherwise put back the parent's stats from before the fork.
	 *
	 * @var array
	 */
	private static $statMemo = array();

	/** @var int Entries kept at most; past it the memo starts again. */
	const STAT_MEMO_MAX = 8192;

	/**
	 * STREAM_OPEN_FOR_INCLUDE: set in stream_open()'s options for include
	 * and require, never for fopen() or file_get_contents(). PHP does not
	 * define the constant for PHP code.
	 */
	const OPEN_FOR_INCLUDE = 0x80;

	/**
	 * The bytes of files included through the wrapper, by path, with the
	 * mtime and size they were read at: path => array(mtime, size, bytes).
	 *
	 * A template engine includes the same compiled templates over and over
	 * -- one search page, ~1 900 includes of a few dozen files -- and each
	 * real open is an unwrap, which leaves a resource behind for the life of
	 * the worker (see self::unwrap()). Kept here, an include costs one real
	 * stat per path per request (remembered in $statMemo) and no open at all
	 * while the file's mtime and size still match that stat.
	 *
	 * What is served is always the file's real contents as of a stat taken
	 * in this request; nothing is ever served that was not read from the
	 * file. Bounded by CONTENT_CACHE_MAX bytes per process and emptied when
	 * full. Filled in the parent by the warm-up, it is shared by every
	 * worker copy-on-write. Exempt from the snapshot restore.
	 *
	 * @var array
	 */
	private static $contentCache = array();
	private static $contentBytes = 0;
	const CONTENT_CACHE_MAX = 4194304;
	const CONTENT_FILE_MAX = 524288;

	/**
	 * The mtime each included path had when this process last served it.
	 *
	 * The opcode cache reads its clock only at request startup, which a
	 * persistent worker never repeats, so it never revalidates a script it
	 * holds: a regenerated template or an edited class kept running the old
	 * compile until a restart. When a path's mtime moves, its compile is
	 * invalidated here, and the include that follows compiles the new bytes.
	 *
	 * @var array
	 */
	private static $servedMtime = array();

	/**
	 * A path's real stat, taken at most once per request. A missing path is
	 * answered with glob(), without the real wrapper.
	 *
	 * @method realStat
	 * @static
	 * @param {string} $realPath
	 * @return {array|false}
	 */
	static function realStat($realPath)
	{
		if (isset(self::$statMemo[$realPath])) {
			return self::$statMemo[$realPath];
		}
		if (self::existsAndIsDir($realPath) === null) {
			return false;
		}
		self::unwrap($realPath);
		$stat = @stat($realPath);
		self::rewrap();
		if (!$stat) return false;
		if (count(self::$statMemo) >= self::STAT_MEMO_MAX) {
			self::$statMemo = array();
		}
		return self::$statMemo[$realPath] = $stat;
	}

	/**
	 * The mtime and size of a file, without the real wrapper.
	 *
	 * libcurl reads file:// itself, never through PHP's stream wrappers, and
	 * reports a file's modification time and length. That is all an include
	 * needs, and all filemtime() and filesize() return, so those cost no
	 * unwrap (see self::unwrap()). The answer goes only to them and never
	 * into PHP's own stat cache, so no stat() or is_writable() elsewhere can
	 * be handed an incomplete one. Where curl, or its file protocol, is not available, or
	 * the path is not a plain file, it is the real stat.
	 *
	 * @method includeStat
	 * @static
	 * @param {string} $realPath
	 * @return {array|false} array('mtime' => ..., 'size' => ...) or false
	 */
	static function includeStat($realPath)
	{
		if (isset(self::$statMemo[$realPath])) {
			return self::$statMemo[$realPath];
		}
		if (isset(self::$includeMemo[$realPath])) {
			return self::$includeMemo[$realPath];
		}
		$curl = self::curlHandle();
		if ($curl === null or strncmp($realPath, 'phar://', 7) === 0) {
			return self::realStat($realPath);
		}
		$type = self::existsAndIsDir($realPath);
		if ($type === null) return false;
		if ($type === true) return self::realStat($realPath);
		$abs = $realPath;
		if ($abs[0] !== '/' and !preg_match('~^[A-Za-z]:[\\\\/]~', $abs)) {
			clearstatcache(true, $abs);
			$abs = realpath($abs);
			if ($abs === false) return false;
		}
		curl_setopt($curl, CURLOPT_URL, 'file://' . str_replace('%2F', '/', rawurlencode($abs)));
		if (curl_exec($curl) === false) return self::realStat($realPath);
		$mtime = (int) curl_getinfo($curl, CURLINFO_FILETIME);
		$size = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
		if ($mtime <= 0 or $size === false or $size < 0) return self::realStat($realPath);
		$stat = array('mtime' => $mtime, 'size' => (int) $size);
		if (count(self::$includeMemo) >= self::STAT_MEMO_MAX) {
			self::$includeMemo = array();
		}
		// Kept apart from full stats: url_stat() must never answer from it.
		return self::$includeMemo[$realPath] = $stat;
	}

	/** @var array mtime/size answers from includeStat(), for this request */
	private static $includeMemo = array();

	/** @var CurlHandle|resource|false|null reused for includeStat() */
	private static $curl = null;

	private static function curlHandle()
	{
		if (self::$curl === null) {
			self::$curl = false;
			if (function_exists('curl_init') and function_exists('curl_version')) {
				$v = curl_version();
				if (!empty($v['protocols']) and in_array('file', $v['protocols'], true)) {
					$h = curl_init();
					curl_setopt_array($h, array(CURLOPT_NOBODY => true, CURLOPT_FILETIME => true,
						CURLOPT_RETURNTRANSFER => true, CURLOPT_PROTOCOLS => CURLPROTO_FILE));
					self::$curl = $h;
				}
			}
		}
		return self::$curl === false ? null : self::$curl;
	}

	/**
	 * Record the mtime a path is being served at, and drop the opcode
	 * cache's compile of it if the file has changed since.
	 */
	private static function noteServed($realPath, $mtime)
	{
		if (isset(self::$servedMtime[$realPath])
			and self::$servedMtime[$realPath] !== $mtime
			and function_exists('opcache_invalidate')) {
			@opcache_invalidate($realPath, true);
		}
		if (count(self::$servedMtime) >= self::STAT_MEMO_MAX) {
			self::$servedMtime = array();
		}
		self::$servedMtime[$realPath] = $mtime;
	}

	/** Serve $bytes from memory as this stream. */
	private function serveBytes($bytes, $mtime, &$opened_path, $realPath)
	{
		$this->buffer = $bytes;
		$this->position = 0;
		$this->transformed = true;
		$this->mtime = $mtime;
		$opened_path = $realPath;
		return true;
	}

	/**
	 * Read a whole file for an include, never a mixture of two versions.
	 *
	 * file_get_contents() reads in 8 KB chunks. A file rewritten in place
	 * (opened "w", truncated, written again) between two of those reads came
	 * back as the head of one version and the tail of the next -- which is
	 * valid PHP, so it ran and was answered as a 200. It took a reader being
	 * held up between chunks for as long as the rewrite took, so it was rare,
	 * and it was seen: 'V000096:...00009' . '97,...:V000097'.
	 *
	 * So the file is read in one read() of its whole size, and the bytes are
	 * kept only if the file's size and mtime are the same after the read as
	 * before it, and the bytes are exactly that size. Otherwise it is read
	 * again. A file still changing after a few tries is served as last read,
	 * as before: a partial file is a parse error, not a mixture.
	 *
	 * Called unwrapped, so fopen() is PHP's own.
	 * @return {string|false}
	 */
	private static function readWhole($realPath)
	{
		$bytes = false;
		for ($try = 0; $try < 5; ++$try) {
			if ($try) usleep(1000);
			$h = @fopen($realPath, 'rb');
			if ($h === false) return false;
			stream_set_read_buffer($h, 0);
			$before = fstat($h);
			$size = $before ? (int) $before['size'] : 0;
			// One byte past the size, so a file that grew meanwhile shows.
			$bytes = '';
			while (strlen($bytes) <= $size) {
				$chunk = fread($h, $size + 1 - strlen($bytes));
				if ($chunk === false or $chunk === '') break;
				$bytes .= $chunk;
			}
			$after = fstat($h);
			fclose($h);
			if ($before and $after and strlen($bytes) === $size
				and (int) $after['size'] === $size
				and (int) $after['mtime'] === (int) $before['mtime']) {
				return $bytes;
			}
		}
		return $bytes;
	}

	/**
	 * Read an included file that needs no transform, keep its bytes if they
	 * fit, and serve them. The caller has unwrapped.
	 */
	private function readAndServe($realPath, $stat, &$opened_path)
	{
		$bytes = self::readWhole($realPath);
		if ($bytes === false) return false;
		$size = strlen($bytes);
		// Only kept when the bytes are the size the stat said: a file being
		// rewritten under us is served, not remembered. Nor while it is new
		// enough that a same-second rewrite of the same size would match the
		// entry (Q_WebServer_Compat::isRacy()).
		if ($stat and $size === (int) $stat['size'] and $size <= self::CONTENT_FILE_MAX
			and !Q_WebServer_Compat::isRacy((int) $stat['mtime'])) {
			if (self::$contentBytes + $size > self::CONTENT_CACHE_MAX) {
				self::$contentCache = array();
				self::$contentBytes = 0;
			}
			if (isset(self::$contentCache[$realPath])) {
				self::$contentBytes -= self::$contentCache[$realPath][1];
			}
			self::$contentCache[$realPath] = array((int) $stat['mtime'], $size, $bytes);
			self::$contentBytes += $size;
		}
		return $this->serveBytes($bytes, $stat ? (int) $stat['mtime'] : 0, $opened_path, $realPath);
	}

	/**
	 * Forget every remembered stat, at a request boundary: the end of a
	 * request, or a worker's first one after a fork.
	 *
	 * When the opcode cache does not check timestamps on every include
	 * (revalidate_freq above 0, or validate_timestamps off), it answers an
	 * include of a script it holds without opening the file, so this wrapper
	 * never sees the include and noteServed() never runs. A file another
	 * process regenerated then kept running its old compile for as long as
	 * revalidate_freq, which in a persistent worker is the rest of its life:
	 * the cache reads its clock only at request startup. So here, once per
	 * request, every path served so far is looked at again and the compile
	 * of any whose mtime moved is dropped. With revalidate_freq=0 the cache
	 * checks for itself and this costs nothing.
	 * @method forgetStats
	 * @static
	 */
	static function forgetStats()
	{
		self::dropStats();
		if (!self::$servedMtime or !self::opcacheTrustsItsCopy()) {
			return;
		}
		foreach (self::$servedMtime as $path => $mtime) {
			$stat = self::includeStat($path);
			$now = $stat === false ? -1 : (int) $stat['mtime'];
			if ($now !== $mtime) {
				@opcache_invalidate($path, true);
				if ($now === -1) unset(self::$servedMtime[$path]);
				else self::$servedMtime[$path] = $now;
			}
		}
		// Those stats were taken between requests; the next request takes its own.
		self::dropStats();
	}

	/**
	 * Forget every remembered stat, for a change made through the wrapper or
	 * a clearstatcache() inside a request.
	 * @method dropStats
	 * @static
	 */
	static function dropStats()
	{
		self::$statMemo = array();
		self::$includeMemo = array();
		self::$existMemo = array();
	}

	/** @var bool|null whether the opcode cache serves includes without checking the file */
	private static $opcacheTrusts = null;

	/** Whether the opcode cache is on and answers includes without a stat. */
	private static function opcacheTrustsItsCopy()
	{
		if (self::$opcacheTrusts === null) {
			$on = function_exists('opcache_invalidate') && function_exists('opcache_get_status')
				&& ($s = @opcache_get_status(false)) && !empty($s['opcache_enabled']);
			self::$opcacheTrusts = $on && !(ini_get('opcache.validate_timestamps')
				&& (int) ini_get('opcache.revalidate_freq') === 0);
		}
		return self::$opcacheTrusts;
	}

	/**
	 * Forget everything held about one file: remembered stats, its kept
	 * bytes and its cached transform. For a change made through the wrapper
	 * -- a write, rename, unlink or touch -- where only the stats used to be
	 * dropped, so a request that regenerated a file and then included it
	 * could be handed the previous contents.
	 * @method forgetFile
	 * @static
	 * @param {string} $path
	 */
	static function forgetFile($path)
	{
		self::dropStats();
		$path = self::bare((string) $path);
		if (isset(self::$contentCache[$path])) {
			self::$contentBytes -= self::$contentCache[$path][1];
			unset(self::$contentCache[$path]);
		}
		Q_WebServer_Compat::forgetTransform($path);
		// The opcode cache may answer the next include of it without looking,
		// so its compile goes too; see forgetStats().
		if (self::opcacheTrustsItsCopy()) {
			@opcache_invalidate($path, true);
		}
	}
	/** @var string Buffered transformed content for reading */
	private $buffer = '';
	/** @var int Read position in buffer */
	private $position = 0;
	/** @var bool Whether this file was transformed (reading from buffer) */
	private $transformed = false;
	/** @var resource Directory handle */
	private $dirHandle;

	// We need to restore the real file:// wrapper for actual file ops,
	// then re-register ours. This prevents infinite recursion.
	//
	// It is not free, and the cost does not go away. Every
	// stream_wrapper_register() allocates a resource that PHP releases only
	// when the request ends -- which, in a persistent worker, is never. So
	// each unwrap()/rewrap() pair left one behind: Exponential made up to
	// 14 000 of them a request, answering file_exists() and is_dir(), and a
	// worker grew by ~1.6 MB a request until it was killed. Everything that
	// can be answered without the real wrapper now is (url_stat for
	// existence and type, directory listings, fstat on an open handle), and
	// repeated stats within a request are remembered; what is left are real
	// opens, writes and first stats, and the worker memory ceiling bounds it.
	//
	// Not done, on purpose: answering an include of a script the opcode
	// cache already holds with an empty stream. The engine opens the file on
	// every include even when it will run the cached compile, so it looked
	// free -- but when the cache decides the script must be recompiled (a
	// regenerated template with a new mtime), it compiles whatever the
	// stream holds, and pages silently lost the templates that came back
	// empty. An optimisation that can blank part of a page is not one.
	//
	// phar:// is swapped out only for an operation on a phar path. It used to
	// be swapped out -- and back in, one more registration -- around every
	// operation whenever an engine archive was in use, which doubled the cost
	// above for plain files that never touch an archive.
	private static $pharOut = false;
	private static function unwrap($path = null)
	{
		stream_wrapper_restore('file');
		self::$pharOut = (Q_WebServer_Compat::pharWrapped()
			and is_string($path) and strncmp($path, 'phar://', 7) === 0);
		if (self::$pharOut) {
			stream_wrapper_restore('phar');
		}
	}
	private static function rewrap()
	{
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', __CLASS__);
		if (self::$pharOut) {
			stream_wrapper_unregister('phar');
			stream_wrapper_register('phar', __CLASS__);
			self::$pharOut = false;
		}
	}

	public function stream_open($path, $mode, $options, &$opened_path)
	{
		// This runs for every file the hosted application opens, not only
		// for include and require: every template, cache file and image.
		// Both tests are exact, anchored string tests, so they are string
		// comparisons rather than two regular expressions per open.
		$realPath = self::bare($path);

		// Only PHP files opened for reading (include/require) are
		// transformed. Cheapest test first: most opens are not reads of a
		// .php file and are settled on the mode.
		$shouldTransform = ($mode === 'r' || $mode === 'rb')
		  && substr_compare($realPath, '.php', -4, 4, true) === 0
		  && Q_WebServer_Compat::isEnabled();

		// Any change made through the wrapper can make a remembered stat --
		// or this file's cached bytes or transform -- wrong.
		if (strpbrk($mode, 'waxc+') !== false) {
			self::forgetFile($realPath);
		}

		// Includes: one real stat per path per request, then the bytes from
		// memory while that stat says the file has not changed.
		$include = (($options & self::OPEN_FOR_INCLUDE) and ($mode === 'r' or $mode === 'rb'));
		$stat = false;
		if ($include) {
			$stat = self::includeStat($realPath);
			if ($stat === false) {
				return false;
			}
			$mtime = (int) $stat['mtime'];
			$racy = Q_WebServer_Compat::isRacy($mtime);
			// The opcode cache keys on the same whole-second mtime, so a
			// same-second rewrite would run the previous compile.
			if ($racy and function_exists('opcache_invalidate')) {
				@opcache_invalidate($realPath, true);
			}
			self::noteServed($realPath, $mtime);
			$kept = isset(self::$contentCache[$realPath]) ? self::$contentCache[$realPath] : null;
			if (!$racy and $kept and $kept[0] === $mtime and $kept[1] === (int) $stat['size']) {
				return $this->serveBytes($kept[2], $mtime, $opened_path, $realPath);
			}
		}

		if ($shouldTransform) {
			// Check in-memory cache — covers both transforms and sentinels.
			$cached = Q_WebServer_Compat::getCachedTransform($realPath);
			// A transform of an older version of the file is not the file.
			// The cache used to be trusted without looking, so an edited
			// file that needs the transform kept its old code until restart.
			//
			// The sentinel too: a file rewritten so that it now needs the
			// transform (it gained an exit() or a header() call) kept being
			// served untransformed, and its exit() ended the worker. And
			// nothing is trusted while the file is new enough that its mtime
			// cannot tell versions apart.
			if ($cached !== null and $stat
				and (Q_WebServer_Compat::isRacy((int) $stat['mtime'])
					or Q_WebServer_Compat::cachedMtime($realPath) !== (int) $stat['mtime'])) {
				// Dropped, not just skipped: transformSource() below looks in
				// the same cache first and would hand the old transform back.
				Q_WebServer_Compat::forgetTransform($realPath);
				$cached = null;
			}
			if ($cached !== null) {
				if ($cached === false) {
					// Sentinel: this file doesn't need transforms.
					// Open it normally — no tokenization, no transform --
					// or, for an include, read it once and keep its bytes.
					self::unwrap($realPath);
					if ($include) {
						$ok = $this->readAndServe($realPath, $stat, $opened_path);
						self::rewrap();
						return $ok;
					}
					$this->handle = fopen($realPath, $mode);
					self::rewrap();
					return $this->handle !== false;
				}
				// Cached transform — serve from memory, no disk I/O
				$this->buffer = $cached;
				$this->position = 0;
				$this->transformed = true;
				$this->mtime = Q_WebServer_Compat::cachedMtime($realPath);
				$opened_path = $realPath;
				return true;
			}
		}

		self::unwrap($realPath);

		if ($shouldTransform && is_file($realPath)) {
			// Runtime trust check — reject files that fail integrity verification
			if (class_exists('Q_WebServer_Trust', false) && Q_WebServer_Trust::isEnabled()) {
				if (!Q_WebServer_Trust::checkFile($realPath)) {
					self::rewrap();
					trigger_error("Trust: blocked untrusted file: $realPath", E_USER_WARNING);
					return false;
				}
			}

			// Cache miss (file added after prewarm) — read, transform, cache.
			// The mtime is the one seen before reading, so an entry can never
			// claim a newer version than the bytes it holds; and the
			// transform is computed without its cache side-effects, stored
			// here once with that mtime.
			$mtimeBefore = $stat ? (int) $stat['mtime'] : (int) @filemtime($realPath);
			$source = self::readWhole($realPath);
			if ($source !== false) {
				$transformed = Q_WebServer_Compat::transformSource($source, '');
				if ($transformed !== $source) {
					Q_WebServer_Compat::saveCache($realPath, $transformed, $mtimeBefore);
					$this->buffer = $transformed;
					$this->position = 0;
					$this->transformed = true;
					$this->mtime = $mtimeBefore;
					self::rewrap();
					$opened_path = $realPath;
					return true;
				}

				// No changes -- and that answer was worth keeping.
				//
				// The prewarm walk caches a sentinel for every file it finds
				// that needs no transform, so those cost nothing again. A file
				// that appears afterwards got no such sentinel: it was read and
				// tokenized here, found to need nothing, and then forgotten --
				// so the next request read and tokenized it again, and so did
				// every request after that, for the life of the worker.
				//
				// Every file an application generates at runtime is in that
				// category. On an unmodified Exponential site, six renders of
				// one content page performed 1138 transforms over 314 distinct
				// files, one compiled template 48 times, against 23 cache hits.
				// Those files are compiled templates and configuration caches:
				// they never need a transform, and they were the bulk of the
				// work.
				Q_WebServer_Compat::saveCache($realPath, false, $mtimeBefore);
			}
		}

		// Pass through — non-PHP, write mode, or no transforms needed
		if ($include) {
			$ok = $this->readAndServe($realPath, $stat, $opened_path);
			self::rewrap();
			return $ok;
		}
		$this->handle = fopen($realPath, $mode);
		self::rewrap();
		return $this->handle !== false;
	}

	public function stream_read($count)
	{
		if ($this->transformed) {
			$data = substr($this->buffer, $this->position, $count);
			$this->position += strlen($data);
			return $data;
		}
		return fread($this->handle, $count);
	}

	public function stream_write($data)
	{
		if ($this->transformed) return 0;
		return fwrite($this->handle, $data);
	}

	public function stream_tell()
	{
		if ($this->transformed) return $this->position;
		return ftell($this->handle);
	}

	public function stream_eof()
	{
		if ($this->transformed) return $this->position >= strlen($this->buffer);
		return feof($this->handle);
	}

	public function stream_seek($offset, $whence = SEEK_SET)
	{
		if ($this->transformed) {
			switch ($whence) {
				case SEEK_SET: $this->position = $offset; break;
				case SEEK_CUR: $this->position += $offset; break;
				case SEEK_END: $this->position = strlen($this->buffer) + $offset; break;
			}
			return true;
		}
		return fseek($this->handle, $offset, $whence) === 0;
	}

	public function stream_stat()
	{
		// A transformed file is served from memory. Its stat carries the
		// source file's mtime: without one the opcode cache will not store a
		// script, so every transformed file -- the front controller, the
		// autoloader, every class that calls header() -- was compiled again
		// on every include instead of once.
		if ($this->transformed) {
			return array('size' => strlen($this->buffer), 'mtime' => $this->mtime,
				'mode' => 0100644);
		}
		// fstat() works on the handle already open and never looks a wrapper
		// up, so there is nothing to unwrap for -- and unwrapping is not free:
		// see self::unwrap().
		return fstat($this->handle);
	}

	public function stream_close()
	{
		if ($this->transformed) {
			$this->buffer = '';
			return;
		}
		if ($this->handle) fclose($this->handle);
	}

	public function stream_set_option($option, $arg1, $arg2)
	{
		return false;
	}

	public function stream_lock($operation)
	{
		if ($this->transformed) return true;
		// PHP calls this entry point with 0 when it releases a lock it took
		// implicitly -- file_put_contents() with LOCK_EX does exactly that.
		// flock() raises a ValueError for 0 in PHP 8, and because the error
		// escapes mid-write the file is left at zero bytes with no sign that
		// anything went wrong. Anything that is not a real flock operation
		// is a no-op here.
		$op = $operation & ~LOCK_NB;
		if ($op !== LOCK_SH and $op !== LOCK_EX and $op !== LOCK_UN) {
			return true;
		}
		return flock($this->handle, $operation);
	}

	public function stream_flush()
	{
		if ($this->transformed) return true;
		return fflush($this->handle);
	}

	/**
	 * Without this, every truncating write through the wrapper -- which is
	 * what fopen('w') and file_put_contents() do -- emits
	 * "stream_truncate is not implemented" and falls back on whatever the
	 * handle already contained. eZ Publish alone produced tens of
	 * kilobytes of these per hundred requests.
	 */
	public function stream_truncate($newSize)
	{
		if ($this->transformed) return true;
		return ftruncate($this->handle, $newSize);
	}

	// ── Required for file_exists, is_file, stat, etc. ──

	public function url_stat($path, $flags)
	{
		$realPath = self::bare($path);

		// A path that is not there is answered without the real wrapper:
		// glob() asks the operating system directly, and a failed stat is
		// never cached by PHP, so the answer cannot be mistaken for anything
		// later. Most probes -- an autoloader or a template lookup trying
		// candidates -- are exactly this. See self::unwrap() for why avoiding
		// it matters.
		//
		// Never raise for a path that is not there, either. Whether a missing
		// path deserves a diagnostic is the calling function's business, and
		// @stat() is not enough to stay quiet: a custom error handler is still
		// invoked, and eZ Publish installs one that throws around
		// mysqli_set_charset(), so a miss during an autoload inside that call
		// silently left the connection latin1.
		// is_link() and lstat() ask about the link itself, which stat() and
		// the existence check below both look through: a link to a missing
		// target is there for is_link() and gone for everything else. These
		// used to be answered with stat(), so is_link() was false for every
		// link under the wrapper.
		if ($flags & STREAM_URL_STAT_LINK) {
			self::unwrap($realPath);
			$stat = @lstat($realPath);
			self::rewrap();
			return $stat ?: false;
		}

		if (self::existsAndIsDir($realPath) === null) {
			return false;
		}

		// A path that exists gets its real stat. An answer made up from the
		// type alone would be cached by PHP under that path, and the
		// filemtime() that commonly follows a file_exists() would read a
		// modification time of zero from it. Existence and type checks in
		// application code do not come here at all -- the source transform
		// sends file_exists(), is_dir() and is_file() to Compat, which asks
		// the operating system directly.
		return self::realStat($realPath);
	}

	/**
	 * Whether a path exists, and if so whether it is a directory, asked of
	 * the operating system without the stream wrappers.
	 *
	 * glob() takes a pattern, so the path's own pattern characters are
	 * escaped; with none left it matches the path alone or nothing. GLOB_MARK
	 * appends a slash to a directory, following links as stat() does. A link
	 * whose target is gone is still listed, where stat() would fail, so a
	 * non-directory match is confirmed with realpath(), which also works on
	 * the path directly -- after dropping that path's realpath cache entry,
	 * which could otherwise still name a file deleted since.
	 *
	 * @method existsAndIsDir
	 * @static
	 * @param {string} $path
	 * @return {boolean|null} true: a directory; false: exists, not a
	 *   directory; null: not there
	 */
	static function existsAndIsDir($path)
	{
		if ($path === '' or strpos($path, "\0") !== false) return null;
		// Remembered like the stats: Exponential asks about the same paths
		// again and again while it renders a page (2600 questions about 700
		// paths on the front page), and every answer used to cost a glob()
		// and a realpath(). Forgotten with the stats -- a change made through
		// the wrapper, clearstatcache(), another program having run, the end
		// of the request -- so it is never older than they are.
		//
		// Only in a pool worker, which has those request boundaries. The
		// server process has none: it keeps the wrapper for its whole life,
		// so a remembered "not there" would never be forgotten -- the
		// response cache's generation marker, created after the first look,
		// was never seen.
		if (!self::$rememberExistence) {
			return self::askExistsAndIsDir($path);
		}
		if (isset(self::$existMemo[$path]) or array_key_exists($path, self::$existMemo)) {
			return self::$existMemo[$path];
		}
		if (count(self::$existMemo) >= self::STAT_MEMO_MAX) {
			self::$existMemo = array();
		}
		return self::$existMemo[$path] = self::askExistsAndIsDir($path);
	}

	/** @var array path => existsAndIsDir() answer, for as long as the stats */
	private static $existMemo = array();

	/** @var bool whether existsAndIsDir() answers are remembered in this process */
	private static $rememberExistence = false;

	/**
	 * Remember existsAndIsDir() answers from now on, in a process that has
	 * request boundaries (forgetStats()) -- a pool worker. Called from the
	 * worker's side of the fork.
	 * @method rememberExistence
	 * @static
	 * @param {boolean} $on
	 */
	static function rememberExistence($on = true)
	{
		self::$rememberExistence = (bool) $on;
		self::$existMemo = array();
	}

	/** existsAndIsDir() without the memory: asks the operating system. */
	private static function askExistsAndIsDir($path)
	{
		$pattern = addcslashes($path, self::globSpecials());
		$found = @glob($pattern, GLOB_MARK | GLOB_NOSORT);
		if (!$found) return null;
		$last = substr($found[0], -1);
		if ($last === '/' or $last === DIRECTORY_SEPARATOR) return true;
		clearstatcache(true, $path);
		return realpath($path) === false ? null : false;
	}

	/**
	 * Characters glob() reads as pattern syntax in a path. The backslash is
	 * one of them except on Windows, where it separates directories.
	 */
	private static function globSpecials()
	{
		return DIRECTORY_SEPARATOR === '\\' ? '*?[' : '\\*?[';
	}

	// ── Directory operations ──

	public function dir_opendir($path, $options)
	{
		// Listed with glob(), which does not go through the stream wrappers,
		// so nothing is unwrapped (see self::unwrap()). Two patterns, because
		// "*" leaves out names beginning with a dot and GLOB_BRACE is not
		// available everywhere; ".*" brings "." and ".." with it, as
		// readdir() does.
		$realPath = rtrim(self::bare($path), '/');
		if ($realPath === '') $realPath = '/';
		if (self::existsAndIsDir($realPath) !== true) return false;
		$base = addcslashes($realPath === '/' ? '' : $realPath, self::globSpecials()) . '/';
		$entries = array();
		foreach (array('*', '.*') as $p) {
			foreach ((@glob($base . $p, GLOB_NOSORT) ?: array()) as $f) {
				$entries[basename($f)] = true;
			}
		}
		$entries['.'] = true;
		$entries['..'] = true;
		$this->dirEntries = array_keys($entries);
		return true;
	}

	public function dir_readdir()
	{
		if ($this->dirEntries !== null) {
			$e = current($this->dirEntries);
			if ($e === false) return false;
			next($this->dirEntries);
			return $e;
		}
		return readdir($this->dirHandle);
	}

	public function dir_rewinddir()
	{
		if ($this->dirEntries !== null) { reset($this->dirEntries); return true; }
		return rewinddir($this->dirHandle);
	}

	public function dir_closedir()
	{
		if ($this->dirEntries !== null) { $this->dirEntries = null; return true; }
		return closedir($this->dirHandle);
	}

	// ── File operations (rename, unlink, mkdir, rmdir) ──

	public function rename($from, $to)
	{
		self::forgetFile($from);
		self::forgetFile($to);
		self::unwrap(strncmp($from, 'phar://', 7) === 0 ? $from : $to);
		$result = rename(self::bare($from),
		                 self::bare($to));
		self::rewrap();
		return $result;
	}

	public function unlink($path)
	{
		self::forgetFile($path);
		self::unwrap($path);
		$result = unlink(self::bare($path));
		self::rewrap();
		return $result;
	}

	public function mkdir($path, $mode, $options)
	{
		self::dropStats();
		self::unwrap($path);
		$result = mkdir(self::bare($path), $mode,
			$options & STREAM_MKDIR_RECURSIVE);
		self::rewrap();
		return $result;
	}

	public function rmdir($path, $options)
	{
		self::dropStats();
		self::unwrap($path);
		$result = rmdir(self::bare($path));
		self::rewrap();
		return $result;
	}

	public function stream_metadata($path, $option, $value)
	{
		$realPath = self::bare($path);
		self::forgetFile($realPath);
		self::unwrap($realPath);
		switch ($option) {
			case STREAM_META_TOUCH:
				$result = touch($realPath, $value[0] ?? time(), $value[1] ?? time());
				break;
			case STREAM_META_OWNER_NAME:
			case STREAM_META_OWNER:
				$result = chown($realPath, $value);
				break;
			case STREAM_META_GROUP_NAME:
			case STREAM_META_GROUP:
				$result = chgrp($realPath, $value);
				break;
			case STREAM_META_ACCESS:
				$result = chmod($realPath, $value);
				break;
			default:
				$result = false;
		}
		self::rewrap();
		return $result;
	}
}

/**
 * Thrown in place of exit / die, so a script can end its request without
 * ending the worker that is running it.
 *
 * Carries the status the script asked to exit with. It is a normal end of
 * request, not an error, and the pool treats it as one.
 */
if (!class_exists('Q_WebServer_ExitSignal', false)) {
class Q_WebServer_ExitSignal extends \Exception
{
	/**
	 * @property $status
	 * @type integer
	 */
	public $status = 0;

	function __construct($status = 0)
	{
		parent::__construct('script called exit', 0);
		$this->status = (int) $status;
	}
}
}
