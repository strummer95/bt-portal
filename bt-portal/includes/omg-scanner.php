<?php
/**
 * BT Portal — OMG Scanner (Other > OMG Scanner).
 *
 * OMG's packing slips carry a QR of the order number, but the gun sends the
 * digits with no terminator. Scanning a stack meant reaching for the keyboard
 * between every code, which is what this tab removes.
 *
 * There is no server side and no table: the scanner never talks to the API.
 * Codes are held in the browser and mirrored into localStorage so a stray
 * refresh mid-stack doesn't lose the pile. Clearing the list is the only way
 * anything leaves.
 *
 * ── Two things this file has to get right ──────────────────────────────────
 *
 * 1. WHERE THE KEYSTROKES GO.
 *    The gun is a keyboard. Its characters land on whatever has focus, and
 *    with nothing focused they land on document. So capture is a document
 *    listener — but that listener is live on every tab of the portal, and a
 *    scan on the Schedule board must not silently pile up here. Every event
 *    is therefore checked against the pane's own .active class before it is
 *    taken, and skipped outright when the target is a field the user is
 *    typing in.
 *
 * 2. WHERE A CODE ENDS.
 *    Without a terminator the only signal is timing: a gun emits characters
 *    a few milliseconds apart and then stops. So each keystroke restarts a
 *    short timer, and the code is committed when that timer runs out. The gap
 *    is adjustable because a slow gun will otherwise split one code in half.
 *    A code that DOES arrive with Enter or Tab is committed immediately and
 *    the timer cancelled, so both kinds of gun work without a setting change.
 *
 * The portal reset (#bt-schedule-app *) is ID + universal, specificity 1,0,0.
 * Every rule below is #bt-omg-scanner + class, 1,0,1, so it outranks the reset
 * without needing the reset to know this subtree exists.
 */
if (!defined('ABSPATH')) exit;

add_shortcode('bt_omg_scanner', 'btp_omg_scanner_shortcode');

