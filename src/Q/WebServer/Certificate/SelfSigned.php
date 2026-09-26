<?php
/**
 * @module Q
 */

/**
 * Keeps a self-signed certificate that is always usable, with nobody looking
 * after it:
 *
 *   - made when there is none, or what is there does not parse, has expired,
 *     or does not match its key (a failed signing once left such a pair
 *     behind, and it was kept for good);
 *   - renewed RENEW_DAYS before it expires, and when a host name or address
 *     it should cover is missing from it -- then covering the names it had as
 *     well, so another server sharing the pair (a second site, a test
 *     instance on another address) keeps every name it relies on. Names it
 *     covers but no longer needs are not a reason to replace it: two servers
 *     wanting different lists would otherwise replace it on every start;
 *   - never replaced by nothing: when no provider can make a new one, a
 *     still-valid old one stays in use.
 *
 * The hosts come from Q.web.https.selfSigned.hosts when set; otherwise the
 * configured domain, the virtual host names and their aliases, this machine's
 * host name, and localhost with its loopback addresses.
 *
 * @class Q_WebServer_Certificate_SelfSigned
 * @static
 */
class Q_WebServer_Certificate_SelfSigned
{
	const RENEW_DAYS = 30;

	/**
	 * The host names and addresses the certificate should cover; the first is
	 * its common name.
	 * @method hosts
	 * @static
	 * @param {string|null} $bindHost the address the server listens on
	 * @return {array}
	 */
	static function hosts($bindHost = null)
	{
		$explicit = Q_Config::get('Q', 'web', 'https', 'selfSigned', 'hosts', null);
		if ($explicit) return array_values(array_unique(array_map('strtolower', (array) $explicit)));
		$hosts = array();
		$domain = Q_Config::get('Q', 'web', 'https', 'domain', '');
		if ($domain) $hosts[] = $domain;
		foreach ((array) Q_Config::get('Q', 'webserver', 'domains', array()) as $name => $conf) {
			if (is_string($name) and $name !== '') $hosts[] = $name;
			foreach ((array) (is_array($conf) && isset($conf['aliases']) ? $conf['aliases'] : array()) as $a) $hosts[] = $a;
		}
		$me = function_exists('gethostname') ? gethostname() : '';
		if ($me) $hosts[] = $me;
		$hosts[] = 'localhost';
		$hosts[] = '127.0.0.1';
		$hosts[] = '::1';
		if ($bindHost and filter_var($bindHost, FILTER_VALIDATE_IP)
			and !in_array($bindHost, array('0.0.0.0', '::'), true)) {
			$hosts[] = $bindHost;
		}
		$out = array();
		foreach ($hosts as $h) {
			$h = strtolower(trim((string) $h));
			if ($h !== '' and strpos($h, '*') === false and !in_array($h, $out, true)) $out[] = $h;
		}
		return $out;
	}

	/**
	 * Why a stored certificate should be replaced, or null when it is fine.
	 * @method renewalReason
	 * @static
	 * @param {Q_WebServer_Certificate|null} $c
	 * @param {array} $hosts
	 * @return {string|null}
	 */
	static function renewalReason($c, array $hosts)
	{
		if (!$c) return 'there is none';
		if (!$c->isUsable()) return 'the stored one is unusable (expired, unreadable or not matching its key)';
		if ($c->daysLeft() < self::RENEW_DAYS) return 'it expires in ' . $c->daysLeft() . ' days';
		$missing = self::missingHosts($c, $hosts);
		if ($missing) {
			return 'it does not cover ' . implode(' ', $missing);
		}
		return null;
	}

	/**
	 * The asked-for hosts a certificate does not cover.
	 * @method missingHosts
	 * @static
	 * @param {Q_WebServer_Certificate} $c
	 * @param {array} $hosts
	 * @return {array}
	 */
	static function missingHosts($c, array $hosts)
	{
		$have = $c->hosts();
		$out = array();
		foreach ($hosts as $h) {
			$h = strtolower((string) $h);
			if ($h !== '' and !in_array($h, $have, true) and !in_array($h, $out, true)) $out[] = $h;
		}
		return $out;
	}

	/**
	 * Make sure a usable certificate is in the store, making or renewing one
	 * as needed.
	 * @method ensure
	 * @static
	 * @param {array|null} $hosts null for hosts()
	 * @param {boolean} $force renew even when nothing calls for it
	 * @param {Q_WebServer_Certificate_Store|null} $store
	 * @return {array|null} array(cert file, key file, changed), or null when none is usable
	 */
	static function ensure(array $hosts = null, $force = false, Q_WebServer_Certificate_Store $store = null)
	{
		$hosts = $hosts ?: self::hosts();
		$store = $store ?: new Q_WebServer_Certificate_Store();
		return $store->locked(function () use ($hosts, $force, $store) {
			$current = $store->load();
			$reason = $force ? 'renewal was asked for' : self::renewalReason($current, $hosts);
			if ($reason === null) {
				Q_WebServer_Certificate_Events::emit('loaded', array('cert' => $store->certFile(),
					'key' => $store->keyFile(), 'certificate' => $current));
				return array($store->certFile(), $store->keyFile(), false);
			}
			if ($current) Q_WebServer_Certificate_Events::emit('renewing', array('reason' => $reason));
			// A usable one being replaced keeps covering what it covered: a
			// server using it -- this directory may be shared -- still needs
			// those names. Ours first, so the first host stays our own.
			if (!$force and $current and $current->isUsable()) {
				$hosts = array_values(array_unique(array_merge($hosts, $current->hostsInOrder())));
			}
			$new = Q_WebServer_Certificate_Factory::create($hosts);
			if ($new and $store->save($new)) {
				return array($store->certFile(), $store->keyFile(), true);
			}
			if ($new) Q_WebServer_Certificate_Events::emit('error', array('reason' => 'could not write ' . $store->dir()));
			// Keep what works rather than leave nothing.
			if ($current and $current->isUsable()) {
				Q_WebServer_Certificate_Events::emit('error', array('reason' => 'keeping the current certificate for now'));
				return array($store->certFile(), $store->keyFile(), false);
			}
			return null;
		});
	}
}
