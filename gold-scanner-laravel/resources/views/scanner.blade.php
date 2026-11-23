<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0f0f10">
  <meta name="robots" content="noindex, nofollow">
  <title>ماسح الذهب</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23d4af37' d='M6 3h12l4 6-10 12L2 9z'/%3E%3C/svg%3E">
  <link rel="stylesheet" href="{{ asset('static/styles.css') }}?v={{ filemtime(public_path('static/styles.css')) }}">
</head>
<body>
  <header class="topbar">
    <div class="brand">
      <svg class="logo" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12l4 6-10 12L2 9z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M2 9h20M9 3l3 18 3-18M6 3l3 6m9-6-3 6" fill="none" stroke="currentColor" stroke-width="1.1" opacity=".7"/></svg>
      <div>
        <h1>ماسح الذهب</h1>
        <div class="sync" id="syncText">جارٍ الاتصال…</div>
      </div>
    </div>
    <div class="top-actions">
    <a class="icon-btn help-link" href="{{ url('/help') }}" title="شرح الاستخدام" aria-label="شرح الاستخدام">؟</a>
    <button type="button" class="icon-btn" id="displayOpen" title="شاشة العرض" aria-label="شاشة العرض">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 20h8M12 16v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    </button>
    <button type="button" class="icon-btn" id="listOpen" title="كل الباركودات" aria-label="كل الباركودات">
      <svg viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <button type="button" class="icon-btn" id="uploadOpen" title="رفع ملف Excel" aria-label="رفع ملف Excel" hidden>
      <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <div class="conn" id="conn" data-state="loading" title="حالة الاتصال">
      <span class="dot"></span><span id="connText">اتصال</span>
    </div>
    </div>
  </header>

  <div class="banner" id="banner" hidden></div>

  <main>
    <!-- Access key -->
    <section class="view" id="viewKey" hidden>
      <div class="card center">
        <h2>مفتاح الدخول</h2>
        <p class="muted">أدخل مفتاح الدخول الذي حدده مسؤول النظام.</p>
        <form id="keyForm" class="row">
          <input id="keyInput" type="password" autocomplete="off" placeholder="مفتاح الدخول" dir="ltr">
          <button class="btn gold" type="submit">دخول</button>
        </form>
        <p class="error" id="keyError" hidden></p>
        <p class="small"><a class="inline-link" href="{{ url('/help') }}">شرح الاستخدام</a></p>
      </div>
    </section>

    <!-- Scan -->
    <section class="view" id="viewScan" hidden>
      <div class="scan-hero">
        <svg class="scan-icon" viewBox="0 0 64 64" aria-hidden="true">
          <path d="M6 18V8h10M48 8h10v10M58 46v10H48M16 56H6V46" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <g fill="currentColor" opacity=".9"><rect x="15" y="18" width="3" height="28"/><rect x="21" y="18" width="1.6" height="28"/><rect x="25" y="18" width="4" height="28"/><rect x="32" y="18" width="1.6" height="28"/><rect x="36" y="18" width="3" height="28"/><rect x="42" y="18" width="1.6" height="28"/><rect x="46" y="18" width="3" height="28"/></g>
          <line class="laser" x1="10" y1="32" x2="54" y2="32" stroke="#ff4d4d" stroke-width="1.5"/>
        </svg>
        <h2>امسح باركود القطعة</h2>
        <p class="muted small">وجّه الماسح نحو الباركود واضغط زر المسح</p>
      </div>
      <form id="scanForm" class="scan-form" autocomplete="off">
        <label class="file-filter" id="scanFilterWrap" hidden>
          <span>البحث في:</span>
          <select id="scanFilter" class="select"><option value="">كل الملفات</option></select>
        </label>
        <div class="input-wrap">
          <input id="barcode" name="barcode" type="text" dir="ltr" inputmode="none"
                 autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                 maxlength="64" placeholder="الباركود" aria-label="الباركود">
          <button type="button" class="kbd-toggle paste-btn" id="pasteBtn" title="لصق" aria-label="لصق">
            <svg viewBox="0 0 24 24"><rect x="6" y="4" width="12" height="17" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 4V3h6v1M9 10h6M9 14h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          </button>
          <button type="button" class="kbd-toggle" id="kbdToggle" title="إظهار لوحة المفاتيح" aria-label="إظهار لوحة المفاتيح">
            <svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6 10h1M10 10h1M14 10h1M18 10h1M7 14h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          </button>
        </div>
        <button class="btn gold big" type="submit" id="searchBtn">بحث</button>
        <button class="btn outline big" type="button" id="cameraBtn">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
          مسح بكاميرا الجوال
        </button>
        <label class="btn outline big img-pick">
          <input type="file" accept="image/*" class="imgInput" hidden>
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="1.8" fill="currentColor"/><path d="m4 17 5-5 4 4 3-3 4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          مسح من صورة (تصوير أو من المعرض)
        </label>
      </form>
      <p class="error" id="scanError" hidden></p>

      <div class="live-box">
        <label class="live-toggle"><input type="checkbox" id="liveSend"> <span>إظهار نتيجة المسح على شاشة الكمبيوتر</span></label>
        <div class="live-fields" id="liveFields">
          <label>القناة <input id="liveChannel" type="text" maxlength="40" dir="auto" placeholder="main"></label>
          <label>اسم الجهاز <input id="liveDevice" type="text" maxlength="40" dir="auto" placeholder="ماسح 1"></label>
        </div>
      </div>

      <aside class="demo" id="demoPanel" hidden>
        <div class="demo-head"><span class="tag">للتجربة فقط</span> باركودات تجريبية</div>
        <div class="chips" id="demoChips"></div>
      </aside>
    </section>

    <!-- Live display (laptop) -->
    <section class="view display" id="viewDisplay" hidden>
      <div class="display-head">
        <div>
          <h2>شاشة العرض</h2>
          <div class="muted small" id="displayStatus">في انتظار المسح…</div>
        </div>
        <label class="display-ch">القناة <input id="displayChannel" type="text" maxlength="40" dir="auto"></label>
      </div>
      <div id="displayBody" class="display-body">
        <div class="display-wait">
          <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M6 18V8h10M48 8h10v10M58 46v10H48M16 56H6V46" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><g fill="currentColor" opacity=".9"><rect x="15" y="18" width="3" height="28"/><rect x="21" y="18" width="1.6" height="28"/><rect x="25" y="18" width="4" height="28"/><rect x="32" y="18" width="1.6" height="28"/><rect x="36" y="18" width="3" height="28"/><rect x="42" y="18" width="1.6" height="28"/><rect x="46" y="18" width="3" height="28"/></g></svg>
          <p>امسح قطعة من جهاز Honeywell — النتيجة هتظهر هنا تلقائياً</p>
        </div>
      </div>
      <div class="scan-table-head">
        <h3>جدول المسح <span class="muted small" id="tableSummary"></span></h3>
        <div class="scan-table-actions">
          <button type="button" class="btn gold small-btn" id="saveCountOpen">حفظ الجرد</button>
          <button type="button" class="btn outline small-btn" id="exportExcel">Excel سريع</button>
          <button type="button" class="btn outline small-btn" id="newSession">جدول جديد</button>
        </div>
      </div>
      <div class="scan-table-wrap">
        <table class="scan-table">
          <thead><tr><th>#</th><th>الوقت</th><th>الباركود</th><th>القطعة</th><th>العيار</th><th>الوزن</th><th>السعر</th><th>النتيجة</th><th></th></tr></thead>
          <tbody id="scanTableBody"><tr class="empty"><td colspan="9">لا توجد عمليات مسح بعد</td></tr></tbody>
        </table>
      </div>

      <!-- Save stock count (جرد) -->
      <div class="card count-panel" id="countPanel" hidden>
        <h3>حفظ الجرد</h3>
        <label class="field">اسم الجرد <input id="countName" type="text" maxlength="80" dir="auto"></label>
        <label class="field">مقارنة مع (لمعرفة القطع الناقصة)
          <select id="countFile" class="select"><option value="">كل الملفات</option></select>
        </label>
        <div class="field">طريقة الحفظ في Excel</div>
        <label class="radio"><input type="radio" name="countMode" value="file" checked> <span><b>ملف Excel لوحده</b> — باسم الجرد</span></label>
        <label class="radio"><input type="radio" name="countMode" value="sheet"> <span><b>نسخة من ملف الإكسل</b> اللي اخترته فوق زي ما هو + صفحة الجرد في الآخر (الملف الأصلي مش بيتعدل)</span></label>
        <input id="countAdmin" type="password" autocomplete="off" placeholder="مفتاح المسؤول" dir="ltr" hidden>
        <p class="muted small">الجرد بيتحفظ على السيستم في كل الحالات، وتقدر تنزّله تاني من "الجرودات المحفوظة".</p>
        <div class="row">
          <button type="button" class="btn gold" id="countSave">حفظ</button>
          <button type="button" class="btn outline" id="countCancel">إلغاء</button>
        </div>
        <div id="countMsg" hidden></div>
      </div>

      <div class="saved-counts">
        <h3>الجرودات المحفوظة</h3>
        <div id="countsList" class="list"><p class="muted small">لا توجد جرودات محفوظة</p></div>
      </div>
      <button class="btn outline big" type="button" id="displayClose" style="margin-top:14px">رجوع للماسح</button>
    </section>

    <!-- All barcodes -->
    <section class="view" id="viewList" hidden>
      <div class="list-head">
        <h2>كل الباركودات</h2>
        <span class="muted small" id="listCount"></span>
      </div>
      <div class="list-tools">
        <input id="listSearch" type="search" placeholder="بحث بالباركود أو الاسم أو الفئة…" autocomplete="off">
        <select id="listFilter" class="select"><option value="">كل الملفات</option></select>
      </div>
      <div class="list-actions">
        <button type="button" class="btn outline small-btn" id="copyBarcodes">نسخ الباركودات</button>
        <button type="button" class="btn outline small-btn" id="copyTable">نسخ الجدول (للصق في Excel)</button>
      </div>
      <div class="list" id="listBody"></div>
      <div class="pager" id="listPager" hidden>
        <button type="button" class="btn outline small-btn" id="pgPrev">السابق</button>
        <span class="muted small" id="pgInfo"></span>
        <button type="button" class="btn outline small-btn" id="pgNext">التالي</button>
      </div>
      <button class="btn gold big" type="button" id="listClose" style="margin-top:14px">رجوع للمسح</button>
    </section>

    <!-- Camera -->
    <section class="view" id="viewCamera" hidden>
      <div class="cam-box">
        <video id="camVideo" playsinline muted></video>
        <div class="cam-frame"><span class="cam-laser"></span></div>
      </div>
      <p class="muted center small" id="camHint">وجّه الكاميرا نحو الباركود — سيتم البحث تلقائياً</p>
      <p class="error" id="camError" hidden></p>
      <label class="btn outline big img-pick cam-img">
          <input type="file" accept="image/*" class="imgInput" hidden>
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="1.8" fill="currentColor"/><path d="m4 17 5-5 4 4 3-3 4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
          مسح من صورة (تصوير أو من المعرض)
        </label>
      <button class="btn outline big" type="button" id="camClose">إلغاء</button>
    </section>

    <!-- Upload -->
    <section class="view" id="viewUpload" hidden>
      <div class="card">
        <h2 class="center">رفع ملف المخزون</h2>
        <p class="muted small">ارفع ملف Excel بصيغة <b dir="ltr">.xls</b> أو <b dir="ltr">.xlsx</b> أو <b dir="ltr">.csv</b>. يمكنك رفع أكثر من ملف، والبحث يشمل كل الملفات. يتم فحص الملف قبل حفظه، وإذا رفعت ملفاً بنفس الاسم يحل محل القديم مع حفظ نسخة احتياطية.</p>
        <form id="uploadForm" class="scan-form">
          <label class="file-pick" id="filePick">
            <input type="file" id="fileInput" accept=".xls,.xlsx,.csv,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            <span id="fileName">اضغط لاختيار ملف Excel أو CSV</span>
          </label>
          <input id="adminKey" type="password" autocomplete="off" placeholder="مفتاح المسؤول" dir="ltr">
          <button class="btn gold big" type="submit" id="uploadBtn">رفع وتحديث البيانات</button>
        </form>
        <div id="uploadMsg" hidden></div>
      </div>
      <div class="card files-card">
        <h3>الملفات المرفوعة</h3>
        <div id="filesList" class="files-list"><p class="muted small">لا توجد ملفات</p></div>
      </div>
      <button class="btn outline big" type="button" id="uploadClose" style="margin-top:12px">رجوع للمسح</button>
    </section>

    <!-- Loading -->
    <section class="view" id="viewLoading" hidden>
      <div class="spinner" aria-label="جارٍ البحث"></div>
    </section>

    <!-- Result -->
    <section class="view" id="viewResult" hidden>
      <div id="resultBody"></div>
      <button class="btn gold big sticky" id="newScanBtn" type="button">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7V4h3M18 4h3v3M21 17v3h-3M6 20H3v-3M7 8v8M10 8v8M13 8v8M16 8v8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        مسح قطعة جديدة
      </button>
    </section>
  </main>

  <footer class="foot">البيانات من آخر ملف Excel تم تحميله على الخادم</footer>
  <script>window.GS_BASE = @json(rtrim(url('/'), '/'));</script>
  <script src="{{ asset('static/vendor/zxing.min.js') }}" defer></script>
  <script src="{{ asset('static/app.js') }}?v={{ filemtime(public_path('static/app.js')) }}"></script>
</body>
</html>