function btp_omg_scanner_shortcode() {
    ob_start();
    ?>
<div id="bt-omg-scanner">

  <div class="omg-head">
    <div class="omg-title">OMG <span>Scanner</span></div>
    <div class="omg-status live" id="omgStatus">
      <span class="omg-dot"></span><span id="omgStatusText">Listening</span>
    </div>
  </div>

  <div class="omg-readout" id="omgReadout">
    <div class="omg-label">Last scan</div>
    <div class="omg-big omg-empty" id="omgLast">Scan a code to begin</div>
    <div class="omg-dupflag" id="omgDupFlag">Already scanned &mdash; see the list below</div>
  </div>

  <div class="omg-counts">
    <div class="omg-count"><div class="omg-n" id="omgTotal">0</div><div class="omg-l">Scans</div></div>
    <div class="omg-count uniq"><div class="omg-n" id="omgUnique">0</div><div class="omg-l">Unique orders</div></div>
    <div class="omg-count rep"><div class="omg-n" id="omgRepeats">0</div><div class="omg-l">Repeats</div></div>
  </div>

  <div class="omg-panel">
    <div class="omg-panelhead">
      <div class="omg-label">Paste-ready</div>
      <label class="omg-opt">Separator
        <select id="omgSep">
          <option value="space" selected>Space</option>
          <option value="newline">New line</option>
          <option value="comma">Comma</option>
          <option value="tab">Tab</option>
        </select>
      </label>
      <label class="omg-opt"><input type="checkbox" id="omgTrailing"> End with a separator too</label>
    </div>
    <textarea id="omgOut" readonly spellcheck="false"
      placeholder="Scanned numbers build up here, ready to select and paste."></textarea>
    <div class="omg-row">
      <button type="button" class="omg-btn primary" id="omgCopy">Copy this</button>
      <button type="button" class="omg-btn" id="omgSelect">Select all</button>
      <button type="button" class="omg-btn" id="omgCsv">Download CSV</button>
      <button type="button" class="omg-btn danger" id="omgClear">Clear all</button>
    </div>
  </div>

  <div class="omg-settings">
    <label class="omg-opt"><input type="checkbox" id="omgSound" checked> Beep on scan</label>
    <label class="omg-opt"><input type="checkbox" id="omgSkipDupes"> Ignore repeat scans</label>
    <label class="omg-opt">End-of-scan gap
      <select id="omgGap">
        <option value="80">80 ms &mdash; fast gun</option>
        <option value="120" selected>120 ms &mdash; default</option>
        <option value="200">200 ms &mdash; slow gun</option>
        <option value="350">350 ms &mdash; very slow</option>
      </select>
    </label>
    <label class="omg-opt"><input type="checkbox" id="omgPause"> Pause capture</label>
  </div>

  <div class="omg-listhead">
    <div class="omg-label">Scanned</div>
    <input type="text" id="omgManual" placeholder="Type a number, press Enter">
    <input type="text" id="omgFind" placeholder="Find a number&hellip;">
  </div>

  <div id="omgList"></div>

  <p class="omg-hint">
    Capture only runs while this tab is open, so scans on the Schedule board never land here.
    Each code is committed on its own once the gun stops sending characters &mdash; codes with no
    Enter or space at the end still register. Typing in either box above pauses capture until you click away.
  </p>

  <div class="omg-toast" id="omgToast"></div>
</div>

<style>
#bt-omg-scanner { padding:22px 24px 60px; max-width:1080px; margin:0 auto; font-family:'Barlow',sans-serif; color:#1a1f5e; }

#bt-omg-scanner .omg-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding-bottom:14px; border-bottom:2px solid #e8eaf0; }
#bt-omg-scanner .omg-title { font-family:'Oswald',sans-serif; font-size:22px; font-weight:600; letter-spacing:.02em; text-transform:uppercase; color:#1a1f5e; }
#bt-omg-scanner .omg-title span { color:#e91e8c; }
#bt-omg-scanner .omg-status { margin-left:auto; display:inline-flex; align-items:center; gap:8px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; padding:5px 13px; border-radius:999px; border:1px solid #e8eaf0; background:#fff; color:#5a6380; }
#bt-omg-scanner .omg-dot { width:8px; height:8px; border-radius:50%; background:#2E7D32; }
#bt-omg-scanner .omg-status.live { color:#2E7D32; border-color:rgba(46,125,50,.35); }
#bt-omg-scanner .omg-status.live .omg-dot { animation:omgPulse 2s infinite; }
#bt-omg-scanner .omg-status.paused { color:#9ca3b8; }
#bt-omg-scanner .omg-status.paused .omg-dot { background:#9ca3b8; animation:none; }
@keyframes omgPulse { 0%{box-shadow:0 0 0 0 rgba(46,125,50,.5)} 70%{box-shadow:0 0 0 8px rgba(46,125,50,0)} 100%{box-shadow:0 0 0 0 rgba(46,125,50,0)} }

#bt-omg-scanner .omg-label { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:#9ca3b8; }

#bt-omg-scanner .omg-readout { position:relative; overflow:hidden; margin:20px 0 0; padding:24px 24px 26px; background:#fff; border:1px solid #e8eaf0; border-radius:10px; box-shadow:0 1px 3px rgba(26,31,94,.06); }
#bt-omg-scanner .omg-readout::after { content:""; position:absolute; inset:0; pointer-events:none; opacity:0; background:#2E7D32; }
#bt-omg-scanner .omg-readout.flash::after { animation:omgFlash .45s ease-out; }
#bt-omg-scanner .omg-readout.flashdup::after { background:#d32f2f; animation:omgFlash .45s ease-out; }
@keyframes omgFlash { 0%{opacity:.18} 100%{opacity:0} }
#bt-omg-scanner .omg-big { margin-top:10px; font-family:ui-monospace,"SF Mono","Cascadia Mono",Menlo,Consolas,monospace; font-variant-numeric:tabular-nums; font-size:clamp(40px,9vw,80px); line-height:1; font-weight:700; letter-spacing:-.02em; word-break:break-all; color:#0f1240; }
#bt-omg-scanner .omg-big.omg-empty { font-family:'Barlow',sans-serif; font-size:clamp(18px,3.4vw,26px); font-weight:500; letter-spacing:0; color:#9ca3b8; }
#bt-omg-scanner .omg-dupflag { display:none; margin-top:12px; font-size:14px; font-weight:600; color:#d32f2f; }
#bt-omg-scanner .omg-dupflag.on { display:block; }

#bt-omg-scanner .omg-counts { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
#bt-omg-scanner .omg-count { flex:1 1 140px; padding:13px 16px; background:#fff; border:1px solid #e8eaf0; border-radius:9px; }
#bt-omg-scanner .omg-n { font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace; font-variant-numeric:tabular-nums; font-size:28px; font-weight:700; line-height:1; color:#1a1f5e; }
#bt-omg-scanner .omg-count.uniq .omg-n { color:#e91e8c; }
#bt-omg-scanner .omg-count.rep .omg-n { color:#d32f2f; }
#bt-omg-scanner .omg-l { margin-top:6px; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:700; letter-spacing:.11em; text-transform:uppercase; color:#9ca3b8; }

#bt-omg-scanner .omg-panel { margin-top:18px; padding:17px 18px; background:#fff; border:1px solid #e8eaf0; border-radius:10px; }
#bt-omg-scanner .omg-panelhead { display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin-bottom:11px; }
#bt-omg-scanner .omg-panelhead .omg-opt:first-of-type { margin-left:auto; }
#bt-omg-scanner .omg-opt { display:inline-flex; align-items:center; gap:7px; font-size:14px; color:#5a6380; cursor:pointer; }
#bt-omg-scanner .omg-opt select { font-family:'Barlow',sans-serif; font-size:14px; color:#1a1f5e; background:#f4f5f9; border:1px solid #e8eaf0; border-radius:6px; padding:5px 8px; }
#bt-omg-scanner textarea#omgOut { width:100%; min-height:92px; resize:vertical; padding:12px 13px; font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace; font-size:15px; line-height:1.7; color:#0f1240; background:#f4f5f9; border:1px solid #e8eaf0; border-radius:8px; word-break:break-all; }
#bt-omg-scanner textarea#omgOut:focus { outline:2px solid #e91e8c; outline-offset:-1px; }

#bt-omg-scanner .omg-row { display:flex; gap:9px; flex-wrap:wrap; margin-top:12px; }
#bt-omg-scanner .omg-btn { font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; padding:9px 16px; border-radius:7px; cursor:pointer; background:#fff; color:#1a1f5e; border:1px solid #e8eaf0; transition:all .15s; }
#bt-omg-scanner .omg-btn:hover { border-color:#9ca3b8; background:#f4f5f9; }
#bt-omg-scanner .omg-btn:focus-visible { outline:2px solid #e91e8c; outline-offset:2px; }
#bt-omg-scanner .omg-btn.primary { background:#1a1f5e; color:#fff; border-color:#1a1f5e; }
#bt-omg-scanner .omg-btn.primary:hover { background:#232875; }
#bt-omg-scanner .omg-btn.danger:hover { border-color:#d32f2f; color:#d32f2f; background:#fff; }

#bt-omg-scanner .omg-settings { display:flex; gap:18px; align-items:center; flex-wrap:wrap; margin-top:16px; padding-top:15px; border-top:1px solid #e8eaf0; }

#bt-omg-scanner .omg-listhead { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin:24px 0 10px; }
#bt-omg-scanner .omg-listhead input { font-family:'Barlow',sans-serif; font-size:14px; color:#1a1f5e; background:#fff; border:1px solid #e8eaf0; border-radius:7px; padding:8px 12px; min-width:180px; }
#bt-omg-scanner .omg-listhead input:focus { outline:2px solid #e91e8c; outline-offset:-1px; }
#bt-omg-scanner .omg-listhead #omgManual { margin-left:auto; }

#bt-omg-scanner .omg-ul { list-style:none; border:1px solid #e8eaf0; border-radius:9px; overflow:hidden; background:#fff; }
#bt-omg-scanner .omg-ul li { display:flex; align-items:center; gap:14px; padding:10px 15px; border-bottom:1px solid #eef0f5; }
#bt-omg-scanner .omg-ul li:last-child { border-bottom:none; }
#bt-omg-scanner .omg-ul li.dup { background:linear-gradient(90deg,rgba(211,47,47,.07),transparent 42%); }
#bt-omg-scanner .omg-idx { min-width:34px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; font-variant-numeric:tabular-nums; color:#9ca3b8; }
#bt-omg-scanner .omg-code { font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace; font-size:19px; font-weight:600; font-variant-numeric:tabular-nums; color:#0f1240; }
#bt-omg-scanner .omg-tag { font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#d32f2f; border:1px solid rgba(211,47,47,.4); padding:2px 7px; border-radius:4px; }
#bt-omg-scanner .omg-time { margin-left:auto; font-size:13px; color:#9ca3b8; font-variant-numeric:tabular-nums; }
#bt-omg-scanner .omg-del { background:none; border:none; cursor:pointer; font-size:18px; line-height:1; padding:3px 8px; border-radius:5px; color:#9ca3b8; }
#bt-omg-scanner .omg-del:hover { color:#d32f2f; background:rgba(211,47,47,.09); }

#bt-omg-scanner .omg-blank { padding:32px 20px; text-align:center; font-size:15px; color:#9ca3b8; background:#fff; border:1px dashed #e8eaf0; border-radius:9px; }
#bt-omg-scanner .omg-hint { margin-top:14px; font-size:13.5px; line-height:1.6; color:#9ca3b8; }

#bt-omg-scanner .omg-toast { position:fixed; left:50%; bottom:26px; transform:translate(-50%,80px); z-index:99999; background:#1a1f5e; color:#fff; font-weight:600; font-size:14px; padding:11px 20px; border-radius:999px; opacity:0; pointer-events:none; transition:transform .22s cubic-bezier(.2,.9,.3,1),opacity .22s; }
#bt-omg-scanner .omg-toast.show { transform:translate(-50%,0); opacity:1; }

@media (prefers-reduced-motion: reduce) { #bt-omg-scanner * { animation:none !important; transition:none !important; } }
</style>

<script>
(function () {
  "use strict";

  var KEY   = 'btOmgScans';
  var PANE  = 'bt-tab-omgscan';
  var scans = [];
  var buf = '', idleTimer = null, lastKey = 0;

  function $(id) { return document.getElementById(id); }

  /* The pane is in the DOM on every tab. Capture only when it is the visible
     one, or a scan on the Schedule board would silently collect here. */
  function paneActive() {
    var p = $(PANE);
    return !!p && p.classList.contains('active');
  }

  function save() {
    try { localStorage.setItem(KEY, JSON.stringify(scans)); } catch (e) {}
  }
  function load() {
    try {
      var raw = localStorage.getItem(KEY);
      if (raw) scans = JSON.parse(raw) || [];
    } catch (e) { scans = []; }
  }

  var actx = null;
  function beep(freq, ms) {
    if (!$('omgSound').checked) return;
    try {
      if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      var o = actx.createOscillator(), g = actx.createGain();
      o.type = 'square'; o.frequency.value = freq;
      g.gain.setValueAtTime(0.05, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + ms / 1000);
      o.connect(g); g.connect(actx.destination);
      o.start(); o.stop(actx.currentTime + ms / 1000);
    } catch (e) {}
  }

  function toast(msg) {
    var t = $('omgToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(function () { t.classList.remove('show'); }, 1600);
  }

  function flash(dup) {
    var r = $('omgReadout');
    r.classList.remove('flash', 'flashdup');
    void r.offsetWidth;
    r.classList.add(dup ? 'flashdup' : 'flash');
    setTimeout(function () { r.classList.remove('flash', 'flashdup'); }, 500);
  }

  function commit(raw) {
    var code = String(raw).replace(/[\u0000-\u001F\u007F]/g, '').trim();
    if (code.length < 2) return;

    var dup = scans.some(function (s) { return s.code === code; });

    $('omgLast').textContent = code;
    $('omgLast').classList.remove('omg-empty');
    $('omgDupFlag').classList.toggle('on', dup);

    if (dup && $('omgSkipDupes').checked) {
      flash(true); beep(320, 160);
      toast('Skipped \u2014 already scanned');
      return;
    }

    scans.push({ code: code, t: Date.now(), dup: dup });
    flash(dup);
    beep(dup ? 320 : 880, dup ? 160 : 70);
    render(); save();
  }

  function flush() { if (buf) { var b = buf; buf = ''; commit(b); } }

  document.addEventListener('keydown', function (e) {
    if (!paneActive() || $('omgPause').checked) return;
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    if (e.key === 'Enter' || e.key === 'Tab') {
      if (buf) { e.preventDefault(); clearTimeout(idleTimer); flush(); }
      return;
    }
    if (e.key.length !== 1) return;

    var now = Date.now();
    if (now - lastKey > 900) buf = '';   // long pause: a new code is starting
    lastKey = now;
    buf += e.key;

    clearTimeout(idleTimer);
    idleTimer = setTimeout(flush, parseInt($('omgGap').value, 10));
  });

  $('omgManual').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      var v = this.value.trim();
      if (v) { commit(v); this.value = ''; }
    }
  });

  var SEPS = { space: ' ', newline: '\n', comma: ',', tab: '\t' };
  function codes() { return scans.map(function (s) { return s.code; }); }
  function outText() {
    var sep = SEPS[$('omgSep').value];
    var txt = codes().join(sep);
    if (txt && $('omgTrailing').checked) txt += sep;
    return txt;
  }
  function renderOut() { $('omgOut').value = outText(); }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function hhmmss(ts) {
    var d = new Date(ts);
    return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function recount() {
    var seen = {};
    scans.forEach(function (s) { s.dup = !!seen[s.code]; seen[s.code] = true; });
  }

  function render() {
    var seen = {}, reps = 0;
    scans.forEach(function (s) { if (seen[s.code]) reps++; else seen[s.code] = true; });
    $('omgTotal').textContent   = scans.length;
    $('omgUnique').textContent  = Object.keys(seen).length;
    $('omgRepeats').textContent = reps;

    var q = $('omgFind').value.trim().toLowerCase();
    var rows = scans.map(function (s, i) { return { s: s, i: i }; })
                    .filter(function (r) { return !q || r.s.code.toLowerCase().indexOf(q) !== -1; })
                    .reverse();

    if (!scans.length) {
      $('omgList').innerHTML = '<div class="omg-blank">Nothing scanned yet. Point the gun at a code \u2014 it lands here on its own.</div>';
    } else if (!rows.length) {
      $('omgList').innerHTML = '<div class="omg-blank">No scan matches \u201C' + esc($('omgFind').value) + '\u201D.</div>';
    } else {
      var h = '<ul class="omg-ul">';
      rows.forEach(function (r) {
        h += '<li class="' + (r.s.dup ? 'dup' : '') + '">' +
               '<span class="omg-idx">' + (r.i + 1) + '</span>' +
               '<span class="omg-code">' + esc(r.s.code) + '</span>' +
               (r.s.dup ? '<span class="omg-tag">Repeat</span>' : '') +
               '<span class="omg-time">' + hhmmss(r.s.t) + '</span>' +
               '<button type="button" class="omg-del" data-i="' + r.i + '" aria-label="Remove ' + esc(r.s.code) + '">&times;</button>' +
             '</li>';
      });
      $('omgList').innerHTML = h + '</ul>';

      Array.prototype.forEach.call($('omgList').querySelectorAll('.omg-del'), function (b) {
        b.addEventListener('click', function () {
          scans.splice(parseInt(b.getAttribute('data-i'), 10), 1);
          recount(); render(); save();
        });
      });
    }
    renderOut();
  }

  function copyText(txt, label) {
    if (!scans.length) { toast('Nothing to copy yet'); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(function () { toast(label); }, function () { fallback(txt, label); });
    } else fallback(txt, label);
  }
  function fallback(txt, label) {
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); toast(label); }
    catch (e) { toast('Copy blocked \u2014 select the box and copy manually'); }
    document.body.removeChild(ta);
  }

  $('omgCopy').addEventListener('click', function () {
    copyText(outText(), 'Copied ' + scans.length + ' number' + (scans.length === 1 ? '' : 's'));
  });
  $('omgSelect').addEventListener('click', function () {
    if (!scans.length) { toast('Nothing to select yet'); return; }
    $('omgOut').focus(); $('omgOut').select();
  });
  $('omgCsv').addEventListener('click', function () {
    if (!scans.length) { toast('Nothing to export yet'); return; }
    var lines = ['order_number,scanned_at,repeat'];
    scans.forEach(function (s) {
      lines.push('"' + s.code + '","' + new Date(s.t).toISOString() + '",' + (s.dup ? 'yes' : 'no'));
    });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    a.download = 'omg-scans-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    toast('CSV downloaded');
  });
  $('omgClear').addEventListener('click', function () {
    if (!scans.length) { toast('List is already empty'); return; }
    if (confirm('Clear all ' + scans.length + ' scans? This cannot be undone.')) {
      scans = []; render(); save(); toast('List cleared');
    }
  });

  $('omgSep').addEventListener('change', renderOut);
  $('omgTrailing').addEventListener('change', renderOut);
  $('omgFind').addEventListener('input', render);

  function status() {
    var s = $('omgStatus');
    if ($('omgPause').checked) { s.className = 'omg-status paused'; $('omgStatusText').textContent = 'Paused'; }
    else { s.className = 'omg-status live'; $('omgStatusText').textContent = 'Listening'; }
  }
  $('omgPause').addEventListener('change', status);

  load(); render(); status();
})();
</script>
    <?php
    return ob_get_clean();
}
