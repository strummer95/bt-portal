<?php
/**
 * BT Portal — Chipply Barcoder (Other > Chipply Barcoder).
 *
 * Drop a Chipply order export in, get the same PDF back with a Code 128 of the
 * order number stamped into each page's footer, ready to print and scan.
 *
 * ── Why this runs in the browser ───────────────────────────────────────────
 *
 * Everything happens client side: pdf.js reads the text layer, pdf-lib writes
 * the bars back into the same file. Nothing is uploaded and nothing is stored.
 * A day's export is 4MB and 184 pages, so pushing it through PHP would mean an
 * upload, a temp file, a PDF library on the server and a cleanup job — for a
 * result the machine that already has the file can produce in about three
 * seconds. The libraries are vendored in assets/ rather than pulled from a CDN
 * so the tab keeps working if the shop's connection doesn't.
 *
 * ── Where the barcode goes ────────────────────────────────────────────────
 *
 * Not at a fixed margin. Each page's footer line ("Page 1 of 2 … Order #NNN")
 * is found in the text layer and the barcode is placed in that same strip,
 * between the horizontal rule and the descender of the footer text. So it adds
 * no height to the page and nothing on the sheet moves. If a page's footer
 * can't be found it is passed through untouched and reported, rather than
 * being stamped somewhere wrong.
 *
 * Code 128 subset B: a few modules wider than subset C for digits, but it
 * accepts anything Chipply might put in an order number, and the extra width
 * is free — there is ~390pt of empty footer to spend, and wider modules
 * survive a laser printer better than narrow ones.
 */
if (!defined('ABSPATH')) exit;

add_shortcode('bt_chipply_barcoder', 'btp_chipply_barcoder_shortcode');

