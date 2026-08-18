<?php
/**
 * BT Portal — Chipply Scanner (Other > Chipply Scanner).
 *
 * Scan a stack of Chipply slips, then click through to Order Manager with
 * exactly those orders filtered and ready to select, so labels and status
 * changes can be done on the whole batch at once.
 *
 * ── Why a URL and not a script ────────────────────────────────────────────
 *
 * A page here cannot touch a page on chipply.com — the browser forbids it,
 * which is why driving their filter would otherwise need an extension. It
 * turns out not to be needed: Order Manager reads the orders straight off its
 * own query string.
 *
 *     manage.chipply.com/ng/ecom-orders.html?orderids=8621453,8628454,8621068
 *
 * Bare commas, no brackets. The bracketed form that appears in Chipply's own
 * PDF export filenames is for sales-order.html, a print view, and Order
 * Manager chokes on it: it splits on commas without stripping the brackets, so
 * the first and last ids parse as NaN and only the middle one survives. Verified
 * live — bracketed gave one row and a "NaN +2" chip, bare commas gave all three
 * and a clean "8621453 +2".
 *
 * The `v=` cache-buster Chipply puts on its own links is deliberately omitted.
 * It changes per deploy and is not needed for the filter to apply.
 *
 * ── Batching ──────────────────────────────────────────────────────────────
 *
 * A day is ~180 orders, which is over 1,400 characters of ids alone. Browsers
 * and servers get unreliable past about 2,000, and the failure mode is a
 * silently truncated list — a batch that looks complete but is missing orders
 * off the end, which is far worse than an obvious error. So the list is split
 * into fixed-size batches with a hard character ceiling, and each batch is its
 * own link.
 */
if (!defined('ABSPATH')) exit;

add_shortcode('bt_chipply_scanner', 'btp_chipply_scanner_shortcode');

