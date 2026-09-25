<?php
/**
 * Event loop driver using PHP 8.6's native Io\Poll API.
 *
 * Io\Poll wraps epoll (Linux), kqueue (macOS/BSD), event ports (Solaris),
 * and WSAPoll (Windows) natively — no PECL extensions needed.
 *
 * Performance vs stream_select:
 *   stream_select: O(n) — copies and scans all fds on every call
 *   epoll/kqueue:  O(1) — kernel tracks readiness, returns only ready fds
 *
 * At 100 connections the difference is negligible. At 1000+ connections,
 * stream_select spends milliseconds per tick just scanning; epoll returns
 * in microseconds regardless of total connection count.
 *
 * Also works on PHP 8.1–8.5 via symfony/polyfill-io-poll (backed by
 * stream_select, so no performance gain, but API compatibility).
 *
 * Behaves like Q_Evented_StreamSelect in everything but how it waits:
 * disabled watchers are skipped, a timer cancelled from inside its own
 * callback stays cancelled, a repeating timer's next run bounds the wait,
 * stop() is honoured before the tick blocks, and no callback -- timer,
 * deferred or stream -- can end the loop by throwing.
 *
 * @class Q_Evented_IoPoll
 * @extends Q_Evented_Driver
 */
class Q_Evented_IoPoll extends Q_Evented_Driver
{
	/**
	 * The last throwable a stream callback raised (its watcher is cancelled).
	 * @property $lastError
	 * @static
	 * @type {Throwable|null}
	 */
	static $lastError = null;

	/**
	 * Longest a tick waits when nothing else bounds it, so that signals
	 * (dispatched between waits) are never held up for long.
	 */
	const MAX_WAIT = 0.1;

	protected $running = false;
	protected $stopRequested = false;
	protected $nextId = 1;
	protected $poll = null;           // Io\Poll\Context
	protected $readers = array();     // id => [stream, callback, handle]
	protected $writers = array();     // id => [stream, callback, handle]
	protected $timers = array();      // id => [fireAt, interval, callback]
	protected $deferred = array();    // id => callback
	protected $signals = array();     // id => [signal, callback]
	protected $disabled = array();    // id => true
	protected $signalInstalled = array();  // signal => true

	/**
	 * Whether this PHP has the native polling API (or its polyfill).
	 * @method available
	 * @static
	 * @return {boolean}
	 */
	static function available()
	{
		return class_exists('Io\\Poll\\Context') && class_exists('StreamPollHandle');
	}

	function __construct()
	{
		$this->poll = new \Io\Poll\Context();
	}

	function onReadable($stream, callable $cb)
	{
		$id = $this->nextId++;
		$handle = new \StreamPollHandle($stream);
		$this->readers[$id] = array($stream, $cb, $handle);
		$this->poll->add($handle, [\Io\Poll\Event::Read], ['id' => $id, 'type' => 'read']);
		return $id;
	}

	function onWritable($stream, callable $cb)
	{
		$id = $this->nextId++;
		$handle = new \StreamPollHandle($stream);
		$this->writers[$id] = array($stream, $cb, $handle);
		$this->poll->add($handle, [\Io\Poll\Event::Write], ['id' => $id, 'type' => 'write']);
		return $id;
	}

	function cancel($id)
	{
		foreach (array('readers', 'writers') as $kind) {
			if (!isset($this->{$kind}[$id])) continue;
			try { $this->poll->remove($this->{$kind}[$id][2]); } catch (\Throwable $e) {}
			unset($this->{$kind}[$id]);
		}
		unset($this->timers[$id], $this->deferred[$id],
			$this->signals[$id], $this->disabled[$id]);
	}

	function disable($id) { $this->disabled[$id] = true; }
	function enable($id) { unset($this->disabled[$id]); }

	function delay($sec, callable $cb)
	{
		$id = $this->nextId++;
		$this->timers[$id] = array(microtime(true) + $sec, 0, $cb);
		return $id;
	}

	function repeat($sec, callable $cb)
	{
		$id = $this->nextId++;
		$this->timers[$id] = array(microtime(true) + $sec, $sec, $cb);
		return $id;
	}

