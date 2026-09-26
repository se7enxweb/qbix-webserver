
var S={{stats}},R={{recent}},BASE=location.origin,
    L=document.getElementById('log'),SP=document.getElementById('spark'),
    LW=document.getElementById('log-wrap'),paused=false,MAX_LOG=300,
    sidFilter='',scFilter='',knownSids={},knownCodes={};

// Status code descriptions
var SC={'200':'OK','201':'Created','204':'No Content','206':'Partial',
'301':'Moved','302':'Found','304':'Not Modified','307':'Redirect','308':'Permanent',
'400':'Bad Request','401':'Unauthorized','403':'Forbidden','404':'Not Found',
'405':'Method Not Allowed','408':'Timeout','413':'Too Large','414':'URI Too Long',
'429':'Too Many Requests','500':'Internal Error','502':'Bad Gateway',
'503':'Unavailable','504':'Gateway Timeout'};

// Uptime ticker — animate every second client-side
var upSec=0,upTimer=null;
function fmtUp(s){
if(s<60)return s+'s';
var d=Math.floor(s/86400),h=Math.floor((s%86400)/3600),m=Math.floor((s%3600)/60),ss=s%60;
if(d>0)return d+'d '+h+'h '+m+'m';
if(h>0)return h+'h '+m+'m '+ss+'s';
return m+'m '+ss+'s';
}
// OS, then PHP, then the Live/Connecting status, uptime last.
function tickUp(){upSec++;el('sub',(S.os||'')+' \u00B7 PHP '+(S.php||'')+' \u00B7 <span class="ws"><span class="wd'+(wsLive?' on':'')+'" id="wd"></span><span id="wl">'+(wsLive?'Live':'Connecting')+'</span></span> \u00B7 Up '+fmtUp(upSec))}
var wsLive=false;

// Sparkline ticker — shift left every second even when idle
var spData=new Array(60).fill(0),spDirty=false;
function tickSpark(){
spData.push(0);if(spData.length>60)spData.shift();
renderSpark();
}
function renderSpark(){
var mx=Math.max.apply(null,spData)||1;
SP.innerHTML=spData.map(function(v){return'<div style="height:'+Math.max(1,v/mx*36)+'px" title="'+v+' req/s"></div>'}).join('');
}

