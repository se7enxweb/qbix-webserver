<?php
/**
 * @module Q
 */

/**
 * Built-in event loop using stream_select(). Zero dependencies.
 * Handles stream watching, timers, deferred callbacks, signals.
 *
 * @class Q_Evented_StreamSelect
 * @extends Q_Evented_Driver
 */
class Q_Evented_StreamSelect extends Q_Evented_Driver
{
	/**
	 * The last throwable a reader callback raised. The catch in run()
	 * assigned to this without it being declared, so PHP turned the very
	 * exception the catch exists to swallow into a fatal and killed the
	 * server -- the opposite of what the comment there promises.
	 * @property $lastError
	 * @static
	 * @type {Throwable|null}
	 */
	static $lastError = null;

	protected $running = false;
	protected $stopRequested = false;
	protected $nextId = 1;
	protected $readers = array();     // id => [stream, callback]
	protected $writers = array();     // id => [stream, callback]
	protected $timers = array();      // id => [fireAt, interval, callback]
	protected $deferred = array();    // id => callback
	protected $signals = array();     // id => [signal, callback]
	protected $disabled = array();    // id => true
	protected $streamToReaders = array();
	protected $streamToWriters = array();

	function onReadable($stream, callable $cb)
	{
		$id = 'r' . ($this->nextId++);
		$this->readers[$id] = array($stream, $cb);
		$this->streamToReaders[(int)$stream][$id] = true;
		return $id;
	}

	function onWritable($stream, callable $cb)
	{
		$id = 'w' . ($this->nextId++);
		$this->writers[$id] = array($stream, $cb);
		$this->streamToWriters[(int)$stream][$id] = true;
		return $id;
	}

	function delay($sec, callable $cb)
	{
		$id = 'd' . ($this->nextId++);
		$this->timers[$id] = array(
			'fireAt' => microtime(true) + $sec,
			'interval' => 0, 'callback' => $cb
		);
		return $id;
	}

	function repeat($sec, callable $cb)
	{
		$id = 't' . ($this->nextId++);
		$this->timers[$id] = array(
			'fireAt' => microtime(true) + $sec,
			'interval' => $sec, 'callback' => $cb
		);
		return $id;
	}

	function defer(callable $cb)
	{
		$id = 'f' . ($this->nextId++);
		$this->deferred[$id] = $cb;
		return $id;
	}

	function onSignal($sig, callable $cb)
	{
		if (!function_exists('pcntl_signal')) {
			throw new Exception("Signal handling requires pcntl extension");
		}
		$id = 's' . ($this->nextId++);
		$this->signals[$id] = array($sig, $cb);
		$signals = &$this->signals;
		$disabled = &$this->disabled;
		pcntl_signal($sig, function ($s) use (&$signals, &$disabled) {
			foreach ($signals as $sid => $entry) {
				if ($entry[0] === $s && empty($disabled[$sid])) {
					$entry[1]($s);
				}
			}
		});
		return $id;
	}

	function cancel($id)
	{
		if (isset($this->readers[$id])) {
			$key = (int)$this->readers[$id][0];
			unset($this->readers[$id], $this->streamToReaders[$key][$id]);
			if (empty($this->streamToReaders[$key])) unset($this->streamToReaders[$key]);
		}
		if (isset($this->writers[$id])) {
			$key = (int)$this->writers[$id][0];
			unset($this->writers[$id], $this->streamToWriters[$key][$id]);
			if (empty($this->streamToWriters[$key])) unset($this->streamToWriters[$key]);
		}
		unset($this->timers[$id], $this->deferred[$id],
			$this->signals[$id], $this->disabled[$id]);
	}

	function disable($id) { $this->disabled[$id] = true; }
	function enable($id) { unset($this->disabled[$id]); }
	function running() { return $this->running; }

	/**
	 * Ask run() to return after the current tick.
	 *
	 * The extra flag is there because stop() is almost always called from
	 * inside a callback, part-way through a tick that would otherwise go on to
	 * block in stream_select() with no timeout. Clearing $running does not
	 * reach that select, so the loop could sit in it indefinitely while the
	 * caller believed it had been asked to stop. tick() reads this and hands
	 * control back instead.
	 */
	function stop() { $this->running = false; $this->stopRequested = true; }

	function run()
	{
		$this->stopRequested = false;
		$this->running = true;
		while ($this->running && $this->hasWatchers()) {
			$this->tick(null);
		}
		$this->running = false;
	}

