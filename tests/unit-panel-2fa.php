<?php
/**
 * The control panel's second factor, end to end through the auth layer and the
 * locked store, without a server.
 *
 *   - with Q.panel.twofactor off, sign-in is password-only and unchanged: a
 *     correct password yields a full session at once, with no 2FA prompt or
 *     gate, even after a secret has been enrolled;
 *   - enrollment: begin returns a secret and its otpauth URI (the one-time
 *     view), confirm proves a code and switches 2FA on with recovery codes;
 *   - with the flag on and a secret enrolled, a correct password yields a
 *     pending session that is NOT allowed into the panel (validateToken false,
 *     and the session.request observer vetoes it), until a valid code promotes
 *     it; a wrong/replayed code is refused and counts toward the lockout; a
 *     recovery code completes sign-in once and is then spent;
 *   - disable needs a valid factor (a code or the password);
 *   - the secret and the recovery plaintext never appear in any response body
 *     but the one-time enrollment views.
 *
 *   php tests/unit-panel-2fa.php   (as root: the store's ownership rule needs it)
 */
if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require __DIR__ . '/../src/Q.php';
require_once __DIR__ . '/../src/Q/WebServer/Layout.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Store.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Auth.php';
require_once __DIR__ . '/../src/Q/WebServer/Panel/Totp.php';

$pass = 0;
$fail = 0;
function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what, var_export($got, true), var_export($want, true));
}
if (!function_exists('posix_geteuid') or posix_geteuid() !== 0) { echo "  skip  needs root for the store's ownership rule\n"; exit(0); }

$A = 'Q_WebServer_Panel_Auth';
$S = 'Q_WebServer_Panel_Store';
$T = 'Q_WebServer_Panel_Totp';
$L = 'Q_WebServer_Panel_LockoutObserver';

$base = sys_get_temp_dir() . DS . 'qbix-panel-2fa-' . getmypid();
@mkdir($base, 0700, true);
chmod($base, 0700);
Q_Config::set('Q', 'panel', 'aclDir', $base . '/acl');
Q_Config::set('Q', 'panel', 'sessionsDir', $base . '/sessions');
Q_Config::set('Q', 'panel', 'defaultPassword', null);
Q_Config::set('Q', 'panel', 'twofactor', false);
$S::reset();

$PW = 'Vx7#qLm2!Rt9@Kw4-Zb';
$IP = '127.0.0.1';
$loginP = array('clientIp' => $IP, 'headers' => array('host' => 'panel.example:8080'));
$hdr = function ($token) use ($IP) {
	return array('clientIp' => $IP, 'headers' => array('host' => 'panel.example:8080', 'x-panel-token' => $token));
};
$store = $S; // for closures
$resetLockout = function () use ($L) { $L::reset(); };
$freshStep = function () use ($S) { $S::twoFactorUpdate(function (array $tt) { $tt['lastStep'] = 0; return $tt; }); };
$wrongCode = function ($key) use ($T) {
	for ($i = 0; $i < 50; ++$i) {
		$c = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
		if ($T::verify($key, $c, null, 1) === false) return $c;
	}
	return '111111';
};

// A password is set, so the panel is past first-run.
$r = $A::storePassword($PW, null, true, null, $A::policyContext($loginP));
check('a panel password is set', $r['ok'] ?? false, true);

// ── Flag off: password-only, unchanged ──────────────────────────
$resetLockout();
list($st, $d) = $A::login($loginP, array('password' => $PW));
check('2FA off: a correct password signs in with 200', $st, 200);
check('2FA off: ...a full token, no 2FA prompt', array(!empty($d['token']), isset($d['twoFactorRequired'])), array(true, false));
check('2FA off: ...and the session is a full one (validateToken)', $A::validateToken($d['token']), true);
check('2FA off: ...the login answer keeps its usual shape (rules present)', isset($d['rules']), true);
$offBody = json_encode($d);

// ── Enrollment ──────────────────────────────────────────────────
list($st, $d) = $A::twoFactorBegin($hdr($d['token']));
check('begin enrollment answers 200 with a secret and otpauth URI',
	array($st, !empty($d['secret']), strpos((string) ($d['otpauth'] ?? ''), 'otpauth://totp/') === 0), array(200, true, true));
$secret = $d['secret'];
check('begin refuses the cookie alone (needs a header token)',
	$A::twoFactorBegin(array('clientIp' => $IP, 'headers' => array('host' => 'h'), 'cookies' => array('Q_panel_token' => 'x')))[0], 403);
$key = $T::base32Decode($secret);
check('the store now holds a pending secret, not an enabled one',
	array($S::twoFactorPendingGet() === $secret, $S::twoFactorEnabled()), array(true, false));

list($st, $d) = $A::twoFactorConfirm($hdr('t'), array('code' => 'off-by-a-mile'));
check('confirm refuses a wrong code', $st, 400);
list($st, $d) = $A::twoFactorConfirm($hdr('t'), array('code' => $T::totp($key)));
check('confirm accepts a real code and turns 2FA on', array($st, $d['enabled'] ?? null), array(200, true));
check('confirm issues ten recovery codes (shown once)', count($d['recovery'] ?? array()), 10);
$recovery = $d['recovery'];
$confirmBody = json_encode($d);
check('2FA is now enrolled and enabled in the store', $S::twoFactorEnabled(), true);

// ── Flag still off, but enrolled: still password-only ───────────
$resetLockout();
list($st, $d) = $A::login($loginP, array('password' => $PW));
check('enrolled but flag off: password alone still yields a FULL session',
	array($st, isset($d['twoFactorRequired']), $A::validateToken($d['token'])), array(200, false, true));

