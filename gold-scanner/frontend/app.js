/* ماسح الذهب — frontend (vanilla JS, no external dependencies) */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
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
      const res = await fetch(path, {
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
    ["viewKey", "viewScan", "viewCamera", "viewUpload", "viewLoading", "viewResult"].forEach((v) => ($(v).hidden = v !== view));
    if (state.view === "viewCamera" && view !== "viewCamera") stopCamera();
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
    const code = String(raw || "").trim();
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
      const r = await api("/api/item/" + encodeURIComponent(code));
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

  function renderResult(status, body, code) {
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
          staleNote(body) +
        "</article>" +
        '<section class="details"><dl>' +
          rows.map((r) => '<div class="r"><dt>' + r[0] + "</dt><dd>" + r[1] + "</dd></div>").join("") +
        "</dl></section>";
    } else if (status === 404) {
      html = '<div class="msg nf">' + ICON_NF + "<h2>القطعة غير موجودة في ملف المخزون</h2>" +
        '<span class="code">' + esc(code) + "</span>" + staleNote(body) + "</div>";
    } else if (status === 409) {
      const locs = (body.locations || []).map((l) => "<li>ورقة " + esc(l.sheet) + " — صف " + esc(l.row) + "</li>").join("");
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
    $("resultBody").innerHTML = html;
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
  const cam = { stream: null, reader: null, timer: null, done: false };
  const FORMATS = ["code_128", "ean_13", "ean_8", "upc_a", "upc_e", "code_39", "code_93", "itf", "codabar", "qr_code", "data_matrix"];

  function camFail(msg) {
    $("camError").innerHTML = msg;
    $("camError").hidden = false;
    $("camHint").hidden = true;
  }

  function onCameraCode(text) {
    if (cam.done || !text) return;
    cam.done = true;
    if (navigator.vibrate) navigator.vibrate(80);
    stopCamera();
    search(String(text).trim());
  }

  async function openCamera() {
    $("camError").hidden = true;
    $("camHint").hidden = false;
    cam.done = false;
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
          video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
        video.srcObject = cam.stream;
        await video.play();
        const tick = async () => {
          if (cam.done || !cam.stream) return;
          try {
            const codes = await native.detect(video);
            if (codes && codes.length) return onCameraCode(codes[0].rawValue);
          } catch (_) {}
          cam.timer = setTimeout(tick, 120);
        };
        tick();
      } else if (window.ZXing) {
        const hints = new Map();
        hints.set(window.ZXing.DecodeHintType.TRY_HARDER, true);
        cam.reader = new window.ZXing.BrowserMultiFormatReader(hints, 150);
        await cam.reader.decodeFromConstraints(
          { video: { facingMode: { ideal: "environment" } }, audio: false }, video,
          (result) => { if (result) onCameraCode(result.getText()); });
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
  });
  $("uploadClose").addEventListener("click", () => show("viewScan"));
  $("fileInput").addEventListener("change", () => {
    const f = $("fileInput").files[0];
    $("fileName").textContent = f ? f.name + " (" + (f.size / 1024).toFixed(0) + " KB)" : "اضغط لاختيار ملف Excel";
    $("filePick").classList.toggle("has", !!f);
  });
  $("uploadForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const f = $("fileInput").files[0];
    const msg = $("uploadMsg");
    const say = (ok, html) => { msg.className = ok ? "up-ok" : "up-err"; msg.innerHTML = html; msg.hidden = false; };
    if (!f) return say(false, "اختر ملف Excel أولاً.");
    if (!/\.xls$/i.test(f.name)) return say(false, "الملف يجب أن يكون بصيغة <b dir=\"ltr\">.xls</b> (في Excel: حفظ باسم ← Excel 97-2003 Workbook).");
    const admin = $("adminKey").value.trim();
    if (!admin) return say(false, "أدخل مفتاح المسؤول.");
    $("uploadBtn").disabled = true;
    $("uploadBtn").textContent = "جارٍ الرفع والفحص…";
    try {
      const res = await fetch("/api/admin/upload", {
        method: "POST",
        headers: { "X-Access-Key": state.key, "X-Admin-Key": admin, "Content-Type": "application/octet-stream" },
        body: f,
      });
      let body = {};
      try { body = await res.json(); } catch (_) {}
      if (res.ok) {
        try { sessionStorage.setItem(ADMIN_STORE, admin); } catch (_) {}
        let html = "✔ تم رفع الملف وتحديث البيانات بنجاح.<br>عدد القطع: <b>" + esc(body.records) + "</b>";
        if (body.duplicate_barcodes) html += "<br>⚠ يوجد " + esc(body.duplicate_barcodes) + " باركود مكرر في الملف.";
        say(true, html);
        $("fileInput").value = ""; $("fileName").textContent = "اضغط لاختيار ملف Excel"; $("filePick").classList.remove("has");
        pollHealth(); loadDemo();
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
    await pollHealth();
    loadDemo();
    clearInterval(pollTimer);
    pollTimer = setInterval(pollHealth, Math.max(2, state.cfg.poll_interval_seconds || 3) * 1000);
    if (initialQuery) { search(initialQuery); initialQuery = ""; }
  }
  // Optional ?q=BARCODE opens a lookup directly (handy for testing/bookmarks).
  let initialQuery = new URLSearchParams(location.search).get("q") || "";

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
