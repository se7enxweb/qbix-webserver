<?php
/**
 * @module Q
 */

/**
 * Time-based one-time passwords for the control panel's second factor, with
 * no dependency beyond PHP's own hash_hmac() and random_bytes().
 *
 * The scheme is the one every authenticator app speaks: HOTP (RFC 4226) with
 * the counter set from the clock (TOTP, RFC 6238) -- HMAC-SHA1, six digits, a
 * thirty-second step. A code from the step before or after the current one is
 * accepted too, so a phone whose clock is up to half a step out still signs
 * in; two steps out is refused (the window is +-1, configurable).
 *
 * The secret is 160 bits of random, kept and shown to the enrolling admin in
 * base32 (RFC 4648), which is what an authenticator's manual-entry field and
 * its otpauth:// QR both take. Nothing here reads or writes any store: the
 * caller holds the secret (see Q_WebServer_Panel_Store) and passes it in.
 *
 * The digit comparison is constant-time (hash_equals), so a wrong code leaks
 * nothing through how long it took to reject. Replay is the caller's to
 * enforce: verify() returns the step a code matched, and the store keeps the
 * last step it accepted, so a code already used cannot be used again.
 *
 * @class Q_WebServer_Panel_Totp
 * @static
 */
class Q_WebServer_Panel_Totp
{
	const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	const PERIOD = 30;
	const DIGITS = 6;
	const ALGORITHM = 'sha1';
	const SECRET_BYTES = 20; // 160 bits, as RFC 4226 recommends

	// ── base32 (RFC 4648) ────────────────────────────────────────────────

	/**
	 * Encode raw bytes as base32, upper-case, no padding (an authenticator's
	 * manual-entry field ignores the '=' padding, so it is left off).
	 * @method base32Encode
	 * @static
	 * @param {string} $bytes
	 * @return {string}
	 */
	static function base32Encode($bytes)
	{
		$bytes = (string) $bytes;
		if ($bytes === '') return '';
		$out = '';
		$buffer = 0;
		$bits = 0;
		$len = strlen($bytes);
		for ($i = 0; $i < $len; ++$i) {
			$buffer = ($buffer << 8) | ord($bytes[$i]);
			$bits += 8;
			while ($bits >= 5) {
				$bits -= 5;
				$out .= self::ALPHABET[($buffer >> $bits) & 0x1f];
			}
		}
		if ($bits > 0) {
			$out .= self::ALPHABET[($buffer << (5 - $bits)) & 0x1f];
		}
		return $out;
	}

	/**
	 * Decode a base32 string to raw bytes. Case, spaces and '=' padding are
	 * tolerated (a user typing a secret by hand adds all three); a character
	 * outside the alphabet makes it return '' rather than guess.
	 * @method base32Decode
	 * @static
	 * @param {string} $str
	 * @return {string} the bytes, or '' when the input is not valid base32
	 */
	static function base32Decode($str)
	{
		$str = strtoupper(preg_replace('/[\s=]+/', '', (string) $str));
		if ($str === '') return '';
		$map = array();
		for ($i = 0, $n = strlen(self::ALPHABET); $i < $n; ++$i) $map[self::ALPHABET[$i]] = $i;
		$buffer = 0;
		$bits = 0;
		$out = '';
		$len = strlen($str);
		for ($i = 0; $i < $len; ++$i) {
			$c = $str[$i];
			if (!isset($map[$c])) return '';
			$buffer = ($buffer << 5) | $map[$c];
			$bits += 5;
			if ($bits >= 8) {
				$bits -= 8;
				$out .= chr(($buffer >> $bits) & 0xff);
			}
		}
		return $out;
	}

	// ── HOTP / TOTP ──────────────────────────────────────────────────────

	/**
	 * HOTP (RFC 4226): the one-time code for a counter, from a raw-byte key.
	 * @method hotp
	 * @static
	 * @param {string} $key raw secret bytes
	 * @param {integer} $counter
	 * @param {integer} [$digits=6]
	 * @param {string} [$algorithm='sha1']
	 * @return {string} the code, zero-padded to $digits
	 */
	static function hotp($key, $counter, $digits = self::DIGITS, $algorithm = self::ALGORITHM)
	{
		// The counter as eight bytes, most significant first. pack('J') needs
		// a 64-bit build; this works whatever the word size and never signs.
		$bin = '';
		$c = $counter;
		for ($i = 7; $i >= 0; --$i) {
			$bin = chr($c & 0xff) . $bin;
			$c = (int) floor($c / 256);
		}
		$hash = hash_hmac($algorithm, $bin, (string) $key, true);
		$offset = ord($hash[strlen($hash) - 1]) & 0x0f;
		$binary = ((ord($hash[$offset]) & 0x7f) << 24)
			| ((ord($hash[$offset + 1]) & 0xff) << 16)
			| ((ord($hash[$offset + 2]) & 0xff) << 8)
			| (ord($hash[$offset + 3]) & 0xff);
		$otp = $binary % (10 ** $digits);
		return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
	}

	/** The step number for a moment: floor(time / period). */
	static function stepFor($time = null, $period = self::PERIOD)
	{
		$time = $time === null ? time() : (int) $time;
		return (int) floor($time / max(1, (int) $period));
	}

	/**
	 * The TOTP code for a moment (RFC 6238).
	 * @method totp
	 * @static
	 * @param {string} $key raw secret bytes
	 * @param {integer|null} [$time=null] a Unix time, or now
	 * @param {integer} [$period=30]
	 * @param {integer} [$digits=6]
	 * @param {string} [$algorithm='sha1']
	 * @return {string}
	 */
	static function totp($key, $time = null, $period = self::PERIOD, $digits = self::DIGITS, $algorithm = self::ALGORITHM)
	{
		return self::hotp($key, self::stepFor($time, $period), $digits, $algorithm);
	}

