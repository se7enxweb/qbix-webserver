
const API = '/Q/api';
let hasNode = false;
let hasComposer = false;
let authToken = null;

// ── Auth ─────────────────────────────────────────────

// The server sets the session cookie at sign-in (Path=/, every /Q/ view);
// it wins over this tab's storage, which can hold a token from an older session.
function getToken() {
  var m = document.cookie.match(/(?:^|;\s*)Q_panel_token=([^;]+)/);
  if (m && m[1]) return (authToken = decodeURIComponent(m[1]));
  if (authToken) return authToken;
  try { authToken = sessionStorage.getItem('Q_panel_token'); } catch(e) {}
  return authToken;
}
// Which view the page shows: 'panel', 'mustchange', 'signin' (see style.css).
// The server sets it on the page from the session; the script only changes
// it on a definite answer.
function setAuthState(s) { document.body.setAttribute('data-auth', s); }
function setToken(t) {
  authToken = t;
  try { sessionStorage.setItem('Q_panel_token', t); } catch(e) {}
  // The same cookie the server sets, for a server too old to set it.
  document.cookie = 'Q_panel_token=' + t + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
}

async function api(path, body) {
  var headers = {'Content-Type':'application/json'};
  var t = getToken();
  if (t) headers['X-Panel-Token'] = t;
  var r = await fetch(API+'/'+path, body
    ? {method:'POST', headers:headers, body:JSON.stringify(body)}
    : {headers:headers});
  var data = await r.json();
  if (r.status === 403 && data.mustChange) {
    showChangePassword(data.rules);
    throw new Error('must change');
  }
  if (r.status === 403 && data.twoFactorRequired) {
    show2FA();
    throw new Error('2fa');
  }
  if (data.error && (data.needsSetup || r.status === 401)) {
    showAuthScreen(data.needsSetup, data);
    throw new Error('auth');
  }
  return data;
}

function showAuthScreen(isSetup, info) {
  setAuthState('signin');
  var main = document.getElementById('main-content');
  if (!main) {
    // Wrap everything after tabs in a container
    var tabs = document.querySelector('.tabs');
    var els = [];
    var sib = tabs.nextElementSibling;
    while (sib) { els.push(sib); sib = sib.nextElementSibling; }
    main = document.createElement('div');
    main.id = 'main-content';
    els.forEach(function(el) { main.appendChild(el); });
    tabs.parentNode.insertBefore(main, tabs.nextSibling);
  }
  main.style.display = 'none';
  document.querySelector('.tabs').style.display = 'none';

  var existing = document.getElementById('auth-screen');
  if (existing) existing.remove();

  // No password yet, and it may not be set from here: say how to set it on
  // the server rather than offering a form the server would refuse.
  if (isSetup && info && info.setupAllowed === false) {
    var help = document.createElement('div');
    help.id = 'auth-screen';
    help.className = 'content';
    help.style.maxWidth = '460px';
    help.style.margin = '40px auto';
    var card = document.createElement('div');
    card.className = 'card';
    var h = document.createElement('h3');
    h.style.marginBottom = '12px';
    h.textContent = 'Panel password not set';
    var p = document.createElement('p');
    p.style.fontSize = '13px';
    p.style.color = 'var(--dim)';
    p.textContent = info.setupHelp || 'Set the panel password on the server with: qbixctl panel:password';
    card.appendChild(h);
    card.appendChild(p);
    help.appendChild(card);
    document.body.insertBefore(help, document.querySelector('.tabs').nextSibling);
    return;
  }

  var screen = document.createElement('div');
  screen.id = 'auth-screen';
  screen.className = 'content';
  screen.style.maxWidth = '380px';
  screen.style.margin = '40px auto';
  screen.innerHTML = '<div class="card">'
    + '<h2 style="font-size:14px;font-weight:700;color:var(--txt);margin-bottom:12px">' + (isSetup ? 'Set Panel Password' : 'Panel Login') + '</h2>'
    + (isSetup ? '<p style="font-size:13px;color:var(--dim);margin-bottom:16px">You\'re the first person to access this panel. Set a password to secure it: at least 16 characters, with upper and lower case, a digit and a symbol.</p>' : '')
    + (!isSetup && info && info.hint ? '<p id="auth-hint" style="font-size:13px;color:var(--dim);margin-bottom:16px"></p>' : '')
    + '<div class="form-row"><label>Password</label><input type="password" id="auth-pw" placeholder="' + (isSetup ? 'Choose a password (16+ characters)' : 'Enter password') + '"></div>'
    + (isSetup ? '<div class="form-row"><label>Confirm</label><input type="password" id="auth-pw2" placeholder="Confirm password"></div>' : '')
    + '<button class="btn btn-primary" onclick="doAuth(' + (isSetup ? 'true' : 'false') + ')" style="width:100%">' + (isSetup ? 'Set Password' : 'Login') + '</button>'
    + '<div id="auth-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>'
    + '</div>';
  document.body.insertBefore(screen, document.querySelector('.tabs').nextSibling);
  var hintEl = document.getElementById('auth-hint');
  if (hintEl) hintEl.textContent = info.hint;

  // Enter key
  screen.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') doAuth(isSetup);
  });
  document.getElementById('auth-pw').focus();
}

async function doAuth(isSetup) {
  var pw = document.getElementById('auth-pw').value;
  var errEl = document.getElementById('auth-error');
  errEl.style.display = 'none';

  if (isSetup) {
    var pw2 = document.getElementById('auth-pw2').value;
    if (pw !== pw2) { errEl.textContent = 'Passwords don\'t match'; errEl.style.display = 'block'; return; }
    if (Array.from(pw).length < 16) { errEl.textContent = 'Must be at least 16 characters'; errEl.style.display = 'block'; return; }
  }

  var endpoint = isSetup ? 'auth/setup' : 'auth/login';
  var r = await fetch(API + '/' + endpoint, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({password: pw})
  });
  var data = await r.json();
  if (data.error) {
    errEl.textContent = data.error + (data.failed ? ' ' + data.failed.join(' ') : '');
    errEl.style.display = 'block';
    return;
  }
  if (data.token && data.twoFactorRequired) {
    // The password was right; the panel is not open until a code is given.
    setToken(data.token);
    show2FA();
    return;
  }
  if (data.token) {
    if (data.mustChange) { setToken(data.token); showChangePassword(data.rules); return; }
    setToken(data.token);
    // If we were redirected here from a protected page, go back to it. The
    // page travels as the `next` view parameter (/Q/panel/(next)/<enc>),
    // base64url-encoded. Only a same-origin relative path is honoured, so a
    // crafted value cannot bounce a freshly-signed-in visitor off-site.
    var dest = safeNextDestination();
    if (dest) { window.location.href = dest; return; }
    setAuthState('panel');
    document.getElementById('auth-screen').remove();
    document.querySelector('.tabs').style.display = '';
    document.getElementById('main-content').style.display = '';
    initPanel();
  }
}

// The second factor at sign-in: the password was accepted, but the session is
// pending until a valid authenticator code (or a recovery code) is given. The
// server has already set data-auth="2fa" and issued the pending token.
function show2FA() {
  setAuthState('2fa');
  var main = document.getElementById('main-content');
  var tabs = document.querySelector('.tabs');
  if (!main && tabs) {
    var els = [], sib = tabs.nextElementSibling;
    while (sib) { if (sib.id !== 'auth-screen') els.push(sib); sib = sib.nextElementSibling; }
    main = document.createElement('div');
    main.id = 'main-content';
    els.forEach(function (el) { main.appendChild(el); });
    tabs.parentNode.insertBefore(main, tabs.nextSibling);
  }
  if (main) main.style.display = 'none';
  if (tabs) tabs.style.display = 'none';
  var old = document.getElementById('auth-screen');
  if (old) old.remove();

  var screen = document.createElement('div');
  screen.id = 'auth-screen';
  screen.className = 'content';
  screen.style.maxWidth = '380px';
  screen.style.margin = '40px auto';
  screen._recovery = false;
  screen.innerHTML = '<div class="card">'
    + '<h2 style="font-size:14px;font-weight:700;color:var(--txt);margin-bottom:12px">Two-factor code</h2>'
    + '<p id="tfa-hint" style="font-size:13px;color:var(--dim);margin-bottom:16px">Enter the 6-digit code from your authenticator app.</p>'
    + '<div class="form-row"><label id="tfa-label">Code</label><input id="tfa-code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"></div>'
    + '<button class="btn btn-primary" id="tfa-go" style="width:100%">Verify</button>'
    + '<div style="margin-top:10px"><a href="#" id="tfa-toggle" style="font-size:12px;color:var(--dim)">Use a recovery code instead</a></div>'
    + '<div id="tfa-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>'
    + '</div>';
  document.body.insertBefore(screen, tabs ? tabs.nextSibling : null);

  document.getElementById('tfa-toggle').onclick = function (e) {
    e.preventDefault();
    screen._recovery = !screen._recovery;
    document.getElementById('tfa-label').textContent = screen._recovery ? 'Recovery code' : 'Code';
    document.getElementById('tfa-hint').textContent = screen._recovery
      ? 'Enter one of the recovery codes you saved when you set up two-factor.'
      : 'Enter the 6-digit code from your authenticator app.';
    var input = document.getElementById('tfa-code');
    input.placeholder = screen._recovery ? 'ABCDE-FGHIJ' : '123456';
    input.value = '';
    this.textContent = screen._recovery ? 'Use an authenticator code instead' : 'Use a recovery code instead';
    input.focus();
  };
  document.getElementById('tfa-go').onclick = function () { submit2FA(screen); };
  screen.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit2FA(screen); });
  document.getElementById('tfa-code').focus();
}

async function submit2FA(screen) {
  var val = document.getElementById('tfa-code').value.trim();
  var err = document.getElementById('tfa-error');
  err.style.display = 'none';
  if (!val) return;
  var body = screen._recovery ? {recovery: val} : {code: val};
  var r = await fetch(API + '/auth/2fa', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-Panel-Token': getToken() || ''},
    body: JSON.stringify(body)
  });
  var data = await r.json();
  if (!data.ok) {
    err.textContent = (data.error || 'That code was not accepted.')
      + (data.retryAfter ? ' Try again in ' + data.retryAfter + 's.' : '');
    err.style.display = 'block';
    return;
  }
  if (data.token) setToken(data.token);
  var dest = safeNextDestination();
  if (dest) { window.location.href = dest; return; }
  setAuthState('panel');
  screen.remove();
  var tabs = document.querySelector('.tabs');
  if (tabs) tabs.style.display = '';
  var main = document.getElementById('main-content');
  if (main) main.style.display = '';
  initPanel();
}

async function checkAuthAndInit() {
  // The server has already looked at the session and opened the page on the
  // right view (body data-auth). Trust it: a signed-in visitor gets the panel
  // at once, and the sign-in form is only ever shown on a definite "not
  // signed in" -- never while the answer is still on its way.
  var state = document.body.getAttribute('data-auth');
  if (state === 'panel') {
    initPanel();      // a session that has ended since answers 401, and api() shows the form then
    return;
  }
  if (state === 'mustchange') {
    try { await api('system'); } catch (e) { /* api() showed the change form */ }
    return;
  }
  if (state === '2fa') {
    show2FA();
    return;
  }
  try {
    var r = await fetch(API + '/auth/login', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({})
    });
    var data = await r.json();
    if (data.needsSetup) {
      showAuthScreen(true, data);
      return;
    }
    if (state === 'signin') {
      // The server found no live session in this request.
      showAuthScreen(false, data);
      return;
    }
    // No state from the server (an older design): try the session this
    // browser holds -- stored token or cookie -- before offering the form.
    try { await api('system'); setAuthState('panel'); initPanel(); }
    catch (e) { /* api() showed the right form */ }
  } catch (e) {
    showAuthScreen(false);
  }
}

// Back/forward restores the page as it was; check the session again quietly.
// Only a definite 401 (inside api()) swaps the panel for the form.
window.addEventListener('pageshow', function (e) {
  if (e.persisted && document.body.getAttribute('data-auth') === 'panel') {
    api('system').catch(function () {});
  }
});

function initPanel() {
  detectTools();
  var params = getViewParams();
  var tab = (typeof params.tab === 'string' && /^[a-z0-9_-]+$/.test(params.tab)) ? params.tab : 'apps';
  if (!document.getElementById('tab-' + tab)) tab = 'apps';
  showTab(tab, {silent: true});
}

// Node detection + suggestions
async function detectTools() {
  var d = await api('system');
  hasNode = d.hasNode;
  hasComposer = d.hasComposer;
  document.querySelectorAll('[id=btn-npm],[id=btn-bundle]').forEach(function(el) {
    el.classList.toggle('disabled', !hasNode);
  });
  renderSuggestions(d);
  return d;
}

function renderSuggestions(sys) {
  var el = document.getElementById('suggestions');
  if (!el) return;
  var html = '';
  var isIOS = /iPhone|iPad/.test(navigator.userAgent);
  var isAndroid = /Android/.test(navigator.userAgent);
  var isMobile = isIOS || isAndroid;

  if (isMobile) {
    html += '<div class="suggest suggest-hotspot" onclick="showHotspotTip()">'
      + '<div class="suggest-icon">' + String.fromCodePoint(0x1F4E1) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Share with nearby people</div>'
      + '<div class="suggest-desc">Create a Personal Hotspot so others can connect</div>'
      + '</div><div class="suggest-action">How &rarr;</div></div>';
  }
  if (isIOS) {
    html += '<a href="https://apps.apple.com/us/app/groups/id407855546" target="_blank" style="text-decoration:none">'
      + '<div class="suggest suggest-app"><div class="suggest-icon">' + String.fromCodePoint(0x1F465) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Get the Groups app</div>'
      + '<div class="suggest-desc">Community app with mesh networking</div>'
      + '</div><div class="suggest-action">App Store &rarr;</div></div></a>';
  } else if (isAndroid) {
    html += '<div class="suggest suggest-app" style="opacity:.6;cursor:default">'
      + '<div class="suggest-icon">' + String.fromCodePoint(0x1F465) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Groups for Android</div>'
      + '<div class="suggest-desc">Coming soon</div></div></div>';
  }
  if (!sys.hasNode) {
    html += '<div class="suggest suggest-warn" onclick="showNodeDialog()">'
      + '<div class="suggest-icon">' + String.fromCodePoint(0x26A0) + '</div><div class="suggest-body">'
      + '<div class="suggest-title">Node.js not installed</div>'
      + '<div class="suggest-desc">Optional &mdash; needed for npm and JS/CSS bundling</div>'
      + '</div><div class="suggest-action">Install &rarr;</div></div>';
  }
  el.innerHTML = html;
}

function showHotspotTip() {
  var isIOS = /iPhone|iPad/.test(navigator.userAgent);
  var steps = isIOS
    ? 'Open <b>Settings &rarr; Personal Hotspot</b> and turn it on.'
    : 'Open <b>Settings &rarr; Hotspot & tethering</b> and enable WiFi hotspot.';
  var overlay = document.createElement('div');
  overlay.className = 'dialog-overlay';
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
  overlay.innerHTML = '<div class="dialog"><h3>Share via Hotspot</h3>'
    + '<p>' + steps + ' Others connect to your hotspot, then scan the QR code to access your server.</p>'
    + '<p style="color:var(--dim);font-size:13px">Once someone connects, their device remembers it. Next time they auto-reconnect.</p>'
    + '<div class="btn-row"><button class="btn btn-ghost" onclick="this.closest(\'.dialog-overlay\').remove()">Got it</button></div></div>';
  document.body.appendChild(overlay);
}

function requireNode(callback) {
  if (hasNode) return callback();
  showNodeDialog();
}

function showNodeDialog() {
  var overlay = document.createElement('div');
  overlay.className = 'dialog-overlay';
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
  overlay.innerHTML = '<div class="dialog">'
    + '<h3>Node.js Required</h3>'
    + '<p>This action needs Node.js for npm package management and JS/CSS bundling. '
    + 'Install Node.js, then refresh this page — the buttons will activate automatically.</p>'
    + '<div class="btn-row">'
    + '<a href="https://nodejs.org/" target="_blank" class="btn btn-primary" '
    + 'style="text-decoration:none">Download Node.js ↗</a>'
    + '<button class="btn btn-ghost" onclick="this.closest(\'.dialog-overlay\').remove()">Cancel</button>'
    + '</div></div>';
  document.body.appendChild(overlay);
}

