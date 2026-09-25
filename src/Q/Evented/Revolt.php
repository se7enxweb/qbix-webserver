<?php
/**
 * @module Q
 */

use Revolt\EventLoop;

/**
 * Event loop driver backed by Revolt (amphp's event loop).
 * Install via: composer require revolt/event-loop
 *
 * @class Q_Evented_Revolt
 * @extends Q_Evented_Driver
 */
class Q_Evented_Revolt extends Q_Evented_Driver
{
	protected $running = false;

	function onReadable($stream, callable $cb)
	{
		return EventLoop::onReadable($stream, function ($id, $s) use ($cb) {
			self::guard(function () use ($cb, $s) { $cb($s); }, 'stream');
		});
	}

	function onWritable($stream, callable $cb)
	{
		return EventLoop::onWritable($stream, function ($id, $s) use ($cb) {
			self::guard(function () use ($cb, $s) { $cb($s); }, 'stream');
		});
	}

	function delay($seconds, callable $cb)
	{
		return EventLoop::delay($seconds, function () use ($cb) {
			self::guard($cb, 'timer');
		});
	}

	function repeat($seconds, callable $cb)
	{
		return EventLoop::repeat($seconds, function () use ($cb) {
			self::guard($cb, 'timer');
		});
	}

	function defer(callable $cb)
	{
		return EventLoop::defer(function () use ($cb) {
			self::guard($cb, 'timer');
		});
	}

	function onSignal($signal, callable $cb)
	{
		return EventLoop::onSignal($signal, function ($id, $s) use ($cb) {
			self::guard(function () use ($cb, $s) { $cb($s); }, 'stream');
		});
	}

	function cancel($id)  { EventLoop::cancel($id); }
	function disable($id) { EventLoop::disable($id); }
	function enable($id)  { EventLoop::enable($id); }

	function run()
	{
		$this->running = true;
		EventLoop::run();
		$this->running = false;
	}

	/**
	 * Revolt has no single-pass call, so run it until a timer set for the
	 * timeout stops it. Plain EventLoop::run() returns only when no watcher
	 * is left, which with any listening socket is never.
	 */
	function tick($timeout = 0)
	{
		$driver = EventLoop::getDriver();
		$id = EventLoop::delay($timeout ? (float)$timeout : 0.0, function () use ($driver) { $driver->stop(); });
		EventLoop::unreference($id);
		$driver->run();
		EventLoop::cancel($id);
	}

	/** Ends run() (and a tick) after the current callbacks. */
	function stop()
	{
		$this->running = false;
		EventLoop::getDriver()->stop();
	}

	function running()
	{
		return $this->running;
	}
}