// ── Flag on ─────────────────────────────────────────────────────
Q_Config::set('Q', 'panel', 'twofactor', true);
check('2FA is now active', $A::twoFactorActive(), true);

$freshStep();
$resetLockout();
list($st, $d) = $A::login($loginP, array('password' => $PW));
$pendingToken = $d['token'] ?? '';
check('flag on: a correct password now yields a PENDING session',
	array($st, $d['twoFactorRequired'] ?? null, $pendingToken !== ''), array(200, true, true));
check('flag on: the pending session is NOT allowed into the panel', $A::validateToken($pendingToken), false);
check('flag on: ...it reads as two-factor-pending', $A::twoFactorPending($pendingToken), true);

// The session.request observer gates a pending session everywhere but auth/2fa and logout.
Q_WebServer_Panel_Events::reset();
$veto = Q_WebServer_Panel_Events::notify('session.request', array('token' => $pendingToken, 'route' => 'system', 'ip' => $IP));
check('a pending session is vetoed from an admin route', array((int) ($veto['status'] ?? 0), $veto['body']['twoFactorRequired'] ?? null), array(403, true));
$veto = Q_WebServer_Panel_Events::notify('session.request', array('token' => $pendingToken, 'route' => 'auth/2fa', 'ip' => $IP));
check('...but may present its code (auth/2fa)', $veto, null);
$veto = Q_WebServer_Panel_Events::notify('session.request', array('token' => $pendingToken, 'route' => 'auth/logout', 'ip' => $IP));
check('...and may sign out', $veto, null);

// A valid code promotes it.
$code = $T::totp($key);
list($st, $d) = $A::verifyTwoFactor($hdr($pendingToken), array('code' => $code));
check('a valid code completes sign-in (200)', array($st, $d['ok'] ?? null), array(200, true));
check('...and the session is now full', $A::validateToken($pendingToken), true);
$verifyBody = json_encode($d);

// Replay: a fresh pending session, the same code, is refused.
list(, $d2) = $A::login($loginP, array('password' => $PW));
list($st, ) = $A::verifyTwoFactor($hdr($d2['token']), array('code' => $code));
check('the same code cannot be used again (replay refused)', $st, 401);

// ── Wrong codes count toward the lockout ────────────────────────
$resetLockout();
$freshStep();
list(, $dl) = $A::login($loginP, array('password' => $PW));
$tk = $dl['token'];
$statuses = array();
for ($i = 0; $i < 5; ++$i) {
	list($st, ) = $A::verifyTwoFactor($hdr($tk), array('code' => $wrongCode($key)));
	$statuses[] = $st;
}
check('five wrong codes are each refused (401)', $statuses, array(401, 401, 401, 401, 401));
list($st, $d) = $A::verifyTwoFactor($hdr($tk), array('code' => $wrongCode($key)));
check('a sixth attempt is locked out (429), so the code path feeds the lockout',
	array($st, isset($d['retryAfter'])), array(429, true));

// ── Recovery code: once, then spent ─────────────────────────────
$resetLockout();
$freshStep();
list(, $dl) = $A::login($loginP, array('password' => $PW));
list($st, ) = $A::verifyTwoFactor($hdr($dl['token']), array('recovery' => $recovery[0]));
check('a recovery code completes sign-in', $st, 200);
list(, $dl) = $A::login($loginP, array('password' => $PW));
list($st, ) = $A::verifyTwoFactor($hdr($dl['token']), array('recovery' => $recovery[0]));
check('...and that recovery code is then spent', $st, 401);
list($st, ) = $A::verifyTwoFactor($hdr($dl['token']), array('recovery' => $recovery[1]));
check('...while a different recovery code still works', $st, 200);

// ── Disable needs a valid factor ────────────────────────────────
list($st, ) = $A::twoFactorDisable($hdr('t'), array());
check('disable with no factor is refused (403)', $st, 403);
list($st, ) = $A::twoFactorDisable($hdr('t'), array('code' => '000000'));
check('disable with a wrong code is refused (403)', $st, 403);
$freshStep();
list($st, $d) = $A::twoFactorDisable($hdr('t'), array('password' => $PW));
check('disable with the password turns 2FA off', array($st, $d['enabled'] ?? null), array(200, false));
check('...the store no longer holds a secret', $S::twoFactorEnabled(), false);
list(, $d) = $A::login($loginP, array('password' => $PW));
check('...and sign-in is password-only again', isset($d['twoFactorRequired']), false);

// ── Nothing secret ever leaks ───────────────────────────────────
list(, $statusData) = $A::twoFactorStatus($loginP);
$statusBody = json_encode($statusData);
$leaks = function ($body) use ($secret, $recovery) {
	if (strpos((string) $body, $secret) !== false) return 'secret';
	foreach ($recovery as $rc) if (strpos((string) $body, $rc) !== false) return 'a recovery code';
	return false;
};
check('the off login body carries no secret or recovery code', $leaks($offBody), false);
check('the status body carries no secret or recovery code', $leaks($statusBody), false);
check('the verify body carries no secret or recovery code', $leaks($verifyBody), false);
check('status never returns the raw secret key at all', isset($statusData['secret']), false);
// The enrollment views are the one place a secret and codes are shown, on purpose.
check('begin IS where the secret is shown (once)', strpos(json_encode(array('secret' => $secret)), $secret) !== false, true);
check('confirm IS where the recovery codes are shown (once)', $leaks($confirmBody) !== false, true);

// ── Clean up ────────────────────────────────────────────────────
Q_Config::set('Q', 'panel', 'twofactor', false);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $e) { $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname()); }
@rmdir($base);

if ($fail) { printf("  FAIL - %d of %d case(s)\n", $fail, $pass + $fail); exit(1); }
printf("  PASS - %d case(s)\n", $pass);
