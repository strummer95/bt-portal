<?php
/**
 * BT Portal — Halftoner (Other > Halftoner).
 *
 * Turns the see-through parts of a DTF design into printed dots, the way a
 * screen print does it.
 *
 * Why: DTF lays white underbase under every inked pixel, so a pixel at 50%
 * opacity does not blend into the shirt, it prints as a paler colour on top
 * of white (50% red on a black shirt comes out pink). The usual "halftone"
 * tools fix that backwards: they keep the design solid and punch holes in it.
 * This does the opposite. Solid stays solid; anything partly transparent
 * becomes round dots of full-strength ink whose AREA matches the original
 * opacity (40% opaque -> dots covering 40% of the cell), shrinking and
 * spreading out until the fade is just shirt.
 *
 * Output guarantee: every pixel is alpha 0 or alpha 255. Nothing partial. The
 * dot keeps the pixel's own colour un-premultiplied, so a faded red becomes
 * dots of real red, not pink.
 *
 * Everything runs in the browser (a Web Worker, so a 16" design does not
 * freeze the tab). No upload, no REST route, no table, nothing stored.
 *
 * Coverage: a round dot centred in a square cell covers pi*r^2 until r = 0.5,
 * then gets clipped by its neighbours, and only reaches 100% at r = sqrt(0.5).
 * radiusFor() inverts that numerically so the ink area is linear in opacity.
 */
if (!defined('ABSPATH')) exit;

add_shortcode('bt_halftoner', 'btp_halftoner_shortcode');

