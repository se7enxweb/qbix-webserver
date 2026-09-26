<?php
/**
 * @module Q
 */
/**
 * The server's settings as the shell sees them, in the manner of `zfs get`:
 * a name, a value and where the value comes from.
 *
 *   SOURCE  default   the engine's default
 *           config    the site configuration file
 *           runtime   changed in the running server since it started
 *
 * `set name=value` changes a runtime-applicable setting in the running server
 * (the runner asks the server to apply it); `set -p` also writes it to the
 * site configuration file. A setting that only takes effect at start says so.
 * Security settings are shown but cannot be changed from the shell.
 *
 * @class Q_WebServer_Shell_Settings
 */
class Q_WebServer_Shell_Settings
{
	/**
	 * name => array(config path, type, applies, default, description, protected)
	 * applies: runtime (the running server takes it now) or restart.
	 */
	const TABLE = array(
		'workers' => array(array('Q', 'webserver', 'workers'), 'int', 'runtime', 4, 'Worker processes (the pool resizes now)', false),
		'spareWorkers' => array(array('Q', 'webserver', 'spareWorkers'), 'int', 'restart', 0, 'Idle workers kept ready; 0 = fixed pool', false),
		'idleWorkerTimeout' => array(array('Q', 'webserver', 'idleWorkerTimeout'), 'int', 'restart', 60, 'Seconds an extra idle worker is kept', false),
		'requestTimeout' => array(array('Q', 'webserver', 'requestTimeout'), 'int', 'runtime', 30, 'Seconds a request may run before its worker is replaced', false),
		'maxRequests' => array(array('Q', 'webserver', 'maxRequests'), 'int', 'restart', 0, 'Requests a worker serves before it is recycled; 0 = no limit', false),
		'workerMemoryCeiling' => array(array('Q', 'webserver', 'workerMemoryCeiling'), 'int', 'restart', 0, 'MB a worker may reach before it is replaced; 0 = no ceiling', false),
		'design' => array(array('Q', 'webserver', 'design'), 'string', 'runtime', 'default', 'The design the server\'s own pages use', false),
		'brand' => array(array('Q', 'webserver', 'brand'), 'string', 'restart', 'Qbix Server', 'The product name the server\'s pages show', false),
		'cache' => array(array('Q', 'web', 'cache', 'enabled'), 'bool', 'restart', false, 'The response cache', false),
		'extensionsCheck' => array(array('Q', 'webserver', 'extensionsCheck'), 'bool', 'restart', true, 'Warn at start about missing PHP extensions', false),
		'shell.timeout' => array(array('Q', 'shell', 'timeout'), 'int', 'runtime', 120, 'Seconds a foreground shell command may run', false),
		'shell.toggleKey' => array(array('Q', 'shell', 'toggleKey'), 'string', 'runtime', '`', 'The key that opens and closes the shell', false),
		'shell.enabled' => array(array('Q', 'shell', 'enabled'), 'bool', 'restart', true, 'The shell itself', true),
		'shell.allowSystem' => array(array('Q', 'shell', 'allowSystem'), 'bool', 'restart', false, 'Raw OS commands in the advanced tier', true),
		'shell.tier' => array(array('Q', 'shell', 'tier'), 'string', 'restart', 'advanced', 'The highest tier a shell session may use', true),
		'panel.remote' => array(array('Q', 'panel', 'remote'), 'bool', 'restart', false, 'The control panel without a password from anywhere', true),
		'panel.twofactor' => array(array('Q', 'panel', 'twofactor'), 'bool', 'restart', false, 'Require a second factor (TOTP) at panel sign-in, once one is enrolled', true),
		'dashboard.remote' => array(array('Q', 'dashboard', 'remote'), 'bool', 'restart', false, 'The dashboard without a credential from anywhere', true),
	);

