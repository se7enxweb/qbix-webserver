<?php
/**
 * @module Q
 */

/**
 * Web-based control panel for managing Qbix apps.
 *
 * Serves at /Q/panel. Provides:
 * - List/create/start/stop apps
 * - Run scripts (configure, install, urls, etc.) via web
 * - Open app folders in Finder/Explorer/VS Code
 * - Plugin management
 * - System info
 *
 * No CLI needed. Everything a normie needs to manage
 * their server from a browser.
 *
 * @class Q_WebServer_Panel
 */
class Q_WebServer_Panel
{
	/** @internal Currently served app dirName, or null */
	static $servingApp = null;
	/**
	 * Handle panel requests with authentication.
	 * First visitor sets a password. All subsequent requests require it.
	 * Password stored in APP_DIR/local/panel.json (gitignored).
	 * @method handle
	 * @static
	 * @param {resource} $client
	 * @param {array} $parsed
	 * @return {boolean} true if handled
	 */
	static function handle($client, $parsed)
	{
		$r = self::respond($parsed);
		if ($r === null) return false;
		$headers = $r['headers'] ?? array();
		$type = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
		unset($headers['Content-Type']);
		Q_WebServer::sendResponse($client, $r['status'] ?? 200, $r['body'] ?? '', $type, $headers);
		return true;
	}

	/**
	 * The panel's answer to a request, as a response array, or null when the
	 * path is not the panel's. handle() sends it on an HTTP/1.1 connection;
	 * Q_WebServer::route() returns it to HTTP/2, which a browser speaks by
	 * default -- and which, answered by nothing, handed /Q/panel to the
	 * application and showed its 404. One function, so both protocols apply
	 * the same address rule and the same authentication.
	 * @method respond
	 * @static
	 * @param {array} $parsed with clientIp (or _remoteAddr) set
	 * @return {array|null} status, headers, body
	 */
	static function respond($parsed)
	{
		$path = $parsed['path'];
		$json = function ($status, $data) {
			return array('status' => $status, 'body' => json_encode($data),
				'headers' => array('Content-Type' => 'application/json', 'Cache-Control' => 'no-store'));
		};
		// A sign-in (or a password change, which keeps its session) sets the
		// session cookie from the server; a sign-out clears it.
		$withCookie = function (array $r, $token) use ($parsed) {
			$r['headers']['Set-Cookie'] = Q_WebServer_Panel_Auth::sessionCookie($token, $parsed);
			return $r;
		};

		// Who may reach the panel at all: see allowed(). The same rule on
		// both protocols, since both come through here.
		if (strpos($path, '/Q/panel') === 0 || strpos($path, '/Q/api/') === 0) {
			if (!self::allowed($parsed)) {
				if (strpos($path, '/Q/api/') === 0) {
					return $json(403, array('error' => self::REFUSED_TEXT));
				}
				return array('status' => 403,
					'body' => self::refusedPage(self::REFUSED_HTML),
					'headers' => array('Content-Type' => 'text/html; charset=utf-8'));
			}
		}

		// The panel page, with optional /(name)/value view parameters that
		// make a view bookmarkable: /Q/panel/(tab)/logs, /Q/panel/(tab)/system.
		if (preg_match('#^/Q/panel(?:/|$)#', $path)) {
			return array('status' => 200, 'body' => self::renderPanel($parsed),
				// It opens on the view the session calls for (initialAuthState()),
				// so no cache may keep one visitor's first paint for another.
				'headers' => array('Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Vary' => 'Cookie'));
		}

		if (strpos($path, '/Q/api/') !== 0) return null;

		$route = substr($path, 7);
		$body = !empty($parsed['body']) ? (json_decode($parsed['body'], true) ?: array()) : array();

		if ($route === 'auth/login') {
			list($status, $data) = Q_WebServer_Panel_Auth::login($parsed, $body);
			$r = $json($status, $data);
			return !empty($data['token']) ? $withCookie($r, (string) $data['token']) : $r;
		}
		if ($route === 'auth/setup') {
			// The first password is set from this machine, with the dashboard
			// token, or where the panel is open remotely on purpose -- never
			// by whoever happens to reach the page first.
			if (!self::setupAllowed($parsed)) {
				return $json(403, array('error' => self::SETUP_REFUSED_TEXT,
					'needsSetup' => !Q_WebServer_Panel_Auth::hasPassword(), 'setupAllowed' => false));
			}
			list($status, $data) = Q_WebServer_Panel_Auth::setup($parsed, $body);
			return $json($status, $data);
		}

		// Everything else needs a session...
		$auth = Q_WebServer_Panel_Auth::checkSession($parsed);
		if (!$auth['ok']) {
			if (!empty($auth['needsSetup'])) $auth += self::setupInfo($parsed);
			return $json(401, $auth);
		}
		// ...that the observers let through: one signed in with the default
		// key can only change it, or sign out.
		$veto = Q_WebServer_Panel_Events::notify('session.request', array(
			'token' => $auth['token'], 'route' => $route,
			'ip' => (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '')));
		if ($veto) return $json($veto['status'], $veto['body']);

		// The shell's HTTP door: the same session, and more checks of its own.
		if (strpos($route, 'shell/') === 0) {
			list($status, $data) = Q_WebServer_Shell_Api::handle($route, $parsed, $auth['token']);
			return $json($status, $data);
		}

		if ($route === 'auth/password') {
			list($status, $data) = Q_WebServer_Panel_Auth::change($parsed, $body);
			$r = $json($status, $data);
			return ($status === 200 && $auth['token'] !== '') ? $withCookie($r, (string) ($data['token'] ?? $auth['token'])) : $r;
		}
		if ($route === 'auth/logout') {
			list($status, $data) = Q_WebServer_Panel_Auth::logout($parsed);
			return $withCookie($json($status, $data), null);
		}

		$result = self::handleApi($path, $parsed);
		return $json($result['status'] ?? 200, $result);
	}

	/** Plain-text refusal, for the API. */
	const REFUSED_TEXT = 'The control panel is available from this machine, with the dashboard token, or once a password is set -- then to anyone who signs in with it. Set one on the server with: qbixctl panel:password --root=<document root>. Or allow the panel remotely with Q.panel.remote.';

	/** The same, for the server's 403 page. Written here, never request data. */
	const REFUSED_HTML = 'The control panel is available from this machine, with the dashboard token, or once a password is set &mdash; then to anyone who signs in with it.<br><br>Set one on the server with <code>qbixctl panel:password</code> <code>--root=&lt;document root&gt;</code>, or allow the panel remotely with <code>Q.panel.remote</code>.';

	/** Why a remote first-time setup was refused, and what to do instead. */
	const SETUP_REFUSED_TEXT = 'The panel password can be set here only from the server itself. Set it on the server with: qbixctl panel:password --root=<document root> (the same --root the server runs with), then sign in here.';

	/**
	 * Whether this request's client is this machine. An empty address is a
	 * Unix-socket client, which is local too.
	 */
	static function isLocal($parsed)
	{
		$ip = (string) ($parsed['clientIp'] ?? $parsed['_remoteAddr'] ?? '');
		return $ip === '' or Q_WebServer::isLocalRequest($parsed);
	}

	/**
	 * Who may reach /Q/panel and /Q/api/: this machine; anyone where
	 * Q.panel.remote is true; anyone once a panel password exists -- the page
	 * then shows its login form, and every API call but auth/login still
	 * needs a session token; and whoever Q_WebServer::adminAllowed() lets see
	 * the dashboard (the dashboard token, a panel session, Q.dashboard.remote).
	 * @method allowed
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function allowed($parsed)
	{
		return self::isLocal($parsed)
			|| Q_Config::get('Q', 'panel', 'remote', false)
			|| Q_WebServer_Panel_Auth::canSignIn($parsed)
			|| Q_WebServer::adminAllowed($parsed);
	}

	/**
	 * Whether this request may set the first password: from this machine,
	 * with the dashboard token, or where Q.panel.remote is true. A remote
	 * visitor who merely reached the page is not enough.
	 * @method setupAllowed
	 * @static
	 * @param {array} $parsed
	 * @return {boolean}
	 */
	static function setupAllowed($parsed)
	{
		return self::isLocal($parsed)
			|| Q_Config::get('Q', 'panel', 'remote', false)
			|| Q_WebServer::hasAdminCredential($parsed);
	}

	/** What the page needs to know when no password is set yet. */
	static function setupInfo($parsed)
	{
		$ok = self::setupAllowed($parsed);
		return $ok ? array('setupAllowed' => true)
			: array('setupAllowed' => false, 'setupHelp' => self::SETUP_REFUSED_TEXT);
	}

	/**
	 * The panel's settings file for an application directory -- where the
	 * server keeps it: APP_DIR/local/panel.json, APP_DIR being the directory
	 * above the document root (or the --app directory).
	 * @method configPathFor
	 * @static
	 * @param {string} $appDir
	 * @return {string}
	 */
	static function configPathFor($appDir)
	{
		return rtrim($appDir, '/\\') . '/local/panel.json';
	}

	/**
	 * Set the panel password: the hash auth/setup stores, in the same file
	 * and format. Used by auth/setup and by `qbixctl panel:password`.
	 * @method storePassword
	 * @static
	 * @param {string} $password at least 6 characters
	 * @param {string|null} $configPath default: panelConfigPath()
	 * @param {boolean} $revokeSessions end every existing session (a changed password should)
	 * @return {array} ok, and error or path
	 */
	static function storePassword($password, $configPath = null, $revokeSessions = false)
	{
		return Q_WebServer_Panel_Auth::storePassword($password, $configPath, $revokeSessions);
	}

	/**
	 * Get the panel config file path
	 */
	static function panelConfigPath()
	{
		// The credentials file in the panel's store (acl/panel.json): see
		// Q_WebServer_Panel_Store for where that is and the rule it passes.
		return Q_WebServer_Panel_Store::aclFile();
	}

	/**
	 * Check if the request has a valid auth token
	 */
	private static function checkAuth($parsed)
	{
		return Q_WebServer_Panel_Auth::checkSession($parsed);
	}

	/**
	 * Validate a session token (for WebSocket auth, etc.)
	 * @method validateToken
	 * @static
	 * @param {string} $token
	 * @return {boolean}
	 */
	static function validateToken($token)
	{
		return Q_WebServer_Panel_Auth::validateToken((string) $token);
	}

	/**
	 * Check whether a panel password has been set
	 * @method hasPassword
	 * @static
	 * @return {boolean}
	 */
	static function hasPassword()
	{
		return Q_WebServer_Panel_Auth::hasPassword();
	}

	static function handleApi($path, $parsed)
	{
		$route = substr($path, 7); // strip /Q/api/

		switch ($route) {
			case 'apps':
				return self::apiListApps();
			case 'apps/fork-mode':
				return self::apiSetForkMode($parsed);
			case 'apps/create':
				return self::apiCreateApp($parsed);
			case 'apps/configure':
				return self::apiRunScript($parsed, 'configure');
			case 'apps/install':
				return self::apiRunScript($parsed, 'install');
			case 'apps/open':
				return self::apiOpenFolder($parsed);
			case 'apps/serve':
				return self::apiServeApp($parsed);
			case 'apps/setdir':
				return self::apiSetAppsDir($parsed);
			case 'scripts':
				return self::apiListScripts($parsed);
			case 'scripts/run':
				return self::apiRunScript($parsed);
			case 'plugins':
				return self::apiListPlugins();
			case 'plugins/add':
				return self::apiAddPlugin($parsed);
			case 'servers':
				return self::apiListServers();
			case 'servers/add':
				return self::apiAddServer($parsed);
			case 'servers/remove':
				return self::apiRemoveServer($parsed);
			case 'servers/deploy':
				return self::apiDeploy($parsed);
			case 'system':
				return self::apiSystemInfo();
			case 'auth/password':
				return self::apiChangePassword($parsed);
			case 'auth/logout':
				return self::apiLogout($parsed);
			case 'playground/run':
				return self::apiPlaygroundRun($parsed);
			case 'platform/install':
				return self::apiInstallPlatform($parsed);
			case 'domains':
				return self::apiListDomains();
			case 'domains/add':
				return self::apiAddDomain($parsed);
			case 'domains/remove':
				return self::apiRemoveDomain($parsed);
			case 'domains/usage':
				return self::apiDomainUsage();
			case 'domains/status':
				return self::apiDomainStatus($parsed);
			case 'domains/alias':
				return self::apiDomainAlias($parsed);
			case 'domains/subdomain':
				return self::apiDomainSubdomain($parsed);
			case 'domains/root':
				return self::apiDomainRoot($parsed);
			case 'domains/redirects':
				return self::apiDomainRedirects($parsed);
			case 'domains/hsts':
				return self::apiDomainHsts($parsed);
			case 'domains/errordocs':
				return self::apiDomainErrorDocs($parsed);
			case 'domains/defaults':
				return self::apiDomainDefaults($parsed);
			case 'domains/cert':
				return self::apiDomainCert($parsed);
			case 'domains/cert/issue':
			case 'domains/provision':
				return self::apiDomainCertIssue($parsed);
			case 'domains/cert/job':
				return self::apiDomainCertJob($parsed);
			case 'ssl/overview':
				return Q_WebServer_Certificate_Admin::overview();
			case 'ssl/certs':
				return Q_WebServer_Certificate_Admin::certs();
			case 'ssl/history':
				return Q_WebServer_Certificate_Admin::history((int) ($parsed['query']['limit'] ?? 100));
			case 'ssl/settings':
			case 'ssl/renew':
			case 'ssl/reload':
				return self::apiSsl($route, $parsed);
			case 'domains/traffic':
				return self::apiDomainTraffic($parsed);
			case 'domains/hosts':
				return self::apiHostsFile();
			case 'domains/hosts/add':
				return self::apiHostsAdd($parsed);
			case 'autohost':
				require_once dirname(__DIR__) . '/WebServer/Autohost.php';
				return Q_WebServer_Autohost::status();
			case 'autohost/toggle':
				return self::apiAutohostToggle($parsed);
			case 'watchdog':
				require_once dirname(__DIR__) . '/WebServer/Watchdog.php';
				return Q_WebServer_Watchdog::status();
			case 'attestation':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				return Q_WebServer_Trust::attestation();
			case 'attestation/sign':
				return self::apiAttestationSign($parsed);
			case 'attestation/verify':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				$m = (int) ($parsed['query']['m'] ?? 0) ?: null;
				return Q_WebServer_Trust::verifyBinary(null, $m);
			case 'attestation/publish-rekor':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				$bp = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
				$uuid = Q_WebServer_Trust::publishToRekor($bp);
				return $uuid
					? ['published' => true, 'uuid' => $uuid, 'url' => "https://search.sigstore.dev/?uuid=$uuid"]
					: ['status' => 500, 'error' => 'Failed. Sign the binary first.'];
			case 'trust':
				require_once dirname(__DIR__) . '/WebServer/Trust.php';
				return Q_WebServer_Trust::status();
			case 'trust/verify':
				return self::apiTrustVerify($parsed);
			case 'metrics':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				return Q_WebServer_Metrics::status();
			case 'metrics/history':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$minutes = (int) ($parsed['query']['minutes'] ?? 60);
				return ['stats' => Q_WebServer_Metrics::recentStats(min($minutes, 1440))];
			case 'metrics/summary':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$hours = (int) ($parsed['query']['hours'] ?? 24);
				return Q_WebServer_Metrics::summary(min($hours, 720));
			case 'metrics/flow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$limit = (int) ($parsed['query']['limit'] ?? 50);
				return ['edges' => Q_WebServer_Metrics::flow(min($limit, 200))];
			case 'metrics/pages':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$limit = (int) ($parsed['query']['limit'] ?? 20);
				return ['pages' => Q_WebServer_Metrics::topPages(min($limit, 100))];
			case 'metrics/pageflow':
				require_once dirname(__DIR__) . '/WebServer/Metrics.php';
				$path = $parsed['query']['path'] ?? '/';
				return Q_WebServer_Metrics::pageFlow($path);
			case 'workers':
				return self::apiWorkerStatus();
			case 'workers/resize':
				return self::apiWorkerResize($parsed);
			case 'workers/recycle':
				return self::apiWorkerRecycle($parsed);
			case 'workers/detail':
				return self::apiWorkerDetail();
			case 'logs':
				return self::apiLogs($parsed);
			case 'cron':
				return self::apiCronStatus();
			case 'cron/run':
				return self::apiCronRun($parsed);
			case 'frameworks':
				return self::apiFrameworks();
			case 'frameworks/run':
				return self::apiFrameworkRun($parsed);
			case 'frameworks/packages':
				return self::apiFrameworkPackages($parsed);
			case 'frameworks/composer':
				return self::apiFrameworkComposer($parsed);
			case 'frameworks/pkg-action':
				return self::apiFrameworkPkgAction($parsed);
			case 'frameworks/pkg-download':
				return self::apiFrameworkPkgDownload($parsed);
			case 'qbix/installer':
				return self::apiQbixInstaller($parsed);
			case 'qbix/npm':
				return self::apiQbixNpm($parsed);
			case 'qbix/plugins':
				return self::apiQbixPlugins();
			case 'qbix/plugins/install':
				return self::apiQbixPluginInstall($parsed);
			case 'qbix/plugins/schema':
				return self::apiQbixPluginSchema($parsed);
			default:
				return array('status' => 404, 'error' => 'Unknown endpoint');
		}
	}