function U(s){S=s;
el('sr',s.requests.toLocaleString());
el('crps',s.currentRps);
el('avg',s.avgMs+'<span style="font-size:12px;font-weight:400">ms</span>');
el('slow',s.slowest+'ms');
el('sm',s.memory+' MB');el('smp',s.memoryPeak+' MB');
// s.workers is "idle/live". Shown as the live count, with idle and busy on their
// own labelled rows: "590/590" read as a count of something unexplained. A
// dynamic pool (workersSpare > 0) also shows the most it will grow to.
(function(){var w=String(s.workers).split('/');
if(w.length===2){var idle=+w[0],total=+w[1];el('sw',total+(s.workersSpare>0&&s.workersMax>total?' <span style="font-size:10px;color:var(--dim)">of '+s.workersMax+'</span>':''));el('swi',idle+'');el('swb',(total-idle)+'')}
else{el('sw',s.workers+(s.forkMode?' <span style="font-size:10px;color:var(--yel)">(fork mode)</span>':''));el('swi','\u2014');el('swb','\u2014')}})();el('wsc',s.wsConnections);el('wsr',s.wsRooms);
// System RAM
if(s.systemRam){
  el('sysram',ramPct(s.systemRam));
  el('sysram-detail',ramDetail(s.systemRam));
}
// Worker COW stats
if(s.workerStats){
  var ws=s.workerStats;
  // Real footprint (PSS) when available, not summed RSS: RSS counts each
  // shared copy-on-write page once per worker, over-reporting the warmed
  // baseline several-fold.
  var real=ws.totalPssKb>0;
  var totalKb=real?ws.totalPssKb:ws.totalRssKb;
  var avgKb=ws.count>0?Math.round(totalKb/ws.count):0;
  var fpmEquiv=ws.count*50;
  el('cow-total',fmtMem(totalKb*1024));
  // One item per line, so the figures read at a glance.
  el('cow-detail','<div>'+ws.idle+'/'+ws.count+' idle</div><div>'+(real?'real ':'rss ')+fmtMem(avgKb*1024)+'/worker</div><div>fpm would use ~'+fpmEquiv+'MB</div>');
  var ce=document.getElementById('cow-total');
  if(ce)ce.style.color='var(--grn)';
}else if(s.forkMode){
  el('cow-total','fork');
  el('cow-detail','<div>each request forks a fresh process (~120KB COW)</div>');
}
el('s2',s.status2xx);el('s3',s.status3xx);el('s4',s.status4xx);el('s5',s.status5xx);
// PHP extensions against the standard set: the largest set this PHP provides,
// what it lacks per tier, and the command that installs it.
if(s.extensions&&!s.extensions.error){(function(){var x=s.extensions,mr=x.missing_required||[],mo=x.missing_recommended||[];
el('extv',esc(String(x.variant_detected||'—')));var ve=document.getElementById('extv');if(ve)ve.className='v '+(mr.length?'sev-crit':(mo.length?'sev-warn':'sev-ok'));
var d='<div>PHP '+esc(String(x.php))+' &#183; <span class="nw">'+esc(String(x.platform))+'</span></div>';
if(mr.length)d+='<div class="sev-crit">required: '+esc(mr.join(', '))+'</div>';
if(mo.length)d+='<div class="sev-warn">recommended: '+esc(mo.join(', '))+'</div>';
if(!mr.length&&!mo.length)d+='<div class="sev-ok">nothing missing</div>';
if(x.hints&&x.hints.length)d+='<div title="'+esc(x.hints.join('\n'))+'"><code>'+esc(x.hints[0]).split(' ').map(function(t){return '<span class="nw">'+t+'</span>'}).join(' ')+'</code></div>';
el('extd',d)})()}
// Q shell: sessions and jobs now, and the latest commands; OS commands in amber.
if(s.shell&&s.shell.enabled){(function(){var x=s.shell,c=document.getElementById('shellcard');if(c)c.hidden=false;
el('shv',x.commands+'<span style="font-size:12px;font-weight:400"> cmds</span>');
var d='<div>'+x.sessions+' session'+(x.sessions==1?'':'s')+' &#183; '+x.running+' running &#183; OS '+(x.allowSystem?'<span class="sev-warn">on</span>':'off')+(x.system?' ('+x.system+')':'')+'</div>';
(x.recent||[]).forEach(function(r){d+='<div class="'+(r.system?'sev-warn':(r.exit?'sev-crit':''))+'" title="'+esc(r.time+' '+r.ip+' exit '+r.exit+' '+r.ms+'ms')+'"><code>'+esc(r.line)+'</code></div>'});
if(!(x.recent||[]).length)d+='<div>no commands yet</div>';
el('shd',d)})()}
el('bout',s.bytesFormatted);el('conn',s.connections);el('ka',s.keepAlive||0);
el('srps',(s.rps)+' avg req/s');
// Requests served, not workers: "5 PHP / 7 static" was read as a worker count.
el('phpn',s.phpRequests.toLocaleString());el('stn',s.staticRequests.toLocaleString());
// Offer every status code the server has recorded, not just the live ones.
if(s.statusCodes){for(var _sc in s.statusCodes){if(!knownCodes[_sc]){knownCodes[_sc]=1;addScOption(_sc)}}}
el('reqc',s.requests.toLocaleString()+' total');
// Sync uptime from server
upSec=s.uptimeSec||0;
// Sync sparkline from server
if(s.sparkline){spData=s.sparkline.slice();renderSpark()}
// Top paths
var pp=document.getElementById('paths');
if(s.topPaths&&s.topPaths.length){pp.innerHTML=s.topPaths.map(tpRow).join('')}
// Rooms
var rm=document.getElementById('rooms');
if(s.activeRooms&&s.activeRooms.length){rm.innerHTML=s.activeRooms.map(function(r){
return'<div class="room"><span class="n">'+esc(r.name)+'</span><span>'+r.members+' members</span></div>'}).join('')}
else{rm.innerHTML='<div style="color:var(--dim);padding:8px;font-size:12px">No active rooms</div>'}
if(s.sessions&&s.sessions.length){updateSidDropdown(s.sessions)}}

