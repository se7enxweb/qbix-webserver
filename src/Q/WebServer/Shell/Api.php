<?php
/**
 * @module Q
 */
/**
 * The shell's two doors into the server, both behind the control panel's
 * sign-in (a live session that is not waiting to change the default key):
 *
 *   WebSocket  /Q/ws/shell          the terminal; messages as in Q_WebServer_Shell_Server
 *   HTTP       /Q/api/shell/...     the same, for automation and as the
 *                                   terminal's fallback when WebSockets are blocked:
 *
 *     GET    shell/session?session=S        hello: tier, themes, toggle key
 *     DELETE shell/session?session=S        end that session and its jobs
 *     POST   shell/exec {command|line, session?, tier?, timeout?, force?}
 *                                           202 {job}; poll or fetch the job for the result
 *     GET    shell/poll?session=S&since=N   {messages, next}
 *     POST   shell/input {job, data}        answer a prompt
 *     POST   shell/signal {job, sig}        INT, TERM, KILL, CONT
 *     GET    shell/jobs[?session=S]         the user's jobs
 *     GET    shell/jobs/<id>                {state, exit, duration, stdout, stderr}
 *     DELETE shell/jobs/<id>                kill it
 *     GET    shell/complete?session=S&line=..&pos=N
 *     GET    shell/history          ?limit=&before=  a page: lines, first, total
 *     GET    shell/history/search   ?q=&before=      the newest match before: hit
 *     GET    shell/audit?limit=N
 *
 * The HTTP routes take the session token only from a header (Authorization:
 * Bearer or X-Panel-Token), never from the cookie alone, so another site
 * cannot drive them from a visitor's browser. The WebSocket checks that the
 * page opening it is this server's own (Origin), and the session on every
 * message.
 *
 * @class Q_WebServer_Shell_Api
 * @static
 */
class Q_WebServer_Shell_Api
{
	/**
	 * An HTTP route under /Q/api/shell/, already past the panel's sign-in.
	 * @param {string} $route after /Q/api/
	 * @param {array} $parsed the request
	 * @param {string} $token the session token
	 * @return {array} array(status, body array)
	 */
	static function handle($route, array $parsed, $token)
	{
		if (!Q_WebServer_Shell::enabled()) return array(404, array('error' => 'The shell is switched off on this server (Q.shell.enabled).'));
		$h = (array) ($parsed['headers'] ?? array());
		$fromHeader = (strpos((string) ($h['authorization'] ?? ''), 'Bearer ') === 0) || (string) ($h['x-panel-token'] ?? '') !== '';
		if (!$fromHeader) {
			return array(403, array('error' => 'Send the session token in an Authorization: Bearer or X-Panel-Token header.'));
		}
		$method = strtoupper((string) ($parsed['method'] ?? 'GET'));
		$q = array();
		if (!empty($parsed['query'])) parse_str((string) $parsed['query'], $q);
		$body = !empty($parsed['body']) ? (json_decode((string) $parsed['body'], true) ?: array()) : array();
		$ip = (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '');
		$owner = Q_WebServer_Shell_Server::owner($token);
		$sessionName = (string) ($body['session'] ?? $q['session'] ?? 'api');
		$sub = substr($route, strlen('shell/'));

		if ($sub === 'session' && $method === 'GET') {
			$key = Q_WebServer_Shell_Server::session($token, $sessionName, $ip);
			return array(200, Q_WebServer_Shell_Server::hello($key));
		}
		if ($sub === 'session' && $method === 'DELETE') {
			// The console's close control: end this session and its jobs.
			return array(200, array('ok' => true, 'closed' => Q_WebServer_Shell_Server::closeClient($token, $sessionName)));
		}
		if ($sub === 'exec' && $method === 'POST') {
			$line = (string) ($body['command'] ?? $body['line'] ?? '');
			if (trim($line) === '') return array(400, array('error' => 'command is required'));
			$key = Q_WebServer_Shell_Server::session($token, $sessionName, $ip);
			$r = Q_WebServer_Shell_Server::exec($key, $line, array(
				'force' => !empty($body['force']),
				'interactive' => array_key_exists('interactive', $body) ? (bool) $body['interactive'] : false,
				'tier' => isset($body['tier']) ? (string) $body['tier'] : null,
				'timeout' => isset($body['timeout']) ? max(1, min((int) Q_WebServer_Shell::config('timeout'), (int) $body['timeout'])) : null,
			));
			// 429 is "too many jobs"; a runner that cannot start at all is the
			// installation's fault (503), and says so.
			if (empty($r['ok'])) return array((int) ($r['status'] ?? 429), array('error' => $r['error'] ?? 'not started'));
			return array(202, array('ok' => true, 'job' => $r['id'], 'n' => $r['n'] ?? null, 'session' => $key,
				'poll' => '/Q/api/shell/jobs/' . $r['id']));
		}
		if ($sub === 'elevate' && $method === 'POST') {
			$key = Q_WebServer_Shell_Server::session($token, $sessionName, $ip);
			list($ok, $error) = Q_WebServer_Shell_Server::elevateSession($key, (string) ($body['password'] ?? ''));
			return $ok ? array(200, array('ok' => true)) : array(401, array('error' => $error));
		}
		if ($sub === 'poll' && $method === 'GET') {
			$key = Q_WebServer_Shell_Server::session($token, $sessionName, $ip);
			list($messages, $next) = Q_WebServer_Shell_Server::poll($key, (int) ($q['since'] ?? 0));
			return array(200, array('messages' => $messages, 'next' => $next));
		}
		if (($sub === 'input' || $sub === 'signal') && $method === 'POST') {
			$id = (string) ($body['job'] ?? '');
			if (Q_WebServer_Shell_Server::job($id, $owner) === null) return array(404, array('error' => 'no such job'));
			$ok = $sub === 'input'
				? Q_WebServer_Shell_Server::input($id, (string) ($body['data'] ?? ''))
				: Q_WebServer_Shell_Server::signal($id, (string) ($body['sig'] ?? 'INT'));
			return array($ok ? 200 : 409, array('ok' => $ok));
		}
		if ($sub === 'jobs' && $method === 'GET') {
			$key = isset($q['session']) ? Q_WebServer_Shell_Server::session($token, (string) $q['session'], $ip) : null;
			return array(200, array('jobs' => Q_WebServer_Shell_Server::jobs($owner, $key)));
		}
		if (preg_match('/^jobs\/(j\d+)$/', $sub, $m)) {
			$job = Q_WebServer_Shell_Server::job($m[1], $owner);
			if ($job === null) return array(404, array('error' => 'no such job'));
			if ($method === 'DELETE') {
				Q_WebServer_Shell_Server::signal($m[1], 'TERM');
				return array(200, array('ok' => true));
			}
			return array(200, $job);
		}
		if ($sub === 'complete' && $method === 'GET') {
			$key = Q_WebServer_Shell_Server::session($token, $sessionName, $ip);
			return array(200, Q_WebServer_Shell_Server::complete($key, (string) ($q['line'] ?? ''), (int) ($q['pos'] ?? strlen((string) ($q['line'] ?? '')))));
		}
		if ($sub === 'history' && $method === 'GET') {
			return array(200, Q_WebServer_Shell_Server::historyPage(isset($q['limit']) ? (int) $q['limit'] : null,
				(isset($q['before']) && is_numeric($q['before'])) ? (int) $q['before'] : null));
		}
		if ($sub === 'history/search' && $method === 'GET') {
			return array(200, Q_WebServer_Shell_Server::historySearch((string) ($q['q'] ?? ''),
				(isset($q['before']) && is_numeric($q['before'])) ? (int) $q['before'] : null));
		}
		if ($sub === 'audit' && $method === 'GET') {
			return array(200, array('entries' => Q_WebServer_Shell_Server::recentAudit(max(1, min(50, (int) ($q['limit'] ?? 20))))));
		}
		return array(404, array('error' => 'no such shell route'));
	}

