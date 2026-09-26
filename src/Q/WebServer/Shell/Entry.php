<?php
/**
 * How to start the shell's runner and the console tools, wherever the server
 * came from: a source checkout, the phar, or a single static binary.
 *
 * From source, qshell.php and qbixconsole.php sit beside the server and run
 * as scripts. In the phar (and the deb/rpm packages and the container image,
 * which start the phar) they are embedded, and a script inside a phar cannot
 * be handed to PHP by path: the phar itself is started with --qshell or
 * --qconsole, and qbixserver.php hands over to the embedded file. A static
 * binary is the interpreter and the phar in one file, so it is started
 * directly with the same switch.
 *
 * Kept free of other classes: qshell.php loads it before anything else.
 *
 * @class Q_WebServer_Shell_Entry
 * @static
 */
class Q_WebServer_Shell_Entry
{
	/**
	 * The directory the engine's files are read from: a real directory from
	 * source, phar://<file> in a phar. Where qshell.php is found, or null.
	 * @method dir
	 * @static
	 * @param {string|null} $near a server script to look beside first
	 * @return {string|null}
	 */
	static function dir($near = null)
	{
		$dirs = array();
		if (is_string($near) && $near !== '') $dirs[] = dirname($near);
		$dirs[] = dirname(__DIR__, 4);
		foreach ($dirs as $d) {
			if (is_file($d . '/qshell.php')) return $d;
		}
		return null;
	}

	/**
	 * The argv that starts the runner (qshell.php), without its own options;
	 * null when this installation has none.
	 * @method shell
	 * @static
	 * @param {string|null} $near see dir()
	 * @return {array|null}
	 */
	static function shell($near = null)
	{
		$dir = self::dir($near);
		if ($dir === null) return null;
		$packed = self::packed($dir);
		return $packed !== null ? array_merge($packed, array('--qshell')) : array(PHP_BINARY, $dir . '/qshell.php');
	}

	/**
	 * The argv that starts the console tools (qbixconsole.php), without the
	 * command. From source PHP's messages are sent to standard error with -d;
	 * packed, the entry point sets the same (a static binary takes no -d).
	 * @method console
	 * @static
	 * @param {string|null} $dir the engine's directory (see dir())
	 * @return {array|null}
	 */
	static function console($dir = null)
	{
		if ($dir === null || $dir === '') $dir = self::dir();
		if ($dir === null) return null;
		$packed = self::packed($dir);
		if ($packed !== null) return array_merge($packed, array('--qconsole'));
		return array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $dir . '/qbixconsole.php');
	}

	/**
	 * For a directory inside a phar: the argv that starts that phar -- the
	 * binary alone when the phar is the executable itself (a static binary),
	 * otherwise PHP and the phar. Null for a real directory.
	 * @method packed
	 * @static
	 * @param {string} $dir
	 * @return {array|null}
	 */
	static function packed($dir)
	{
		if (strncmp((string) $dir, 'phar://', 7) !== 0) return null;
		$file = substr((string) $dir, 7);
		// phar://<file>/<inside>: the phar is the longest prefix that is a file.
		while ($file !== '' && !is_file($file)) {
			$up = dirname($file);
			if ($up === $file) break;
			$file = $up;
		}
		if (!is_file($file)) return null;
		$self = realpath(PHP_BINARY);
		if ($self !== false && $self === realpath($file)) return array(PHP_BINARY);
		return array(PHP_BINARY, $file);
	}
}