// View parameters: /(name)/value pairs in the path, so a view can be bookmarked.
function getViewParams() {
  var params = {};
  if (typeof window.Q_VIEW_PARAMS === 'object' && window.Q_VIEW_PARAMS !== null) {
    for (var k in window.Q_VIEW_PARAMS) params[k] = window.Q_VIEW_PARAMS[k];
  }
  var fromUrl = parseViewParamsFromUrl(location.pathname);
  for (var k in fromUrl) params[k] = fromUrl[k];
  return params;
}
function parseViewParamsFromUrl(path) {
  var params = {};
  var prefix = '/Q/panel/';
  if (path.indexOf(prefix) !== 0) return params;
  var rest = path.slice(prefix.length).replace(/\/$/, '');
  if (!rest) return params;
  var parts = rest.split('/');
  for (var i = 0; i + 1 < parts.length; i += 2) {
    var k = parts[i];
    if (k.length > 2 && k[0] === '(' && k[k.length - 1] === ')') {
      params[k.slice(1, -1)] = decodeURIComponent(parts[i + 1]);
    }
  }
  return params;
}
function base64urlDecode(str) {
  try {
    var b = String(str).replace(/-/g, '+').replace(/_/g, '/');
    while (b.length % 4) b += '=';
    return atob(b);
  } catch (e) { return ''; }
}
// The page to return to after signing in, read from the `next` view parameter,
// base64url-decoded, and only if it is a same-origin relative path: it must
// start with a single "/" and not "//" or "/\\", carry no scheme and no
// control characters. Anything else is ignored (open-redirect guard).
function safeNextDestination() {
  var params = parseViewParamsFromUrl(location.pathname);
  var enc = params.next;
  if (!enc) return null;
  var p = base64urlDecode(enc);
  if (!p || p.charAt(0) !== '/') return null;
  if (p.charAt(1) === '/' || p.charAt(1) === '\\') return null;
  if (/[\x00-\x1f\\]/.test(p)) return null;
  return p;
}
function buildViewParamUrl(params) {
  var parts = [];
  for (var k in params) {
    if (params[k] == null || params[k] === '') continue;
    parts.push('/(' + encodeURIComponent(k) + ')/' + encodeURIComponent(params[k]));
  }
  return '/Q/panel' + (parts.length ? parts.join('') : '');
}
// Log filters belong to the Logs tab; any other tab's address is just its name.
var LOG_PARAMS = ['type', 'lines', 'filter', 'method', 'status', 'host'];
function setViewParam(name, value) {
  var params = getViewParams();
  var changed = name === 'tab' && params.tab !== value;
  params[name] = value;
  if (name === 'tab' && value !== 'logs') LOG_PARAMS.forEach(function(k) { delete params[k]; });
  // A new tab is a new history entry, so Back returns to the one before.
  if (changed) history.pushState({viewParams: params}, '', buildViewParamUrl(params));
  else history.replaceState({viewParams: params}, '', buildViewParamUrl(params));
}

// Tabs
function showTab(name, opts) {
  opts = opts || {};
  if (typeof name !== 'string' || !/^[a-z0-9_-]+$/.test(name)) return;
  var current = document.querySelector('.tab.active');
  if (current && current.dataset.tab === 'logs' && name !== 'logs') onLeaveLogs();
  var target = document.getElementById('tab-' + name);
  if (!target) return;
  document.querySelectorAll('[id^=tab-]').forEach(function(el) { el.classList.add('hidden'); });
  target.classList.remove('hidden');
  document.querySelectorAll('.tab').forEach(function(el) { el.classList.remove('active'); });
  var tab = document.querySelector('.tab[data-tab="' + name + '"]');
  if (tab) tab.classList.add('active');
  if (!opts.silent) setViewParam('tab', name);
  if (name==='apps') loadApps();
  if (name==='plugins') loadPlugins();
  if (name==='system') loadSystem();
  if (name==='servers') loadServers();
  if (name==='domains') loadDomains();
  if (name==='ssl') loadSsl();
  if (name==='autohost') loadAutohost();
  if (name==='security') loadSecurity();
  if (name==='workers') loadWorkers();
  if (name==='logs') { updateLogControls(); loadLogs(); }
  if (name==='cron') loadCron();
  if (name==='frameworks') loadFrameworks();
  if (name==='scripts') loadAppSelect();
}

window.addEventListener('popstate', function(e) {
  var params = (e.state && e.state.viewParams) ? e.state.viewParams : parseViewParamsFromUrl(location.pathname);
  // showTab() reloads the Logs tab from the restored address, filters included.
  showTab(params.tab || 'apps', {silent: true});
});

// Apps
// Text from the server (names, paths, versions a detector read) is escaped
// before it goes into markup.
function escH(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}

// One detected application: its name, release, where it lives, what it
// says about itself, links while it is the one being served, and its tools.
function installationCard(d, withCommands) {
  var h = '<div class="card inst-card" data-kind="' + escH(d.kind) + '" style="margin-bottom:12px">';
  h += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">';
  h += '<h3 style="font-size:15px;margin:0">' + escH(d.title || d.name) + '</h3>';
  h += '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
  if (d.serving) h += '<span class="badge-serving" style="font-size:12px;color:var(--grn)">● serving on this port</span>';
  if (d.serving && d.links) d.links.forEach(function(l){ h += '<a class="btn btn-sm btn-ghost" href="' + escH(l.url) + '" target="_blank" rel="noopener">' + escH(l.label) + '</a>'; });
  if (withCommands && d.kind !== 'qbix') h += '<button class="btn btn-sm btn-ghost" onclick="loadFwPackages(\'' + escH(d.kind) + '\')">Packages</button>';
  h += '</div></div>';
  h += '<div style="font-size:12px;color:var(--dim);margin:6px 0;word-break:break-all">' + escH(d.dir) + (d.webRoot && d.webRoot !== d.dir ? ' &nbsp;·&nbsp; web root ' + escH(d.webRoot) : '') + '</div>';
  var det = d.details || {};
  var keys = Object.keys(det);
  if (keys.length) {
    h += '<dl class="inst-details" style="display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;font-size:12px;margin:6px 0">';
    keys.forEach(function(k){ h += '<dt style="color:var(--dim)">' + escH(k) + '</dt><dd style="margin:0;word-break:break-word">' + escH(det[k]) + '</dd>'; });
    h += '</dl>';
  }
  if (withCommands) {
    if (d.hasCli === false) h += '<div style="color:var(--yel);font-size:12px;margin:8px 0">Its command-line tool was not found; install it for full management.</div>';
    if (d.commands && d.commands.length) {
      h += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">';
      d.commands.forEach(function(c){
        h += '<button class="btn btn-ghost' + (c.disruptive ? ' btn-caution' : '') + '" style="font-size:12px;padding:5px 12px" data-kind="' + escH(d.kind) + '" data-dir="' + escH(d.dir) + '" data-cmd="' + escH(c.cmd) + '" data-name="' + escH(c.name) + '" data-disruptive="' + (c.disruptive ? '1' : '') + '" onclick="runFwCmdBtn(this)">' + escH(c.name) + (c.disruptive ? ' …' : '') + '</button>';
      });
      h += '</div>';
    }
    h += '<pre id="fw-output-' + escH(d.kind) + '" style="display:none;margin-top:12px;max-height:300px;overflow:auto;font-size:12px;white-space:pre-wrap"></pre>';
    h += '<div id="fw-packages-' + escH(d.kind) + '" style="display:none;margin-top:12px"></div>';
  }
  return h + '</div>';
}

async function loadInstallations(list) {
  var el = document.getElementById('installations-list');
  if (!el) return;
  if (!list || !list.length) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">No PHP application found in the document root, its parent, the apps directory or Q.panel.appRoots.</p></div>'; return; }
  el.innerHTML = list.map(function(d){ return installationCard(d, false); }).join('');
}

async function loadApps() {
  var d = await api('apps');
  loadInstallations(d.installations);
  var el = document.getElementById('apps-list');
  // Show appsDir
  document.getElementById('apps-dir-path').textContent = d.appsDir || '(not set)';
  if (!d.apps || !d.apps.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No apps found in this directory. Click + New App to create one.</p></div>';
    return;
  }
  el.innerHTML = d.apps.map(function(a) {
    var isServing = a.serving;
    var badges = [];
    if (a.hasWeb) badges.push('web');
    if (a.hasHandlers) badges.push('handlers');
    if (a.hasClasses) badges.push('classes');
    if (a.isQbixApp) badges.push('qbix');
    var badgeHtml = badges.map(function(b){return '<span style="font-size:10px;background:rgba(255,255,255,.06);padding:1px 5px;border-radius:3px;color:var(--dim)">'+b+'</span>'}).join(' ');
    var statusText = isServing ? '<span style="color:var(--grn)">serving on this port</span>'
      : (a.url ? a.url : (a.configured ? 'configured' : 'not configured'));
    var forkLabel = a.forkPerRequest === true ? 'fork' : (a.forkPerRequest === false ? 'persistent' : 'auto');
    var forkColor = a.forkPerRequest === true ? 'var(--yel)' : (a.forkPerRequest === false ? 'var(--grn)' : 'var(--dim)');
    var forkHtml = '<select style="font-size:10px;padding:1px 4px;background:var(--card);color:' + forkColor + ';border:1px solid var(--brd);border-radius:3px;cursor:pointer" onchange="setForkMode(\'' + a.dirName + '\',this.value)">'
      + '<option value="auto"' + (a.forkPerRequest === null ? ' selected' : '') + '>auto</option>'
      + '<option value="false"' + (a.forkPerRequest === false ? ' selected' : '') + '>persistent workers</option>'
      + '<option value="true"' + (a.forkPerRequest === true ? ' selected' : '') + '>fork per request</option>'
      + '</select>';
    return ''
    + '<div class="app-row">'
    + '<span class="dot '+(isServing?'on':(a.configured?'on':'off'))+'"></span>'
    + '<span class="app-name">'+a.name+'</span>'
    + '<span class="app-url">'+statusText+' '+badgeHtml+' '+forkHtml+'</span>'
    + '<div class="btn-row">'
    + (a.hasWeb && !isServing ? '<button class="btn btn-sm btn-primary" onclick="serveApp(\''+a.dirName+'\',true)">Serve</button>' : '')
    + (isServing ? '<button class="btn btn-sm btn-red" onclick="serveApp(\''+a.dirName+'\',false)">Stop</button>' : '')
    + (a.isQbixApp && a.hasScripts && !a.configured ? '<button class="btn btn-sm btn-grn" onclick="configureApp(\''+a.dirName+'\',\''+a.name+'\')">Configure</button>' : '')
    + '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+a.dir+'\',\'folder\')">📂</button>'
    + '<button class="btn btn-sm btn-ghost" onclick="openFolder(\''+a.dir+'\',\'vscode\')">VS</button>'
    + '</div></div>';
  }).join('');
}

function showCreate(){document.getElementById('create-form').classList.remove('hidden')}
function hideCreate(){document.getElementById('create-form').classList.add('hidden')}

async function setForkMode(app, value) {
  var forkVal = value === 'true' ? true : (value === 'false' ? false : null);
  var r = await api('apps/fork-mode', {app: app, forkPerRequest: forkVal});
  if (r.note) {
    var out = document.getElementById('fw-output-' + app) || null;
    if (!out) alert(r.note);
  }
  loadApps();
}
async function createApp() {
  var name = document.getElementById('new-name').value.trim();
  var template = document.getElementById('new-template').value;
  if (!name) return alert('Enter an app name');
  var r = await api('apps/create', {name:name, template:template});
  if (r.error) return alert(r.error);
  hideCreate();
  loadApps();
}
async function configureApp(dirName, appName) {
  var name = prompt('App name for configuration:', appName || dirName);
  if (!name) return;
  var r = await api('apps/configure', {app: dirName, name: name});
  if (r.error) alert(r.error);
  else if (r.output) alert(r.output);
  loadApps();
}
async function serveApp(name, enable) {
  var r = await api('apps/serve', {app:name, enable:enable});
  if (r.error) return alert(r.error);
  loadApps();
}
function editAppsDir() {
  document.getElementById('apps-dir-input').value = document.getElementById('apps-dir-path').textContent;
  document.getElementById('apps-dir-edit').classList.remove('hidden');
  document.getElementById('apps-dir-path').style.display = 'none';
  document.getElementById('apps-dir-input').focus();
}
function cancelAppsDir() {
  document.getElementById('apps-dir-edit').classList.add('hidden');
  document.getElementById('apps-dir-path').style.display = '';
}
async function saveAppsDir() {
  var dir = document.getElementById('apps-dir-input').value.trim();
  var r = await api('apps/setdir', {dir: dir});
  if (r.error) return alert(r.error);
  cancelAppsDir();
  loadApps();
}
async function openFolder(dir, editor) {
  await api('apps/open', {dir:dir, editor:editor});
}

// Playground
async function runPlayground() {
  var code = document.getElementById('pg-code').value;
  var outEl = document.getElementById('pg-output');
  var timeEl = document.getElementById('pg-time');
  var btn = document.getElementById('pg-run');
  btn.disabled = true; btn.textContent = '⏳ Running...';
  outEl.textContent = '';
  outEl.style.color = 'var(--grn)';
  timeEl.textContent = '';
  try {
    var r = await api('playground/run', {code: code});
    outEl.textContent = r.output || '(no output)';
    if (r.error) { outEl.textContent += '\n\n⚠ ' + r.error; outEl.style.color = 'var(--red)'; }
    if (r.ms) timeEl.textContent = r.ms + 'ms';
  } catch(e) {
    outEl.textContent = 'Error: ' + e.message;
    outEl.style.color = 'var(--red)';
  }
  btn.disabled = false; btn.textContent = '▶ Run';
}
function clearPlayground() {
  document.getElementById('pg-output').textContent = '';
  document.getElementById('pg-time').textContent = '';
}
// Ctrl+Enter to run
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && document.getElementById('tab-playground').style.display !== 'none') {
    e.preventDefault(); runPlayground();
  }
});

// Scripts
async function loadAppSelect() {
  var d = await api('apps');
  var sel = document.getElementById('script-app');
  sel.innerHTML = (d.apps||[]).map(function(a) {
    return '<option value="'+a.dirName+'">'+a.name+'</option>';
  }).join('');
  loadScripts();
}
async function loadScripts() {
  var app = document.getElementById('script-app').value;
  if (!app) return;
  var d = await api('scripts', {app:app});
  var sel = document.getElementById('script-name');
  sel.innerHTML = (d.scripts||[]).map(function(s) {
    return '<option value="'+s.name+'">'+s.name+' ('+s.scope+')</option>';
  }).join('');
}
async function runScript() {
  var app = document.getElementById('script-app').value;
  var script = document.getElementById('script-name').value;
  var args = document.getElementById('script-args').value.split(/\s+/).filter(Boolean);
  var out = document.getElementById('script-output');
  out.classList.remove('hidden');
  out.textContent = 'Running '+script+'...';
  var r = await api('scripts/run', {app:app, script:script, args:args});
  out.textContent = (r.output||'(no output)') + '\n\nExit code: '+(r.exitCode||'0');
}
function quickScript(name, args) {
  var app = document.getElementById('script-app').value;
  if (!app) return alert('Select an app first');
  document.getElementById('script-name').value = name;
  document.getElementById('script-args').value = args||'';
  runScript();
}