	private static function apiChangePassword($parsed)
	{
		$body = !empty($parsed['body']) ? (json_decode($parsed['body'], true) ?: array()) : array();
		list($status, $data) = Q_WebServer_Panel_Auth::change($parsed, $body);
		return $data + array('status' => $status);
	}

	private static function apiLogout($parsed)
	{
		list($status, $data) = Q_WebServer_Panel_Auth::logout($parsed);
		return $data + array('status' => $status);
	}

	// ── Apps API ─────────────────────────────────────────

	static function apiListApps()
	{
		$appsDir = self::appsDir();
		$apps = array();
		// Every application the server can see -- the one it serves first --
		// whatever it is built on. The list below it is the apps directory's
		// Qbix apps, which can be created, configured and served from here.
		$installations = Q_WebServer_Framework::scan();
		if (!$appsDir || !is_dir($appsDir)) {
			return array('apps' => $apps, 'appsDir' => $appsDir, 'installations' => $installations);
		}

		foreach (scandir($appsDir) as $name) {
			if ($name[0] === '.' || !is_dir($appsDir . DS . $name)) continue;
			$appDir = $appsDir . DS . $name;

			// Include any directory that has web/ or config/app.json
			$hasWeb = is_dir($appDir . DS . 'web');
			$configFile = $appDir . DS . 'config' . DS . 'app.json';
			$hasConfig = file_exists($configFile);
			if (!$hasWeb && !$hasConfig) continue;

			$config = $hasConfig
				? json_decode(file_get_contents($configFile), true)
				: array();
			$localConfig = null;
			$localFile = $appDir . DS . 'local' . DS . 'app.json';
			if (file_exists($localFile)) {
				$localConfig = json_decode(file_get_contents($localFile), true);
			}

			$appName = $config['Q']['app'] ?? $name;
			$plugins = $config['Q']['plugins'] ?? array();
			$configured = is_dir($appDir . DS . 'local');
			$url = $localConfig['Q']['web']['appRootUrl'] ?? '';
			$hasHandlers = is_dir($appDir . DS . 'handlers');
			$hasClasses = is_dir($appDir . DS . 'classes');
			$hasScripts = is_dir($appDir . DS . 'scripts');
			$isQbixApp = $hasConfig && isset($config['Q']);

			$apps[] = array(
				'name' => $appName,
				'dir' => $appDir,
				'dirName' => $name,
				'plugins' => $plugins,
				'configured' => $configured,
				'url' => $url,
				'hasWeb' => $hasWeb,
				'hasHandlers' => $hasHandlers,
				'hasClasses' => $hasClasses,
				'hasScripts' => $hasScripts,
				'isQbixApp' => $isQbixApp,
				'serving' => (self::$servingApp === $name),
				'forkPerRequest' => $localConfig['Q']['webserver']['forkPerRequest'] ?? $config['Q']['webserver']['forkPerRequest'] ?? null,
			);
		}

		return array('apps' => $apps, 'appsDir' => $appsDir, 'installations' => $installations);
	}

	/**
	 * Set forkPerRequest mode for an app.
	 * Writes to the app's local/app.json so it persists.
	 */
	static function apiSetForkMode($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appDirName = $body['app'] ?? '';
		$forkMode = $body['forkPerRequest'] ?? null;

		if (!$appDirName) return array('status' => 400, 'error' => 'App name required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appDirName;
		if (!is_dir($appDir)) return array('status' => 404, 'error' => 'App not found');

		$localDir = $appDir . DS . 'local';
		@mkdir($localDir, 0755, true);
		$localFile = $localDir . DS . 'app.json';

		$config = array();
		if (is_file($localFile)) {
			$config = json_decode(file_get_contents($localFile), true) ?: array();
		}

		if ($forkMode === null || $forkMode === 'auto') {
			// Remove the setting (use server default)
			unset($config['Q']['webserver']['forkPerRequest']);
			// Clean up empty nesting
			if (empty($config['Q']['webserver'])) unset($config['Q']['webserver']);
			if (empty($config['Q'])) unset($config['Q']);
		} else {
			$config['Q']['webserver']['forkPerRequest'] = (bool) $forkMode;
		}

		file_put_contents($localFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return array('ok' => true, 'forkPerRequest' => $forkMode, 'note' => 'Restart the server for changes to take effect.');
	}

	static function apiCreateApp($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^A-Za-z0-9_]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'App name required');

		$template = $body['template'] ?? 'MyApp';
		$appsDir = self::appsDir();
		$targetDir = $appsDir . DS . $name;

		if (file_exists($targetDir)) {
			return array('status' => 409, 'error' => "App '$name' already exists");
		}

		// Find template
		$templateDir = null;
		$candidates = array(
			$appsDir . DS . $template,
			dirname($appsDir) . DS . $template,
			defined('Q_DIR') ? Q_DIR . DS . '..' . DS . $template : null,
		);
		foreach ($candidates as $c) {
			if (is_dir($c) && file_exists($c . DS . 'config' . DS . 'app.json')) {
				$templateDir = realpath($c);
				break;
			}
		}
		if (!$templateDir) {
			// No template found — create a minimal standalone app
			@mkdir($targetDir . DS . 'web', 0755, true);
			@mkdir($targetDir . DS . 'handlers', 0755, true);
			@mkdir($targetDir . DS . 'classes', 0755, true);
			@mkdir($targetDir . DS . 'config', 0755, true);

			file_put_contents($targetDir . DS . 'config' . DS . 'app.json',
				json_encode(array('Q' => array('app' => $name)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			file_put_contents($targetDir . DS . 'web' . DS . 'index.html',
				"<!DOCTYPE html>\n<html><head><title>{$name}</title></head>\n"
				. "<body><h1>{$name}</h1><p>Edit web/index.html to get started.</p></body></html>\n");

			return array('created' => $name, 'dir' => $targetDir);
		}

		// Copy template
		self::copyDir($templateDir, $targetDir);

		// Rename references
		$oldName = basename($templateDir);
		self::renameInApp($targetDir, $oldName, $name);

		return array('created' => $name, 'dir' => $targetDir);
	}

	// ── Scripts API ──────────────────────────────────────

	static function apiListScripts($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appName = $body['app'] ?? '';

		$scripts = array();

		// Platform scripts
		$platformScripts = defined('Q_DIR') ? Q_DIR . DS . 'scripts' : null;
		if ($platformScripts && is_dir($platformScripts)) {
			foreach (glob($platformScripts . DS . '*.php') as $f) {
				$scripts[] = array(
					'name' => basename($f, '.php'),
					'path' => $f,
					'scope' => 'platform'
				);
			}
		}

		// App scripts
		if ($appName) {
			$appDir = self::appsDir() . DS . $appName;
			$appScripts = $appDir . DS . 'scripts' . DS . 'Q';
			if (is_dir($appScripts)) {
				foreach (glob($appScripts . DS . '*.php') as $f) {
					$scripts[] = array(
						'name' => basename($f, '.php'),
						'path' => $f,
						'scope' => 'app'
					);
				}
			}
		}

		return array('scripts' => $scripts);
	}

	static function apiRunScript($parsed, $scriptName = null)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$appName = $body['app'] ?? '';
		$scriptName = $scriptName ?: ($body['script'] ?? '');
		$args = $body['args'] ?? array();

		if (!$appName || !$scriptName) {
			return array('status' => 400, 'error' => 'app and script required');
		}

		$appDir = self::appsDir() . DS . $appName;
		if (!is_dir($appDir)) {
			return array('status' => 404, 'error' => "App '$appName' not found");
		}

		$scriptPath = $appDir . DS . 'scripts' . DS . 'Q' . DS . $scriptName . '.php';
		if (!file_exists($scriptPath)) {
			return array('status' => 404, 'error' => "Script '$scriptName' not found");
		}

		// Run script as subprocess
		$argStr = '';
		foreach ($args as $k => $v) {
			if (is_numeric($k)) {
				$argStr .= ' ' . escapeshellarg($v);
			} else {
				$argStr .= ' --' . $k . '=' . escapeshellarg($v);
			}
		}

		$cmd = PHP_BINARY . ' ' . escapeshellarg($scriptPath) . $argStr . ' 2>&1';
		$output = array();
		$code = 0;
		exec($cmd, $output, $code);

		return array(
			'script' => $scriptName,
			'app' => $appName,
			'exitCode' => $code,
			'output' => implode("\n", $output)
		);
	}

	// ── Plugins API ──────────────────────────────────────

	static function apiListPlugins()
	{
		$plugins = array();
		$platformDir = null;
		$pluginsDir = null;

		// 1. Find platform via local/paths.json
		if (defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) {
					$platformDir = realpath($paths['platform']);
				}
			}
		}
		if (!$platformDir && defined('Q_DIR')) {
			$platformDir = Q_DIR;
		}

		// 2. Read app's plugin list from config/app.json
		$appPlugins = array();
		if (defined('APP_DIR')) {
			$appConfig = APP_DIR . DS . 'config' . DS . 'app.json';
			if (file_exists($appConfig)) {
				$config = json_decode(file_get_contents($appConfig), true);
				$appPlugins = $config['Q']['plugins'] ?? array();
			}
		}

		// 3. Read installed versions from local/plugins.json
		$installedVersions = array();
		if (defined('APP_DIR')) {
			$localPlugins = APP_DIR . DS . 'local' . DS . 'plugins.json';
			if (file_exists($localPlugins)) {
				$lp = json_decode(file_get_contents($localPlugins), true);
				$installedVersions = $lp['Q']['pluginLocal'] ?? array();
			}
		}

		// 4. Find plugins directory
		if ($platformDir) {
			$pluginsDir = $platformDir . DS . 'plugins';
			if (!is_dir($pluginsDir)) {
				$pluginsDir = dirname($platformDir) . DS . 'plugins';
			}
		}

		// 5. Build plugin list — prefer app's declared plugins, fall back to scanning
		$pluginNames = !empty($appPlugins) ? $appPlugins : array();
		if (empty($pluginNames) && $pluginsDir && is_dir($pluginsDir)) {
			foreach (scandir($pluginsDir) as $name) {
				if ($name[0] === '.' || !is_dir($pluginsDir . DS . $name)) continue;
				$pluginNames[] = $name;
			}
		}

		foreach ($pluginNames as $name) {
			$pDir = $pluginsDir ? $pluginsDir . DS . $name : null;
			$configFile = $pDir ? $pDir . DS . 'config' . DS . 'plugin.json' : null;
			$pConfig = ($configFile && file_exists($configFile))
				? json_decode(file_get_contents($configFile), true) : null;

			$info = $installedVersions[$name] ?? array();
			$pluginInfo = $pConfig['Q']['pluginInfo'][$name] ?? array();

			$plugins[] = array(
				'name' => $name,
				'dir' => $pDir,
				'installed' => isset($info['version']),
				'version' => $info['version'] ?? $pluginInfo['version'] ?? null,
				'compatible' => $info['compatible'] ?? $pluginInfo['compatible'] ?? null,
				'requires' => $pluginInfo['requires'] ?? $info['requires'] ?? array(),
				'connections' => $pluginInfo['connections'] ?? $info['connections'] ?? array(),
				'hasConfig' => $configFile && file_exists($configFile),
				'inApp' => in_array($name, $appPlugins),
			);
		}

		return array(
			'plugins' => $plugins,
			'pluginsDir' => $pluginsDir,
			'platformDir' => $platformDir,
			'appPlugins' => $appPlugins,
		);
	}

	// ── System API ───────────────────────────────────────

	/**
	 * Run PHP code in an isolated forked child. 5 second timeout.
	 * The child has no filesystem write access and no network.
	 */
	/**
	 * Add a plugin by cloning from github.com/Qbix/{name}.
	 * If the repo is private or doesn't exist, returns {private: true}.
	 */
	// ── Server management ─────────────────────────────

	static function apiListServers()
	{
		$config = self::deployConfig();
		$servers = array();
		foreach (($config['targets'] ?? array()) as $name => $t) {
			$servers[] = array_merge(array('name' => $name), $t);
		}
		return array('servers' => $servers);
	}

	static function apiAddServer($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'Name required');
		if (empty($body['host'])) return array('status' => 400, 'error' => 'Host required');