function el(id,v){var e=document.getElementById(id);if(e)e.innerHTML=v}
function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
// Top paths and System RAM helpers. Each mirrors a PHP method of the same
// name in Q_WebServer_Dashboard, which renders the first paint, so the two
// must stay in step: fmtMs, tpRow (topPathRow), ramLevel, swapLevel, swapTitle, fmtRate, fmtSwapMb,
// ramPct (ramPercentHtml), ramDetail (ramDetailHtml).
function fmtMs(ms){ms=+ms||0;var r=Math.round(ms*10)/10;if(r<1000)return r+' ms';return(ms/1000).toFixed(2)+' s'}
function tpRow(p){var h=esc(String(p.path));return'<div class="tp"><span class="p" title="'+h+'">'+h+
'</span><span class="c">'+(parseInt(p.count,10)||0)+' req</span><span class="a">'+fmtMs(p.avgMs)+' avg</span></div>'}
function ramLevel(pc){pc=+pc||0;return pc>=90?'crit':(pc>=70?'warn':'ok')}
function swapLevel(u,t,k){u=+u||0;t=+t||0;k=(k===null||k===undefined)?null:+k;if(u<=0)return'none';if(k!==null&&k>=10240)return'crit';if(k!==null&&k>=1024)return'warn';if(t>0&&u>=t*0.9)return'warn';return'none'}
function n1(v){return Math.round((+v||0)*10)/10}
function fmtRate(k){k=+k||0;return k<1024?Math.round(k)+' KB/s':n1(k/1024)+' MB/s'}
function fmtSwapMb(m){m=+m||0;return(m>0&&m<1024)?Math.round(m)+' MB':n1(m/1024)+' GB'}
function swapTitle(u,t,k){var l=swapLevel(u,t,k);k=(k===null||k===undefined)?null:+k;
if((+u||0)<=0)return'Nothing is in swap.';
if(l==='crit'||(l==='warn'&&k!==null&&k>=1024))return'Pages are being read back from swap at '+fmtRate(k)+': the server is short of memory and slows down while it waits on disk.';
if(l==='warn')return'Swap is nearly full: if memory runs short now, the kernel has nowhere left to move pages and may stop processes.';
return'Memory the kernel moved out earlier and has not needed back. It costs nothing while it stays there; it only slows the server when pages are read back in'+(k===null?'':' (now '+fmtRate(k)+')')+'.'}
function ramPct(r){return'<span class="sev-'+ramLevel(r.percent)+'">'+(parseInt(r.percent,10)||0)+'%</span>'}
function ramDetail(r){
var o=Math.round((r.usedMb||0)/1024*10)/10+' / '+Math.round((r.totalMb||0)/1024*10)/10+' GB';
var st=+r.swapTotalMb||0,sw=+r.swapUsedMb||0,k=(r.swapInKBps===null||r.swapInKBps===undefined)?null:+r.swapInKBps;
if(st>0||sw>0){var a='swap '+fmtSwapMb(sw)+(st>0?' / '+fmtSwapMb(st):'');if(k!==null&&k>=1024)a+=', '+fmtRate(k)+' in';
o+=' &#183; <span class="sev-'+swapLevel(sw,st,k)+'" title="'+swapTitle(sw,st,k)+'">'+a+'</span>'}
return o}

function fmtMem(b){
if(b<=0)return'\u2014';
if(b<1024)return b+' B';
if(b<1048576)return(b/1024).toFixed(1)+' KB';
return(b/1048576).toFixed(1)+' MB';
}
// A request's memory: the pool worker's heap peak while it ran the script.
// Static files and cache hits ran no PHP, so they have none to show.
function rowMem(e){var b=+e.mem||0;return(e.kind==='php'&&b>0)?fmtMem(b):'\u2014'}

// Put a row at the top of the list; the newest entry is always first.
// Never scrolls by itself: at the top the reader sees the new row; scrolled
// down to older rows, scrollTop grows by the inserted height so what they are
// reading stays where it was. The oldest rows are dropped past the cap.
function insertRow(list,wrap,row,max){
var top=wrap?wrap.scrollTop:0;
list.insertBefore(row,list.firstChild);
if(wrap&&top>0)wrap.scrollTop=top+(row.offsetHeight||0);
while(list.children.length>max)list.removeChild(list.lastChild);
}

var K={php:'🐘',html:'🌐',css:'🎨',js:'\u26A1',img:'🖼',
font:'🔤',json:'📋',xml:'📄',doc:'📑',media:'🎬',file:'📦'};

function shouldShow(e){
if(sidFilter&&(e.sid||'')!==sidFilter)return false;
if(scFilter&&String(e.status)!==scFilter)return false;
return true;
}

