<?php

/**
 * What an uncaught error in a request says, and to whom.
 *
 * The log always gets where it happened, the first frames of the trace, and
 * every previous exception in the chain with its own file and line -- a
 * framework's outer exception is often only "something went wrong while
 * doing X", and the real cause sits further down.
 *
 * The response body gets the message and nothing else, unless the server
 * runs with --debug (or Q.webserver.debug): then the class, file and line,
 * the trace and the chain as well. Paths in a response are the same
 * trade-off as display_errors, so they are never shown by default.
 *
 * @class Q_WebServer_ErrorReport
 * @static
 */
class Q_WebServer_ErrorReport
{
	/** Frames of each trace written to the log. */
	const LOG_FRAMES = 8;

	/** Previous exceptions followed, in the log and in a debug body. */
	const MAX_CHAIN = 10;

	/**
	 * Whether responses carry the details: --debug, or Q.webserver.debug.
	 * @method debug
	 * @static
	 * @return {boolean}
	 */
	static function debug()
	{
		return class_exists('Q_Config', false)
			and (bool) Q_Config::get('Q', 'webserver', 'debug', false);
	}

	/**
	 * The response body for an uncaught error.
	 * @method body
	 * @static
	 * @param {Throwable} $e
	 * @param {boolean} [$debug] defaults to self::debug()
	 * @return {string}
	 */
	static function body($e, $debug = null)
	{
		if ($debug === null) $debug = self::debug();
		if (!$debug) return (string) $e->getMessage();
		$out = get_class($e) . ': ' . $e->getMessage()
			. ' in ' . $e->getFile() . ':' . $e->getLine() . "\n"
			. $e->getTraceAsString() . "\n";
		foreach (self::chain($e) as $prev) {
			$out .= "\nCaused by " . get_class($prev) . ': ' . $prev->getMessage()
				. ' in ' . $prev->getFile() . ':' . $prev->getLine() . "\n"
				. $prev->getTraceAsString() . "\n";
		}
		return $out;
	}

	/**
	 * The log entry for an uncaught error: one line saying where, the first
	 * frames, then a line for each previous exception with its first frames.
	 * @method logText
	 * @static
	 * @param {Throwable} $e
	 * @param {string} [$who] prefix, e.g. "worker 1234"
	 * @return {string} ending in a newline
	 */
	static function logText($e, $who = '')
	{
		$text = sprintf("  %suncaught %s at %s:%d: %s\n%s",
			$who === '' ? '' : $who . ': ', get_class($e), $e->getFile(), $e->getLine(),
			self::oneLine($e->getMessage()), self::frames($e, '    '));
		foreach (self::chain($e) as $prev) {
			$text .= sprintf("    caused by %s at %s:%d: %s\n%s",
				get_class($prev), $prev->getFile(), $prev->getLine(),
				self::oneLine($prev->getMessage()), self::frames($prev, '      '));
		}
		return $text;
	}

	/**
	 * Write the log entry to STDERR (the server's error log).
	 * @method log
	 * @static
	 * @param {Throwable} $e
	 * @param {string} [$who]
	 */
	static function log($e, $who = '')
	{
		$text = self::logText($e, $who);
		if (defined('STDERR')) @fwrite(STDERR, $text);
		else error_log(rtrim($text));
	}

	/** The previous exceptions, outermost first, at most MAX_CHAIN. */
	static function chain($e)
	{
		$out = array();
		for ($p = $e->getPrevious(); $p and count($out) < self::MAX_CHAIN; $p = $p->getPrevious()) {
			$out[] = $p;
		}
		return $out;
	}

	private static function frames($e, $indent)
	{
		$lines = '';
		foreach (array_slice($e->getTrace(), 0, self::LOG_FRAMES) as $f) {
			$lines .= $indent . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' '
				. (isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '')
				. ($f['function'] ?? '') . "\n";
		}
		return $lines;
	}

	private static function oneLine($s)
	{
		return str_replace(array("\r", "\n"), ' ', (string) $s);
	}
}
