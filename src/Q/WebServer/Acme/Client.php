<?php
/**
 * @module Q
 */

/**
 * An ACME client (RFC 8555) for any ACME CA: Let's Encrypt, its staging
 * server, ZeroSSL, Google Trust Services, Buypass, step-ca, Pebble.
 *
 * Every request after the directory is a signed POST (fetching an order, an
 * authorization or a certificate is a POST-as-GET, as the RFC requires and as
 * Let's Encrypt enforces). The account key is ECDSA P-256 (ES256) for new
 * accounts; an existing RSA account key (RS256) keeps working. A stale nonce
 * is retried with a fresh one, Retry-After is honoured, and the CA's error
 * documents are reported as they come.
 *
 * Challenges are answered by callbacks, so this class does no I/O of its own
 * beyond HTTPS to the CA: see Q_WebServer_Acme for the files and hooks.
 *
 * @class Q_WebServer_Acme_Client
 */
class Q_WebServer_Acme_Client
{
	/** @var string the directory URL */
	private $directoryUrl;
	/** @var array the directory document */
	private $directory = null;
	/** @var resource|OpenSSLAsymmetricKey the account key */
	private $accountKey;
	/** @var string|null the account URL (kid), once known */
	private $kid = null;
	/** @var string|null */
	private $nonce = null;
	/** @var array options: caBundle, verify, timeout, userAgent */
	private $options;

	/**
	 * @param {string} $directoryUrl
	 * @param {string} $accountKeyPem
	 * @param {array} $options caBundle (a CA file for the ACME server's TLS),
	 *   verify (default true), timeout (seconds, 30)
	 */
	function __construct($directoryUrl, $accountKeyPem, array $options = array())
	{
		$this->directoryUrl = $directoryUrl;
		$this->accountKey = openssl_pkey_get_private($accountKeyPem);
		if (!$this->accountKey) throw new Exception('the ACME account key could not be read');
		$this->options = $options + array('verify' => true, 'timeout' => 30, 'caBundle' => null,
			'userAgent' => 'exponential-velocity-acme/1 (+RFC 8555)');
	}

