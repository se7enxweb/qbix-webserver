<?php
/**
 * @module Q
 */
/**
 * Non-blocking event loop for Q. Timers, stream watchers,
 * deferred callbacks, signal handling. Built-in stream_select
 * driver; native Io\Poll and Revolt drivers when available (see driver()).
 * @class Q_Evented
 */
class Q_Evented
{
	static function onReadable($stream, callable $cb) { return self::driver()->onReadable($stream, $cb); }
	static function onWritable($stream, callable $cb) { return self::driver()->onWritable($stream, $cb); }
	static function delay($sec, callable $cb) { return self::driver()->delay($sec, $cb); }
	static function repeat($sec, callable $cb) { return self::driver()->repeat($sec, $cb); }
	static function defer(callable $cb) { return self::driver()->defer($cb); }
	static function onSignal($sig, callable $cb) { return self::driver()->onSignal($sig, $cb); }
	static function cancel($id) { self::driver()->cancel($id); }
	static function disable($id) { self::driver()->disable($id); }
	static function enable($id) { self::driver()->enable($id); }
	static function run() { self::driver()->run(); }
	static function tick($timeout = 0) { self::driver()->tick($timeout); }
	static function stop() { self::driver()->stop(); }
	static function running() { return self::driver()->running(); }

	/**
	 * The event loop backend, chosen once per process:
	 *   iopoll  native Io\Poll (PHP 8.6+, or its polyfill): epoll/kqueue
	 *   revolt  Revolt\EventLoop, when revolt/event-loop is installed
	 *   select  stream_select(), built in, always available
	 * "auto" (the default) takes the first available in that order. Force one
	 * with the QBIX_EVENT_LOOP environment variable or Q.webserver.eventLoop;
	 * a forced backend this PHP cannot load falls back to auto, with a warning.
	 */
	static function driver()
	{
		if (!self::$driver) {
			$want = self::configured();
			$name = self::available($want) ? $want : null;
			if ($want !== 'auto' && !$name) {
				fwrite(STDERR, "  event loop \"$want\" is not available here; choosing automatically\n");
			}
			if (!$name) {
				foreach (array('iopoll', 'revolt', 'select') as $n) {
					if (self::available($n)) { $name = $n; break; }
				}
			}
			$class = self::$backends[$name];
			self::$driver = new $class();
		}
		return self::$driver;
	}

	/**
	 * The backend asked for: the environment first, then config, else auto.
	 * @return {string} auto, iopoll, revolt or select
	 */
	static function configured()
	{
		$want = getenv('QBIX_EVENT_LOOP');
		if (($want === false || $want === '') && class_exists('Q_Config')) {
			try { $want = Q_Config::get('Q', 'webserver', 'eventLoop', 'auto'); } catch (\Throwable $e) { $want = 'auto'; }
		}
		$want = strtolower(trim((string)$want));
		if ($want === 'stream_select' || $want === 'streamselect') $want = 'select';
		return isset(self::$backends[$want]) ? $want : 'auto';
	}

	/** Whether the named backend can be used on this PHP. */
	static function available($name)
	{
		switch ($name) {
			case 'iopoll': return Q_Evented_IoPoll::available();
			case 'revolt': return class_exists('Revolt\\EventLoop');
			case 'select': return true;
		}
		return false;
	}

	/** The name of the backend in use (choosing it if none is yet). */
	static function backend()
	{
		return array_search(get_class(self::driver()), self::$backends) ?: get_class(self::$driver);
	}

	static function setDriver(Q_Evented_Driver $d) { self::$driver = $d; }
	protected static $driver = null;
	protected static $backends = array(
		'iopoll' => 'Q_Evented_IoPoll',
		'revolt' => 'Q_Evented_Revolt',
		'select' => 'Q_Evented_StreamSelect',
	);
}