// Plugins
function showAddPlugin() {
  document.getElementById('add-plugin-form').classList.remove('hidden');
  document.getElementById('plugin-name').focus();
}
function hideAddPlugin() {
  document.getElementById('add-plugin-form').classList.add('hidden');
  document.getElementById('plugin-log').style.display = 'none';
}
function hidePrivateDialog() {
  document.getElementById('plugin-private-dialog').classList.add('hidden');
}
async function addPlugin() {
  var name = document.getElementById('plugin-name').value.trim();
  if (!name) return alert('Enter a plugin name');
  var log = document.getElementById('plugin-log');
  log.style.display = 'block';
  log.style.color = 'var(--dim)';
  log.textContent = 'Cloning https://github.com/Qbix/' + name + '...\n';
  try {
    var r = await api('plugins/add', {name: name});
    if (r.private) {
      hideAddPlugin();
      var d = document.getElementById('plugin-private-dialog');
      document.getElementById('plugin-private-name').textContent = name;
      var subject = encodeURIComponent('Access to ' + name + ' plugin');
      var body = encodeURIComponent('Hi Qbix team,\n\nI would like access to the ' + name + ' plugin for my project.\n\nThanks!');
      document.getElementById('plugin-contact-link').href = 'mailto:team@qbix.com?subject=' + subject + '&body=' + body;
      d.classList.remove('hidden');
    } else if (r.error) {
      log.textContent += '\n⚠ ' + r.error;
      log.style.color = 'var(--red)';
    } else {
      log.textContent += (r.output || '') + '\n✅ Installed!';
      log.style.color = 'var(--grn)';
      setTimeout(function() { hideAddPlugin(); loadPlugins(); }, 1500);
    }
  } catch(e) {
    log.textContent += '\nError: ' + e.message;
    log.style.color = 'var(--red)';
  }
}

async function loadPlugins() {
  var r = await api('qbix/plugins');
  var info = document.getElementById('qbix-plugins-info');
  var list = document.getElementById('qbix-plugins-list');
  
  var topHtml = '';
  if (r.app) {
    topHtml += '<div class="card" style="margin-bottom:12px"><strong>' + r.app + '</strong> v' + (r.appVersion||'?')
      + (r.pluginsDir ? '<span style="color:var(--dim);font-size:11px;margin-left:8px">' + r.pluginsDir + '</span>' : '')
      + (r.dbError ? '<div style="color:var(--red);font-size:12px;margin-top:4px">DB: ' + r.dbError + '</div>' : '')
      + '</div>';
  } else {
    topHtml += '<div class="card" style="margin-bottom:12px;color:var(--dim)">No Qbix app detected. Point --app or --root at a Qbix app directory.</div>';
  }
  
  // Download from URL
  topHtml += '<div class="card" style="margin-bottom:12px">';
  topHtml += '<div style="font-size:12px;margin-bottom:6px;color:var(--dim)">Download plugin from GitHub or URL</div>';
  topHtml += '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
  topHtml += '<input id="qbix-dl-url" type="text" placeholder="https://github.com/Qbix/PluginName" style="flex:1;min-width:200px;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
  topHtml += '<input id="qbix-dl-name" type="text" placeholder="PluginName (optional)" style="width:140px;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
  topHtml += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="downloadPlugin()">Clone</button>';
  topHtml += '</div></div>';
  
  // Installer controls
  topHtml += '<div class="card" style="margin-bottom:12px">';
  topHtml += '<div style="font-size:12px;margin-bottom:6px;color:var(--dim)">Qbix Installer (scripts/Q/install.php)</div>';
  topHtml += '<div style="display:flex;gap:6px;flex-wrap:wrap">';
  topHtml += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'all\')">Install All (--all)</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'plugins\')">--plugins</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'app\')">--app</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'composer\')">--composer</button>';
  topHtml += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="qbixInstall(\'npm\')">--npm</button>';
  topHtml += '</div>';
  topHtml += '<pre id="qbix-install-output" style="display:none;margin-top:8px;font-size:11px;max-height:300px;overflow:auto;white-space:pre-wrap"></pre>';
  topHtml += '</div>';
  
  info.innerHTML = topHtml;
  
  if (!r.plugins || !r.plugins.length) {
    list.innerHTML = '<div class="card"><p style="color:var(--dim)">No plugins found.</p></div>';
    return;
  }
  
  list.innerHTML = r.plugins.map(function(p) {
    var statusBadge = {
      'installed': '<span style="color:var(--grn)">\u2713 installed</span>',
      'available': '<span style="color:var(--dim)">available</span>',
      'upgradable': '<span style="color:var(--yel)">\u2191 upgrade</span>',
      'missing': '<span style="color:var(--red)">\u2717 missing</span>'
    }[p.status] || p.status;
    
    var schemaBadge = '';
    if (p.schemaVersion) {
      schemaBadge = p.schemaStatus === 'current'
        ? ' <span style="color:var(--grn);font-size:11px">schema ' + p.schemaVersion + '</span>'
        : ' <span style="color:var(--yel);font-size:11px">schema ' + p.schemaVersion + ' \u2191</span>';
      if (p.db) schemaBadge += ' <span style="color:var(--dim);font-size:10px">(' + p.db + ')</span>';
    }
    
    var versions = '';
    if (p.availableVersion) versions += '<span style="font-size:11px;color:var(--dim)">v' + p.availableVersion + '</span> ';
    if (p.installedVersion && p.installedVersion !== p.availableVersion) versions += '<span style="font-size:11px;color:var(--dim)">installed: ' + p.installedVersion + '</span> ';
    
    // Package manager badges
    var pkgBadges = '';
    if (p.hasPackageJson) pkgBadges += ' <span style="font-size:9px;padding:1px 4px;border-radius:2px;background:#cb3837;color:#fff" title="Has package.json">npm</span>';
    if (p.hasComposerJson) pkgBadges += ' <span style="font-size:9px;padding:1px 4px;border-radius:2px;background:#885630;color:#fff" title="Has composer.json">composer</span>';
    if (p.hasNodeModules) pkgBadges += ' <span style="font-size:9px;color:var(--grn)" title="node_modules exists">\u2713npm</span>';
    if (p.hasVendor) pkgBadges += ' <span style="font-size:9px;color:var(--grn)" title="vendor exists">\u2713vendor</span>';
    
    var requires = '';
    if (p.requires && Object.keys(p.requires).length) {
      requires = ' <span style="font-size:10px;color:var(--dim)">needs ' + Object.keys(p.requires).join(', ') + '</span>';
    }
    
    var extra = '';
    if (p.extra && Object.keys(p.extra).length) {
      extra = '<details style="margin-top:4px"><summary style="font-size:11px;color:var(--dim);cursor:pointer">extra</summary><pre style="font-size:10px;margin-top:4px;max-height:80px;overflow:auto">' + JSON.stringify(p.extra, null, 2) + '</pre></details>';
    }
    
    // Action buttons
    var bs = 'font-size:10px;padding:2px 7px;margin-left:3px';
    var btns = '';
    if (p.hasDir) {
      btns += '<button class="btn btn-ghost" style="' + bs + '" onclick="viewPluginSchema(\'' + p.name + '\')">Scripts</button>';
      if (p.status === 'available' || p.status === 'upgradable') {
        btns += '<button class="btn btn-primary" style="' + bs + '" onclick="qbixInstall(\'plugin-full\',\'' + p.name + '\')">' + (p.status === 'upgradable' ? 'Upgrade' : 'Install') + '</button>';
      }
      if (p.hasPackageJson && !p.hasNodeModules) {
        btns += '<button class="btn btn-ghost" style="' + bs + '" onclick="qbixNpm(\'install\',\'' + p.name + '\')">npm install</button>';
      }
      if (p.hasPackageJson && p.hasNodeModules) {
        btns += '<button class="btn btn-ghost" style="' + bs + '" onclick="qbixNpm(\'update\',\'' + p.name + '\')">npm update</button>';
      }
    }
    
    return '<div class="card" style="margin-bottom:4px;padding:8px 12px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px">'
      + '<div><strong>' + p.name + '</strong>'
      + (p.declared ? ' <span style="font-size:9px;background:var(--dim);color:var(--bg);padding:1px 4px;border-radius:2px">declared</span>' : '')
      + ' ' + statusBadge + schemaBadge + pkgBadges + ' ' + versions + requires + '</div>'
      + '<div>' + btns + '</div>'
      + '</div>'
      + extra
      + '<pre id="plugin-scripts-' + p.name + '" style="display:none;margin-top:4px;font-size:10px;max-height:120px;overflow:auto"></pre>'
      + '</div>';
  }).join('');
}

async function downloadPlugin() {
  var url = document.getElementById('qbix-dl-url').value.trim();
  var name = document.getElementById('qbix-dl-name').value.trim();
  if (!url) { alert('Enter a URL'); return; }
  var out = document.getElementById('qbix-install-output');
  out.style.display = 'block';
  out.textContent = 'Downloading ' + url + '...';
  var r = await api('frameworks/pkg-download', {framework: 'qbix', source: url, target: name});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) setTimeout(function(){ loadPlugins(); }, 500);
}

async function qbixInstall(action, plugin) {
  var out = document.getElementById('qbix-install-output');
  out.style.display = 'block';
  out.textContent = 'Running installer (' + action + (plugin ? ' ' + plugin : '') + ')...';
  var r = await api('qbix/installer', {action: action, plugin: plugin || ''});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) setTimeout(function(){ loadPlugins(); }, 500);
}

async function qbixNpm(action, target) {
  var out = document.getElementById('qbix-install-output');
  out.style.display = 'block';
  out.textContent = 'Running npm ' + action + ' for ' + target + '...';
  var r = await api('qbix/npm', {action: action, target: target});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) setTimeout(function(){ loadPlugins(); }, 500);
}

async function installQbixPlugin(name) {
  qbixInstall('plugin-full', name);
}

async function viewPluginSchema(name) {
  var el = document.getElementById('plugin-scripts-' + name);
  if (el.style.display !== 'none') { el.style.display = 'none'; return; }
  el.style.display = 'block';
  el.textContent = 'Loading...';
  var r = await api('qbix/plugins/schema', {plugin: name});
  if (r.scripts && r.scripts.length) {
    el.textContent = 'Schema version: ' + (r.schemaVersion || 'none') + '\n\nInstall scripts:\n' + r.scripts.join('\n');
  } else {
    el.textContent = 'No install scripts found for ' + name;
  }
}

// System
// Servers
function showAddServer() { document.getElementById('add-server-form').classList.remove('hidden'); document.getElementById('srv-name').focus(); }
function hideAddServer() { document.getElementById('add-server-form').classList.add('hidden'); }
async function saveServer() {
  var s = { name: document.getElementById('srv-name').value.trim(), host: document.getElementById('srv-host').value.trim(),
    user: document.getElementById('srv-user').value.trim(), path: document.getElementById('srv-path').value.trim(),
    key: document.getElementById('srv-key').value.trim() };
  if (!s.name || !s.host) return alert('Name and host required');
  var r = await api('servers/add', s);
  if (r.error) return alert(r.error);
  hideAddServer(); loadServers();
}
async function deployTo(name) {
  var btn = event.target; btn.disabled = true; btn.textContent = '⏳ Deploying...';
  var r = await api('servers/deploy', {target: name});
  btn.disabled = false; btn.textContent = '⬆ Deploy';
  if (r.error) alert(r.error);
  else alert('✨ Deployed ' + (r.files||0) + ' files to ' + name);
}
async function removeServer(name) {
  if (!confirm('Remove server "' + name + '"?')) return;
  await api('servers/remove', {name: name});
  loadServers();
}
async function loadServers() {
  var d = await api('servers');
  var el = document.getElementById('servers-list');
  if (!d.servers || !d.servers.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No remote servers configured. Add one to deploy your app.</p></div>';
    return;
  }
  el.innerHTML = d.servers.map(function(s) { return ''
    + '<div class="app-row">'
    + '<span class="dot on"></span>'
    + '<span class="app-name">' + s.name + '</span>'
    + '<span class="app-url">' + s.user + '@' + s.host + ':' + s.path + '</span>'
    + '<div class="btn-row">'
    + '<button class="btn btn-sm btn-primary" onclick="deployTo(\'' + s.name + '\')">⬆ Deploy</button>'
    + '<button class="btn btn-sm btn-red" onclick="removeServer(\'' + s.name + '\')">✕</button>'
    + '</div></div>';
  }).join('');
}

async function loadSystem() {
  var d = await detectTools();
  var el = document.getElementById('system-info');
  
  // Key extensions to highlight
  var keyExts = ['pdo_sqlite','pdo_mysql','pdo_pgsql','openssl','curl','mbstring','gd','zip','sockets','pcntl','posix','readline'];
  var extStatus = keyExts.map(function(e) {
    var has = d.extensions && d.extensions.indexOf(e) !== -1;
    return (has ? '<span style="color:var(--grn)">✅</span>' : '<span style="color:var(--red)">❌</span>') + ' ' + e;
  }).join('&nbsp;&nbsp;');
  var extCount = d.extensions ? d.extensions.length : 0;
  
  var items = [
    ['PHP', d.php + ' <span style="font-size:11px;color:var(--dim)">' + extCount + ' extensions</span>'],
    ['OS', d.os + ' ' + d.arch],
    ['Memory Limit', d.memoryLimit],
    ['pcntl', d.hasPcntl ? '✅' : '❌'],
    ['APCu', d.hasApcu ? '✅' : '❌'],
    ['Composer', d.hasComposer ? '✅ installed' : '❌ not found'],
    ['Node.js', d.hasNode ? '✅ installed' : '<span style="color:var(--red)">❌ not found</span>'],
    ['npm', d.hasNpm ? '✅ installed' : '❌ requires Node.js'],
    ['Git', d.hasGit ? '✅ installed' : '❌ not found'],
  ];
  if (d.platform) items.push(['Platform', d.platform]);
  if (d.appDir) items.push(['App Dir', d.appDir]);
  if (d.diskFree) items.push(['Disk Free', d.diskFree]);
  if (d.serverVersion) items.push(['Server', d.serverVersion]);
  
  el.innerHTML = items.map(function(i) {
    return '<div class="card"><div class="stat-lbl">'+i[0]+'</div><div class="stat-val" style="font-size:16px">'+i[1]+'</div></div>';
  }).join('');
  
  // Extensions detail
  el.innerHTML += '<div class="card" style="grid-column:1/-1"><div class="stat-lbl">Key Extensions</div><div style="font-size:12px;line-height:2;margin-top:4px">' + extStatus + '</div>'
    + '<details style="margin-top:8px"><summary style="font-size:11px;color:var(--dim);cursor:pointer">All ' + extCount + ' extensions</summary>'
    + '<div style="font-size:11px;color:var(--dim);margin-top:4px;column-count:3;column-gap:12px">' + (d.extensions||[]).sort().join('<br>') + '</div></details></div>';

  // Platform install section
  var pEl = document.getElementById('platform-status');
  if (d.platform) {
    pEl.innerHTML = '<p style="color:var(--grn)">✅ Platform installed at <code style="font-size:12px">'+d.platform+'</code></p>';
  } else {
    pEl.innerHTML = ''
      + '<p style="color:var(--dim);margin-bottom:12px">Qbix Platform adds user accounts, real-time streams, assets, and 20+ plugins to your app.</p>'
      + '<div class="form-row"><label>Install to</label>'
      + '<input id="platform-dir" value="'+(d.appDir ? d.appDir.replace(/[/\\][^/\\]*$/,'') : '')+'/platform" '
      + 'style="font-size:12px" placeholder="/path/to/install/platform"></div>'
      + '<div class="btn-row">'
      + '<button class="btn btn-primary" onclick="installPlatform()" id="platform-btn">Clone from GitHub</button>'
      + '</div>'
      + '<pre id="platform-log" style="display:none;margin-top:12px;font-size:11px;color:var(--dim);max-height:200px;overflow:auto;background:rgba(0,0,0,.2);padding:8px;border-radius:4px"></pre>';
  }
}

async function installPlatform() {
  var dir = document.getElementById('platform-dir').value.trim();
  if (!dir) return alert('Enter a directory path');
  var btn = document.getElementById('platform-btn');
  var log = document.getElementById('platform-log');
  btn.disabled = true; btn.textContent = '⏳ Cloning...';
  log.style.display = 'block'; log.textContent = 'git clone https://github.com/Qbix/Platform.git ' + dir + '\n';
  try {
    var r = await api('platform/install', {dir: dir});
    if (r.error) { log.textContent += '\n⚠ ' + r.error; log.style.color = 'var(--red)'; }
    else { log.textContent += r.output + '\n✅ Done! Refresh to see plugins.'; log.style.color = 'var(--grn)'; }
  } catch(e) { log.textContent += '\nError: ' + e.message; log.style.color = 'var(--red)'; }
  btn.disabled = false; btn.textContent = 'Clone from GitHub';
}

