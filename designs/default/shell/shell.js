/* The Q shell: a Quake-style drop-down console for the server's own pages.
 * ` (or ~) toggles it; Esc hides it. zsh-style line editing, tabs, split
 * panes, themes. Talks to /Q/ws/shell, or to /Q/api/shell/* when WebSockets
 * are blocked -- the same messages either way (see docs/shell.md). */
(function () {
  'use strict';
  if (window.QShell) return;
  var script = document.currentScript || document.querySelector('script[src*="/Q/shell/shell.js"]');
  var TOGGLE = (script && script.getAttribute('data-toggle-key')) || '`';
  var LS = {
    get: function (k, d) { try { var v = localStorage.getItem('qshell.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem('qshell.' + k, JSON.stringify(v)); } catch (e) {} }
  };
  // The session the server set at sign-in (the cookie, on every /Q/ view)
  // comes first; this tab's own storage only when there is no cookie. A token
  // left in one tab's storage used to win over the live cookie and be refused.
  function token() {
    var m = document.cookie.match(/(?:^|;\s*)Q_panel_token=([^;]+)/);
    if (m && m[1]) return decodeURIComponent(m[1]);
    try { return sessionStorage.getItem('Q_panel_token'); } catch (e) { return null; }
  }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function rid() { return Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4); }

  // ── ANSI (SGR) to spans, with links ─────────────────────────────────
  var URL_RE = /(https?:\/\/[^\s<>"')\]]+|\/Q\/[A-Za-z0-9._\/?=&%-]+)/g;
  function appendText(parent, text, cls) {
    var last = 0, m;
    URL_RE.lastIndex = 0;
    while ((m = URL_RE.exec(text))) {
      if (m.index > last) parent.appendChild(span(text.slice(last, m.index), cls));
      var a = el('a', cls, m[0]); a.href = m[0]; a.target = '_blank'; a.rel = 'noopener';
      parent.appendChild(a);
      last = m.index + m[0].length;
    }
    if (last < text.length) parent.appendChild(span(text.slice(last), cls));
  }
  function span(t, cls) { var s = el('span', cls || '', t); return s; }
  function Ansi() { this.fg = ''; this.bold = false; this.dim = false; this.ital = false; this.und = false; }
  Ansi.prototype.render = function (parent, text) {
    var re = /\x1b\[([0-9;]*)m|\x1b\[[0-9;]*[A-HJKSTf]|\x1b\][^\x07]*\x07/g, last = 0, m;
    while ((m = re.exec(text))) {
      if (m.index > last) appendText(parent, text.slice(last, m.index), this.cls());
      if (m[1] !== undefined && m[0].slice(-1) === 'm') this.apply(m[1]);
      last = re.lastIndex;
    }
    if (last < text.length) appendText(parent, text.slice(last), this.cls());
  };
  Ansi.prototype.apply = function (codes) {
    var list = codes === '' ? [0] : codes.split(';').map(Number);
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c === 0) { this.fg = ''; this.bold = this.dim = this.ital = this.und = false; }
      else if (c === 1) this.bold = true; else if (c === 2) this.dim = true; else if (c === 3) this.ital = true; else if (c === 4) this.und = true;
      else if (c === 22) { this.bold = this.dim = false; } else if (c === 23) this.ital = false; else if (c === 24) this.und = false;
      else if ((c >= 30 && c <= 37) || (c >= 90 && c <= 97)) this.fg = 'qs-c' + c;
      else if (c === 39) this.fg = '';
      else if (c === 38 || c === 48) { i += list[i + 1] === 5 ? 2 : 4; }
    }
  };
  Ansi.prototype.cls = function () {
    return [this.fg, this.bold ? 'qs-bold' : '', this.dim ? 'qs-dimmed' : '', this.ital ? 'qs-ital' : '', this.und ? 'qs-und' : ''].join(' ').trim();
  };

  // ── Transport: one socket for every pane, or HTTP polling ───────────
  var Link = {
    ws: null, mode: 'ws', panes: {}, byKey: {}, queue: [], polling: {}, ready: false, conn: 'connecting',
    // How the console is connected, shown as a dot in its title bar:
    // live (WebSocket), polling, reconnecting, or signed out.
    setConn: function (c) { if (this.conn === c) return; this.conn = c; if (typeof Shell !== 'undefined' && Shell.paintStatus) { Shell.paintStatus(); syncItems(); } },
    start: function () {
      var self = this;
      if (!('WebSocket' in window)) return this.fallback();
      var failed = false;
      try {
        this.ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/Q/ws/shell');
      } catch (e) { return this.fallback(); }
      var opened = false;
      this.ws.onopen = function () { opened = true; self.ready = true; self.setConn('live'); self.flush(); };
      this.ws.onmessage = function (e) { var m; try { m = JSON.parse(e.data); } catch (x) { return; } self.dispatch(m); };
      this.ws.onerror = function () { if (!opened && !failed) { failed = true; self.fallback(); } };
      this.ws.onclose = function () {
        // A refused handshake fires error and then close: by then fallback()
        // has switched to HTTP and is ready, and must stay so -- clearing
        // ready here queued every later command for good.
        if (!opened) { if (!failed) { failed = true; self.fallback(); } return; }
        self.ready = false;
        if (self.conn === 'signed-out') return;
        self.setConn('reconnecting');
        // Reconnect, and say hello again for every pane.
        setTimeout(function () { if (self.mode === 'ws') { self.start(); Object.keys(self.panes).forEach(function (id) { self.hello(self.panes[id]); }); } }, 1500);
      };
    },
    fallback: function () {
      this.mode = 'http';
      this.ready = true;
      if (this.conn !== 'signed-out') this.setConn('polling');
      var self = this;
      Object.keys(this.panes).forEach(function (id) { self.hello(self.panes[id]); });
      this.flush();
    },
    api: function (method, path, body) {
      var t = token();
      var opt = { method: method, headers: { 'X-Panel-Token': t || '' }, credentials: 'same-origin' };
      if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
      // Whatever comes back -- JSON, a plain-text error page, nothing at all --
      // becomes an object with _status, so no failure is ever swallowed.
      return fetch('/Q/api/' + path, opt).then(function (r) {
        return r.text().then(function (txt) {
          var j; try { j = txt ? JSON.parse(txt) : {}; } catch (e) { j = { error: (txt || '').slice(0, 300) || ('HTTP ' + r.status) }; }
          if (!j || typeof j !== 'object') j = { value: j };
          j._status = r.status; return j;
        });
      }, function (e) { return { _status: 0, error: 'The server did not answer (' + (e && e.message || 'network error') + ').' }; });
    },
    send: function (m) {
      if (!this.ready) { this.queue.push(m); return; }
      if (this.mode === 'ws') { if (this.ws && this.ws.readyState === 1) this.ws.send(JSON.stringify(m)); else this.queue.push(m); return; }
      this.sendHttp(m);
    },
    flush: function () { var q = this.queue; this.queue = []; for (var i = 0; i < q.length; i++) this.send(q[i]); },
    sendHttp: function (m) {
      var self = this, pane = this.panes[m.pane || ''] || null;
      switch (m.t) {
        case 'hello':
          this.api('GET', 'shell/session?session=' + encodeURIComponent(m.session)).then(function (j) {
            if (j._status !== 200) { if (j._status === 401) self.setConn('signed-out'); self.dispatch({ t: 'error', d: Link.explain(j), auth: j._status === 401, pane: m.pane }); return; }
            j.pane = m.pane; self.dispatch(j); self.poll(j.session);
          });
          break;
        case 'exec':
          this.api('POST', 'shell/exec', { command: m.line, session: m.session, interactive: true, force: !!m.force }).then(function (j) {
            if (j._status === 401) self.setConn('signed-out');
            if (j._status !== 202) self.dispatch({ t: 'error', d: Link.explain(j), auth: j._status === 401, pane: m.pane, session: pane && pane.key });
          });
          break;
        case 'stdin': this.api('POST', 'shell/input', { job: m.id, data: m.d }); break;
        case 'signal': this.api('POST', 'shell/signal', { job: m.id, sig: m.sig }); break;
        case 'complete':
          this.api('GET', 'shell/complete?session=' + encodeURIComponent(m.session) + '&line=' + encodeURIComponent(m.line) + '&pos=' + m.pos).then(function (j) {
            j.t = 'completion'; j.rid = m.rid; j.session = pane && pane.key; self.dispatch(j);
          });
          break;
        case 'history':
          this.api('GET', 'shell/history').then(function (j) { j.t = 'history'; j.rid = m.rid; j.session = pane && pane.key; self.dispatch(j); });
          break;
      }
    },
    poll: function (key) {
      var self = this;
      if (this.polling[key]) return;
      this.polling[key] = { since: 0 };
      (function loop() {
        if (!self.byKey[key]) { delete self.polling[key]; return; }
        var p = self.polling[key];
        self.api('GET', 'shell/poll?session=' + encodeURIComponent(self.byKey[key].clientId) + '&since=' + p.since).then(function (j) {
          if (j._status !== 200) {
            // Say so once per outage, then keep trying with a longer wait.
            if (!p.down) { p.down = true; self.dispatch({ t: 'error', d: Link.explain(j), auth: j._status === 401, session: key }); }
            self.setConn(j._status === 401 ? 'signed-out' : 'reconnecting');
            setTimeout(loop, j._status === 401 ? 5000 : 2000); return;
          }
          if (p.down) { p.down = false; self.dispatch({ t: 'notice', d: 'Connected again.', session: key }); }
          self.setConn('polling');
          (j.messages || []).forEach(function (m) { self.dispatch(m); });
          if (typeof j.next === 'number') p.since = j.next;
          setTimeout(loop, (j.messages && j.messages.length) ? 60 : 350);
        }).catch(function () { setTimeout(loop, 2000); });
      })();
    },
    // What a failed request means, and what to do next.
    explain: function (j) {
      var s = j._status, e = j.error || '';
      if (s === 401) return 'Your control panel session is not valid here: sign in at /Q/panel, then press ` again.';
      if (s === 403) return e || 'Not allowed at this tier. `help` lists what you can run.';
      if (s === 404) return e || 'The shell is not available on this server.';
      if (s === 429) return e || 'Too many jobs are running; wait for one to finish (`jobs`).';
      if (s === 0) return e + ' Check that the server is running.';
      if (s >= 500) return 'The server failed to run it (HTTP ' + s + '): ' + (e || 'no details') + '.';
      return e || ('Unexpected answer (HTTP ' + s + ').');
    },
    hello: function (pane) { this.send({ t: 'hello', session: pane.clientId, pane: pane.id }); },
    register: function (pane) { this.panes[pane.id] = pane; this.hello(pane); },
    unregister: function (pane) { delete this.panes[pane.id]; if (pane.key) delete this.byKey[pane.key]; },
    // The close control: end a session on the server (its jobs with it).
    closeSession: function (clientId) { return this.api('DELETE', 'shell/session?session=' + encodeURIComponent(clientId)); },
    // Forget every pane and connection, without reconnecting; the next
    // start() begins afresh.
    reset: function () {
      var ws = this.ws; this.ws = null;
      if (ws) { ws.onclose = null; ws.onerror = null; ws.onmessage = null; try { ws.close(); } catch (e) {} }
      this.panes = {}; this.byKey = {}; this.queue = []; this.polling = {};
      this.ready = false; this.mode = 'ws'; this.conn = 'connecting';
    },
    dispatch: function (m) {
      var pane = null;
      if (m.t === 'hello') {
        pane = this.panes[m.pane] || null;
        if (!pane) { for (var id in this.panes) if (this.panes[id].clientId && !this.panes[id].key) { pane = this.panes[id]; break; } }
        if (pane) { pane.key = m.session; this.byKey[m.session] = pane; }
      } else if (m.session) pane = this.byKey[m.session];
      else if (m.pane) pane = this.panes[m.pane];
      if (m.t === 'error' && m.auth === false) this.setConn('signed-out');
      // auth === false is the WebSocket's "this session has ended"; true is
      // an HTTP 401. Either way the visitor is signed out and must be told.
      if (!pane && m.t === 'error') { Shell.notice(m.d, m.auth === false || m.auth === true); return; }
      if (pane) pane.receive(m);
    }
  };

  // ── A pane: one session, one line editor ────────────────────────────
  var paneSeq = 0;
  function Pane(tab) {
    this.id = 'p' + (++paneSeq);
    this.tab = tab;
    this.clientId = LS.get('clientPrefix', null) || rid();
    LS.set('clientPrefix', this.clientId.slice(0, 8));
    this.clientId = this.clientId.slice(0, 8) + '-' + this.id + '-' + rid().slice(0, 4);
    this.key = null;
    this.status = 0;
    this.job = null;           // the foreground job id
    this.waiting = [];         // job ids "wait" is waiting for
    this.prompting = null;     // {id, secret}
    this.history = [];
    this.hpos = -1;
    this.draft = '';
    this.kill = [];
    this.search = null;        // {q, idx}
    this.menu = null;          // {items, sel, start}
    this.blocks = {};
    this.ansi = {};
    this.context = {};
    this.build();
    Link.register(this);
    Link.send({ t: 'history', rid: rid(), pane: this.id, session: this.clientId });
  }
  Pane.prototype.build = function () {
    var self = this;
    this.node = el('div', 'qs-pane');
    this.out = el('div', 'qs-out');
    this.out.setAttribute('role', 'log');
    this.line = el('div', 'qs-line');
    this.promptEl = el('span', 'qs-prompt');
    this.searchEl = el('span', 'qs-search');
    this.searchEl.hidden = true;
    this.input = el('input', 'qs-input');
    this.input.type = 'text';
    this.input.setAttribute('autocomplete', 'off'); this.input.setAttribute('autocapitalize', 'off');
    this.input.setAttribute('autocorrect', 'off'); this.input.setAttribute('spellcheck', 'false');
    this.input.setAttribute('aria-label', 'Shell command');
    this.line.appendChild(this.promptEl); this.line.appendChild(this.searchEl); this.line.appendChild(this.input);
    this.node.appendChild(this.out); this.node.appendChild(this.line);
    this.node.addEventListener('mousedown', function () { Shell.activate(self); });
    this.out.addEventListener('mouseup', function () { if (!String(window.getSelection())) self.input.focus(); });
    this.input.addEventListener('keydown', function (e) { self.key_(e); });
    this.input.addEventListener('focus', function () { Shell.activate(self, true); });
    this.renderPrompt();
    this.note('Q shell. Type help, man shell, or man keys. Tab completes; ` or Esc hides me.\n');
  };
  Pane.prototype.renderPrompt = function () {
    this.promptEl.textContent = '';
    if (this.prompting) { this.promptEl.appendChild(span(this.prompting.text)); return; }
    var host = location.hostname;
    var site = this.context && this.context.site ? ':' + this.context.site : '';
    this.promptEl.appendChild(span('panel@' + host + site + ' '));
    if (this.status) this.promptEl.appendChild(span('[' + this.status + '] ', 'qs-code'));
    this.promptEl.appendChild(span(this.job ? '… ' : '$ '));
  };
  Pane.prototype.scroll = function () { this.out.scrollTop = this.out.scrollHeight; };
  Pane.prototype.note = function (text, cls) { var b = el('div', 'qs-block ' + (cls || 'qs-note')); b.textContent = text; this.out.appendChild(b); this.scroll(); };
  Pane.prototype.block = function (id, line) {
    var self = this;
    var b = el('div', 'qs-block');
    var head = el('div', 'qs-cmd');
    var p = el('b'); p.textContent = this.promptEl.textContent; head.appendChild(p);
    head.appendChild(document.createTextNode(line));
    var body = el('div', 'qs-body');
    var copy = el('button', 'qs-btn qs-copy', 'copy');
    copy.type = 'button';
    copy.addEventListener('click', function (e) {
      e.stopPropagation();
      var txt = body.innerText;
      if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function () { copy.textContent = 'copied'; setTimeout(function () { copy.textContent = 'copy'; }, 1200); });
    });
    b.appendChild(head); b.appendChild(body); b.appendChild(copy);
    this.out.appendChild(b);
    var rec = { node: b, body: body, ansi: new Ansi() };
    if (id) this.blocks[id] = rec;
    this.lastBlock = rec;
    this.scroll();
    return rec;
  };
  Pane.prototype.write = function (id, text, isErr) {
    var rec = (id && this.blocks[id]) || this.lastBlock || this.block(null, '');
    if (isErr) { var s = el('span', 'qs-err'); rec.ansi.render(s, text); rec.body.appendChild(s); }
    else rec.ansi.render(rec.body, text);
    var body = rec.body;
    while (body.childNodes.length > 5000) body.removeChild(body.firstChild);
    var blocks = this.out.children;
    while (blocks.length > 400) this.out.removeChild(blocks[0]);
    this.scroll();
  };
  Pane.prototype.receive = function (m) {
    switch (m.t) {
      case 'hello':
        this.context = m.context || {};
        Shell.onHello(m, this);
        this.renderPrompt();
        break;
      case 'history':
        if (m.lines) this.history = m.lines.slice();
        break;
      case 'start':
        if (m.quiet) break;
        if (!this.blocks[m.id]) { var rec = this.pendingBlock || this.block(m.id, m.line); this.pendingBlock = null; this.blocks[m.id] = rec; }
        if (!m.bg) { this.job = m.id; this.tab.busy(true); }
        this.renderPrompt();
        break;
      case 'out': this.write(m.id, m.d, false); break;
      case 'err': this.write(m.id, m.d, true); break;
      case 'prompt':
        this.prompting = { id: m.id, secret: !!m.secret, text: m.d };
        this.input.type = m.secret ? 'password' : 'text';
        this.input.value = '';
        this.renderPrompt();
        if (Shell.isOpen()) this.input.focus();
        break;
      case 'ctl': this.ctl(m); break;
      case 'exit':
        if (m.id === null || m.id === this.job || (!this.job && m.id)) {
          if (!m.bg) { this.status = m.code || 0; this.job = null; this.tab.busy(false); }
        }
        if (this.prompting && this.prompting.id === m.id) this.endPrompt();
        this.waiting = this.waiting.filter(function (x) { return x !== m.id; });
        this.renderPrompt();
        break;
      case 'fg':
        if (m.state === 'running') { this.job = m.id; this.tab.busy(true); } else { this.note('[' + m.n + '] done (' + m.code + ') ' + m.line + '\n'); }
        this.renderPrompt();
        break;
      case 'wait':
        this.waiting = (m.ids || []).slice();
        if (this.waiting.length) { this.job = this.waiting[0]; this.tab.busy(true); }
        this.renderPrompt();
        break;
      case 'completion': this.completed(m); break;
      case 'notice': this.note(m.d + '\n'); break;
      case 'error':
        this.note(m.d + '\n', 'qs-err');
        if (m.auth === false || m.auth === true) Shell.notice(m.d, true);
        this.job = null; this.tab.busy(false); this.renderPrompt();
        break;
    }
  };
  Pane.prototype.endPrompt = function () {
    this.prompting = null;
    this.input.type = 'text';
    this.input.value = '';
    this.renderPrompt();
  };
  Pane.prototype.ctl = function (m) {
    switch (m.op) {
      case 'clear': this.out.textContent = ''; this.blocks = {}; this.lastBlock = null; break;
      case 'hide': Shell.hide(); break;
      case 'theme': Shell.theme(m.name, true); break;
      case 'layout': Shell.layout(m.value, true); break;
      case 'pager': var rec = this.blocks[m.id]; if (rec) rec.node.classList.add('qs-pager'); break;
      case 'context': this.context = m.site ? { site: m.site } : {}; this.renderPrompt(); break;
      case 'bind': Shell.bind(m.key, m.command, this); break;
      case 'binds': this.write(m.id, Shell.listBinds()); break;
      case 'elevated': Shell.elevated(m.until); break;
      case 'unelevate': Shell.elevated(0); break;
    }
  };
  Pane.prototype.run = function (line) {
    var self = this;
    if (this.prompting) {
      var p = this.prompting;
      var shown = p.secret ? '' : line;
      this.write(p.id, p.text + shown + '\n');
      Link.send({ t: 'stdin', id: p.id, d: line, pane: this.id });
      this.endPrompt();
      return;
    }
    if (line.trim() !== '' && line[0] !== ' ' && this.history[this.history.length - 1] !== line) this.history.push(line);
    this.hpos = -1; this.draft = '';
    if (line.trim() === '') { this.note(this.promptEl.textContent + '\n', 'qs-cmd'); return; }
    this.pendingBlock = this.block(null, line);
    Link.send({ t: 'exec', session: this.clientId, line: line, pane: this.id });
  };
  Pane.prototype.focus = function () { this.input.focus(); };

  // The line editor: zsh/Emacs keys on a real <input>, so phones and IMEs work.
  Pane.prototype.key_ = function (e) {
    var inp = this.input, v = inp.value, s = inp.selectionStart, end = inp.selectionEnd;
    var ctrl = e.ctrlKey || Shell.sticky.ctrl, alt = e.altKey, k = e.key;
    if (Shell.handleBind(e, this)) { e.preventDefault(); return; }
    if (this.menu && this.menuKey(e)) return;
    if (this.search && this.searchKey(e)) return;
    if (k === TOGGLE || (TOGGLE === '`' && k === '~')) {
      if (v === '' && !this.prompting) { e.preventDefault(); Shell.hide(); return; }
    }
    var set = function (val, pos) { inp.value = val; inp.setSelectionRange(pos, pos); };
    var wordLeft = function (i) { while (i > 0 && /\s/.test(v[i - 1])) i--; while (i > 0 && !/\s/.test(v[i - 1])) i--; return i; };
    var wordRight = function (i) { while (i < v.length && /\s/.test(v[i])) i++; while (i < v.length && !/\s/.test(v[i])) i++; return i; };
    var done = true;
    if (k === 'Enter') { var line = v; inp.value = ''; this.run(line); }
    else if (k === 'Escape') { if (this.prompting) { this.endPrompt(); } else Shell.hide(); }
    else if (k === 'Tab') { if (!this.prompting) this.complete(); }
    else if (ctrl && e.shiftKey && (k === 'T' || k === 't')) Shell.newTab();
    else if (ctrl && e.shiftKey && (k === 'D' || k === 'd')) Shell.split('row');
    else if (ctrl && e.shiftKey && (k === 'E' || k === 'e')) Shell.split('col');
    else if (ctrl && e.shiftKey && (k === 'W' || k === 'w')) Shell.closePane(this);
    else if (ctrl && (k === 'c' || k === 'C') && !(s !== end)) {
      if (this.job) Link.send({ t: 'signal', id: this.job, sig: 'INT', pane: this.id });
      else { this.note(this.promptEl.textContent + v + '^C\n', 'qs-cmd'); inp.value = ''; }
      if (this.prompting) this.endPrompt();
    }
    else if (ctrl && k === 'd') { if (v === '') Shell.hide(); else set(v.slice(0, s) + v.slice(s + 1), s); }
    else if (ctrl && k === 'l') { this.out.textContent = ''; this.blocks = {}; this.lastBlock = null; }
    else if (ctrl && k === 'a') set(v, 0);
    else if (ctrl && k === 'e') set(v, v.length);
    else if (ctrl && k === 'b') set(v, Math.max(0, s - 1));
    else if (ctrl && k === 'f') set(v, Math.min(v.length, s + 1));
    else if (ctrl && k === 'k') { this.kill.push(v.slice(s)); set(v.slice(0, s), s); }
    else if (ctrl && k === 'u') { this.kill.push(v.slice(0, s)); set(v.slice(s), 0); }
    else if (ctrl && k === 'w') { var w = wordLeft(s); this.kill.push(v.slice(w, s)); set(v.slice(0, w) + v.slice(s), w); }
    else if (ctrl && k === 'y') { var y = this.kill[this.kill.length - 1] || ''; set(v.slice(0, s) + y + v.slice(end), s + y.length); }
    else if (ctrl && k === 'r') { if (!this.prompting) this.startSearch(); }
    else if (ctrl && k === 'p') this.hist(-1);
    else if (ctrl && k === 'n') this.hist(1);
    else if (alt && (k === 'b' || k === 'B')) set(v, wordLeft(s));
    else if (alt && (k === 'f' || k === 'F')) set(v, wordRight(s));
    else if (alt && (k === 'd' || k === 'D')) { var r = wordRight(s); this.kill.push(v.slice(s, r)); set(v.slice(0, s) + v.slice(r), s); }
    else if (k === 'ArrowUp' && !this.prompting) this.hist(-1);
    else if (k === 'ArrowDown' && !this.prompting) this.hist(1);
    else if (k === 'PageUp') this.out.scrollTop -= this.out.clientHeight * 0.8;
    else if (k === 'PageDown') this.out.scrollTop += this.out.clientHeight * 0.8;
    else done = false;
    if (done) e.preventDefault();
    if (Shell.sticky.ctrl && (done || k.length === 1)) Shell.setSticky(false);
  };
  Pane.prototype.hist = function (dir) {
    if (!this.history.length) return;
    if (this.hpos === -1) { if (dir > 0) return; this.draft = this.input.value; this.hpos = this.history.length; }
    this.hpos = Math.max(0, Math.min(this.history.length, this.hpos + dir));
    var v = this.hpos === this.history.length ? this.draft : this.history[this.hpos];
    if (this.hpos === this.history.length) this.hpos = -1;
    this.input.value = v; this.input.setSelectionRange(v.length, v.length);
  };
  // Ctrl-R: incremental reverse search through the history.
  Pane.prototype.startSearch = function () { this.search = { q: '', idx: this.history.length }; this.showSearch(); };
  Pane.prototype.showSearch = function () {
    this.searchEl.hidden = !this.search;
    if (this.search) this.searchEl.textContent = "(reverse-i-search)'" + this.search.q + "': ";
  };
  Pane.prototype.searchKey = function (e) {
    var sr = this.search, k = e.key;
    if (k === 'Escape' || (e.ctrlKey && k === 'g')) { this.search = null; this.showSearch(); e.preventDefault(); return true; }
    if (k === 'Enter') { this.search = null; this.showSearch(); return false; }
    if (e.ctrlKey && k === 'r') { this.findBack(sr.idx - 1); e.preventDefault(); return true; }
    if (k === 'Backspace') { sr.q = sr.q.slice(0, -1); this.findBack(this.history.length - 1); e.preventDefault(); return true; }
    if (k.length === 1 && !e.ctrlKey && !e.metaKey) { sr.q += k; this.findBack(Math.min(sr.idx, this.history.length - 1)); e.preventDefault(); return true; }
    if (k === 'ArrowLeft' || k === 'ArrowRight' || k === 'Tab') { this.search = null; this.showSearch(); return false; }
    return false;
  };
  Pane.prototype.findBack = function (from) {
    var sr = this.search;
    for (var i = from; i >= 0; i--) {
      if (this.history[i] && this.history[i].indexOf(sr.q) !== -1) { sr.idx = i; this.input.value = this.history[i]; this.showSearch(); return; }
    }
    this.showSearch();
  };
  // Tab completion, from the server.
  Pane.prototype.complete = function () {
    this.completeRid = rid();
    Link.send({ t: 'complete', session: this.clientId, line: this.input.value, pos: this.input.selectionStart, rid: this.completeRid, pane: this.id });
  };
  Pane.prototype.completed = function (m) {
    if (m.rid && m.rid !== this.completeRid) return;
    var items = m.items || [];
    if (!items.length) return;
    var v = this.input.value, pos = this.input.selectionStart, start = typeof m.start === 'number' ? m.start : pos;
    if (items.length === 1) { this.insert(start, pos, items[0].v, /=$/.test(items[0].v) ? '' : ' '); return; }
    var common = items[0].v;
    items.forEach(function (it) { while (it.v.indexOf(common) !== 0) common = common.slice(0, -1); });
    if (common.length > pos - start) { this.insert(start, pos, common, ''); return; }
    this.openMenu(items, start);
  };
  Pane.prototype.insert = function (start, pos, text, after) {
    var v = this.input.value;
    var nv = v.slice(0, start) + text + after + v.slice(pos);
    this.input.value = nv;
    var c = start + text.length + after.length;
    this.input.setSelectionRange(c, c);
  };
  Pane.prototype.openMenu = function (items, start) {
    this.closeMenu();
    var self = this;
    var menu = el('div', 'qs-menu');
    menu.setAttribute('role', 'listbox');
    items.forEach(function (it, i) {
      var row = el('div'); row.setAttribute('role', 'option');
      row.appendChild(span(it.v)); if (it.d) row.appendChild(el('small', '', it.d));
      row.addEventListener('mousedown', function (e) { e.preventDefault(); self.menu.sel = i; self.pickMenu(); });
      menu.appendChild(row);
    });
    this.node.appendChild(menu);
    this.menu = { items: items, sel: 0, start: start, node: menu };
    this.paintMenu();
  };
  Pane.prototype.paintMenu = function () {
    var rows = this.menu.node.children;
    for (var i = 0; i < rows.length; i++) rows[i].className = i === this.menu.sel ? 'sel' : '';
    if (rows[this.menu.sel]) rows[this.menu.sel].scrollIntoView({ block: 'nearest' });
  };
  Pane.prototype.menuKey = function (e) {
    var k = e.key, n = this.menu.items.length;
    if (k === 'Tab' || k === 'ArrowDown') { this.menu.sel = (this.menu.sel + (e.shiftKey && k === 'Tab' ? n - 1 : 1)) % n; this.paintMenu(); e.preventDefault(); return true; }
    if (k === 'ArrowUp') { this.menu.sel = (this.menu.sel + n - 1) % n; this.paintMenu(); e.preventDefault(); return true; }
    if (k === 'Enter') { this.pickMenu(); e.preventDefault(); return true; }
    if (k === 'Escape') { this.closeMenu(); e.preventDefault(); return true; }
    this.closeMenu();
    return false;
  };
  Pane.prototype.pickMenu = function () {
    var it = this.menu.items[this.menu.sel];
    this.insert(this.menu.start, this.input.selectionStart, it.v, /=$/.test(it.v) ? '' : ' ');
    this.closeMenu();
    this.input.focus();
  };
  Pane.prototype.closeMenu = function () { if (this.menu) { this.menu.node.remove(); this.menu = null; } };
  Pane.prototype.destroy = function () { Link.unregister(this); this.node.remove(); };

  // ── Tabs, splits and the console itself ─────────────────────────────
  var tabSeq = 0;
  function Tab() {
    var self = this;
    this.id = ++tabSeq;
    this.name = 'shell ' + this.id;
    this.root = null;   // a Pane, or {dir, a, b, node}
    this.node = el('div', 'qs-split qs-row');
    this.btn = el('div', 'qs-tab');
    this.btn.setAttribute('role', 'tab');
    this.btn.appendChild(span(this.name));
    var x = el('button', 'qs-x', '×'); x.type = 'button'; x.title = 'Close tab (Ctrl-Shift-W)';
    x.addEventListener('click', function (e) { e.stopPropagation(); Shell.closeTab(self); });
    this.btn.appendChild(x);
    this.btn.addEventListener('click', function () { Shell.showTab(self); });
    var p = new Pane(this);
    this.root = p;
    this.node.appendChild(p.node);
    this.active = p;
  }
  Tab.prototype.busy = function (on) { this.btn.classList.toggle('busy', !!on); };
  Tab.prototype.panes = function () {
    var out = [];
    (function walk(n) { if (!n) return; if (n instanceof Pane) out.push(n); else { walk(n.a); walk(n.b); } })(this.root);
    return out;
  };

  var Shell = {
    node: null, tabs: [], current: null, binds: LS.get('binds', {}), sticky: { ctrl: false }, info: null, sudoUntil: 0,
    build: function () {
      var self = this;
      var n = el('div', 'qshell');
      n.setAttribute('role', 'dialog'); n.setAttribute('aria-label', 'Shell'); n.setAttribute('aria-hidden', 'true');
      var bar = el('div', 'qs-bar');
      this.titleEl = el('span', 'qs-title', 'Q shell');
      this.statusEl = el('span', 'qs-status', 'connecting…');
      bar.appendChild(this.titleEl); bar.appendChild(this.statusEl); bar.appendChild(el('span', 'qs-spacer'));
      [['＋ tab', function () { self.newTab(); }, 'New tab (Ctrl-Shift-T)'],
       ['split ┃', function () { self.split('row'); }, 'Split side by side (Ctrl-Shift-D)'],
       ['split ━', function () { self.split('col'); }, 'Split top and bottom (Ctrl-Shift-E)'],
       ['tabs ⇄', function () { self.layout(self.node.classList.contains('qs-horizontal') ? 'vertical' : 'horizontal', true); }, 'Tabs down the side or along the top'],
      ].forEach(function (b) {
        var btn = el('button', 'qs-btn', b[0]); btn.type = 'button'; btn.title = b[2];
        btn.addEventListener('click', b[1]); bar.appendChild(btn);
      });
      // Window controls: start or show, hide (the session keeps running),
      // maximise, and close (ends the session and its jobs).
      var win = el('span', 'qs-win');
      [['+', 'qs-show', 'Start or show the shell', function () { self.show(); }],
       ['−', 'qs-hide', 'Hide the shell; its session keeps running (` or Esc)', function () { self.hide(); }],
       ['m', 'qs-maxbtn', 'Maximise to the full height, and back', function () { self.maximise(); }],
       ['×', 'qs-close', 'Close the shell: ends its session and jobs', function () { self.close(false); }]].forEach(function (b) {
        var btn = el('button', b[1], b[0]); btn.type = 'button'; btn.title = b[2]; btn.setAttribute('aria-label', b[2]);
        btn.addEventListener('click', b[3]); win.appendChild(btn);
        if (b[1] === 'qs-maxbtn') self.maxBtn = btn;
      });
      bar.appendChild(win);
      var main = el('div', 'qs-main');
      this.tabsEl = el('div', 'qs-tabs'); this.tabsEl.setAttribute('role', 'tablist');
      var nt = el('div', 'qs-tab qs-newtab', '＋'); nt.title = 'New tab'; nt.addEventListener('click', function () { self.newTab(); });
      this.newTabBtn = nt;
      this.tabsEl.appendChild(nt);
      this.panesEl = el('div', 'qs-panes');
      main.appendChild(this.tabsEl); main.appendChild(this.panesEl);
      var keys = el('div', 'qs-keys');
      [['Tab', 'Tab'], ['Ctrl', 'Ctrl'], ['Esc', 'Escape'], ['←', 'ArrowLeft'], ['↑', 'ArrowUp'], ['↓', 'ArrowDown'], ['→', 'ArrowRight'], ['`', '`'], ['|', '|'], ['-', '-'], ['/', '/']].forEach(function (k) {
        var b = el('button', '', k[0]); b.type = 'button';
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { self.softKey(k[1], b); });
        keys.appendChild(b);
        if (k[1] === 'Ctrl') self.ctrlBtn = b;
      });
      var grip = el('div', 'qs-resize'); grip.title = 'Drag to resize';
      n.appendChild(bar); n.appendChild(main); n.appendChild(keys); n.appendChild(grip);
      document.body.appendChild(n);
      this.node = n;
      var h = LS.get('height', null); if (h) n.style.height = h;
      if (LS.get('max', false)) n.classList.add('qs-max');
      if (window.matchMedia && matchMedia('(pointer: coarse)').matches) n.classList.add('qs-touch');
      this.layout(LS.get('layout', 'vertical'), false);
      this.resizer(grip);
      this.newTab();
      this.theme(LS.get('theme', 'dark'), false);
      Link.start();
    },
    resizer: function (grip) {
      var self = this;
      grip.addEventListener('pointerdown', function (e) {
        e.preventDefault(); grip.setPointerCapture(e.pointerId);
        var move = function (ev) { var hgt = Math.max(140, Math.min(window.innerHeight * 0.96, ev.clientY)); self.node.style.height = hgt + 'px'; };
        var up = function () { grip.removeEventListener('pointermove', move); grip.removeEventListener('pointerup', up); LS.set('height', self.node.style.height); };
        grip.addEventListener('pointermove', move); grip.addEventListener('pointerup', up);
      });
    },
    ensure: function () { if (!this.node) this.build(); },
    isOpen: function () { return !!(this.node && this.node.classList.contains('open')); },
    show: function () {
      if (!this.allowed) { this.notice(this.why || 'Sign in to the Control Panel to use the shell.', true); return; }
      this.ensure();
      this.node.classList.add('open'); this.node.setAttribute('aria-hidden', 'false');
      var p = this.current && this.current.active; if (p) setTimeout(function () { p.focus(); }, 30);
      syncItems();
    },
    hide: function () {
      if (!this.node) return;
      var had = this.node.contains(document.activeElement);
      this.node.classList.remove('open'); this.node.setAttribute('aria-hidden', 'true');
      if (had) document.activeElement.blur();
      syncItems(); focusItem();
    },
    // m: the full height and back, remembered in this browser.
    maximise: function () {
      if (!this.node) return;
      var on = !this.node.classList.contains('qs-max');
      this.node.classList.toggle('qs-max', on);
      LS.set('max', on);
      if (this.maxBtn) this.maxBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    },
    // ×: end the session and its jobs. Running jobs are named first, and
    // nothing is ended unless that is confirmed.
    close: function (force) {
      var self = this;
      if (!this.node) return;
      if (force) return this.destroy();
      var keys = Object.keys(Link.panes).map(function (id) { return Link.panes[id].key; }).filter(Boolean);
      Link.api('GET', 'shell/jobs').then(function (j) {
        var running = (j.jobs || []).filter(function (x) { return x.state === 'running' && keys.indexOf(x.session) !== -1; });
        if (!running.length) return self.destroy();
        self.confirm((running.length === 1 ? 'One job is' : running.length + ' jobs are') + ' still running: '
          + running.map(function (x) { return '[' + x.n + '] ' + x.line; }).join(', ') + '. Close the shell and end them?',
          function () { self.destroy(); });
      });
    },
    confirm: function (text, yes) {
      var self = this;
      if (this.confirmEl) this.confirmEl.remove();
      var box = el('div', 'qs-confirm'); box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-label', 'Close the shell?');
      box.appendChild(el('p', '', text));
      var ok = el('button', 'qs-btn qs-confirm-yes', 'End them and close'); ok.type = 'button';
      var no = el('button', 'qs-btn qs-confirm-no', 'Keep running'); no.type = 'button';
      ok.addEventListener('click', function () { box.remove(); self.confirmEl = null; yes(); });
      no.addEventListener('click', function () { box.remove(); self.confirmEl = null; var p = self.current && self.current.active; if (p) p.focus(); });
      box.appendChild(ok); box.appendChild(no);
      this.node.appendChild(box); this.confirmEl = box;
      no.focus();
    },
    destroy: function () {
      var ids = Object.keys(Link.panes).map(function (id) { return Link.panes[id].clientId; });
      ids.forEach(function (c) { Link.closeSession(c); });
      Link.reset();
      if (this.node) this.node.remove();
      this.node = null; this.tabs = []; this.current = null; this.info = null; this.confirmEl = null;
      syncItems(); focusItem();
    },
    toggle: function () { this.isOpen() ? this.hide() : this.show(); },
    newTab: function () {
      var t = new Tab();
      this.tabs.push(t);
      this.tabsEl.insertBefore(t.btn, this.newTabBtn);
      this.showTab(t);
      return t;
    },
    showTab: function (t) {
      this.current = t;
      this.tabs.forEach(function (x) { x.btn.classList.toggle('active', x === t); x.btn.setAttribute('aria-selected', x === t ? 'true' : 'false'); });
      this.panesEl.textContent = '';
      this.panesEl.appendChild(t.node);
      this.activate(t.active);
    },
    closeTab: function (t) {
      t.panes().forEach(function (p) { p.destroy(); });
      t.btn.remove();
      this.tabs = this.tabs.filter(function (x) { return x !== t; });
      if (!this.tabs.length) { this.newTab(); this.hide(); return; }
      if (this.current === t) this.showTab(this.tabs[this.tabs.length - 1]);
    },
    activate: function (p, noFocus) {
      if (!p) return;
      var t = p.tab;
      t.active = p;
      t.panes().forEach(function (x) { x.node.classList.toggle('active', x === p); });
      if (!noFocus && this.isOpen()) p.focus();
    },
    // tmux-style splits: the active pane shares its place with a new one.
    split: function (dir) {
      var t = this.current, p = t.active;
      var np = new Pane(t);
      var box = { dir: dir, a: p, b: np, node: el('div', 'qs-split ' + (dir === 'row' ? 'qs-row' : 'qs-col')) };
      var parent = p.node.parentNode;
      parent.replaceChild(box.node, p.node);
      box.node.appendChild(p.node); box.node.appendChild(np.node);
      this.replaceIn(t, p, box);
      p.parentBox = box; np.parentBox = box; box.parentBox = p.parentBoxBefore || null;
      this.activate(np);
    },
    replaceIn: function (t, old, neu) {
      if (t.root === old) { t.root = neu; return; }
      (function walk(n) {
        if (!n || n instanceof Pane) return;
        if (n.a === old) { n.a = neu; return; } if (n.b === old) { n.b = neu; return; }
        walk(n.a); walk(n.b);
      })(t.root);
    },
    closePane: function (p) {
      var t = p.tab;
      if (t.panes().length === 1) { this.closeTab(t); return; }
      var parentBox = null;
      (function find(n) { if (!n || n instanceof Pane) return; if (n.a === p || n.b === p) { parentBox = n; return; } find(n.a); find(n.b); })(t.root);
      var other = parentBox.a === p ? parentBox.b : parentBox.a;
      p.destroy();
      var otherNode = other.node;
      parentBox.node.parentNode.replaceChild(otherNode, parentBox.node);
      this.replaceIn(t, parentBox, other);
      this.activate(t.panes()[0]);
    },
    layout: function (value, save) {
      if (!this.node) return;
      this.node.classList.toggle('qs-horizontal', value === 'horizontal');
      if (save) LS.set('layout', value);
    },
    theme: function (name, save) {
      var self = this;
      if (!/^[A-Za-z0-9_-]+$/.test(name || '')) return;
      fetch('/Q/shell/theme/' + name + '.json', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (t) {
        if (!t || !self.node) return;
        var map = { background: 'bg', foreground: 'fg', dim: 'dim', accent: 'accent', cursor: 'cursor', selection: 'sel', prompt: 'prompt', error: 'err', border: 'border', opacity: 'opacity' };
        Object.keys(t).forEach(function (k) {
          var v = t[k]; if (typeof v !== 'string' && typeof v !== 'number') return;
          var name2 = map[k] || (/^(bright)?(black|red|green|yellow|blue|magenta|cyan|white)$/.test(k) ? k.replace(/^bright/, 'b') : null);
          if (name2 && /^[#(),.%\w\s-]+$/.test(String(v))) self.node.style.setProperty('--qs-' + name2, String(v));
        });
        if (save) LS.set('theme', name);
      });
    },
    elevated: function (until) { this.sudoUntil = until ? until * 1000 : 0; this.paintStatus(); },
    paintStatus: function () {
      if (!this.statusEl) return;
      var CONN = { live: ['connected (WebSocket)', 'qs-conn-live'], polling: ['connected (polling)', 'qs-conn-poll'],
        reconnecting: ['reconnecting…', 'qs-conn-down'], 'signed-out': ['signed out: sign in at /Q/panel', 'qs-conn-out'],
        connecting: ['connecting…', 'qs-conn-poll'] };
      var c = CONN[Link.conn] || CONN.connecting;
      var dot = el('span', 'qs-conn ' + c[1], '\u25CF');
      dot.title = c[0]; dot.setAttribute('role', 'status'); dot.setAttribute('aria-label', 'Shell ' + c[0]);
      this.statusEl.textContent = '';
      this.statusEl.appendChild(dot);
      if (!this.info || Link.conn === 'signed-out' || Link.conn === 'reconnecting') { this.statusEl.appendChild(document.createTextNode(' ' + c[0])); return; }
      this.statusEl.appendChild(document.createTextNode(' ' + (this.info.brand ? this.info.brand + ' · ' : '') + 'tier ' + this.info.tier + (this.info.allowSystem ? ' · OS commands on' : '') + (Link.mode === 'http' ? ' · polling' : '')));
      if (this.info.user) {
        var u = el('span', this.info.root ? 'qs-root' : '', ' · runs as ' + this.info.user);
        if (this.info.root) u.title = 'Commands run as root. Set Q.shell.user to run them as an ordinary user.';
        this.statusEl.appendChild(u);
      }
      if (this.sudoUntil > Date.now()) {
        var s = el('span', 'qs-sudo', 'sudo until ' + new Date(this.sudoUntil).toTimeString().slice(0, 5));
        this.statusEl.appendChild(s);
      }
    },
    onHello: function (m) { this.info = m; this.paintStatus(); },
    notice: function (text, auth) {
      var items = document.querySelectorAll('[data-qshell-open]');
      for (var i = 0; i < items.length; i++) { items[i].classList.add('qshell-off'); items[i].title = text; }
      if (auth) { this.allowed = false; this.why = text; } syncItems();
      // An open console stays open and says what happened in its own pane;
      // closing it on a lost session left the visitor with no message at all.
      if (auth) {
        this.allowed = false; this.why = text;
        Link.setConn('signed-out');
        var p = this.current && this.current.active;
        if (this.isOpen() && p && p.lastNotice !== text) { p.lastNotice = text; p.note(text + ' Sign in at /Q/panel, then press ` here again.\n', 'qs-err'); }
      }
    },
    // Keys: bind <key> <command>, kept in this browser.
    keyName: function (e) {
      var parts = [];
      if (e.ctrlKey) parts.push('ctrl'); if (e.altKey) parts.push('alt'); if (e.shiftKey && e.key.length > 1) parts.push('shift'); if (e.metaKey) parts.push('meta');
      var k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
      parts.push(k);
      return parts.join('+').toLowerCase();
    },
    bind: function (key, command, pane) {
      key = String(key).toLowerCase();
      if (command === null) delete this.binds[key]; else this.binds[key] = command;
      LS.set('binds', this.binds);
      pane.note((command === null ? 'unbound ' + key : 'bound ' + key + ' to ' + command) + '\n');
    },
    listBinds: function () {
      var keys = Object.keys(this.binds);
      if (!keys.length) return 'no bindings (bind F2 workers list)\n';
      return keys.map(function (k) { return k + '  ' + Shell.binds[k]; }).join('\n') + '\n';
    },
    handleBind: function (e, pane) {
      var name = this.keyName(e);
      if (!this.binds[name]) return false;
      pane.run(this.binds[name]);
      return true;
    },
    setSticky: function (on) { this.sticky.ctrl = on; if (this.ctrlBtn) this.ctrlBtn.classList.toggle('on', on); },
    // The on-screen key row, for phones.
    softKey: function (k, btn) {
      var p = this.current && this.current.active; if (!p) return;
      if (k === 'Ctrl') { this.setSticky(!this.sticky.ctrl); p.focus(); return; }
      if (k.length === 1) {
        if (this.sticky.ctrl) { p.key_({ key: k, ctrlKey: true, altKey: false, shiftKey: false, metaKey: false, preventDefault: function () {} }); this.setSticky(false); p.focus(); return; }
        var inp = p.input, s = inp.selectionStart, v = inp.value;
        inp.value = v.slice(0, s) + k + v.slice(inp.selectionEnd); inp.setSelectionRange(s + 1, s + 1); p.focus(); return;
      }
      if (k === 'ArrowLeft' || k === 'ArrowRight') { var i = p.input, c = i.selectionStart + (k === 'ArrowLeft' ? -1 : 1); c = Math.max(0, Math.min(i.value.length, c)); i.setSelectionRange(c, c); p.focus(); return; }
      p.key_({ key: k, ctrlKey: false, altKey: false, shiftKey: false, metaKey: false, preventDefault: function () {} });
      p.focus();
    }
  };

  // ── Wiring: the toolbar item and the toggle key ─────────────────────
  // The toolbar item mirrors the console: showing (aria-expanded), running
  // but hidden, the connection's dot, or unavailable until signed in.
  function syncItems() {
    var items = document.querySelectorAll('[data-qshell-open]');
    var started = !!Shell.node, open = Shell.isOpen();
    var conn = { live: 'qshell-c-live', polling: 'qshell-c-poll', reconnecting: 'qshell-c-down', 'signed-out': 'qshell-c-out' }[Link.conn] || '';
    var state = !Shell.allowed ? (Shell.why || 'Sign in to the Control Panel to use the shell.')
      : open ? 'Shell: showing (press ' + TOGGLE + ' or Esc to hide)'
      : started ? 'Shell: running, hidden (press ' + TOGGLE + ' to show)'
      : 'Shell (press ' + TOGGLE + ')';
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      it.setAttribute('aria-expanded', open ? 'true' : 'false');
      it.setAttribute('aria-disabled', Shell.allowed ? 'false' : 'true');
      it.setAttribute('aria-label', state);
      it.title = state;
      it.classList.toggle('qshell-off', !Shell.allowed);
      it.classList.toggle('qshell-started', started);
      it.classList.toggle('qshell-hidden-run', started && !open);
      ['qshell-c-live', 'qshell-c-poll', 'qshell-c-down', 'qshell-c-out'].forEach(function (c) { it.classList.toggle(c, started && c === conn); });
    }
  }
  function focusItem() {
    var it = document.querySelector('[data-qshell-open]');
    if (it && typeof it.focus === 'function') it.focus({ preventScroll: true });
  }
  function typingElsewhere(t) {
    if (!t || !t.tagName) return false;
    if (Shell.node && Shell.node.contains(t)) return false;
    var tag = t.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable;
  }
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    var isToggle = e.key === TOGGLE || (TOGGLE === '`' && e.key === '~');
    if (!isToggle || typingElsewhere(e.target)) return;
    if (Shell.node && Shell.node.contains(e.target)) return; // the pane's own editor decides
    e.preventDefault();
    Shell.toggle();
  }, true);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && Shell.isOpen() && !(Shell.node.contains(e.target))) Shell.hide();
  });
  function wireItems() {
    var items = document.querySelectorAll('[data-qshell-open]');
    for (var i = 0; i < items.length; i++) {
      items[i].addEventListener('click', function (e) {
        e.preventDefault();
        if (!Shell.allowed) { location.href = '/Q/panel'; return; }
        Shell.toggle();
      });
    }
  }
  // Whether this visitor may use the shell: a signed-in panel session.
  function check() {
    var t = token();
    Shell.allowed = false;
    if (!t) { Shell.notice('Sign in to the Control Panel to use the shell.', true); return; }
    fetch('/Q/api/shell/session?session=probe', { headers: { 'X-Panel-Token': t }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (x) {
        if (x.s === 200) {
          Shell.allowed = true;
          var items = document.querySelectorAll('[data-qshell-open]');
          Shell.why = null; syncItems();
          if (x.j.toggleKey) TOGGLE = x.j.toggleKey;
          if (location.hash === '#shell') Shell.show();
        } else {
          Shell.notice(x.s === 404 ? (x.j.error || 'The shell is switched off.') : 'Sign in to the Control Panel to use the shell.', true);
        }
      }).catch(function () { Shell.notice('The shell is not available.', true); });
  }
  window.QShell = Shell;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { wireItems(); check(); });
  else { wireItems(); check(); }
})();