	/**
	 * Every setting's row.
	 * @param {array} $effective name => value the running server has (from the runner's context)
	 * @param {array} $changed names changed at runtime
	 * @param {string|null} $configFile the site configuration file
	 * @return {array} list of array(name, value, source, applies, description, protected)
	 */
	static function rows(array $effective, array $changed, $configFile)
	{
		$file = self::readFile($configFile);
		$rows = array();
		foreach (self::TABLE as $name => $t) {
			list($path, $type, $applies, $default, $desc, $protected) = $t;
			$inFile = self::dig($file, $path, $found);
			if (in_array($name, $changed, true) && array_key_exists($name, $effective)) {
				$value = $effective[$name]; $source = 'runtime';
			} elseif ($found) {
				$value = $inFile; $source = 'config';
			} elseif (array_key_exists($name, $effective) && $effective[$name] !== null) {
				$value = $effective[$name]; $source = $effective[$name] === $default ? 'default' : 'config';
			} else {
				$value = $default; $source = 'default';
			}
			$rows[] = array('name' => $name, 'value' => self::show($value), 'source' => $source,
				'applies' => $applies, 'description' => $desc, 'protected' => $protected ? 'yes' : 'no');
		}
		return $rows;
	}

	/** A value as text. */
	static function show($v)
	{
		if (is_bool($v)) return $v ? 'on' : 'off';
		if ($v === null) return '-';
		if (is_array($v)) return json_encode($v);
		return (string) $v;
	}

	/**
	 * Check and convert a value for a setting.
	 * @return {array} array(value, error|null)
	 */
	static function parse($name, $raw)
	{
		if (!isset(self::TABLE[$name])) return array(null, 'unknown setting "' . $name . '" (see: get all)');
		$t = self::TABLE[$name];
		if ($t[5]) return array(null, $name . ' is a security setting and cannot be changed from the shell');
		switch ($t[1]) {
			case 'int':
				if (!preg_match('/^-?\d+$/', (string) $raw)) return array(null, $name . ' takes a whole number');
				$v = (int) $raw;
				if ($name === 'workers' && ($v < 1 || $v > 4096)) return array(null, 'workers must be between 1 and 4096');
				if ($v < 0) return array(null, $name . ' cannot be negative');
				return array($v, null);
			case 'bool':
				$r = strtolower((string) $raw);
				if (in_array($r, array('on', 'true', 'yes', '1'), true)) return array(true, null);
				if (in_array($r, array('off', 'false', 'no', '0'), true)) return array(false, null);
				return array(null, $name . ' takes on or off');
			default:
				if ($name === 'design' && !preg_match('/^[A-Za-z0-9_-]+$/', (string) $raw)) return array(null, 'a design is a plain name');
				if (strlen((string) $raw) > 200) return array(null, 'too long');
				return array((string) $raw, null);
		}
	}

	/**
	 * Write a setting into the site configuration file (JSON), keeping a copy
	 * of the file as it was beside it (.bak).
	 * @return {string|null} an error, or null
	 */
	static function persist($configFile, $name, $value)
	{
		if (!$configFile || !is_file($configFile)) return 'no site configuration file to write to';
		if (!is_writable($configFile)) return 'cannot write ' . $configFile;
		$text = (string) file_get_contents($configFile);
		$data = json_decode($text, true);
		if (!is_array($data)) return $configFile . ' is not JSON; change it by hand';
		$ref = &$data;
		foreach (self::TABLE[$name][0] as $key) {
			if (!isset($ref[$key]) || !is_array($ref[$key])) $ref[$key] = array();
			$ref = &$ref[$key];
		}
		$ref = $value;
		unset($ref);
		@copy($configFile, $configFile . '.bak');
		$tmp = $configFile . '.tmp' . getmypid();
		if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
			return 'cannot write ' . $configFile;
		}
		@chmod($tmp, fileperms($configFile) & 0777);
		if (!@rename($tmp, $configFile)) { @unlink($tmp); return 'cannot replace ' . $configFile; }
		return null;
	}

	private static function readFile($configFile)
	{
		if (!$configFile || !is_file($configFile)) return array();
		$data = json_decode((string) @file_get_contents($configFile), true);
		return is_array($data) ? $data : array();
	}

	private static function dig(array $data, array $path, &$found)
	{
		$found = false;
		$ref = $data;
		foreach ($path as $key) {
			if (!is_array($ref) || !array_key_exists($key, $ref)) return null;
			$ref = $ref[$key];
		}
		$found = true;
		return $ref;
	}
}
