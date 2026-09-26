<?php
/**
 * @module Q
 */

/**
 * The subject the control panel's observers attach to -- the same shape as
 * Q_WebServer_Certificate_Events. The panel runs in the server's parent
 * process, so one hub serves every request. The observers the panel always
 * has (the default key, lockout, the log) are attached on first use; a
 * test can reset() and attach its own.
 *
 * @class Q_WebServer_Panel_Events
 * @static
 */
class Q_WebServer_Panel_Events
{
	/** @var Q_WebServer_Panel_Observer[] */
	private static $observers = array();

	/** @var boolean whether the built-in observers are attached */
	private static $ready = false;

	/** Attach an observer; attaching the same one twice has no effect. */
	static function attach(Q_WebServer_Panel_Observer $o)
	{
		self::$observers[spl_object_id($o)] = $o;
	}

	static function detach(Q_WebServer_Panel_Observer $o)
	{
		unset(self::$observers[spl_object_id($o)]);
	}

	/**
	 * Detach every observer. The built-in ones come back on the next notify(),
	 * unless $builtins is false (a test attaching exactly the ones it wants).
	 */
	static function reset($builtins = true)
	{
		self::$observers = array();
		self::$ready = !$builtins;
	}

	/**
	 * Tell every observer; the first veto wins and is returned. An observer
	 * that throws is skipped, and logged, rather than letting the request
	 * through unchecked or failing it outright.
	 * @method notify
	 * @static
	 * @param {string} $event
	 * @param {array} $context
	 * @return {array|null} a veto response, or null
	 */
	static function notify($event, array $context = array())
	{
		if (!self::$ready) {
			self::$ready = true;
			self::attach(new Q_WebServer_Panel_LogObserver());
			self::attach(new Q_WebServer_Panel_LockoutObserver());
			self::attach(new Q_WebServer_Panel_DefaultPasswordObserver());
			self::attach(new Q_WebServer_Panel_TwoFactorObserver());
		}
		$veto = null;
		foreach (self::$observers as $o) {
			try {
				$r = $o->update($event, $context);
				if ($veto === null and is_array($r)) $veto = $r;
			} catch (\Throwable $e) {
				fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'),
					'  panel: observer ' . get_class($o) . ' failed on ' . $event . ': ' . $e->getMessage() . "\n");
			}
		}
		return $veto;
	}
}