	function defer(callable $cb)
	{
		$id = $this->nextId++;
		$this->deferred[$id] = $cb;
		return $id;
	}

	function onSignal($sig, callable $cb)
	{
		if (!function_exists('pcntl_signal')) {
			throw new Exception("Signal handling requires pcntl extension");
		}
		$id = $this->nextId++;
		$this->signals[$id] = array($sig, $cb);
		// One process-level handler per signal, dispatching to every watcher
		// of it; installing one per watcher replaced the earlier ones.
		if (empty($this->signalInstalled[$sig])) {
			$this->signalInstalled[$sig] = true;
			pcntl_signal($sig, function ($s) {
				foreach ($this->signals as $sid => $entry) {
					if ($entry[0] === $s && empty($this->disabled[$sid])) {
						self::guard(function () use ($entry, $s) { $entry[1]($s); }, 'signal');
					}
				}
			});
		}
		return $id;
	}

	function run()
	{
		$this->stopRequested = false;
		$this->running = true;
		while ($this->running && $this->hasWatchers()) {
			$this->tick(null);
		}
		$this->running = false;
	}

	/** See Q_Evented_StreamSelect::stop(): also ends a tick before it waits. */
	function stop() { $this->running = false; $this->stopRequested = true; }
	function running() { return $this->running; }

	/**
	 * One pass of the loop: deferred callbacks, due timers, signals, then
	 * wait for I/O. $timeout bounds the wait in seconds: 0 polls without
	 * waiting, null waits until the next timer (at most MAX_WAIT).
	 */
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

		// 2. Timers. Iterating a copy: a callback may cancel this or any
		// other timer, and a cancelled timer must neither fire later in this
		// pass nor be brought back by rescheduling it.
		$now = microtime(true);
		$nextTimer = null;
		foreach ($this->timers as $id => $t) {
			if (!isset($this->timers[$id]) || !empty($this->disabled[$id])) continue;
			if ($now >= $t[0]) {
				if ($t[1] <= 0) unset($this->timers[$id]);
				self::guard($t[2], 'timer');
				if ($t[1] > 0 && isset($this->timers[$id])) {
					$this->timers[$id][0] = $now + $t[1];
					// Its next run bounds this tick's wait too.
					if ($nextTimer === null || $t[1] < $nextTimer) $nextTimer = $t[1];
				}
			} else {
				$rem = $t[0] - $now;
				if ($nextTimer === null || $rem < $nextTimer) $nextTimer = $rem;
			}
		}

		// 3. Signals
		if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
		if ($this->stopRequested) return;

		// 4. Wait for I/O. A watcher whose stream has been closed is
		// cancelled rather than handed to the poller.
		foreach (array('readers', 'writers') as $kind) {
			foreach ($this->$kind as $id => $e) {
				if (!is_resource($e[0])) $this->cancel($id);
			}
		}
		$wait = ($timeout === null) ? self::MAX_WAIT : max(0.0, (float)$timeout);
		if ($nextTimer !== null) $wait = min($wait, max(0.0, $nextTimer));

		if (empty($this->readers) && empty($this->writers)) {
			if ($wait > 0) usleep((int)($wait * 1000000));
			return;
		}

		try {
			$ready = $this->poll->wait(timeoutSeconds: $wait);
		} catch (\Throwable $e) {
			return; // e.g. a stream closed since the check above; next tick prunes it
		}
		foreach ($ready as $watcher) {
			$data = $watcher->getData();
			$id = $data['id'] ?? null;
			if ($id === null || !empty($this->disabled[$id])) continue;
			$kind = ($data['type'] ?? '') === 'write' ? 'writers' : 'readers';
			if (!isset($this->{$kind}[$id])) continue;
			$entry = $this->{$kind}[$id];
			// One connection must not be able to kill the server: drop the
			// watcher that threw and keep serving everybody else.
			try {
				$entry[1]($entry[0]);
			} catch (\Throwable $e) {
				$this->cancel($id);
				self::$lastError = $e;
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