function A(e){
if(paused)return;
var vis=shouldShow(e);
var d=document.createElement('div');d.className='le';
if(!vis)d.style.display='none';
d.setAttribute('data-sid',e.sid||'');
d.setAttribute('data-sc',e.status);
d.innerHTML=mkRow(e);
insertRow(L,LW,d,MAX_LOG);
// Track session
if(e.sid&&!knownSids[e.sid]){knownSids[e.sid]=1;addSidOption(e.sid)}
// Track status code
var sc=String(e.status);
if(!knownCodes[sc]){knownCodes[sc]=1;addScOption(sc)}
}

function mkRow(e){
var c=e.status<300?'s2':e.status<400?'s3':e.status<500?'s4':'s5';
var k=K[e.kind]||'📂';
var uri=esc(e.uri);
if(e.method==='GET'){uri='<a href="'+BASE+esc(e.uri)+'" target="_blank">'+uri+'</a>'}
return '<span class="lk">'+k+'</span><span class="lt">'+e.time+'</span><span class="ls '+c+'">'+e.status+
'</span><span class="lm">'+e.method+'</span><span class="lu">'+uri+
'</span><span class="ld">'+(e.ms==null?'':(+e.ms).toFixed(1))+'ms</span><span class="lmem">'+rowMem(e)+'</span>';
}

function togglePause(){
paused=!paused;
var btn=document.getElementById('btn-pause');
btn.textContent=paused?'\u25B6':'\u23F8';
btn.classList.toggle('active',paused);
btn.title=paused?'Resume':'Pause';
}
function clearLog(){L.innerHTML=''}

// Session filter
function updateSidDropdown(sessions){
sessions.forEach(function(s){
if(!knownSids[s.id]){knownSids[s.id]=1;addSidOption(s.id)}
});
}
function addSidOption(sid){
var sel=document.getElementById('sid-filter');
var o=document.createElement('option');
o.value=sid;o.textContent=sid;
sel.appendChild(o);
}
function filterSession(){
sidFilter=document.getElementById('sid-filter').value;
refilterRows();
}

// Status code filter
function addScOption(sc){
var sel=document.getElementById('sc-filter');
var o=document.createElement('option');
o.value=sc;o.textContent=sc+(SC[sc]?' \u2014 '+SC[sc]:'');
// Insert sorted
var opts=sel.options;
for(var i=1;i<opts.length;i++){
if(parseInt(opts[i].value)>parseInt(sc)){sel.insertBefore(o,opts[i]);return}
}
sel.appendChild(o);
}
function filterStatus(){
scFilter=document.getElementById('sc-filter').value;
refilterRows();
}

function refilterRows(){
var rows=L.children;
for(var i=0;i<rows.length;i++){
var r=rows[i],sid=r.getAttribute('data-sid')||'',sc=r.getAttribute('data-sc')||'';
var vis=(!sidFilter||sid===sidFilter)&&(!scFilter||sc===scFilter);
r.style.display=vis?'':'none';
}
}

// R arrives newest-first; insert oldest first so the newest ends on top.
function renderRecent(list){for(var i=list.length-1;i>=0;i--)A(list[i])}

U(S);renderRecent(R);

// 1-second tickers for uptime + sparkline
setInterval(function(){tickUp();tickSpark()},1000);
tickUp();

var wsUrl=(location.protocol==='https:'?'wss://':'ws://')+location.host+'/Q/ws{{tokenParam}}';
var ws;function C(){ws=new WebSocket(wsUrl);
ws.onopen=function(){wsLive=true;tickUp()};
ws.onmessage=function(e){var m=JSON.parse(e.data);if(m.type==='request'){A(m.entry);if(m.stats)U(m.stats)}else if(m.type==='heartbeat'){U(m.stats)}};
ws.onclose=function(){wsLive=false;tickUp();setTimeout(C,2000)}}
C();
// Section tabs, as in the control panel: the one in view is marked.
(function(){var tabs=[].slice.call(document.querySelectorAll('.tabs .tab'));if(!tabs.length||!window.IntersectionObserver)return;
function mark(id){tabs.forEach(function(t){t.classList.toggle('active',t.getAttribute('href')==='#'+id)})}
tabs.forEach(function(t){t.addEventListener('click',function(){mark(t.getAttribute('href').slice(1))})});
var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting)mark(e.target.id)})},{rootMargin:'-120px 0px -60% 0px'});
tabs.forEach(function(t){var s=document.getElementById(t.getAttribute('href').slice(1));if(s)io.observe(s)})})();