	/**
	 * Upgrade /Q/ws/shell to a WebSocket, or refuse it.
	 * @return {array|true} true when upgraded; otherwise a response (status, body)
	 */
	static function upgrade($client, array $parsed)
	{
		if (!Q_WebServer_Shell::enabled()) return array(404, 'The shell is switched off.');
		$h = (array) ($parsed['headers'] ?? array());
		// Only this server's own pages may open it: a page elsewhere cannot
		// ride a visitor's cookie into the shell.
		if (!self::originAllowed($parsed)) {
			return array(403, 'Forbidden: the shell opens only from this server\'s own pages.');
		}
		if (!Q_WebServer_Panel::allowed($parsed)) return array(403, 'Forbidden.');
		$ps = Q_WebServer_Panel_Auth::sessionFromRequest($parsed);
		if ($ps === null) return array(401, 'Sign in to the Control Panel to use the shell.');
		if ($ps['mustChange']) return array(403, 'Change the control panel\'s default password first, then open the shell.');
		$token = $ps['token'];
		$ip = (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '');
		$ok = Q_WebSocket::upgrade($client, $h, function ($sk, $raw) use ($token, $ip) {
			Q_WebServer_Shell_Api::onMessage($sk, $raw, $token, $ip);
		}, null, '/Q/ws/shell');
		return $ok ? true : array(400, 'Bad WebSocket request.');
	}