function btp_halftoner_shortcode() {
    if ( function_exists('btp_user_can_access') && ! btp_user_can_access() ) return '';
    ob_start();
    ?>
<div id="btp-halftoner">
<style>
#btp-halftoner{--ht-navy:#27267e;--ht-pink:#e535ab;--ht-line:#d9dce6;--ht-mute:#5a6380;font-family:Barlow,system-ui,sans-serif;font-size:15px;color:#1d2033;display:flex;gap:12px;padding:12px;box-sizing:border-box;align-items:flex-start}
#btp-halftoner *{box-sizing:border-box}
#btp-halftoner .ht-side{width:280px;flex:none;background:#fff;border:1px solid var(--ht-line);border-radius:8px;padding:12px}
#btp-halftoner .ht-side h2{font-family:Oswald,sans-serif;font-size:20px;color:var(--ht-navy);margin:0 0 2px;text-transform:uppercase;letter-spacing:.5px}
#btp-halftoner .ht-sub{color:var(--ht-mute);font-size:13px;margin:0 0 6px;line-height:1.35}
#btp-halftoner label{display:block;margin:10px 0 2px;font-family:Oswald,sans-serif;font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:var(--ht-navy)}
#btp-halftoner .ht-row{display:flex;gap:8px;align-items:center}
#btp-halftoner .ht-row input[type=range]{flex:1;accent-color:var(--ht-pink)}
#btp-halftoner .ht-val{width:48px;text-align:right;font-variant-numeric:tabular-nums;font-size:14px}
#btp-halftoner .ht-hint{font-size:13px;color:var(--ht-mute);line-height:1.3}
#btp-halftoner input[type=number],#btp-halftoner select{width:100%;padding:5px 8px;border:1px solid var(--ht-line);border-radius:6px;font-size:14px;background:#fff}
#btp-halftoner .ht-btns{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
#btp-halftoner button{font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:.4px;font-size:14px;border-radius:6px;padding:7px 12px;cursor:pointer;border:1px solid var(--ht-navy);background:#fff;color:var(--ht-navy)}
#btp-halftoner button.ht-pri{background:var(--ht-pink);border-color:var(--ht-pink);color:#fff}
#btp-halftoner button:disabled{opacity:.4;cursor:default}
#btp-halftoner .ht-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:8px}
#btp-halftoner .ht-drop{border:2px dashed var(--ht-line);border-radius:8px;background:#fff;padding:14px;text-align:center;color:var(--ht-mute);cursor:pointer;font-size:15px}
#btp-halftoner .ht-drop.over{border-color:var(--ht-pink);color:var(--ht-navy)}
#btp-halftoner .ht-files{display:flex;flex-wrap:wrap;gap:6px}
#btp-halftoner .ht-chip{border:1px solid var(--ht-line);background:#fff;border-radius:6px;padding:3px 8px;font-size:13px;cursor:pointer;display:flex;gap:6px;align-items:center}
#btp-halftoner .ht-chip.on{border-color:var(--ht-navy);background:#e9e9f6}
#btp-halftoner .ht-chip b{font-weight:400;color:var(--ht-mute)}
#btp-halftoner .ht-status{font-size:13px;color:var(--ht-mute);min-height:17px}
#btp-halftoner .ht-panes{display:flex;gap:10px}
#btp-halftoner .ht-pane{flex:1;min-width:0}
#btp-halftoner .ht-pane h3{margin:0 0 4px;font-family:Oswald,sans-serif;font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:var(--ht-navy);font-weight:500}
#btp-halftoner .ht-stage{height:calc(100vh - 290px);min-height:320px;overflow:auto;border:1px solid var(--ht-line);border-radius:8px;background:#fff}
#btp-halftoner .ht-stage canvas{display:block}
@media (max-width:900px){#btp-halftoner{flex-direction:column}#btp-halftoner .ht-side{width:100%}#btp-halftoner .ht-panes{flex-direction:column}#btp-halftoner .ht-stage{height:60vh}}
</style>

<div class="ht-side">
  <h2>Halftoner</h2>
  <p class="ht-sub">Fades become solid ink dots that shrink into the shirt. Solid stays solid. The file comes out 100% or 0% opacity only, so nothing prints pink over the white.</p>

  <label>Lines per inch</label>
  <div class="ht-row"><input id="btp-ht-lpi" type="range" min="15" max="85" step="1" value="45"><span class="ht-val" id="btp-ht-lpiV"></span></div>
  <div class="ht-hint">Lower = bigger dots. 35 to 55 suits DTF.</div>

  <label>Dot angle</label>
  <div class="ht-row"><input id="btp-ht-angle" type="range" min="0" max="90" step="0.5" value="22.5"><span class="ht-val" id="btp-ht-angleV"></span></div>

  <label>Smallest dot kept</label>
  <div class="ht-row"><input id="btp-ht-min" type="range" min="0" max="30" step="1" value="8"><span class="ht-val" id="btp-ht-minV"></span></div>
  <div class="ht-hint">Fainter than this is dropped. Raise it if tiny dots flake off.</div>

  <label>Solid above</label>
  <div class="ht-row"><input id="btp-ht-solid" type="range" min="70" max="100" step="1" value="95"><span class="ht-val" id="btp-ht-solidV"></span></div>
  <div class="ht-hint">At least this opaque prints as solid ink.</div>

  <label>Image DPI</label>
  <input id="btp-ht-dpi" type="number" min="72" max="1200" value="300">
  <div class="ht-hint">Read from the PNG when it says.</div>

  <label>Output resolution</label>
  <select id="btp-ht-scale"><option value="1">Same as image</option><option value="2" selected>2× (rounder dots)</option><option value="4">4× (big files)</option></select>
  <div class="ht-hint">Same print size, more pixels per dot.</div>

  <label>Preview shirt color</label>
  <div class="ht-row"><input id="btp-ht-shirt" type="color" value="#111111" style="width:44px;height:28px;border:0;padding:0;background:none"><span class="ht-hint">Preview only, not in the file.</span></div>

  <label>Zoom</label>
  <select id="btp-ht-zoom"><option value="fit">Fit</option><option value="1">100%</option><option value="2">200%</option><option value="4">400%</option></select>

  <div class="ht-btns">
    <button class="ht-pri" id="btp-ht-dl" disabled>Download PNG</button>
    <button id="btp-ht-dlall" disabled>Download all</button>
    <button id="btp-ht-test">Test pattern</button>
  </div>
</div>

<div class="ht-main">
  <div class="ht-drop" id="btp-ht-drop">Drop transparent PNGs here, or click to choose<input id="btp-ht-pick" type="file" accept="image/png,image/webp,image/*" multiple hidden></div>
  <div class="ht-files" id="btp-ht-files"></div>
  <div class="ht-status" id="btp-ht-status"></div>
  <div class="ht-panes">
    <div class="ht-pane"><h3>Original, as DTF prints it (white underbase)</h3><div class="ht-stage" id="btp-ht-s1"><canvas id="btp-ht-cin"></canvas></div></div>
    <div class="ht-pane"><h3>Halftoned</h3><div class="ht-stage" id="btp-ht-s2"><canvas id="btp-ht-cout"></canvas></div></div>
  </div>
</div>

<script>
(function () {
  var el = function (id) { return document.getElementById('btp-ht-' + id); };
  if (!el('lpi')) return;

  /* ---------- the halftone (runs inside the worker) ---------- */
  function htEngine() {
    var R_MAX = Math.SQRT1_2 + 0.001, LUT = null;
    function buildLut() {
      var N = 400, G = 120, rs = [], cs = [], i, x, y;
      for (i = 0; i <= N; i++) {
        var r = R_MAX * i / N, hit = 0;
        for (y = 0; y < G; y++) for (x = 0; x < G; x++) {
          var dx = (x + .5) / G - .5, dy = (y + .5) / G - .5;
          if (dx * dx + dy * dy <= r * r) hit++;
        }
        rs.push(r); cs.push(hit / (G * G));
      }
      var T = 1024, lut = new Float32Array(T + 1), j = 0;
      for (var k = 0; k <= T; k++) {
        var c = k / T;
        while (j < N && cs[j + 1] < c) j++;
        var j1 = Math.min(j + 1, N), c0 = cs[j], c1 = cs[j1];
        var t = c1 > c0 ? (c - c0) / (c1 - c0) : 0;
        lut[k] = rs[j] + (rs[j1] - rs[j]) * Math.min(1, Math.max(0, t));
      }
      lut[T] = R_MAX;
      return lut;
    }
    function radiusFor(c) { return LUT[Math.round(Math.min(1, Math.max(0, c)) * 1024)]; }

    function halftone(src, w, h, s) {
      if (!LUT) LUT = buildLut();
      var k = s.scale, W = w * k, H = h * k;
      var out = new Uint8ClampedArray(W * H * 4);
      var cell = s.dpi * k / s.lpi;
      var th = s.angle * Math.PI / 180, co = Math.cos(th), si = Math.sin(th);
      function A(x, y) { // bilinear source alpha 0..1 at output coords
        x = x / k - .5; y = y / k - .5;
        if (x < -.5 || y < -.5 || x > w - .5 || y > h - .5) return 0;
        x = Math.min(Math.max(x, 0), w - 1); y = Math.min(Math.max(y, 0), h - 1);
        var x0 = Math.floor(x), y0 = Math.floor(y), x1 = Math.min(x0 + 1, w - 1), y1 = Math.min(y0 + 1, h - 1);
        var fx = x - x0, fy = y - y0;
        var a = src[(y0 * w + x0) * 4 + 3], b = src[(y0 * w + x1) * 4 + 3], c = src[(y1 * w + x0) * 4 + 3], d = src[(y1 * w + x1) * 4 + 3];
        return ((a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy) / 255;
      }
      var cache = new Map();
      function cellInfo(i, j) {
        var key = i * 100003 + j, v = cache.get(key);
        if (v) return v;
        var u = (i + .5) * cell, vv = (j + .5) * cell;
        var cx = u * co - vv * si, cy = u * si + vv * co, q = cell * .25;
        // average over the cell so dots follow the fade, not single noisy pixels
        var a = (A(cx, cy) * 2 + A(cx - q, cy - q) + A(cx + q, cy - q) + A(cx - q, cy + q) + A(cx + q, cy + q)) / 6;
        v = [cx, cy, a < s.minDot ? -1 : radiusFor(a) * cell];
        cache.set(key, v);
        return v;
      }
      for (var y = 0; y < H; y++) {
        if (cache.size > 200000) cache.clear();
        if (y % 256 === 0) postMessage({ progress: y / H });
        var sy = Math.min(h - 1, (y / k) | 0);
        for (var x = 0; x < W; x++) {
          var sx = Math.min(w - 1, (x / k) | 0), sp = (sy * w + sx) * 4;
          var a = k === 1 ? src[sp + 3] / 255 : A(x + .5, y + .5);
          if (a <= 0) continue;
          var ink = a >= s.solid;
          if (!ink) {
            var u = (x + .5) * co + (y + .5) * si, v = -(x + .5) * si + (y + .5) * co;
            var ci = Math.floor(u / cell), cj = Math.floor(v / cell);
            for (var dj = -1; dj <= 1 && !ink; dj++) for (var di = -1; di <= 1 && !ink; di++) {
              var c = cellInfo(ci + di, cj + dj);
              if (c[2] <= 0) continue;
              var dx = x + .5 - c[0], dy = y + .5 - c[1];
              if (dx * dx + dy * dy <= c[2] * c[2]) ink = true;
            }
          }
          if (ink) { var p = (y * W + x) * 4; out[p] = src[sp]; out[p + 1] = src[sp + 1]; out[p + 2] = src[sp + 2]; out[p + 3] = 255; }
        }
      }
      return { data: out, w: W, h: H };
    }
    onmessage = function (e) {
      var m = e.data, r = halftone(new Uint8ClampedArray(m.src), m.w, m.h, m.s);
      postMessage({ id: m.id, w: r.w, h: r.h, data: r.data.buffer }, [r.data.buffer]);
    };
  }

  var worker = null, jobSeq = 0, waiting = {};
  function getWorker() {
    if (worker) return worker;
    var url = URL.createObjectURL(new Blob(['(' + htEngine.toString() + ')()'], { type: 'text/javascript' }));
    worker = new Worker(url);
    worker.onmessage = function (e) {
      var m = e.data;
      if (m.progress != null) { if (busyLabel) status(busyLabel + ' ' + Math.round(m.progress * 100) + '%'); return; }
      var cb = waiting[m.id]; delete waiting[m.id];
      if (cb) cb({ w: m.w, h: m.h, data: new Uint8ClampedArray(m.data) });
    };
    worker.onerror = function (e) { status('Halftone failed: ' + (e.message || 'unknown error')); };
    return worker;
  }
  function runHalftone(it, s) {
    return new Promise(function (resolve) {
      var id = ++jobSeq; waiting[id] = resolve;
      var copy = it.data.slice().buffer; // keep the original for re-runs
      getWorker().postMessage({ id: id, src: copy, w: it.w, h: it.h, s: s }, [copy]);
    });
  }

  /* ---------- state + settings ---------- */
  var items = [], cur = -1, busyLabel = '', timer = null, runSeq = 0;
  function S() {
    return { lpi: +el('lpi').value, angle: +el('angle').value, minDot: +el('min').value / 100,
             solid: +el('solid').value / 100, dpi: +el('dpi').value || 300, scale: +el('scale').value };
  }
  function status(t) { el('status').textContent = t; }
  function labels() {
    el('lpiV').textContent = el('lpi').value;
    el('angleV').textContent = el('angle').value + '°';
    el('minV').textContent = el('min').value + '%';
    el('solidV').textContent = el('solid').value + '%';
  }
  ['lpi', 'angle', 'min', 'solid', 'dpi', 'scale'].forEach(function (id) {
    el(id).addEventListener('input', function () { labels(); schedule(); });
  });
  el('shirt').addEventListener('input', paintBoth);
  el('zoom').addEventListener('change', paintBoth);
  labels();

  /* ---------- loading files ---------- */
  var drop = el('drop');
  drop.addEventListener('click', function (e) { if (e.target === drop) el('pick').click(); });
  el('pick').addEventListener('change', function (e) { addFiles(e.target.files); e.target.value = ''; });
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });

  async function decode(file, buf) {
    // Ask for un-premultiplied pixels so faint colours keep their real value.
    var bmp;
    try { bmp = await createImageBitmap(new Blob([buf], { type: file.type }), { premultiplyAlpha: 'none', colorSpaceConversion: 'none' }); }
    catch (e) { bmp = await createImageBitmap(new Blob([buf], { type: file.type })); }
    var c = document.createElement('canvas'); c.width = bmp.width; c.height = bmp.height;
    var g = c.getContext('2d'); g.drawImage(bmp, 0, 0);
    return { w: bmp.width, h: bmp.height, data: g.getImageData(0, 0, bmp.width, bmp.height).data };
  }
  async function addFiles(list) {
    var added = 0;
    for (var i = 0; i < list.length; i++) {
      var f = list[i];
      if (!/^image\//.test(f.type)) continue;
      try {
        status('Opening ' + f.name + '…');
        var buf = await f.arrayBuffer(), img = await decode(f, buf);
        var hasAlpha = false;
        for (var p = 3; p < img.data.length; p += 4) if (img.data[p] < 255) { hasAlpha = true; break; }
        items.push({ name: f.name.replace(/\.[^.]+$/, ''), w: img.w, h: img.h, data: img.data, dpi: readPngDpi(new Uint8Array(buf)), noAlpha: !hasAlpha });
        added++;
      } catch (e) { status('Could not open ' + f.name + ': ' + (e.message || e)); }
    }
    if (added) select(items.length - 1);
  }
  el('test').addEventListener('click', function () {
    var w = 1500, h = 1500, d = new Uint8ClampedArray(w * h * 4), x0 = 450, x1 = 1050, fade = 380;
    for (var y = 0; y < h; y++) for (var x = 0; x < w; x++) {
      var dist = Math.hypot(Math.max(x0 - x, 0, x - x1), Math.max(x0 - y, 0, y - x1));
      d[(y * w + x) * 4 + 3] = Math.round(Math.max(0, 1 - dist / fade) * 255);
    }
    el('shirt').value = '#ffffff';
    items.push({ name: 'test-faded-square', w: w, h: h, data: d, dpi: 300 });
    select(items.length - 1);
  });

  function select(i) {
    cur = i;
    if (items[i].dpi) el('dpi').value = items[i].dpi;
    chips(); schedule();
  }
  function chips() {
    var box = el('files'); box.innerHTML = '';
    items.forEach(function (it, i) {
      var b = document.createElement('span');
      b.className = 'ht-chip' + (i === cur ? ' on' : '');
      b.textContent = it.name;
      var x = document.createElement('b'); x.textContent = '×'; x.title = 'Remove';
      x.addEventListener('click', function (e) {
        e.stopPropagation(); items.splice(i, 1);
        if (!items.length) { cur = -1; chips(); clearCanvases(); status(''); return; }
        var next = cur > i ? cur - 1 : cur;
        select(Math.max(0, Math.min(next, items.length - 1)));
      });
      b.appendChild(x);
      b.addEventListener('click', function () { select(i); });
      box.appendChild(b);
    });
    el('dl').disabled = cur < 0; el('dlall').disabled = items.length < 2;
  }
  function clearCanvases() { ['cin', 'cout'].forEach(function (id) { var c = el(id); c.width = c.height = 0; }); }

  /* ---------- processing ---------- */
  function schedule() { clearTimeout(timer); timer = setTimeout(run, 150); }
  async function run() {
    if (cur < 0) return;
    var it = items[cur], s = S(), key = JSON.stringify(s), mine = ++runSeq;
    paintBoth(); // original shows straight away
    if (it.out && it.outKey === key) { report(it, s); return; }
    busyLabel = 'Halftoning…'; status(busyLabel);
    var t = performance.now(), out = await runHalftone(it, s);
    busyLabel = '';
    it.out = out; it.outKey = key;
    if (mine !== runSeq || items[cur] !== it) return; // settings moved on meanwhile
    report(it, s, performance.now() - t);
    paintBoth();
  }
  function report(it, s, ms) {
    var t = (it.w / s.dpi).toFixed(2) + '″ × ' + (it.h / s.dpi).toFixed(2) + '″ · output ' + it.out.w + '×' + it.out.h + 'px @ ' + (s.dpi * s.scale) + ' DPI';
    if (ms) t += ' · ' + (ms / 1000).toFixed(1) + 's';
    if (it.noAlpha) t += ' · This image has no transparency, so there is nothing to dot. Use a transparent PNG.';
    status(t);
  }

  function paint(canvas, stage, w, h, data, fitW) {
    canvas.width = w; canvas.height = h;
    var g = canvas.getContext('2d'), sh = el('shirt').value;
    var sr = parseInt(sh.slice(1, 3), 16), sg = parseInt(sh.slice(3, 5), 16), sb = parseInt(sh.slice(5, 7), 16);
    var img = g.createImageData(w, h), o = img.data;
    // DTF puts white underbase under anything with ink at all, so a see-through
    // pixel shows up as its colour faded toward WHITE, not toward the shirt.
    // That is the pink problem; drawing it this way makes it visible. The
    // halftoned side is all 0 or 255, so the same rule draws it exactly.
    for (var p = 0; p < o.length; p += 4) {
      var a = data[p + 3] / 255;
      if (a < 0.02) { o[p] = sr; o[p + 1] = sg; o[p + 2] = sb; }
      else { o[p] = data[p] * a + 255 * (1 - a); o[p + 1] = data[p + 1] * a + 255 * (1 - a); o[p + 2] = data[p + 2] * a + 255 * (1 - a); }
      o[p + 3] = 255;
    }
    g.putImageData(img, 0, 0);
    var z = el('zoom').value, f = (fitW || w) / w;
    var scale = (z === 'fit' ? Math.min((stage.clientWidth - 2) / (w * f), (stage.clientHeight - 2) / (h * f), 1) : +z) * f;
    canvas.style.width = (w * scale) + 'px'; canvas.style.height = (h * scale) + 'px';
    canvas.style.imageRendering = scale >= 1 ? 'pixelated' : 'auto';
  }
  function paintBoth() {
    if (cur < 0) return;
    var it = items[cur];
    paint(el('cin'), el('s1'), it.w, it.h, it.data);
    if (it.out) paint(el('cout'), el('s2'), it.out.w, it.out.h, it.out.data, it.w);
    else { var c = el('cout'); c.width = c.height = 0; }
  }
  window.addEventListener('resize', function () { if (el('s1').offsetParent) paintBoth(); });

  // Called by btSwitchTab(): canvases sized while the pane was hidden measure 0.
  window.btpHalftonerShow = function () { paintBoth(); };

  /* ---------- export ---------- */
  async function exportItem(it) {
    var s = S(), key = JSON.stringify(s);
    if (!it.out || it.outKey !== key) { busyLabel = 'Halftoning ' + it.name + '…'; it.out = await runHalftone(it, s); it.outKey = key; busyLabel = ''; }
    var c = document.createElement('canvas'); c.width = it.out.w; c.height = it.out.h;
    c.getContext('2d').putImageData(new ImageData(it.out.data, it.out.w, it.out.h), 0, 0);
    var blob = await new Promise(function (r) { c.toBlob(r, 'image/png'); });
    var bytes = setPngDpi(new Uint8Array(await blob.arrayBuffer()), s.dpi * s.scale);
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([bytes], { type: 'image/png' }));
    a.download = it.name + '-halftone.png';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 10000);
  }
  el('dl').addEventListener('click', async function () { if (cur >= 0) { await exportItem(items[cur]); report(items[cur], S()); } });
  el('dlall').addEventListener('click', async function () {
    for (var i = 0; i < items.length; i++) { status('Exporting ' + (i + 1) + ' of ' + items.length + '…'); await exportItem(items[i]); await new Promise(function (r) { setTimeout(r, 300); }); }
    status('Done. ' + items.length + ' files downloaded.');
  });

  /* ---------- PNG DPI (pHYs) ---------- */
  var CRC = (function () { var t = new Uint32Array(256); for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
  function crc32(b) { var c = 0xffffffff; for (var i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; }
  function readPngDpi(b) {
    if (b[0] !== 0x89 || b[1] !== 0x50) return null;
    var p = 8, dv = new DataView(b.buffer, b.byteOffset);
    while (p + 8 < b.length) {
      var len = dv.getUint32(p), type = String.fromCharCode(b[p + 4], b[p + 5], b[p + 6], b[p + 7]);
      if (type === 'pHYs' && b[p + 16] === 1) return Math.round(dv.getUint32(p + 8) * 0.0254);
      if (type === 'IDAT') break;
      p += 12 + len;
    }
    return null;
  }
  function setPngDpi(b, dpi) {
    var ppm = Math.round(dpi / 0.0254), chunk = new Uint8Array(21), dv = new DataView(chunk.buffer);
    dv.setUint32(0, 9); chunk.set([0x70, 0x48, 0x59, 0x73], 4);
    dv.setUint32(8, ppm); dv.setUint32(12, ppm); chunk[16] = 1;
    dv.setUint32(17, crc32(chunk.subarray(4, 17)));
    var parts = [b.subarray(0, 33), chunk], p = 33, bv = new DataView(b.buffer, b.byteOffset);
    while (p < b.length) {
      var len = bv.getUint32(p), type = String.fromCharCode(b[p + 4], b[p + 5], b[p + 6], b[p + 7]);
      if (type !== 'pHYs') parts.push(b.subarray(p, p + 12 + len));
      p += 12 + len;
    }
    var n = 0; parts.forEach(function (x) { n += x.length; });
    var out = new Uint8Array(n), o = 0;
    parts.forEach(function (x) { out.set(x, o); o += x.length; });
    return out;
  }
})();
</script>
</div>
    <?php
    return ob_get_clean();
}