// ── Domains ─────────────────────────────────────────
// ── Domains in use: every name this server answers to, and where it comes from ──
async function loadDomainUsage() {
  var el = document.getElementById('domains-usage');
  if (!el) return;
  var r;
  try { r = await api('domains/usage'); } catch (e) { return; }
  if (!r || !r.hosts) { el.innerHTML = ''; return; }
  var li = (r.listeners || []).map(function(l){ return escH(l.scheme + '://' + (l.host || '*') + ':' + l.port); }).join(', ');
  var c = r.certificate;
  var cert = c ? escH((c.names || []).join(', ')) + (c.daysLeft != null ? ' <span style="color:' + (c.daysLeft < 14 ? 'var(--red)' : c.daysLeft < 30 ? 'var(--yel)' : 'var(--grn)') + '">(' + escH(c.daysLeft) + ' days left)</span>' : '') : '<span style="color:var(--dim)">no certificate presented</span>';
  var stLabel = {serving:'serving', covered:'certificate covers it', unconfigured:'seen, not configured', unseen:'configured, not seen'};
  var stColor = {serving:'var(--grn)', covered:'var(--ac)', unconfigured:'var(--yel)', unseen:'var(--dim)'};
  var rows = r.hosts.map(function(h) {
    var src = (h.sources || []).map(function(s){ return '<span class="dom-src" title="' + escH(s.detail) + '">' + escH(s.source) + '</span>'; }).join(' ');
    var st = (h.states || []).map(function(s){ return '<span style="color:' + (stColor[s] || 'var(--dim)') + '">' + escH(stLabel[s] || s) + '</span>'; }).join(' · ');
    var seen = h.seen ? escH(h.seen.count) + ' req, last ' + escH(new Date(h.seen.last * 1000).toLocaleTimeString()) : '';
    var cur = h.status || 'active';
    var sel = '<select aria-label="Status of ' + escH(h.host) + '" onchange="setDomainStatus(\'' + escH(h.host) + '\', this.value, this)" data-was="' + escH(cur) + '">'
      + ['active','suspended','disabled'].map(function(s){ return '<option value="' + s + '"' + (s === cur ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select>';
    var scheme = h.https ? 'https://' : 'http://';
    return '<tr><td><a href="' + scheme + escH(h.host) + '/" target="_blank" rel="noopener">' + escH(h.host) + '</a></td><td>' + src + '</td><td>' + st + (seen ? '<div style="font-size:12px;color:var(--dim)">' + seen + '</div>' : '') + '</td><td>' + (!h.ip && (h.record || h.seen || src) ? sel : '') + '</td></tr>';
  }).join('');
  el.innerHTML = '<div class="card" style="margin-bottom:16px"><h3 style="font-size:14px;margin-bottom:8px">In use</h3>'
    + '<div style="font-size:12px;color:var(--dim);margin-bottom:4px">Listening: ' + (li || 'unknown') + '</div>'
    + '<div style="font-size:12px;color:var(--dim);margin-bottom:10px">Certificate: ' + cert + '</div>'
    + (rows ? '<div class="dom-usage-wrap"><table class="dom-usage"><thead><tr><th>Host</th><th>From</th><th>State</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            : '<p style="color:var(--dim)">No domains seen or configured yet.</p>')
    + '</div>';
}
async function setDomainStatus(host, status, sel) {
  if (status !== 'active' && !confirm('Set ' + host + ' to ' + status + '? ' + (status === 'suspended' ? 'Visitors get a 503 "temporarily suspended" page.' : 'The server stops serving it (404).'))) {
    if (sel) sel.value = sel.getAttribute('data-was') || 'active';
    return;
  }
  var r = await api('domains/status', {domain: host, status: status, confirm: true});
  if (r && r.error) { alert(r.error); if (sel) sel.value = sel.getAttribute('data-was') || 'active'; return; }
  loadDomains();
}
async function loadDomains() {
  loadDomainUsage();
  var r = await api('domains');
  var el = document.getElementById('domains-list');
  if (!r.domains || !r.domains.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No domains configured. Add one below, or set <code>Q.webserver.domains</code> in config.</p></div>';
  } else {
    domainCache = {};
    r.domains.forEach(function(d) { domainCache[d.domain] = d; });
    el.innerHTML = r.domains.map(function(d) {
      var btns = ' <button class="btn btn-ghost" style="font-size:11px;padding:4px 10px;color:var(--red)" onclick="removeDomain(\'' + d.domain + '\')">Remove</button>';
      return '<div class="card" style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px"><div><strong>' + escH(d.domain) + '</strong></div><div>' + btns + '</div></div>'
        + '<div class="dom-cert" id="' + certBoxId(d.domain) + '"><span style="color:var(--dim);font-size:12px">Checking the certificate\u2026</span></div>'
        + '<div class="dom-traffic" id="' + trafficBoxId(d.domain) + '"></div>'
        + (d.source === 'panel' ? domainEditor(d) : (d.root ? '<div style="font-size:12px;color:var(--dim);margin-top:4px">Root: ' + escH(d.root) + ' (from config)</div>' : ''))
        + '</div>';
    }).join('');
    r.domains.forEach(function(d) { loadDomainCert(d.domain); loadDomainTraffic(d.domain); });
  }
  loadHosts();
}
// Per-domain editor: document root, aliases, subdomains.
function domainEditor(d) {
  var id = 'dom-' + d.domain.replace(/[^a-z0-9]/g, '-');
  var D = escH(d.domain);
  var rootWarn = d.root && !d.rootResolved ? ' <span style="color:var(--red)">not an existing directory; served from the default root</span>' : '';
  var aliases = (d.aliases || []).map(function(a) {
    return '<span class="dom-chip">' + escH(a) + ' <button class="dom-x" aria-label="Remove alias ' + escH(a) + '" onclick="domainAlias(\'' + D + '\', \'' + escH(a) + '\', false)">&times;</button></span>';
  }).join(' ');
  var subs = Object.keys(d.subdomains || {}).map(function(k) {
    return '<tr><td>' + escH(k) + '</td><td><code>' + escH(d.subdomains[k]) + '</code></td><td><button class="btn btn-ghost" style="font-size:12px;padding:3px 8px;color:var(--red)" onclick="domainSubdomain(\'' + D + '\', \'' + escH(k) + '\', null)">Remove</button></td></tr>';
  }).join('');
  return '<div class="dom-edit">'
    + '<label for="' + id + '-root">Document root</label>'
    + '<div class="dom-row"><input id="' + id + '-root" value="' + escH(d.root || '') + '" placeholder="/srv/example.com/web (empty: server default)"><button class="btn btn-ghost" onclick="domainRoot(\'' + D + '\', \'' + id + '\')">Save root</button></div>' + rootWarn
    + '<label for="' + id + '-alias">Aliases</label>'
    + '<div class="dom-row">' + (aliases || '<span style="color:var(--dim)">none</span>') + '</div>'
    + '<div class="dom-row"><input id="' + id + '-alias" placeholder="www.' + D + '"><button class="btn btn-ghost" onclick="domainAlias(\'' + D + '\', document.getElementById(\'' + id + '-alias\').value, true)">Add alias</button></div>'
    + '<label for="' + id + '-sub">Subdomains</label>'
    + (subs ? '<table class="dom-usage"><thead><tr><th>Name</th><th>Root</th><th></th></tr></thead><tbody>' + subs + '</tbody></table>' : '')
    + '<div class="dom-row"><input id="' + id + '-sub" placeholder="blog" aria-label="Subdomain name" oninput="previewDomainRoot(\'' + D + '\', this.value, document.getElementById(\'' + id + '-subroot\'))"><input id="' + id + '-subroot" placeholder="empty: the standard folder" aria-label="Subdomain root"><button class="btn btn-ghost" onclick="domainSubdomain(\'' + D + '\', document.getElementById(\'' + id + '-sub\').value, document.getElementById(\'' + id + '-subroot\').value)">Add subdomain</button></div>'
    + domainHostingEditor(d, id, D)
    + '</div>';
}
// Redirects, HSTS and error documents (stored per domain; applied before routing).
function domainHostingEditor(d, id, D) {
  var rd = d.redirects || {}, h = d.hsts || {}, ed = d.errorDocs || {};
  var rules = (rd.rules || []).map(function(r, i) {
    return '<tr><td>' + escH(r.match || 'prefix') + '</td><td><code>' + escH(r.from || '') + '</code></td><td><code>' + escH(r.to || '') + '</code></td><td>' + (r.code === 302 ? 302 : 301) + (r.keepQuery ? ' +query' : '') + '</td><td><button class="btn btn-ghost" style="font-size:12px;padding:3px 8px;color:var(--red)" aria-label="Remove redirect ' + (i + 1) + '" onclick="domainRuleRemove(\'' + D + '\', ' + i + ')">Remove</button></td></tr>';
  }).join('');
  var docs = [403, 404, 500, 503].map(function(c) {
    return '<div class="dom-row"><label for="' + id + '-ed' + c + '" style="min-width:3em">' + c + '</label><input id="' + id + '-ed' + c + '" value="' + escH(ed[c] || '') + '" placeholder="errors/' + c + '.html (under the document root)"></div>';
  }).join('');
  return '<label>Redirects</label>'
    + '<div class="dom-row"><label class="dom-check"><input type="checkbox" id="' + id + '-https"' + (rd.https ? ' checked' : '') + '> HTTP → HTTPS (301)</label>'
    + '<label for="' + id + '-pref">Preferred host</label><select id="' + id + '-pref"><option value="">either</option><option value="www"' + (rd.preferredHost === 'www' ? ' selected' : '') + '>www.' + D.replace(/^www\./, '') + '</option><option value="bare"' + (rd.preferredHost === 'bare' ? ' selected' : '') + '>' + D.replace(/^www\./, '') + '</option></select>'
    + '<button class="btn btn-ghost" onclick="domainRedirectsSave(\'' + D + '\', \'' + id + '\')">Save redirects</button></div>'
    + (rules ? '<div class="dom-usage-wrap"><table class="dom-usage"><thead><tr><th>Match</th><th>From</th><th>To</th><th>Code</th><th></th></tr></thead><tbody>' + rules + '</tbody></table></div>' : '')
    + '<div class="dom-row"><select id="' + id + '-rmatch" aria-label="Match"><option value="prefix">path prefix</option><option value="exact">exact path</option><option value="host">whole host</option></select>'
    + '<input id="' + id + '-rfrom" placeholder="/old or blog.' + D + '" aria-label="From"><input id="' + id + '-rto" placeholder="https://' + D + '/new" aria-label="To">'
    + '<select id="' + id + '-rcode" aria-label="Code"><option>301</option><option>302</option></select>'
    + '<label class="dom-check"><input type="checkbox" id="' + id + '-rq"> keep query</label>'
    + '<button class="btn btn-ghost" onclick="domainRuleAdd(\'' + D + '\', \'' + id + '\')">Add redirect</button></div>'
    + '<label>HSTS</label>'
    + '<div class="dom-row"><label class="dom-check"><input type="checkbox" id="' + id + '-hsts"' + (h.enabled ? ' checked' : '') + '> send Strict-Transport-Security</label>'
    + '<input id="' + id + '-hage" type="number" min="0" max="63072000" value="' + escH(String(h.maxAge != null ? h.maxAge : 31536000)) + '" aria-label="max-age seconds" style="max-width:10em">'
    + '<label class="dom-check"><input type="checkbox" id="' + id + '-hsub"' + (h.includeSubDomains ? ' checked' : '') + '> includeSubDomains</label>'
    + '<button class="btn btn-ghost" onclick="domainHstsSave(\'' + D + '\', \'' + id + '\')">Save HSTS</button></div>'
    + '<label>Error documents</label>' + docs
    + '<div class="dom-row"><button class="btn btn-ghost" onclick="domainErrorDocsSave(\'' + D + '\', \'' + id + '\')">Save error documents</button></div>';
}
var domainCache = {};
function domainRedirectBody(domain, id, rules) {
  return {domain: domain, https: document.getElementById(id + '-https').checked,
    preferredHost: document.getElementById(id + '-pref').value || null, rules: rules};
}
function domainRulesOf(domain) { return ((domainCache[domain] || {}).redirects || {}).rules || []; }
function domainRedirectsSave(domain, id) { return domainPost('domains/redirects', domainRedirectBody(domain, id, domainRulesOf(domain))); }
function domainRuleAdd(domain, id) {
  var rule = {match: document.getElementById(id + '-rmatch').value, from: document.getElementById(id + '-rfrom').value.trim(),
    to: document.getElementById(id + '-rto').value.trim(), code: parseInt(document.getElementById(id + '-rcode').value, 10),
    keepQuery: document.getElementById(id + '-rq').checked};
  if (!rule.from || !rule.to) return alert('Enter where from and where to');
  return domainPost('domains/redirects', domainRedirectBody(domain, id, domainRulesOf(domain).concat([rule])));
}
function domainRuleRemove(domain, i) {
  var d = domainCache[domain] || {}, rd = d.redirects || {};
  var rules = (rd.rules || []).filter(function(_, j) { return j !== i; });
  return domainPost('domains/redirects', {domain: domain, https: !!rd.https, preferredHost: rd.preferredHost || null, rules: rules});
}
function domainHstsSave(domain, id) {
  return domainPost('domains/hsts', {domain: domain, enabled: document.getElementById(id + '-hsts').checked,
    maxAge: parseInt(document.getElementById(id + '-hage').value, 10), includeSubDomains: document.getElementById(id + '-hsub').checked});
}
function domainErrorDocsSave(domain, id) {
  var docs = {};
  [403, 404, 500, 503].forEach(function(c) { var v = document.getElementById(id + '-ed' + c).value.trim(); if (v) docs[c] = v; });
  return domainPost('domains/errordocs', {domain: domain, docs: docs});
}
async function domainPost(route, body) {
  var r = await api(route, body);
  if (r && r.status === 409 && r.confirm) {
    var q = r.create ? 'Create ' + r.root + ' (0755, owned like its parent)?' : (r.error || 'Are you sure?');
    if (!confirm(q + '\n\nProceed?')) return null;
    body.confirm = true;
    if (r.create) body.create = true;
    r = await api(route, body);
  }
  if (r && r.error) { alert(r.error); return null; }
  loadDomains();
  return r;
}
function domainRoot(domain, id) { return domainPost('domains/root', {domain: domain, root: document.getElementById(id + '-root').value.trim()}); }
function domainAlias(domain, alias, add) {
  alias = (alias || '').trim(); if (!alias) return alert('Enter an alias');
  var b = {domain: domain}; b[add ? 'add' : 'remove'] = alias;
  return domainPost('domains/alias', b);
}
function domainSubdomain(domain, name, root) {
  name = (name || '').trim(); if (!name) return alert('Enter a subdomain name');
  if (root === null) return domainPost('domains/subdomain', {domain: domain, name: name, remove: true});
  return domainPost('domains/subdomain', {domain: domain, name: name, root: (root || '').trim()});
}
async function loadHosts() {
  var r = await api('domains/hosts');
  var el = document.getElementById('hosts-info');
  if (!el) return;
  if (r.error) { el.innerHTML = '<div class="card"><p style="color:var(--dim)">' + r.error + '</p></div>'; return; }
  var html = '<h3 style="font-size:14px;margin:16px 0 8px">System Hosts <span style="font-size:11px;color:var(--dim)">(' + r.path + ')</span></h3>';
  if (r.domains && r.domains.length) {
    html += r.domains.map(function(d) {
      if (d.inHosts) {
        return '<div class="card" style="margin-bottom:6px;padding:8px 12px"><span style="color:var(--grn)">\u2713</span> <strong>' + d.domain + '</strong> \u2192 ' + d.hostsIp + '</div>';
      }
      var isLocalhost = d.domain.endsWith('.localhost');
      if (isLocalhost) {
        return '<div class="card" style="margin-bottom:6px;padding:8px 12px"><span style="color:var(--grn)">\u2713</span> <strong>' + d.domain + '</strong> <span style="color:var(--dim)">(resolves via .localhost)</span></div>';
      }
      return '<div class="card" style="margin-bottom:6px;padding:8px 12px"><span style="color:var(--yel)">\u26a0</span> <strong>' + d.domain + '</strong> <span style="color:var(--dim)">not in hosts</span>'
        + ' <button class="btn btn-primary" style="font-size:11px;padding:3px 8px;margin-left:8px" onclick="addHostsEntry(\'' + d.domain + '\')">Add to hosts</button></div>';
    }).join('');
  } else {
    html += '<div class="card"><p style="color:var(--dim)">No domains configured.</p></div>';
  }
  el.innerHTML = html;
}
async function addHostsEntry(hostname, ip) {
  ip = ip || '127.0.0.1';
  var r = await api('domains/hosts/add', {hostname: hostname, ip: ip});
  if (r.already) { alert(hostname + ' is already in your hosts file.'); return; }
  if (r.conflict) { alert(hostname + ' is mapped to ' + r.existingIp + ' (not ' + r.requestedIp + '). Edit your hosts file manually to change it.'); return; }
  if (r.added) { alert('Added ' + hostname + ' \u2192 ' + ip); loadHosts(); return; }
  if (r.needsElevation) {
    var cmd = r.commands.gui || r.commands.command;
    if (confirm(hostname + ' needs admin access to add to ' + r.entry + '.\n\nRun this command in your terminal:\n\n' + r.commands.command + '\n\nCopy to clipboard?')) {
      try { navigator.clipboard.writeText(r.commands.command); } catch(e) {}
    }
  }
}
async function addDomain() {
  var name = document.getElementById('dom-name').value.trim();
  if (!name) return alert('Enter a domain');
  var r = await domainPost('domains/add', {domain:name, root:document.getElementById('dom-root').value.trim()||null, app:document.getElementById('dom-app').value.trim()||null, tls:document.getElementById('dom-tls').value});
  if (r) { document.getElementById('dom-name').value=''; previewDomainRoot(); }
}
// Show the standard root a new domain (or subdomain) gets when none is typed.
async function previewDomainRoot(domain, name, input) {
  input = input || document.getElementById('dom-root');
  domain = domain !== undefined ? domain : (document.getElementById('dom-name') || {}).value || '';
  if (!input) return;
  if (!domain.trim() || (name !== undefined && !String(name).trim())) { input.placeholder = input.getAttribute('data-ph') || input.placeholder; return; }
  if (!input.getAttribute('data-ph')) input.setAttribute('data-ph', input.placeholder);
  try {
    var r = await api('domains/defaults', name !== undefined ? {domain: domain.trim(), name: String(name).trim()} : {domain: domain.trim()});
    if (r && r.root) input.placeholder = 'Default: ' + r.root + (r.exists ? '' : ' (will be created)');
  } catch (e) {}
}
async function removeDomain(n) { if(!confirm('Remove '+n+'?'))return; var r = await api('domains/remove',{domain:n, confirm:true}); if (r && r.error) alert(r.error); loadDomains(); }
// ── A domain's traffic, counted since the server started ──
function trafficBoxId(domain) { return 'traffic-' + String(domain).replace(/[^a-z0-9]/g, '-'); }
async function loadDomainTraffic(domain) {
  var box = document.getElementById(trafficBoxId(domain));
  if (!box) return;
  var r;
  try { r = await api('domains/traffic?domain=' + encodeURIComponent(domain)); } catch (e) { return; }
  if (!r || r.error) { box.innerHTML = '<span style="color:var(--red);font-size:12px">' + escH((r && r.error) || 'could not read the traffic') + '</span>'; return; }
  var logs = '/Q/panel/(tab)/logs/(host)/' + encodeURIComponent(domain);
  var cls = r.classes || {};
  var h = '<div class="cert-head">Traffic <span style="font-weight:400">' + escH(r.window || '') + ' (' + new Date((r.since || 0) * 1000).toISOString().slice(0, 16).replace('T', ' ') + ')</span></div>'
    + '<div class="cert-grid">'
    + '<span>Requests</span><span>' + (r.requests || 0) + '</span>'
    + '<span>Sent</span><span>' + fmtBytesPlain(r.bytes || 0) + '</span>'
    + '<span>Status</span><span class="traffic-classes">'
    + ['2xx', '3xx', '4xx', '5xx'].map(function (k) { return '<b class="tc-' + k + '">' + k + ' ' + (cls[k] || 0) + '</b>'; }).join(' ') + '</span>'
    + '<span>Last seen</span><span>' + (r.last ? new Date(r.last * 1000).toISOString().slice(0, 19).replace('T', ' ') : 'not yet') + '</span>'
    + '</div>';
  if (r.top && r.top.length) {
    h += '<div class="traffic-top">' + r.top.map(function (t) { return '<div><span>' + escH(t.path) + '</span><b>' + t.count + '</b></div>'; }).join('') + '</div>';
  }
  h += '<a class="btn btn-ghost traffic-logs" href="' + logs + '" onclick="event.preventDefault();openHostLogs(\'' + escH(domain) + '\')">View its log lines</a>';
  box.innerHTML = h;
}
function openHostLogs(domain) {
  var params = getViewParams();
  params.tab = 'logs'; params.host = domain;
  history.pushState({viewParams: params}, '', buildViewParamUrl(params));
  showTab('logs', {silent: true});
}

// ── A domain's certificate: which one covers it, and issuing one ──
function certBoxId(domain) { return 'cert-' + String(domain).replace(/[^a-z0-9]/g, '-'); }
function certDate(t) { return t ? new Date(t * 1000).toISOString().slice(0, 10) : '?'; }
async function loadDomainCert(domain) {
  var box = document.getElementById(certBoxId(domain));
  if (!box) return;
  var r;
  try { r = await api('domains/cert?domain=' + encodeURIComponent(domain)); } catch (e) { return; }
  if (!r || r.error) { box.innerHTML = '<span style="color:var(--red);font-size:12px">' + escH((r && r.error) || 'could not read the certificate') + '</span>'; return; }
  var c = r.certificate, h = '<div class="cert-head">Certificate</div>';
  if (c) {
    var days = c.daysLeft, cls = days === null ? '' : days < 0 ? 'cert-bad' : days <= 21 ? 'cert-warn' : 'cert-ok';
    h += '<div class="cert-grid">'
      + '<span>Issuer</span><span>' + escH(c.issuer || '?') + (c.selfSigned ? ' <em class="cert-warn">(self-signed)</em>' : '') + '</span>'
      + '<span>Valid</span><span>' + certDate(c.notBefore) + ' → ' + certDate(c.notAfter) + ' <b class="' + cls + '">' + (days === null ? '' : days < 0 ? 'expired' : days + ' days left') + '</b></span>'
      + '<span>Names</span><span>' + (c.names || []).map(escH).join(', ') + '</span>'
      + '<span>Served</span><span>' + (r.served ? 'yes, by the HTTPS listener' : 'no') + '</span>'
      + '</div>';
  } else {
    h += '<div style="font-size:12px;color:var(--dim)">No certificate is being served.</div>';
  }
  var cov = r.covered || {};
  h += '<div class="cert-hosts">' + Object.keys(cov).map(function (k) {
    return '<span class="dom-chip ' + (cov[k] ? 'cert-ok' : 'cert-bad') + '">' + (cov[k] ? '✓ ' : '✗ ') + escH(k) + '</span>';
  }).join(' ') + '</div>';
  (r.warnings || []).forEach(function (w) { h += '<div class="cert-warn" style="font-size:12px">⚠ ' + escH(w) + '</div>'; });
  if (r.canIssue) {
    h += '<div class="dom-row" style="margin-top:6px"><button class="btn btn-primary" onclick="issueDomainCert(\'' + escH(domain) + '\')">' + (c && !r.notCovered.length ? 'Renew for this domain' : 'Issue for this domain') + '</button>'
      + '<span class="cert-job" id="' + certBoxId(domain) + '-job">' + certJobText(r.job) + '</span></div>';
  } else {
    h += '<div style="font-size:12px;color:var(--dim);margin-top:6px">Issuing here is not available: ' + escH(r.issueWhy || '') + '</div>';
  }
  box.innerHTML = h;
  if (r.job && (r.job.state === 'running' || r.job.state === 'queued')) pollCertJob(domain, r.job.job);
}
function certJobText(j) {
  if (!j || !j.state || j.state === 'none') return '';
  if (j.state === 'running') return 'Issuing…';
  if (j.state === 'queued') return 'Queued…';
  if (j.state === 'succeeded') return '<span class="cert-ok">Issued ' + certDate(j.lastSuccess) + '</span>';
  return '<span class="cert-bad">Failed: ' + escH(j.error || 'unknown error') + (j.nextAttempt ? ' (next try ' + new Date(j.nextAttempt * 1000).toLocaleString() + ')' : '') + '</span>';
}
async function issueDomainCert(domain) {
  var r = await api('domains/cert/issue', {domain: domain});
  if (r && r.confirm) {
    if (!confirm(r.error)) return;
    r = await api('domains/cert/issue', {domain: domain, confirm: true});
  }
  if (!r || r.error) { alert((r && r.error) || 'Could not start'); return; }
  var el = document.getElementById(certBoxId(domain) + '-job');
  if (el) el.innerHTML = certJobText(r);
  pollCertJob(domain, r.job);
}
var certPolls = {};
function pollCertJob(domain, id) {
  if (!id || certPolls[domain]) return;
  certPolls[domain] = setInterval(async function () {
    var j;
    try { j = await api('domains/cert/job?id=' + encodeURIComponent(id)); } catch (e) { j = null; }
    var el = document.getElementById(certBoxId(domain) + '-job');
    if (!el || !j || j.error) { clearInterval(certPolls[domain]); delete certPolls[domain]; return; }
    el.innerHTML = certJobText(j);
    if (j.state !== 'running' && j.state !== 'queued') { clearInterval(certPolls[domain]); delete certPolls[domain]; if (j.state === 'succeeded') setTimeout(function () { loadDomainCert(domain); }, 1500); }
  }, 3000);
}
async function provisionCert(n) { return issueDomainCert(n); }

// ── SSL ─────────────────────────────────────────────
// The server's certificate administration: what is served, every certificate
// by expiry, the settings the panel may change, renew and reload, and the
// history. The API never returns key material; key files are shown by path.
function sslDays(c) {
  if (!c || c.daysLeft === null || c.daysLeft === undefined) return '';
  var cls = {expired: 'cert-bad', critical: 'cert-bad', warning: 'cert-warn', ok: 'cert-ok'}[c.state] || '';
  return '<b class="' + cls + '">' + (c.daysLeft < 0 ? 'expired' : c.daysLeft + ' days left') + '</b>';
}
function sslKey(k) { return k ? escH(k.type + (k.curve ? ' ' + k.curve : ' ' + k.bits)) : '?'; }
function sslTime(t) { return t ? new Date(t * 1000).toLocaleString() : 'not yet'; }
async function loadSsl() { loadSslOverview(); loadSslCerts(); loadSslHistory(); }
async function loadSslOverview() {
  var el = document.getElementById('ssl-overview'), r;
  try { r = await api('ssl/overview'); } catch (e) { return; }
  if (!r || r.error) { el.innerHTML = '<div class="card"><span class="cert-bad">' + escH((r && r.error) || 'Could not read the SSL state') + '</span></div>'; return; }
  var c = r.served, h = '<div class="card"><h3>Served certificate</h3>';
  if (c) {
    h += '<div class="cert-grid">'
      + '<span>Mode</span><span><b>' + escH(r.mode) + '</b>' + (r.canIssue ? ' — this server issues and renews it' : ' — issuing not offered (see below)') + '</span>'
      + (r.configured && r.configured.cert ? '<span>Configured</span><span><code style="font-size:11px">' + escH(r.configured.cert) + '</code></span>' : '')
      + '<span>Issuer</span><span>' + escH(c.issuer || '?') + (c.selfSigned ? ' <em class="cert-warn">(self-signed)</em>' : '') + '</span>'
      + '<span>Names</span><span>' + (c.names || []).map(escH).join(', ') + '</span>'
      + '<span>Valid</span><span>' + certDate(c.notBefore) + ' → ' + certDate(c.notAfter) + ' ' + sslDays(c) + '</span>'
      + '<span>Key</span><span>' + sslKey(c.key) + '</span>'
      + '<span>SHA-256</span><span><code style="font-size:11px">' + escH(c.fingerprint) + '</code></span>'
      + '<span>File</span><span><code style="font-size:11px">' + escH(c.file) + '</code></span>'
      + '</div>';
  } else {
    h += '<p style="color:var(--dim);font-size:12px">No certificate is being served (mode <b>' + escH(r.mode) + '</b>; HTTPS may be off).</p>';
  }
  h += '<div class="cert-grid" style="margin-top:8px">'
    + '<span>Fallback</span><span>' + escH(r.fallback) + (r.usingFallback ? ' <b class="cert-warn">— in use now: the configured certificate could not be loaded</b>' : ' (not in use)') + '</span>'
    + '<span>Watcher</span><span>every ' + escH(r.watch.interval) + ' s; last check ' + escH(sslTime(r.watch.lastCheck)) + '; last change ' + escH(sslTime(r.watch.lastChange)) + '</span>'
    + '</div>';
  h += '<div class="btn-row" style="margin-top:10px">';
  if (r.canIssue) h += '<button class="btn btn-primary" onclick="sslRenew()">Renew / issue now</button>';
  h += '<button class="btn btn-ghost" onclick="sslReload()">Reload certificate</button><span class="cert-job" id="ssl-job"></span></div>';
  if (!r.canIssue) h += '<p style="font-size:12px;color:var(--dim);margin-top:6px">Issuing is not offered here: ' + escH(r.issueWhy) + '</p>';
  el.innerHTML = h + '</div>';
  sslSettingsForm(r);
}
async function loadSslCerts() {
  var el = document.getElementById('ssl-certs'), r;
  try { r = await api('ssl/certs'); } catch (e) { return; }
  if (!r || r.error || !r.certificates) { el.innerHTML = '<span class="cert-bad">' + escH((r && r.error) || 'Could not list the certificates') + '</span>'; return; }
  if (!r.certificates.length) { el.innerHTML = '<p style="color:var(--dim);font-size:12px">No certificates found.</p>'; return; }
  el.innerHTML = '<div class="ssl-list">' + r.certificates.map(function (c) {
    return '<div class="ssl-cert ssl-' + escH(c.state) + '"><div class="ssl-cert-top"><b>' + escH((c.names || [])[0] || c.subject || '?') + '</b> ' + sslDays(c) + '</div>'
      + '<div class="ssl-cert-meta">' + escH((c.source || []).join(', ')) + ' · ' + escH(c.issuer || '?') + ' · until ' + certDate(c.notAfter) + ' · ' + sslKey(c.key) + '</div>'
      + ((c.names || []).length > 1 ? '<div class="ssl-cert-meta">' + c.names.map(escH).join(', ') + '</div>' : '')
      + '<div class="ssl-cert-meta"><code>' + escH(c.file) + '</code></div></div>';
  }).join('') + '</div>';
}
function sslSettingsForm(r) {
  var s = r.settings || {}, el = document.getElementById('ssl-settings');
  var src = function (k) { return '<span class="ssl-src">' + escH((s[k] || {}).source || '') + '</span>'; };
  var v = function (k) { var x = (s[k] || {}).value; return x === null || x === undefined ? '' : x; };
  el.innerHTML = '<div class="form-row"><label for="ssl-mode">Mode</label><select id="ssl-mode">'
    + (r.modes || []).map(function (m) { return '<option' + (m === v('mode') ? ' selected' : '') + '>' + escH(m) + '</option>'; }).join('')
    + '</select>' + src('mode') + '</div>'
    + '<div class="form-row"><label for="ssl-email">ACME email</label><input id="ssl-email" value="' + escH(v('email')) + '" placeholder="admin@example.com">' + src('email') + '</div>'
    + '<div class="form-row"><label for="ssl-dir">Directory</label><input id="ssl-dir" value="' + escH(v('directory')) + '" placeholder="letsencrypt, letsencrypt-staging or https://…">' + src('directory') + '</div>'
    + '<div class="form-row"><label for="ssl-renew">Renew at</label><input id="ssl-renew" type="number" step="0.01" min="0.05" max="0.9" value="' + escH(v('renewAt')) + '">' + src('renewAt') + '</div>'
    + '<div class="form-row"><label for="ssl-hosts">Hosts</label><input id="ssl-hosts" value="' + escH((v('domains') || []).join(', ')) + '" placeholder="example.com, www.example.com">' + src('domains') + '</div>'
    + '<p style="font-size:12px;color:var(--dim)">Renew at is the share of the lifetime left when renewal starts. A mode change takes effect when the server restarts; the others at the next certificate check. Keys are never shown here.</p>'
    + '<div class="btn-row" style="margin-top:8px"><button class="btn btn-primary" onclick="sslSave()">Save settings</button></div>';
}
async function sslSave() {
  var b = {
    mode: document.getElementById('ssl-mode').value,
    email: document.getElementById('ssl-email').value.trim(),
    directory: document.getElementById('ssl-dir').value.trim() || 'letsencrypt',
    renewAt: document.getElementById('ssl-renew').value,
    domains: document.getElementById('ssl-hosts').value
  };
  var r = await api('ssl/settings', b);
  if (r && r.confirm) {
    var list = Object.keys(r.changes || {}).map(function (k) { return k + ': ' + JSON.stringify(r.changes[k].from) + ' → ' + JSON.stringify(r.changes[k].to); }).join('\n');
    if (!confirm(r.error + '\n\n' + list)) return;
    b.confirm = true;
    r = await api('ssl/settings', b);
  }
  if (!r || r.error) { alert((r && r.error) || 'Could not save'); return; }
  if (r.note) alert(r.note);
  loadSsl();
}
async function sslRenew() {
  var r = await api('ssl/renew', {});
  if (r && r.confirm) { if (!confirm(r.error)) return; r = await api('ssl/renew', {confirm: true}); }
  if (!r || r.error) { alert((r && r.error) || 'Could not start'); return; }
  var el = document.getElementById('ssl-job');
  if (el) el.innerHTML = certJobText(r);
  sslPoll();
}
var sslPollTimer = null;
function sslPoll() {
  if (sslPollTimer) return;
  sslPollTimer = setInterval(async function () {
    var h;
    try { h = await api('ssl/history?limit=20'); } catch (e) { h = null; }
    var j = h && h.jobs ? h.jobs.filter(function (x) { return x.state === 'running' || x.state === 'queued'; }) : [];
    var el = document.getElementById('ssl-job');
    if (!h || !el || !j.length) { clearInterval(sslPollTimer); sslPollTimer = null; loadSsl(); return; }
    el.innerHTML = certJobText(j[0]);
  }, 3000);
}
async function sslReload() {
  var r = await api('ssl/reload', {});
  if (!r || r.error) { alert((r && r.error) || 'Could not reload'); return; }
  var el = document.getElementById('ssl-job');
  if (el) el.innerHTML = r.changed ? '<span class="cert-ok">Reloaded: a new certificate is served</span>' : 'Reloaded: the same certificate is served';
  loadSslCerts(); loadSslHistory();
}
async function loadSslHistory() {
  var el = document.getElementById('ssl-history'), r;
  try { r = await api('ssl/history?limit=100'); } catch (e) { return; }
  if (!r || r.error) { el.innerHTML = '<span class="cert-bad">' + escH((r && r.error) || 'Could not read the history') + '</span>'; return; }
  var h = '';
  (r.jobs || []).forEach(function (j) { h += '<div class="ssl-job">Issuing job <b>' + escH(j.name) + '</b>: ' + certJobText(j) + '</div>'; });
  if (!r.entries.length) h += '<p style="color:var(--dim);font-size:12px">Nothing recorded yet.</p>';
  else h += '<div class="ssl-hist">' + r.entries.map(function (e) {
    var bad = /error|failed|exhausted/.test(e.event);
    var info = Object.keys(e.info || {}).map(function (k) { var x = e.info[k]; return escH(k) + ': ' + escH(Array.isArray(x) ? x.join(', ') : x); }).join(' · ');
    return '<div class="ssl-hist-row"><span class="ssl-hist-t">' + escH(sslTime(e.time)) + '</span><b class="' + (bad ? 'cert-bad' : '') + '">' + escH(e.event) + (e.count > 1 ? ' ×' + escH(e.count) : '') + '</b><span class="ssl-hist-i">' + info + '</span></div>';
  }).join('') + '</div>';
  el.innerHTML = h + '<p style="font-size:11px;color:var(--dim);margin-top:6px">' + escH(r.total) + ' entries kept in <code>' + escH(r.file) + '</code></p>';
}

// ── Two-factor authentication ───────────────────────
// Manage the panel's own second factor. The secret and the recovery codes are
// shown once, here, when enrolling or regenerating; they are never stored in
// the page or fetched again.
async function load2FA() {
  var el = document.getElementById('sec-2fa');
  if (!el) return;
  var s;
  try { s = await api('auth/2fa/status'); } catch (e) { return; }
  var h = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Two-Factor Authentication (TOTP)</h3>';
  if (!s.configEnabled) {
    h += '<p style="font-size:12px;color:var(--dim);margin-bottom:10px">Enforcement is off on this server (<code>Q.panel.twofactor</code>). '
      + 'You can enroll a device now; sign-in will require it once an operator turns the flag on.</p>';
  }
  if (s.enabled) {
    h += '<p style="font-size:13px;margin-bottom:10px"><b class="cert-ok" style="color:var(--green)">Enabled</b>'
      + (s.configEnabled ? ' and enforced at sign-in.' : ' (enrolled; not yet enforced).')
      + ' Recovery codes left: ' + escH(String(s.recoveryRemaining)) + '.</p>'
      + '<div class="form-row"><label>Current code or password</label><input id="tfa-mgr-factor" placeholder="123456 or your password" type="password"></div>'
      + '<div class="btn-row">'
      + '<button class="btn btn-ghost" onclick="regen2FA()">New recovery codes</button>'
      + '<button class="btn btn-ghost" onclick="disable2FA()">Turn off</button>'
      + '</div>';
  } else {
    h += '<p style="font-size:12px;color:var(--dim);margin-bottom:10px">Add a time-based code from an authenticator app as a second factor.</p>'
      + '<button class="btn btn-primary" onclick="begin2FA()">Set up two-factor</button>';
  }
  h += '<div id="tfa-mgr-out" style="margin-top:12px"></div><div id="tfa-mgr-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div></div>';
  el.innerHTML = h;
}

function tfa2faErr(msg) {
  var e = document.getElementById('tfa-mgr-error');
  if (e) { e.textContent = msg; e.style.display = 'block'; }
}

function renderRecoveryCodes(codes) {
  return '<div class="card" style="background:var(--panel2,#0000000d);margin-top:10px">'
    + '<p style="font-size:12px;margin-bottom:8px"><b>Save these recovery codes now.</b> Each works once, and they are shown only this time.</p>'
    + '<pre style="font-size:13px;line-height:1.8;font-family:monospace;margin:0">' + codes.map(escH).join('\n') + '</pre></div>';
}

async function begin2FA() {
  var out = document.getElementById('tfa-mgr-out');
  var r;
  try { r = await api('auth/2fa/begin', {}); } catch (e) { return; }
  if (!r.ok) { tfa2faErr(r.error || 'Could not start enrollment.'); return; }
  out.innerHTML = '<div class="card" style="margin-top:10px">'
    + '<p style="font-size:12px;margin-bottom:8px">Add this to your authenticator app, then enter a code to confirm.</p>'
    + '<div style="font-size:12px;margin-bottom:6px"><b>Secret:</b> <code>' + escH(r.secret) + '</code></div>'
    + '<div style="font-size:11px;color:var(--dim);word-break:break-all;margin-bottom:10px"><b>otpauth:</b> ' + escH(r.otpauth) + '</div>'
    + '<div class="form-row"><label>Code from the app</label><input id="tfa-confirm-code" inputmode="numeric" placeholder="123456"></div>'
    + '<button class="btn btn-primary" onclick="confirm2FA()">Confirm &amp; enable</button>'
    + '</div>';
  var i = document.getElementById('tfa-confirm-code');
  if (i) i.focus();
}

async function confirm2FA() {
  var code = (document.getElementById('tfa-confirm-code') || {}).value || '';
  var r;
  try { r = await api('auth/2fa/confirm', {code: code.trim()}); } catch (e) { return; }
  if (!r.ok) { tfa2faErr(r.error || 'That code did not match.'); return; }
  document.getElementById('tfa-mgr-out').innerHTML = renderRecoveryCodes(r.recovery || []);
  // Refresh the card to the enabled state below the codes.
  var codes = document.getElementById('tfa-mgr-out').innerHTML;
  await load2FA();
  document.getElementById('tfa-mgr-out').innerHTML = codes;
}

async function disable2FA() {
  var factor = (document.getElementById('tfa-mgr-factor') || {}).value || '';
  if (!factor.trim()) { tfa2faErr('Enter a current code or your password.'); return; }
  var r;
  try { r = await api('auth/2fa/disable', factor2body(factor.trim())); } catch (e) { return; }
  if (!r.ok) { tfa2faErr(r.error || 'Could not turn two-factor off.'); return; }
  load2FA();
}

async function regen2FA() {
  var factor = (document.getElementById('tfa-mgr-factor') || {}).value || '';
  if (!factor.trim()) { tfa2faErr('Enter a current code or your password.'); return; }
  var r;
  try { r = await api('auth/2fa/recovery', factor2body(factor.trim())); } catch (e) { return; }
  if (!r.ok) { tfa2faErr(r.error || 'Could not make new codes.'); return; }
  document.getElementById('tfa-mgr-out').innerHTML = renderRecoveryCodes(r.recovery || []);
}

// A management factor is a 6-digit code, else treated as the password.
function factor2body(v) {
  return /^\d{6}$/.test(v) ? {code: v} : {password: v};
}

// ── Security & Attestation ──────────────────────────
async function loadSecurity() {
  load2FA();
  var el = document.getElementById('sec-attestation');
  try {
    var r = await api('attestation');
    var html = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Binary Attestation</h3>';
    html += '<div style="font-size:12px;margin-bottom:8px"><strong>Hash:</strong> <code style="font-size:11px">' + (r.binary_hash||'unknown') + '</code></div>';
    html += '<div style="font-size:12px;margin-bottom:8px"><strong>Size:</strong> ' + ((r.binary_size||0)/1024).toFixed(0) + ' KB</div>';
    if (r.verification) {
      var v = r.verification;
      var color = v.valid ? 'var(--grn)' : 'var(--red)';
      html += '<div style="font-size:12px;margin-bottom:8px"><strong>Status:</strong> <span style="color:'+color+'">' + v.label + ' — ' + (v.valid?'VALID':'FAILED') + '</span></div>';
      if (v.hash_matches === false) {
        html += '<div style="font-size:12px;color:var(--red)">⚠ Binary was modified since signing</div>';
      }
    }
    if (r.signatures && r.signatures.length) {
      html += '<h4 style="font-size:13px;margin:12px 0 6px">Signatures</h4>';
      r.signatures.forEach(function(s) {
        html += '<div style="font-size:12px;padding:4px 0;border-top:1px solid var(--border)">';
        html += '<strong>' + s.signer + '</strong> <span style="color:var(--dim)">(key:' + (s.key_id||'?').slice(0,8) + ')</span>';
        if (s.signed_at) html += ' <span style="color:var(--dim)">' + s.signed_at.slice(0,10) + '</span>';
        html += '</div>';
      });
    } else {
      html += '<div style="font-size:12px;color:var(--dim)">No signatures. Use the form below or the CLI to sign.</div>';
    }
    if (r.rekor && r.rekor.uuid) {
      html += '<div style="font-size:12px;margin-top:10px;padding-top:8px;border-top:1px solid var(--border)"><strong>Transparency log:</strong> <a href="' + (r.rekor.url||'#') + '" target="_blank" style="color:#4a9eff">' + r.rekor.uuid.slice(0,24) + '...</a> <span style="color:var(--grn)">✓ on Rekor</span></div>';
    } else if (r.signed) {
      html += '<div style="font-size:12px;margin-top:10px;padding-top:8px;border-top:1px solid var(--border)"><strong>Transparency log:</strong> <span style="color:var(--dim)">not published</span> <button class="btn btn-ghost" style="font-size:10px;padding:2px 8px;margin-left:6px" onclick="publishRekor()">Publish to Sigstore Rekor</button></div>';
    }
    html += '</div>';
    el.innerHTML = html;
  } catch(e) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">Attestation data unavailable.</p></div>';
  }
  // Trust status
  var trustEl = document.getElementById('sec-trust');
  try {
    var t = await api('trust');
    if (t.enabled) {
      var thtml = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Code Trust (File Manifests)</h3>';
      thtml += '<div style="font-size:12px">Trusted keys: ' + (t.keys||[]).length + '</div>';
      if (t.verified && t.verified.length) {
        thtml += '<div style="font-size:12px;margin-top:6px">';
        t.verified.forEach(function(v) {
          var icon = v.ok ? '<span style="color:var(--grn)">✓</span>' : '<span style="color:var(--red)">✗</span>';
          thtml += '<div>' + icon + ' ' + v.dir + (v.errors && v.errors.length ? ' (' + v.errors.length + ' errors)' : '') + '</div>';
        });
        thtml += '</div>';
      }
      thtml += '</div>';
      trustEl.innerHTML = thtml;
    } else {
      trustEl.innerHTML = '<div class="card"><p style="font-size:12px;color:var(--dim)">Code trust not enabled. Set <code>Q.trust.enabled: true</code> and add trusted keys.</p></div>';
    }
  } catch(e) {}
}
async function signBinary() {
  var key = document.getElementById('sec-key').value.trim();
  var signer = document.getElementById('sec-signer').value.trim() || 'panel-user';
  if (!key) return alert('Paste a PEM private key');
  var r = await api('attestation/sign', {key: key, signer: signer});
  if (r.error) { alert(r.error); return; }
  alert('Signed! ' + r.signers + ' total signature(s)');
  document.getElementById('sec-key').value = '';
  loadSecurity();
}
async function verifyBinary() {
  var m = parseInt(document.getElementById('sec-m').value) || 1;
  var r = await api('attestation/verify?m=' + m);
  var el = document.getElementById('sec-verify-result');
  var color = r.valid ? 'var(--grn)' : 'var(--red)';
  var html = '<div style="color:'+color+';font-weight:700">' + (r.valid ? '✓ VALID' : '✗ FAILED') + ' — ' + r.label + '</div>';
  if (r.details) {
    r.details.forEach(function(d) {
      var icon = d.status === 'valid' ? '✓' : '✗';
      html += '<div style="font-size:12px">' + icon + ' ' + d.signer + ' (' + d.status + ')</div>';
    });
  }
  el.innerHTML = html;
}
async function publishRekor() {
  if (!confirm('Publish this binary\'s attestation to the public Sigstore Rekor transparency log?\n\nThis is permanent and publicly visible.')) return;
  var r = await api('attestation/publish-rekor', {});
  if (r.published) {
    alert('Published to Rekor!\n\nUUID: ' + r.uuid + '\n\nVerify at: ' + r.url);
    loadSecurity();
  } else {
    alert(r.error || 'Failed to publish');
  }
}

// ── Autohost ────────────────────────────────────────
async function loadAutohost() {
  var r = await api('autohost');
  document.getElementById('ah-enabled').value = r.enabled ? '1' : '0';
  document.getElementById('ah-authorize').value = r.authorize || 'open';
  document.getElementById('ah-dns').value = r.dnsCheck !== false ? '1' : '0';
  document.getElementById('ah-allowlist-row').style.display = r.authorize === 'allowlist' ? '' : 'none';
  var el = document.getElementById('ah-status');
  var prov = r.provisioning || [];
  el.innerHTML = prov.length
    ? '<div class="card" style="margin-bottom:12px"><h3 style="font-size:14px;margin-bottom:8px">Currently Provisioning</h3>' + prov.map(function(h){return '<div>\u23f3 '+h+'</div>';}).join('') + '</div>'
    : '';
  var logEl = document.getElementById('ah-log');
  var lines = r.recentLog || [];
  if (lines.length) {
    logEl.innerHTML = '<div class="card"><h3 style="font-size:14px;margin-bottom:8px">Recent Activity</h3>'
      + '<pre style="font-size:11px;max-height:200px;overflow-y:auto;margin:0;white-space:pre-wrap">' + lines.join('\n') + '</pre></div>';
  } else {
    logEl.innerHTML = '<div class="card"><p style="color:var(--dim)">No autohost activity yet.</p></div>';
  }
  document.getElementById('ah-authorize').onchange = function() {
    document.getElementById('ah-allowlist-row').style.display = this.value === 'allowlist' ? '' : 'none';
  };
}
async function saveAutohost() {
  var data = {
    enabled: document.getElementById('ah-enabled').value === '1',
    authorize: document.getElementById('ah-authorize').value,
    dnsCheck: document.getElementById('ah-dns').value === '1',
    acmeEmail: document.getElementById('ah-email').value.trim()
  };
  if (data.authorize === 'allowlist') {
    data.allowlist = document.getElementById('ah-allowlist').value;
  }
  await api('autohost/toggle', data);
  loadAutohost();
}

// ── Workers ─────────────────────────────────────────
async function loadWorkers() {
  var r = await api('workers');
  var el = document.getElementById('workers-info');
  if (r.mode==='in-process') { el.innerHTML='<div class="card"><p>In-process mode (no pool).</p></div>'; return; }
  function s(l,v){return '<div><div style="font-size:18px;font-weight:700">'+v+'</div><div style="font-size:11px;color:var(--dim)">'+l+'</div></div>';}
  function fmt(b){return b>1048576?(b/1048576).toFixed(1)+' MB':(b/1024).toFixed(0)+' KB';}
  function fmtT(s){var h=Math.floor(s/3600),m=Math.floor((s%3600)/60);return h?h+'h '+m+'m':m+'m';}
  el.innerHTML='<div class="card"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px">'
    +s('Workers',r.workers)+s('Active',r.activeWorkers||0)+s('Requests',(r.totalRequests||0).toLocaleString())
    +s('Memory',fmt(r.memoryUsage||0))+s('Peak',fmt(r.memoryPeak||0))+s('Uptime',fmtT(r.uptime||0))
    +'</div></div>';
  document.getElementById('worker-count').value=r.workers;
  // Load worker detail
  var d = await api('workers/detail');
  var detailEl = document.getElementById('worker-detail');
  if (detailEl && d.workers) {
    var tbl = '<table style="width:100%;font-size:12px;border-collapse:collapse"><tr style="color:var(--dim)">'
      + '<th style="text-align:left;padding:4px">PID</th><th>Status</th><th>Requests</th><th></th></tr>';
    d.workers.forEach(function(w) {
      var status = w.busy ? '<span style="color:var(--yel)">\u25cf busy</span>'
        : w.recycleAfter ? '<span style="color:var(--red)">\u21bb recycling</span>'
        : '<span style="color:var(--grn)">\u25cf idle</span>';
      tbl += '<tr style="border-top:1px solid var(--border);padding:4px"><td style="padding:4px">' + w.pid + '</td><td style="text-align:center">' + status
        + '</td><td style="text-align:center">' + (w.requests||0)
        + '</td><td style="text-align:right"><button class="btn btn-ghost" style="font-size:10px;padding:2px 6px" onclick="recycleWorker(' + w.index + ')">\u21bb</button></td></tr>';
    });
    tbl += '</table>';
    detailEl.innerHTML = '<div class="card" style="margin-top:12px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
      + '<h3 style="font-size:14px;margin:0">Worker Detail</h3>'
      + '<button class="btn btn-primary" style="font-size:11px;padding:4px 10px" onclick="recycleAll()">Recycle All</button></div>'
      + '<p style="font-size:11px;color:var(--dim);margin:0 0 8px">Mode: ' + d.mode + ' \u00b7 Max requests: ' + d.maxRequests + ' \u00b7 Queue: ' + (d.pending||0) + '</p>'
      + tbl + '</div>';
  }
}
async function resizeWorkers() {
  var c=parseInt(document.getElementById('worker-count').value);
  if(!c||c<1)return alert('Enter a number');
  var r=await api('workers/resize',{workers:c});
  alert(r.error||'Resizing to '+c); loadWorkers();
}
async function recycleWorker(idx) {
  var r = await api('workers/recycle', {index: idx});
  loadWorkers();
}
async function recycleAll() {
  if (!confirm('Recycle all workers? Busy workers finish their current request first.')) return;
  var r = await api('workers/recycle', {});
  alert('Recycled: ' + (r.recycled ? r.recycled.immediate + ' immediate, ' + r.recycled.pending + ' pending' : 'done'));
  loadWorkers();
}

// ── Logs ────────────────────────────────────────────
var logTailInterval = null;
var logTailOn = false;
var logLastFile = null;
var logLastLines = [];

function getLogParams() {
  var p = getViewParams();
  var lines = parseInt(p.lines, 10);
  if (!lines || lines < 1) lines = 50;
  if (lines > 500) lines = 500;
  return {
    type: p.type === 'error' ? 'error' : 'access',
    lines: lines,
    filter: (p.filter || '').trim(),
    method: (p.method || '').trim().toUpperCase(),
    status: (p.status || '').trim(),
    host: (p.host || '').trim().toLowerCase()
  };
}

function setLogParams(changes) {
  var params = getViewParams();
  if (changes.type !== undefined) params.type = changes.type === 'error' ? 'error' : 'access';
  if (changes.lines !== undefined) params.lines = String(changes.lines);
  if (changes.filter !== undefined) { if (changes.filter) params.filter = changes.filter; else delete params.filter; }
  if (changes.method !== undefined) { if (changes.method) params.method = changes.method; else delete params.method; }
  if (changes.status !== undefined) { if (changes.status) params.status = changes.status; else delete params.status; }
  if (changes.host !== undefined) { if (changes.host) params.host = changes.host; else delete params.host; }
  history.replaceState({viewParams: params}, '', buildViewParamUrl(params));
  updateLogControls();
}

function updateLogControls() {
  var p = getLogParams();
  var t = document.getElementById('log-type');
  if (t) t.value = p.type;
  var l = document.getElementById('log-lines');
  if (l) l.value = String(p.lines);
  var f = document.getElementById('log-filter');
  if (f) f.value = p.filter;
  var m = document.getElementById('log-method');
  if (m) m.value = p.method;
  var s = document.getElementById('log-status');
  if (s) s.value = p.status;
  var hh = document.getElementById('log-host');
  if (hh) hh.value = p.host;
}

function logControlChanged() {
  setLogParams({
    type: document.getElementById('log-type').value,
    lines: document.getElementById('log-lines').value,
    filter: document.getElementById('log-filter').value,
    method: document.getElementById('log-method').value,
    status: document.getElementById('log-status').value,
    host: (document.getElementById('log-host') || {value: ''}).value.trim().toLowerCase()
  });
  loadLogs();
}

var logFilterTimer;
function logFilterChanged() {
  clearTimeout(logFilterTimer);
  logFilterTimer = setTimeout(logControlChanged, 250);
}

async function loadLogs() {
  var p = getLogParams();
  var url = 'logs?type=' + encodeURIComponent(p.type)
    + '&lines=' + encodeURIComponent(p.lines)
    + '&filter=' + encodeURIComponent(p.filter)
    + '&method=' + encodeURIComponent(p.method)
    + '&status=' + encodeURIComponent(p.status)
    + (p.host ? '&host=' + encodeURIComponent(p.host) : '');
  var el = document.getElementById('logs-output');
  var stats = document.getElementById('log-stats');
  if (el) el.innerHTML = '<p style="color:var(--dim)">Loading…</p>';
  try {
    var r = await api(url);
    if (r.error) {
      if (el) el.innerHTML = '<p style="color:var(--red)">' + escH(r.error) + '</p>';
      if (stats) stats.textContent = '';
      return;
    }
    if (!r.exists) {
      if (el) el.innerHTML = '<p style="color:var(--red)">Log file not found: ' + escH(r.file) + '</p>';
      if (stats) stats.textContent = '';
      logLastLines = [];
      return;
    }
    logLastFile = r.file;
    logLastLines = r.lines || [];
    renderLogs(logLastLines, p);
    if (stats) {
      var shown = r.lines ? r.lines.length : 0;
      var filtering = p.filter || p.method || p.status || p.host;
      var depth = r.complete ? 'the whole file' : 'the last ' + fmtBytesPlain(r.scanned || 0);
      stats.textContent = (filtering
        ? shown + ' of ' + (r.matched || 0) + ' matching lines in ' + depth
        : shown + ' lines') + ' · ' + r.file;
    }
    if (el && logTailOn) el.scrollTop = el.scrollHeight;
  } catch (e) {
    if (el) el.innerHTML = '<p style="color:var(--red)">Error: ' + escH(e.message) + '</p>';
  }
}

function renderLogs(lines, p) {
  var el = document.getElementById('logs-output');
  if (!el) return;
  if (!lines.length) { el.innerHTML = '<p style="color:var(--dim)">No matching lines.</p>'; return; }
  var html = '';
  var filter = p.filter.toLowerCase();
  lines.forEach(function(line) {
    var parsed = parseAccessLog(line);
    if (parsed && p.type === 'access') {
      html += renderAccessRow(parsed, filter);
    } else {
      html += renderLogLine(line, filter, p.type);
    }
  });
  el.innerHTML = html;
}

// Apache/NCSA combined + Qbix ms extension
// %h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" %{ms}T
function parseAccessLog(line) {
  var m = line.match(/^(\S+)\s+(\S+)\s+(\S+)\s+\[([^\]]+)\]\s+"([^"]+)"\s+(\d{3})\s+(\S+)\s+"([^"]*)"\s+"([^"]*)"\s*([\d.]+)?/);
  if (!m) return null;
  var req = m[5].split(' ');
  return {
    ip: m[1],
    ident: m[2],
    user: m[3],
    time: m[4],
    method: req[0] || '',
    path: req.slice(1, -1).join(' ') || '',
    protocol: req[req.length - 1] || '',
    status: m[6],
    size: m[7],
    referer: m[8],
    ua: m[9],
    ms: m[10] || ''
  };
}

function renderAccessRow(r, filter) {
  var statusClass = 'status-' + (r.status[0] || 'x') + 'xx';
  var text = r.ip + ' ' + r.time + ' ' + r.method + ' ' + r.path + ' ' + r.status + ' ' + r.size + ' ' + r.ms;
  var hl = filter ? highlightText(text, filter) : escH(text);
  return '<div class="log-row ' + statusClass + '" style="display:flex;gap:8px;padding:3px 0;border-bottom:1px solid var(--bdr)" title="' + escH((r.referer && r.referer !== '-' ? 'Referrer: ' + r.referer + '\n' : '') + r.ua) + '">'
    + '<span style="min-width:100px;color:var(--dim)">' + escH(r.ip) + '</span>'
    + '<span style="min-width:140px;color:var(--dim)">' + escH(r.time) + '</span>'
    + '<span style="min-width:45px;font-weight:600">' + escH(r.method) + '</span>'
    + '<span style="flex:1;min-width:120px;word-break:break-all">' + (filter ? highlightText(r.path, filter) : escH(r.path)) + '</span>'
    + '<span style="min-width:50px;text-align:right" class="log-status-' + escH(r.status[0]) + 'xx">' + escH(r.status) + '</span>'
    + '<span style="min-width:60px;text-align:right;color:var(--dim)">' + escH(r.size) + '</span>'
    + '<span style="min-width:70px;text-align:right;color:var(--dim)">' + (r.ms ? escH(r.ms) + 'ms' : '') + '</span>'
    + '</div>';
}

function renderLogLine(line, filter, type) {
  var highlighted = filter ? highlightText(line, filter) : escH(line);
  if (type === 'error') {
    var m = line.match(/^\[([^\]]+)\]\s*(.*)$/);
    if (m) {
      highlighted = '<span style="color:var(--dim)">[' + escH(m[1]) + ']</span> ' + (filter ? highlightText(m[2], filter) : escH(m[2]));
    }
  }
  return '<div class="log-row" style="padding:3px 0;border-bottom:1px solid var(--bdr)">' + highlighted + '</div>';
}