function btp_chipply_scanner_shortcode() {
    ob_start();
    ?>
<div id="bt-chipscan">

  <div class="cs-head">
    <div class="cs-title">CHIPPLY <span>Scanner</span></div>
    <div class="cs-status live" id="csStatus"><span class="cs-dot"></span><span id="csStatusText">Listening</span></div>
  </div>

  <div class="cs-layout">
   <div class="cs-main">

  <div class="cs-readout" id="csReadout">
    <div class="cs-label">Last scan</div>
    <div class="cs-big cs-empty" id="csLast">Scan a Chipply slip to begin</div>
    <div class="cs-dupflag" id="csDupFlag">Already scanned &mdash; see the list below</div>
    <div class="cs-typing" id="csTyping"></div>
  </div>

  <div class="cs-counts">
    <div class="cs-count"><div class="cs-n" id="csTotal">0</div><div class="cs-l">Scans</div></div>
    <div class="cs-count uniq"><div class="cs-n" id="csUnique">0</div><div class="cs-l">Orders</div></div>
    <div class="cs-count rep"><div class="cs-n" id="csRepeats">0</div><div class="cs-l">Repeats</div></div>
  </div>

  <div class="cs-panel">
    <div class="cs-panelhead">
      <div class="cs-label">Open in Chipply</div>
      <label class="cs-opt">Per batch
        <select id="csBatch">
          <option value="40">40</option>
          <option value="60" selected>60</option>
          <option value="80">80</option>
        </select>
      </label>
    </div>
    <div id="csLinks" class="cs-links"></div>
    <div class="cs-row">
      <button type="button" class="cs-btn" id="csCopyUrl">Copy link</button>
      <button type="button" class="cs-btn" id="csCopyNums">Copy numbers</button>
      <button type="button" class="cs-btn danger" id="csClear">Clear all</button>
    </div>
  </div>

  <div class="cs-settings">
    <label class="cs-opt"><input type="checkbox" id="csSound" checked> Beep on scan</label>
    <label class="cs-opt"><input type="checkbox" id="csSkipDupes" checked> Ignore repeat scans</label>
    <label class="cs-opt">End-of-scan gap
      <select id="csGap">
        <option value="80">80 ms &mdash; fast gun</option>
        <option value="120" selected>120 ms &mdash; default</option>
        <option value="200">200 ms &mdash; slow gun</option>
        <option value="350">350 ms &mdash; very slow</option>
      </select>
    </label>
    <label class="cs-opt"><input type="checkbox" id="csPause"> Pause capture</label>
  </div>

  <div class="cs-listhead">
    <div class="cs-label">Scanned</div>
    <input type="text" id="csManual" placeholder="Type a number, press Enter">
    <input type="text" id="csFind" placeholder="Find a number&hellip;">
  </div>

  <div id="csList"></div>

  <p class="cs-hint">
    Scan straight down the stack, then click a batch to open Order Manager with exactly those orders
    filtered &mdash; ready to select for labels or a status change. Long lists are split into batches
    because an over-long link gets truncated, which would quietly drop orders off the end. Capture only
    runs while this tab is open.
  </p>

   </div><!-- /cs-main -->

   <aside class="cs-guide">
     <div class="cs-guidetitle">How to do this</div>
     <ol class="cs-steps">
       <li>Get your Chipply slips ready.</li>
       <li>Scan them one after another.
           <span class="cs-said">No need to touch the keyboard. Each one beeps.</span></li>
       <li>A <b>red flash</b> means you already scanned that one.
           <span class="cs-said">It was skipped. Just carry on.</span></li>
       <li>Check the <b>Orders</b> count matches your pile.</li>
       <li>Click the blue <b>Open in Chipply</b> button.
           <span class="cs-said">Chipply opens in a new tab with only your orders.</span></li>
       <li>Tick the box at the <b>top of the list</b> to select them all.</li>
       <li>Print your labels, or change the status.</li>
       <li>Come back here and press <b>Clear all</b>.</li>
     </ol>
     <div class="cs-guidefoot">
       <b>More than one button?</b> Big piles get split up. Do the first one, come back, then do the next.
     </div>
   </aside>
  </div><!-- /cs-layout -->

  <div class="cs-toast" id="csToast"></div>
</div>

<style>
#bt-chipscan { padding:22px 24px 60px; max-width:1080px; margin:0 auto; font-family:'Barlow',sans-serif; color:#1a1f5e; }
#bt-chipscan .cs-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding-bottom:14px; border-bottom:2px solid #e8eaf0; }
#bt-chipscan .cs-title { font-family:'Oswald',sans-serif; font-size:22px; font-weight:600; letter-spacing:.02em; text-transform:uppercase; }
#bt-chipscan .cs-title span { color:#e91e8c; }
#bt-chipscan .cs-status { margin-left:auto; display:inline-flex; align-items:center; gap:8px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; padding:5px 13px; border-radius:999px; border:1px solid #e8eaf0; background:#fff; color:#5a6380; }
#bt-chipscan .cs-dot { width:8px; height:8px; border-radius:50%; background:#2E7D32; }
#bt-chipscan .cs-status.live { color:#2E7D32; border-color:rgba(46,125,50,.35); }
#bt-chipscan .cs-status.live .cs-dot { animation:csPulse 2s infinite; }
#bt-chipscan .cs-status.paused { color:#9ca3b8; }
#bt-chipscan .cs-status.paused .cs-dot { background:#9ca3b8; animation:none; }
@keyframes csPulse { 0%{box-shadow:0 0 0 0 rgba(46,125,50,.5)} 70%{box-shadow:0 0 0 8px rgba(46,125,50,0)} 100%{box-shadow:0 0 0 0 rgba(46,125,50,0)} }


/* ---- side-by-side guide ---- */
#bt-chipscan .cs-layout { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
#bt-chipscan .cs-guide { position:sticky; top:16px; padding:20px 20px 22px; background:#fff; border:1px solid #e8eaf0; border-radius:11px; box-shadow:0 1px 3px rgba(26,31,94,.06); }
#bt-chipscan .cs-guidetitle { font-family:'Oswald',sans-serif; font-size:16px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:#1a1f5e; padding-bottom:12px; margin-bottom:4px; border-bottom:2px solid #e91e8c; }
#bt-chipscan .cs-steps { list-style:none; counter-reset:step; }
#bt-chipscan .cs-steps li { counter-increment:step; position:relative; padding:13px 0 13px 40px; border-bottom:1px solid #f0f2f7; font-size:15px; line-height:1.5; color:#1a1f5e; }
#bt-chipscan .cs-steps li:last-child { border-bottom:none; padding-bottom:2px; }
#bt-chipscan .cs-steps li::before { content:counter(step); position:absolute; left:0; top:12px; width:26px; height:26px; border-radius:50%; background:#1a1f5e; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:15px; font-weight:700; display:flex; align-items:center; justify-content:center; }
#bt-chipscan .cs-steps b { font-weight:700; }
#bt-chipscan .cs-steps .cs-said { display:block; margin-top:3px; font-size:13.5px; color:#8b93a8; }
#bt-chipscan .cs-guidefoot { margin-top:16px; padding-top:14px; border-top:1px solid #f0f2f7; font-size:13.5px; line-height:1.55; color:#8b93a8; }
#bt-chipscan .cs-guidefoot b { color:#1a1f5e; }
@media (max-width:900px) {
  #bt-chipscan .cs-layout { grid-template-columns:1fr; }
  #bt-chipscan .cs-guide { position:static; order:-1; }
}

#bt-chipscan .cs-label { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:#9ca3b8; }
#bt-chipscan .cs-readout { position:relative; overflow:hidden; margin:20px 0 12px; padding:24px; background:#fff; border:1px solid #e8eaf0; border-radius:10px; box-shadow:0 1px 3px rgba(26,31,94,.06); }
#bt-chipscan .cs-readout::after { content:""; position:absolute; inset:0; pointer-events:none; opacity:0; background:#2E7D32; }
#bt-chipscan .cs-readout.flash::after { animation:csFlash .45s ease-out; }
#bt-chipscan .cs-readout.flashdup::after { background:#d32f2f; animation:csFlash .45s ease-out; }
@keyframes csFlash { 0%{opacity:.18} 100%{opacity:0} }
#bt-chipscan .cs-big { margin-top:10px; font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace; font-variant-numeric:tabular-nums; font-size:clamp(40px,9vw,80px); line-height:1; font-weight:700; letter-spacing:-.02em; word-break:break-all; color:#0f1240; }
#bt-chipscan .cs-big.cs-empty { font-family:'Barlow',sans-serif; font-size:clamp(18px,3.4vw,26px); font-weight:500; letter-spacing:0; color:#9ca3b8; }
#bt-chipscan .cs-dupflag { display:none; margin-top:12px; font-size:14px; font-weight:600; color:#d32f2f; }
#bt-chipscan .cs-dupflag.on { display:block; }
#bt-chipscan .cs-typing { display:none; margin-top:12px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:17px; font-weight:600; color:#5a6380; }
#bt-chipscan .cs-typing.on { display:block; }
#bt-chipscan .cs-typing b { color:#0f1240; }
#bt-chipscan .cs-typing i { font-style:normal; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#9ca3b8; margin-left:10px; }

#bt-chipscan .cs-counts { display:flex; gap:10px; flex-wrap:wrap; }
#bt-chipscan .cs-count { flex:1 1 140px; padding:13px 16px; background:#fff; border:1px solid #e8eaf0; border-radius:9px; }
#bt-chipscan .cs-n { font-family:ui-monospace,Menlo,Consolas,monospace; font-variant-numeric:tabular-nums; font-size:28px; font-weight:700; line-height:1; color:#1a1f5e; }
#bt-chipscan .cs-count.uniq .cs-n { color:#e91e8c; }
#bt-chipscan .cs-count.rep .cs-n { color:#d32f2f; }
#bt-chipscan .cs-l { margin-top:6px; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:700; letter-spacing:.11em; text-transform:uppercase; color:#9ca3b8; }

#bt-chipscan .cs-panel { margin-top:18px; padding:17px 18px; background:#fff; border:1px solid #e8eaf0; border-radius:10px; }
#bt-chipscan .cs-panelhead { display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin-bottom:12px; }
#bt-chipscan .cs-panelhead .cs-opt { margin-left:auto; }
#bt-chipscan .cs-opt { display:inline-flex; align-items:center; gap:7px; font-size:14px; color:#5a6380; cursor:pointer; }
#bt-chipscan .cs-opt select { font-family:'Barlow',sans-serif; font-size:14px; color:#1a1f5e; background:#f4f5f9; border:1px solid #e8eaf0; border-radius:6px; padding:5px 8px; }

#bt-chipscan .cs-links { display:flex; flex-direction:column; gap:8px; }
#bt-chipscan .cs-open { display:flex; align-items:center; gap:12px; padding:13px 16px; background:#1a1f5e; color:#fff; border-radius:9px; text-decoration:none; transition:background .15s; }
#bt-chipscan .cs-open:hover { background:#232875; color:#fff; }
#bt-chipscan .cs-open:focus-visible { outline:2px solid #e91e8c; outline-offset:2px; }
#bt-chipscan .cs-openlabel { font-family:'Barlow Condensed',sans-serif; font-size:15px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
#bt-chipscan .cs-opencount { margin-left:auto; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px; opacity:.75; }
#bt-chipscan .cs-none { padding:26px 18px; text-align:center; font-size:15px; color:#9ca3b8; background:#f4f5f9; border:1px dashed #e8eaf0; border-radius:9px; }

#bt-chipscan .cs-row { display:flex; gap:9px; flex-wrap:wrap; margin-top:13px; }
#bt-chipscan .cs-btn { font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; padding:9px 16px; border-radius:7px; cursor:pointer; background:#fff; color:#1a1f5e; border:1px solid #e8eaf0; transition:all .15s; }
#bt-chipscan .cs-btn:hover { border-color:#9ca3b8; background:#f4f5f9; }
#bt-chipscan .cs-btn.danger:hover { border-color:#d32f2f; color:#d32f2f; background:#fff; }
#bt-chipscan .cs-btn:focus-visible { outline:2px solid #e91e8c; outline-offset:2px; }

#bt-chipscan .cs-settings { display:flex; gap:18px; align-items:center; flex-wrap:wrap; margin-top:16px; padding-top:15px; border-top:1px solid #e8eaf0; }
#bt-chipscan .cs-listhead { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin:24px 0 10px; }
#bt-chipscan .cs-listhead input { font-family:'Barlow',sans-serif; font-size:14px; color:#1a1f5e; background:#fff; border:1px solid #e8eaf0; border-radius:7px; padding:8px 12px; min-width:180px; }
#bt-chipscan .cs-listhead input:focus { outline:2px solid #e91e8c; outline-offset:-1px; }
#bt-chipscan .cs-listhead #csManual { margin-left:auto; }

#bt-chipscan .cs-ul { list-style:none; border:1px solid #e8eaf0; border-radius:9px; overflow:hidden; background:#fff; }
#bt-chipscan .cs-ul li { display:flex; align-items:center; gap:14px; padding:10px 15px; border-bottom:1px solid #eef0f5; }
#bt-chipscan .cs-ul li:last-child { border-bottom:none; }
#bt-chipscan .cs-ul li.dup { background:linear-gradient(90deg,rgba(211,47,47,.07),transparent 42%); }
#bt-chipscan .cs-idx { min-width:34px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; font-variant-numeric:tabular-nums; color:#9ca3b8; }
#bt-chipscan .cs-code { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:19px; font-weight:600; font-variant-numeric:tabular-nums; color:#0f1240; }
#bt-chipscan .cs-tag { font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#d32f2f; border:1px solid rgba(211,47,47,.4); padding:2px 7px; border-radius:4px; }
#bt-chipscan .cs-time { margin-left:auto; font-size:13px; color:#9ca3b8; font-variant-numeric:tabular-nums; }
#bt-chipscan .cs-del { background:none; border:none; cursor:pointer; font-size:18px; line-height:1; padding:3px 8px; border-radius:5px; color:#9ca3b8; }
#bt-chipscan .cs-del:hover { color:#d32f2f; background:rgba(211,47,47,.09); }
#bt-chipscan .cs-blank { padding:32px 20px; text-align:center; font-size:15px; color:#9ca3b8; background:#fff; border:1px dashed #e8eaf0; border-radius:9px; }
#bt-chipscan .cs-hint { margin-top:14px; font-size:13.5px; line-height:1.6; color:#9ca3b8; }
#bt-chipscan .cs-toast { position:fixed; left:50%; bottom:26px; transform:translate(-50%,80px); z-index:99999; background:#1a1f5e; color:#fff; font-weight:600; font-size:14px; padding:11px 20px; border-radius:999px; opacity:0; pointer-events:none; transition:transform .22s cubic-bezier(.2,.9,.3,1),opacity .22s; }
#bt-chipscan .cs-toast.show { transform:translate(-50%,0); opacity:1; }
@media (prefers-reduced-motion: reduce) { #bt-chipscan * { animation:none !important; transition:none !important; } }
</style>

<script>
(function () {
  "use strict";

  var KEY  = 'btChipScans';
  var PANE = 'bt-tab-chipscan';
  var BASE = 'https://manage.chipply.com/ng/ecom-orders.html?orderids=';
  var MAX_URL = 1900;   // keep the whole link under the ~2000 char danger zone

  var scans = [];
  var buf = '', idleTimer = null, lastKey = 0, gaps = [];
  var STALE_MS = 2500;

  function $(id) { return document.getElementById(id); }
  function paneActive() { var p = $(PANE); return !!p && p.classList.contains('active'); }

  function save() { try { localStorage.setItem(KEY, JSON.stringify(scans)); } catch (e) {} }
  function load() {
    try { var r = localStorage.getItem(KEY); if (r) scans = JSON.parse(r) || []; }
    catch (e) { scans = []; }
  }

  var actx = null;
  function beep(f, ms) {
    if (!$('csSound').checked) return;
    try {
      if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      var o = actx.createOscillator(), g = actx.createGain();
      o.type = 'square'; o.frequency.value = f;
      g.gain.setValueAtTime(0.05, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + ms / 1000);
      o.connect(g); g.connect(actx.destination); o.start(); o.stop(actx.currentTime + ms / 1000);
    } catch (e) {}
  }
  function toast(m) {
    var t = $('csToast'); t.textContent = m; t.classList.add('show');
    clearTimeout(t._t); t._t = setTimeout(function () { t.classList.remove('show'); }, 1700);
  }
  function flash(dup) {
    var r = $('csReadout');
    r.classList.remove('flash', 'flashdup'); void r.offsetWidth;
    r.classList.add(dup ? 'flashdup' : 'flash');
    setTimeout(function () { r.classList.remove('flash', 'flashdup'); }, 500);
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function showLast(code, dup) {
    var b = $('csLast');
    if (code === null) {
      b.textContent = 'Scan a Chipply slip to begin';
      b.classList.add('cs-empty'); $('csDupFlag').classList.remove('on'); return;
    }
    b.textContent = code; b.classList.remove('cs-empty');
    $('csDupFlag').classList.toggle('on', !!dup);
  }
  function syncLast() {
    if (!scans.length) { showLast(null); return; }
    var l = scans[scans.length - 1]; showLast(l.code, l.dup);
  }

  function commit(raw) {
    var code = String(raw).replace(/[\u0000-\u001F\u007F]/g, '').trim();
    if (code.length < 2) return;

    var dup = scans.some(function (s) { return s.code === code; });
    showLast(code, dup);

    if (dup && $('csSkipDupes').checked) {
      flash(true); beep(320, 160); toast('Skipped \u2014 already scanned'); return;
    }
    scans.push({ code: code, t: Date.now(), dup: dup });
    flash(dup); beep(dup ? 320 : 880, dup ? 160 : 70);
    render(); save();
  }

  /* Gun and hand are told apart by the gaps between keystrokes: a burst commits
     itself, anything at human speed waits for Enter. */
  function median(a) {
    if (!a.length) return 0;
    var v = a.slice().sort(function (x, y) { return x - y; }), m = Math.floor(v.length / 2);
    return v.length % 2 ? v[m] : (v[m - 1] + v[m]) / 2;
  }
  function idleGap() { return parseInt($('csGap').value, 10); }
  function looksLikeGun() { return gaps.length >= 1 && median(gaps) <= Math.round(idleGap() * 0.6); }
  function showTyping() {
    var e = $('csTyping');
    if (!buf) { e.classList.remove('on'); e.innerHTML = ''; return; }
    e.classList.add('on'); e.innerHTML = 'Typing <b>' + esc(buf) + '</b><i>Press Enter</i>';
  }
  function clearBuf() { buf = ''; gaps = []; showTyping(); }
  function flush() { if (!buf) return; var b = buf; clearBuf(); commit(b); }
  function onIdle() { if (!buf) return; if (looksLikeGun()) flush(); else showTyping(); }

  document.addEventListener('keydown', function (e) {
    if (!paneActive() || $('csPause').checked) return;
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    if (e.key === 'Enter' || e.key === 'Tab') {
      if (buf) { e.preventDefault(); clearTimeout(idleTimer); flush(); }
      return;
    }
    if (e.key === 'Escape') { clearTimeout(idleTimer); clearBuf(); return; }
    if (e.key === 'Backspace') {
      if (buf) { e.preventDefault(); buf = buf.slice(0, -1); gaps.pop(); showTyping(); }
      return;
    }
    if (e.key.length !== 1) return;

    var now = Date.now();
    if (buf && now - lastKey > STALE_MS) clearBuf();
    if (buf) gaps.push(now - lastKey);
    lastKey = now; buf += e.key; showTyping();
    clearTimeout(idleTimer); idleTimer = setTimeout(onIdle, idleGap());
  });

  document.addEventListener('paste', function (e) {
    if (!paneActive() || $('csPause').checked) return;
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
    var cd = e.clipboardData || window.clipboardData; if (!cd) return;
    var parts = (cd.getData('text') || '').split(/[\s,;]+/).filter(function (x) { return x.length > 1; });
    if (!parts.length) return;
    e.preventDefault(); clearTimeout(idleTimer); clearBuf();
    parts.forEach(commit);
    toast('Added ' + parts.length + ' from paste');
  });

  $('csManual').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { var v = this.value.trim(); if (v) { commit(v); this.value = ''; } }
  });

  /* ---------- the links ---------- */
  function uniqueCodes() {
    var seen = {}, out = [];
    scans.forEach(function (s) { if (!seen[s.code]) { seen[s.code] = true; out.push(s.code); } });
    return out;
  }

  /* Split by the batch size, then again by URL length if a batch would still be
     too long. A truncated link silently drops the orders on the end, so the
     character ceiling wins over the chosen batch size. */
  function batches() {
    var codes = uniqueCodes();
    var per = parseInt($('csBatch').value, 10);
    var out = [], cur = [];
    for (var i = 0; i < codes.length; i++) {
      var next = cur.concat([codes[i]]);
      if (cur.length && (next.length > per || (BASE + next.join(',')).length > MAX_URL)) {
        out.push(cur); cur = [codes[i]];
      } else cur = next;
    }
    if (cur.length) out.push(cur);
    return out;
  }
  function urlFor(list) { return BASE + list.join(','); }

  function renderLinks() {
    var b = batches(), host = $('csLinks');
    if (!b.length) {
      host.innerHTML = '<div class="cs-none">Scan some orders and a link to Order Manager appears here.</div>';
      return;
    }
    var h = '';
    for (var i = 0; i < b.length; i++) {
      var label = b.length === 1 ? 'Open ' + b[i].length + ' order' + (b[i].length === 1 ? '' : 's') + ' in Chipply'
                                 : 'Open batch ' + (i + 1) + ' of ' + b.length;
      h += '<a class="cs-open" href="' + esc(urlFor(b[i])) + '" target="_blank" rel="noopener">' +
             '<span class="cs-openlabel">' + label + '</span>' +
             '<span class="cs-opencount">' + b[i].length + ' orders</span>' +
           '</a>';
    }
    host.innerHTML = h;
  }

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function hhmmss(ts) { var d = new Date(ts); return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
  function recount() { var seen = {}; scans.forEach(function (s) { s.dup = !!seen[s.code]; seen[s.code] = true; }); }

  function render() {
    var seen = {}, reps = 0;
    scans.forEach(function (s) { if (seen[s.code]) reps++; else seen[s.code] = true; });
    $('csTotal').textContent = scans.length;
    $('csUnique').textContent = Object.keys(seen).length;
    $('csRepeats').textContent = reps;

    var q = $('csFind').value.trim().toLowerCase();
    var rows = scans.map(function (s, i) { return { s: s, i: i }; })
                    .filter(function (r) { return !q || r.s.code.toLowerCase().indexOf(q) !== -1; })
                    .reverse();

    if (!scans.length) {
      $('csList').innerHTML = '<div class="cs-blank">Nothing scanned yet. Point the gun at a Chipply slip \u2014 it lands here on its own.</div>';
    } else if (!rows.length) {
      $('csList').innerHTML = '<div class="cs-blank">No scan matches \u201C' + esc($('csFind').value) + '\u201D.</div>';
    } else {
      var h = '<ul class="cs-ul">';
      rows.forEach(function (r) {
        h += '<li class="' + (r.s.dup ? 'dup' : '') + '">' +
               '<span class="cs-idx">' + (r.i + 1) + '</span>' +
               '<span class="cs-code">' + esc(r.s.code) + '</span>' +
               (r.s.dup ? '<span class="cs-tag">Repeat</span>' : '') +
               '<span class="cs-time">' + hhmmss(r.s.t) + '</span>' +
               '<button type="button" class="cs-del" data-i="' + r.i + '" aria-label="Remove ' + esc(r.s.code) + '">&times;</button>' +
             '</li>';
      });
      $('csList').innerHTML = h + '</ul>';
      Array.prototype.forEach.call($('csList').querySelectorAll('.cs-del'), function (b) {
        b.addEventListener('click', function () {
          scans.splice(parseInt(b.getAttribute('data-i'), 10), 1);
          recount(); render(); syncLast(); save();
        });
      });
    }
    renderLinks();
  }

  function copy(txt, label) {
    if (!txt) { toast('Nothing to copy yet'); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(function () { toast(label); }, function () { fb(txt, label); });
    } else fb(txt, label);
  }
  function fb(txt, label) {
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); toast(label); } catch (e) { toast('Copy blocked'); }
    document.body.removeChild(ta);
  }

  $('csCopyUrl').addEventListener('click', function () {
    var b = batches();
    if (!b.length) { toast('Nothing to copy yet'); return; }
    copy(b.map(urlFor).join('\n'), b.length === 1 ? 'Link copied' : b.length + ' batch links copied');
  });
  $('csCopyNums').addEventListener('click', function () {
    copy(uniqueCodes().join(','), 'Copied ' + uniqueCodes().length + ' numbers');
  });
  $('csClear').addEventListener('click', function () {
    if (!scans.length) { toast('List is already empty'); return; }
    if (confirm('Clear all ' + scans.length + ' scans? This cannot be undone.')) {
      scans = []; render(); syncLast(); save(); toast('List cleared');
    }
  });

  $('csBatch').addEventListener('change', renderLinks);
  $('csFind').addEventListener('input', render);

  function status() {
    var s = $('csStatus');
    if ($('csPause').checked) { s.className = 'cs-status paused'; $('csStatusText').textContent = 'Paused'; }
    else { s.className = 'cs-status live'; $('csStatusText').textContent = 'Listening'; }
  }
  $('csPause').addEventListener('change', status);

  load(); render(); syncLast(); status();
})();
</script>
    <?php
    return ob_get_clean();
}
