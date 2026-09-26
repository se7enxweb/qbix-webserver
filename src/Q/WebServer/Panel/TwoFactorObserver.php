<?php
/**
 * @module Q
 */

/**
 * Keeps a session that has given the right password but not yet its second
 * factor out of the panel. Such a session is marked twoFactorPending by
 * Q_WebServer_Panel_Auth::login() when Q.panel.twofactor is on and a secret is
 * enrolled; every session.request from it is vetoed except presenting the code
 * (auth/2fa) and signing out (auth/logout), the same shape the default-key
 * observer uses for must-change. Once auth/2fa accepts a code the mark is
 * cleared and this observer lets the session through.
 *
 * @class Q_WebServer_Panel_TwoFactorObserver
 */
class Q_WebServer_Panel_TwoFactorObserver implements Q_WebServer_Panel_Observer
{
	/** The routes a session still waiting on its second factor may use. */
	private static $allowed = array('auth/2fa', 'auth/logout');

	function update($event, array $context)
	{
		if ($event !== 'session.request') return null;
		$token = (string) ($context['token'] ?? '');
		if ($token === '' or !Q_WebServer_Panel_Auth::twoFactorPending($token)) return null;
		if (in_array((string) ($context['route'] ?? ''), self::$allowed, true)) return null;
		return array('status' => 403, 'body' => array(
			'error' => 'enter your two-factor code first', 'twoFactorRequired' => true));
	}
}