	function tick($timeout = 0)
	{
		// 1. Deferred callbacks
		if (!empty($this->deferred)) {
			$batch = $this->deferred;
			$this->deferred = array();
			foreach ($batch as $id => $cb) {
				if (empty($this->disabled[$id])) self::guard($cb, 'deferred');
			}
		}

		// 2. Timers
		$now = microtime(true);
		$nextTimer = null;
		// Iterating a copy: a callback may cancel this or any other timer,
		// and a cancelled timer must neither fire later in this pass nor be
		// brought back as a half-entry (fireAt only) by rescheduling it.
		foreach ($this->timers as $id => $t) {
			if (!isset($this->timers[$id]) || !empty($this->disabled[$id])) continue;
			if ($now >= $t['fireAt']) {
				if ($t['interval'] <= 0) unset($this->timers[$id]);
				self::guard($t['callback'], 'timer');
				if ($t['interval'] > 0 && isset($this->timers[$id])) {
					$this->timers[$id]['fireAt'] = $now + $t['interval'];
					// Its next run counts toward how long this tick may wait;
					// left out, a repeating timer waited for the next I/O or
					// the next other timer, however far off that was.
					if ($nextTimer === null || $t['interval'] < $nextTimer) $nextTimer = $t['interval'];
				}
			} else {
				$rem = $t['fireAt'] - $now;
				if ($nextTimer === null || $rem < $nextTimer) $nextTimer = $rem;
			}
		}

		// 3. Signals
		if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

		// A handler just run -- a shutdown signal, most often -- may have
		// asked the loop to stop. Falling through to the select below would
		// block on whatever watchers are left, with nothing remaining to
		// deliver an event, which is how a shutdown that has already done
		// its work ends up waiting for ever.
		if ($this->stopRequested) return;

		// 4. Stream select
		//
		// A watcher whose stream has since been closed must not be handed to
		// stream_select(). PHP 8 raises a TypeError for a closed resource,
		// and a TypeError is an exception, so the "@" further down does not
		// contain it: the loop throws, run() unwinds, and the whole server
		// dies -- parent and every worker -- on a completely ordinary event.
		// A browser opening several connections in parallel for a page's
		// images and dropping the spares is enough to do it.
		//
		// Dead streams are cancelled rather than merely skipped, or every
		// later tick would walk over them again. Iterating a property array
		// yields a copy, so cancelling inside the loop is safe.
		$read = $write = array();
		foreach ($this->readers as $id => $e) {
			if (!is_resource($e[0])) { $this->cancel($id); continue; }
			if (empty($this->disabled[$id])) $read[] = $e[0];
		}
		foreach ($this->writers as $id => $e) {
			if (!is_resource($e[0])) { $this->cancel($id); continue; }
			if (empty($this->disabled[$id])) $write[] = $e[0];
		}

		if (empty($read) && empty($write)) {
			if ($nextTimer !== null) {
				$sleep = ($timeout !== null) ? min($nextTimer, $timeout) : $nextTimer;
				if ($sleep > 0) usleep((int)($sleep * 1000000));
			}
			return;
		}

		// Filter out closed/invalid resources — a watcher callback may have
		// closed a socket that was already in this iteration's read/write arrays.
		$read = array_filter($read, 'is_resource');
		$write = array_filter($write, 'is_resource');
		if (empty($read) && empty($write)) return;

		$wait = $timeout;
		if ($nextTimer !== null) {
			$wait = ($wait !== null) ? min($wait, $nextTimer) : $nextTimer;
		}
		$sec = ($wait !== null) ? (int)$wait : null;
		$usec = ($wait !== null) ? (int)(($wait - (int)$wait) * 1000000) : null;
		$except = null;
		// A stream can still be closed between the check above and this call,
		// by a callback run earlier in this same tick. The event loop of a
		// server should not be able to die of that, so the throw is caught,
		// the dead watchers pruned, and the tick abandoned; the next one runs
		// with a clean set.
		try {
			$n = @stream_select($read, $write, $except, $sec, $usec);
		} catch (\TypeError $e) {
			foreach ($this->readers as $id => $r) {
				if (!is_resource($r[0])) $this->cancel($id);
			}
			foreach ($this->writers as $id => $w) {
				if (!is_resource($w[0])) $this->cancel($id);
			}
			return;
		}
		if ($n === false) return;

		foreach ($read as $stream) {
			$key = (int)$stream;
			if (!isset($this->streamToReaders[$key])) continue;
			foreach ($this->streamToReaders[$key] as $id => $_) {
				if (empty($this->disabled[$id]) && isset($this->readers[$id])) {
					// One connection must not be able to kill the server.
					// These callbacks run the whole request path, and any
					// throw in one of them unwinds out of run(). Drop the
					// watcher and keep serving everybody else.
					try {
						$this->readers[$id][1]($stream);
					} catch (\Throwable $e) {
						$this->cancel($id);
						self::$lastError = $e;
					}
				}
			}
		}
		foreach ($write as $stream) {
			$key = (int)$stream;
			if (!isset($this->streamToWriters[$key])) continue;
			foreach ($this->streamToWriters[$key] as $id => $_) {
				if (empty($this->disabled[$id]) && isset($this->writers[$id])) {
					$this->writers[$id][1]($stream);
				}
			}
		}
	}

	protected function hasWatchers()
	{
		return !empty($this->readers) || !empty($this->writers)
			|| !empty($this->timers) || !empty($this->deferred)
			|| !empty($this->signals);
	}

}