		$config = self::deployConfig();
		$config['targets'][$name] = array(
			'host' => $body['host'],
			'user' => $body['user'] ?? 'deploy',
			'path' => $body['path'] ?? '/var/www/' . $name,
			'key' => $body['key'] ?? '',
			'dirs' => array('web', 'handlers', 'classes', 'config'),
		);
		self::saveDeployConfig($config);
		return array('ok' => true, 'name' => $name);
	}

	static function apiRemoveServer($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = $body['name'] ?? '';
		$config = self::deployConfig();
		unset($config['targets'][$name]);
		self::saveDeployConfig($config);
		return array('ok' => true);
	}

	static function apiDeploy($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$target = $body['target'] ?? '';
		$config = self::deployConfig();
		$t = $config['targets'][$target] ?? null;
		if (!$t) return array('error' => "Unknown target: $target");

		$baseDir = defined('APP_DIR') ? APP_DIR : Q_WebServer::$rootDir . '..';
		$dirs = $t['dirs'] ?? array('web', 'handlers', 'classes', 'config');
		$sshKey = !empty($t['key']) ? " -e 'ssh -i " . escapeshellarg($t['key']) . "'" : '';
		$remote = $t['user'] . '@' . $t['host'] . ':' . rtrim($t['path'], '/') . '/';

		$total = 0;
		$log = '';
		foreach ($dirs as $dir) {
			$localDir = $baseDir . DS . $dir;
			if (!is_dir($localDir)) continue;
			$cmd = "rsync -avz --delete{$sshKey} "
				. escapeshellarg(rtrim($localDir, '/') . '/') . " "
				. escapeshellarg($remote . $dir . '/') . " 2>&1";
			$output = shell_exec($cmd);
			$log .= "rsync $dir/\n" . $output . "\n";
			$lines = array_filter(explode("\n", trim($output)), function ($l) {
				return $l && $l[0] !== '.' && substr($l, -1) !== '/'
					&& strpos($l, 'sending') === false && strpos($l, 'total') === false;
			});
			$total += count($lines);
		}

		return array('ok' => true, 'files' => $total, 'output' => $log);
	}

	private static function deployConfigPath()
	{
		$base = defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir);
		return $base . DS . 'config' . DS . 'deploy.json';
	}

	private static function deployConfig()
	{
		$path = self::deployConfigPath();
		return file_exists($path) ? json_decode(file_get_contents($path), true) : array('targets' => array());
	}

	private static function saveDeployConfig($config)
	{
		$path = self::deployConfigPath();
		$dir = dirname($path);
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Add a plugin by cloning from github.com/Qbix/{name}.
	 */
	static function apiAddPlugin($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$name = preg_replace('/[^A-Za-z0-9_-]/', '', $body['name'] ?? '');
		if (!$name) return array('status' => 400, 'error' => 'Plugin name required');

		// Find plugins directory
		$platformDir = null;
		if (defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) $platformDir = realpath($paths['platform']);
			}
		}
		if (!$platformDir && defined('Q_DIR')) $platformDir = Q_DIR;
		if (!$platformDir) {
			return array('error' => 'Qbix Platform not installed. Install it from the System tab first.');
		}

		$pluginsDir = $platformDir . DS . 'plugins';
		if (!is_dir($pluginsDir)) {
			$pluginsDir = dirname($platformDir) . DS . 'plugins';
		}
		if (!is_dir($pluginsDir)) {
			return array('error' => 'Plugins directory not found at ' . $pluginsDir);
		}

		$targetDir = $pluginsDir . DS . $name;
		if (is_dir($targetDir)) {
			return array('error' => "$name is already installed at $targetDir");
		}

		if (!self::which('git')) {
			return array('error' => 'git not found. Install git first.');
		}

		// Try to clone — test if accessible first with git ls-remote
		$testCmd = 'git ls-remote https://github.com/Qbix/' . escapeshellarg($name) . '.git HEAD 2>&1';
		$testOutput = shell_exec($testCmd);

		if (strpos($testOutput, 'fatal') !== false
			|| strpos($testOutput, 'not found') !== false
			|| strpos($testOutput, 'could not read') !== false
		) {
			return array('private' => true, 'name' => $name);
		}

		// Clone into plugins directory
		$cmd = 'cd ' . escapeshellarg($pluginsDir)
			. ' && git clone https://github.com/Qbix/' . escapeshellarg($name) . '.git'
			. ' ' . escapeshellarg($name) . ' 2>&1'
			. ' && cd ' . escapeshellarg($name)
			. ' && git submodule init 2>&1'
			. ' && git submodule update --recursive 2>&1';
		$output = shell_exec($cmd);

		if (!is_dir($targetDir)) {
			return array('error' => 'Clone failed', 'output' => $output);
		}

		return array('ok' => true, 'name' => $name, 'dir' => $targetDir, 'output' => $output);
	}

	static function apiPlaygroundRun($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$code = $body['code'] ?? '';
		if (!$code) return array('output' => '', 'ms' => 0);

		// Strip opening <?php tag if present
		$code = preg_replace('/^\s*<\?php\s*/i', '', $code);

		$start = microtime(true);

		// Build a wrapper that loads Q.php for access to Q::, Q_Config, etc.
		$qPath = dirname(dirname(__DIR__)) . DS . 'Q.php';
		$bootstrap = "error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);\n"
			. "require_once " . var_export($qPath, true) . ";\n";
		if (defined('APP_DIR')) {
			$bootstrap .= "if (method_exists('Q','init')) Q::init(" . var_export(APP_DIR, true) . ");\n";
		}
		$fullCode = "<?php\n" . $bootstrap . $code;

		// Write to temp file (safer than -r for complex code)
		$tmpFile = tempnam(sys_get_temp_dir(), 'qplay_');
		file_put_contents($tmpFile, $fullCode);

		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$cmd = PHP_BINARY . ' -d disable_functions=exec,shell_exec,system,passthru,popen,proc_open'
			. ',file_put_contents,fwrite,unlink,rmdir,mkdir,rename,chmod,chown'
			. ',curl_init,fsockopen,stream_socket_client'
			. ' -d disable_classes=SplFileObject'
			. ' -d open_basedir=' . escapeshellarg(sys_get_temp_dir() . ':' . dirname(dirname(__DIR__)))
			. ' -d memory_limit=32M -d max_execution_time=5'
			. ' ' . escapeshellarg($tmpFile);

		$proc = @proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			@unlink($tmpFile);
			return array('output' => '', 'error' => 'Failed to start process', 'ms' => 0);
		}
		fclose($pipes[0]);

		stream_set_timeout($pipes[1], 5);
		stream_set_timeout($pipes[2], 5);
		$output = stream_get_contents($pipes[1], 65536);
		$stderr = stream_get_contents($pipes[2], 65536);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$exitCode = proc_close($proc);
		@unlink($tmpFile);
		$ms = round((microtime(true) - $start) * 1000, 1);

		// Filter out xdebug noise from stderr
		if ($stderr) {
			$stderr = preg_replace('/^Xdebug:.*\n?/m', '', $stderr);
			$stderr = preg_replace('/^Cannot load Xdebug.*\n?/m', '', $stderr);
			$stderr = trim($stderr);
		}

		$result = array('output' => $output, 'ms' => $ms);
		if ($stderr) $result['error'] = $stderr;
		if ($exitCode !== 0 && !$stderr) $result['error'] = "Exit code: $exitCode";

		return $result;
	}

	static function apiSystemInfo()
	{
		$platformDir = defined('Q_DIR') ? Q_DIR : null;
		if (!$platformDir && defined('APP_DIR')) {
			$pathsFile = APP_DIR . DS . 'local' . DS . 'paths.json';
			if (file_exists($pathsFile)) {
				$paths = json_decode(file_get_contents($pathsFile), true);
				if (!empty($paths['platform'])) {
					$platformDir = realpath($paths['platform']) ?: $paths['platform'];
				}
			}
		}
		return array(
			'php' => PHP_VERSION,
			'os' => PHP_OS,
			'arch' => php_uname('m'),
			'extensions' => get_loaded_extensions(),
			'hasComposer' => self::which('composer') !== null,
			'hasNode' => self::which('node') !== null,
			'hasNpm' => self::which('npm') !== null,
			'hasPcntl' => function_exists('pcntl_fork'),
			'hasApcu' => function_exists('apcu_fetch'),
			'memoryLimit' => ini_get('memory_limit'),
			'platform' => $platformDir,
			'appDir' => defined('APP_DIR') ? APP_DIR : null,
			'hasGit' => self::which('git') !== null,
			'diskFree' => self::formatBytes(disk_free_space(Q_WebServer::$rootDir ?: '.')),
			'serverVersion' => defined('QBIX_SERVER_VERSION') ? 'QbixServer/' . QBIX_SERVER_VERSION : null,
			'sapi' => php_sapi_name(),
			'pid' => getmypid(),
			'uid' => function_exists('posix_getuid') ? posix_getuid() : null,
		);
	}

	/**
	 * Clone Qbix Platform from GitHub and set up local/paths.json
	 */
	static function apiInstallPlatform($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$dir = $body['dir'] ?? '';
		if (!$dir) return array('status' => 400, 'error' => 'Directory required');

		// Safety: don't overwrite existing
		if (is_dir($dir) && file_exists($dir . DS . 'Q.php')) {
			return array('error' => 'Platform already exists at ' . $dir);
		}

		// Check git
		if (!self::which('git')) {
			return array('error' => 'git not found. Install git first.');
		}

		// Clone
		$parentDir = dirname($dir);
		if (!is_dir($parentDir)) {
			@mkdir($parentDir, 0755, true);
		}
		$dirName = basename($dir);
		$cmd = 'cd ' . escapeshellarg($parentDir)
			. ' && git clone https://github.com/Qbix/Platform.git '
			. escapeshellarg($dirName) . ' 2>&1'
			. ' && cd ' . escapeshellarg($dirName)
			. ' && git submodule init 2>&1'
			. ' && git submodule update --recursive 2>&1';
		$output = shell_exec($cmd);

		if (!is_dir($dir)) {
			return array('error' => 'Clone failed', 'output' => $output);
		}

		// Set up local/paths.json pointing to the platform
		if (defined('APP_DIR')) {
			$localDir = APP_DIR . DS . 'local';
			if (!is_dir($localDir)) @mkdir($localDir, 0755, true);
			$pathsFile = $localDir . DS . 'paths.json';
			$platformPath = realpath($dir) ?: $dir;
			// Use the platform subdirectory if it exists (Qbix convention)
			if (is_dir($platformPath . DS . 'platform')) {
				$platformPath = $platformPath . DS . 'platform';
			}
			$paths = file_exists($pathsFile)
				? json_decode(file_get_contents($pathsFile), true) : array();
			$paths['platform'] = $platformPath;
			file_put_contents($pathsFile, json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		return array(
			'ok' => true,
			'dir' => realpath($dir),
			'output' => $output,
		);
	}

	static function apiServeApp($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$appName = preg_replace('/[^A-Za-z0-9_]/', '', $body['app'] ?? '');
		$enable = !empty($body['enable']);

		if (!$appName) return array('status' => 400, 'error' => 'App name required');

		$appsDir = self::appsDir();
		$appDir = $appsDir . DS . $appName;
		$webDir = $appDir . DS . 'web';

		if ($enable) {
			if (!is_dir($webDir)) {
				return array('status' => 404, 'error' => "No web/ directory in $appName");
			}
			$root = realpath($webDir);
			if (!$root) return array('status' => 500, 'error' => 'Cannot resolve path');
			Q_WebServer::$rootDir = rtrim(str_replace(array('/', '\\'), DS, $root), DS) . DS;
			self::$servingApp = $appName;
			return array('ok' => true, 'serving' => $appName, 'rootDir' => Q_WebServer::$rootDir);
		} else {
			if (defined('APP_DIR')) {
				$orig = APP_DIR . DS . 'web';
				if (is_dir($orig)) {
					Q_WebServer::$rootDir = rtrim(str_replace(array('/', '\\'), DS, realpath($orig)), DS) . DS;
				}
			}
			self::$servingApp = null;
			return array('ok' => true, 'serving' => null, 'rootDir' => Q_WebServer::$rootDir);
		}
	}

	/**
	 * Change the apps directory. Persisted in panel config.
	 */
	static function apiSetAppsDir($parsed)
	{
		$body = json_decode($parsed['body'], true);
		$dir = $body['dir'] ?? '';
		if (!$dir || !is_dir($dir)) {
			return array('status' => 400, 'error' => 'Directory does not exist: ' . $dir);
		}
		// Persist in config
		Q_Config::set('Q', 'webserver', 'panel', 'appsDir', realpath($dir));
		// Also save to panel config file
		$configPath = self::panelConfigPath();
		$config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : array();
		$config['appsDir'] = realpath($dir);
		$d = dirname($configPath);
		if (!is_dir($d)) @mkdir($d, 0700, true);
		@file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
		return array('ok' => true, 'appsDir' => realpath($dir));
	}

	static function apiOpenFolder($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$dir = $body['dir'] ?? '';
		$editor = $body['editor'] ?? 'folder'; // folder, vscode, textmate

		if (!$dir || !is_dir($dir)) {
			return array('status' => 400, 'error' => 'Invalid directory');
		}

		$os = PHP_OS_FAMILY;
		switch ($editor) {
			case 'vscode':
				$cmd = 'code ' . escapeshellarg($dir);
				break;
			case 'textmate':
				$cmd = 'mate ' . escapeshellarg($dir);
				break;
			default: // open in file manager
				if ($os === 'Darwin') {
					$cmd = 'open ' . escapeshellarg($dir);
				} elseif ($os === 'Windows') {
					$cmd = 'explorer ' . escapeshellarg(str_replace('/', '\\', $dir));
				} else {
					$cmd = 'xdg-open ' . escapeshellarg($dir);
				}
		}

		exec($cmd . ' 2>&1 &');
		return array('opened' => $dir, 'editor' => $editor);
	}

	// ── Framework Package Management ─────────────────────

	/**
	 * Get packages/plugins for a detected framework.
	 * Reads composer.lock, wp plugin dirs, etc.
	 */
	static function apiFrameworkPackages($parsed)
	{
		$query = [];
		if (!empty($parsed['query'])) parse_str($parsed['query'], $query);
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? $query['framework'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$packages = [];

		switch ($framework) {

		case 'exponential':
			// Its own directory is the project; listed read-only (see
			// composerWriteRefused()).
			$found = Q_WebServer_Framework::find('exponential', is_string($query['dir'] ?? null) ? $query['dir'] : null);
			if (!$found) return ['status' => 404, 'error' => 'No such application here'];
			$projectDir = $found['dir'];
			// fall through: the same composer.json / composer.lock reading
		case 'laravel':
		case 'symfony':
			// Read composer.lock for installed packages
			$lockFile = $projectDir . '/composer.lock';
			$jsonFile = $projectDir . '/composer.json';

			$required = [];
			if (is_file($jsonFile)) {
				$cj = json_decode(file_get_contents($jsonFile), true);
				foreach (($cj['require'] ?? []) as $pkg => $ver) {
					$required[$pkg] = $ver;
				}
				foreach (($cj['require-dev'] ?? []) as $pkg => $ver) {
					$required[$pkg] = $ver . ' (dev)';
				}
			}

			if (is_file($lockFile)) {
				$lock = json_decode(file_get_contents($lockFile), true);
				foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
					$name = $pkg['name'] ?? '';
					$isDev = in_array($pkg, $lock['packages-dev'] ?? []);
					$packages[] = [
						'name' => $name,
						'version' => $pkg['version'] ?? '',
						'description' => $pkg['description'] ?? '',
						'type' => $pkg['type'] ?? 'library',
						'constraint' => $required[$name] ?? null,
						'dev' => $isDev,
						'homepage' => $pkg['homepage'] ?? null,
					];
				}
			} elseif (!empty($required)) {
				// No lock file, show requirements
				foreach ($required as $pkg => $ver) {
					$packages[] = [
						'name' => $pkg,
						'version' => null,
						'constraint' => $ver,
						'description' => '(not installed — run composer install)',
					];
				}
			}
			return ['framework' => $framework, 'packages' => $packages, 'source' => is_file($lockFile) ? 'composer.lock' : 'composer.json'];

		case 'wordpress':
			// Try wp-cli first
			$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (self::which($p)) {
					$wpCli = $p; break;
				}
			}

			if ($wpCli) {
				// wp-cli gives structured JSON
				$pluginJson = shell_exec("cd " . escapeshellarg($wpDir) . " && $wpCli plugin list --format=json 2>/dev/null");
				$plugins = json_decode($pluginJson ?: '[]', true) ?: [];
				foreach ($plugins as $p) {
					$packages[] = [
						'name' => $p['name'] ?? '',
						'version' => $p['version'] ?? '',
						'status' => $p['status'] ?? '',
						'update' => $p['update'] ?? 'none',
						'type' => 'plugin',
					];
				}

				$themeJson = shell_exec("cd " . escapeshellarg($wpDir) . " && $wpCli theme list --format=json 2>/dev/null");
				$themes = json_decode($themeJson ?: '[]', true) ?: [];
				foreach ($themes as $t) {
					$packages[] = [
						'name' => $t['name'] ?? '',
						'version' => $t['version'] ?? '',
						'status' => $t['status'] ?? '',
						'update' => $t['update'] ?? 'none',
						'type' => 'theme',
					];
				}
				return ['framework' => 'wordpress', 'packages' => $packages, 'source' => 'wp-cli'];
			}

			// Fallback: scan wp-content/plugins/ directory
			$pluginsDir = $wpDir . '/wp-content/plugins';
			if (is_dir($pluginsDir)) {
				foreach (scandir($pluginsDir) as $d) {
					if ($d === '.' || $d === '..' || !is_dir($pluginsDir . '/' . $d)) continue;
					// Read plugin header from main PHP file
					$mainFile = $pluginsDir . '/' . $d . '/' . $d . '.php';
					if (!is_file($mainFile)) {
						// Try first .php file
						foreach (glob($pluginsDir . '/' . $d . '/*.php') as $f) {
							$mainFile = $f; break;
						}
					}
					$info = ['name' => $d, 'type' => 'plugin'];
					if (is_file($mainFile)) {
						$header = file_get_contents($mainFile, false, null, 0, 4096);
						if (preg_match('/Plugin Name:\s*(.+)/i', $header, $m)) $info['title'] = trim($m[1]);
						if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
						if (preg_match('/Description:\s*(.+)/i', $header, $m)) $info['description'] = trim($m[1]);
					}
					$packages[] = $info;
				}
			}

			// Scan themes too
			$themesDir = $wpDir . '/wp-content/themes';
			if (is_dir($themesDir)) {
				foreach (scandir($themesDir) as $d) {
					if ($d === '.' || $d === '..' || !is_dir($themesDir . '/' . $d)) continue;
					$styleFile = $themesDir . '/' . $d . '/style.css';
					$info = ['name' => $d, 'type' => 'theme'];
					if (is_file($styleFile)) {
						$header = file_get_contents($styleFile, false, null, 0, 2048);
						if (preg_match('/Theme Name:\s*(.+)/i', $header, $m)) $info['title'] = trim($m[1]);
						if (preg_match('/Version:\s*(.+)/i', $header, $m)) $info['version'] = trim($m[1]);
					}
					$packages[] = $info;
				}
			}
			return ['framework' => 'wordpress', 'packages' => $packages, 'source' => 'filesystem'];

		case 'drupal':
			$drush = null;
			foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
				if (self::which($p)) {
					$drush = $p; break;
				}
			}
			if ($drush) {
				$moduleJson = shell_exec("cd " . escapeshellarg($projectDir) . " && $drush pm:list --format=json 2>/dev/null");
				$modules = json_decode($moduleJson ?: '{}', true) ?: [];
				foreach ($modules as $name => $info) {
					$packages[] = [
						'name' => $name,
						'version' => $info['version'] ?? '',
						'status' => $info['status'] ?? '',
						'type' => $info['type'] ?? 'module',
						'description' => $info['display_name'] ?? $name,
					];
				}
				return ['framework' => 'drupal', 'packages' => $packages, 'source' => 'drush'];
			}
			// Fallback to composer
			return self::apiFrameworkPackages(array_merge($parsed, ['body' => json_encode(['framework' => 'symfony'])]));

		case 'joomla':
			// Read administrator/cache or manifest files
			$extDir = rtrim($rootDir, '/') . '/administrator/manifests/packages';
			if (is_dir($extDir)) {
				foreach (glob($extDir . '/*.xml') as $xml) {
					$info = ['name' => basename($xml, '.xml'), 'type' => 'package'];
					$content = file_get_contents($xml, false, null, 0, 4096);
					if (preg_match('/<version>(.+?)<\/version>/i', $content, $m)) $info['version'] = $m[1];
					if (preg_match('/<name>(.+?)<\/name>/i', $content, $m)) $info['title'] = $m[1];
					$packages[] = $info;
				}
			}
			return ['framework' => 'joomla', 'packages' => $packages, 'source' => 'manifests'];

		default:
			return ['status' => 400, 'error' => 'Unknown framework'];
		}
	}

	/**
	 * Run a composer command (require, update, remove).
	 */
	static function apiFrameworkComposer($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? '';
		$package = $body['package'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		if (!is_file($projectDir . '/composer.json')) {
			return ['status' => 400, 'error' => 'No composer.json found'];
		}
		if ($refused = self::composerWriteRefused($projectDir)) return $refused;

		$allowed = ['update', 'install', 'dump-autoload'];
		if ($package && in_array($action, ['require', 'remove', 'update'])) {
			// Validate package name (vendor/package format)
			if (!preg_match('#^[a-z0-9]([a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$#i', $package)) {
				return ['status' => 400, 'error' => 'Invalid package name'];
			}
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer $action " . escapeshellarg($package) . " --no-interaction 2>&1";
		} elseif (in_array($action, $allowed)) {
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer $action --no-interaction 2>&1";
		} else {
			return ['status' => 400, 'error' => 'Invalid action'];
		}

		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "composer $action" . ($package ? " $package" : '')];
	}

	/**
	 * Unified package management: install, remove, enable, disable, update.
	 * Each framework maps these to its own CLI tool.
	 */
	static function apiFrameworkPkgAction($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$action = $body['action'] ?? '';
		$package = $body['package'] ?? '';

		if (!$framework || !$action || !$package) {
			return ['status' => 400, 'error' => 'Missing framework, action, or package'];
		}

		// Validate package name to prevent injection
		if (!preg_match('#^[a-zA-Z0-9/_.:@^~>=<*-]+$#', $package)) {
			return ['status' => 400, 'error' => 'Invalid package name'];
		}

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		if ($refused = self::composerWriteRefused($projectDir)) return $refused;
		$cmd = null;
		$cwd = $projectDir;

		switch ($framework) {

		case 'laravel':
		case 'symfony':
			// Composer-based
			switch ($action) {
				case 'install':
				case 'require':
					$cmd = "composer require " . escapeshellarg($package) . " --no-interaction"; break;
				case 'remove':
					$cmd = "composer remove " . escapeshellarg($package) . " --no-interaction"; break;
				case 'update':
					$cmd = "composer update " . escapeshellarg($package) . " --no-interaction"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for $framework"];
			}
			break;

		case 'wordpress':
			$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
			$wpCli = null;
			foreach (['wp', $projectDir . '/vendor/bin/wp'] as $p) {
				if (self::which($p)) {
					$wpCli = $p; break;
				}
			}
			if (!$wpCli) {
				return ['status' => 400, 'error' => 'wp-cli not found. Install it: https://wp-cli.org/'];
			}
			$cwd = $wpDir;
			$pathFlag = ' --path=' . escapeshellarg($wpDir);

			// Determine if it's a theme or plugin from package name prefix
			$type = 'plugin';
			if (strpos($package, 'theme:') === 0) {
				$type = 'theme';
				$package = substr($package, 6);
			}

			switch ($action) {
				case 'install':
					$cmd = "$wpCli $type install " . escapeshellarg($package) . "$pathFlag"; break;
				case 'activate':
					$cmd = "$wpCli $type activate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'deactivate':
					$cmd = "$wpCli $type deactivate " . escapeshellarg($package) . "$pathFlag"; break;
				case 'remove':
				case 'delete':
					$cmd = "$wpCli $type delete " . escapeshellarg($package) . "$pathFlag"; break;
				case 'update':
					$cmd = "$wpCli $type update " . escapeshellarg($package) . "$pathFlag"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for WordPress"];
			}
			break;

		case 'drupal':
			$drush = null;
			foreach (['drush', $projectDir . '/vendor/bin/drush'] as $p) {
				if (self::which($p)) {
					$drush = $p; break;
				}
			}

			switch ($action) {
				case 'install':
				case 'enable':
					if ($drush) {
						$cmd = "$drush pm:install " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer require " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'remove':
				case 'uninstall':
					if ($drush) {
						$cmd = "$drush pm:uninstall " . escapeshellarg($package) . " -y";
					} else {
						$cmd = "composer remove " . escapeshellarg("drupal/$package") . " --no-interaction";
					}
					break;
				case 'update':
					$cmd = "composer update " . escapeshellarg("drupal/$package") . " --no-interaction"; break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for Drupal"];
			}
			break;

		case 'joomla':
			switch ($action) {
				case 'install':
					$cmd = "php cli/joomla.php extension:install --package=" . escapeshellarg($package); break;
				case 'remove':
					$cmd = "php cli/joomla.php extension:remove " . escapeshellarg($package); break;
				default:
					return ['status' => 400, 'error' => "Unknown action '$action' for Joomla"];
			}
			$cwd = rtrim($rootDir, '/');
			break;

		default:
			return ['status' => 400, 'error' => "Unknown framework '$framework'"];
		}

		$fullCmd = "cd " . escapeshellarg($cwd) . " && $cmd 2>&1";
		$output = shell_exec($fullCmd);
		return ['output' => $output, 'cmd' => $cmd];
	}

	// ── Package Download (all frameworks) ────────────────

	/**
	 * Download a plugin/package from a URL (GitHub, zip, etc.)
	 * or install via composer/npm.
	 */
	static function apiFrameworkPkgDownload($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = $body['framework'] ?? '';
		$source = trim($body['source'] ?? '');
		$target = $body['target'] ?? '';

		if (!$source) return ['status' => 400, 'error' => 'No source URL provided'];

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));
		$output = '';
		$cmd = '';

		// Determine target directory based on framework
		switch ($framework) {
			case 'qbix':
				// Qbix plugins go into platform/plugins/
				$pluginsDir = null;
				foreach ([
					$projectDir . '/platform/plugins',
					dirname($projectDir) . '/platform/plugins',
				] as $pd) {
					if (is_dir($pd)) { $pluginsDir = $pd; break; }
				}
				if (!$pluginsDir) {
					return ['status' => 400, 'error' => 'Cannot find platform/plugins directory'];
				}
				$targetDir = $pluginsDir . '/' . ($target ?: basename($source, '.git'));
				break;
			case 'wordpress':
				$wpDir = is_file($rootDir . 'wp-config.php') ? rtrim($rootDir, '/') : $projectDir;
				$targetDir = $wpDir . '/wp-content/plugins/' . ($target ?: basename($source, '.git'));
				break;
			case 'drupal':
				$targetDir = $projectDir . '/web/modules/custom/' . ($target ?: basename($source, '.git'));
				if (!is_dir(dirname($targetDir))) {
					$targetDir = $projectDir . '/modules/custom/' . ($target ?: basename($source, '.git'));
				}
				break;
			case 'joomla':
				$targetDir = rtrim($rootDir, '/') . '/plugins/' . ($target ?: basename($source, '.git'));
				break;
			default:
				// Laravel/Symfony: use composer require instead of git clone
				if (preg_match('#^[a-z0-9]([a-z0-9._-]*/)[a-z0-9][a-z0-9._-]*$#i', $source)) {
					$cmd = "cd " . escapeshellarg($projectDir) . " && composer require " . escapeshellarg($source) . " --no-interaction 2>&1";
					$output = shell_exec($cmd);
					return ['output' => $output, 'cmd' => "composer require $source"];
				}
				$targetDir = $projectDir . '/plugins/' . ($target ?: basename($source, '.git'));
		}

		// If it's a GitHub URL or git URL, clone it
		if (preg_match('#^(https?://|git@)#', $source)) {
			if (is_dir($targetDir)) {
				// Already exists — try git pull
				$cmd = "cd " . escapeshellarg($targetDir) . " && git pull 2>&1";
			} else {
				$cmd = "git clone --depth 1 " . escapeshellarg($source) . " " . escapeshellarg($targetDir) . " 2>&1";
			}
			$output = shell_exec($cmd);

			// Check for package.json and composer.json in the downloaded plugin
			$extras = [];
			if (is_file($targetDir . '/package.json')) {
				$extras[] = 'Has package.json — run npm install from the panel';
			}
			if (is_file($targetDir . '/composer.json')) {
				$extras[] = 'Has composer.json — run composer install from the panel';
			}
			if (is_file($targetDir . '/config/plugin.json')) {
				$extras[] = 'Qbix plugin detected — run the installer to set up DB schema';
			}
			if ($extras) {
				$output .= "\n\n" . implode("\n", $extras);
			}

			return ['output' => $output, 'cmd' => $cmd, 'dir' => $targetDir];
		}

		// If it looks like a composer package name
		if (preg_match('#^[a-z0-9]([a-z0-9._-]*/)[a-z0-9][a-z0-9._-]*$#i', $source)) {
			$cmd = "cd " . escapeshellarg($projectDir) . " && composer require " . escapeshellarg($source) . " --no-interaction 2>&1";
			$output = shell_exec($cmd);
			return ['output' => $output, 'cmd' => "composer require $source"];
		}

		return ['status' => 400, 'error' => 'Source must be a git URL (https:// or git@) or a composer package name (vendor/package)'];
	}

	// ── Qbix Installer & NPM ────────────────────────────

	/**
	 * Run the Qbix installer (install.php) with various flags.
	 */
	static function apiQbixInstaller($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? '';
		$plugin = $body['plugin'] ?? '';

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// Find install.php
		$installScript = null;
		foreach ([
			$projectDir . '/scripts/Q/install.php',
			dirname($projectDir) . '/scripts/Q/install.php',
		] as $p) {
			if (is_file($p)) { $installScript = $p; break; }
		}

		if (!$installScript) {
			return ['status' => 400, 'error' => 'Cannot find scripts/Q/install.php. Is this a Qbix app?'];
		}

		$appDir = dirname(dirname($installScript));
		$allowed = ['--all', '--plugins', '--app', '--composer', '--npm'];

		switch ($action) {
			case 'all':
				$flags = '--all';
				break;
			case 'plugins':
				$flags = '--plugins';
				break;
			case 'app':
				$flags = '--app';
				break;
			case 'plugin':
				if (!$plugin || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $plugin)) {
					return ['status' => 400, 'error' => 'Invalid plugin name'];
				}
				$flags = '-p ' . escapeshellarg($plugin);
				break;
			case 'composer':
				$flags = '--composer';
				break;
			case 'npm':
				$flags = '--npm';
				break;
			case 'plugin-full':
				// Install a single plugin with its SQL + composer + npm
				if (!$plugin || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $plugin)) {
					return ['status' => 400, 'error' => 'Invalid plugin name'];
				}
				$flags = '-p ' . escapeshellarg($plugin) . ' --composer --npm';
				break;
			default:
				return ['status' => 400, 'error' => "Unknown action: $action. Use: all, plugins, app, plugin, composer, npm, plugin-full"];
		}

		$cmd = "cd " . escapeshellarg($appDir) . " && php " . escapeshellarg($installScript) . " $flags 2>&1";
		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "php scripts/Q/install.php $flags"];
	}

	/**
	 * Run npm commands for Qbix plugins or the app.
	 */
	static function apiQbixNpm($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$action = $body['action'] ?? 'install';
		$target = $body['target'] ?? '';  // plugin name or 'app' or 'platform'

		$rootDir = Q_WebServer::$rootDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// Determine directory
		$dir = null;
		if ($target === 'app' || $target === '') {
			$dir = $projectDir;
		} elseif ($target === 'platform') {
			foreach ([
				$projectDir . '/platform',
				dirname($projectDir) . '/platform',
			] as $pd) {
				if (is_dir($pd)) { $dir = $pd; break; }
			}
		} else {
			// Plugin name
			foreach ([
				$projectDir . '/platform/plugins/' . $target,
				dirname($projectDir) . '/platform/plugins/' . $target,
			] as $pd) {
				if (is_dir($pd)) { $dir = $pd; break; }
			}
		}

		if (!$dir || !is_dir($dir)) {
			return ['status' => 400, 'error' => "Directory not found for target: $target"];
		}

		if (!is_file($dir . '/package.json')) {
			return ['status' => 400, 'error' => "No package.json in $dir"];
		}

		$hasNpm = (bool) self::which('npm');
		if (!$hasNpm) {
			return ['status' => 400, 'error' => 'npm is not installed on this system'];
		}

		$allowed = ['install', 'update', 'audit', 'ls'];
		if (!in_array($action, $allowed)) {
			return ['status' => 400, 'error' => "Invalid npm action: $action"];
		}

		$cmd = "cd " . escapeshellarg($dir) . " && npm $action --ignore-scripts 2>&1";
		$output = shell_exec($cmd);
		return ['output' => $output, 'cmd' => "npm $action", 'dir' => $dir];
	}

	// ── Qbix Plugin Management API ──────────────────────

	/**
	 * Parse a Qbix JSON file (tolerant of comments and trailing commas).
	 */
	private static function parseQbixJson($path)
	{
		if (!is_file($path)) return null;
		$raw = file_get_contents($path);
		// Remove block comments
		$raw = preg_replace('#/\*.*?\*/#s', '', $raw);
		// Remove line comments (outside strings)
		$lines = explode("\n", $raw);
		$cleaned = [];
		foreach ($lines as $line) {
			$inStr = false;
			$out = '';
			for ($i = 0; $i < strlen($line); $i++) {
				$ch = $line[$i];
				if ($ch === '"' && ($i === 0 || $line[$i-1] !== '\\'))
					$inStr = !$inStr;
				if (!$inStr && $ch === '/' && $i+1 < strlen($line) && $line[$i+1] === '/')
					break;
				$out .= $ch;
			}
			$cleaned[] = $out;
		}
		$raw = implode("\n", $cleaned);
		// Remove trailing commas before ] or }
		$raw = preg_replace('/,\s*([\]\}])/', '$1', $raw);
		return json_decode($raw, true);
	}

	/**
	 * Scan all plugin sources and return a unified view.
	 */
	static function apiQbixPlugins()
	{
		$rootDir = Q_WebServer::$rootDir;
		$serverDir = Q_WebServer::$serverDir;
		$projectDir = dirname(rtrim($rootDir, DIRECTORY_SEPARATOR));

		// 1. Find the app config
		$appJson = null;
		$appDir = null;
		foreach ([
			$projectDir . '/config/app.json',
			$rootDir . '../config/app.json',
		] as $p) {
			if (is_file($p)) {
				$appJson = self::parseQbixJson($p);
				$appDir = dirname(dirname($p));
				break;
			}
		}

		$declaredPlugins = [];
		$appName = null;
		$appVersion = null;
		if ($appJson) {
			$declaredPlugins = $appJson['Q']['plugins'] ?? [];
			$appName = $appJson['Q']['app'] ?? basename($appDir);
			$appVersion = $appJson['Q']['appInfo']['version'] ?? null;
		}

		// 2. Find local/plugins.json (installed filesystem versions)
		$localPlugins = [];
		foreach ([
			$appDir . '/local/plugins.json',
			$projectDir . '/local/plugins.json',
		] as $p) {
			if (is_file($p)) {
				$lp = self::parseQbixJson($p);
				if ($lp) {
					$localPlugins = $lp['Q']['pluginLocal'] ?? $lp;
				}
				break;
			}
		}

		// 3. Scan platform/plugins/ for available plugins
		$available = [];
		$platformDirs = [
			$projectDir . '/platform/plugins',
			$appDir . '/../platform/plugins',
			dirname($serverDir) . '/Platform/platform/plugins',
		];
		$pluginsDir = null;
		foreach ($platformDirs as $pd) {
			if (is_dir($pd)) {
				$pluginsDir = realpath($pd);
				break;
			}
		}

		if ($pluginsDir) {
			foreach (scandir($pluginsDir) as $pName) {
				if ($pName === '.' || $pName === '..') continue;
				$pDir = $pluginsDir . '/' . $pName;
				if (!is_dir($pDir)) continue;
				$pJson = self::parseQbixJson($pDir . '/config/plugin.json');
				if ($pJson) {
					$pi = $pJson['Q']['pluginInfo'][$pName] ?? [];
					$available[$pName] = [
						'version' => $pi['version'] ?? null,
						'compatible' => $pi['compatible'] ?? null,
						'requires' => $pi['requires'] ?? [],
						'connections' => $pi['connections'] ?? [],
						'dir' => $pDir,
					];
				} else {
					// Directory exists but no parseable plugin.json
					$available[$pName] = [
						'version' => null,
						'dir' => $pDir,
						'noConfig' => true,
					];
				}
			}
		}

		// 4. Check database for schema versions
		$dbPlugins = [];
		$dbError = null;
		try {
			// Try to find SQLite databases in the app
			$dbPaths = [];
			foreach ([
				$appDir . '/local',
				$projectDir . '/local',
				$projectDir . '/data',
				$rootDir . '../data',
			] as $dir) {
				if (!is_dir($dir)) continue;
				foreach (glob($dir . '/*.db') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
				foreach (glob($dir . '/*.sqlite') as $dbFile) {
					$dbPaths[] = $dbFile;
				}
			}

			foreach ($dbPaths as $dbPath) {
				try {
					$pdo = new \PDO('sqlite:' . $dbPath);
					$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

					// Check for Q_plugin table (with any prefix)
					$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%Q_plugin'")->fetchAll(\PDO::FETCH_COLUMN);
					foreach ($tables as $table) {
						$rows = $pdo->query("SELECT * FROM \"$table\"")->fetchAll(\PDO::FETCH_ASSOC);
						foreach ($rows as $row) {
							$name = $row['plugin'] ?? null;
							if (!$name) continue;
							$dbPlugins[$name] = [
								'schemaVersion' => $row['version'] ?? null,
								'schemaPHPVersion' => $row['versionPHP'] ?? null,
								'extra' => json_decode($row['extra'] ?? '{}', true),
								'db' => basename($dbPath),
								'table' => $table,
							];
						}
					}
				} catch (\Exception $e) {
					// Skip this database
				}
			}
		} catch (\Exception $e) {
			$dbError = $e->getMessage();
		}

		// 5. Build unified plugin list
		$plugins = [];
		$allNames = array_unique(array_merge(
			$declaredPlugins,
			array_keys($localPlugins),
			array_keys($available),
			array_keys($dbPlugins)
		));
		sort($allNames);

		foreach ($allNames as $name) {
			$entry = [
				'name' => $name,
				'declared' => in_array($name, $declaredPlugins),
				'availableVersion' => $available[$name]['version'] ?? null,
				'installedVersion' => $localPlugins[$name]['version'] ?? null,
				'schemaVersion' => $dbPlugins[$name]['schemaVersion'] ?? null,
				'schemaPHPVersion' => $dbPlugins[$name]['schemaPHPVersion'] ?? null,
				'extra' => $dbPlugins[$name]['extra'] ?? null,
				'db' => $dbPlugins[$name]['db'] ?? null,
				'requires' => $available[$name]['requires'] ?? [],
				'connections' => $available[$name]['connections'] ?? [],
				'hasDir' => isset($available[$name]['dir']),
				'hasPackageJson' => $available[$name]['hasPackageJson'] ?? false,
				'hasComposerJson' => $available[$name]['hasComposerJson'] ?? false,
				'hasNodeModules' => $available[$name]['hasNodeModules'] ?? false,
				'hasVendor' => $available[$name]['hasVendor'] ?? false,
			];
			// Status
			if (!$entry['availableVersion'] && !$entry['hasDir']) {
				$entry['status'] = 'missing';  // declared but not on filesystem
			} elseif (!$entry['installedVersion']) {
				$entry['status'] = 'available';  // on filesystem, not installed
			} elseif ($entry['availableVersion']
				&& version_compare($entry['installedVersion'], $entry['availableVersion'], '<')) {
				$entry['status'] = 'upgradable';
			} else {
				$entry['status'] = 'installed';
			}
			// Schema status
			if ($entry['schemaVersion'] && $entry['availableVersion']
				&& version_compare($entry['schemaVersion'], $entry['availableVersion'], '<')) {
				$entry['schemaStatus'] = 'outdated';
			} elseif ($entry['schemaVersion']) {
				$entry['schemaStatus'] = 'current';
			} else {
				$entry['schemaStatus'] = 'none';
			}
			$plugins[] = $entry;
		}

		return [
			'app' => $appName,
			'appVersion' => $appVersion,
			'pluginsDir' => $pluginsDir,
			'plugins' => $plugins,
			'dbError' => $dbError,
		];
	}

	static function apiQbixPluginInstall($parsed)
	{
		// Placeholder — full install requires Q_Plugin::installPlugin()
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['plugin'] ?? '';
		if (!$name) return ['status' => 400, 'error' => 'Missing plugin name'];
		return ['status' => 501, 'error' => 'Plugin installation from the panel requires the Qbix Platform. Use: php scripts/Q/install.php --plugin=' . $name];
	}

	static function apiQbixPluginSchema($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['plugin'] ?? '';
		if (!$name) return ['status' => 400, 'error' => 'Missing plugin name'];

		// Find the plugin's SQL scripts
		$result = self::apiQbixPlugins();
		$scripts = [];
		foreach ($result['plugins'] as $p) {
			if ($p['name'] === $name && $p['hasDir']) {
				$pluginsDir = $result['pluginsDir'];
				$scriptsDir = $pluginsDir . '/' . $name . '/scripts/' . $name;
				if (is_dir($scriptsDir)) {
					foreach (scandir($scriptsDir) as $f) {
						if ($f === '.' || $f === '..') continue;
						$scripts[] = $f;
					}
					sort($scripts);
				}
				break;
			}
		}

		return [
			'plugin' => $name,
			'scripts' => $scripts,
			'schemaVersion' => $result['plugins'][array_search($name, array_column($result['plugins'], 'name'))]['schemaVersion'] ?? null,
		];
	}

	// ── Frameworks API ───────────────────────────────────

	static function apiFrameworks()
	{
		// One registry for this tab, the Apps tab and the autohost; see
		// Q_WebServer_Framework. Plain PHP sites and bare Composer projects
		// are left to the Apps tab: they have no framework tools to offer.
		$out = array();
		foreach (Q_WebServer_Framework::scan() as $d) {
			if (in_array($d['kind'], array('php', 'composer'), true)) continue;
			$out[] = $d;
		}
		return ['frameworks' => $out];
	}

	static function apiFrameworkRun($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$framework = is_string($body['framework'] ?? null) ? $body['framework'] : '';
		$cmd = is_string($body['cmd'] ?? null) ? $body['cmd'] : '';
		$dir = is_string($body['dir'] ?? null) ? $body['dir'] : null;
		if (!$framework || !$cmd) return ['status' => 400, 'error' => 'Missing framework or cmd'];
		// A directory from the request must be one the scan found: never an
		// arbitrary path to run commands in.
		if ($dir !== null) {
			$known = false;
			foreach (Q_WebServer_Framework::scan() as $d) {
				if ($d['dir'] === $dir and $d['kind'] === $framework) { $known = true; break; }
			}
			if (!$known) return ['status' => 404, 'error' => 'No such application here'];
		}
		// Only a command the detector listed runs, as an argv array with no
		// shell, in the application's own directory; a disruptive one needs
		// confirm: true.
		return Q_WebServer_Framework::run($framework, $cmd, $dir, !empty($body['confirm']));
	}

	/**
	 * Why composer must not change the project at $dir, or null when it may:
	 * a detector marked it composerWrite false (its packages may be live git
	 * checkouts), and Q.panel.allowComposerWrite is not set.
	 */
	static function composerWriteRefused($dir)
	{
		if (Q_Config::get('Q', 'panel', 'allowComposerWrite', false)) return null;
		foreach (array($dir, rtrim(Q_WebServer::$rootDir, DIRECTORY_SEPARATOR)) as $candidate) {
			$d = Q_WebServer_Framework::detect($candidate);
			if ($d and $d['composerWrite'] === false) {
				return ['status' => 403, 'error' => $d['name'] . ' installations are not changed with composer from the panel'
					. ' (their packages may be live checkouts); set Q.panel.allowComposerWrite to allow it.'];
			}
		}
		return null;
	}

	// ── Helpers ──────────────────────────────────────────

	// ── Domains API ──────────────────────────────────────

	static function apiListDomains()
	{
		// Config's and the panel's own, one list (Q_WebServer_Domains).
		$domains = Q_WebServer_Domains::records();
		$certDir = Q_Config::get('Q', 'webserver', 'tls', 'certDir', 'local/certs');
		$result = [];
		foreach ($domains as $name => $conf) {
			$certPath = rtrim($certDir, '/') . '/' . $name . '/fullchain.pem';
			$entry = [
				'domain' => $name,
				'root' => $conf['root'] ?? null,
				'app' => $conf['app'] ?? null,
				'tls' => $conf['tls'] ?? 'none',
				'aliases' => $conf['aliases'] ?? [],
				'subdomains' => (object) ($conf['subdomains'] ?? []),
				'rootResolved' => Q_WebServer_Domains::realDir($conf['root'] ?? null),
				'status' => $conf['status'] ?? 'active',
				'since' => $conf['since'] ?? null,
				'note' => $conf['note'] ?? '',
				'source' => $conf['source'] ?? 'panel',
				'redirects' => is_array($conf['redirects'] ?? null) ? $conf['redirects'] : null,
				'hsts' => is_array($conf['hsts'] ?? null) ? $conf['hsts'] : null,
				'errorDocs' => is_array($conf['errorDocs'] ?? null) ? (object) $conf['errorDocs'] : null,
			];
			if (is_file($certPath)) {
				$expiry = Q_WebServer_Acme::certExpiry($certPath);
				$entry['certExpires'] = $expiry ? date('Y-m-d', $expiry) : null;
				$entry['certDaysLeft'] = $expiry ? max(0, (int) (($expiry - time()) / 86400)) : null;
				$entry['certDomains'] = Q_WebServer_Acme::certDomains($certPath);
				$entry['certStatus'] = ($expiry && $expiry > time())
					? ($expiry - time() < 30 * 86400 ? 'expiring' : 'valid')
					: 'expired';
			} else {
				$entry['certStatus'] = 'none';
			}
			$result[] = $entry;
		}
		// Also check for certs without config entries
		if (is_dir($certDir)) {
			foreach (scandir($certDir) as $d) {
				if ($d === '.' || $d === '..' || $d === 'account.pem') continue;
				if (!is_dir($certDir . '/' . $d)) continue;
				if (isset($domains[$d])) continue; // already listed
				$certPath = $certDir . '/' . $d . '/fullchain.pem';
				if (!is_file($certPath)) continue;
				$expiry = Q_WebServer_Acme::certExpiry($certPath);
				$result[] = [
					'domain' => $d,
					'tls' => 'manual',
					'certExpires' => $expiry ? date('Y-m-d', $expiry) : null,
					'certDaysLeft' => $expiry ? max(0, (int) (($expiry - time()) / 86400)) : null,
					'certStatus' => ($expiry && $expiry > time()) ? 'valid' : 'expired',
					'certDomains' => Q_WebServer_Acme::certDomains($certPath),
					'unconfigured' => true,
				];
			}
		}
		return ['domains' => $result];
	}

	static function apiAddDomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		// One validator, shared with the autohost path. The copy that stood
		// here accepted a label ending in a hyphen, and a name ending in a
		// newline, and the domain goes on to be written into a config file.
		if (!$domain || !Q_WebServer_Autohost::validateHostname($domain)) {
			return ['status' => 400, 'error' => 'Invalid domain name'];
		}
		// No root given: the standard layout, <base>/<domain>/<docDirName>.
		// A missing default folder is only created when asked, with confirm.
		$domainKey = strtolower($domain);
		$records = Q_WebServer_Domains::records();
		if (empty($body['root']) and empty($records[$domainKey]['root'])) {
			$default = Q_WebServer_Domains::defaultRoot($domainKey);
			$made = self::ensureDefaultDir($default, $body);
			if ($made !== true) return $made;
			$body['root'] = $default;
		}
		// Through the store, under its lock; fields this form does not know
		// (status, and those later versions add) are kept.
		$ok = Q_WebServer_Domains::update($domainKey, function ($rec) use ($body) {
			$rec['root'] = $body['root'] ?? ($rec['root'] ?? null);
			$rec['app'] = $body['app'] ?? ($rec['app'] ?? null);
			$rec['tls'] = $body['tls'] ?? ($rec['tls'] ?? 'auto');
			$rec['aliases'] = array_values(array_filter((array) ($body['aliases'] ?? ($rec['aliases'] ?? array())), 'is_string'));
			$rec['status'] = $rec['status'] ?? 'active';
			return $rec;
		});
		if (!$ok) return ['status' => 503, 'error' => 'The panel store cannot be written'];
		return ['added' => $domain];
	}

	static function apiRemoveDomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = $body['domain'] ?? '';
		if (!Q_WebServer_Domains::validName(Q_WebServer_Domains::normalize($domain))) {
			return ['status' => 400, 'error' => 'Invalid domain name'];
		}
		if (empty($body['confirm'])) {
			return ['status' => 409, 'error' => 'Removing a domain needs confirm', 'confirm' => true];
		}
		$ok = Q_WebServer_Domains::update($domain, function ($rec) { return null; });
		if (!$ok) return ['status' => 503, 'error' => 'The panel store cannot be written'];
		return ['removed' => $domain];
	}

	/** GET domains/usage: which domains are in use, and where each name comes from. */
	static function apiDomainUsage()
	{
		return Q_WebServer_DomainUsage::collect();
	}

	/**
	 * POST domains/status {domain, status, note?, confirm}: active,
	 * suspended or disabled. Anything but active needs confirm (409 without).
	 */
	static function apiDomainStatus($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		$status = (string) ($body['status'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		if (!in_array($status, Q_WebServer_Domains::STATUSES, true)) {
			return ['status' => 400, 'error' => 'Status must be one of: ' . implode(', ', Q_WebServer_Domains::STATUSES)];
		}
		if ($status !== 'active' and empty($body['confirm'])) {
			return ['status' => 409, 'error' => "Setting $domain to $status takes it off the air; send confirm to proceed", 'confirm' => true];
		}
		if (!Q_WebServer_Domains::setStatus($domain, $status, $body['note'] ?? null)) {
			return ['status' => 503, 'error' => 'The panel store cannot be written'];
		}
		return ['domain' => $domain, 'status' => $status];
	}

	/**
	 * POST domains/alias {domain, add|remove}: an extra name served as the
	 * domain. A name another domain already answers to is refused.
	 */
	static function apiDomainAlias($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$add = isset($body['add']);
		$alias = Q_WebServer_Domains::normalize($add ? $body['add'] : ($body['remove'] ?? ''));
		if (!Q_WebServer_Domains::validName($alias)) return ['status' => 400, 'error' => 'Invalid alias: give add or remove with a host name'];
		if ($alias === $domain) return ['status' => 400, 'error' => 'An alias cannot be the domain itself'];
		if ($add) {
			$owner = Q_WebServer_Domains::owner($alias);
			if ($owner !== null and $owner !== $domain) {
				return ['status' => 400, 'error' => "$alias already belongs to $owner"];
			}
		}
		if (!Q_WebServer_Domains::setAlias($domain, $alias, $add)) {
			return ['status' => 503, 'error' => 'The panel store cannot be written'];
		}
		return ['domain' => $domain, ($add ? 'added' : 'removed') => $alias];
	}

	/**
	 * POST domains/subdomain {domain, name, root, remove?, confirm?}: a host
	 * under the domain served from its own root (relative roots are taken
	 * under the domain's root and must stay inside it). Changing an
	 * existing subdomain's root, or removing it, needs confirm.
	 */
	static function apiDomainSubdomain($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$records = Q_WebServer_Domains::records();
		$rec = $records[$domain] ?? null;
		if ($rec === null) return ['status' => 400, 'error' => "No record for $domain; add the domain first"];
		$name = Q_WebServer_Domains::normalize($body['name'] ?? '');
		$host = Q_WebServer_Domains::subdomainHost($name, $domain);
		if ($host === null) return ['status' => 400, 'error' => 'Invalid subdomain name'];
		$key = $name;
		$existing = (array) ($rec['subdomains'] ?? array());
		if (!empty($body['remove'])) {
			if (!array_key_exists($key, $existing)) return ['status' => 400, 'error' => "No subdomain $name on $domain"];
			if (empty($body['confirm'])) return ['status' => 409, 'error' => "Removing $host stops serving it; send confirm to proceed", 'confirm' => true];
			if (!Q_WebServer_Domains::setSubdomain($domain, $key, null)) return ['status' => 503, 'error' => 'The panel store cannot be written'];
			return ['domain' => $domain, 'removed' => $host];
		}
		$root = trim((string) ($body['root'] ?? ''));
		$domainRoot = Q_WebServer_Domains::realDir($rec['root'] ?? null);
		if ($root === '') {
			// No root given: <base>/<domain>/<name>/<docDirName>.
			$root = Q_WebServer_Domains::defaultSubdomainRoot($domain, $name);
			$made = self::ensureDefaultDir($root, $body);
			if ($made !== true) return $made;
		}
		Q_WebServer_Domains::subdomainRoot($root, $domainRoot, $why, Q_WebServer_Domains::fence($domainRoot));
		if ($why !== null) return ['status' => 400, 'error' => "Root for $host: $why"];
		$owner = Q_WebServer_Domains::owner($host);
		if ($owner !== null and !array_key_exists($key, $existing)) return ['status' => 400, 'error' => "$host already belongs to $owner"];
		if (array_key_exists($key, $existing) and $existing[$key] !== $root and empty($body['confirm'])) {
			return ['status' => 409, 'error' => "Changing the root of $host changes what it serves; send confirm to proceed", 'confirm' => true];
		}
		if (!Q_WebServer_Domains::setSubdomain($domain, $key, $root)) return ['status' => 503, 'error' => 'The panel store cannot be written'];
		return ['domain' => $domain, 'subdomain' => $host, 'root' => $root];
	}

	/**
	 * A defaulted document root: true when it exists (or was just created
	 * with create + confirm); otherwise the 409 that offers to create it.
	 * Nothing that exists is ever touched.
	 */
	protected static function ensureDefaultDir($path, array $body)
	{
		if (is_dir($path)) return true;
		if (file_exists($path) or is_link($path)) {
			return ['status' => 400, 'error' => "$path exists and is not a directory", 'root' => $path];
		}
		if (empty($body['create']) or empty($body['confirm'])) {
			return ['status' => 409, 'error' => "$path does not exist; send create and confirm to create it (0755, owned like its parent)",
				'confirm' => true, 'create' => true, 'root' => $path];
		}
		if (!Q_WebServer_Domains::createDir($path, $why)) {
			return ['status' => 400, 'error' => "Could not create $path: $why", 'root' => $path];
		}
		return true;
	}

	/**
	 * POST domains/defaults {domain, name?}: the root a new domain (or, with
	 * name, a new subdomain) would get, and whether it exists yet.
	 */
	static function apiDomainDefaults($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$name = Q_WebServer_Domains::normalize($body['name'] ?? '');
		if ($name !== '' and Q_WebServer_Domains::subdomainHost($name, $domain) === null) {
			return ['status' => 400, 'error' => 'Invalid subdomain name'];
		}
		$root = $name === '' ? Q_WebServer_Domains::defaultRoot($domain) : Q_WebServer_Domains::defaultSubdomainRoot($domain, $name);
		return ['root' => $root, 'exists' => is_dir($root), 'baseDir' => Q_WebServer_Domains::baseDir(),
			'docDirName' => Q_WebServer_Domains::docDirName()];
	}

	/**
	 * POST domains/root {domain, root, confirm?}: the domain's own document
	 * root (an empty root clears it). Changing or clearing an existing root
	 * needs confirm.
	 */
	static function apiDomainRoot($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$root = trim((string) ($body['root'] ?? ''));
		if ($root !== '') {
			if ($root[0] !== '/' and !preg_match('#^[A-Za-z]:[\\\\/]#', $root)) return ['status' => 400, 'error' => 'The root must be an absolute path'];
			if (Q_WebServer_Domains::realDir($root) === null) return ['status' => 400, 'error' => "$root is not an existing directory"];
		}
		$records = Q_WebServer_Domains::records();
		$current = (string) ($records[$domain]['root'] ?? '');
		if ($current !== '' and $current !== $root and empty($body['confirm'])) {
			return ['status' => 409, 'error' => ($root === '' ? "Clearing" : "Changing") . " the root of $domain changes what it serves; send confirm to proceed", 'confirm' => true];
		}
		if (!Q_WebServer_Domains::setRoot($domain, $root === '' ? null : $root)) {
			return ['status' => 503, 'error' => 'The panel store cannot be written'];
		}
		return ['domain' => $domain, 'root' => $root === '' ? null : $root];
	}

	/**
	 * POST domains/redirects {domain, https?, preferredHost?, rules?, confirm?}
	 * -- replaces the domain's redirects; an empty body (no https, no
	 * preferredHost, no rules) clears them. Turning the HTTPS redirect on
	 * needs confirm, and is refused without an HTTPS listener or a
	 * certificate that covers the domain.
	 */
	static function apiDomainRedirects($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		if (!is_array($body)) return ['status' => 400, 'error' => 'The body must be a JSON object'];
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$records = Q_WebServer_Domains::records();
		$rec = is_array($records[$domain] ?? null) ? $records[$domain] : array();
		$r = array();
		if (!empty($body['https'])) $r['https'] = true;
		$pref = $body['preferredHost'] ?? null;
		if ($pref !== null and $pref !== '') $r['preferredHost'] = $pref;
		$rules = array();
		foreach ((array) ($body['rules'] ?? array()) as $rule) {
			if (!is_array($rule)) return ['status' => 400, 'error' => 'Each rule must be an object'];
			$rules[] = array(
				'match' => (string) ($rule['match'] ?? 'prefix'),
				'from' => (string) ($rule['from'] ?? ''),
				'to' => trim((string) ($rule['to'] ?? '')),
				'code' => (int) ($rule['code'] ?? 301),
				'keepQuery' => !empty($rule['keepQuery']),
			);
		}
		if ($rules) $r['rules'] = $rules;
		$cert = Q_WebServer_DomainUsage::certificate();
		$why = Q_WebServer_Domains::redirectsProblem($domain, $rec, $r, $cert ? $cert['names'] : array());
		if ($why !== null) return ['status' => 400, 'error' => $why];
		$wasHttps = !empty($rec['redirects']['https']);
		if (!empty($r['https']) and !$wasHttps and empty($body['confirm'])) {
			return ['status' => 409, 'error' => "Sending $domain from HTTP to HTTPS makes browsers remember it; send confirm to proceed", 'confirm' => true];
		}
		if (!Q_WebServer_Domains::setRedirects($domain, $r ? $r : null)) {
			return ['status' => 503, 'error' => 'The panel store cannot be written'];
		}
		return ['domain' => $domain, 'redirects' => $r ? $r : null];
	}

	/** POST domains/hsts {domain, enabled, maxAge?, includeSubDomains?, confirm?} */
	static function apiDomainHsts($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		if (!is_array($body)) return ['status' => 400, 'error' => 'The body must be a JSON object'];
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		if (empty($body['enabled'])) {
			if (!Q_WebServer_Domains::setHsts($domain, null)) return ['status' => 503, 'error' => 'The panel store cannot be written'];
			return ['domain' => $domain, 'hsts' => null];
		}
		$age = isset($body['maxAge']) ? $body['maxAge'] : 31536000;
		if (!is_numeric($age) or (int) $age < 0 or (int) $age > 63072000) {
			return ['status' => 400, 'error' => 'maxAge must be between 0 and 63072000 seconds (two years)'];
		}
		if (Q_WebServer_Domains::httpsPortSuffix() === null) {
			return ['status' => 400, 'error' => 'there is no HTTPS listener, so HSTS would never be sent'];
		}
		$records = Q_WebServer_Domains::records();
		if (empty($records[$domain]['hsts']['enabled']) and empty($body['confirm'])) {
			return ['status' => 409, 'error' => "HSTS makes browsers refuse plain HTTP for $domain for the whole max-age, even after it is turned off; send confirm to proceed", 'confirm' => true];
		}
		$h = array('enabled' => true, 'maxAge' => (int) $age, 'includeSubDomains' => !empty($body['includeSubDomains']));
		if (!Q_WebServer_Domains::setHsts($domain, $h)) return ['status' => 503, 'error' => 'The panel store cannot be written'];
		return ['domain' => $domain, 'hsts' => $h];
	}

	/** POST domains/errordocs {domain, docs: {"404": "errors/404.html", ...}} -- replaces them; {} clears. */
	static function apiDomainErrorDocs($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		if (!is_array($body)) return ['status' => 400, 'error' => 'The body must be a JSON object'];
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$records = Q_WebServer_Domains::records();
		$root = Q_WebServer_Domains::realDir($records[$domain]['root'] ?? null);
		$docs = array();
		foreach ((array) ($body['docs'] ?? array()) as $code => $rel) {
			$rel = trim((string) $rel);
			if ($rel === '') continue;
			if (!in_array((int) $code, Q_WebServer_Domains::ERROR_CODES, true)) {
				return ['status' => 400, 'error' => "Error documents are for " . implode(', ', Q_WebServer_Domains::ERROR_CODES) . ", not $code"];
			}
			if (Q_WebServer_Domains::errorDocPath($root, $rel, $why) === null) {
				return ['status' => 400, 'error' => "$code: $why"];
			}
			$docs[(string) (int) $code] = ltrim(str_replace('\\', '/', $rel), '/');
		}
		if (!Q_WebServer_Domains::setErrorDocs($domain, $docs)) return ['status' => 503, 'error' => 'The panel store cannot be written'];
		return ['domain' => $domain, 'errorDocs' => $docs ? $docs : null];
	}

	/** The host names a domain answers to: itself, its aliases, its subdomains. */
	private static function domainHosts($domain, array $rec)
	{
		$hosts = array($domain);
		foreach ((array) ($rec['aliases'] ?? array()) as $a) $hosts[] = Q_WebServer_Domains::normalize($a);
		foreach (array_keys((array) ($rec['subdomains'] ?? array())) as $s) $hosts[] = strtolower($s) . '.' . $domain;
		return array_values(array_unique(array_filter($hosts)));
	}

	/** A value from the request's query, whether it arrived parsed or as a string. */
	private static function queryValue($parsed, $name)
	{
		$q = $parsed['query'] ?? array();
		if (is_string($q)) { $s = array(); parse_str($q, $s); $q = $s; }
		return isset($q[$name]) && is_string($q[$name]) ? $q[$name] : '';
	}

	/** GET domains/cert?domain=… -- the certificate covering a domain, and whether it can be issued here. */
	/**
	 * GET domains/traffic?domain= -- requests, bytes, status classes, the
	 * busiest paths and when it was last seen, for the domain with its
	 * aliases and subdomains, counted since the server started.
	 */
	static function apiDomainTraffic($parsed)
	{
		$domain = Q_WebServer_Domains::normalize(self::queryValue($parsed, 'domain'));
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$records = Q_WebServer_Domains::records();
		if (!isset($records[$domain])) return ['status' => 404, 'error' => "No domain $domain"];
		$names = self::domainHosts($domain, $records[$domain]);
		$sum = Q_WebServer_Traffic::summary($names, 10);
		$sum['domain'] = $domain;
		$sum['names'] = array_values($names);
		$sum['window'] = 'since the server started';
		return $sum;
	}

	static function apiDomainCert($parsed)
	{
		$domain = Q_WebServer_Domains::normalize(self::queryValue($parsed, 'domain'));
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$records = Q_WebServer_Domains::records();
		if (!isset($records[$domain])) return ['status' => 404, 'error' => "No domain $domain"];
		$config = (array) Q_Config::get('Q', 'web', 'https', array());
		return Q_WebServer_Certificate_Inspector::forDomain($domain, self::domainHosts($domain, $records[$domain]), $config);
	}

	/**
	 * POST ssl/settings, ssl/renew and ssl/reload: the SSL tab's changes.
	 * Each takes a JSON object; settings and renew answer 409 until the
	 * body carries confirm. Nothing here returns key material.
	 */
	static function apiSsl($route, $parsed)
	{
		if (strtoupper((string) ($parsed['method'] ?? 'POST')) !== 'POST') return ['status' => 405, 'error' => 'Use POST'];
		$body = json_decode($parsed['body'] ?? '{}', true);
		if ($body === null and trim((string) ($parsed['body'] ?? '')) === '') $body = array();
		if (!is_array($body)) return ['status' => 400, 'error' => 'The body must be a JSON object'];
		switch ($route) {
			case 'ssl/settings': return Q_WebServer_Certificate_Admin::saveSettings($body);
			case 'ssl/renew': return Q_WebServer_Certificate_Admin::renew($body);
			default: return Q_WebServer_Certificate_Admin::reload();
		}
	}

	/**
	 * POST domains/cert/issue {domain, confirm} -- issue or renew a certificate
	 * that covers the domain and its aliases, as a background job; poll
	 * domains/cert/job for the outcome. domains/provision is the old name.
	 */
	static function apiDomainCertIssue($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		if (!is_array($body)) return ['status' => 400, 'error' => 'The body must be a JSON object'];
		$domain = Q_WebServer_Domains::normalize($body['domain'] ?? '');
		if (!Q_WebServer_Domains::validName($domain)) return ['status' => 400, 'error' => 'Invalid domain name'];
		$records = Q_WebServer_Domains::records();
		if (!isset($records[$domain])) return ['status' => 404, 'error' => "No domain $domain"];
		$config = (array) Q_Config::get('Q', 'web', 'https', array());
		$why = '';
		if (!Q_WebServer_Certificate_Inspector::canIssue($config, $why)) return ['status' => 400, 'error' => $why];
		$aliases = array_values(array_filter(array_map(array('Q_WebServer_Domains', 'normalize'), (array) ($records[$domain]['aliases'] ?? array()))));
		if (empty($body['confirm'])) {
			$names = Q_WebServer_Certificate_Inspector::issueConfig($config, $domain, $aliases)['acme']['domains'];
			return ['status' => 409, 'confirm' => true, 'names' => $names,
				'error' => 'This asks the certificate authority for a certificate naming ' . implode(', ', $names)
					. '; each of them must reach this server over HTTP for the challenge. Send confirm to proceed'];
		}
		$r = Q_WebServer_Certificate_Inspector::startIssue($domain, $aliases, $config, null, $why);
		if (!$r) return ['status' => 400, 'error' => $why];
		return ['status' => 202] + $r;
	}

	/** GET domains/cert/job?id=… -- a certificate job's status: queued, running, succeeded, failed. */
	static function apiDomainCertJob($parsed)
	{
		$file = Q_WebServer_Certificate_Inspector::jobFile(self::queryValue($parsed, 'id'));
		if (!$file) return ['status' => 404, 'error' => 'No such certificate job'];
		return array('job' => Q_WebServer_Certificate_Inspector::jobId($file)) + Q_WebServer_Certificate_Inspector::jobStatus($file);
	}

	// ── Hosts File API ──────────────────────────────────

	/**
	 * Get the system hosts file path for the current OS.
	 */
	static function hostsFilePath()
	{
		return PHP_OS_FAMILY === 'Windows'
			? 'C:\\Windows\\System32\\drivers\\etc\\hosts'
			: '/etc/hosts';
	}

	/**
	 * Read and parse the system hosts file.
	 * Returns entries as [{ip, hostname, line}] and the raw content.
	 */
	static function apiHostsFile()
	{
		$path = self::hostsFilePath();
		if (!is_readable($path)) {
			return ['error' => "Cannot read $path", 'entries' => [], 'writable' => false];
		}
		$raw = file_get_contents($path);
		$entries = [];
		foreach (explode("\n", $raw) as $i => $line) {
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed[0] === '#') continue;
			$parts = preg_split('/\s+/', $trimmed);
			if (count($parts) >= 2) {
				$ip = array_shift($parts);
				foreach ($parts as $host) {
					if ($host === '' || $host[0] === '#') break;
					$entries[] = ['ip' => $ip, 'hostname' => $host, 'line' => $i + 1];
				}
			}
		}

		// Cross-reference with configured domains
		$domains = Q_Config::get('Q', 'webserver', 'domains', array());
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$panelConfig = json_decode(file_get_contents($configPath), true);
			if (!empty($panelConfig['domains'])) {
				$domains = array_merge($domains, $panelConfig['domains']);
			}
		}

		$mapped = [];
		$hostsMap = [];
		foreach ($entries as $e) {
			$hostsMap[$e['hostname']] = $e['ip'];
		}
		foreach ($domains as $name => $conf) {
			$mapped[] = [
				'domain' => $name,
				'inHosts' => isset($hostsMap[$name]),
				'hostsIp' => $hostsMap[$name] ?? null,
			];
		}

		return [
			'entries' => $entries,
			'domains' => $mapped,
			'path' => $path,
			'writable' => is_writable($path),
			'needsElevation' => !is_writable($path),
		];
	}

	/**
	 * Add an entry to /etc/hosts. Returns a shell command for elevation
	 * if the server doesn't have write access (which is the normal case).
	 */
	static function apiHostsAdd($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$hostname = $body['hostname'] ?? '';
		$ip = $body['ip'] ?? '127.0.0.1';

		// A hosts entry may legitimately be a bare label, so the dot is not
		// required here -- everything else about the shape still is.
		if (!$hostname || !Q_WebServer_Autohost::validateHostname($hostname, false)) {
			return ['status' => 400, 'error' => 'Invalid hostname'];
		}
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return ['status' => 400, 'error' => 'Invalid IP'];
		}

		// Check if already in hosts
		$path = self::hostsFilePath();
		if (is_readable($path)) {
			$existing = file_get_contents($path);
			if (preg_match('/^\s*' . preg_quote($ip, '/') . '\s+.*\b' . preg_quote($hostname, '/') . '\b/m', $existing)) {
				return ['already' => true, 'hostname' => $hostname, 'ip' => $ip];
			}
			// Check for conflicting entry (different IP, same hostname)
			if (preg_match('/^\s*(\S+)\s+.*\b' . preg_quote($hostname, '/') . '\b/m', $existing, $m)) {
				return [
					'conflict' => true,
					'hostname' => $hostname,
					'existingIp' => trim($m[1]),
					'requestedIp' => $ip,
				];
			}
		}

		$entry = "$ip\t$hostname";

		// Try direct write first
		if (is_writable($path)) {
			file_put_contents($path, "\n$entry\n", FILE_APPEND);
			return ['added' => true, 'hostname' => $hostname, 'ip' => $ip];
		}

		// Return platform-specific elevation commands
		$cmds = [];
		if (PHP_OS_FAMILY === 'Darwin') {
			$cmds['command'] = "sudo -- sh -c 'echo \"$entry\" >> /etc/hosts'";
			$cmds['gui'] = "osascript -e 'do shell script \"echo \\\"$entry\\\" >> /etc/hosts\" with administrator privileges'";
		} elseif (PHP_OS_FAMILY === 'Windows') {
			$psCmd = "Add-Content -Path '$path' -Value '$entry'";
			$cmds['command'] = "powershell -Command \"Start-Process powershell -Verb RunAs -ArgumentList '-Command $psCmd'\"";
		} else {
			$cmds['command'] = "sudo -- sh -c 'echo \"$entry\" >> /etc/hosts'";
			$cmds['gui'] = "pkexec sh -c 'echo \"$entry\" >> /etc/hosts'";
		}

		return [
			'needsElevation' => true,
			'hostname' => $hostname,
			'ip' => $ip,
			'entry' => $entry,
			'commands' => $cmds,
		];
	}

	// ── Attestation & Trust API ─────────────────────────

	static function apiAttestationSign($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$keyPem = $body['key'] ?? '';
		$signer = $body['signer'] ?? 'panel-user';

		if (!$keyPem) {
			return ['status' => 400, 'error' => 'Provide a PEM private key in the "key" field'];
		}

		// Write key to temp file
		$tmpKey = tempnam(sys_get_temp_dir(), 'qbix_sign_');
		file_put_contents($tmpKey, $keyPem);

		require_once dirname(__DIR__) . '/WebServer/Trust.php';
		$binaryPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $GLOBALS['argv'][0]);
		$result = Q_WebServer_Trust::signBinary($binaryPath, $tmpKey, $signer);
		@unlink($tmpKey);

		if (!$result) {
			return ['status' => 500, 'error' => 'Signing failed — check key format'];
		}
		return [
			'signed' => true,
			'hash' => $result['binary_hash'],
			'signers' => count($result['signatures']),
		];
	}

	static function apiTrustVerify($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$dir = $body['dir'] ?? null;

		require_once dirname(__DIR__) . '/WebServer/Trust.php';
		if ($dir) {
			$dir = realpath($dir);
			if (!$dir || !is_dir($dir)) {
				return ['status' => 400, 'error' => 'Directory not found'];
			}
			return Q_WebServer_Trust::verifyDirectory($dir);
		}
		// Verify all known directories
		return Q_WebServer_Trust::status();
	}

	// ── Autohost API ────────────────────────────────────

	static function apiAutohostToggle($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$configPath = self::panelConfigPath();
		$config = is_file($configPath)
			? json_decode(file_get_contents($configPath), true) : [];

		if (isset($body['enabled'])) {
			$config['autohost']['enabled'] = (bool) $body['enabled'];
		}
		if (isset($body['authorize'])) {
			$config['autohost']['authorize'] = $body['authorize'];
		}
		if (isset($body['dnsCheck'])) {
			$config['autohost']['dnsCheck'] = (bool) $body['dnsCheck'];
		}
		if (isset($body['acmeEmail'])) {
			$config['autohost']['acmeEmail'] = $body['acmeEmail'];
		}
		if (isset($body['allowlist'])) {
			$config['autohost']['allowlist'] = array_values(array_filter(
				array_map('trim', explode("\n", $body['allowlist']))
			));
		}

		file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		// Apply to runtime config
		foreach ($config['autohost'] ?? [] as $k => $v) {
			Q_Config::set('Q', 'webserver', 'autohost', $k, $v);
		}

		return ['saved' => true, 'autohost' => $config['autohost'] ?? []];
	}

	// ── Workers API ──────────────────────────────────────

	static function apiWorkerStatus()
	{
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['mode' => 'in-process', 'workers' => 0];
		}
		$stats = Q_WebServer_Dashboard::getStats();
		return [
			'mode' => 'persistent',
			'workers' => $stats['workers'] ?? 0,
			'activeWorkers' => $stats['activeWorkers'] ?? 0,
			'totalRequests' => $stats['totalRequests'] ?? 0,
			'uptime' => $stats['uptime'] ?? 0,
			'memoryUsage' => memory_get_usage(true),
			'memoryPeak' => memory_get_peak_usage(true),
			'pid' => getmypid(),
		];
	}

	static function apiWorkerResize($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$count = (int) ($body['workers'] ?? 0);
		if ($count < 1 || $count > 10000) {
			return ['status' => 400, 'error' => 'Worker count must be 1-10000'];
		}
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['status' => 400, 'error' => 'No worker pool (in-process mode)'];
		}
		if (method_exists($pool, 'resize')) {
			return array('resized' => $count) + $pool->resize($count);
		}
		return ['status' => 501, 'error' => 'Pool does not support dynamic resize yet'];
	}

	static function apiWorkerRecycle($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['status' => 400, 'error' => 'No worker pool'];
		}
		$index = $body['index'] ?? null;
		if ($index !== null) {
			// Recycle a specific worker
			$result = $pool->recycleWorker((int) $index);
			return ['worker' => (int) $index, 'result' => $result];
		}
		// Recycle all workers (rolling restart)
		$result = $pool->recycleAll();
		return ['recycled' => $result];
	}

	static function apiWorkerDetail()
	{
		$pool = Q_WebServer::$pool ?? null;
		if (!$pool) {
			return ['mode' => 'in-process', 'workers' => []];
		}
		return $pool->workerStats();
	}

	// ── Logs API ─────────────────────────────────────────

	static function apiLogs($parsed)
	{
		$query = $parsed['query'] ?? [];
		parse_str($query, $params);
		$lines = (int) ($params['lines'] ?? 50);
		$lines = max(1, min($lines, 500));
		$type = $params['type'] ?? 'access'; // access or error

		// Ask the log itself where it writes: it resolved `dir` against
		// APP_DIR at startup and knows the configured filenames, neither
		// of which can be reconstructed from the config key alone.
		// ?host= picks a virtual host's own log; without it, the server's.
		$host = strtolower(trim($params['host'] ?? ''));
		// Without a log of its own, a host is picked out of the server's log
		// by the Host field at the end of each line (the default format
		// writes it); a domain counts its aliases and subdomains with it.
		$hostNames = array();
		if ($host !== '' and !isset(Q_WebServer_Log::$hosts[$host])) {
			$h = Q_WebServer_Domains::normalize($host);
			if ($h === '' or strlen($h) > 253 or !preg_match('/^[a-z0-9.\-:\[\]]+$/', $h)) {
				return ['status' => 400, 'error' => 'host must be a host name such as example.com'];
			}
			$records = Q_WebServer_Domains::records();
			$hostNames = isset($records[$h]) ? array_values(self::domainHosts($h, $records[$h])) : array($h);
		}
		if ($host !== '' and isset(Q_WebServer_Log::$hosts[$host])) {
			$rec = Q_WebServer_Log::$hosts[$host];
			$file = $type === 'error' ? $rec['errorPath'] : $rec['accessPath'];
		} else {
			$file = $type === 'error'
				? Q_WebServer_Log::$errorPath
				: Q_WebServer_Log::$accessPath;
		}
		if (!$file) {
			$logDir = Q_Config::get('Q', 'webserver', 'log', 'dir', 'logs');
			$name = $type === 'error'
				? Q_WebServer_Log::$errorName
				: Q_WebServer_Log::$accessName;
			$file = $logDir . '/' . $name;
		}

		if (!is_file($file)) {
			return ['lines' => [], 'file' => $file, 'exists' => false];
		}

		// Filters: free text, HTTP method, status (exact, or a class: 5, 5xx).
		// Checked here so a bad value is refused, not silently ignored.
		$filter = substr(trim((string) ($params['filter'] ?? '')), 0, 200);
		$method = strtoupper(trim((string) ($params['method'] ?? '')));
		$status = strtolower(trim((string) ($params['status'] ?? '')));
		if ($method !== '' and !preg_match('/^[A-Z]{1,10}$/', $method)) {
			return ['status' => 400, 'error' => 'method must be a word such as GET or POST'];
		}
		if ($status !== '' and !preg_match('/^[1-5](?:\d\d|xx)?$/', $status)) {
			return ['status' => 400, 'error' => 'status must be a code such as 404, or a class such as 5 or 5xx'];
		}
		$filtering = ($filter !== '' or $method !== '' or $status !== '' or $hostNames);

		// Read the end of the file only, never the whole of it: the last few
		// hundred KB without a filter, up to Q.panel.logScanBytes (2 MB) with
		// one, so a search reaches further back than the lines it returns.
		$size = (int) filesize($file);
		$want = $filtering
			? max(65536, (int) Q_Config::get('Q', 'panel', 'logScanBytes', 2097152))
			: min(1048576, $lines * 1024);
		$from = max(0, $size - $want);
		$content = '';
		if ($size > 0 and ($fp = @fopen($file, 'rb'))) {
			fseek($fp, $from);
			$content = (string) stream_get_contents($fp, $size - $from);
			fclose($fp);
		}
		$all = $content === '' ? [] : explode("\n", rtrim($content, "\n"));
		// Started mid-file: the first line is a fragment.
		if ($from > 0 and $all) array_shift($all);

		$matched = 0;
		$result = [];
		if ($filtering) {
			foreach ($all as $line) {
				if ($filter !== '' and stripos($line, $filter) === false) continue;
				if ($hostNames) {
					// The last field, after the response time: "host".
					if (!preg_match('/ms "([^"]*)"$/', $line, $hm)) continue;
					if (!in_array(Q_WebServer_Domains::normalize(stripcslashes($hm[1])), $hostNames, true)) continue;
				}
				if ($method !== '' or $status !== '') {
					if (!preg_match('/"([A-Z]+)\s+\S+[^"]*"\s+(\d{3})\s/', $line, $m)) continue;
					if ($method !== '' and $m[1] !== $method) continue;
					if ($status !== '') {
						if (ctype_digit($status) and strlen($status) === 3) {
							if ($m[2] !== $status) continue;          // exact: 404
						} elseif ($m[2][0] !== $status[0]) {
							continue;                                 // class: 5 or 5xx
						}
					}
				}
				$result[] = $line;
			}
			$matched = count($result);
			$result = array_slice($result, -$lines);
		} else {
			$result = array_slice($all, -$lines);
			$matched = count($result);
		}

		return ['lines' => array_values($result), 'file' => $file, 'exists' => true, 'size' => $size,
			'matched' => $matched, 'scanned' => $size - $from, 'complete' => $from === 0,
			'hosts' => $hostNames];
	}

	// ── Cron / Scheduler API ─────────────────────────────

	static function apiCronStatus()
	{
		$tasks = Q_Config::get('Q', 'scheduler', array());
		$result = [];
		foreach ($tasks as $name => $conf) {
			$entry = [
				'name' => $name,
				'handler' => $conf['handler'] ?? $name,
				'every' => $conf['every'] ?? null,
				'times' => $conf['times'] ?? null,
				'weekdays' => $conf['weekdays'] ?? null,
				'monthdays' => $conf['monthdays'] ?? null,
			];
			$result[] = $entry;
		}
		return ['tasks' => $result];
	}

	static function apiCronRun($parsed)
	{
		$body = json_decode($parsed['body'] ?? '{}', true);
		$name = $body['task'] ?? '';
		$tasks = Q_Config::get('Q', 'scheduler', array());
		if (!isset($tasks[$name])) {
			return ['status' => 404, 'error' => "Task '{$name}' not found"];
		}
		$handler = $tasks[$name]['handler'] ?? $name;
		// Dispatch in a forked process
		if (function_exists('pcntl_fork')) {
			$pid = pcntl_fork();
			if ($pid === 0) {
				Q::event($handler);
				exit(0);
			}
			return ['dispatched' => $name, 'handler' => $handler, 'pid' => $pid];
		}
		return ['status' => 501, 'error' => 'pcntl_fork not available'];
	}

	static function appsDir()
	{
		// 1. Explicit Q config
		$dir = Q_Config::get('Q', 'webserver', 'panel', 'appsDir', null);
		if ($dir && is_dir($dir)) return $dir;
		// 2. Saved in panel config file
		$configPath = self::panelConfigPath();
		if (file_exists($configPath)) {
			$config = json_decode(file_get_contents($configPath), true);
			if (!empty($config['appsDir']) && is_dir($config['appsDir'])) {
				return $config['appsDir'];
			}
		}
		// 3. Platform mode: parent of APP_DIR
		if (defined('APP_DIR')) return dirname(APP_DIR);
		return null;
	}

	static function which($cmd)
	{
		$path = trim(shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where' : 'which')
			. ' ' . escapeshellarg($cmd) . ' 2>/dev/null') ?? '');
		return $path ?: null;
	}

	static function formatBytes($bytes)
	{
		if ($bytes === false) return 'N/A';
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
		return round($bytes, 1) . ' ' . $units[$i];
	}

	static function copyDir($src, $dst)
	{
		$dir = opendir($src);
		@mkdir($dst, 0755, true);
		while (($file = readdir($dir)) !== false) {
			if ($file === '.' || $file === '..') continue;
			$srcPath = $src . DS . $file;
			$dstPath = $dst . DS . $file;
			if (is_dir($srcPath)) {
				self::copyDir($srcPath, $dstPath);
			} else {
				copy($srcPath, $dstPath);
			}
		}
		closedir($dir);
	}

	static function renameInApp($dir, $oldName, $newName)
	{
		// Rename in config/app.json
		$configFile = $dir . DS . 'config' . DS . 'app.json';
		if (file_exists($configFile)) {
			$content = file_get_contents($configFile);
			$content = str_replace($oldName, $newName, $content);
			file_put_contents($configFile, $content);
		}

		// Rename handler/class directories
		foreach (array('handlers', 'classes', 'views', 'text') as $sub) {
			$oldDir = $dir . DS . $sub . DS . $oldName;
			$newDir = $dir . DS . $sub . DS . $newName;
			if (is_dir($oldDir)) {
				rename($oldDir, $newDir);
			}
		}

		// Rename script directories
		$oldScripts = $dir . DS . 'scripts' . DS . $oldName;
		$newScripts = $dir . DS . 'scripts' . DS . $newName;
		if (is_dir($oldScripts)) {
			rename($oldScripts, $newScripts);
		}
	}

	// ── Panel HTML ───────────────────────────────────────

	/**
	 * Which view the panel page opens on, decided here from the session the
	 * request carries, so the page is right from its first paint: 'panel' for
	 * a live session, 'mustchange' for one that must change the default key
	 * first, 'signin' otherwise. The page used to open on the panel and let its
	 * script ask the server afterwards, so a reload showed one view and then
	 * the other.
	 * @method initialAuthState
	 * @static
	 * @param {array} $parsed
	 * @return {string}
	 */
	static function initialAuthState($parsed)
	{
		$s = Q_WebServer_Panel_Auth::sessionFromRequest($parsed);
		if ($s === null) return 'signin';
		return $s['mustChange'] ? 'mustchange' : 'panel';
	}

	/**
	 * Parse /(name)/value view parameters from a panel path.
	 * /Q/panel/(tab)/system  ->  array('tab' => 'system')
	 * @method parseViewParams
	 * @static
	 * @param {string} $path
	 * @return {array}
	 */
	static function parseViewParams($path)
	{
		$params = array();
		if (strncmp($path, '/Q/panel', 8) !== 0) return $params;
		$rest = substr($path, 8);
		if ($rest === '' || $rest === '/') return $params;
		$rest = trim($rest, '/');
		$parts = explode('/', $rest);
		$count = count($parts);
		for ($i = 0; $i < $count; $i += 2) {
			if ($i + 1 >= $count) break;
			$k = $parts[$i];
			if (strlen($k) > 2 && $k[0] === '(' && substr($k, -1) === ')') {
				$name = substr($k, 1, -1);
				if ($name !== '') $params[$name] = urldecode($parts[$i + 1]);
			}
		}
		return $params;
	}

	static function renderPanel($parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost:8080';
		$wsUrl = "ws://$host/Q/ws";
		$viewParams = self::parseViewParams($parsed['path'] ?? '/Q/panel');
		return self::panelHtml($host, $wsUrl, self::initialAuthState($parsed), $viewParams);
	}

	/**
	 * The panel's own page for a visitor it refuses: its toolbar and design,
	 * with the reason where the login form would be. Sent with status 403.
	 * Falls back to the server's error page when the design has no
	 * refused.html (an older custom design).
	 * @method refusedPage
	 * @static
	 * @param {string} $messageHtml written by the server, never request data
	 * @return {string}
	 */
	static function refusedPage($messageHtml)
	{
		$brand = class_exists('Q_WebServer', false) ? Q_WebServer::brand() : 'Qbix';
		$page = Q_WebServer_Design::render('panel', array(
			'brandHead' => Q_WebServer_Brand::headTags($brand . ' Control Panel', '/Q/panel'),
			'brand'     => htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'),
			'message'   => $messageHtml,
		), 'refused.html');
		return $page !== null ? Q_WebServer_Shell::decorate($page) : Q_WebServer::renderErrorPage(403, '/Q/panel', $messageHtml);
	}

	static function panelHtml($host, $wsUrl, $authState = 'unknown', array $viewParams = array())
	{
		$brand = class_exists('Q_WebServer', false)
			? Q_WebServer::brand() : 'Qbix';
		// The page is a design on disk -- designs/default/panel/ (page.html,
		// style.css, script.js), or the same files in the configuration
		// directory's designs/ -- byte for byte what this method used to
		// return from a nowdoc here (see tests/unit-design-render.php).
		// Icons, manifest and link-preview tags; escaped by headTags().
		$viewParamsJson = json_encode((object) $viewParams, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
		$page = Q_WebServer_Design::render('panel', array(
			'brandHead' => Q_WebServer_Brand::headTags($brand . ' Control Panel', '/Q/panel'),
			'brand'     => htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'),
			'authState' => in_array($authState, array('panel', 'mustchange', 'signin'), true) ? $authState : 'unknown',
			'viewParams' => $viewParamsJson,
		));
		if ($page !== null and isset($viewParams['tab']) and is_string($viewParams['tab'])) {
			$page = self::openOnTab($page, $viewParams['tab']);
		}
		return $page !== null ? Q_WebServer_Shell::decorate($page)
			: '<!DOCTYPE html><html><body><p>The panel design is missing (designs/default/panel).</p></body></html>';
	}

	/**
	 * The panel page opened on a tab other than its default, in the HTML
	 * itself: the tab marked active and its section shown before any script
	 * runs, so /Q/panel/(tab)/logs paints the Logs tab first instead of the
	 * Apps tab and then switching. A name that is not one of the page's tabs,
	 * or a design without the markers, leaves the page as it is.
	 * @method openOnTab
	 * @static
	 * @param {string} $page
	 * @param {string} $tab
	 * @return {string}
	 */
	static function openOnTab($page, $tab)
	{
		if (!preg_match('/^[a-z0-9_-]+$/', $tab)) return $page;
		$want = 'id="tab-' . $tab . '" class="content hidden"';
		$tabOff = 'class="tab" data-tab="' . $tab . '"';
		if (strpos($page, $want) === false or strpos($page, $tabOff) === false) return $page;
		// The default tab: whichever section is shown and whichever tab is active.
		$page = preg_replace('/(id="tab-[a-z0-9_-]+" class="content)"/', '$1 hidden"', $page, 1);
		$page = preg_replace('/class="tab active" (data-tab="[a-z0-9_-]+")/', 'class="tab" $1', $page, 1);
		$page = str_replace($want, 'id="tab-' . $tab . '" class="content"', $page);
		return str_replace($tabOff, 'class="tab active" data-tab="' . $tab . '"', $page);
	}
}
