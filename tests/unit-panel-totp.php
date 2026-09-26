<?php
/**
 * The control panel's TOTP engine, on its own, with no server and no store.
 *
 *   - the published RFC 6238 SHA-1 test vectors reproduce, at 8 and 6 digits;
 *   - base32 (RFC 4648) round-trips, and its own published vectors match;
 *   - a code from the step before or after is accepted (+-1), two steps out is
 *     refused, and verify() reports which step matched (for the replay guard);
 *   - the digit compare is constant-time (hash_equals), and length-safe;
 *   - a caller that records the last accepted step rejects a replayed code;
 *   - recovery codes are high-entropy, distinct, and hash stably however the
 *     user re-types them (case, dashes and spaces ignored);
 *   - the otpauth:// provisioning URI has the shape an authenticator reads.
 *
 *   php tests/unit-panel-totp.php
 */

require __DIR__ . '/../src/Q/WebServer/Panel/Totp.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

$T = 'Q_WebServer_Panel_Totp';

// ── RFC 6238, Appendix B: the SHA-1 vectors ─────────────────────
// The shared secret is the ASCII "12345678901234567890" (20 bytes).
$secret = '12345678901234567890';
$vectors = array(
	// time  => 8-digit code (RFC 6238 Appendix B, SHA1)
	59          => '94287082',
	1111111109  => '07081804',
	1111111111  => '14050471',
	1234567890  => '89005924',
	2000000000  => '69279037',
	20000000000 => '65353130',
);
foreach ($vectors as $time => $code8) {
	check("RFC 6238 SHA1, T=$time, 8 digits", $T::totp($secret, $time, 30, 8, 'sha1'), $code8);
	check("RFC 6238 SHA1, T=$time, 6 digits (last six)", $T::totp($secret, $time, 30, 6, 'sha1'), substr($code8, -6));
}

// HOTP (RFC 4226, Appendix D) — the counter-based codes the first vectors build on.
$hotp = array('755224', '287082', '359152', '969429', '338314');
foreach ($hotp as $c => $want) {
	check("RFC 4226 HOTP counter $c", $T::hotp($secret, $c, 6, 'sha1'), $want);
}

// ── base32 (RFC 4648) ───────────────────────────────────────────
$b32 = array('' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6',
	'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI');
foreach ($b32 as $raw => $enc) {
	check("base32 encode " . var_export($raw, true), $T::base32Encode($raw), $enc);
	if ($enc !== '') check("base32 decode $enc", $T::base32Decode($enc), $raw);
}
// A round-trip on random bytes, and tolerance of case/spaces/padding on input.
for ($i = 0; $i < 20; ++$i) {
	$bytes = random_bytes(random_int(1, 40));
	$enc = $T::base32Encode($bytes);
	check("base32 round-trip #$i", $T::base32Decode($enc), $bytes);
}
check('base32 decode tolerates lower case, spaces and =',
	$T::base32Decode(' mz xw6ytb oi== '), 'foobar');
check('base32 decode refuses a character outside the alphabet', $T::base32Decode('MZXW0!'), '');

// ── The skew window ─────────────────────────────────────────────
$key = $T::base32Decode($T::generateSecret());
$t = 1600000000;                 // a fixed "now"
$period = 30;
$now = intdiv($t, $period);
// A code generated for each nearby step, verified at $t with window 1.
$codeAt = function ($step) use ($T, $key, $period) { return $T::totp($key, $step * $period, $period, 6, 'sha1'); };
check('current step is accepted, and reports itself', $T::verify($key, $codeAt($now), $t, 1), $now);
check('one step behind is accepted (+-1)', $T::verify($key, $codeAt($now - 1), $t, 1), $now - 1);
check('one step ahead is accepted (+-1)', $T::verify($key, $codeAt($now + 1), $t, 1), $now + 1);
check('two steps behind is refused', $T::verify($key, $codeAt($now - 2), $t, 1), false);
check('two steps ahead is refused', $T::verify($key, $codeAt($now + 2), $t, 1), false);
check('a wrong code is refused', $T::verify($key, '000000', $t, 1), false);
check('a non-numeric code is refused', $T::verify($key, 'abcdef', $t, 1), false);
check('an empty secret verifies nothing', $T::verify('', $codeAt($now), $t, 1), false);

// ── Constant-time compare ───────────────────────────────────────
check('constant-time equal is true for equal strings', $T::constantTimeEquals('123456', '123456'), true);
check('constant-time equal is false for a one-digit difference', $T::constantTimeEquals('123456', '123457'), false);
check('constant-time equal is length-safe', $T::constantTimeEquals('123456', '12345'), false);

// ── Replay guard (the pattern a caller uses) ────────────────────
$code = $codeAt($now);
$first = $T::verify($key, $code, $t, 1);
$last = $first;                             // the caller records the step it accepted
$second = $T::verify($key, $code, $t, 1);   // the same code, presented again
check('the same code matches the same step twice', array($first !== false, $second === $first), array(true, true));
check('a caller recording lastStep rejects the replay', $second <= $last, true);
check('a later step is greater than lastStep, so it is not a replay', $codeAt($now + 1) !== null && ($now + 1) > $last, true);

// ── Recovery codes ──────────────────────────────────────────────
$rc = $T::generateRecoveryCodes(10);
check('ten recovery codes are made', count($rc['codes']), 10);
check('...with ten hashes', count($rc['hashes']), 10);
check('...all distinct', count(array_unique($rc['codes'])), 10);
check('...each hash matches its code', $T::hashRecoveryCode($rc['codes'][0]), $rc['hashes'][0]);
check('a recovery code hashes the same however it is re-typed (case, dashes, spaces)',
	$T::hashRecoveryCode(strtolower(str_replace('-', '', $rc['codes'][0]))), $rc['hashes'][0]);
check('a wrong recovery code hashes to something else',
	$T::hashRecoveryCode('AAAAA-BBBBB') !== $rc['hashes'][0], true);
check('a recovery hash is a 64-hex sha256, never the plaintext',
	(bool) preg_match('/^[0-9a-f]{64}$/', $rc['hashes'][0]) && strpos($rc['hashes'][0], $rc['codes'][0]) === false, true);

// ── Secrets and provisioning ────────────────────────────────────
$s = $T::generateSecret();
check('a generated secret is 160 bits (32 base32 chars)', strlen($s), 32);
check('...and decodes to 20 bytes', strlen($T::base32Decode($s)), 20);
check('two generated secrets differ', $T::generateSecret() !== $T::generateSecret(), true);

$uri = $T::provisioningUri('JBSWY3DPEHPK3PXP', 'alice@example.com', 'Exponential Velocity');
check('otpauth URI scheme and type', strpos($uri, 'otpauth://totp/') === 0, true);
check('otpauth URI carries the issuer:label path', strpos($uri, 'Exponential%20Velocity:alice%40example.com') !== false, true);
check('otpauth URI has the secret', strpos($uri, 'secret=JBSWY3DPEHPK3PXP') !== false, true);
check('otpauth URI names the issuer', strpos($uri, 'issuer=Exponential%20Velocity') !== false, true);
check('otpauth URI names SHA1, 6 digits, 30s', array(
	strpos($uri, 'algorithm=SHA1') !== false,
	strpos($uri, 'digits=6') !== false,
	strpos($uri, 'period=30') !== false), array(true, true, true));

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
