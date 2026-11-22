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
      const fid = $("scanFilter").value;
      const qs = [];
      if (fid) qs.push("file=" + encodeURIComponent(fid));
      if (live.send) qs.push("live=" + encodeURIComponent(live.channel || "main") + "&device=" + encodeURIComponent(live.device || ""));
      const r = await api("/api/item/" + encodeURIComponent(code) + (qs.length ? "?" + qs.join("&") : ""));
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
        '</div></div><button type="button" class="del-btn" data-del="' + esc(f.id) + '" data-name="' + esc(f.name) + '">حذف</button></div>').join("")
        : '<p class="muted small">لا توجد ملفات</p>';
    } catch (_) {}
  }
  $("filesList").addEventListener("click", async (e) => {
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
  $("displayOpen").addEventListener("click", () => openDisplay(live.channel));
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
    if (initialDisplay !== null) { openDisplay(initialDisplay || live.channel); initialDisplay = null; }
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