	/**
	 * A new account key, ECDSA P-256, as PEM.
	 * @method newAccountKey
	 * @static
	 * @return {string}
	 */
	static function newAccountKey()
	{
		$k = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048));
		if (!$k or !openssl_pkey_export($k, $pem)) throw new Exception('could not make an ACME account key');
		return $pem;
	}

	/** @return {array} the directory document (meta.termsOfService etc.) */
	function directory()
	{
		if ($this->directory === null) {
			$r = $this->http('GET', $this->directoryUrl);
			if ($r['status'] !== 200 or !is_array($r['json'])) throw new Exception("the ACME directory at {$this->directoryUrl} answered {$r['status']}");
			$this->directory = $r['json'];
		}
		return $this->directory;
	}

	/**
	 * Register the account, or find it again (the same key finds the same
	 * account). External account binding for CAs that require it.
	 * @method account
	 * @param {string|null} $email
	 * @param {array|null} $eab array('kid' => ..., 'hmacKey' => base64url)
	 * @return {string} the account URL
	 */
	function account($email = null, array $eab = null)
	{
		$dir = $this->directory();
		$payload = array('termsOfServiceAgreed' => true);
		if ($email) $payload['contact'] = array('mailto:' . $email);
		if ($eab and !empty($eab['kid']) and !empty($eab['hmacKey'])) {
			$payload['externalAccountBinding'] = $this->eab($dir['newAccount'], $eab['kid'], $eab['hmacKey']);
		}
		$r = $this->post($dir['newAccount'], $payload, true);
		if (!in_array($r['status'], array(200, 201), true) or empty($r['headers']['location'])) {
			throw new Exception('the account was refused: ' . self::problem($r));
		}
		return $this->kid = $r['headers']['location'];
	}

	/**
	 * Issue a certificate.
	 * @method issue
	 * @param {array} $identifiers host names (a leading "*." for a wildcard) and IP addresses
	 * @param {string} $certKeyPem the certificate's own key
	 * @param {callable} $respond function(array $challenge, string $keyAuth, string $identifier, string $type): bool
	 *   -- make the challenge answerable; $type is "http-01" or "dns-01"
	 * @param {callable} $cleanup function(array $challenge, string $keyAuth, string $identifier, string $type)
	 * @param {array} $prefer challenge types in order of preference
	 * @param {integer} $timeout seconds to wait for validation and issuance
	 * @return {string} the full chain, PEM
	 */
	function issue(array $identifiers, $certKeyPem, callable $respond, callable $cleanup,
		array $prefer = array('http-01', 'dns-01'), $timeout = 300)
	{
		if ($this->kid === null) throw new Exception('account() first');
		$dir = $this->directory();
		$ids = array();
		foreach ($identifiers as $i) {
			$ids[] = array('type' => filter_var($i, FILTER_VALIDATE_IP) ? 'ip' : 'dns', 'value' => $i);
		}
		$r = $this->post($dir['newOrder'], array('identifiers' => $ids));
		if ($r['status'] !== 201) throw new Exception('the order was refused: ' . self::problem($r));
		$orderUrl = $r['headers']['location'];
		$order = $r['json'];
		$deadline = time() + $timeout;

		$answered = array();
		try {
			foreach ($order['authorizations'] as $authzUrl) {
				$authz = $this->postAsGet($authzUrl)['json'];
				if (($authz['status'] ?? '') === 'valid') continue;
				$identifier = ($authz['wildcard'] ?? false) ? '*.' . $authz['identifier']['value'] : $authz['identifier']['value'];
				$chosen = null;
				foreach ($prefer as $type) {
					if ($type === 'http-01' and strpos($identifier, '*.') === 0) continue;
					foreach ($authz['challenges'] as $ch) {
						if ($ch['type'] === $type) { $chosen = $ch; break 2; }
					}
				}
				if (!$chosen) throw new Exception("no usable challenge for $identifier (offered: "
					. implode(', ', array_column($authz['challenges'], 'type')) . ')');
				$keyAuth = $chosen['token'] . '.' . $this->thumbprint();
				if (!$respond($chosen, $keyAuth, $identifier, $chosen['type'])) {
					throw new Exception("could not set up the {$chosen['type']} challenge for $identifier");
				}
				$answered[] = array($chosen, $keyAuth, $identifier);
				$this->post($chosen['url'], new stdClass());
				$this->waitFor($authzUrl, array('valid'), array('invalid', 'deactivated', 'expired', 'revoked'), $deadline, $identifier);
			}

			$csr = self::csr($identifiers, $certKeyPem);
			$r = $this->post($order['finalize'], array('csr' => self::b64($csr)));
			if (!in_array($r['status'], array(200, 201), true)) throw new Exception('finalizing was refused: ' . self::problem($r));
			$order = $this->waitFor($orderUrl, array('valid'), array('invalid'), $deadline, 'the order');
			$r = $this->postAsGet($order['certificate'], 'application/pem-certificate-chain');
			if ($r['status'] !== 200 or strpos($r['body'], '-----BEGIN CERTIFICATE-----') === false) {
				throw new Exception('the certificate could not be downloaded: ' . self::problem($r));
			}
			return $r['body'];
		} finally {
			foreach ($answered as $a) {
				try { $cleanup($a[0], $a[1], $a[2], $a[0]['type']); } catch (\Throwable $e) {}
			}
		}
	}

	/** The DNS TXT value for a dns-01 key authorization. */
	static function dnsValue($keyAuth)
	{
		return self::b64(hash('sha256', $keyAuth, true));
	}

	// ── Internals ────────────────────────────────────────────────────────

	private function waitFor($url, array $done, array $failed, $deadline, $what)
	{
		while (true) {
			$r = $this->postAsGet($url);
			$status = $r['json']['status'] ?? '';
			if (in_array($status, $done, true)) return $r['json'];
			if (in_array($status, $failed, true)) {
				$detail = '';
				foreach ((array) ($r['json']['challenges'] ?? array()) as $ch) {
					if (!empty($ch['error'])) $detail = ': ' . ($ch['error']['detail'] ?? $ch['error']['type'] ?? '');
				}
				if (!$detail and !empty($r['json']['error'])) $detail = ': ' . ($r['json']['error']['detail'] ?? '');
				throw new Exception("$what is $status$detail");
			}
			if (time() >= $deadline) throw new Exception("timed out waiting for $what (still $status)");
			$wait = isset($r['headers']['retry-after']) ? max(1, min(30, (int) $r['headers']['retry-after'])) : 2;
			sleep($wait);
		}
	}

	private function postAsGet($url, $accept = null)
	{
		return $this->post($url, null, false, $accept);
	}

	/**
	 * A signed POST. $payload null is POST-as-GET (an empty payload).
	 */
	private function post($url, $payload, $withJwk = false, $accept = null)
	{
		for ($attempt = 0; $attempt < 3; $attempt++) {
			if ($this->nonce === null) $this->nonce = $this->freshNonce();
			$protected = array('alg' => $this->alg(), 'nonce' => $this->nonce, 'url' => $url);
			if ($withJwk or $this->kid === null) $protected['jwk'] = $this->jwk();
			else $protected['kid'] = $this->kid;
			$p64 = self::b64(json_encode($protected, JSON_UNESCAPED_SLASHES));
			$b64 = $payload === null ? '' : self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
			$body = json_encode(array('protected' => $p64, 'payload' => $b64, 'signature' => self::b64($this->sign("$p64.$b64"))));
			$this->nonce = null;
			$headers = array('Content-Type: application/jose+json');
			if ($accept) $headers[] = 'Accept: ' . $accept;
			$r = $this->http('POST', $url, $body, $headers);
			if (!empty($r['headers']['replay-nonce'])) $this->nonce = $r['headers']['replay-nonce'];
			if ($r['status'] === 400 and ($r['json']['type'] ?? '') === 'urn:ietf:params:acme:error:badNonce') continue;
			return $r;
		}
		return $r;
	}

	private function freshNonce()
	{
		$r = $this->http('HEAD', $this->directory()['newNonce']);
		if (empty($r['headers']['replay-nonce'])) throw new Exception('the ACME server gave no nonce');
		return $r['headers']['replay-nonce'];
	}

	private function eab($url, $kid, $hmacKey)
	{
		$p64 = self::b64(json_encode(array('alg' => 'HS256', 'kid' => $kid, 'url' => $url), JSON_UNESCAPED_SLASHES));
		$b64 = self::b64(json_encode($this->jwk(), JSON_UNESCAPED_SLASHES));
		$sig = hash_hmac('sha256', "$p64.$b64", self::unb64($hmacKey), true);
		return array('protected' => $p64, 'payload' => $b64, 'signature' => self::b64($sig));
	}

	private function details()
	{
		return openssl_pkey_get_details($this->accountKey);
	}

	private function alg()
	{
		return $this->details()['type'] === OPENSSL_KEYTYPE_EC ? 'ES256' : 'RS256';
	}

	/** The account key's public JWK, members in the order the thumbprint needs. */
	private function jwk()
	{
		$d = $this->details();
		if ($d['type'] === OPENSSL_KEYTYPE_EC) {
			return array('crv' => 'P-256', 'kty' => 'EC',
				'x' => self::b64(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)),
				'y' => self::b64(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)));
		}
		return array('e' => self::b64($d['rsa']['e']), 'kty' => 'RSA', 'n' => self::b64($d['rsa']['n']));
	}

	private function thumbprint()
	{
		return self::b64(hash('sha256', json_encode($this->jwk(), JSON_UNESCAPED_SLASHES), true));
	}

	private function sign($data)
	{
		if (!openssl_sign($data, $sig, $this->accountKey, OPENSSL_ALGO_SHA256)) throw new Exception('signing failed');
		return $this->alg() === 'ES256' ? self::derToRaw($sig, 32) : $sig;
	}

	/** An ECDSA signature from DER (as openssl makes it) to r||s (as JWS wants it). */
	private static function derToRaw($der, $size)
	{
		$pos = 2;
		if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
		$out = '';
		for ($i = 0; $i < 2; $i++) {
			$pos++; // INTEGER tag
			$len = ord($der[$pos++]);
			$int = substr($der, $pos, $len);
			$pos += $len;
			$out .= str_pad(ltrim($int, "\0"), $size, "\0", STR_PAD_LEFT);
		}
		return $out;
	}

	/** A certificate request for these identifiers, DER. */
	private static function csr(array $identifiers, $keyPem)
	{
		$names = array_values(array_filter($identifiers, function ($i) { return !filter_var($i, FILTER_VALIDATE_IP); }));
		$cn = $names ? $names[0] : $identifiers[0];
		$conf = Q_WebServer_Certificate_Provider_Openssl::writeConfig(array_values(array_unique(array_merge(array($cn), $identifiers))));
		try {
			$key = openssl_pkey_get_private($keyPem);
			$dn = strlen($cn) <= 64 ? array('commonName' => $cn) : array();
			$csr = openssl_csr_new($dn, $key, array('config' => $conf, 'digest_alg' => 'sha256', 'req_extensions' => 'qbix_ext'));
			if (!$csr or !openssl_csr_export($csr, $pem)) throw new Exception('could not make the certificate request');
		} finally {
			@unlink($conf);
		}
		return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
	}

	/** One HTTP exchange: status, lower-cased headers, body, and body as JSON when it is. */
	private function http($method, $url, $body = null, array $headers = array())
	{
		$headers[] = 'User-Agent: ' . $this->options['userAgent'];
		if (function_exists('curl_init')) {
			$h = curl_init($url);
			$respHeaders = array();
			curl_setopt_array($h, array(
				CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD',
				CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
				CURLOPT_TIMEOUT => (int) $this->options['timeout'], CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_SSL_VERIFYPEER => (bool) $this->options['verify'],
				CURLOPT_SSL_VERIFYHOST => $this->options['verify'] ? 2 : 0,
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
				CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
					$p = strpos($line, ':');
					if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
					return strlen($line);
				},
			));
			if ($this->options['caBundle']) curl_setopt($h, CURLOPT_CAINFO, $this->options['caBundle']);
			if ($body !== null) curl_setopt($h, CURLOPT_POSTFIELDS, $body);
			$resp = curl_exec($h);
			if ($resp === false) { $e = curl_error($h); curl_close($h); throw new Exception("$method $url: $e"); }
			$status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
			curl_close($h);
		} else {
			$ssl = array('verify_peer' => (bool) $this->options['verify'], 'verify_peer_name' => (bool) $this->options['verify']);
			if ($this->options['caBundle']) $ssl['cafile'] = $this->options['caBundle'];
			$ctx = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers),
				'content' => (string) $body, 'ignore_errors' => true, 'timeout' => (int) $this->options['timeout']), 'ssl' => $ssl));
			$resp = @file_get_contents($url, false, $ctx);
			if ($resp === false and empty($http_response_header)) throw new Exception("$method $url: no response");
			$status = 0;
			$respHeaders = array();
			foreach ((array) $http_response_header as $line) {
				if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) { $status = (int) $m[1]; $respHeaders = array(); continue; }
				$p = strpos($line, ':');
				if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
			}
		}
		$json = json_decode((string) $resp, true);
		return array('status' => $status, 'headers' => $respHeaders, 'body' => (string) $resp, 'json' => is_array($json) ? $json : null);
	}

	/** The CA's error, readable. */
	static function problem(array $r)
	{
		$j = $r['json'] ?? null;
		if (is_array($j) and (isset($j['detail']) or isset($j['type']))) {
			$sub = '';
			foreach ((array) ($j['subproblems'] ?? array()) as $s) $sub .= '; ' . ($s['detail'] ?? '');
			return trim(($j['detail'] ?? '') . ' (' . ($j['type'] ?? '') . ')' . $sub);
		}
		return 'HTTP ' . $r['status'];
	}

	static function b64($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	static function unb64($data)
	{
		return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
	}
}