	/**
	 * Whether $code is right for the secret now, allowing a clock skew of
	 * +-$window steps, and, if so, which step it matched -- so the caller can
	 * refuse a step it has already accepted (replay). Every candidate step is
	 * compared, with hash_equals, whether or not an earlier one matched, so
	 * the time taken does not reveal the code or which step it was.
	 *
	 * @method verify
	 * @static
	 * @param {string} $secretBytes raw secret bytes (base32Decode of the stored secret)
	 * @param {string} $code the digits the user gave
	 * @param {integer|null} [$time=null]
	 * @param {integer} [$window=1] steps of skew accepted on each side
	 * @param {integer} [$period=30]
	 * @param {integer} [$digits=6]
	 * @param {string} [$algorithm='sha1']
	 * @return {integer|false} the step it matched, or false
	 */
	static function verify($secretBytes, $code, $time = null, $window = 1, $period = self::PERIOD, $digits = self::DIGITS, $algorithm = self::ALGORITHM)
	{
		$code = (string) $code;
		if ($secretBytes === '' or !preg_match('/^\d{' . (int) $digits . '}$/', $code)) return false;
		$now = self::stepFor($time, $period);
		$window = max(0, (int) $window);
		$matched = false;
		for ($i = -$window; $i <= $window; ++$i) {
			$step = $now + $i;
			$candidate = self::hotp($secretBytes, $step, $digits, $algorithm);
			// hash_equals over every step, and no early return, so a match
			// late in the window costs the same as one early in it.
			if (self::constantTimeEquals($candidate, $code) and $matched === false) {
				$matched = $step;
			}
		}
		return $matched;
	}

	/** A length-safe, constant-time string compare. */
	static function constantTimeEquals($a, $b)
	{
		return hash_equals((string) $a, (string) $b);
	}

	// ── Secrets and provisioning ─────────────────────────────────────────

	/**
	 * A fresh 160-bit secret, base32-encoded, ready to store and to show the
	 * enrolling admin once.
	 * @method generateSecret
	 * @static
	 * @param {integer} [$bytes=20]
	 * @return {string} base32
	 */
	static function generateSecret($bytes = self::SECRET_BYTES)
	{
		return self::base32Encode(random_bytes(max(10, (int) $bytes)));
	}

	/**
	 * The otpauth:// URI an authenticator reads from a QR or takes typed in.
	 *   otpauth://totp/<issuer>:<label>?secret=..&issuer=..&algorithm=SHA1&digits=6&period=30
	 * @method provisioningUri
	 * @static
	 * @param {string} $secretBase32 the stored secret, base32
	 * @param {string} $label who the account is (an email, a host, "panel")
	 * @param {string} $issuer the product or site name
	 * @param {integer} [$digits=6]
	 * @param {integer} [$period=30]
	 * @param {string} [$algorithm='SHA1']
	 * @return {string}
	 */
	static function provisioningUri($secretBase32, $label, $issuer, $digits = self::DIGITS, $period = self::PERIOD, $algorithm = 'SHA1')
	{
		$issuer = (string) $issuer;
		$label = (string) $label;
		// The path is issuer:label, each percent-encoded; the query repeats
		// the issuer, which is what makes an authenticator group the account.
		$path = rawurlencode($issuer) . ':' . rawurlencode($label);
		$query = http_build_query(array(
			'secret' => preg_replace('/[\s=]+/', '', strtoupper($secretBase32)),
			'issuer' => $issuer,
			'algorithm' => strtoupper($algorithm),
			'digits' => (int) $digits,
			'period' => (int) $period,
		), '', '&', PHP_QUERY_RFC3986);
		return 'otpauth://totp/' . $path . '?' . $query;
	}

	// ── Recovery codes ───────────────────────────────────────────────────

	/**
	 * A set of one-time recovery codes, in plaintext, to show the admin once,
	 * and the SHA-256 hash of each, which is all the store keeps. A code is
	 * ten characters of the same base32 alphabet, shown in two groups of
	 * five; a lost authenticator is then still a way in, and a stolen store
	 * file is not (only the hashes are in it).
	 * @method generateRecoveryCodes
	 * @static
	 * @param {integer} [$count=10]
	 * @return {array} array('codes' => [plaintext...], 'hashes' => [hash...])
	 */
	static function generateRecoveryCodes($count = 10)
	{
		$codes = array();
		$hashes = array();
		for ($i = 0, $n = max(1, (int) $count); $i < $n; ++$i) {
			$raw = '';
			for ($j = 0; $j < 10; ++$j) $raw .= self::ALPHABET[random_int(0, 31)];
			$display = substr($raw, 0, 5) . '-' . substr($raw, 5);
			$codes[] = $display;
			$hashes[] = self::hashRecoveryCode($display);
		}
		return array('codes' => $codes, 'hashes' => $hashes);
	}

	/**
	 * The stored hash of a recovery code. The code is upper-cased and its
	 * dashes and spaces removed first, so how the user types it back does not
	 * matter. SHA-256 is enough here: a recovery code is high-entropy random,
	 * not a human-chosen password, so it is not open to a dictionary.
	 * @method hashRecoveryCode
	 * @static
	 * @param {string} $code
	 * @return {string} hex sha256
	 */
	static function hashRecoveryCode($code)
	{
		$norm = strtoupper(preg_replace('/[\s-]+/', '', (string) $code));
		return hash('sha256', $norm);
	}
}