function highlightText(text, q) {
  if (!q) return escH(text);
  var parts = text.split(new RegExp('(' + escapeRegex(q) + ')', 'gi'));
  return parts.map(function(part) {
    return part.toLowerCase() === q.toLowerCase() ? '<mark style="background:rgba(255,215,0,.25);color:var(--txt);border-radius:2px">' + escH(part) + '</mark>' : escH(part);
  }).join('');
}

function escapeRegex(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function toggleLogTail() {
  logTailOn = !logTailOn;
  var btn = document.getElementById('log-tail');
  if (btn) btn.textContent = 'Tail: ' + (logTailOn ? 'on' : 'off');
  if (logTailOn) startLogTail(); else stopLogTail();
}

function startLogTail() {
  stopLogTail();
  if (!document.getElementById('tab-logs') || document.getElementById('tab-logs').classList.contains('hidden')) {
    logTailOn = false;
    var btn = document.getElementById('log-tail');
    if (btn) btn.textContent = 'Tail: off';
    return;
  }
  loadLogs();
  logTailInterval = setInterval(function() {
    if (!logTailOn) return;
    loadLogs();
  }, 2000);
}

function stopLogTail() {
  if (logTailInterval) { clearInterval(logTailInterval); logTailInterval = null; }
}

function copyLogLink() {
  var url = location.origin + buildViewParamUrl(getViewParams());
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(function() { alert('Link copied'); }, function() { prompt('Copy this link:', url); });
  } else {
    prompt('Copy this link:', url);
  }
}

