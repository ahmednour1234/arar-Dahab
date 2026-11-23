/* ماسح الذهب — frontend (vanilla JS, no external dependencies) */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  // Base URL so the app also works from a sub-folder (e.g. https://host/public/)
  const BASE = String(window.GS_BASE || "").replace(/\/$/, "");
  const KEY_STORE = "goldScanner.accessKey";
  const state = {
    cfg: null,
    key: "",
    view: null,
    online: false,
    excel: null,
    manualKeyboard: false,
    busy: false,
    idleTimer: null,
  };
  const isTouch = window.matchMedia("(pointer: coarse)").matches;

  // ---------- helpers ----------
  // Reliable symbologies only. Checked after decoding: ITF/Codabar give false reads on small labels.
  // ITF (Interleaved 2 of 5) is what the shop's labels use. It has no check digit, so it is accepted only
  // as an even-length digit string (6-14) and the live camera needs more identical reads for it.
  function itfOk(text) { return /^\d{6,14}$/.test(text) && text.length % 2 === 0; }
  function zxAllowed(result) {
    const Z = window.ZXing, f = result.getBarcodeFormat();
    if (f === Z.BarcodeFormat.ITF) return itfOk(cleanCode(result.getText()));
    return [Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.CODE_93, Z.BarcodeFormat.EAN_13,
            Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E, Z.BarcodeFormat.QR_CODE].includes(f);
  }
  function zxIsWeak(result) { return result.getBarcodeFormat() === window.ZXing.BarcodeFormat.ITF; }
  function cleanCode(s) { return String(s == null ? "" : s).replace(/[\s\u00A0\u200B-\u200F\u202A-\u202E\u2060\uFEFF\u0640]+/g, ""); }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }
  function store(k, v) { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (_) {} }
  function load(k) { try { return localStorage.getItem(k) || ""; } catch (_) { return ""; } }

  function fmtTime(iso) {
    if (!iso) return "—";
    const d = new Date(iso);
    if (isNaN(d)) return iso;
    const sameDay = d.toDateString() === new Date().toDateString();
    const t = d.toLocaleTimeString("ar-SA-u-nu-latn", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    return sameDay ? t : d.toLocaleDateString("ar-SA-u-nu-latn-ca-gregory") + " " + t;
  }
  function fmtNum(v) {
    const n = Number(v);
    if (v === "" || v == null || isNaN(n)) return esc(v);
    return n.toLocaleString("en-US", { maximumFractionDigits: 6 });
  }

  async function api(path, opts = {}) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), opts.timeout || 8000);
    try {
      const res = await fetch(BASE + path, {
        headers: state.key ? { "X-Access-Key": state.key } : {},
        cache: "no-store",
        signal: ctrl.signal,
      });
      let body = null;
      try { body = await res.json(); } catch (_) {}
      return { status: res.status, body };
    } finally {
      clearTimeout(t);
    }
  }

  function show(view) {
    ["viewKey", "viewScan", "viewList", "viewDisplay", "viewCamera", "viewUpload", "viewLoading", "viewResult"].forEach((v) => ($(v).hidden = v !== view));
    if (state.view === "viewCamera" && view !== "viewCamera") stopCamera();
    if (state.view === "viewDisplay" && view !== "viewDisplay") stopDisplay();
    document.body.classList.toggle("is-display", view === "viewDisplay");
    state.view = view;
    if (view === "viewScan") focusInput();
  }

  // ---------- focus / soft keyboard ----------
  function focusInput() {
    const inp = $("barcode");
    inp.setAttribute("inputmode", state.manualKeyboard || !isTouch ? "text" : "none");
    if (state.view === "viewScan") inp.focus({ preventScroll: true }); // immediately, so fast wedge input isn't lost
    setTimeout(() => { if (state.view === "viewScan") inp.focus({ preventScroll: true }); }, 30);
  }
  $("kbdToggle").addEventListener("click", () => {
    state.manualKeyboard = !state.manualKeyboard;
    $("kbdToggle").classList.toggle("on", state.manualKeyboard);
    const inp = $("barcode");
    inp.blur();
    focusInput();
  });
  // Keep the input focused on the scan screen so the keyboard-wedge scanner always lands there.
  $("barcode").addEventListener("blur", () => {
    setTimeout(() => {
      const a = document.activeElement;
      if (state.view === "viewScan" && (!a || a === document.body)) focusInput();
    }, 150);
  });

  // ---------- connection + sync status polling ----------
  function setConn(s, text) {
    $("conn").dataset.state = s;
    $("connText").textContent = text;
  }
  function renderStatus() {
    const banner = $("banner");
    if (!state.online) {
      setConn("offline", "غير متصل");
      $("syncText").textContent = "تعذر الاتصال بالخادم";
      banner.className = "banner err";
      banner.textContent = "تعذر الاتصال بجهاز الكمبيوتر (الخادم). تأكد أن الجهاز يعمل وأنك متصل بنفس شبكة Wi‑Fi.";
      banner.hidden = false;
      return;
    }
    const ex = state.excel || {};
    $("syncText").textContent = "آخر تحديث ناجح: " + fmtTime(ex.last_success);
    const warns = [];
    if (ex.state === "ok") setConn("ok", "متصل");
    else if (ex.state === "stale") setConn("stale", "بيانات قديمة");
    else if (ex.state === "error") setConn("error", "خطأ");
    else setConn("loading", "تحميل…");

    if (ex.state === "stale") {
      warns.push("تعذر قراءة ملف Excel حالياً: " + (ex.last_error || "") +
        " — يتم عرض آخر بيانات تم تحميلها بنجاح (" + fmtTime(ex.last_success) + ") وقد تكون غير محدثة.");
    } else if (ex.state === "error") {
      warns.push("لم يتم تحميل ملف المخزون: " + (ex.last_error || ""));
    }
    if (ex.duplicate_barcodes) warns.push("تنبيه: يوجد " + ex.duplicate_barcodes + " باركود مكرر في ملف Excel.");
    banner.className = "banner" + (ex.state === "error" ? " err" : "");
    banner.textContent = warns.join("  •  ");
    banner.hidden = warns.length === 0;
  }

  async function pollHealth() {
    try {
      const r = await api("/api/health", { timeout: 5000 });
      if (r.status === 401) { askKey(true); return; }
      state.online = r.status === 200;
      if (state.online) state.excel = r.body.excel;
    } catch (_) {
      state.online = false;
    }
    renderStatus();
  }

  // ---------- access key ----------
  function askKey(invalid) {
    store(KEY_STORE, null);
    state.key = "";
    $("keyError").hidden = !invalid;
    $("keyError").textContent = invalid ? "مفتاح الدخول غير صحيح" : "";
    show("viewKey");
    setTimeout(() => $("keyInput").focus(), 50);
  }
  $("keyForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    state.key = $("keyInput").value.trim();
    const r = await api("/api/health").catch(() => null);
    if (!r) { $("keyError").hidden = false; $("keyError").textContent = "تعذر الاتصال بالخادم"; return; }
    if (r.status === 401) { askKey(true); return; }
    store(KEY_STORE, state.key);
    $("keyInput").value = "";
    startApp();
  });

  // ---------- search ----------
  async function search(raw) {
    const code = cleanCode(raw);
    $("scanError").hidden = true;
    if (!code) { focusInput(); return; }
    if (!/^[0-9A-Za-z\-_.\/ ]{1,64}$/.test(code)) {
      $("scanError").textContent = "صيغة الباركود غير صالحة";
      $("scanError").hidden = false;
      $("barcode").value = "";
      focusInput();
      return;
    }
    if (state.busy) return;
    state.busy = true;
    show("viewLoading");
    try {
      const fid = $("scanFilter").value;
      const r = await api(itemUrl(code, fid));
      state.online = true;
      if (r.status === 401) { askKey(true); return; }
      renderResult(r.status, r.body || {}, code);
    } catch (_) {
      state.online = false;
      renderResult(0, {}, code);
    } finally {
      state.busy = false;
      $("barcode").value = "";
      renderStatus();
    }
  }

  const ICON_NF = '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M16.5 16.5 21 21M8.5 8.5l5 5m0-5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
  const ICON_WARN = '<svg viewBox="0 0 24 24"><path d="M12 3 2 21h20L12 3z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 10v5m0 3v.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
  const ICON_OFF = '<svg viewBox="0 0 24 24"><path d="M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0M12 19h.01M3 3l18 18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

  function statusBadge(s) {
    const v = String(s || "");
    const cls = /متوفر|available/i.test(v) ? "ok" : /محجوز|reserved/i.test(v) ? "warn" : /مباع|sold/i.test(v) ? "err" : "";
    return v ? '<span class="badge ' + cls + '">' + esc(v) + "</span>" : "—";
  }

  function staleNote(body) {
    if (body.data_state === "stale")
      return '<p class="stale-note">⚠ قد تكون هذه البيانات غير محدثة — آخر تحميل ناجح لملف Excel: ' + esc(fmtTime(body.last_success)) + "</p>";
    return "";
  }

  function itemUrl(code, fid) {
    const qs = [];
    if (fid) qs.push("file=" + encodeURIComponent(fid));
    if (live.send) qs.push("live=" + encodeURIComponent(live.channel || "main") + "&device=" + encodeURIComponent(live.device || ""));
    return "/api/item/" + encodeURIComponent(code) + (qs.length ? "?" + qs.join("&") : "");
  }

  function buildResultHtml(status, body, code) {
    let html = "";
    if (status === 200 && body.item) {
      const f = body.item.fields, L = body.item.labels || {};
      const label = (k, d) => esc(L[k] || d);
      const main = ["barcode", "item_name", "category", "karat", "weight", "price", "status"];
      const rows = [];
      ["making_charge", "branch", "notes"].forEach((k) => {
        if (k in f) rows.push([label(k, k), k === "making_charge" ? fmtNum(f[k]) : esc(f[k] || "—")]);
      });
      Object.keys(f).forEach((k) => { if (!main.includes(k) && !["making_charge", "branch", "notes"].includes(k)) rows.push([label(k, k), esc(f[k])]); });
      (body.item.extra || []).forEach((x) => rows.push([esc(x.label), esc(x.value)]));
      rows.push(["الحالة", statusBadge(f.status)]);
      const loc = body.item.location || {};
      if (loc.file) rows.push(["الملف", esc(loc.file) + ' <span class="muted small">(صف ' + esc(loc.row) + ")</span>"]);
      state.lastItem = { fields: f, labels: L, extra: body.item.extra || [], location: loc };
      rows.push([label("barcode", "الباركود"), '<span dir="ltr">' + esc(f.barcode) + "</span>"]);

      html =
        '<article class="item-hero">' +
          '<div class="bc">' + esc(f.barcode) + "</div>" +
          "<h2>" + esc(f.item_name || "—") + "</h2>" +
          '<div class="cat">' + esc(f.category || "") + "</div>" +
          '<div class="stats">' +
            '<div class="stat"><div class="k">' + label("karat", "العيار") + '</div><div class="v">' + esc(f.karat || "—") + (f.karat ? " <small>قيراط</small>" : "") + "</div></div>" +
            '<div class="stat"><div class="k">' + label("weight", "الوزن") + '</div><div class="v">' + (f.weight ? fmtNum(f.weight) + " <small>جم</small>" : "—") + "</div></div>" +
          "</div>" +
          '<div class="price"><span class="muted">السعر المخزّن</span><span class="v">' + (f.price ? fmtNum(f.price) + ' <small class="muted">ر.س</small>' : "—") + "</span></div>" +
          (body.matched_as ? '<p class="stale-note">ℹ تم العثور على القطعة بدون الأصفار: الباركود الممسوح <b dir="ltr">' + esc(code) + '</b> ومسجل في الملف <b dir="ltr">' + esc(body.matched_as) + "</b></p>" : "") +
          staleNote(body) +
        "</article>" +
        '<section class="details"><dl>' +
          rows.map((r) => '<div class="r"><dt>' + r[0] + "</dt><dd>" + r[1] + "</dd></div>").join("") +
        "</dl></section>" +
        '<div class="copy-row">' +
          '<button type="button" class="btn outline small-btn" data-copy="barcode">نسخ الباركود</button>' +
          '<button type="button" class="btn outline small-btn" data-copy="details">نسخ التفاصيل</button>' +
        "</div>";
    } else if (status === 404) {
      html = '<div class="msg nf">' + ICON_NF + "<h2>القطعة غير موجودة في ملف المخزون</h2>" +
        '<span class="code">' + esc(code) + "</span>" + staleNote(body) + "</div>";
    } else if (status === 409) {
      const locs = (body.locations || []).map((l) => "<li>" + (l.file ? esc(l.file) + " — " : "") + "ورقة " + esc(l.sheet) + " — صف " + esc(l.row) + "</li>").join("");
      html = '<div class="msg dup">' + ICON_WARN + "<h2>خطأ: باركود مكرر</h2>" +
        "<p>هذا الباركود مسجل " + esc(body.count || 2) + " مرات في ملف المخزون، ولا يمكن عرض قطعة واحدة بشكل مؤكد. يرجى تصحيح ملف Excel.</p>" +
        '<span class="code">' + esc(code) + "</span><ul>" + locs + "</ul></div>";
    } else if (status === 503) {
      html = '<div class="msg dup">' + ICON_WARN + "<h2>ملف المخزون غير محمّل</h2><p>" +
        esc(body.detail || "") + "</p><p class=\"muted\">تحقق من مسار ملف Excel في إعدادات الخادم.</p></div>";
    } else if (status === 0) {
      html = '<div class="msg dup">' + ICON_OFF + "<h2>تعذر الاتصال بالخادم</h2><p>تأكد أن جهاز الكمبيوتر يعمل وأن الجهاز متصل بنفس شبكة Wi‑Fi.</p></div>";
    } else {
      html = '<div class="msg dup">' + ICON_WARN + "<h2>حدث خطأ</h2><p>" + esc((body && body.detail) || "خطأ غير متوقع") + "</p></div>";
    }
    return html;
  }

  function renderResult(status, body, code) {
    $("resultBody").innerHTML = buildResultHtml(status, body, code);
    show("viewResult");
    window.scrollTo(0, 0);
    $("newScanBtn").focus({ preventScroll: true });
  }

  function newScan() {
    $("barcode").value = "";
    $("scanError").hidden = true;
    show("viewScan");
  }
  $("newScanBtn").addEventListener("click", newScan);

  // ---------- scanner input handling ----------
  $("scanForm").addEventListener("submit", (e) => { e.preventDefault(); search($("barcode").value); });
  $("barcode").addEventListener("keydown", (e) => {
    if (e.key === "Tab" && state.cfg && state.cfg.scanner_suffix === "tab") {
      e.preventDefault();
      search($("barcode").value);
    }
  });
  $("barcode").addEventListener("input", () => {
    if (!state.cfg || state.cfg.scanner_suffix !== "none") return;
    clearTimeout(state.idleTimer);
    state.idleTimer = setTimeout(() => { if ($("barcode").value.trim()) search($("barcode").value); },
      state.cfg.scanner_idle_submit_ms || 250);
  });

  // On the result screen, a new scan from the wedge scanner starts the next lookup directly.
  document.addEventListener("keydown", (e) => {
    if (state.view !== "viewResult" || e.ctrlKey || e.altKey || e.metaKey) return;
    if (e.key.length === 1 && /[0-9A-Za-z\-_.\/]/.test(e.key)) {
      e.preventDefault();
      newScan();
      $("barcode").value = e.key;
    }
  });

  // ---------- phone camera scanning ----------
  const cam = { stream: null, reader: null, timer: null, done: false, last: "", hits: 0 };
  // ITF / Codabar removed: they produce false reads on small jewelry labels
  const FORMATS = ["itf", "code_128", "code_39", "code_93", "ean_13", "ean_8", "upc_a", "upc_e", "qr_code"];

  function camFail(msg) {
    $("camError").innerHTML = msg;
    $("camError").hidden = false;
    $("camHint").hidden = true;
  }

  function onCameraCode(text, weak) {
    if (cam.done || !text) return;
    const t = cleanCode(text);
    if (weak && !itfOk(t)) return;
    // accept only when the same value is decoded twice in a row (filters misreads)
    if (t !== cam.last) { cam.last = t; cam.hits = 1; return; }
    if (++cam.hits < (weak ? 3 : 2)) return;
    text = t;
    cam.done = true;
    if (navigator.vibrate) navigator.vibrate(80);
    stopCamera();
    search(String(text).trim());
  }

  async function openCamera() {
    $("camError").hidden = true;
    $("camHint").hidden = false;
    cam.done = false; cam.last = ""; cam.hits = 0;
    show("viewCamera");
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      camFail("المتصفح يسمح باستخدام الكاميرا فقط عبر اتصال آمن <b dir=\"ltr\">https://</b>.<br>شغّل الخادم بشهادة HTTPS (راجع ملف README) ثم افتح الرابط بـ https.");
      return;
    }
    const video = $("camVideo");
    try {
      // Prefer the native BarcodeDetector (fast, built into Chrome on Android)
      let native = null;
      if ("BarcodeDetector" in window) {
        try {
          const sup = await window.BarcodeDetector.getSupportedFormats();
          const fmts = FORMATS.filter((f) => sup.includes(f));
          if (fmts.length) native = new window.BarcodeDetector({ formats: fmts });
        } catch (_) {}
      }
      if (native) {
        cam.stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 }, focusMode: { ideal: "continuous" } }, audio: false });
        video.srcObject = cam.stream;
        await video.play();
        const tick = async () => {
          if (cam.done || !cam.stream) return;
          try {
            const codes = await native.detect(video);
            if (codes && codes.length) { const c0 = codes[0]; onCameraCode(c0.rawValue, c0.format === "itf"); if (cam.done) return; }
          } catch (_) {}
          cam.timer = setTimeout(tick, 120);
        };
        tick();
      } else if (window.ZXing) {
        // ZXing (iPhone / browsers without BarcodeDetector): our own frame loop that finds the bars,
        // crops tightly around them (background confuses the decoder) and decodes the crop.
        const Z = window.ZXing;
        const hints = new Map();
        hints.set(Z.DecodeHintType.TRY_HARDER, true);
        const mf = new Z.MultiFormatReader();
        mf.setHints(hints);
        const decodeCanvas = zxDecode;
        cam.stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 }, focusMode: { ideal: "continuous" } }, audio: false });
        video.srcObject = cam.stream;
        await video.play();
        const tick = () => {
          if (cam.done || !cam.stream) return;
          const vw = video.videoWidth, vh = video.videoHeight;
          if (vw && vh) {
            // 1) white labels in the frame -> bars inside each label (backgrounds like wood grain look like bars)
            const base = toCanvas(video, 0, 0, vw, vh, 960), kk = vw / base.width;
            for (const r of findLabelRegions(base).slice(0, 4)) {
              const rx = r.x * kk, ry = r.y * kk, rw = r.w * kk, rh = r.h * kk;
              const nat = toCanvas(video, rx, ry, rw, rh, 1200, 400);
              const bars = findBars(nat);
              if (!bars) continue;
              const k2 = rw / nat.width;
              const bx = Math.round(rx + bars.x * k2), by = Math.round(ry + bars.y * k2), bw = Math.round(bars.w * k2), bh = Math.round(bars.h * k2);
              for (const minSide of [640, 480, 900]) {
                const res = decodeCanvas(padCanvas(toCanvas(video, bx, by, bw, bh, 1280, minSide)));
                if (res) { onCameraCode(res.text, res.weak); if (cam.done) return; break; }
              }
            }
            // 2) the area inside the gold frame, then the whole frame
            const areas = [[vw * 0.05, vh * 0.2, vw * 0.9, vh * 0.6], [0, 0, vw, vh]];
            for (const [ax, ay, aw, ah] of areas) {
              const base = toCanvas(video, ax, ay, aw, ah, 1280);
              const bars = findBars(base);
              let text = null;
              if (bars) {
                const k = aw / base.width;
                const bx = Math.round(ax + bars.x * k), by = Math.round(ay + bars.y * k), bw = Math.round(bars.w * k), bh = Math.round(bars.h * k);
                for (const minSide of [640, 480, 900]) {
                  text = decodeCanvas(padCanvas(toCanvas(video, bx, by, bw, bh, 1280, minSide)));
                  if (text) break;
                }
              }
              if (!text) text = decodeCanvas(base);
              if (text) { onCameraCode(text.text, text.weak); if (cam.done) return; break; }
            }
          }
          cam.timer = setTimeout(tick, 150);
        };
        tick();
      } else {
        camFail("مكتبة قراءة الباركود غير محمّلة.");
      }
    } catch (e) {
      const denied = e && (e.name === "NotAllowedError" || e.name === "SecurityError");
      camFail(denied ? "تم رفض إذن الكاميرا. اسمح للمتصفح باستخدام الكاميرا ثم حاول مرة أخرى."
                     : "تعذر تشغيل الكاميرا: " + esc((e && e.message) || e));
    }
  }

  function stopCamera() {
    clearTimeout(cam.timer);
    try { if (cam.reader) cam.reader.reset(); } catch (_) {}
    cam.reader = null;
    if (cam.stream) cam.stream.getTracks().forEach((t) => t.stop());
    cam.stream = null;
    const v = $("camVideo");
    if (v) { try { v.pause(); } catch (_) {} v.srcObject = null; }
  }

  $("cameraBtn").addEventListener("click", openCamera);
  $("camClose").addEventListener("click", () => { stopCamera(); show("viewScan"); });
  document.addEventListener("visibilitychange", () => { if (document.hidden && state.view === "viewCamera") { stopCamera(); show("viewScan"); } });

  // ---------- Excel upload (server deployments) ----------
  const ADMIN_STORE = "goldScanner.adminKey";
  $("uploadOpen").addEventListener("click", () => {
    $("uploadMsg").hidden = true;
    $("adminKey").value = sessionStorage.getItem(ADMIN_STORE) || "";
    show("viewUpload");
    loadFiles();
  });
  $("uploadClose").addEventListener("click", () => show("viewScan"));
  $("fileInput").addEventListener("change", () => {
    const f = $("fileInput").files[0];
    $("fileName").textContent = f ? f.name + " (" + (f.size / 1024).toFixed(0) + " KB)" : "اضغط لاختيار ملف Excel أو CSV";
    $("filePick").classList.toggle("has", !!f);
  });
  $("uploadForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const f = $("fileInput").files[0];
    const msg = $("uploadMsg");
    const say = (ok, html) => { msg.className = ok ? "up-ok" : "up-err"; msg.innerHTML = html; msg.hidden = false; };
    if (!f) return say(false, "اختر ملف Excel أولاً.");
    if (!/\.(xlsx?|csv)$/i.test(f.name)) return say(false, "الملف يجب أن يكون بصيغة <b dir=\"ltr\">.xls</b> أو <b dir=\"ltr\">.xlsx</b> أو <b dir=\"ltr\">.csv</b>.");
    const admin = $("adminKey").value.trim();
    if (!admin && state.cfg.admin_required) return say(false, "أدخل مفتاح المسؤول.");
    $("uploadBtn").disabled = true;
    $("uploadBtn").textContent = "جارٍ الرفع والفحص…";
    try {
      const res = await fetch(BASE + "/api/admin/upload", {
        method: "POST",
        headers: { "X-Access-Key": state.key, "X-Admin-Key": admin, "X-Filename": encodeURIComponent(f.name), "Content-Type": "application/octet-stream" },
        body: f,
      });
      let body = {};
      try { body = await res.json(); } catch (_) {}
      if (res.ok) {
        try { sessionStorage.setItem(ADMIN_STORE, admin); } catch (_) {}
        let html = "✔ " + (body.file && body.file.replaced ? "تم تحديث الملف " : "تمت إضافة الملف ") + "<b>" + esc(body.file ? body.file.name : f.name) + "</b> بنجاح.<br>عدد القطع في الملف: <b>" + esc(body.records) + "</b>";
        if (body.duplicate_barcodes) html += "<br>⚠ يوجد " + esc(body.duplicate_barcodes) + " باركود مكرر في الملف.";
        say(true, html);
        $("fileInput").value = ""; $("fileName").textContent = "اضغط لاختيار ملف Excel أو CSV"; $("filePick").classList.remove("has");
        pollHealth(); loadDemo(); loadFiles();
      } else {
        say(false, "✖ لم يتم رفع الملف: " + esc(body.detail || ("خطأ " + res.status)) + "<br>البيانات الحالية لم تتغير.");
      }
    } catch (_) {
      say(false, "✖ تعذر الاتصال بالخادم.");
    } finally {
      $("uploadBtn").disabled = false;
      $("uploadBtn").textContent = "رفع وتحديث البيانات";
    }
  });

  // ---------- scan barcodes from a photo (camera shot or gallery) ----------
  const IMG_FORMATS = FORMATS;
  function loadImage(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => resolve({ img, url });
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error("bad image")); };
      img.src = url;
    });
  }
  function toCanvas(img, sx, sy, sw, sh, maxSide, minSide) {
    let scale = Math.min(1, maxSide / Math.max(sw, sh));
    if (minSide && Math.max(sw, sh) * scale < minSide) scale = Math.min(4, minSide / Math.max(sw, sh)); // enlarge small crops
    const c = document.createElement("canvas");
    c.width = Math.max(1, Math.round(sw * scale)); c.height = Math.max(1, Math.round(sh * scale));
    const g = c.getContext("2d");
    g.imageSmoothingEnabled = true; g.imageSmoothingQuality = "high";
    g.drawImage(img, sx, sy, sw, sh, 0, 0, c.width, c.height);
    return c;
  }
  // add a white margin (quiet zone) around a crop: decoders need it
  function padCanvas(cv) {
    const px = Math.round(cv.width * 0.15), py = Math.round(cv.height * 0.3);
    const c = document.createElement("canvas");
    c.width = cv.width + 2 * px; c.height = cv.height + 2 * py;
    const g = c.getContext("2d");
    g.fillStyle = "#fff"; g.fillRect(0, 0, c.width, c.height);
    g.drawImage(cv, px, py);
    return c;
  }
  // grayscale + contrast stretch: helps on dim / low-contrast photos
  function enhance(canvas) {
    const g = canvas.getContext("2d");
    const d = g.getImageData(0, 0, canvas.width, canvas.height), a = d.data;
    let lo = 255, hi = 0;
    for (let i = 0; i < a.length; i += 4) {
      const v = (a[i] * 299 + a[i + 1] * 587 + a[i + 2] * 114) / 1000;
      a[i] = v; if (v < lo) lo = v; if (v > hi) hi = v;
    }
    const k = hi > lo ? 255 / (hi - lo) : 1;
    for (let i = 0; i < a.length; i += 4) { const v = (a[i] - lo) * k; a[i] = a[i + 1] = a[i + 2] = v; }
    g.putImageData(d, 0, 0);
    return canvas;
  }
  // Find bright, low-saturation areas (labels / paper) and return their boxes in image coordinates.
  function findLabelRegions(img) {
    const W = img.naturalWidth || img.videoWidth || img.width, H = img.naturalHeight || img.videoHeight || img.height;
    const s = Math.min(1, 700 / Math.max(W, H));
    const c = toCanvas(img, 0, 0, W, H, 700);
    const w = c.width, h = c.height, a = c.getContext("2d").getImageData(0, 0, w, h).data;
    // "label-like" = bright and only lightly coloured. Thresholds adapt to the photo (white balance may tint labels blue/yellow).
    const bright = new Uint8Array(w * h);
    for (let i = 0, j = 0; j < w * h; i += 4, j++) bright[j] = Math.max(a[i], a[i + 1], a[i + 2]);
    const srt = Uint8Array.from(bright).sort();
    const minBright = Math.max(140, Math.min(200, srt[Math.floor(srt.length * 0.7)]));
    const mask = new Uint8Array(w * h);
    for (let i = 0, j = 0; j < w * h; i += 4, j++) {
      const mx = bright[j], mn = Math.min(a[i], a[i + 1], a[i + 2]);
      mask[j] = mx >= minBright && (mx - mn) / mx < 0.3 ? 1 : 0;
    }
    const seen = new Uint8Array(w * h), boxes = [], stack = [];
    for (let j = 0; j < w * h; j++) {
      if (!mask[j] || seen[j]) continue;
      let x0 = w, y0 = h, x1 = 0, y1 = 0, n = 0;
      stack.push(j); seen[j] = 1;
      while (stack.length) {
        const q = stack.pop(), x = q % w, y = (q / w) | 0; n++;
        if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
        const nb = [q - 1, q + 1, q - w, q + w];
        for (const t of nb) if (t >= 0 && t < w * h && !seen[t] && mask[t] && Math.abs((t % w) - x) <= 1) { seen[t] = 1; stack.push(t); }
      }
      const bw = x1 - x0 + 1, bh = y1 - y0 + 1;
      if (n < w * h * 0.0004 || bw < 8 || bh < 6) continue;          // too small
      if (bw * bh > w * h * 0.9) continue;                            // the whole picture
      const m = 0.12;                                                 // margin around the label
      boxes.push({ x: Math.max(0, (x0 - bw * m) / s), y: Math.max(0, (y0 - bh * m) / s),
                   w: Math.min(W, (bw * (1 + 2 * m)) / s), h: Math.min(H, (bh * (1 + 2 * m)) / s), n });
    }
    return boxes.sort((p, q) => q.n - p.n).slice(0, 40);
  }
  // Locate the barcode bars inside a canvas: rows with many sharp light/dark transitions.
  function findBars(cv) {
    const w = cv.width, h = cv.height;
    if (w < 40 || h < 20) return null;
    const a = cv.getContext("2d").getImageData(0, 0, w, h).data;
    const lum = new Float32Array(w * h);
    for (let i = 0, j = 0; j < w * h; i += 4, j++) lum[j] = (a[i] * 299 + a[i + 1] * 587 + a[i + 2] * 114) / 1000;
    const rowCount = new Int32Array(h), colHits = new Int32Array(w);
    // threshold adapts to the image contrast (small / soft photos have weak edges)
    const sorted = Float32Array.from(lum).sort();
    const range = sorted[Math.floor(sorted.length * 0.97)] - sorted[Math.floor(sorted.length * 0.03)];
    const thr = Math.max(14, range * 0.28), st = w < 500 ? 1 : 2;
    const edge = (y, x) => Math.abs(lum[y * w + Math.min(w - 1, x + st - 1) + (st === 1 ? 1 : 0)] - lum[y * w + x - 1]) > thr;
    for (let y = 0; y < h; y++) {
      let n = 0, prev = false;
      for (let x = 1; x < w - 1; x++) { const e = edge(y, x); if (e && !prev) n++; prev = e; }
      rowCount[y] = n;
    }
    let best = 0; for (let y = 0; y < h; y++) if (rowCount[y] > best) best = rowCount[y];
    if (best < 10) return null;
    // longest run of rows with at least 55% of the best transition count
    let y0 = -1, y1 = -1, s0 = -1;
    for (let y = 0; y <= h; y++) {
      const on = y < h && rowCount[y] >= best * 0.55;
      if (on && s0 < 0) s0 = y;
      if (!on && s0 >= 0) { if (y - s0 > y1 - y0) { y0 = s0; y1 = y; } s0 = -1; }
    }
    if (y1 - y0 < 4) return null;
    for (let y = y0; y < y1; y++) for (let x = 1; x < w - 1; x++) if (edge(y, x)) colHits[x]++;
    // smooth column hits, then take the longest dense run (the bars). Isolated label borders are separate runs.
    const win = Math.max(6, Math.round(w * 0.025)), need = (y1 - y0) * 0.3;
    const dense = new Uint8Array(w);
    let acc = 0;
    for (let x = 0; x < w; x++) {
      acc += colHits[x] >= need ? 1 : 0;
      if (x >= win) acc -= colHits[x - win] >= need ? 1 : 0;
      if (acc > 0) for (let k = Math.max(0, x - win + 1); k <= x; k++) dense[k] = 1;
    }
    let x0 = -1, x1 = -1, r0 = -1;
    for (let x = 0; x <= w; x++) {
      const on = x < w && dense[x];
      if (on && r0 < 0) r0 = x;
      if (!on && r0 >= 0) {
        // count edges in the run: the bars have many, a label border has few
        let e = 0; for (let k = r0; k < x; k++) if (colHits[k] >= need) e++;
        if (e >= 8 && x - r0 > x1 - x0) { x0 = r0; x1 = x; }
        r0 = -1;
      }
    }
    if (x0 < 0 || x1 - x0 < 20) return null;
    const bw = x1 - x0, bh = y1 - y0;
    const padX = bw * 0.08, padY = bh * 0.15;
    return { x: Math.max(0, x0 - padX), y: Math.max(0, y0 - padY), w: Math.min(w, bw + 2 * padX), h: Math.min(h, bh + 2 * padY) };
  }
  // Direct decode of a canvas (Hybrid, then Global binarizer). A fresh reader per attempt:
  // reusing one MultiFormatReader across many failed attempts made later decodes fail.
  function zxDecode(canvas) {
    const Z = window.ZXing;
    for (const Bin of [Z.HybridBinarizer, Z.GlobalHistogramBinarizer]) {
      try {
        const mf = new Z.MultiFormatReader();
        const h = new Map(); h.set(Z.DecodeHintType.TRY_HARDER, true); mf.setHints(h);
        const r = mf.decodeWithState(new Z.BinaryBitmap(new Bin(new Z.HTMLCanvasElementLuminanceSource(canvas))));
        if (r && zxAllowed(r)) return { text: r.getText(), weak: zxIsWeak(r) };
      } catch (_) {}
    }
    return null;
  }
  async function decodeWithZXing(canvas) {
    const t = zxDecode(canvas);
    return t ? [t.text] : [];
  }
  // Returns every distinct barcode found in the image.
  async function decodeImage(file) {
    const { img, url } = await loadImage(file);
    const W = img.naturalWidth, H = img.naturalHeight;
    const found = new Set();
    const add = (t) => { const c = cleanCode(t); if (c) found.add(c); };
    try {
      const regions = findLabelRegions(img);
      let det = null, reader = null;
      if ("BarcodeDetector" in window) {
        try {
          const sup = await window.BarcodeDetector.getSupportedFormats();
          det = new window.BarcodeDetector({ formats: IMG_FORMATS.filter((f) => sup.includes(f)) });
        } catch (_) { det = null; }
      }
      if (window.ZXing) {
        const hints = new Map();
        hints.set(window.ZXing.DecodeHintType.TRY_HARDER, true);
        reader = new window.ZXing.MultiFormatReader();
        reader.setHints(hints);
      }
      const tryCanvas = async (cv) => {
        if (det) { try { (await det.detect(cv)).forEach((c) => { if (c.format !== "itf" || itfOk(cleanCode(c.rawValue))) add(c.rawValue); }); } catch (_) {} }
        if (reader) (await decodeWithZXing(cv, reader)).forEach(add);
      };
      // 1) whole picture (native detector can find several codes at once)
      if (det) for (const maxSide of [2400, 1400]) { await tryCanvas(toCanvas(img, 0, 0, W, H, maxSide)); if (found.size) break; }
      // 2) every label / paper area: cropped, enlarged, contrast-enhanced
      for (const r of regions) {
        const before = found.size;
        const rc = toCanvas(img, r.x, r.y, r.w, r.h, 1600, 900);
        const nat = toCanvas(img, r.x, r.y, r.w, r.h, 1200, 400);
        const bars = findBars(nat);
        if (bars) {
          const k = r.w / nat.width;  // canvas -> image scale
          const bx = Math.round(r.x + bars.x * k), by = Math.round(r.y + bars.y * k);
          const bw = Math.round(bars.w * k), bh = Math.round(bars.h * k);
          // small barcodes (≈1px per bar) are sensitive to the enlarge factor: try several
          for (const minSide of [700, 520, 900, 1100]) {
            await tryCanvas(padCanvas(toCanvas(img, bx, by, bw, bh, 1600, minSide)));
            if (found.size > before) break;
            await tryCanvas(padCanvas(enhance(toCanvas(img, bx, by, bw, bh, 1600, minSide))));
            if (found.size > before) break;
          }
        }
        if (found.size === before) await tryCanvas(rc);
        if (found.size === before) await tryCanvas(enhance(toCanvas(img, r.x, r.y, r.w, r.h, 1600, 1200)));
        // text above/below the bars can confuse the decoder: try overlapping horizontal strips of the label
        if (found.size === before) {
          for (const frac of [0.4, 0.28]) {
            for (let t = 0; t + frac <= 1.001 && found.size === before; t += frac / 2) {
              await tryCanvas(toCanvas(img, r.x, r.y + r.h * t, r.w, r.h * frac, 1600, 700));
            }
          }
        }
      }
      // 3) nothing yet: whole picture at several sizes, then overlapping tiles
      if (!found.size && reader) {
        for (const maxSide of [1600, 2400, 1000]) { (await decodeWithZXing(toCanvas(img, 0, 0, W, H, maxSide), reader)).forEach(add); if (found.size) break; }
        if (!found.size) for (const n of [2, 3]) {
          const tw = W / n, th = H / n;
          for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) {
            const sx = Math.max(0, x * tw - tw * 0.2), sy = Math.max(0, y * th - th * 0.2);
            const sw = Math.min(W - sx, tw * 1.4), sh = Math.min(H - sy, th * 1.4);
            (await decodeWithZXing(toCanvas(img, sx, sy, sw, sh, 1400), reader)).forEach(add);
            for (let t = 0; t < 1; t += 0.25) (await decodeWithZXing(toCanvas(img, sx, sy + sh * t, sw, sh * 0.35, 1400), reader)).forEach(add);
          }
        }
      }
    } finally {
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    }
    return { codes: [...found].filter((c) => c && c.length <= 64), url };
  }

  async function scanImageFile(file) {
    if (!file) return;
    stopCamera();
    show("viewLoading");
    let res;
    try { res = await decodeImage(file); }
    catch (_) { res = { codes: [], url: "" }; }
    const codes = res.codes;
    if (!codes.length) {
      $("resultBody").innerHTML = '<div class="msg nf">' + ICON_NF + "<h2>لم يتم العثور على باركود في الصورة</h2>" +
        (res.url ? '<img class="img-preview" src="' + res.url + '" alt="">' : "") +
        '<p class="muted">قرّب الكاميرا من الباركود، وخلّي الصورة واضحة ومنوّرة ومن غير انعكاس، والباركود كله جوه الصورة.</p></div>';
      show("viewResult");
      return;
    }
    if (codes.length === 1) { search(codes[0]); return; }
    // several barcodes: look all of them up and list them
    const fid = $("scanFilter").value;
    const rows = await Promise.all(codes.slice(0, 40).map(async (code) => {
      try { const r = await api(itemUrl(code, fid)); return { code, status: r.status, body: r.body || {} }; }
      catch (_) { return { code, status: 0, body: {} }; }
    }));
    const ok = rows.filter((r) => r.status === 200).length;
    $("resultBody").innerHTML =
      '<div class="multi-head">' + (res.url ? '<img class="img-preview" src="' + res.url + '" alt="">' : "") +
      "<h2>تم العثور على " + rows.length + " باركود في الصورة</h2><p class=\"muted small\">" + ok + " موجودة في المخزون — اضغط على أي قطعة لعرض تفاصيلها</p></div>" +
      '<div class="list multi-list">' + rows.map((r) => {
        const f = (r.body.item && r.body.item.fields) || {};
        const res2 = r.status === 200 ? '<span class="badge ok">موجودة</span>' : r.status === 404 ? '<span class="badge">غير موجودة</span>' : r.status === 409 ? '<span class="badge err">مكرر</span>' : '<span class="badge err">خطأ</span>';
        return '<div class="li' + (r.status === 409 ? " dup" : "") + '" data-code="' + esc(r.code) + '"><div class="main"><div class="bc">' + esc(r.code) + "</div>" +
          '<div class="nm">' + esc(f.item_name || "") + "</div>" +
          '<div class="meta">' + [f.karat && "عيار " + esc(f.karat), f.weight && fmtNum(f.weight) + " جم", f.price && fmtNum(f.price) + " ر.س"].filter(Boolean).join(" • ") + "</div></div>" +
          '<div class="res">' + res2 + "</div></div>";
      }).join("") + "</div>" +
      '<div class="copy-row"><button type="button" class="btn outline small-btn" id="copyImgCodes">نسخ كل الباركودات</button></div>';
    state.lastImageCodes = rows.map((r) => r.code);
    show("viewResult");
  }
  document.querySelectorAll(".imgInput").forEach((inp) => inp.addEventListener("change", () => {
    const f = inp.files && inp.files[0];
    inp.value = "";
    scanImageFile(f);
  }));
  $("resultBody").addEventListener("click", (e) => {
    if (e.target.closest("#copyImgCodes")) { copyText((state.lastImageCodes || []).join("\n"), "تم نسخ الباركودات"); return; }
    const row = e.target.closest(".multi-list [data-code]");
    if (row) search(row.dataset.code);
  });

  // ---------- copy / paste ----------
  function toast(msg) {
    const t = document.createElement("div");
    t.className = "toast"; t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 1600);
  }
  async function copyText(text, okMsg) {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
      else {
        const ta = document.createElement("textarea");
        ta.value = text; ta.setAttribute("readonly", ""); ta.style.position = "fixed"; ta.style.opacity = "0";
        document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove();
      }
      toast(okMsg || "تم النسخ");
      return true;
    } catch (_) { toast("تعذر النسخ"); return false; }
  }
  function itemText(it) {
    const lines = [];
    Object.keys(it.fields).forEach((k) => { if (it.fields[k] !== "") lines.push((it.labels[k] || k) + ": " + it.fields[k]); });
    it.extra.forEach((x) => lines.push(x.label + ": " + x.value));
    if (it.location && it.location.file) lines.push("الملف: " + it.location.file);
    return lines.join("\n");
  }
  $("resultBody").addEventListener("click", (e) => {
    const b = e.target.closest("[data-copy]");
    if (!b || !state.lastItem) return;
    if (b.dataset.copy === "barcode") copyText(state.lastItem.fields.barcode, "تم نسخ الباركود");
    else copyText(itemText(state.lastItem), "تم نسخ التفاصيل");
  });
  $("pasteBtn").addEventListener("click", async () => {
    try {
      const t = (await navigator.clipboard.readText()).trim().split(/\s+/)[0] || "";
      if (!t) { toast("الحافظة فارغة"); return; }
      $("barcode").value = t;
      search(t);
    } catch (_) {
      // Clipboard read not allowed (http or permission): let the user paste manually
      state.manualKeyboard = true; $("kbdToggle").classList.add("on");
      focusInput(); toast("اضغط مطولاً داخل الخانة ثم اختر لصق");
    }
  });
  // Pasting a barcode into the field (Ctrl+V or long-press) searches immediately
  $("barcode").addEventListener("paste", (e) => {
    const t = ((e.clipboardData || window.clipboardData).getData("text") || "").trim().split(/\s+/)[0];
    if (t) { e.preventDefault(); $("barcode").value = t; search(t); }
  });

  // ---------- files (filters + management) ----------
  state.files = [];
  function fillFilter(sel, keep) {
    const cur = keep ? sel.value : "";
    sel.innerHTML = '<option value="">كل الملفات (' + state.files.length + ")</option>" +
      state.files.map((f) => '<option value="' + esc(f.id) + '">' + esc(f.name) + " — " + esc(f.records) + " قطعة</option>").join("");
    if ([...sel.options].some((o) => o.value === cur)) sel.value = cur;
  }
  async function loadFiles() {
    try {
      const r = await api("/api/files");
      if (r.status !== 200) return;
      state.files = r.body.files || [];
      fillFilter($("scanFilter"), true);
      fillFilter($("listFilter"), true);
      $("scanFilterWrap").hidden = state.files.length < 2;
      $("filesList").innerHTML = state.files.length ? state.files.map((f) =>
        '<div class="fi' + (f.state !== "ok" ? " err" : "") + '"><div class="main"><div class="nm">' + esc(f.name) + '</div><div class="meta">' +
        esc(f.records) + " قطعة" + (f.duplicate_barcodes ? " • " + esc(f.duplicate_barcodes) + " مكرر" : "") +
        " • " + esc(fmtTime(f.loaded_at)) + (f.last_error ? '<br><span style="color:#ffb3b1">' + esc(f.last_error) + "</span>" : "") +
        '</div></div><button type="button" class="btn outline small-btn" data-dl-src="' + esc(f.id) + '" data-name="' + esc(f.name) + '">تنزيل</button>' +
        '<button type="button" class="del-btn" data-del="' + esc(f.id) + '" data-name="' + esc(f.name) + '">حذف</button></div>').join("")
        : '<p class="muted small">لا توجد ملفات</p>';
    } catch (_) {}
  }
  $("filesList").addEventListener("click", async (e) => {
    const d = e.target.closest("[data-dl-src]");
    if (d) {
      const k = $("adminKey").value.trim() || sessionStorage.getItem(ADMIN_STORE) || "";
      try { await downloadBlob("/api/files/" + d.dataset.dlSrc + "/download", { headers: Object.assign({ "X-Access-Key": state.key }, k ? { "X-Admin-Key": k } : {}) }, d.dataset.name); }
      catch (err) { toast(err.message); }
      return;
    }
    const b = e.target.closest("[data-del]");
    if (!b) return;
    const admin = $("adminKey").value.trim();
    if (!admin && state.cfg.admin_required) { toast("أدخل مفتاح المسؤول أولاً"); $("adminKey").focus(); return; }
    if (!confirm("حذف الملف \"" + b.dataset.name + "\"؟ (تُحفظ نسخة احتياطية على الخادم)")) return;
    try {
      const res = await fetch(BASE + "/api/admin/files/" + b.dataset.del, {
        method: "DELETE", headers: { "X-Access-Key": state.key, "X-Admin-Key": admin } });
      let body = {}; try { body = await res.json(); } catch (_) {}
      if (res.ok) { try { sessionStorage.setItem(ADMIN_STORE, admin); } catch (_) {} toast("تم حذف الملف"); loadFiles(); pollHealth(); loadDemo(); }
      else toast(body.detail || "تعذر الحذف");
    } catch (_) { toast("تعذر الاتصال بالخادم"); }
  });

  // ---------- all barcodes list ----------
  const list = { page: 1, pages: 1, items: [], timer: null };
  function listQuery(extra) {
    const q = $("listSearch").value.trim(), fid = $("listFilter").value;
    return "/api/items?" + extra + (q ? "&q=" + encodeURIComponent(q) : "") + (fid ? "&file=" + encodeURIComponent(fid) : "");
  }
  async function loadList() {
    $("listBody").innerHTML = '<div class="spinner" style="margin:30px auto"></div>';
    try {
      const r = await api(listQuery("per_page=100&page=" + list.page));
      if (r.status === 401) { askKey(true); return; }
      const b = r.body || {};
      list.items = b.items || []; list.pages = b.pages || 1;
      $("listCount").textContent = (b.total || 0) + " قطعة";
      $("listBody").innerHTML = list.items.length ? list.items.map((it, i) =>
        '<div class="li' + (it.duplicate ? " dup" : "") + '" data-i="' + i + '"><div class="main">' +
        '<div class="bc">' + esc(it.barcode) + (it.duplicate ? ' <span class="badge err">مكرر</span>' : "") + "</div>" +
        '<div class="nm">' + esc(it.item_name || "—") + "</div>" +
        '<div class="meta">' + [it.karat && "عيار " + esc(it.karat), it.weight && fmtNum(it.weight) + " جم", it.price && fmtNum(it.price) + " ر.س", esc(it.file)].filter(Boolean).join(" • ") + "</div>" +
        '</div><button type="button" class="copy-btn" data-c="' + i + '" title="نسخ الباركود" aria-label="نسخ الباركود">' +
        '<svg viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" fill="none" stroke="currentColor" stroke-width="1.7"/></svg></button></div>').join("")
        : '<p class="muted center">لا توجد نتائج</p>';
      $("listPager").hidden = list.pages <= 1;
      $("pgInfo").textContent = "صفحة " + list.page + " من " + list.pages;
      $("pgPrev").disabled = list.page <= 1; $("pgNext").disabled = list.page >= list.pages;
    } catch (_) {
      $("listBody").innerHTML = '<p class="error">تعذر الاتصال بالخادم</p>';
    }
  }
  $("listOpen").addEventListener("click", () => { show("viewList"); list.page = 1; loadFiles().then(loadList); });
  $("listClose").addEventListener("click", () => show("viewScan"));
  $("listSearch").addEventListener("input", () => { clearTimeout(list.timer); list.timer = setTimeout(() => { list.page = 1; loadList(); }, 300); });
  $("listFilter").addEventListener("change", () => { list.page = 1; loadList(); });
  $("pgPrev").addEventListener("click", () => { if (list.page > 1) { list.page--; loadList(); } });
  $("pgNext").addEventListener("click", () => { if (list.page < list.pages) { list.page++; loadList(); } });
  $("listBody").addEventListener("click", (e) => {
    const c = e.target.closest("[data-c]");
    if (c) { const it = list.items[+c.dataset.c]; copyText(it.barcode, "تم نسخ " + it.barcode); c.classList.add("done"); return; }
    const row = e.target.closest("[data-i]");
    if (row) {
      const it = list.items[+row.dataset.i];
      // a duplicated barcode opens the copy from this row's file
      if (it.duplicate) $("scanFilter").value = it.file_id;
      search(it.barcode);
    }
  });
  async function fetchAllRows() {
    const first = await api(listQuery("per_page=1000&page=1"), { timeout: 20000 });
    let rows = (first.body && first.body.items) || [];
    const pages = (first.body && first.body.pages) || 1;
    for (let p = 2; p <= pages; p++) {
      const rp = await api(listQuery("per_page=1000&page=" + p), { timeout: 20000 });
      rows = rows.concat((rp.body && rp.body.items) || []);
    }
    return rows;
  }
  $("copyBarcodes").addEventListener("click", async () => {
    const rows = await fetchAllRows();
    copyText(rows.map((r) => r.barcode).join("\n"), "تم نسخ " + rows.length + " باركود");
  });
  $("copyTable").addEventListener("click", async () => {
    const rows = await fetchAllRows();
    const clean = (v) => String(v == null ? "" : v).replace(/[\t\r\n]+/g, " ");
    const head = ["الباركود", "اسم القطعة", "الفئة", "العيار", "الوزن", "السعر", "الحالة", "الملف"];
    const tsv = [head.join("\t")].concat(rows.map((r) =>
      [r.barcode, r.item_name, r.category, r.karat, r.weight, r.price, r.status, r.file].map(clean).join("\t"))).join("\n");
    copyText(tsv, "تم نسخ " + rows.length + " صف — الصقه في Excel");
  });

  // ---------- live display: scanner -> laptop ----------
  const LIVE_STORE = "goldScanner.live";
  const live = (() => {
    let v = {};
    try { v = JSON.parse(load(LIVE_STORE) || "{}"); } catch (_) {}
    return { send: v.send !== false, channel: v.channel || "main", device: v.device || "" };
  })();
  function saveLive() { store(LIVE_STORE, JSON.stringify(live)); }
  function syncLiveUi() {
    $("liveSend").checked = live.send;
    $("liveFields").hidden = !live.send;
    $("liveChannel").value = live.channel;
    $("liveDevice").value = live.device;
  }
  syncLiveUi();
  $("liveSend").addEventListener("change", () => { live.send = $("liveSend").checked; saveLive(); syncLiveUi(); focusInput(); });
  $("liveChannel").addEventListener("change", () => { live.channel = $("liveChannel").value.trim() || "main"; saveLive(); syncLiveUi(); focusInput(); });
  $("liveDevice").addEventListener("change", () => { live.device = $("liveDevice").value.trim(); saveLive(); focusInput(); });

  const disp = { channel: "main", after: 0, timer: null, busy: false, wake: null, history: [], start: 0, removed: new Set() };
  const sessKey = (ch) => "goldScanner.session." + ch;
  function loadSession(ch) {
    try { const s = JSON.parse(load(sessKey(ch)) || "{}"); return { start: s.start || 0, removed: new Set(s.removed || []) }; }
    catch (_) { return { start: 0, removed: new Set() }; }
  }
  function saveSession() { store(sessKey(disp.channel), JSON.stringify({ start: disp.start, removed: [...disp.removed] })); }
  function sessionRows() { return disp.history.filter((e) => e.id > disp.start && !disp.removed.has(e.id)); }
  function beep(ok) {
    try {
      const ctx = disp.audio || (disp.audio = new (window.AudioContext || window.webkitAudioContext)());
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = ok ? 880 : 300; g.gain.value = 0.08;
      o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + (ok ? 0.12 : 0.3));
    } catch (_) {}
  }
  function eventLabel(ev) {
    const b = ev.body || {};
    if (ev.status === 200 && b.item) return esc(b.item.fields.item_name || "—");
    if (ev.status === 404) return "غير موجودة";
    if (ev.status === 409) return "باركود مكرر";
    return "خطأ";
  }
  function showEvent(ev, isNew) {
    const when = new Date(ev.ts);
    $("displayBody").innerHTML =
      '<div class="display-meta"><span>' + (ev.device ? "من: " + esc(ev.device) : "") + "</span><span>" +
      esc(isNaN(when) ? "" : when.toLocaleTimeString("ar-SA-u-nu-latn")) + "</span></div>" +
      buildResultHtml(ev.status, ev.body || {}, ev.barcode);
    if (isNew) {
      const card = $("displayBody").querySelector(".item-hero, .msg");
      if (card) { card.classList.remove("flash"); void card.offsetWidth; card.classList.add("flash"); }
      beep(ev.status === 200);
    }
    $("displayStatus").textContent = "آخر مسح: " + (isNaN(when) ? "" : when.toLocaleTimeString("ar-SA-u-nu-latn"));
  }
  function renderHistory(newId) {
    const rows = sessionRows();
    let w = 0, n = 0;
    rows.forEach((ev) => { const f = (ev.body && ev.body.item && ev.body.item.fields) || {}; if (ev.status === 200) { n++; w += parseFloat(f.weight) || 0; } });
    $("tableSummary").textContent = rows.length ? "— " + rows.length + " مسح • " + n + " موجودة • إجمالي الوزن " + fmtNum(w.toFixed(3)) + " جم" : "";
    $("scanTableBody").innerHTML = rows.length ? rows.slice().reverse().map((ev, i) => {
      const f = (ev.body && ev.body.item && ev.body.item.fields) || {};
      const res = ev.status === 200 ? "موجودة" : ev.status === 404 ? "غير موجودة" : ev.status === 409 ? "مكرر" : "خطأ";
      return '<tr data-ev="' + ev.id + '" class="' + (ev.status !== 200 ? "bad " : "") + (ev.id === newId ? "new" : "") + '">' +
        "<td>" + (rows.length - i) + "</td>" +
        "<td>" + esc(new Date(ev.ts).toLocaleTimeString("ar-SA-u-nu-latn")) + "</td>" +
        '<td class="bc">' + esc(f.barcode || ev.barcode) + "</td>" +
        '<td class="nm">' + esc(f.item_name || "") + "</td>" +
        "<td>" + esc(f.karat || "") + "</td>" +
        "<td>" + (f.weight ? fmtNum(f.weight) : "") + "</td>" +
        "<td>" + (f.price ? fmtNum(f.price) : "") + "</td>" +
        "<td>" + res + "</td>" +
        '<td><button type="button" class="rm" data-rm="' + ev.id + '" title="حذف من الجدول" aria-label="حذف من الجدول">✕</button></td></tr>';
    }).join("") : '<tr class="empty"><td colspan="9">لا توجد عمليات مسح بعد</td></tr>';
  }
  async function pollDisplay(first) {
    if (disp.busy) return;
    disp.busy = true;
    try {
      const r = await api("/api/live?channel=" + encodeURIComponent(disp.channel) + "&after=" + disp.after, { timeout: 6000 });
      if (r.status === 401) { askKey(true); return; }
      if (r.status === 200) {
        state.online = true;
        const evs = r.body.events || [];
        if (r.body.last_id < disp.after) disp.after = 0;          // feed was reset
        if (evs.length) {
          disp.history = disp.history.concat(evs).slice(-1000);
          const last = evs[evs.length - 1];
          if (!first || last.id > disp.start) showEvent(last, !first);
          renderHistory(first ? null : last.id);
        }
        disp.after = Math.max(disp.after, r.body.last_id || 0);
      }
    } catch (_) {
      state.online = false; renderStatus();
    } finally {
      disp.busy = false;
    }
  }
  async function openDisplay(channel) {
    disp.channel = (channel || live.channel || "main").trim() || "main";
    disp.after = 0; disp.history = [];
    disp.ready = false;
    const sess = loadSession(disp.channel);
    disp.start = sess.start; disp.removed = sess.removed;
    $("displayChannel").value = disp.channel;
    renderHistory();
    show("viewDisplay");
    history.replaceState(null, "", location.pathname + "?display=" + encodeURIComponent(disp.channel));
    await pollDisplay(true);
    disp.ready = true;
    clearInterval(disp.timer);
    disp.timer = setInterval(() => pollDisplay(false), 1000);
    try { if (navigator.wakeLock) disp.wake = await navigator.wakeLock.request("screen"); } catch (_) {}
  }
  function stopDisplay() {
    clearInterval(disp.timer); disp.timer = null;
    try { if (disp.wake) disp.wake.release(); } catch (_) {}
    disp.wake = null;
    history.replaceState(null, "", location.pathname);
  }
  $("displayOpen").addEventListener("click", () => { openDisplay(live.channel); loadFiles(); loadCounts(); });
  $("displayClose").addEventListener("click", () => show("viewScan"));
  $("displayChannel").addEventListener("change", () => openDisplay($("displayChannel").value));
  $("scanTableBody").addEventListener("click", (e) => {
    const rm = e.target.closest("[data-rm]");
    if (rm) { disp.removed.add(+rm.dataset.rm); saveSession(); renderHistory(); return; }
    const row = e.target.closest("[data-ev]");
    if (!row) return;
    const ev = disp.history.find((x) => String(x.id) === row.dataset.ev);
    if (ev) { showEvent(ev, false); window.scrollTo({ top: 0, behavior: "smooth" }); }
  });
  $("newSession").addEventListener("click", () => {
    if (!disp.ready) { toast("لحظة… جاري تحميل الجدول"); return; }
    if (sessionRows().length && !confirm("بدء جدول جديد؟ الجدول الحالي هيتمسح من الشاشة (احفظه Excel الأول لو محتاجه).")) return;
    disp.start = disp.after; disp.removed = new Set(); saveSession(); renderHistory();
    $("displayBody").innerHTML = '<div class="display-wait"><p>جدول جديد — امسح قطعة من جهاز Honeywell</p></div>';
  });
  $("exportExcel").addEventListener("click", async () => {
    const ids = sessionRows().map((e) => e.id);
    if (!ids.length) { toast("الجدول فاضي"); return; }
    const btn = $("exportExcel"); btn.disabled = true; btn.textContent = "جارٍ الحفظ…";
    try {
      const res = await fetch(BASE + "/api/live/export", {
        method: "POST",
        headers: { "X-Access-Key": state.key, "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ channel: disp.channel, ids }),
      });
      if (!res.ok) { let b = {}; try { b = await res.json(); } catch (_) {} toast(b.detail || "تعذر الحفظ"); return; }
      const blob = await res.blob();
      const cd = res.headers.get("Content-Disposition") || "";
      const m = cd.match(/filename\*=UTF-8''([^;]+)/i) || cd.match(/filename="?([^";]+)"?/i);
      const name = m ? decodeURIComponent(m[1]) : "scans.xlsx";
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob); a.download = name;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      toast("تم حفظ " + ids.length + " صف في ملف Excel");
    } catch (_) { toast("تعذر الاتصال بالخادم"); }
    finally { btn.disabled = false; btn.textContent = "حفظ Excel"; }
  });
  document.addEventListener("visibilitychange", async () => {
    if (!document.hidden && state.view === "viewDisplay" && navigator.wakeLock && !disp.wake) {
      try { disp.wake = await navigator.wakeLock.request("screen"); } catch (_) {}
    }
  });

  // ---------- stock counts (جرد): save on the system, as a standalone Excel or as sheets inside the inventory file ----------
  async function downloadBlob(url, opts, fallbackName) {
    const res = await fetch(BASE + url, opts);
    if (!res.ok) { let b = {}; try { b = await res.json(); } catch (_) {} throw new Error(b.detail || ("خطأ " + res.status)); }
    const blob = await res.blob();
    const cd = res.headers.get("Content-Disposition") || "";
    const m = cd.match(/filename\*=UTF-8''([^;]+)/i) || cd.match(/filename="?([^";]+)"?/i);
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob); a.download = m ? decodeURIComponent(m[1]) : fallbackName;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  }
  function adminHeaders() {
    const k = $("countAdmin").value.trim() || sessionStorage.getItem(ADMIN_STORE) || "";
    return k ? { "X-Admin-Key": k } : {};
  }
  function countMode() { return document.querySelector('input[name="countMode"]:checked').value; }
  function syncCountUi() {
    const sheet = countMode() === "sheet";
    $("countAdmin").hidden = !(sheet && state.cfg.admin_required);
    if (sheet && !$("countFile").value && state.files.length) $("countFile").value = state.files[0].id;
  }
  document.querySelectorAll('input[name="countMode"]').forEach((r) => r.addEventListener("change", syncCountUi));
  $("saveCountOpen").addEventListener("click", () => {
    if (!sessionRows().length) { toast("الجدول فاضي — امسح قطع الأول"); return; }
    const d = new Date();
    if (!$("countName").value) $("countName").value = "جرد " + d.toLocaleDateString("ar-SA-u-nu-latn-ca-gregory");
    $("countFile").innerHTML = '<option value="">كل الملفات</option>' +
      state.files.map((f) => '<option value="' + esc(f.id) + '">' + esc(f.name) + "</option>").join("");
    $("countMsg").hidden = true;
    $("countPanel").hidden = false;
    syncCountUi();
    $("countPanel").scrollIntoView({ behavior: "smooth", block: "center" });
  });
  $("countCancel").addEventListener("click", () => { $("countPanel").hidden = true; });
  $("countSave").addEventListener("click", async () => {
    const ids = sessionRows().map((e) => e.id);
    const mode = countMode(), fileId = $("countFile").value;
    const msg = $("countMsg");
    const say = (ok, html) => { msg.className = ok ? "up-ok" : "up-err"; msg.innerHTML = html; msg.hidden = false; };
    if (mode === "sheet" && !fileId) return say(false, "اختار ملف الإكسل اللي هتتضاف فيه صفحة الجرد.");
    if (mode === "sheet" && state.cfg.admin_required && !Object.keys(adminHeaders()).length) return say(false, "أدخل مفتاح المسؤول.");
    const btn = $("countSave"); btn.disabled = true; btn.textContent = "جارٍ الحفظ…";
    try {
      const r = await fetch(BASE + "/api/counts", {
        method: "POST",
        headers: { "X-Access-Key": state.key, "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ name: $("countName").value.trim(), channel: disp.channel, ids, file_id: fileId }),
      });
      const b = await r.json().catch(() => ({}));
      if (!r.ok) return say(false, "✖ " + esc(b.detail || "تعذر الحفظ"));
      const c = b.count, s = c.summary;
      let html = "✔ تم حفظ <b>" + esc(c.name) + "</b> على السيستم.<br>" +
        "ممسوح: " + s.unique + " • موجود: " + s.found + " • غير موجود: " + s.not_found + " • ناقص: " + s.missing;
      if (mode === "file") {
        await downloadBlob("/api/counts/" + c.id + "/excel", { headers: { "X-Access-Key": state.key } }, c.name + ".xlsx");
        html += "<br>تم تنزيل ملف Excel باسم الجرد.";
      } else {
        const a = await fetch(BASE + "/api/counts/" + c.id + "/append", {
          method: "POST",
          headers: Object.assign({ "X-Access-Key": state.key, "Content-Type": "application/json", "Accept": "application/json" }, adminHeaders()),
          body: JSON.stringify({ file_id: fileId }),
        });
        const ab = await a.json().catch(() => ({}));
        if (!a.ok) return say(false, html.replace("✔", "⚠") + "<br>✖ لم تتم إضافة الصفحة للملف: " + esc(ab.detail || a.status));
        try { const k = $("countAdmin").value.trim(); if (k) sessionStorage.setItem(ADMIN_STORE, k); } catch (_) {}
        html += "<br>تمت إضافة صفحتين لملف <b>" + esc(ab.file) + "</b>: " + ab.sheets.map(esc).join(" و ") +
          '<br><button type="button" class="btn outline small-btn" data-dl-file="' + esc(ab.file_id) + '" data-name="' + esc(ab.file) + '">تنزيل الملف بعد الإضافة</button>';
      }
      say(true, html);
      loadCounts();
    } catch (e) {
      say(false, "✖ " + esc(e.message || "تعذر الاتصال بالخادم"));
    } finally {
      btn.disabled = false; btn.textContent = "حفظ";
    }
  });
  $("countMsg").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-dl-file]");
    if (!b) return;
    try { await downloadBlob("/api/files/" + b.dataset.dlFile + "/download", { headers: Object.assign({ "X-Access-Key": state.key }, adminHeaders()) }, b.dataset.name); }
    catch (err) { toast(err.message); }
  });
  async function loadCounts() {
    try {
      const r = await api("/api/counts");
      if (r.status !== 200) return;
      const list = r.body.counts || [];
      $("countsList").innerHTML = list.length ? list.map((c) => {
        const s = c.summary || {};
        const when = new Date(c.created_at);
        return '<div class="count-item"><div class="main"><div class="nm">' + esc(c.name) + "</div>" +
          '<div class="meta">' + esc(isNaN(when) ? "" : when.toLocaleString("ar-SA-u-nu-latn-ca-gregory")) + " • ممسوح " + (s.unique || 0) +
          " • موجود " + (s.found || 0) + " • ناقص " + (s.missing || 0) +
          ((c.appended || []).length ? " • مضاف لملف " + esc(c.appended[c.appended.length - 1].file) : "") + "</div></div>" +
          '<div class="acts"><button type="button" class="btn outline small-btn" data-dl-count="' + esc(c.id) + '" data-name="' + esc(c.name) + '">Excel</button></div></div>';
      }).join("") : '<p class="muted small">لا توجد جرودات محفوظة</p>';
    } catch (_) {}
  }
  $("countsList").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-dl-count]");
    if (!b) return;
    try { await downloadBlob("/api/counts/" + b.dataset.dlCount + "/excel", { headers: { "X-Access-Key": state.key } }, b.dataset.name + ".xlsx"); }
    catch (err) { toast(err.message); }
  });

  // ---------- demo panel ----------
  async function loadDemo() {
    if (!state.cfg.demo_mode) { $("demoPanel").hidden = true; return; }
    try {
      const r = await api("/api/demo/barcodes");
      if (r.status !== 200) return;
      $("demoChips").innerHTML = r.body.barcodes.map((b) =>
        '<button type="button" class="chip" data-code="' + esc(b.barcode) + '"><b>' + esc(b.barcode) + "</b>" + esc(b.name) + "</button>").join("");
      $("demoPanel").hidden = false;
    } catch (_) {}
  }
  $("demoChips").addEventListener("click", (e) => {
    const btn = e.target.closest(".chip");
    if (btn) search(btn.dataset.code);
  });

  // ---------- boot ----------
  let pollTimer = null;
  async function startApp() {
    show("viewScan");
    $("uploadOpen").hidden = !state.cfg.upload_enabled;
    $("adminKey").hidden = !state.cfg.admin_required;
    await pollHealth();
    loadFiles();
    loadDemo();
    clearInterval(pollTimer);
    pollTimer = setInterval(pollHealth, Math.max(2, state.cfg.poll_interval_seconds || 3) * 1000);
    if (initialQuery) { search(initialQuery); initialQuery = ""; }
    if (initialDisplay !== null) { openDisplay(initialDisplay || live.channel); loadCounts(); initialDisplay = null; }
  }
  // Optional ?q=BARCODE opens a lookup directly (handy for testing/bookmarks).
  let initialQuery = new URLSearchParams(location.search).get("q") || "";
  let initialDisplay = new URLSearchParams(location.search).get("display");

  async function boot() {
    // Access key can be provided once via ?key=... (e.g. a bookmark on the scanner), then stored locally.
    const params = new URLSearchParams(location.search);
    if (params.get("key")) {
      store(KEY_STORE, params.get("key"));
      history.replaceState(null, "", location.pathname);
    }
    state.key = load(KEY_STORE);
    try {
      const r = await api("/api/config/status");
      state.cfg = r.body;
      state.online = true;
    } catch (_) {
      state.online = false;
      renderStatus();
      setTimeout(boot, 4000);
      return;
    }
    if (state.cfg.auth_required && !state.key) { askKey(false); return; }
    startApp();
  }
  boot();
})();