	/**
	 * Whether a WebSocket request comes from this server's own pages: the
	 * same scheme, host and port as the request itself. Another port on the
	 * same host is another site -- browsers send it the same cookies, so a
	 * page there must not be able to open the shell. Q.shell.allowedOrigins
	 * lists more (for a proxy in front), as "https://host[:port]".
	 * @method originAllowed
	 * @static
	 * @param {array} $parsed the request
	 * @return {boolean}
	 */
	static function originAllowed(array $parsed)
	{
		$h = (array) ($parsed['headers'] ?? array());
		$origin = strtolower(trim((string) ($h['origin'] ?? '')));
		$host = strtolower(trim((string) ($h['host'] ?? '')));
		if ($origin === '' || $origin === 'null' || $host === '') return false;
		$o = parse_url($origin);
		if (!is_array($o) || empty($o['scheme']) || empty($o['host']) || isset($o['path']) && $o['path'] !== '') return false;
		$norm = function ($scheme, $hostname, $port) {
			$port = $port ?: ($scheme === 'https' || $scheme === 'wss' ? 443 : 80);
			return $scheme . '://' . trim($hostname, '[]') . ':' . (int) $port;
		};
		$want = $norm($o['scheme'], $o['host'], $o['port'] ?? null);
		foreach ((array) Q_WebServer_Shell::config('allowedOrigins') as $extra) {
			$e = parse_url(strtolower(trim((string) $extra)));
			if (is_array($e) && !empty($e['scheme']) && !empty($e['host']) && $norm($e['scheme'], $e['host'], $e['port'] ?? null) === $want) return true;
		}
		// The server marks a TLS request with '_https' (see handleRequest());
		// 'https' is accepted too, for callers that build the request themselves.
		$scheme = (!empty($parsed['_https']) || !empty($parsed['https'])) ? 'https' : 'http';
		if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d+))?$/', $host, $m)) return false;
		return $norm($scheme, $m[1], $m[2] ?? null) === $want;
	}

	/** A message from the terminal. */
	static function onMessage($sk, $raw, $token, $ip)
	{
		if (!Q_WebServer_Panel_Auth::validateToken($token)) {
			Q_WebSocket::send($sk, array('t' => 'error', 'd' => 'Your control panel session has ended; sign in again.', 'auth' => false));
			Q_WebSocket::disconnect($sk);
			return;
		}
		$m = json_decode((string) $raw, true);
		if (!is_array($m) || !isset($m['t'])) return;
		$owner = Q_WebServer_Shell_Server::owner($token);
		switch ($m['t']) {
			case 'hello':
				$key = Q_WebServer_Shell_Server::session($token, (string) ($m['session'] ?? ''), $ip);
				Q_WebServer_Shell_Server::subscribe($key, $sk);
				Q_WebSocket::send($sk, Q_WebServer_Shell_Server::hello($key));
				return;
			case 'exec':
				$key = Q_WebServer_Shell_Server::session($token, (string) ($m['session'] ?? ''), $ip);
				Q_WebServer_Shell_Server::subscribe($key, $sk);
				$r = Q_WebServer_Shell_Server::exec($key, (string) ($m['line'] ?? ''), array('force' => !empty($m['force']), 'interactive' => true));
				if (empty($r['ok'])) Q_WebSocket::send($sk, array('t' => 'error', 'd' => (string) ($r['error'] ?? 'not started'), 'rid' => $m['rid'] ?? null));
				return;
			case 'stdin':
			case 'signal':
				$id = (string) ($m['id'] ?? '');
				if (Q_WebServer_Shell_Server::job($id, $owner) === null) return;
				if ($m['t'] === 'stdin') Q_WebServer_Shell_Server::input($id, (string) ($m['d'] ?? ''));
				else Q_WebServer_Shell_Server::signal($id, (string) ($m['sig'] ?? 'INT'));
				return;
			case 'complete':
				$key = Q_WebServer_Shell_Server::session($token, (string) ($m['session'] ?? ''), $ip);
				$c = Q_WebServer_Shell_Server::complete($key, (string) ($m['line'] ?? ''), (int) ($m['pos'] ?? 0));
				Q_WebSocket::send($sk, array('t' => 'completion', 'rid' => $m['rid'] ?? null, 'session' => $key) + $c);
				return;
			case 'close':
				Q_WebServer_Shell_Server::closeClient($token, (string) ($m['session'] ?? ''));
				Q_WebSocket::send($sk, array('t' => 'closed', 'session' => (string) ($m['session'] ?? '')));
				return;
			case 'history':
				$key = Q_WebServer_Shell_Server::session($token, (string) ($m['session'] ?? ''), $ip);
				Q_WebSocket::send($sk, array('t' => 'history', 'rid' => $m['rid'] ?? null, 'session' => $key)
					+ Q_WebServer_Shell_Server::historyPage(isset($m['limit']) ? (int) $m['limit'] : null,
						(isset($m['before']) && is_numeric($m['before'])) ? (int) $m['before'] : null));
				return;
			case 'hsearch':
				$key = Q_WebServer_Shell_Server::session($token, (string) ($m['session'] ?? ''), $ip);
				Q_WebSocket::send($sk, array('t' => 'hsearch', 'rid' => $m['rid'] ?? null, 'session' => $key)
					+ Q_WebServer_Shell_Server::historySearch((string) ($m['q'] ?? ''),
						(isset($m['before']) && is_numeric($m['before'])) ? (int) $m['before'] : null));
				return;
		}
	}
}