function downloadLog() {
  if (!logLastLines.length) return alert('No log lines to download.');
  var p = getLogParams();
  var text = logLastLines.join('\n') + '\n';
  var blob = new Blob([text], {type: 'text/plain'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = (p.type === 'error' ? 'error' : 'access') + '.log';
  document.body.appendChild(a);
  a.click();
  setTimeout(function() { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 100);
}

// Stop tail when leaving the logs tab.
function onLeaveLogs() {
  stopLogTail();
  logTailOn = false;
  var btn = document.getElementById('log-tail');
  if (btn) btn.textContent = 'Tail: off';
}

// ── Cron ────────────────────────────────────────────
async function loadCron() {
  var r=await api('cron');
  var el=document.getElementById('cron-list');
  if(!r.tasks||!r.tasks.length){el.innerHTML='<div class="card"><p style="color:var(--dim)">No scheduled tasks configured.</p></div>';return;}
  el.innerHTML=r.tasks.map(function(t){
    var sched=t.every?'Every '+t.every+'s':t.times?t.times.join(', '):'manual';
    return '<div class="card" style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">'
      +'<div><strong>'+t.name+'</strong><div style="font-size:11px;color:var(--dim)">'+t.handler+' · '+sched+'</div></div>'
      +'<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="runCron(\''+t.name+'\')">Run Now</button></div>';
  }).join('');
}
async function runCron(n){var r=await api('cron/run',{task:n});alert(r.error||'Dispatched '+n);}


// ── Frameworks ──────────────────────────────────────
async function loadFrameworks() {
  var r = await api('frameworks');
  var el = document.getElementById('fw-list');
  if (!r.frameworks || !r.frameworks.length) {
    el.innerHTML = '<div class="card"><p style="color:var(--dim)">No known framework or CMS found in the document root, its parent, the apps directory or Q.panel.appRoots.</p><p style="font-size:12px;color:var(--dim);margin-top:8px">Recognised: Laravel, Symfony, WordPress, Drupal, Joomla, Magento, TYPO3, Craft CMS, Moodle, MediaWiki, Nextcloud, PrestaShop, Laminas, FuelPHP, Qbix, and whatever this distribution adds.</p></div>';
    return;
  }
  el.innerHTML = r.frameworks.map(function(d){ return installationCard(d, true); }).join('');
}

// Disruptive commands ask first; the server refuses them without confirm too.
async function runFwCmdBtn(btn) {
  var kind = btn.dataset.kind, cmd = btn.dataset.cmd, dir = btn.dataset.dir;
  if (btn.dataset.disruptive && !confirm(btn.dataset.name + '?\n\nThis changes the running installation.')) return;
  // The output box of this card: two installations of one kind each have their own.
  var card = btn.closest('.inst-card');
  var el = (card && card.querySelector('pre[id^="fw-output-"]')) || document.getElementById('fw-output-' + kind);
  el.style.display = 'block';
  el.textContent = 'Running ' + btn.dataset.name + '…';
  btn.disabled = true;
  try {
    var r = await api('frameworks/run', {framework: kind, cmd: cmd, dir: dir, confirm: !!btn.dataset.disruptive});
    el.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done') + (r.exit ? '\n[exit ' + r.exit + ']' : '');
  } catch(e) {
    el.textContent = 'Error: ' + e.message;
  }
  btn.disabled = false;
}

// Kept for older callers: runs a command by kind and id, asking first.
async function runFwCmd(framework, cmd, btn) {
  btn.dataset.kind = framework; btn.dataset.cmd = cmd; btn.dataset.name = btn.dataset.name || cmd;
  return runFwCmdBtn(btn);
}

async function loadFwPackages(framework) {
  var el = document.getElementById('fw-packages-' + framework);
  if (el.style.display !== 'none' && el.innerHTML && !el.dataset.reload) {
    el.style.display = 'none';
    return;
  }
  delete el.dataset.reload;
  el.style.display = 'block';
  el.innerHTML = '<p style="color:var(--dim);font-size:12px">Loading packages...</p>';
  
  var r = await api('frameworks/packages?framework=' + framework);
  if (r.error) { el.innerHTML = '<p style="color:var(--red);font-size:12px">' + r.error + '</p>'; return; }
  
  var isComposer = (framework === 'laravel' || framework === 'symfony');
  var isWP = (framework === 'wordpress');
  var isDrupal = (framework === 'drupal');
  
  var html = '<div style="font-size:11px;color:var(--dim);margin-bottom:6px">' + (r.packages||[]).length + ' packages (source: ' + (r.source||'?') + ')</div>';
  
  // Add new package form
  if (isComposer) {
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-add-pkg-' + framework + '" type="text" placeholder="vendor/package" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'require\',document.getElementById(\'fw-add-pkg-' + framework + '\').value)">composer require</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-dl-url-' + framework + '" type="text" placeholder="https://github.com/author/package" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="fwDownload(\'' + framework + '\')">Clone from URL</button>';
    html += '</div>';
  } else if (isWP) {
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-add-pkg-' + framework + '" type="text" placeholder="plugin-slug" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'install\',document.getElementById(\'fw-add-pkg-' + framework + '\').value)">Install Plugin</button>';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'install\',\'theme:\'+document.getElementById(\'fw-add-pkg-' + framework + '\').value)">Install Theme</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-dl-url-' + framework + '" type="text" placeholder="https://github.com/author/plugin-name" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="fwDownload(\'' + framework + '\')">Clone from URL</button>';
    html += '</div>';
  } else if (isDrupal) {
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-add-pkg-' + framework + '" type="text" placeholder="module_name" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-primary" style="font-size:11px;padding:5px 12px" onclick="pkgAction(\'' + framework + '\',\'install\',document.getElementById(\'fw-add-pkg-' + framework + '\').value)">Install Module</button>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px;margin-bottom:8px;align-items:center">';
    html += '<input id="fw-dl-url-' + framework + '" type="text" placeholder="https://github.com/author/module" style="flex:1;padding:5px 8px;font-size:12px;background:var(--card);border:1px solid var(--brd);color:var(--fg);border-radius:4px">';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:5px 12px" onclick="fwDownload(\'' + framework + '\')">Clone from URL</button>';
    html += '</div>';
  }
  
  if (!r.packages || !r.packages.length) {
    html += '<p style="color:var(--dim);font-size:12px">No packages found.</p>';
    el.innerHTML = html;
    return;
  }
  
  html += '<div style="max-height:400px;overflow:auto">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<tr style="background:rgba(255,255,255,.05)"><th style="text-align:left;padding:5px 8px">Name</th><th style="padding:5px 8px">Version</th>';
  if (isWP) html += '<th style="padding:5px 8px">Status</th>';
  html += '<th style="padding:5px 8px;text-align:right">Actions</th></tr>';
  
  r.packages.forEach(function(p) {
    var name = p.title || p.name;
    var pkgId = p.name;
    var rowStyle = 'border-bottom:1px solid rgba(255,255,255,.06)';
    html += '<tr style="' + rowStyle + '">';
    html += '<td style="padding:4px 8px">' + name;
    if (p.dev) html += ' <span style="color:var(--yel);font-size:10px">dev</span>';
    if (p.constraint) html += ' <span style="color:var(--dim);font-size:10px">' + p.constraint + '</span>';
    html += '</td>';
    html += '<td style="padding:4px 8px;text-align:center">' + (p.version||'-');
    if (p.update && p.update !== 'none') html += ' <span style="color:var(--yel)">→ ' + p.update + '</span>';
    html += '</td>';
    
    // Status column for WP
    if (isWP) {
      var sBadge = p.status === 'active' ? '<span style="color:var(--grn)">active</span>' : '<span style="color:var(--dim)">' + (p.status||'?') + '</span>';
      html += '<td style="padding:4px 8px;text-align:center">' + sBadge + '</td>';
    }
    
    // Action buttons
    html += '<td style="padding:3px 8px;text-align:right;white-space:nowrap">';
    var bs = 'font-size:10px;padding:2px 7px;margin-left:3px';
    
    if (isWP) {
      var wpPkg = (p.type === 'theme' ? 'theme:' : '') + pkgId;
      if (p.status === 'active') {
        html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'deactivate\',\'' + wpPkg + '\')">Deactivate</button>';
      } else if (p.status === 'inactive') {
        html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'activate\',\'' + wpPkg + '\')">Activate</button>';
      }
      if (p.update && p.update !== 'none') {
        html += '<button class="btn btn-primary" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'update\',\'' + wpPkg + '\')">Update</button>';
      }
      html += '<button class="btn btn-ghost" style="' + bs + ';color:var(--red)" onclick="if(confirm(\'Delete ' + pkgId + '?\'))pkgAction(\'' + framework + '\',\'delete\',\'' + wpPkg + '\')">Delete</button>';
    } else if (isComposer) {
      html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'update\',\'' + pkgId + '\')">Update</button>';
      if (pkgId.indexOf('/') !== -1) {
        html += '<button class="btn btn-ghost" style="' + bs + ';color:var(--red)" onclick="if(confirm(\'Remove ' + pkgId + '?\'))pkgAction(\'' + framework + '\',\'remove\',\'' + pkgId + '\')">Remove</button>';
      }
    } else if (isDrupal) {
      if (p.status === 'Enabled' || p.status === 'enabled') {
        html += '<button class="btn btn-ghost" style="' + bs + ';color:var(--red)" onclick="if(confirm(\'Uninstall ' + pkgId + '?\'))pkgAction(\'' + framework + '\',\'uninstall\',\'' + pkgId + '\')">Uninstall</button>';
      } else {
        html += '<button class="btn btn-ghost" style="' + bs + '" onclick="pkgAction(\'' + framework + '\',\'enable\',\'' + pkgId + '\')">Enable</button>';
      }
    }
    html += '</td></tr>';
  });
  html += '</table></div>';
  
  // Global actions
  html += '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">';
  if (isComposer) {
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="pkgAction(\'' + framework + '\',\'update\',\'--all\')">Update All</button>';
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="composerAction(\'dump-autoload\',\'\',\'' + framework + '\')">Dump Autoload</button>';
  }
  if (isWP) {
    html += '<button class="btn btn-ghost" style="font-size:11px;padding:4px 10px" onclick="runFwCmd(\'' + framework + '\',\'plugin update --all\',this)">Update All Plugins</button>';
  }
  html += '</div>';
  html += '<pre id="fw-pkg-output-' + framework + '" style="display:none;margin-top:8px;font-size:11px;max-height:200px;overflow:auto;white-space:pre-wrap"></pre>';
  
  el.innerHTML = html;
}

async function pkgAction(framework, action, pkg) {
  if (!pkg) { alert('Enter a package name'); return; }
  var isUpdateAll = (pkg === '--all');
  
  var output = document.getElementById('fw-pkg-output-' + framework);
  if (!output) output = document.getElementById('fw-output-' + framework);
  output.style.display = 'block';
  output.textContent = (isUpdateAll ? 'Updating all packages' : action + ' ' + pkg) + '...';
  
  var r;
  if (isUpdateAll) {
    r = await api('frameworks/composer', {action: 'update', package: ''});
  } else {
    r = await api('frameworks/pkg-action', {framework: framework, action: action, package: pkg});
  }
  output.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  
  // Reload package list
  var el = document.getElementById('fw-packages-' + framework);
  if (el) { el.dataset.reload = '1'; setTimeout(function(){ loadFwPackages(framework); }, 500); }
}

async function composerAction(action, pkg, framework) {
  var output = document.getElementById('fw-pkg-output-' + framework) || document.getElementById('fw-output-' + framework);
  output.style.display = 'block';
  output.textContent = 'Running composer ' + action + (pkg ? ' ' + pkg : '') + '...';
  var r = await api('frameworks/composer', {action: action, package: pkg || ''});
  output.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
}

async function fwDownload(framework) {
  var urlEl = document.getElementById('fw-dl-url-' + framework);
  if (!urlEl || !urlEl.value.trim()) { alert('Enter a URL'); return; }
  var out = document.getElementById('fw-pkg-output-' + framework) || document.getElementById('fw-output-' + framework);
  out.style.display = 'block';
  out.textContent = 'Cloning ' + urlEl.value.trim() + '...';
  var r = await api('frameworks/pkg-download', {framework: framework, source: urlEl.value.trim()});
  out.textContent = (r.cmd ? '$ ' + r.cmd + '\n\n' : '') + (r.output || r.error || 'Done');
  if (!r.error) {
    var el = document.getElementById('fw-packages-' + framework);
    if (el) { el.dataset.reload = '1'; setTimeout(function(){ loadFwPackages(framework); }, 500); }
  }
}

// Init
checkAuthAndInit();

// ── Changing the default password ────────────────────
// Signed in with the default key, nothing else works until it is changed.
// The checklist mirrors the server's rules so it can tick as you type; the
// server decides, and its list of failed rules is shown if it disagrees.

var PW_SEQUENCES = ['abcdefghijklmnopqrstuvwxyz', '0123456789', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890'];

function pwChecks(pw, rules) {
  rules = rules || {};
  var min = rules.minLength || 16, minDistinct = rules.minDistinct || 10, minBits = rules.minBits || 80;
  var chars = Array.from(pw), lower = pw.toLowerCase();
  var up = /\p{Lu}/u.test(pw), lo = /\p{Ll}/u.test(pw), dg = /\p{Nd}/u.test(pw);
  var sy = chars.some(function (c) { return !/[\p{Lu}\p{Ll}\p{Nd}]/u.test(c); });
  var other = chars.some(function (c) { return !/[\p{Lu}\p{Ll}\p{Nd}]/u.test(c) && c.charCodeAt(0) > 127; });
  var pool = (lo ? 26 : 0) + (up ? 26 : 0) + (dg ? 10 : 0) + (sy ? 33 : 0) + (other ? 64 : 0);
  var bits = pool > 1 ? Math.log2(pool) * chars.length : 0;
  var run = false;
  for (var i = 0; i + 4 <= lower.length && !run; i++) {
    var four = lower.substr(i, 4);
    run = PW_SEQUENCES.some(function (s) { return s.indexOf(four) !== -1 || s.split('').reverse().join('').indexOf(four) !== -1; });
  }
  var words = ['qbix', 'password', 'admin', 'panel'].filter(function (w) { return lower.indexOf(w) !== -1; });
  return [
    [min + ' or more characters', chars.length >= min],
    ['no more than ' + (rules.maxBytes || 72) + ' bytes', new TextEncoder().encode(pw).length <= (rules.maxBytes || 72)],
    ['an uppercase letter', up], ['a lowercase letter', lo], ['a digit', dg], ['a symbol', sy],
    [minDistinct + ' or more different characters', new Set(chars).size >= minDistinct],
    ['no letter three times in a row (aaa); digits, symbols and separators may repeat', !/(\p{L})\1\1/u.test(pw)],
    ['no run of four in order (abcd, 4321, qwer)', !run],
    ['none of: panel, qbix, password, admin, the server or host name', words.length === 0],
    ['at least ' + minBits + ' bits of estimated strength', bits >= minBits],
    ['not a common password (checked by the server)', null]
  ];
}

function showChangePassword(rules) {
  setAuthState('mustchange');
  var main = document.getElementById('main-content');
  var tabs = document.querySelector('.tabs');
  if (!main && tabs) {
    // Wrap everything after the tabs, as showAuthScreen() does, so it can be hidden.
    var els = [], sib = tabs.nextElementSibling;
    while (sib) { if (sib.id !== 'auth-screen') els.push(sib); sib = sib.nextElementSibling; }
    main = document.createElement('div');
    main.id = 'main-content';
    els.forEach(function (el) { main.appendChild(el); });
    tabs.parentNode.insertBefore(main, tabs.nextSibling);
  }
  if (main) main.style.display = 'none';
  if (tabs) tabs.style.display = 'none';
  var old = document.getElementById('auth-screen');
  if (old) old.remove();

  var screen = document.createElement('div');
  screen.id = 'auth-screen';
  screen.className = 'content';
  screen.style.maxWidth = '460px';
  screen.style.margin = '40px auto';
  screen.innerHTML = '<div class="card">'
    + '<h3 style="margin-bottom:12px">Change the default password</h3>'
    + '<p style="font-size:13px;color:var(--dim);margin-bottom:16px">You signed in with the default password. Choose your own before anything else: until you do, anyone who knows the default can sign in too.</p>'
    + '<div class="form-row"><label>New password</label><input type="password" id="cpw-1" autocomplete="new-password"></div>'
    + '<div class="form-row"><label>Again</label><input type="password" id="cpw-2" autocomplete="new-password"></div>'
    + '<ul id="cpw-rules" style="list-style:none;padding:0;margin:8px 0 12px;font-size:12px;line-height:1.7"></ul>'
    + '<button class="btn btn-primary" id="cpw-go" style="width:100%">Change password</button>'
    + '<div id="cpw-error" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>'
    + '</div>';
  document.body.insertBefore(screen, tabs ? tabs.nextSibling : null);

  var list = document.getElementById('cpw-rules');
  function render() {
    var pw = document.getElementById('cpw-1').value;
    list.innerHTML = '';
    pwChecks(pw, rules).forEach(function (r) {
      var li = document.createElement('li');
      li.textContent = (r[1] === null ? '• ' : r[1] ? '✓ ' : '✗ ') + r[0];
      li.style.color = r[1] === null ? 'var(--dim)' : r[1] ? 'var(--green)' : 'var(--red)';
      list.appendChild(li);
    });
  }
  document.getElementById('cpw-1').addEventListener('input', render);
  render();
  document.getElementById('cpw-1').focus();

  document.getElementById('cpw-go').onclick = async function () {
    var a = document.getElementById('cpw-1').value, b = document.getElementById('cpw-2').value;
    var err = document.getElementById('cpw-error');
    err.style.display = 'none';
    if (a !== b) { err.textContent = 'The two passwords differ.'; err.style.display = 'block'; return; }
    var r = await fetch(API + '/auth/password', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Panel-Token': getToken() || ''},
      body: JSON.stringify({password: a})
    });
    var data = await r.json();
    if (!data.ok) {
      err.textContent = (data.error || 'Not changed.') + (data.failed ? ' ' + data.failed.join(' ') : '');
      err.style.display = 'block';
      return;
    }
    setAuthState('panel');
    screen.remove();
    if (tabs) tabs.style.display = '';
    if (main) main.style.display = '';
    initPanel();
  };
}


function fmtBytesPlain(n) {
  if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
  if (n >= 1024) return Math.round(n / 1024) + ' KB';
  return n + ' B';
}