function btp_chipply_barcoder_shortcode() {
    $base = BTP_URL . 'assets/barcoder/';
    $v    = BTP_VERSION;
    ob_start();
    ?>
<div id="bt-barcoder">

  <div class="bc-head">
    <div class="bc-title">CHIPPLY <span>Barcoder</span></div>
    <div class="bc-badge" id="bcBadge">Ready</div>
  </div>

  <div class="bc-drop" id="bcDrop">
    <input type="file" id="bcFile" accept="application/pdf,.pdf" hidden>
    <div class="bc-dropinner">
      <div class="bc-dropicon">
        <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>
        </svg>
      </div>
      <div class="bc-dropbig">Drop a Chipply order PDF here</div>
      <div class="bc-dropsub">or <button type="button" class="bc-link" id="bcBrowse">choose a file</button> &mdash; it never leaves this computer</div>
    </div>
  </div>

  <div class="bc-progress" id="bcProgress">
    <div class="bc-bar"><div class="bc-fill" id="bcFill"></div></div>
    <div class="bc-ptext" id="bcPText">Reading&hellip;</div>
  </div>

  <div class="bc-result" id="bcResult">
    <div class="bc-stats">
      <div class="bc-stat"><div class="bc-n" id="bcPages">0</div><div class="bc-l">Pages</div></div>
      <div class="bc-stat ok"><div class="bc-n" id="bcStamped">0</div><div class="bc-l">Barcoded</div></div>
      <div class="bc-stat warn"><div class="bc-n" id="bcSkipped">0</div><div class="bc-l">Skipped</div></div>
      <div class="bc-stat"><div class="bc-n" id="bcOrders">0</div><div class="bc-l">Orders</div></div>
    </div>
    <div class="bc-skiplist" id="bcSkipList"></div>
    <div class="bc-row">
      <button type="button" class="bc-btn primary" id="bcPrint">Print</button>
      <button type="button" class="bc-btn" id="bcSave">Save PDF</button>
      <button type="button" class="bc-btn" id="bcAgain">Do another</button>
    </div>
    <div class="bc-fname" id="bcFname"></div>
  </div>

  <div class="bc-opts">
    <label class="bc-opt">Barcode width
      <select id="bcWidth">
        <option value="130">Narrow</option>
        <option value="160" selected>Standard</option>
        <option value="200">Wide &mdash; if scans misread</option>
      </select>
    </label>
    <label class="bc-opt"><input type="checkbox" id="bcPage1"> First page of each order only</label>
  </div>

  <p class="bc-hint">
    The barcode sits in the footer strip beside the page number, so nothing on the sheet moves and no
    page grows. Pages whose footer can't be read are passed through untouched and listed above rather
    than stamped somewhere wrong. Print one and scan it before running a full day's stack.
  </p>

  <div class="bc-toast" id="bcToast"></div>
</div>

<style>
#bt-barcoder { padding:22px 24px 60px; max-width:1080px; margin:0 auto; font-family:'Barlow',sans-serif; color:#1a1f5e; }
#bt-barcoder .bc-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding-bottom:14px; border-bottom:2px solid #e8eaf0; }
#bt-barcoder .bc-title { font-family:'Oswald',sans-serif; font-size:22px; font-weight:600; letter-spacing:.02em; text-transform:uppercase; }
#bt-barcoder .bc-title span { color:#e91e8c; }
#bt-barcoder .bc-badge { margin-left:auto; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; padding:5px 13px; border-radius:999px; border:1px solid #e8eaf0; background:#fff; color:#5a6380; }
#bt-barcoder .bc-badge.busy { color:#e91e8c; border-color:rgba(233,30,140,.4); }
#bt-barcoder .bc-badge.done { color:#2E7D32; border-color:rgba(46,125,50,.4); }
#bt-barcoder .bc-badge.err  { color:#d32f2f; border-color:rgba(211,47,47,.4); }

#bt-barcoder .bc-drop { margin-top:20px; padding:44px 24px; border:2px dashed #cfd5e4; border-radius:12px; background:#fff; text-align:center; cursor:pointer; transition:all .15s; }
#bt-barcoder .bc-drop:hover { border-color:#9ca3b8; background:#fbfbfd; }
#bt-barcoder .bc-drop.over { border-color:#e91e8c; background:#fff5fa; }
#bt-barcoder .bc-drop.hide { display:none; }
#bt-barcoder .bc-dropicon { color:#cfd5e4; margin-bottom:10px; }
#bt-barcoder .bc-drop.over .bc-dropicon { color:#e91e8c; }
#bt-barcoder .bc-dropbig { font-family:'Oswald',sans-serif; font-size:19px; font-weight:500; text-transform:uppercase; letter-spacing:.02em; }
#bt-barcoder .bc-dropsub { margin-top:7px; font-size:14px; color:#9ca3b8; }
#bt-barcoder .bc-link { background:none; border:none; padding:0; font:inherit; color:#e91e8c; text-decoration:underline; cursor:pointer; }

#bt-barcoder .bc-progress { display:none; margin-top:20px; padding:20px 22px; background:#fff; border:1px solid #e8eaf0; border-radius:11px; }
#bt-barcoder .bc-progress.on { display:block; }
#bt-barcoder .bc-bar { height:8px; background:#eef0f5; border-radius:999px; overflow:hidden; }
#bt-barcoder .bc-fill { height:100%; width:0%; background:#e91e8c; border-radius:999px; transition:width .12s linear; }
#bt-barcoder .bc-ptext { margin-top:10px; font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#5a6380; }

#bt-barcoder .bc-result { display:none; margin-top:20px; }
#bt-barcoder .bc-result.on { display:block; }
#bt-barcoder .bc-stats { display:flex; gap:10px; flex-wrap:wrap; }
#bt-barcoder .bc-stat { flex:1 1 130px; padding:14px 16px; background:#fff; border:1px solid #e8eaf0; border-radius:9px; }
#bt-barcoder .bc-n { font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace; font-variant-numeric:tabular-nums; font-size:28px; font-weight:700; line-height:1; }
#bt-barcoder .bc-stat.ok .bc-n { color:#2E7D32; }
#bt-barcoder .bc-stat.warn .bc-n { color:#d32f2f; }
#bt-barcoder .bc-l { margin-top:6px; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:700; letter-spacing:.11em; text-transform:uppercase; color:#9ca3b8; }
#bt-barcoder .bc-skiplist { display:none; margin-top:12px; padding:12px 15px; background:#fff5f5; border:1px solid rgba(211,47,47,.3); border-radius:9px; font-size:14px; color:#8a2020; }
#bt-barcoder .bc-skiplist.on { display:block; }

#bt-barcoder .bc-row { display:flex; gap:9px; flex-wrap:wrap; margin-top:16px; }
#bt-barcoder .bc-btn { font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; padding:10px 18px; border-radius:7px; cursor:pointer; background:#fff; color:#1a1f5e; border:1px solid #e8eaf0; transition:all .15s; }
#bt-barcoder .bc-btn:hover { border-color:#9ca3b8; background:#f4f5f9; }
#bt-barcoder .bc-btn.primary { background:#1a1f5e; color:#fff; border-color:#1a1f5e; }
#bt-barcoder .bc-btn.primary:hover { background:#232875; }
#bt-barcoder .bc-btn:focus-visible { outline:2px solid #e91e8c; outline-offset:2px; }
#bt-barcoder .bc-fname { margin-top:10px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:13px; color:#9ca3b8; word-break:break-all; }

#bt-barcoder .bc-opts { display:flex; gap:18px; align-items:center; flex-wrap:wrap; margin-top:18px; padding-top:16px; border-top:1px solid #e8eaf0; }
#bt-barcoder .bc-opt { display:inline-flex; align-items:center; gap:7px; font-size:14px; color:#5a6380; cursor:pointer; }
#bt-barcoder .bc-opt select { font-family:'Barlow',sans-serif; font-size:14px; color:#1a1f5e; background:#f4f5f9; border:1px solid #e8eaf0; border-radius:6px; padding:5px 8px; }
#bt-barcoder .bc-hint { margin-top:14px; font-size:13.5px; line-height:1.6; color:#9ca3b8; }

#bt-barcoder .bc-toast { position:fixed; left:50%; bottom:26px; transform:translate(-50%,80px); z-index:99999; background:#1a1f5e; color:#fff; font-weight:600; font-size:14px; padding:11px 20px; border-radius:999px; opacity:0; pointer-events:none; transition:transform .22s cubic-bezier(.2,.9,.3,1),opacity .22s; }
#bt-barcoder .bc-toast.show { transform:translate(-50%,0); opacity:1; }
@media (prefers-reduced-motion: reduce) { #bt-barcoder * { transition:none !important; } }
</style>

<script src="<?php echo esc_url($base . 'pdf.min.js?v=' . $v); ?>"></script>
<script src="<?php echo esc_url($base . 'pdf-lib.min.js?v=' . $v); ?>"></script>
<script>
(function () {
  "use strict";

  var WORKER = <?php echo wp_json_encode($base . 'pdf.worker.min.js?v=' . $v); ?>;
  var PANE   = 'bt-tab-barcoder';

  /* Code 128 subset B. Index is the symbol value; each string is the run of
     bar/space widths in modules, starting with a bar. */
  var PATTERNS = ["212222","222122","222221","121223","121322","131222","122213","122312","132212","221213",
  "221312","231212","112232","122132","122231","113222","123122","123221","223211","221132",
  "221231","213212","223112","312131","311222","321122","321221","312212","322112","322211",
  "212123","212321","232121","111323","131123","131321","112313","132113","132311","211313",
  "231113","231311","112133","112331","132131","113123","113321","133121","313121","211331",
  "231131","213113","213311","213131","311123","311321","331121","312113","312311","332111",
  "314111","221411","431111","111224","111422","121124","121421","141122","141221","112214",
  "112412","122114","122411","142112","142211","241211","221114","413111","241112","134111",
  "111242","121142","121241","114212","124112","124211","411212","421112","421211","212141",
  "214121","412121","111143","111341","131141","114113","114311","411113","411311","113141",
  "114131","311141","411131","211412","211214","211232","2331112"];
  var START_B = 104, STOP = 106;

  function encode(text) {
    var vals = [START_B], i;
    for (i = 0; i < text.length; i++) vals.push(text.charCodeAt(i) - 32);
    var sum = START_B;
    for (i = 1; i < vals.length; i++) sum += vals[i] * i;
    vals.push(sum % 103);
    vals.push(STOP);
    var bars = [], modules = 0;
    for (i = 0; i < vals.length; i++) {
      var pat = PATTERNS[vals[i]];
      for (var j = 0; j < pat.length; j++) {
        var w = parseInt(pat[j], 10);
        bars.push({ w: w, bar: j % 2 === 0 });
        modules += w;
      }
    }
    return { bars: bars, modules: modules };
  }

  function layout(text, targetWidth, centerX, bottomY, height) {
    var e = encode(text), QUIET = 10;
    var mw = targetWidth / (e.modules + QUIET * 2);
    var x = centerX - targetWidth / 2 + QUIET * mw;
    var rects = [];
    for (var i = 0; i < e.bars.length; i++) {
      var w = e.bars[i].w * mw;
      if (e.bars[i].bar) rects.push({ x: x, y: bottomY, w: w, h: height });
      x += w;
    }
    return rects;
  }

  var ORDER_RE = /Order\s*#\s*(\d+)/;
  var FIELD_RE = /Order\s*Number:?\s*(\d+)/i;
  var PAGE1_RE = /Page\s*1\s*of/i;

  /* The band is taken from the footer line's own baseline, not a fixed margin,
     so the bars land in the strip the footer text already occupies. */
  function readPage(items) {
    var full = items.map(function (i) { return i.str; }).join(' ');
    var num = null, m;
    m = ORDER_RE.exec(full); if (m) num = m[1];
    if (!num) { m = FIELD_RE.exec(full); if (m) num = m[1]; }

    var anchor = null;
    for (var k = 0; k < items.length; k++) {
      var it = items[k];
      if (!it.str || !it.str.trim()) continue;
      var y = it.transform[5];
      if (y > 120) continue;
      if (/Order\s*#\s*\d+/.test(it.str) || /^Page\s+\d+\s+of/i.test(it.str)) {
        if (!anchor || y < anchor.transform[5]) anchor = it;
      }
    }
    if (!num && anchor) { m = /#\s*(\d+)/.exec(anchor.str); if (m) num = m[1]; }

    var band = null;
    if (anchor) {
      var base = anchor.transform[5], h = anchor.height || 12;
      band = { bottom: base - 2.5, top: base + h - 1.0 };
    }
    return { number: num, band: band, isPage1: PAGE1_RE.test(full) };
  }

  /* ---------- DOM ---------- */
  function $(id) { return document.getElementById(id); }
  var drop = $('bcDrop'), fileIn = $('bcFile');
  var outBytes = null, outName = '', outUrl = null;

  function paneActive() { var p = $(PANE); return !!p && p.classList.contains('active'); }
  function toast(msg) {
    var t = $('bcToast'); t.textContent = msg; t.classList.add('show');
    clearTimeout(t._t); t._t = setTimeout(function () { t.classList.remove('show'); }, 1800);
  }
  function badge(text, cls) { var b = $('bcBadge'); b.textContent = text; b.className = 'bc-badge' + (cls ? ' ' + cls : ''); }
  function progress(pct, text) {
    $('bcProgress').classList.add('on');
    $('bcFill').style.width = Math.round(pct) + '%';
    if (text) $('bcPText').textContent = text;
  }

  drop.addEventListener('click', function () { fileIn.click(); });
  $('bcBrowse').addEventListener('click', function (e) { e.stopPropagation(); fileIn.click(); });
  fileIn.addEventListener('change', function () { if (this.files && this.files[0]) handle(this.files[0]); });

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) {
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) handle(f);
  });
  /* A file dropped anywhere else on the tab would otherwise navigate the
     browser away from the portal to that file. */
  window.addEventListener('dragover', function (e) { if (paneActive()) e.preventDefault(); });
  window.addEventListener('drop', function (e) { if (paneActive() && !drop.contains(e.target)) e.preventDefault(); });

  async function handle(file) {
    if (!file) return;
    if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') {
      badge('Not a PDF', 'err'); toast('That file isn\u2019t a PDF'); return;
    }
    $('bcResult').classList.remove('on');
    $('bcSkipList').classList.remove('on');
    badge('Working', 'busy');
    progress(2, 'Reading the file\u2026');

    try {
      var buf = await file.arrayBuffer();
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER;

      var doc = await window.pdfjsLib.getDocument({ data: new Uint8Array(buf.slice(0)) }).promise;
      var out = await window.PDFLib.PDFDocument.load(buf.slice(0));
      var pages = out.getPages();
      var black = window.PDFLib.rgb(0, 0, 0);

      var targetW  = parseInt($('bcWidth').value, 10);
      var only1    = $('bcPage1').checked;
      var stamped = 0, skipped = [], orders = {};

      for (var i = 1; i <= doc.numPages; i++) {
        var pg = await doc.getPage(i);
        var tc = await pg.getTextContent();
        var info = readPage(tc.items);

        if (info.number) orders[info.number] = true;

        if (!info.number || !info.band) { skipped.push(i); }
        else if (only1 && !info.isPage1) { /* deliberately left alone */ }
        else {
          var p = pages[i - 1], sz = p.getSize();
          var rects = layout(info.number, targetW, sz.width / 2,
                             info.band.bottom, info.band.top - info.band.bottom);
          for (var r = 0; r < rects.length; r++) {
            p.drawRectangle({ x: rects[r].x, y: rects[r].y, width: rects[r].w, height: rects[r].h, color: black });
          }
          stamped++;
        }
        if (i % 3 === 0 || i === doc.numPages) {
          progress(5 + (i / doc.numPages) * 85, 'Barcoding page ' + i + ' of ' + doc.numPages);
        }
      }

      progress(95, 'Building the file\u2026');
      outBytes = await out.save();
      outName  = file.name.replace(/\.pdf$/i, '') + '-barcoded.pdf';
      if (outUrl) URL.revokeObjectURL(outUrl);
      outUrl = URL.createObjectURL(new Blob([outBytes], { type: 'application/pdf' }));

      $('bcPages').textContent   = doc.numPages;
      $('bcStamped').textContent = stamped;
      $('bcSkipped').textContent = skipped.length;
      $('bcOrders').textContent  = Object.keys(orders).length;
      $('bcFname').textContent   = outName;

      if (skipped.length) {
        var show = skipped.slice(0, 25).join(', ') + (skipped.length > 25 ? '\u2026' : '');
        $('bcSkipList').textContent = 'No footer found on ' + skipped.length +
          ' page' + (skipped.length === 1 ? '' : 's') + ' \u2014 passed through unstamped: ' + show;
        $('bcSkipList').classList.add('on');
      }

      progress(100, 'Done');
      setTimeout(function () { $('bcProgress').classList.remove('on'); }, 500);
      $('bcResult').classList.add('on');
      drop.classList.add('hide');
      badge(skipped.length ? 'Done, with skips' : 'Done', skipped.length ? 'err' : 'done');
      toast('Barcoded ' + stamped + ' page' + (stamped === 1 ? '' : 's'));

    } catch (err) {
      $('bcProgress').classList.remove('on');
      badge('Failed', 'err');
      toast('Couldn\u2019t read that PDF');
      if (window.console) console.error('[Chipply Barcoder]', err);
    }
  }

  $('bcPrint').addEventListener('click', function () {
    if (!outUrl) return;
    var w = window.open(outUrl, '_blank');
    if (!w) { toast('Pop-up blocked \u2014 use Save PDF instead'); return; }
    toast('Opened in a new tab \u2014 print from there');
  });

  $('bcSave').addEventListener('click', function () {
    if (!outBytes) return;
    var a = document.createElement('a');
    a.href = outUrl; a.download = outName;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    toast('Saved ' + outName);
  });

  $('bcAgain').addEventListener('click', function () {
    outBytes = null;
    if (outUrl) { URL.revokeObjectURL(outUrl); outUrl = null; }
    fileIn.value = '';
    $('bcResult').classList.remove('on');
    $('bcSkipList').classList.remove('on');
    drop.classList.remove('hide');
    badge('Ready', '');
  });
})();
</script>
    <?php
    return ob_get_clean();
}
